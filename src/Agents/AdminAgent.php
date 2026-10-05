<?php
namespace CMS\Agents;

use CMS\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Admin Agent: publishing, scheduling and settings.
 *
 * The model proposes an INTENT; this agent validates it against the
 * IntentRegistry allowlist and executes it with bound parameters.
 * Model output never reaches SQL directly.
 */
class AdminAgent extends BaseAgent
{
    private Connection $db;
    private IntentRegistry $registry;

    public function __construct(?Connection $db = null, string $provider = 'kilo')
    {
        parent::__construct($provider);

        $this->db = $db ?? new Connection(require __DIR__ . '/../../config/database.php');

        $built   = Actions::build($this->db);
        $this->registry = new IntentRegistry($this->db, $built['schema'], $built['writers']);
    }

    /**
     * @param string              $task    Natural-language command.
     * @param array|Context       $context Working context; must carry the acting
     *                               user's role. A bare array without
     *                               'user_role' is treated as 'author'.
     */
    public function execute(string $task, array|Context $context = []): array
    {
        $ctx = $context instanceof Context ? $context : Context::fromArray($context);

        $system = <<<PROMPT
You are an admin automation agent inside a CMS. Convert the request into exactly ONE JSON intent.
Respond with ONLY raw JSON — no prose, no markdown fences.

{$this->actionSpec()}

If the request does not map to one of these, respond with {"action":"none","reason":"..."}.
Never invent SQL. Never add parameters outside those listed.
PROMPT;

        $content = $this->callLLM($system, $ctx->toPromptBlock() . "\n\nRequest: " . $task, [
            'max_tokens' => 400,
            'temperature' => 0.1,
        ]);

        if ($content === null) {
            return ['status' => 'failed', 'error' => 'No LLM provider available'];
        }

        $intent = $this->decodeIntent($content);
        if ($intent === null) {
            return ['status' => 'failed', 'error' => 'Model did not return a valid intent'];
        }

        if (($intent['action'] ?? '') === 'none') {
            return [
                'status' => 'declined',
                'reason' => $intent['reason'] ?? 'Request did not map to an allowed action',
            ];
        }

        try {
            $result = $this->registry->execute($intent, $ctx->userRole);
        } catch (InvalidArgumentException|RuntimeException $e) {
            // Validation/authorization failures are expected outcomes, not crashes.
            return [
                'status'  => 'rejected',
                'action'  => $intent['action'] ?? null,
                'error'   => $e->getMessage(),
            ];
        }

        return [
            'status' => 'ok',
            'action' => $intent['action'],
            'result' => $result,
        ];
    }

    /**
     * The wire format, generated from the live allowlist so the prompt can
     * never drift from what IntentRegistry actually accepts:
     *
     *   {"action":"publish_post","params":{"post_id":42}}
     */
    public function actionSpec(): string
    {
        $built   = Actions::build($this->db);
        $schema  = $built['schema'];
        $writers = $built['writers'];

        $lines = [];
        foreach ($schema as $action => $spec) {
            $params = [];
            foreach ($spec['params'] as $name => $rule) {
                $params[] = json_encode((string) $name) . ':<' . ($rule['type'] ?? 'string') . '>';
            }
            $line = json_encode($action) . ' -> {"action":' . json_encode($action)
                . ',"params":{' . implode(',', $params) . '}}';
            if (isset($writers[$action])) {
                $line .= '  (requires ' . $writers[$action] . ')';
            }
            $lines[] = $line;
        }

        return "Allowed actions and their exact parameters:\n" . implode("\n", $lines);
    }

    /**
     * Parse the model response, tolerating markdown fences around the JSON.
     * Same wire format IntentEncoder produces.
     */
    private function decodeIntent(string $content): ?array
    {
        $content = trim($content);

        // Strip ```json ... ``` wrappers if the model added them.
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $content, $m)) {
            $content = trim($m[1]);
        }

        // Fall back to the outermost JSON object in the response.
        if (!str_starts_with($content, '{')) {
            if (preg_match('/\{.*\}/s', $content, $m)) {
                $content = $m[0];
            }
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) && isset($decoded['action']) ? $decoded : null;
    }
}