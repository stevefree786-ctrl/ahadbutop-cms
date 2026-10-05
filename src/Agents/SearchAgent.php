<?php
declare(strict_types=1);

namespace CMS\Agents;

use CMS\Database\Connection;

/**
 * Search Agent: finds posts and answers questions about the library.
 *
 * Read-only by construction. Every statement in this file is a SELECT, and
 * the only intent it touches is the read-only `list_posts`. There is no code
 * path from a task string to a write, which is the strongest form of the
 * allowlist rule the rest of the fleet follows: this agent cannot be made to
 * modify the database even if the model returns something adversarial.
 *
 * The query itself is built with bound parameters, never string interpolation
 * of user text. `search()` uses the FTS5 table when one exists and the
 * `posts_fts%` shadow tables are present, and falls back to LIKE otherwise —
 * a fresh checkout has no FTS index until the migrator builds it, and an
 * agent that hard-fails there is worse than one that degrades.
 */
class SearchAgent extends BaseAgent
{
    private Connection $db;
    private IntentRegistry $registry;

    /** Cap on rows handed back to the model. The model does not need the
     *  whole library, and an unbounded result set is how a "search my site"
     *  task turns into a context-window overflow. */
    private const MAX_RESULTS = 20;

    public function __construct(?Connection $db = null, string $provider = 'kilo')
    {
        parent::__construct($provider);
        $this->db = $db ?? new Connection(require __DIR__ . '/../../config/database.php');
        $built = Actions::build($this->db);
        $this->registry = new IntentRegistry($this->db, $built['schema'], $built['writers']);
    }

    /**
     * Task forms:
     *   "wordpress alternatives"   -> search titles/excerpts/bodies
     *   "count drafts"             -> report on status buckets
     *   "how many posts?"          -> same
     *
     * Returns ['status' => 'ok', ...] or ['status' => 'failed', 'error' => ...].
     */
    public function execute(string $task, array $context = []): array
    {
        $ctx  = Context::fromArray($context);
        $task = trim($task);
        if ($task === '') {
            return ['status' => 'failed', 'error' => 'Empty task'];
        }

        $stats = $this->stats();
        $term  = $this->searchTerm($task);

        if ($term === null) {
            // No searchable phrase in the task — answer with the census, which
            // is genuinely the most useful thing to know about an empty site.
            return [
                'status'  => 'ok',
                'summary' => $this->summarise($stats),
                'stats'   => $stats,
                'matches' => array(),
            ];
        }

        $matches = $this->search($term);
        $answer  = $this->callLLM(
            "You are a CMS search assistant. Answer using ONLY the post excerpts "
            . "provided. If they do not contain the answer, say so plainly.",
            "Question: {$task}\n\nMatching posts:\n"
            . json_encode(array_map(
                static fn (array $r): array => [
                    'id'      => (int) $r['id'],
                    'title'   => (string) $r['title'],
                    'status'  => (string) $r['status'],
                    'excerpt' => (string) ($r['excerpt'] ?? ''),
                ],
                $matches
            ), JSON_PRETTY_PRINT),
            ['max_tokens' => 700, 'temperature' => 0.2]
        );

        return [
            'status'    => 'ok',
            'query'     => $term,
            'answer'    => $answer,
            'matches'   => $matches,
            'match_count' => count($matches),
            'stats'     => $stats,
        ];
    }

    /**
     * Pull a search phrase out of the task.
     *
     * Strips the imperative wrappers people actually type ("search for",
     * "find posts about") so the phrase reaches FTS as content rather than
     * as stopwords. Returns null when nothing meaningful is left, which the
     * caller reads as "this was a census question, not a search".
     */
    private function searchTerm(string $task): ?string
    {
        $t = preg_replace(
            '/\b(?:please\s+)?(?:search(?:\s+for)?|find|look\s+(?:for|up)|show|list|get)\s+/i',
            '',
            $task
        ) ?? $task;

        $t = trim(preg_replace('/[?!.]+$/', '', $t) ?? $t);
        $t = trim($t, '" \'');

        if ($t === '' || mb_strlen($t) < 2) {
            return null;
        }

        // An FTS5 MATCH string is not a LIKE pattern. Quoting the phrase makes
        // it a literal string, so a user typing `title:"x"` or an unbalanced
        // quote cannot produce the syntax error that would otherwise surface
        // as a 500 from a routine question.
        return '"' . str_replace('"', '""', $t) . '"';
    }

