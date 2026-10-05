<?php
namespace CMS\Agents;

use CMS\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Design Agent: turns a brief into a validated token set and a theme row.
 *
 * The model is only allowed to produce DATA (a JSON token tree). Before a
 * single value is stored, Tokens::normalize() forces every entry into the
 * shape the design_tokens / themes tables actually accept — a model that
 * returns prose, or a colour that is not a hex string, produces a clean
 * `failed` result instead of a half-written row.
 */
class DesignAgent extends BaseAgent
{
    /** Mirrors the CHECK constraint on design_tokens.category. */
    public const CATEGORIES = ['color', 'font', 'space', 'radius', 'shadow', 'layout'];

    private Connection $db;
    private IntentRegistry $registry;

    public function __construct(?Connection $db = null, string $provider = 'kilo')
    {
        parent::__construct($provider);
        $this->db = $db ?? new Connection(require __DIR__ . '/../../config/database.php');
        $built = Actions::build($this->db);
        $this->registry = new IntentRegistry($this->db, $built['schema'], $built['writers']);
    }

    /**
     * Task forms:
     *   "generate tokens for a fintech brand"  -> LLM tokens, validated, saved
     *   anything else                          -> legacy HTML page draft
     */
    public function execute(string $task, array $context = []): array
    {
        $ctx = Context::fromArray($context);

        if (preg_match('/\b(token|tokens|palette|theme|colour|color)\b/i', $task)) {
            return $this->generateTokens($task, $ctx);
        }

        return $this->renderPage($task, $ctx);
    }

    // ------------------------------------------------------------------
    // Design tokens
    // ------------------------------------------------------------------

    /**
     * Generate, validate and persist a token set + theme config.
     *
     * @param bool $persist false returns the tokens without writing anything.
     */
    public function generateTokens(string $brief, ?Context $context = null, bool $persist = true): array
    {
        $context ??= new Context();

        $system = <<<'PROMPT'
You are a design agent inside a CMS. Produce a design token set as JSON.
Respond with ONLY raw JSON — no prose, no markdown fences — in exactly this shape:
{
  "name": "Theme display name",
  "slug": "kebab-case-theme-slug",
  "colors":    {"primary":"#RRGGBB","surface":"#RRGGBB","text":"#RRGGBB","accent":"#RRGGBB"},
  "fonts":     {"heading":"Font Name","body":"Font Name"},
  "space":     {"sm":"4px","md":"8px","lg":"16px"},
  "radius":    {"sm":"2px","md":"6px","lg":"12px"},
  "shadows":   {"card":"0 1px 3px rgba(0,0,0,0.12)"},
  "layout":    {"container":"1200px","sidebar":"240px"}
}
Every colour MUST be a #RRGGBB hex string. Every spacing/radius value MUST be a
CSS length. Omit a group entirely rather than inventing values.
PROMPT;

        $content = $this->callLLM($system, $context->toPromptBlock() . "\n\nBrief: " . $brief, [
            'max_tokens' => 1200,
            'temperature' => 0.4,
        ]);

        if ($content === null) {
            return ['status' => 'failed', 'error' => 'No LLM provider available (missing API key or rate limited)'];
        }

        $decoded = $this->extractJson($content);
        if ($decoded === null) {
            return [
                'status' => 'failed',
                'error'  => 'Model did not return valid JSON',
            ];
        }

        $tokens = Tokens::normalize($decoded);
        if ($tokens['tokens'] === []) {
            return [
                'status' => 'failed',
                'error'  => 'Model returned no usable design tokens',
                'keys'   => array_keys($decoded),
            ];
        }

        if (!$persist) {
            return ['status' => 'ok', 'persisted' => false, 'tokens' => $tokens];
        }

        $saved = $this->persistTokens($tokens, $context);
        if (($saved['status'] ?? '') !== 'ok') {
            return $saved;
        }

        return [
            'status'    => 'ok',
            'persisted' => true,
            'tokens'    => $tokens,
            'written'   => $saved['written'],
            'theme'     => $saved['theme'],
        ];
    }

    /**
     * Write every token, then the theme config, through the IntentRegistry.
     * Each write is a separate allowlisted action with bound parameters.
     */
    private function persistTokens(array $tokens, Context $context): array
    {
        $written = [];
        $failures = [];

        foreach ($tokens['tokens'] as $token) {
            try {
                $this->registry->execute([
                    'action' => 'upsert_design_token',
                    'params' => $token,
                ], $context->userRole);
                $written[] = $token['key'];
            } catch (InvalidArgumentException|RuntimeException $e) {
                $failures[] = ['key' => $token['key'], 'error' => $e->getMessage()];
            }
        }

        if ($written === []) {
            return [
                'status' => 'rejected',
                'error'  => 'No design token could be written: ' . ($failures[0]['error'] ?? 'unknown'),
                'errors' => $failures,
            ];
        }

        try {
            $theme = $this->registry->execute([
                'action' => 'save_theme',
                'params' => [
                    'slug'        => $tokens['slug'],
                    'name'        => $tokens['name'],
                    'source'      => 'ai',
                    'config_json' => json_encode($tokens['config'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'activate'    => true,
                ],
            ], $context->userRole);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return [
                'status' => 'rejected',
                'error'  => $e->getMessage(),
                'written'=> $written,
                'errors' => $failures,
            ];
        }

        return ['status' => 'ok', 'written' => $written, 'theme' => $theme, 'errors' => $failures];
    }

    // ------------------------------------------------------------------
    // Legacy HTML page draft
    // ------------------------------------------------------------------

    private function renderPage(string $task, Context $context): array
    {
        $system = <<<'PROMPT'
You are a design agent inside a CMS. Given a design brief, produce a complete HTML page with embedded CSS.
Rules:
- Modern, responsive, mobile-first layout
- Use CSS custom properties for theming
- Semantic HTML5
- Dark/light mode support via prefers-color-scheme
- Include: header, nav, hero, content sections, footer
Respond with ONLY the complete HTML document.
PROMPT;

        $html = $this->callLLM($system, $context->toPromptBlock() . "\n\nBrief: " . $task, [
            'max_tokens' => 6000,
            'temperature' => 0.6,
        ]);

        if ($html === null) {
            return ['status' => 'failed', 'error' => 'No LLM provider available'];
        }

        // The filename comes from the task text; sanitize it down to a safe
        // kebab-case slug so a model can never steer the write out of the dir.
        $themeName = slugify($task);
        $themeName = mb_substr($themeName, 0, 60) ?: ('theme-' . substr(bin2hex(random_bytes(4)), 0, 8));

        $themeDir = dirname(__DIR__, 2) . '/storage/themes/';
        if (!is_dir($themeDir) && !mkdir($themeDir, 0755, true) && !is_dir($themeDir)) {
            return ['status' => 'failed', 'error' => 'Could not create theme directory'];
        }

        $path = $themeDir . $themeName . '.html';
        if (file_put_contents($path, $html) === false) {
            return ['status' => 'failed', 'error' => 'Could not write theme file'];
        }

        return [
            'status'     => 'created',
            'theme_path' => str_replace(dirname(__DIR__, 2) . '/', '', $path),
            'preview'    => mb_substr($html, 0, 300),
        ];
    }

    /**
     * Strip markdown fences and pull the outermost JSON object out of a model
     * response. Returns null for prose — never throws on malformed input.
     */
    private function extractJson(string $content): ?array
    {
        $content = trim($content);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $content, $m)) {
            $content = trim($m[1]);
        }
        if (!str_starts_with($content, '{')) {
            if (preg_match('/\{.*\}/s', $content, $m)) {
                $content = $m[0];
            }
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }
}