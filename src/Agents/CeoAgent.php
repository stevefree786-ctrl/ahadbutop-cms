<?php
namespace CMS\Agents;

use CMS\Database\Connection;

/**
 * The agent you talk to.
 *
 * Every other agent in the fleet takes ONE intent for ONE narrow job: publish
 * this post, set this token, import this image. That is the right shape for a
 * machine-facing API and the wrong shape for a person. Nobody wants to know
 * that "make the site look more professional and add a pricing page" is
 * three calls to three different agents in a specific order.
 *
 * So this agent does the thing its name says. It takes a plain-English goal,
 * asks the model to decompose it into steps, routes each step to the
 * specialist that owns it, and reports back what actually happened.
 *
 * THE ONE RULE: THE CEO HAS NO PRIVILEGES
 *
 * Delegation is not escalation. Every step's intent goes through
 * IntentRegistry::execute() with the CALLER's role, never a role of its own,
 * and the CEO has no role of its own to pass. An editor who says "delete
 * every page" gets the same refusal the editor would get from calling
 * delete_page directly, and the plan does not stop because the CEO asked
 * nicely — each step is authorized independently and a denial is recorded
 * against that step alone.
 *
 * The model therefore proposes; it never disposes. It cannot name an intent
 * outside the registry, cannot pass a parameter the registry does not declare,
 * and cannot reach the database except through an allowlisted handler. That is
 * the same guarantee every other agent has, and the reason this is a
 * composition of them rather than a shortcut around them.
 *
 * Planning is separated from executing on purpose. The plan comes back in the
 * response and can be inspected before anything runs; execution is a separate
 * method with the same role gate. An operator who wants to see the plan
 * before it lands can call plan() and then execute().
 */
class CeoAgent extends BaseAgent
{
    private Connection $db;

    /**
     * Which agent owns which kind of step.
     *
     * The value is the agent's key in Orchestrator's map AND the basename the
     * API allowlist uses, so one name resolves the same way everywhere.
     *
     * 'admin' is the fallback because it is the only agent that reaches the
     * full intent registry — a step naming a capability nothing else owns
     * (rename_page, activate_theme) must still be executable rather than
     * silently dropped. Routing it to a specialist that does not have the
     * intent would turn a working capability into a "unknown agent" error.
     */
    private const ROUTING = [
        'blog'      => 'BlogAgent',
        'article'   => 'BlogAgent',
        'post'      => 'BlogAgent',
        'news'      => 'NewsAgent',
        'image'     => 'MediaAgent',
        'media'     => 'MediaAgent',
        'seo'       => 'SeoAgent',
        'keyword'   => 'SeoAgent',
        'meta'      => 'SeoAgent',
        'design'    => 'DesignAgent',
        'theme'     => 'DesignAgent',
        'style'     => 'DesignAgent',
        'layout'    => 'DesignAgent',
        'color'     => 'DesignAgent',
        'search'    => 'SearchAgent',
        'analytics' => 'AnalyticsAgent',
        'social'    => 'SocialAgent',
        'editor'    => 'EditorAgent',
        'page'      => 'AdminAgent',
        'admin'     => 'AdminAgent',
        'settings'  => 'AdminAgent',
        'redirect'  => 'AdminAgent',
        'publish'   => 'AdminAgent',
    ];

    /**
     * Specialists the CEO may delegate to, as agent keys.
     *
     * A closed list even though ROUTING is an array: the model emits a
     * "specialist" string, and resolveSpecialist() is what turns it into an
     * actual agent. Anything outside this list falls through to AdminAgent
     * rather than reaching for a class name, so a model answering
     * "specialist":"../../System" has nowhere for that to go.
     */
    private const SPECIALISTS = [
        'BlogAgent', 'SeoAgent', 'DesignAgent', 'AdminAgent', 'EditorAgent',
        'SocialAgent', 'MediaAgent', 'NewsAgent', 'SearchAgent', 'AnalyticsAgent',
    ];

    public function __construct(?Connection $db = null, string $provider = 'kilo')
    {
        parent::__construct($provider);

        $this->db = $db ?? new Connection(require __DIR__ . '/../../config/database.php');
    }

    /**
     * Plan AND run a goal.
     *
     * The shape every other agent uses, so the API endpoint that dispatches
     * by agent name reaches this without special-casing.
     *
     * @return array{status:string, goal:string, plan:array, steps:array, summary:string, done:int, failed:int}
     */
    public function execute(string $task, array|Context $context = []): array
    {
        $ctx   = $context instanceof Context ? $context : Context::fromArray($context);
        $plan  = $this->plan($task, $ctx);

        if (($plan['status'] ?? '') !== 'ok') {
            return $plan;
        }

        $run = $this->runPlan($plan['steps'], $ctx);

        return [
            'status'  => $run['failed'] > 0 ? 'partial' : 'ok',
            'goal'    => $task,
            'plan'    => $plan['steps'],
            'steps'   => $run['steps'],
            'summary' => $run['summary'],
            'done'    => $run['done'],
            'failed'  => $run['failed'],
            'role'    => $ctx->userRole,
        ];
    }

