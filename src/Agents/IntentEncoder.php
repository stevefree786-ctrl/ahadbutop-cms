<?php
namespace CMS\Agents;

/**
 * A validated action request — the output/tool-calling counterpart to the
 * `{"action":..,"params":{..}}` JSON that IntentRegistry consumes.
 *
 * Deliberately NOT an LLM target type: models answer with JSON, not with
 * PHP. Construct one in your own code, hand it to the encoder, and the
 * registry gets a request it can validate and authorize.
 */
final class ActionRequest
{
    public function __construct(
        public readonly string $action,
        public readonly array $params = []
    ) {
    }

    public function with(string $action, array $params = []): self
    {
        return new self($action, $params);
    }
}

/**
 * Encodes an ActionRequest into the compact wire format IntentRegistry and
 * AdminAgent::decodeIntent() agree on:
 *
 *     {"action":"publish_post","params":{"post_id":42}}
 *
 * Single responsibility, no I/O, no LLM call — trivially testable. The
 * registry stays the only thing that ever touches SQL.
 */
final class IntentEncoder
{
    public function __construct(private IntentRegistry $registry)
    {
    }

    /**
     * @throws \InvalidArgumentException when the action is not on the allowlist.
     */
    public function encode(ActionRequest $request): string
    {
        if (!in_array($request->action, $this->registry->actions(), true)) {
            throw new \InvalidArgumentException(
                'Unknown action: ' . var_export($request->action, true)
                . '. Allowed: ' . implode(', ', $this->registry->actions())
            );
        }

        $json = json_encode(
            ['action' => $request->action, 'params' => $request->params],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new \InvalidArgumentException('Intent is not JSON-encodable');
        }

        return $json;
    }

    /**
     * Encode without throwing. Useful on paths that must degrade rather than
     * abort — returns null so the caller can report a failed status.
     */
    public function tryEncode(ActionRequest $request): ?string
    {
        try {
            return $this->encode($request);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Decode what encode() produced. Round-trip helper for callers that need
     * to read an intent back (logging, tests, dry-run inspection). Does NOT
     * validate params — only IntentRegistry does that, with authorization.
     */
    public function decode(string $json): ?array
    {
        $json = trim($json);

        // Tolerate markdown fences, the same way AdminAgent::decodeIntent() does.
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $json, $m)) {
            $json = trim($m[1]);
        }
        if (!str_starts_with($json, '{')) {
            if (preg_match('/\{.*\}/s', $json, $m)) {
                $json = $m[0];
            }
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) && isset($decoded['action']) ? $decoded : null;
    }
}