    /**
     * Run the search, preferring FTS5 and degrading to LIKE.
     *
     * Both paths bind the term as a parameter. There is no interpolation of
     * user text into SQL anywhere in this method.
     *
     * @return array<int, array<string, mixed>>
     */
    private function search(string $ftsTerm): array
    {
        $pdo = $this->db->getPdo();

        $like = '%' . trim($ftsTerm, '"') . '%';

        if ($this->hasFts()) {
            try {
                $stmt = $pdo->prepare(
                    "SELECT p.id, p.title, p.slug, p.status, p.excerpt,
                            p.published_at, p.author_id
                     FROM posts_fts f
                     JOIN posts p ON p.id = f.rowid
                     WHERE posts_fts MATCH ?
                     ORDER BY rank
                     LIMIT ?"
                );
                $stmt->bindValue(1, $ftsTerm, \PDO::PARAM_STR);
                $stmt->bindValue(2, self::MAX_RESULTS, \PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll();
                if ($rows !== []) {
                    return $rows;
                }
                // FTS matched nothing. Fall through to LIKE so a phrase the
                // index tokenises differently still finds the post.
            } catch (\PDOException) {
                // A malformed MATCH is not the caller's problem to debug.
            }
        }

        $stmt = $pdo->prepare(
            "SELECT id, title, slug, status, excerpt, published_at, author_id
             FROM posts
             WHERE title LIKE ? ESCAPE '\\'
                OR excerpt LIKE ? ESCAPE '\\'
                OR body_md LIKE ? ESCAPE '\\'
             ORDER BY published_at DESC, id DESC
             LIMIT ?"
        );
        $stmt->execute([$like, $like, $like, self::MAX_RESULTS]);
        return $stmt->fetchAll();
    }

    /** Whether the FTS5 shadow tables were built. */
    private function hasFts(): bool
    {
        $stmt = $this->db->getPdo()->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='posts_fts'"
        );
        return ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * A census of the library, answered from the database rather than the
     * model — counts are not something to ask a language model for.
     *
     * @return array<string, mixed>
     */
    private function stats(): array
    {
        $pdo = $this->db->getPdo();

        $byStatus = array();
        $stmt = $pdo->query('SELECT status, COUNT(*) AS n FROM posts GROUP BY status ORDER BY status');
        foreach ($stmt->fetchAll() as $r) {
            $byStatus[(string) $r['status']] = (int) $r['n'];
        }

        $totals = $pdo->query(
            "SELECT COUNT(*) AS posts,
                    COALESCE(SUM(word_count), 0) AS words,
                    COALESCE(ROUND(AVG(NULLIF(word_count, 0))), 0) AS avg_words
             FROM posts"
        )->fetch() ?: array();

        return array(
            'by_status'  => $byStatus,
            'posts'      => (int) ($totals['posts'] ?? 0),
            'words'      => (int) ($totals['words'] ?? 0),
            'avg_words'  => (int) ($totals['avg_words'] ?? 0),
            'tags'       => (int) $pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn(),
            'categories' => (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn(),
            'media'      => (int) $pdo->query('SELECT COUNT(*) FROM media')->fetchColumn(),
        );
    }

    private function summarise(array $s): string
    {
        if ($s['posts'] === 0) {
            return 'The library is empty — no posts exist yet.';
        }
        $parts = array();
        foreach ($s['by_status'] as $status => $n) {
            $parts[] = "{$n} {$status}";
        }
        return sprintf(
            '%d post(s) (%s), %d words total, average %d words. %d tag(s), %d categor(y/ies), %d media file(s).',
            $s['posts'],
            implode(', ', $parts),
            $s['words'],
            $s['avg_words'],
            $s['tags'],
            $s['categories'],
            $s['media']
        );
    }
}