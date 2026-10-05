<?php
declare(strict_types=1);

namespace CMS\Agents;

use CMS\Database\Connection;

/**
 * Analytics Agent: reports what the site actually contains and when it moved.
 *
 * Read-only. Every statement is a SELECT and there is no intent call in this
 * file at all — it has no writer, so it cannot mutate anything even in principle.
 *
 * There is no analytics table in the schema: no page views, no sessions, no
 * referrers. So this agent does not invent traffic numbers, which is the
 * single most important thing it does NOT do. "Ten thousand monthly visitors"
 * from a model is a fabrication, and a CMS that reports it is worse than one
 * that reports nothing. What it reports instead is content analytics — counts,
 * lengths, status mix, publishing cadence, engagement signals that exist as
 * columns — and it says plainly that traffic data is not collected, so the
 * gap is visible rather than silent.
 */
class AnalyticsAgent extends BaseAgent
{
    private Connection $db;

    public function __construct(?Connection $db = null, string $provider = 'kilo')
    {
        parent::__construct($provider);
        $this->db = $db ?? new Connection(require __DIR__ . '/../../config/database.php');
    }

    /**
     * Task forms:
     *   "how is the site doing"
     *   "content report"
     *   "which posts need work"
     *
     * Returns ['status' => 'ok', 'metrics' => [...]] or a failure shape.
     */
    public function execute(string $task, array $context = []): array
    {
        $ctx  = Context::fromArray($context);
        $task = trim($task);
        if ($task === '') {
            return ['status' => 'failed', 'error' => 'Empty task'];
        }

        $metrics = array(
            'content'    => $this->contentMetrics(),
            'cadence'    => $this->cadence(),
            'seo_health' => $this->seoHealth(),
            'top_posts'  => $this->topPosts(),
            'jobs'       => $this->jobHealth(),
        );

        return array(
            'status'      => 'ok',
            'metrics'     => $metrics,
            'summary'     => $this->summarise($metrics),
            'limitations' => array(
                'No traffic data is collected: this schema records no page views, '
                . 'sessions or referrers, so no traffic figures are reported.',
                'Counts describe content only. They are not audience measurement.',
            ),
        );
    }

    /** @return array<string, mixed> */
    private function contentMetrics(): array
    {
        $pdo = $this->db->getPdo();

        $byStatus = array();
        foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM posts GROUP BY status') as $r) {
            $byStatus[(string) $r['status']] = (int) $r['n'];
        }

        $totals = $pdo->query(
            'SELECT COUNT(*) AS posts,
                    COALESCE(SUM(word_count), 0) AS words,
                    COALESCE(ROUND(AVG(NULLIF(word_count, 0))), 0) AS avg_words,
                    COALESCE(ROUND(AVG(NULLIF(reading_time, 0))), 0) AS avg_reading_minutes,
                    COALESCE(SUM(CASE WHEN allow_comments = 1 THEN 1 ELSE 0 END), 0) AS commentable
             FROM posts'
        )->fetch() ?: array();

        return array(
            'posts'               => (int) ($totals['posts'] ?? 0),
            'by_status'           => $byStatus,
            'total_words'         => (int) ($totals['words'] ?? 0),
            'avg_words'           => (int) ($totals['avg_words'] ?? 0),
            'avg_reading_minutes' => (int) ($totals['avg_reading_minutes'] ?? 0),
            'commentable'         => (int) ($totals['commentable'] ?? 0),
            'tags'                => (int) $pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn(),
            'categories'          => (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn(),
            'media'               => (int) $pdo->query('SELECT COUNT(*) FROM media')->fetchColumn(),
            'comments'            => (int) $pdo->query('SELECT COUNT(*) FROM comments')->fetchColumn(),
            'users'               => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn(),
        );
    }

