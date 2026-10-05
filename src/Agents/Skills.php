<?php
declare(strict_types=1);

namespace CMS\Agents;

/**
 * Skills: named, reusable playbooks the CEO agent can run in one step.
 *
 * WHAT A SKILL IS, AND WHY IT IS NOT A PROMPT
 *
 * Every agent here is reachable two ways: the CEO can decompose a goal into
 * raw intents, or it can invoke a skill by name. The second path exists
 * because some sequences are not things a model should be inventing fresh
 * each time.
 *
 * "Write a post, generate its SEO meta, and submit it for review" is a
 * sequence with a real failure mode when improvised: the model writes a
 * perfectly good post and forgets the SEO step, or writes SEO meta for the
 * *previous* post because it reordered the steps. Asking the model to be
 * careful about a known-shaped sequence is a losing game. Encoding it once,
 * deterministically, means the ordering is right by construction.
 *
 * So a skill is a fixed list of intents with parameter slots — not a prompt,
 * and not a chain of LLM calls. A skill does not think; it does a known thing
 * in a known order, and reports what each step did.
 *
 * WHY PARAMETERS ARE SLOTS AND NOT INTERPOLATED STRINGS
 *
 * A skill step looks like this:
 *
 *     ['intent' => 'save_seo_meta', 'from' => ['title'], 'to' => ['meta_title']]
 *
 * "from title" means: take the skill's `title` parameter and put it in this
 * step's `meta_title` param. The registry then validates and casts it exactly
 * as it would any other value.
 *
 * The alternative — building the params array by string-formatting values into
 * a template — is how parameter values end up bypassing validation, because a
 * formatted string is no longer typed. Here every value passes through the same
 * castString()/castInt() path whether it came from the model or from a skill.
 *
 * THE SECURITY PROPERTY, WHICH IS THE WHOLE POINT
 *
 * A skill cannot reach anything the caller could not reach directly. Each step
 * is executed through IntentRegistry::execute() with the CALLER's role, so:
 *
 *   - a skill cannot name an action that is not in the registry;
 *   - a skill cannot declare params the action does not accept (the registry
 *     rejects unknown keys, and skills are validated against the same schema
 *     when they load);
 *   - a skill cannot escalate. An editor running a skill full of admin intents
 *     gets those steps denied, one at a time, with the denial recorded — the
 *     same result as calling each intent by hand.
 *
 * There is no path from a skill definition to the database that does not go
 * through the registry. That is what makes a skill safe to ship as data.
 *
 * WHERE SKILLS COME FROM
 *
 * A built-in catalogue plus, optionally, JSON files in storage/skills/. The
 * file form exists so an operator can add a playbook for their own site
 * without a deploy — but a file's steps are validated against the registry
 * exactly as the built-ins are, so a bad file is refused at load time rather
 * than failing halfway through a run.
 */
final class Skills
{
    /**
     * Built-in playbooks.
     *
     * Each step: ['intent' => name, 'from' => slotMap, 'to' => paramMap,
     *             'as' => optional label].
     *
     * `from` is keyed by the slot name in the skill's params; `to` is keyed by
     * the intent's own param name. A step whose target param is not optional in
     * the registry schema and has no source is rejected at load time — that
     * check is what stops a malformed skill from failing at run time, halfway
     * through, after the earlier steps already wrote.
     */
    private const BUILTIN = [
        'seo_optimise_post' => [
            'label'       => 'Optimise a post for search',
            'description' => 'Attach SEO metadata to an existing post.',
            'params'      => ['post_id', 'meta_title?', 'meta_description?', 'focus_keyword?'],
            'steps'       => [
                ['intent' => 'save_seo_meta', 'to' => [
                    'entity_type'      => ['literal' => 'post'],
                    'entity_id'        => ['from' => 'post_id'],
                    'meta_title'       => ['from' => 'meta_title'],
                    'meta_description' => ['from' => 'meta_description'],
                    'focus_keyword'    => ['from' => 'focus_keyword'],
                ]],
            ],
        ],

        'publish_page' => [
            'label'       => 'Publish a page',
            'description' => 'Create a page and publish it in one step.',
            'params'      => ['title', 'body_md?', 'slug?'],
            'steps'       => [
                ['intent' => 'create_page', 'to' => [
                    'title'   => ['from' => 'title'],
                    'slug'    => ['from' => 'slug'],
                    'body_md' => ['from' => 'body_md'],
                ]],
                ['intent' => 'publish_post', 'to' => [
                    'post_id' => ['step_ref' => 0, 'field' => 'post_id'],
                ]],
            ],
        ],

        'restyle_site' => [
            'label'       => 'Change the site accent',
            'description' => 'Apply a design token site-wide.',
            'params'      => ['value', 'key?', 'css_var?', 'category?'],
            'steps'       => [
                ['intent' => 'apply_design_token', 'to' => [
                    'key'      => ['from' => 'key', 'default' => 'accent'],
                    'value'    => ['from' => 'value'],
                    'css_var'  => ['from' => 'css_var', 'default' => '--color-accent'],
                    'category' => ['from' => 'category', 'default' => 'color'],
                ]],
            ],
        ],
    ];

