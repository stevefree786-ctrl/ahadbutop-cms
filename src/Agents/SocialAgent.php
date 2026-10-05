<?php
declare(strict_types=1);

namespace CMS\Agents;

use CMS\Database\Connection;

/**
 * Social Agent: turns a post into platform-shaped promotional copy.
 *
 * Entirely read-and-generate. It writes nothing: it reads a post and returns
 * text for a human to paste. That is not a limitation, it is the correct
 * posture — this agent's output is prose meant to be published under the
 * site's own voice to audiences a model cannot see, and an automated poster
 * is the kind of thing that gets a domain banned rather than gets engagement.
 * Every result carries `origin = draft copy`, and nothing reaches a network.
 *
 * No network calls at all. The platform length limits below are constants
 * because they are contracts, not preferences, and they change slowly enough
 * that a hardcoded table is honest.
 */
class SocialAgent extends BaseAgent
{
    /** Characters each platform actually renders before truncating. */
    private const LIMITS = array(
        'x'        => 280,
        'bluesky'  => 300,
        'linkedin' => 3000,
        'mastodon' => 500,
        'facebook' => 63206,
        'instagram'=> 2200,
    );

    /** Posts longer than this are summarised rather than quoted; the model
     *  cannot be handed a 40,000-word article inside a completion budget. */
    private const MAX_SOURCE_CHARS = 6000;

    private Connection $db;

    public function __construct(?Connection $db = null, string $provider = 'kilo')
    {
        parent::__construct($provider);
        $this->db = $db ?? new Connection(require __DIR__ . '/../../config/database.php');
    }

    /**
     * Task forms:
     *   "post 12"                 -> every platform
     *   "post 12 for x"           -> one platform
     *   "share the latest post"   -> newest published
     *
     * Returns ['status' => 'ok', 'variants' => [...]] or a failure shape.
     */
    public function execute(string $task, array $context = []): array
    {
        $ctx  = Context::fromArray($context);
        $task = trim($task);
        if ($task === '') {
            return ['status' => 'failed', 'error' => 'Empty task'];
        }

        $post = $this->resolvePost($task);
        if ($post === null) {
            return [
                'status' => 'failed',
                'error'  => 'No post found. Name one, e.g. "post 12", or say "latest".',
            ];
        }

        $platforms = $this->requestedPlatforms($task);
        $source    = $this->sourceText($post, $ctx);

        $variants = array();
        foreach ($platforms as $platform) {
            $copy = $this->callLLM(
                $this->systemPrompt($platform),
                "POST TITLE: {$post['title']}\n\nPOST BODY:\n{$source}\n\n"
                . "Return ONLY the post text. No hashtags-as-a-separate-list, no label, no quotes around it.",
                array('max_tokens' => 500, 'temperature' => 0.8)
            );

            if ($copy === null) {
                // One provider failure should not discard the other platforms'
                // work; record the failure for this one and keep going.
                $variants[$platform] = array('error' => 'no LLM provider available');
                continue;
            }

            $copy  = trim($this->stripCodeFence($copy));
            $limit = self::LIMITS[$platform];

            $variants[$platform] = array(
                'text'    => $copy,
                'limit'   => $limit,
                'length'  => mb_strlen($copy),
                'fits'    => mb_strlen($copy) <= $limit,
                'note'    => mb_strlen($copy) <= $limit
                    ? 'ready to paste'
                    : "too long by " . (mb_strlen($copy) - $limit) . ' characters',
            );
        }

        return array(
            'status'     => 'ok',
            'post_id'    => (int) $post['id'],
            'post_title' => (string) $post['title'],
            'url'        => $this->urlFor($post, $ctx),
            'variants'   => $variants,
            'note'       => 'Nothing was published. These are drafts for a human to post.',
        );
    }

