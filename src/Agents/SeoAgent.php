<?php
namespace CMS\Agents;

use CMS\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

/**
 * SEO Agent: deterministic auditing of real content, plus optional LLM
 * suggestions that persist through the IntentRegistry.
 *
 * The split matters: score() and generateSitemap() never touch a model and
 * never invent data — they read the actual `posts` table. Only optimize()
 * calls the LLM, and even then the model's suggestion is validated and
 * written through the allowlisted `save_seo_meta` action, so nothing an LLM
 * produces ever reaches SQL unvalidated.
 */
class SeoAgent extends BaseAgent
{
    private Connection $db;
    private IntentRegistry $registry;

    /** Weights for score(); they sum to 100. */
    private const WEIGHTS = [
        'title_length'    => 20,
        'meta_description'=> 25,
        'heading_structure'=> 20,
        'word_count'      => 20,
        'internal_links'  => 15,
    ];

    public function __construct(?Connection $db = null, string $provider = 'kilo')
    {
        parent::__construct($provider);
        $this->db = $db ?? new Connection(require __DIR__ . '/../../config/database.php');
        $built = Actions::build($this->db);
        $this->registry = new IntentRegistry($this->db, $built['schema'], $built['writers']);
    }

    /**
     * BaseAgent contract.
     *
     * Task forms:
     *   "score post 12" / "audit post 12"  -> deterministic audit, persisted
     *   "optimize post 12"                  -> LLM suggestions (editor+)
     *   "sitemap https://example.com"       -> sitemap XML
     *   anything else                       -> LLM meta pack (legacy shape)
     */
    public function execute(string $task, array $context = []): array
    {
        $ctx = Context::fromArray($context);
        $postId = $this->extractPostId($task);

        if (($kind = $this->extractCommand($task)) === 'score' && $postId !== null) {
            return $this->audit((int) $postId, $ctx);
        }
        if ($kind === 'optimize' && $postId !== null) {
            return $this->optimize((int) $postId, $ctx);
        }
        if ($kind === 'sitemap') {
            return ['status' => 'ok', 'sitemap' => $this->generateSitemap($ctx->siteTitle)];
        }

        return $this->metaPack($task, $ctx, $context);
    }

    // ------------------------------------------------------------------
    // Sitemap — real published posts, XML-escaped
    // ------------------------------------------------------------------

