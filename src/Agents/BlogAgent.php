<?php
namespace CMS\Agents;

use CMS\Database\Connection;

/**
 * Blog Agent: generates posts, drafts, outlines from master prompt
 */
class BlogAgent extends BaseAgent
{
    private ?Connection $db = null;

    public function __construct(?Connection $db = null, string $provider = 'kilo')
    {
        parent::__construct($provider);
        $this->db = $db;
    }

    public function execute(string $task, array $context = []): array
    {
        $ctx = Context::fromArray($context);

        $system = <<<'PROMPT'
You are a blog writing agent inside a CMS. Given a task, produce a complete blog post in Markdown with:
- YAML frontmatter (title, slug, excerpt, tags, category, status: draft)
- Engaging body (800-1500 words) with H2/H3 sections
- A meta description under 160 chars
Respond with ONLY the Markdown document, no commentary.
PROMPT;

        $content = $this->callLLM($system, $ctx->toPromptBlock() . "\n\n" . $task, [
            'max_tokens' => 4096,
            'temperature' => 0.8,
        ]);

        if ($content === null) {
            return ['status' => 'failed', 'error' => 'No LLM provider available (missing API key or rate limited)'];
        }

        // Attribute the draft to whoever asked for it, not a hardcoded id.
        $authorId = $ctx->userId ?? 1;

        // Save as a draft post
        $postId = $this->savePost($content, 'draft', $authorId);

        return [
            'status' => 'created',
            'post_id' => $postId,
            'content_preview' => mb_substr($content, 0, 300),
        ];
    }

    private function savePost(string $markdown, string $status, int $authorId = 1): int
    {
        $frontmatter = $this->parseFrontmatter($markdown);
        $body = $this->stripFrontmatter($markdown);

        $pdo = $this->pdo();
        $slug = $this->uniqueSlug($frontmatter['slug'] ?? $frontmatter['title'] ?? 'post');
        $body = trim($body);
        $words = str_word_count(strip_tags($body));

        $stmt = $pdo->prepare(
            "INSERT INTO posts
                (uuid, slug, type, title, excerpt, body_md, status, origin, author_id,
                 word_count, reading_time, created_at, updated_at, published_at)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'), ?)"
        );
        $stmt->execute([
            bin2hex(random_bytes(16)),
            $slug,
            'post',
            $frontmatter['title'] ?? 'Untitled',
            $frontmatter['excerpt'] ?? '',
            $body,
            $status,
            'ai',
            $authorId,
            $words,
            max(1, (int) ceil($words / 220)),
            // SQLite datetime('now') is UTC, so published_at must be too.
            $status === 'published'
                ? $pdo->query("SELECT datetime('now')")->fetchColumn()
                : null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Slugs are unique per (type, slug), so a generated slug may collide
     * with an existing post. Append a short suffix until it is free.
     */
    private function uniqueSlug(string $raw): string
    {
        $pdo = $this->pdo();

        $base = strtolower(trim($raw));
        $base = preg_replace('/[^a-z0-9]+/i', '-', $base) ?: 'post';
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'post';
        }

        $slug = $base;
        $n = 2;
        $stmt = $pdo->prepare("SELECT 1 FROM posts WHERE type = ? AND slug = ? LIMIT 1");

        while (true) {
            $stmt->execute(['post', $slug]);
            if (!$stmt->fetch()) {
                return $slug;
            }
            $slug = $base . '-' . $n++;
        }
    }

    private function pdo(): \PDO
    {
        return ($this->db ?? new Connection(require __DIR__ . '/../../config/database.php'))->getPdo();
    }

    private function parseFrontmatter(string $md): array
    {
        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $md, $m)) {
            return [];
        }
        $result = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^(\w+):\s*(.+)$/', trim($line), $kv)) {
                $result[$kv[1]] = trim($kv[2], " \t\"'");
            }
        }
        return $result;
    }

    private function stripFrontmatter(string $md): string
    {
        return preg_replace('/^---\s*\n.*?\n---\s*\n/s', '', $md);
    }
}