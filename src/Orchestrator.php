<?php
namespace CMS;

use CMS\Agents\Context;

/**
 * Routes a master prompt to the agent that can handle it.
 *
 * Every agent receives a Context, never a loose array, so the caller's role
 * always reaches it. Previously $context was passed straight through and
 * AdminAgent fell back to `$context['user_role'] ?? 'author'` — nothing ever
 * populated that key, so an admin's publish request silently ran as an
 * author and was rejected. normalizeContext() makes the role explicit and
 * default-safe instead.
 */
class Orchestrator
{
    private array $agents;
    private array $config;
    private ?Context $context;

    public function __construct(array $config = [], array|Context|null $context = null)
    {
        $this->config = $config;
        $this->context = $context instanceof Context ? $context : null;
        $this->agents = [
            'blog'  => new Agents\BlogAgent(),
            'seo'   => new Agents\SeoAgent(),
            'design' => new Agents\DesignAgent(),
            'admin' => new Agents\AdminAgent(),
            // The delegating agent. It is registered here as well as in the
            // API allowlist so that `process('...')` and a direct
            // POST /agents/ceo reach the same class rather than two
            // constructions of the fleet.
            //
            // It is NOT a parsePrompt() target, and that is deliberate.
            // parsePrompt() is a keyword splitter: a prompt mentioning
            // "pricing page" and "purple" would fan out to blog+design+admin
            // as three independent agents each guessing at the whole goal,
            // which is the behaviour CeoAgent exists to replace. The CEO
            // plans the decomposition itself and is invoked explicitly.
            'ceo'   => new Agents\CeoAgent(),
        ];
    }

    /**
     * Build an orchestrator that acts as the given user.
     *
     * In an HTTP request this is where the role from the JWT stops being
     * dropped:
     *
     *     $o = new Orchestrator([], Context::fromRequest($request));
     *     $o->process('publish post 12');
     */
    public static function forUser(Context $context, array $config = []): self
    {
        return new self($config, $context);
    }

    /**
     * Process a master prompt through the orchestrator
     * Routes to specialized agents based on intent detection.
     *
     * @param array|Context $context Per-call override of the constructor
     *                              context. An array is upgraded to a Context;
     *                              an array with no 'user_role' is an author.
     */
    public function process(string $masterPrompt, array|Context $context = []): array
    {
        $resolved = $this->normalizeContext($context);
        $steps = $this->parsePrompt($masterPrompt);
        $results = [];

        foreach ($steps as $step) {
            $agent = $this->resolveAgent($step['agent']);
            if (!$agent) {
                $results[] = ['error' => "Unknown agent: {$step['agent']}", 'step' => $step];
                continue;
            }

            $result = $agent->execute($step['task'], $resolved->toArray());
            $results[] = [
                'agent' => $step['agent'],
                'task'  => $step['task'],
                'result'=> $result,
            ];
        }

        return [
            'steps'   => $results,
            'summary' => $this->summarize($results),
            'context' => $resolved->toArray(),
        ];
    }

    /**
     * The identity every agent in this run will see.
     *
     * Precedence: explicit Context argument > constructor Context > anonymous
     * author. Site details are filled in from settings when the caller did not
     * supply them, so prompts always carry a real brand.
     */
    private function normalizeContext(array|Context $context): Context
    {
        $resolved = $context instanceof Context ? $context : Context::fromArray($context);

        // Value comparison, not identity: two distinct Context objects are
        // never ===, so an identity check would discard the constructor
        // context on every call.
        if ($this->context !== null && $resolved == new Context()) {
            $resolved = $this->context;
        }

        if ($resolved->siteTitle === '' || $resolved->siteDescription === '') {
            try {
                $site = Context::siteSettings(
                    new \CMS\Database\Connection(require __DIR__ . '/../config/database.php')
                );
                $resolved = $resolved->with(
                    siteTitle: $resolved->siteTitle !== '' ? $resolved->siteTitle : $site['title'],
                    siteDescription: $resolved->siteDescription !== '' ? $resolved->siteDescription : $site['description'],
                );
            } catch (\Throwable) {
                // An unreadable settings table must not stop a prompt running.
            }
        }

        return $resolved;
    }

    /**
     * Parse master prompt into actionable steps
     */
    private function parsePrompt(string $prompt): array
    {
        $steps = [];
        $lower = strtolower($prompt);

        if (preg_match('/blog|post|write|article/i', $lower)) {
            $steps[] = ['agent' => 'blog', 'task' => $prompt];
        }
        if (preg_match('/seo|optimize|meta|keyword/i', $lower)) {
            $steps[] = ['agent' => 'seo', 'task' => $prompt];
        }
        if (preg_match('/design|theme|layout|css|style/i', $lower)) {
            $steps[] = ['agent' => 'design', 'task' => $prompt];
        }
        if (preg_match('/admin|publish|schedule|user|setting/i', $lower)) {
            $steps[] = ['agent' => 'admin', 'task' => $prompt];
        }

        if (empty($steps)) {
            $steps[] = ['agent' => 'blog', 'task' => $prompt];
        }

        return $steps;
    }

    private function summarize(array $results): string
    {
        $count = count($results);
        return "Processed through {$count} agent(s): " . implode(', ', array_column($results, 'agent'));
    }

    /**
     * The agent instance that will handle $name, or null when unknown.
     *
     * A seam, not just a lookup: a subclass swaps one in by overriding
     * resolveAgent() and returning its own instance. process() used to read
     * the private $agents map directly, so overriding getAgents() advertised
     * an extra agent that routing then never reached — the override was
     * silently inert.
     */
    protected function resolveAgent(string $name): ?\CMS\Agents\BaseAgent
    {
        return $this->agents[$name] ?? null;
    }

    /**
     * Agent names this orchestrator will route to.
     */
    public function getAgents(): array
    {
        return array_keys($this->agents);
    }

    /** The identity this orchestrator uses when process() gets no override. */
    public function getContext(): Context
    {
        return $this->context ?? new Context();
    }
}