    /**
     * Build a sitemap from the `posts` table (status='published'). Pages are
     * the same table with type='page', so both are covered.
     *
     * Every value is XML-escaped: a slug or a lastmod containing '&' or '<'
     * would otherwise produce unparseable XML, and a title-derived segment is
     * exactly the kind of value that can.
     */
    public function generateSitemap(string $baseUrl): string
    {
        $stmt = $this->db->getPdo()->prepare(
            "SELECT slug, COALESCE(updated_at, published_at, created_at) AS lastmod
             FROM posts
             WHERE status = 'published'
             ORDER BY COALESCE(published_at, created_at) DESC, id DESC"
        );
        $stmt->execute();

        $base = rtrim($baseUrl, '/');
        $esc  = fn(string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($stmt->fetchAll() as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            // Sitemap URLs are path-only; a host is not part of a slug.
            $slug = ltrim(preg_replace('#^[a-z][a-z0-9+.-]*://[^/]+/?#i', '', $slug) ?? $slug, '/');

            $xml .= "  <url>\n";
            $xml .= '    <loc>' . $esc($base . '/' . $slug) . "</loc>\n";
            $xml .= '    <lastmod>' . $esc(substr((string) $row['lastmod'], 0, 10)) . "</lastmod>\n";
            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>';
        return $xml;
    }

    // ------------------------------------------------------------------
    // Scoring — deterministic, no LLM
    // ------------------------------------------------------------------

    /**
     * Audit one post against real, checkable signals. Same input always
     * yields the same score; nothing here calls a model.
     */
    public function score(array $post): array
    {
        $title    = trim((string) ($post['title'] ?? ''));
        $excerpt  = trim((string) ($post['excerpt'] ?? ''));
        $body     = (string) ($post['body_md'] ?? '');
        $metaDesc = trim((string) ($post['meta_description'] ?? ''));

        $checks = [];

        // Each check awards its full weight when it passes and NOTHING when
        // it fails. Partial credit is not offered: a partial award has to be
        // justified against an arbitrary threshold, and it makes "this post
        // has no content at all" score 8/100 instead of 0/100 — which hides
        // the one signal a score is most useful for. Every threshold below
        // is a floor or a ceiling, and failing one is a hard fail.
        $points = static fn(bool $ok, int $weight): int => $ok ? $weight : 0;

        // --- title length -------------------------------------------------
        $titleLen = mb_strlen($title);
        $titleOk  = $titleLen >= 30 && $titleLen <= 60;
        $checks[] = [
            'name'   => 'title_length',
            'ok'     => $titleOk,
            'weight' => self::WEIGHTS['title_length'],
            'points' => $points($titleOk, self::WEIGHTS['title_length']),
            'detail' => "Title is {$titleLen} characters; aim for 30-60.",
        ];

        // --- meta description ---------------------------------------------
        // An excerpt counts as a meta description only when it is short
        // enough to survive truncation in a SERP snippet.
        $desc = $metaDesc !== '' ? $metaDesc : $excerpt;
        $descLen = mb_strlen($desc);
        $descOk = $descLen >= 70 && $descLen <= 160;
        $checks[] = [
            'name'   => 'meta_description',
            'ok'     => $descOk,
            'weight' => self::WEIGHTS['meta_description'],
            'points' => $points($descOk, self::WEIGHTS['meta_description']),
            'detail' => $desc === ''
                ? 'No meta description and no excerpt.'
                : "Meta description is {$descLen} characters; aim for 70-160.",
        ];

        // --- heading structure --------------------------------------------
        preg_match_all('/^#{1,6}\s+.+$/m', $body, $h);
        $headings = $h[0];
        $h1       = count(preg_grep('/^#\s+/', $headings) ?: []);
        $h2plus   = count(preg_grep('/^#{2,6}\s+/', $headings) ?: []);
        // One H1 is the document title; H2+ is the actual section structure.
        $headOk = $h1 <= 1 && $h2plus >= 2;
        $checks[] = [
            'name'   => 'heading_structure',
            'ok'     => $headOk,
            'weight' => self::WEIGHTS['heading_structure'],
            'points' => $points($headOk, self::WEIGHTS['heading_structure']),
            'detail' => "Found {$h1} H1 and {$h2plus} H2-H6 headings; aim for 1 H1 and 2+ subsections.",
        ];

        // --- word count ----------------------------------------------------
        $plain  = trim(preg_replace('/[#*`>\[\]()_-]+/', ' ', strip_tags($body)) ?? '');
        $words  = $plain === '' ? 0 : count(preg_split('/\s+/', $plain) ?: []);
        $wordOk = $words >= 300;
        $checks[] = [
            'name'   => 'word_count',
            'ok'     => $wordOk,
            'weight' => self::WEIGHTS['word_count'],
            'points' => $points($wordOk, self::WEIGHTS['word_count']),
            'detail' => "Body has {$words} words; aim for 300+.",
        ];

        // --- internal links ------------------------------------------------
        preg_match_all('/\]\(\s*\/[^)\s]*\s*\)/', $body, $internal);
        $links = count($internal[0]);
        $linkOk = $links >= 1;
        $checks[] = [
            'name'   => 'internal_links',
            'ok'     => $linkOk,
            'weight' => self::WEIGHTS['internal_links'],
            'points' => $points($linkOk, self::WEIGHTS['internal_links']),
            'detail' => "Found {$links} internal link(s); aim for at least 1.",
        ];

        $score = 0;
        foreach ($checks as $c) {
            $score += $c['points'];
        }

        return [
            'score'  => (int) max(0, min(100, $score)),
            'checks' => $checks,
            'words'  => $words,
        ];
    }

    /**
     * Score a real post and persist the score to seo_meta through the
     * registry (so an author gets a clean 'rejected' rather than a write).
     */
    public function audit(int $postId, ?Context $context = null): array
    {
        $context ??= new Context();
        $post = $this->fetchPost($postId);

        if ($post === null) {
            return ['status' => 'failed', 'error' => 'post_not_found', 'post_id' => $postId];
        }

        $result = $this->score($post + ['meta_description' => $post['meta_description'] ?? '']);

        try {
            $persisted = $this->registry->execute([
                'action' => 'save_seo_meta',
                'params' => [
                    'entity_type' => 'post',
                    'entity_id'   => $postId,
                    'score'       => $result['score'],
                ],
            ], $context->userRole);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return [
                'status' => 'rejected',
                'error'  => $e->getMessage(),
                'score'  => $result['score'],
            ];
        }

        return [
            'status'    => 'ok',
            'post_id'   => $postId,
            'score'     => $result['score'],
            'checks'    => $result['checks'],
            'persisted' => $persisted,
        ];
    }

    // ------------------------------------------------------------------
    // LLM-assisted optimization — suggestions, never SQL
    // ------------------------------------------------------------------

    /**
     * Ask the model for a title/description, then persist through the
     * registry. The model's text is only ever a bound parameter.
     */
    public function optimize(int $postId, ?Context $context = null): array
    {
        $context ??= new Context();
        $post = $this->fetchPost($postId);

        if ($post === null) {
            return ['status' => 'failed', 'error' => 'post_not_found', 'post_id' => $postId];
        }

        $system = <<<'PROMPT'
You are an SEO agent inside a CMS. Rewrite the given post's title and meta description.
Respond with ONLY raw JSON — no prose, no markdown fences — in exactly this shape:
{"meta_title":"<=60 chars","meta_description":"<=160 chars","focus_keyword":"one keyword"}
Never invent facts that are not supported by the post body.
PROMPT;

        $user = $context->toPromptBlock() . "\n\n"
            . "Current title: {$post['title']}\n"
            . "Current excerpt: {$post['excerpt']}\n"
            . "Body:\n" . mb_substr((string) $post['body_md'], 0, 4000);

        $content = $this->callLLM($system, $user, ['max_tokens' => 400, 'temperature' => 0.3]);

        if ($content === null) {
            return [
                'status'  => 'failed',
                'error'   => 'No LLM provider available (missing API key or rate limited)',
                'post_id' => $postId,
                'score'   => $this->score($post + ['meta_description' => ''])['score'],
            ];
        }

        $json = $this->extractJson($content);
        if ($json === null) {
            return ['status' => 'failed', 'error' => 'Model did not return valid JSON', 'post_id' => $postId];
        }

        $metaTitle = trim((string) ($json['meta_title'] ?? ''));
        $metaDesc  = trim((string) ($json['meta_description'] ?? ''));
        $keyword   = trim((string) ($json['focus_keyword'] ?? ''));

        if ($metaTitle === '' && $metaDesc === '') {
            return ['status' => 'failed', 'error' => 'Model returned no usable suggestions', 'post_id' => $postId];
        }

        // Persist through the allowlist — the model never supplies SQL.
        try {
            $persisted = $this->registry->execute([
                'action' => 'save_seo_meta',
                'params' => [
                    'entity_type'      => 'post',
                    'entity_id'        => $postId,
                    'meta_title'       => $metaTitle !== '' ? $metaTitle : null,
                    'meta_description' => $metaDesc !== '' ? $metaDesc : null,
                    'focus_keyword'    => $keyword !== '' ? $keyword : null,
                    'score'            => $this->score($post + [
                        'meta_description' => $metaDesc !== '' ? $metaDesc : (string) $post['excerpt'],
                    ])['score'],
                ],
            ], $context->userRole);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return ['status' => 'rejected', 'error' => $e->getMessage(), 'post_id' => $postId];
        }

        return [
            'status'    => 'ok',
            'post_id'   => $postId,
            'suggestion'=> ['meta_title' => $metaTitle, 'meta_description' => $metaDesc, 'focus_keyword' => $keyword],
            'persisted' => $persisted,
        ];
    }

    // ------------------------------------------------------------------
    // Legacy meta pack
    // ------------------------------------------------------------------

    private function metaPack(string $task, Context $ctx, array $context): array
    {
        $system = <<<'PROMPT'
You are an SEO agent inside a CMS. Given a page title/content brief, produce JSON with:
{
  "meta_title": "<=60 chars",
  "meta_description": "<=160 chars",
  "slug": "url-friendly-slug",
  "keywords": ["kw1", "kw2"],
  "og_title": "<=60 chars",
  "og_description": "<=160 chars",
  "schema_type": "Article|WebPage|BlogPosting",
  "headings": {"h2": ["..."], "h3": ["..."]},
  "internal_links": [{"anchor": "...", "suggestion": "..."}]
}
Respond with ONLY raw JSON — no prose, no markdown fences.
PROMPT;

        $content = $this->callLLM($system, $ctx->toPromptBlock() . "\n\n" . $task, [
            'max_tokens' => 1500,
            'temperature' => 0.3,
        ]);

        if ($content === null) {
            return ['status' => 'failed', 'error' => 'No LLM provider available'];
        }

        $seo = $this->extractJson($content);
        if ($seo === null) {
            return ['status' => 'failed', 'error' => 'Invalid JSON from LLM'];
        }

        // Persist through a job so the write is queued, never inline SQL.
        if (isset($context['entity_type'], $context['entity_id'])) {
            $this->enqueueJob('seo.persist', [
                'entity_type' => $context['entity_type'],
                'entity_id'   => $context['entity_id'],
                'seo'         => $seo,
            ]);
        }

        return ['status' => 'ok', 'seo' => $seo];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function fetchPost(int $postId): ?array
    {
        $stmt = $this->db->getPdo()->prepare(
            'SELECT p.id, p.slug, p.type, p.title, p.excerpt, p.body_md, p.status,
                    COALESCE(s.meta_description, \'\') AS meta_description,
                    s.meta_title, s.focus_keyword
             FROM posts p
             LEFT JOIN seo_meta s ON s.entity_type = \'post\' AND s.entity_id = CAST(p.id AS TEXT)
             WHERE p.id = ? LIMIT 1'
        );
        $stmt->execute([$postId]);

        return $stmt->fetch() ?: null;
    }

    /** "audit post 12", "optimize post 12" -> 12. Null when no id present. */
    private function extractPostId(string $task): ?int
    {
        if (preg_match('/\bpost\s*#?(\d+)\b/i', $task, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function extractCommand(string $task): ?string
    {
        $lower = strtolower($task);
        if (str_contains($lower, 'sitemap')) {
            return 'sitemap';
        }
        if (str_contains($lower, 'optimize')) {
            return 'optimize';
        }
        if (str_contains($lower, 'score') || str_contains($lower, 'audit')) {
            return 'score';
        }
        return null;
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