    /**
     * Optional per-install skills, loaded from JSON files.
     *
     * @var array<string,array> merged catalogue
     */
    private array $loaded = [];

    private ?IntentRegistry $registry = null;

    public function __construct(
        private ?\CMS\Database\Connection $db = null,
        private ?string $skillsDir = null
    ) {
        $this->db        ??= new \CMS\Database\Connection(
            require __DIR__ . '/../../config/database.php'
        );
        $this->skillsDir ??= dirname(__DIR__, 2) . '/storage/skills';
    }

    /** The catalogue: built-in skills plus any on-disk additions. */
    public function all(): array
    {
        return $this->loaded + self::BUILTIN;
    }

    /** A one-line-per-skill catalogue, for the CEO's prompt. */
    public function catalog(): string
    {
        $lines = [];

        foreach ($this->all() as $name => $skill) {
            $params = implode(', ', $skill['params'] ?? []);
            $lines[] = sprintf(
                '"%s" (params: %s) — %s',
                $name,
                $params === '' ? 'none' : $params,
                $skill['description'] ?? ''
            );
        }

        return implode("\n", $lines);
    }

    /**
     * The catalog as structured data, for the admin screen.
     *
     * A separate method from catalog() rather than a second format of the same
     * one: that string is a PROMPT FRAGMENT, written for a model to read, and
     * a screen that renders model-facing prose as if it were a data structure
     * ends up with a UI whose labels change when a prompt is reworded.
     *
     * Each step carries its writer role so the screen can show which step an
     * author's run will be refused on, before they press the button.
     */
    public function describe(): array
    {
        $writers = Actions::build($this->db)['writers'] ?? [];
        $out     = [];

        foreach ($this->all() as $name => $skill) {
            $steps = [];

            foreach ($skill['steps'] ?? [] as $step) {
                $intent = (string) ($step['intent'] ?? '');
                $steps[] = [
                    'intent' => $intent,
                    'to'     => $step['to'] ?? [],
                    // The floor is null for a reader, which the view renders
                    // as "any role" — the same distinction the Agents screen
                    // makes with its role tags.
                    'writer' => $writers[$intent] ?? null,
                ];
            }

            $out[] = [
                'name'        => $name,
                'label'       => $skill['label'] ?? $name,
                'description' => $skill['description'] ?? '',
                'params'      => $skill['params'] ?? [],
                'steps'       => $steps,
                // One role that could run the whole thing, or null when the
                // steps disagree — which is most of them. An editor who
                // cannot publish needs to be told that here, not by a
                // 'denied' halfway through the run.
                'runnable_by' => $steps === [] ? null : $this->narrowestRole($steps),
                'source'      => isset(self::BUILTIN[$name]) ? 'built-in' : 'operator file',
            ];
        }

        return $out;
    }