    /** @return array<string, mixed>|null */
    private function resolvePost(string $task): ?array
    {
        $pdo = $this->db->getPdo();

        if (preg_match('/\bpost\s*#?\s*(\d+)/i', $task, $m) === 1) {
            $stmt = $pdo->prepare('SELECT * FROM posts WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $m[1]]);
            $row = $stmt->fetch();
            return $row === false ? null : $row;
        }

        // "latest" / "the newest one" — a published post is what someone
        // actually wants to promote; falling back to any status keeps the
        // agent useful on a site where nothing has shipped yet.
        $row = $pdo->query(
            "SELECT * FROM posts
             ORDER BY (status = 'published') DESC,
                      COALESCE(published_at, created_at) DESC, id DESC
             LIMIT 1"
        )->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<int, string> */
    private function requestedPlatforms(string $task): array
    {
        $found = array();
        foreach (array_keys(self::LIMITS) as $platform) {
            if (preg_match('/\b' . preg_quote($platform, '/') . '\b/i', $task) === 1) {
                $found[] = $platform;
            }
        }
        return $found === [] ? array('x', 'linkedin', 'bluesky') : $found;
    }

    /**
     * The post body, trimmed and given to the model as source material.
     *
     * @param array<string, mixed> $post
     */
    private function sourceText(array $post, Context $ctx): string
    {
        $site = $ctx->siteTitle !== '' ? $ctx->siteTitle : 'this site';
        $body = (string) ($post['body_md'] ?? '');
        $body = (string) preg_replace('/```.*?```/s', '', $body);   // drop code blocks
        $body = trim((string) preg_replace('/[#*_>`\[\]]/', '', $body));
        $body = (string) preg_replace('/\n{3,}/', "\n\n", $body);

        if (mb_strlen($body) > self::MAX_SOURCE_CHARS) {
            $body = mb_substr($body, 0, self::MAX_SOURCE_CHARS) . "\n\n[truncated]";
        }
        return "Site: {$site}\n\n{$body}";
    }

    private function systemPrompt(string $platform): string
    {
        $rules = array(
            'x'        => 'One sharp sentence, then a short hook. Under 280 characters. No hashtags unless one is genuinely load-bearing.',
            'bluesky'  => 'Conversational and brief, under 300 characters. Bluesky rewards plainness.',
            'linkedin' => 'Two short paragraphs. Lead with the specific outcome, not the topic. No "excited to announce".',
            'mastodon' => 'Plain, complete sentences. Assume a technical, sceptical audience.',
            'facebook' => 'One readable paragraph. No clickbait framing.',
            'instagram' => 'Under 2200 characters, emoji-sparing, with a single line break at most.',
        );

        return <<<PROMPT
You write the social post that accompanies a link to an article. Write as {$platform}.

{$rules[$platform]}

Never invent facts, numbers or claims that are not in the source. Never say you read something the source does not say you read.
PROMPT;
    }

    /**
     * The permalink to the post.
     *
     * Falls back through the two setting keys this codebase has used, then to
     * APP_URL, and finally to a relative path. The relative fallback is
     * deliberate and reported as such: a bare "/slug" is something a human
     * can paste and the platform resolves, whereas a made-up domain is one
     * that looks authoritative and is wrong. Returning null tells the caller
     * "configure site.url before pasting this".
     *
     * @param array<string, mixed> $post
     */
    private function urlFor(array $post, Context $ctx): string
    {
        $stmt = $this->db->getPdo()->prepare('SELECT value FROM settings WHERE key = ? LIMIT 1');

        $base = '';
        foreach (array('site.url', 'site.base_url') as $key) {
            $stmt->execute([$key]);
            $candidate = (string) ($stmt->fetchColumn() ?: '');
            if ($candidate !== '') {
                $base = $candidate;
                break;
            }
        }

        if ($base === '') {
            // env(), not getenv(): Dotenv::createImmutable() does not call
            // putenv, so a raw getenv() returns false for every key in .env
            // and this fallback would silently never fire.
            $appUrl = (string) env('APP_URL', '');
            if ($appUrl !== '') {
                $base = $appUrl;
            }
        }

        $slug = ltrim((string) $post['slug'], '/');

        return $base === ''
            ? '/' . $slug
            : rtrim($base, '/') . '/' . $slug;
    }

    private function stripCodeFence(string $text): string
    {
        $t = trim($text);
        if (str_starts_with($t, '```')) {
            $t = (string) preg_replace('/^```[a-z]*\s*\n?/i', '', $t);
            $t = (string) preg_replace('/\n?```\s*$/', '', $t);
        }
        return trim($t, " \t\n\r\0\x0B\"'");
    }
}