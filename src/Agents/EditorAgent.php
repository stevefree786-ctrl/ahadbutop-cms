<?php
declare(strict_types=1);

namespace CMS\Agents;

use CMS\Database\Connection;

/**
 * Editor Agent: improves, rewrites and fact-checks existing posts.
 *
 * This is the first agent in the fleet whose whole job is a write to someone
 * else's prose, so it is the one where the allowlist matters most. The model
 * is handed the current text and asked for a replacement; that replacement is
 * stored ONLY after passing through the `save_draft_post` intent, which is the
 * one validated path that writes a post body. The model cannot name a table,
 * a column or a post id — it returns prose and a title, and nothing else.
 *
 * Two deliberate constraints:
 *
 *   The rewrite is saved as a DRAFT, never published. Even an editor asking
 *   "tighten this and ship it" gets a draft, because a model rewriting an
 *   article can quietly drop a qualification or invert a claim, and that is
 *   not the kind of thing an automated step should be able to do unattended.
 *
 *   It never overwrites in place. The result lands as a new post carrying
 *   `origin = ai`, which is how the editorial queue tells agent output apart
 *   from human writing. If the caller wanted to destroy the original, that
 *   should be a separate, explicit action.
 */
class EditorAgent extends BaseAgent
{
    private Connection $db;
    private IntentRegistry $registry;

    /** Refuse to ship a "rewrite" that is mostly gone. A model that returns
     *  an empty or stub body has failed, and replacing a 2,000 word article
     *  with three lines is a data-loss bug wearing a success message. */
    private const MIN_BODY_CHARS = 40;

    public function __construct(?Connection $db = null, string $provider = 'kilo')
    {
        parent::__construct($provider);
        $this->db = $db ?? new Connection(require __DIR__ . '/../../config/database.php');
        $built = Actions::build($this->db);
        $this->registry = new IntentRegistry($this->db, $built['schema'], $built['writers']);
    }

    /**
     * Task forms:
     *   "tighten post 12"          -> rewrite for concision
     *   "make post 12 more SEO"    -> rewrite for search
     *   "fix grammar on post 12"   -> copy edit
     *
     * Returns ['status' => 'ok'|'failed'|'rejected', ...].
     */
    public function execute(string $task, array $context = []): array
    {
        $ctx  = Context::fromArray($context);
        $task = trim($task);
        if ($task === '') {
            return ['status' => 'failed', 'error' => 'Empty task'];
        }

        // Fail before the model call, not after: rewriting is an editor act
        // and spending a provider call to then refuse the write is wasteful.
        if (!$ctx->isAtLeast('editor')) {
            return [
                'status' => 'rejected',
                'error'  => "Rewriting posts requires the 'editor' role; caller is '{$ctx->userRole}'",
            ];
        }

        $postId = $this->targetPost($task);
        if ($postId === null) {
            return [
                'status' => 'failed',
                'error'  => 'No post id in the task. Say, for example: "tighten post 12"',
            ];
        }

        $post = $this->loadPost($postId);
        if ($post === null) {
            return ['status' => 'failed', 'error' => "Post {$postId} does not exist"];
        }

        $body = (string) $post['body_md'];
        if (trim($body) === '') {
            return [
                'status' => 'failed',
                'error'  => "Post {$postId} has an empty body — nothing to edit",
            ];
        }

        $rewrite = $this->callLLM(
            $this->systemPrompt($task),
            "TITLE: {$post['title']}\n\nCURRENT BODY:\n{$body}\n\n"
            . "Return ONLY the rewritten Markdown body. No frontmatter, no preamble.",
            ['max_tokens' => 4096, 'temperature' => 0.5]
        );

        if ($rewrite === null) {
            return ['status' => 'failed', 'error' => 'No LLM provider available'];
        }

        $rewrite = trim($this->stripCodeFence($rewrite));
        if (mb_strlen($rewrite) < self::MIN_BODY_CHARS) {
            return [
                'status' => 'failed',
                'error'  => 'The model returned a rewrite too short to be usable; the original is untouched',
            ];
        }

        // This is the ONLY write in this file, and it goes through the
        // registry with typed params. The model's output is data, not code.
        $saved = $this->registry->execute(array(
            'action' => 'save_draft_post',
            'params' => array(
                'title'     => $post['title'] . ' (edited)',
                'slug'      => $this->slugFor($post),
                'excerpt'   => (string) ($post['excerpt'] ?? ''),
                'body_md'   => $rewrite,
                'author_id' => $ctx->userId,
            ),
        ), $ctx->userRole);

        return array(
            'status'      => 'ok',
            'source_post' => (int) $post['id'],
            'draft_id'    => (int) ($saved['post_id'] ?? 0),
            'words_before' => (int) ($post['word_count'] ?? 0),
            'words_after'  => $this->wordCount($rewrite),
            'note'        => 'Saved as a new draft. The original post is unchanged.',
        );
    }

    /** First post id mentioned in the task, or null. */
    private function targetPost(string $task): ?int
    {
        if (preg_match('/\bpost\s*#?\s*(\d+)/i', $task, $m) === 1) {
            return (int) $m[1];
        }
        if (preg_match('/\b(\d+)\b/', $task, $m) === 1) {
            return (int) $m[1];
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    private function loadPost(int $id): ?array
    {
        $stmt = $this->db->getPdo()->prepare(
            'SELECT id, title, slug, excerpt, body_md, word_count, status
             FROM posts WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * A slug for the draft.
     *
     * Appends a discriminator derived from the ORIGINAL post's id, which is
     * stable and short. A content-derived slug would need hashing the body to
     * guarantee uniqueness, and two edits of the same post a minute apart
     * would then collide on the same hash — the registry handles that, but
     * there is no reason to make it do so.
     */
    private function slugFor(array $post): string
    {
        $base = trim((string) $post['slug']);
        if ($base === '') {
            $base = 'post-' . $post['id'];
        }
        return $base . '-edit-' . $post['id'];
    }

    private function systemPrompt(string $task): string
    {
        return <<<PROMPT
You are a line editor inside a CMS. Given a task and a Markdown article, return the article rewritten.

Rules:
- Keep every factual claim the original made unless the task asked you to change it.
- Do not invent facts, statistics, citations, quotes or names.
- Preserve the Markdown heading structure unless asked otherwise.
- Match the original's language.
- Respond with ONLY the rewritten Markdown body, no frontmatter and no commentary.

TASK: {$task}
PROMPT;
    }

    /** Models wrap output in ``` fences often enough that not stripping them
     *  means the literal "```markdown" ends up in the published body. */
    private function stripCodeFence(string $text): string
    {
        $t = trim($text);
        if (str_starts_with($t, '```')) {
            $t = (string) preg_replace('/^```[a-z]*\s*\n?/i', '', $t);
            $t = (string) preg_replace('/\n?```\s*$/', '', $t);
        }
        return trim($t);
    }

    private function wordCount(string $text): int
    {
        $n = preg_split('/\s+/u', trim(strip_tags($text))) ?: array();
        return count(array_filter($n, static fn ($w) => $w !== ''));
    }
}