    /**
     * The highest floor across a skill's steps, or null if any is open.
     *
     * An empty return is NOT "everyone can run this": a step that cannot be
     * read from the writers map is an unknown intent, and run() will refuse
     * it. Treating unknown as open would advertise a skill as runnable by an
     * author and then fail the attempt — so null means "no single role",
     * which the view renders as "needs admin" only when it is genuinely the
     * admin steps that bind, and the per-step roles show the rest.
     */
    private function narrowestRole(array $steps): ?string
    {
        $floor = null;

        foreach ($steps as $step) {
            $writer = $step['writer'];
            if ($writer === null) {
                return null;
            }
            $rank = \CMS\Agents\Context::ROLES[$writer] ?? 3;
            if ($floor === null || $rank > $floor) {
                $floor = $rank;
            }
        }

        return array_search($floor, \CMS\Agents\Context::ROLES, true) ?: 'admin';
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /**
     * Run a skill.
     *
     * Every step runs through the registry with the caller's role, so a skill
     * can never do more than its caller could do by hand. A denied or failed
     * step does not stop the rest — same reasoning as the CEO's plan runner:
     * "optimise the SEO then publish" should still publish when the SEO step is
     * refused for a role reason, and the operator is told which part did not
     * happen.
     *
     * @return array{skill:string, ok:bool, done:int, failed:int,
     *               steps:array<int,array{intent:string,status:string,result?:array,error?:string}>,
     *               summary:string}
     */
    public function run(string $name, array $params, string $userRole = 'author'): array
    {
        $registry = $this->registry();

        $catalogue = $this->all();
        if (!isset($catalogue[$name])) {
            return [
                'skill' => $name, 'status' => 'rejected', 'ok' => false,
                'done' => 0, 'failed' => 0, 'steps' => [],
                'summary' => 'Unknown skill: ' . $name,
            ];
        }

        $skill = $catalogue[$name];
        $results = [];
        $done = 0;
        $failed = 0;

        foreach ($skill['steps'] as $index => $step) {
            $entry = ['intent' => $step['intent'] ?? '?'];

            try {
                $built = $this->bindStep($step, $params, $results, $index);

                if ($built === null) {
                    // A step that had nothing to bind (an optional source that
                    // was not supplied). Skipped, not failed: the skill is
                    // still valid, this step just had nothing to do.
                    $entry['status'] = 'skipped';
                    $results[] = $entry;
                    continue;
                }

                $result = $registry->execute(
                    ['action' => $step['intent'], 'params' => $built],
                    $userRole
                );

                if (isset($result['error'])) {
                    $failed++;
                    $entry['status'] = 'failed';
                    $entry['result']  = $result;
                } else {
                    $done++;
                    $entry['status'] = 'ok';
                    $entry['result']  = $result;
                }
            } catch (\InvalidArgumentException $e) {
                $failed++;
                $entry['status'] = 'rejected';
                $entry['error']   = $e->getMessage();
            } catch (\RuntimeException $e) {
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

        $summary = sprintf(
            '%s: %d of %d step(s) completed%s.',
            $name,
            $done,
            count($results),
            $failed > 0 ? sprintf('; %d did not', $failed) : ''
        );

        return [
            'skill'   => $name,
            // 'ok' only when EVERY step ran. A skill that stopped halfway is
            // not 'ok' with a caveat — the caller has to treat it as a
            // partial write, and a boolean that is true in both cases is how
            // a UI ends up reloading and implying the whole thing applied.
            'status'  => $failed === 0 ? 'ok' : 'partial',
            'ok'      => $failed === 0,
            'done'    => $done,
            'failed'  => $failed,
            'steps'   => $results,
            'summary' => $summary,
        ];
    }

    /**
     * Turn a step's declarative binding into a concrete param array.
     *
     * Returns null when the step had nothing to bind — an optional source the
     * caller did not supply. The caller then skips the step rather than
     * executing it with missing required params, which the registry would
     * reject anyway, but as a confusing error rather than a clean skip.
     *
     * @param array<int,array> $prior results of earlier steps, for step_ref
     */
    private function bindStep(array $step, array $params, array $prior, int $index): ?array
    {
        $bound = [];

        foreach ($step['to'] as $target => $source) {
            // A literal constant — the entity_type a skill always means.
            if (isset($source['literal'])) {
                $bound[$target] = $source['literal'];
                continue;
            }

            // A value from an earlier step's result, e.g. publish the page id
            // that create_page just returned.
            if (isset($source['step_ref'])) {
                $refStep = $prior[$source['step_ref']] ?? null;
                $field   = $source['field'] ?? null;
                $value   = is_array($refStep) ? ($refStep['result'][$field] ?? null) : null;

                if ($value === null) {
                    // The referenced step did not produce the field (it was
                    // skipped, or the action returns something else). Skipping
                    // is right: publishing "post_id: 0" would be worse.
                    return null;
                }
                $bound[$target] = $value;
                continue;
            }

            // A value from the skill's own params.
            $slot = (string) ($source['from'] ?? '');
            $value = $params[$slot] ?? null;

            if (($value === null || $value === '') && isset($source['default'])) {
                $value = $source['default'];
            }

            if ($value === null || $value === '') {
                // Optional param with no value: omit it entirely so the
                // registry's own default/required logic applies, rather than
                // passing an empty string it would have to reject.
                continue;
            }

            $bound[$target] = $value;
        }

        return $bound === [] ? null : $bound;
    }

    /**
     * Build (and cache) the registry from Actions.
     *
     * The registry is the only thing skills are allowed to execute through,
     * and it is built from the same Actions::build() the API uses — so a skill
     * can never name an action the API cannot also call.
     */
    private function registry(): IntentRegistry
    {
        return $this->registry ??= new IntentRegistry(
            $this->db,
            ...array_values(Actions::build($this->db))
        );
    }

    /**
     * Load operator-authored skills from disk.
     *
     * Only if something called load(). Kept out of the constructor because
     * reading a directory on every construction — and Skills is constructed per
     * CEO run — costs more than it earns for a feature most installs will not
     * use.
     */
    public function load(): array
    {
        if ($this->loaded !== []) {
            return $this->loaded;
        }

        if (!is_dir($this->skillsDir)) {
            return $this->loaded;
        }

        $schema  = Actions::build($this->db)['schema'];
        $loaded  = [];

        foreach (glob($this->skillsDir . '/*.json') ?: [] as $file) {
            $skill = $this->validateSkill(json_decode((string) file_get_contents($file), true), basename($file, '.json'), $schema);

            if ($skill !== null) {
                $loaded[$skill['name']] = $skill;
            }
        }

        return $this->loaded = $loaded;
    }

    /**
     * Validate an operator-authored skill against the live registry schema.
     *
     * Refused loudly at load time, because the alternative is a skill that
     * looks installed and then fails on its second step every time it runs.
     * Returns null (and logs) when the file is not a usable skill.
     */
    private function validateSkill(mixed $raw, string $fallbackName, array $schema): ?array
    {
        if (!is_array($raw) || !isset($raw['steps']) || !is_array($raw['steps'])) {
            error_log("[cms.skills] ignoring skill '{$fallbackName}': not a JSON object with a 'steps' array");
            return null;
        }

        $name = preg_match('/^[a-z][a-z0-9_]*$/', (string) ($raw['name'] ?? ''))
            ? (string) $raw['name']
            : $fallbackName;

        $steps = [];
        foreach ($raw['steps'] as $step) {
            if (!is_array($step) || !isset($step['intent'], $step['to'])) {
                error_log("[cms.skills] ignoring a malformed step in skill '{$name}'");
                return null;
            }

            $intent = (string) $step['intent'];
            if (!isset($schema[$intent])) {
                error_log("[cms.skills] ignoring skill '{$name}': unknown intent '{$intent}'");
                return null;
            }

            // Every target param must be one the intent declares. A typo'd key
            // would be rejected at run time by the registry, but catching it
            // here means the operator finds out when they save the file.
            foreach (array_keys($step['to']) as $target) {
                if (!isset($schema[$intent]['params'][$target])) {
                    error_log("[cms.skills] ignoring skill '{$name}': intent '{$intent}' has no param '{$target}'");
                    return null;
                }
            }

            $steps[] = $step;
        }

        return [
            'name'        => $name,
            'label'       => (string) ($raw['label'] ?? $name),
            'description' => (string) ($raw['description'] ?? ''),
            'params'      => array_values(array_map('strval', (array) ($raw['params'] ?? []))),
            'steps'       => $steps,
        ];
    }
}