    /**
     * Decompose a goal into steps, without touching anything.
     *
     * Split out from execute() so the plan is inspectable on its own: this is
     * what an operator calls to see what the AI is about to do. Nothing here
     * writes, so it is safe to call at any role.
     *
     * @return array{status:string, steps?:array, summary?:string, error?:string, reason?:string}
     */
    public function plan(string $goal, array|Context $context = []): array
    {
        $ctx = $context instanceof Context ? $context : Context::fromArray($context);

        $system = <<<PROMPT
You are the CEO of a content management system. You do not write, design, or edit
anything yourself. You break a goal into steps and hand each step to the right
specialist.

Respond with ONLY raw JSON — no prose, no markdown fences:

{"summary":"one line describing the plan",
 "steps":[{"specialist":"BlogAgent","intent":"save_draft_post","params":{"title":"..."}},
          {"specialist":"AdminAgent","intent":"create_page","params":{"title":"Pricing","body_md":"..."}}]}

Rules:
- "intent" MUST be one of the allowed actions listed below, spelled exactly.
- "params" MUST contain only the parameters listed for that action. No extras.
- One action per step. Never combine two actions into one step.
- 2 to 6 steps. Fewer is better; do not invent work the goal did not ask for.
- If the goal needs nothing you can do, return {"summary":"...","steps":[]}.

{$this->actionSpec()}
PROMPT;

        $content = $this->callLLM($system, $ctx->toPromptBlock() . "\n\nGoal: " . $goal, [
            'max_tokens' => 1200,
            // Low, because a delegation plan should be the obvious reading of
            // the goal. Creativity here means inventing steps nobody asked for.
            'temperature' => 0.2,
        ]);

        if ($content === null) {
            return ['status' => 'failed', 'error' => 'No LLM provider available'];
        }

        $decoded = $this->decodeJson($content);
        if ($decoded === null) {
            return ['status' => 'failed', 'error' => 'Model did not return a valid plan'];
        }

        $steps = [];
        foreach ((array) ($decoded['steps'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $intent = (string) ($raw['intent'] ?? '');
            if ($intent === '') {
                continue;
            }
            $steps[] = [
                // resolveSpecialist() is what turns this string into an
                // agent; an unrecognised one becomes AdminAgent rather than
                // being trusted or dropped.
                'specialist' => $this->resolveSpecialist((string) ($raw['specialist'] ?? '')),
                'intent'     => $intent,
                'params'     => is_array($raw['params'] ?? null) ? $raw['params'] : [],
                'why'        => trim((string) ($raw['why'] ?? '')),
            ];
        }

        if ($steps === []) {
            return [
                'status'  => 'declined',
                'reason'  => (string) ($decoded['summary'] ?? 'No actionable steps in that request'),
                'summary' => (string) ($decoded['summary'] ?? ''),
            ];
        }

        return [
            'status'  => 'ok',
            'steps'   => $steps,
            'summary' => (string) ($decoded['summary'] ?? count($steps) . ' step(s)'),
        ];
    }

    /**
     * Execute a plan produced by plan().
     *
     * Every step is authorized and executed independently against the
     * CALLER's role. A step that is refused does not stop the ones after it:
     * "write two articles and publish them" where publishing is not permitted
     * should still produce the two drafts, and saying so, rather than throwing
     * the drafts away with the refusal.
     *
     * @param array<int,array{specialist:string, intent:string, params:array}> $steps
     */
    public function runPlan(array $steps, array|Context $context = []): array
    {
        $ctx     = $context instanceof Context ? $context : Context::fromArray($context);
        $built   = Actions::build($this->db);
        $registry = new IntentRegistry($this->db, $built['schema'], $built['writers']);

        $results = [];
        $done = 0;
        $failed = 0;

        foreach ($steps as $step) {
            $entry = [
                'specialist' => $step['specialist'] ?? null,
                'intent'     => $step['intent'] ?? null,
                'params'     => $step['params'] ?? [],
            ];

            try {
                // The caller's role, always. There is no path from here to a
                // role the caller does not hold.
                $result = $registry->execute(
                    ['action' => (string) $step['intent'], 'params' => (array) ($step['params'] ?? [])],
                    $ctx->userRole
                );

                // A handler can return an error payload rather than throw —
                // post_not_found, theme_not_found, invalid_token_value. Those
                // are failures too, and counting them as successes is how a
                // plan ends up reporting "done" having changed nothing.
                if (isset($result['error'])) {
                    $failed++;
                    $entry['status'] = 'failed';
                    $entry['result'] = $result;
                } else {
                    $done++;
                    $entry['status'] = 'ok';
                    $entry['result'] = $result;
                }
            } catch (\InvalidArgumentException $e) {
                // Unknown action, bad param, smuggled extra key. The registry
                // caught it; nothing was written.
                $failed++;
                $entry['status'] = 'rejected';
                $entry['error']   = $e->getMessage();
            } catch (\RuntimeException $e) {
                // Role floor not met. Recorded against this step so the caller
                // sees exactly which capability they lack.
                $failed++;
                $entry['status'] = 'denied';
                $entry['error']   = $e->getMessage();
            } catch (\Throwable $e) {
                $failed++;
                $entry['status'] = 'error';
                $entry['error']   = $e->getMessage();
            }

            $results[] = $entry;
        }

        return [
            'steps'   => $results,
            'done'    => $done,
            'failed'  => $failed,
            'summary' => $this->summarizeRun($results, $ctx->userRole),
        ];
    }

    /**
     * Map a model-supplied specialist name to a real agent.
     *
     * Three tiers, in order: an exact class name, an Orchestrator key
     * ('blog'), and finally AdminAgent. The fallback is deliberate — a step
     * whose specialist the model misnamed should still run against the agent
     * that can reach the intent, rather than being dropped. And AdminAgent is
     * the right fallback precisely because it executes through the same
     * registry, so an unrecognised name buys no extra capability.
     */
    public function resolveSpecialist(string $name): string
    {
        $name = trim($name);

        if ($name !== '' && in_array($name, self::SPECIALISTS, true)) {
            return $name;
        }

        $key = strtolower($name);
        if (isset(self::ROUTING[$key])) {
            return self::ROUTING[$key];
        }

        // Tolerate "design agent", "Design", "designAgent".
        $normalised = preg_replace('/[^a-z]/', '', $key) ?? '';
        foreach (self::SPECIALISTS as $class) {
            if (strtolower($class) === $normalised || strtolower(rtrim($class, 'Agent')) === $normalised) {
                return $class;
            }
        }

        return 'AdminAgent';
    }

    /**
     * The routing table, for prompts and for the admin UI.
     *
     * @return array<string,string> step keyword -> agent class
     */
    public static function routing(): array
    {
        return self::ROUTING;
    }

    /**
     * The allowed actions, with their role floors.
     *
     * Built from Actions rather than restated here, so a new intent becomes
     * plannable the moment it is registered and cannot be advertised to the
     * model without also being executable.
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
                $optional = ($rule['required'] ?? false) ? '' : '?';
                $params[] = json_encode((string) $name) . ':<' . ($rule['type'] ?? 'string') . '>' . $optional;
            }
            $line = json_encode((string) $action) . ' {"params": {' . implode(', ', $params) . '}';
            if (isset($writers[$action])) {
                $line .= '  [min role: ' . $writers[$action] . ']';
            }
            $line .= '}';
            $lines[] = $line;
        }

        return "Allowed actions, each with the exact parameters it accepts:\n" . implode("\n", $lines);
    }

    /**
     * A plain-English account of the run.
     *
     * Reads as a status report to the person who typed the prompt rather than
     * a dump of intents, because that is the audience — they did not write
     * these action names and should not have to.
     */
    private function summarizeRun(array $results, string $role): string
    {
        $ok   = array_values(array_filter($results, static fn ($r) => ($r['status'] ?? '') === 'ok'));
        $den  = array_values(array_filter($results, static fn ($r) => ($r['status'] ?? '') === 'denied'));
        $bad  = array_values(array_filter($results, static fn ($r) => in_array($r['status'] ?? '', ['rejected', 'failed', 'error'], true)));

        $parts = [];
        $parts[] = count($ok) . ' of ' . count($results) . ' step(s) completed';

        if ($ok !== []) {
            $parts[] = 'done: ' . implode(', ', array_map(
                static fn ($r) => (string) ($r['intent'] ?? '?'),
                $ok
            ));
        }
        if ($den !== []) {
            $parts[] = 'not permitted for a ' . $role . ': ' . implode(', ', array_map(
                static fn ($r) => (string) ($r['intent'] ?? '?'),
                $den
            ));
        }
        if ($bad !== []) {
            $parts[] = 'failed: ' . implode(', ', array_map(
                static fn ($r) => (string) ($r['intent'] ?? '?'),
                $bad
            ));
        }

        return implode('. ', $parts) . '.';
    }

    /**
     * Parse a JSON object from a model response, tolerating markdown fences.
     *
     * Same wire format IntentEncoder produces and AdminAgent::decodeIntent
     * accepts. Returns null rather than a partial array: a plan is a list of
     * writes, and half a plan is not something to act on.
     */
    private function decodeJson(string $content): ?array
    {
        $content = trim($content);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $content, $m) === 1) {
            $content = trim($m[1]);
        }

        // Some models prepend a sentence before the object; take the outermost
        // brace-balanced span.
        $start = strpos($content, '{');
        if ($start !== false) {
            $depth = 0;
            $len   = strlen($content);
            for ($i = $start; $i < $len; $i++) {
                if ($content[$i] === '{') {
                    $depth++;
                } elseif ($content[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $content = substr($content, $start, $i - $start + 1);
                        break;
                    }
                }
            }
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }
}