    /** @return array<string, mixed> */
    private function cadence(): array
    {
        $pdo = $this->db->getPdo();

        $row = $pdo->query(
            "SELECT MIN(published_at) AS first, MAX(published_at) AS last,
                    COUNT(*) AS n
             FROM posts WHERE status = 'published' AND published_at IS NOT NULL"
        )->fetch() ?: array();

        $n         = (int) ($row['n'] ?? 0);
        $first     = (string) ($row['first'] ?? '');
        $last      = (string) ($row['last'] ?? '');
        $perMonth  = null;

        if ($n > 0 && $first !== '' && $last !== '') {
            $days = max(1, (int) ((strtotime($last) - strtotime($first)) / 86400) + 1);
            $perMonth = round($n * 30 / $days, 1);
        }

        return array(
            'published'      => $n,
            'first_publish'  => $first === '' ? null : $first,
            'last_publish'   => $last === '' ? null : $last,
            'per_month'      => $perMonth,
        );
    }

    /** @return array<string, mixed> */
    private function seoHealth(): array
    {
        $pdo = $this->db->getPdo();

        $row = $pdo->query(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN meta_title    IS NOT NULL AND meta_title    != '' THEN 1 ELSE 0 END) AS has_title,
                SUM(CASE WHEN meta_description IS NOT NULL AND meta_description != '' THEN 1 ELSE 0 END) AS has_description,
                SUM(CASE WHEN canonical_url IS NOT NULL AND canonical_url != '' THEN 1 ELSE 0 END) AS has_canonical,
                SUM(CASE WHEN focus_keyword IS NOT NULL AND focus_keyword != '' THEN 1 ELSE 0 END) AS has_keyword
             FROM seo_meta WHERE entity_type = 'post'"
        )->fetch() ?: array();

        $total = (int) ($row['total'] ?? 0);

        return array(
            'tagged_posts'   => $total,
            'with_title'     => (int) ($row['has_title'] ?? 0),
            'with_description' => (int) ($row['has_description'] ?? 0),
            'with_canonical' => (int) ($row['has_canonical'] ?? 0),
            'with_keyword'   => (int) ($row['has_keyword'] ?? 0),
            'coverage'       => $total === 0
                ? null
                : round(100 * (int) ($row['has_description'] ?? 0) / $total, 1),
        );
    }

    /**
     * The longest posts, as candidates for trimming.
     *
     * Ranked by length because it is the one engagement signal the schema can
     * actually support. A "most viewed" list would require inventing a column.
     *
     * @return array<int, array<string, mixed>>
     */
    private function topPosts(): array
    {
        $stmt = $this->db->getPdo()->query(
            "SELECT id, title, slug, status, word_count, reading_time, published_at
             FROM posts
             ORDER BY COALESCE(word_count, 0) DESC, id DESC
             LIMIT 10"
        );
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed> */
    private function jobHealth(): array
    {
        $pdo = $this->db->getPdo();

        $byStatus = array();
        foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM jobs GROUP BY status') as $r) {
            $byStatus[(string) $r['status']] = (int) $r['n'];
        }

        return array(
            'by_status'    => $byStatus,
            'stuck_running' => (int) $pdo->query(
                "SELECT COUNT(*) FROM jobs WHERE status = 'running' AND locked_at < datetime('now', '-10 minutes')"
            )->fetchColumn(),
            'failed_recent' => (int) $pdo->query(
                "SELECT COUNT(*) FROM jobs WHERE status = 'failed' AND created_at > datetime('now', '-7 days')"
            )->fetchColumn(),
        );
    }

    /** @param array<string, mixed> $m */
    private function summarise(array $m): string
    {
        $c = $m['content'];
        if ($c['posts'] === 0) {
            return 'The site has no posts yet.';
        }

        $published = $c['by_status']['published'] ?? 0;
        $cadence   = $m['cadence']['per_month'];
        $cadenceTxt = $cadence === null ? '' : sprintf(' Roughly %s posts per month.', $cadence);

        $seo = $m['seo_health']['coverage'];
        $seoTxt = $seo === null
            ? ''
            : sprintf(' %s%% of tagged posts have a meta description.', $seo);

        return sprintf(
            '%d post(s), %d published, %d words total, average %d words (%d min read).%s%s',
            $c['posts'],
            $published,
            $c['total_words'],
            $c['avg_words'],
            $c['avg_reading_minutes'],
            $cadenceTxt,
            $seoTxt
        );
    }
}