<?php
namespace CMS\Repository;

/**
 * Posts and pages. `posts` is a single table with `type` in
 * ('post','page') — WordPress-style — so both share this repository.
 */
class PostRepository extends Repository
{
    protected const TABLE = 'posts';
    protected const COLUMNS = [
        'id', 'uuid', 'slug', 'type', 'title', 'excerpt', 'body_md', 'status',
        'origin', 'author_id', 'featured_media_id', 'template', 'menu_order',
        'word_count', 'reading_time', 'revision', 'created_at', 'updated_at',
        'published_at', 'scheduled_at',
    ];

    public function find(int $id): ?array
    {
        return $this->selectOne('id = ?', [$id]);
    }

    public function findBySlug(string $slug, string $type = 'post'): ?array
    {
        return $this->selectOne('slug = ? AND type = ?', [$slug, $type]);
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne('uuid = ?', [$uuid]);
    }

    /**
     * List with filters. All filters are bound, none interpolated.
     *
     * `public` is opt-in and must be set for any listing shown on the public
     * site. paginate() itself defaults to the admin's view (`status != 'trash'`),
     * which includes drafts, review items and future-dated posts — correct for
     * the admin list, wrong for the home page. The public predicate is applied
     * here rather than baked into buildFilters() precisely so that widening the
     * admin default can never silently widen the public one.
     */
    public function paginate(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        [$where, $params] = $this->buildFilters($filters);

        if (!empty($filters['public'])) {
            // Exactly the published predicate — no `status != 'trash'` here.
            // That exclusion is applied upstream, at the one public entry
            // point, because this filter set is caller-controlled: a caller
            // that passes status='trash' along with public=true would
            // otherwise land on "status = 'published' AND status = 'trash'"
            // and get nothing, which looks like a bug at every call site
            // rather than here. A trashed post 404s by path (pageRoute()
            // and postRoute() both resolve through findPublishedBySlug) and
            // never reaches a listing, so nothing public loses the row.
            $where .= " AND status = 'published'"
                   . " AND (published_at IS NULL OR published_at <= datetime('now'))";
        }

        $limit  = max(1, min($limit, 200));
        $offset = max(0, $offset);

        $rows = $this->select(
            $where,
            $params,
            $this->buildOrder($filters),
            'LIMIT ? OFFSET ?',
            [$limit, $offset]
        );

        return [
            'items' => $rows,
            'total' => $this->countWhere($where, $params),
            'limit' => $limit,
            'offset'=> $offset,
            'pages' => (int) ceil($this->countWhere($where, $params) / $limit),
        ];
    }

    private function countWhere(string $where, array $params): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . $this->table();
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $stmt = $this->db->getPdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{0:string,1:array} */
    private function buildFilters(array $f): array
    {
        $clauses = [];
        $params  = [];

        $clauses[] = "status != 'trash'";

        if (!empty($f['type'])) {
            $clauses[] = 'type = ?';
            $params[]  = $f['type'];
        }
        if (!empty($f['status'])) {
            $clauses[] = 'status = ?';
            $params[]  = $f['status'];
        }
        if (!empty($f['author_id'])) {
            $clauses[] = 'author_id = ?';
            $params[]  = (int) $f['author_id'];
        }
        if (!empty($f['origin'])) {
            $clauses[] = 'origin = ?';
            $params[]  = $f['origin'];
        }
        if (!empty($f['search'])) {
            // LIKE with a bound param — the wildcards are part of the value.
            $clauses[] = '(title LIKE ? OR excerpt LIKE ?)';
            $params[]  = '%' . $f['search'] . '%';
            $params[]  = '%' . $f['search'] . '%';
        }

        return [implode(' AND ', $clauses), $params];
    }

    private function buildOrder(array $f): string
    {
        return match ($f['order'] ?? 'recent') {
            'oldest'  => 'created_at ASC',
            'title'   => 'title ASC',
            'reading' => 'reading_time DESC',
            // Explicit hand-ordering, and the tie-break that makes it stable:
            // two pages both left at the default menu_order must not shuffle
            // between requests, or the nav appears to reorder itself on reload.
            'menu'    => 'menu_order ASC, title ASC',
            default   => 'created_at DESC',
        };
    }

    /**
     * Published pages for the site navigation, in author-controlled order.
     *
     * This is the read that makes menu_order mean anything. Before it, the
     * header was synthesised from the top eight tags — a list of topics, not
     * of pages — so an AI-driven "reorder the navigation" edit had nowhere to
     * land even though the column existed.
     *
     * Visibility is decided here and not at the template: only published rows
     * with a due-or-no publish date reach the nav, exactly as for any other
     * public listing.
     *
     * @return array<int,array{id:int,slug:string,title:string,menu_order:int}>
     */
    public function navPages(int $limit = 12): array
    {
        $limit = max(1, min($limit, 50));

        $rows = $this->select(
            "type = 'page' AND status = 'published'"
            . " AND (published_at IS NULL OR published_at <= datetime('now'))",
            [],
            'menu_order ASC, title ASC',
            'LIMIT ' . $limit
        );

        return array_values(array_map(
            static fn (array $r): array => [
                'id'         => (int) $r['id'],
                'slug'       => (string) $r['slug'],
                'title'      => (string) $r['title'],
                'menu_order' => (int) $r['menu_order'],
            ],
            $rows
        ));
    }

    public function create(array $data): int
    {
        $row = [
            'uuid'       => $data['uuid'] ?? bin2hex(random_bytes(16)),
            'slug'       => $data['slug'] ?? 'untitled',
            'type'       => $data['type'] ?? 'post',
            'title'      => $data['title'] ?? 'Untitled',
            'excerpt'    => $data['excerpt'] ?? null,
            'body_md'    => $data['body_md'] ?? '',
            'status'     => $data['status'] ?? 'draft',
            'origin'     => $data['origin'] ?? 'manual',
            'author_id'  => $data['author_id'] ?? null,
            'created_at' => $data['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        foreach (['featured_media_id', 'template', 'menu_order', 'published_at', 'scheduled_at'] as $opt) {
            if (array_key_exists($opt, $data)) {
                $row[$opt] = $data[$opt];
            }
        }

        // Recompute derived metrics from the body so they can never drift.
        $row['word_count']   = $this->wordCount((string) $row['body_md']);
        $row['reading_time'] = $this->readingTime((int) $row['word_count']);

        if ($row['status'] === 'published' && empty($row['published_at'])) {
            $row['published_at'] = date('Y-m-d H:i:s');
        }

        // The DB enforces UNIQUE(type, slug). Resolving the collision here turns
        // a 500 into a working post; letting the constraint fire means two posts
        // titled the same thing cannot both exist, which is a content bug
        // masquerading as a database error. uniqueSlug() is scoped by type, so
        // a post and a page may share a slug.
        $row['slug'] = $this->uniqueSlug((string) $row['slug'], null, (string) $row['type']);

        return $this->insertRow($row);
    }

    public function update(int $id, array $data): int
    {
        if (array_key_exists('body_md', $data)) {
            $data['word_count']   = $this->wordCount((string) $data['body_md']);
            $data['reading_time'] = $this->readingTime((int) $data['word_count']);
        }
        $data['updated_at'] = date('Y-m-d H:i:s');

        // Same collision handling as create(): renaming a post onto a slug
        // another post already holds must not raise a constraint violation.
        if (array_key_exists('slug', $data) && $data['slug'] !== '') {
            $existing = $this->find($id);
            $type = (string) ($existing['type'] ?? 'post');
            $data['slug'] = $this->uniqueSlug((string) $data['slug'], $id, $type);
        }

        return $this->updateRow($id, $data);
    }

    /**
     * Publishing is a state transition with invariants, not a field write:
     * it stamps published_at once, never downgrades a trash item, and
     * records an audit trail.
     */
    public function publish(int $id): bool
    {
        $post = $this->find($id);
        if ($post === null || in_array($post['status'], ['published', 'trash'], true)) {
            return false;
        }
        return $this->updateRow($id, [
            'status'       => 'published',
            'published_at' => $post['published_at'] ?: date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]) > 0;
    }

    public function unpublish(int $id): bool
    {
        $post = $this->find($id);
        if ($post === null || $post['status'] !== 'published') {
            return false;
        }
        // published_at is kept so unpublishing is reversible.
        return $this->updateRow($id, ['status' => 'draft', 'updated_at' => date('Y-m-d H:i:s')]) > 0;
    }

    public function trash(int $id): bool
    {
        return $this->updateRow($id, ['status' => 'trash', 'updated_at' => date('Y-m-d H:i:s')]) > 0;
    }

    public function restore(int $id): bool
    {
        $post = $this->find($id);
        if ($post === null) {
            return false;
        }
        return $this->updateRow($id, ['status' => 'draft', 'updated_at' => date('Y-m-d H:i:s')]) > 0;
    }

    /**
     * Slug must be unique per (type, slug) — append a suffix until free.
     *
     * $type matters: the table's uniqueness constraint is on the PAIR, so a post
     * and a page may legitimately share a slug. Checking scope 'post' while
     * creating a page would wrongly disambiguate, and checking the wrong scope
     * the other way would hand back a slug the insert then rejects.
     */
    public function uniqueSlug(string $base, ?int $ignoreId = null, string $type = 'post'): string
    {
        $base = slugify($base);
        if ($base === '') {
            $base = 'untitled';
        }
        $slug = $base;
        $n    = 2;

        while (true) {
            $sql    = 'SELECT id FROM posts WHERE type = ? AND slug = ?';
            $params = [$type, $slug];
            if ($ignoreId !== null) {
                $sql .= ' AND id != ?';
                $params[] = $ignoreId;
            }
            $stmt = $this->db->getPdo()->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);

            if (!$stmt->fetchColumn()) {
                return $slug;
            }
            $slug = $base . '-' . $n++;
        }
    }

    /** Posts eligible for publication at the given time (scheduled drip). */
    public function dueForPublication(string $now): array
    {
        return $this->select(
            "status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= ?",
            [$now],
            'scheduled_at ASC'
        );
    }

    /**
     * Published posts, newest first.
     *
     * The `published_at <= now` half of the predicate is not optional. A post
     * scheduled for next month is already status='published' (the scheduler
     * flips the status, and `create()` stamps published_at up front), so a
     * status-only filter would list tomorrow's posts today. findPublishedBySlug()
     * guards the same way; both must, or a single-post view and its listing
     * disagree about what is public.
     */
    public function published(int $limit = 10, int $offset = 0, ?string $type = null): array
    {
        $where  = "status = 'published'"
                . " AND (published_at IS NULL OR published_at <= datetime('now'))";
        $params = [];
        if ($type !== null) {
            $where .= ' AND type = ?';
            $params[] = $type;
        }
        return $this->select(
            $where,
            $params,
            'published_at DESC',
            'LIMIT ? OFFSET ?',
            [max(1, min($limit, 200)), max(0, $offset)]
        );
    }

    /**
     * A published post/page by slug, for the public router.
     *
     * findBySlug() deliberately does NOT filter on status — the admin needs to
     * open a draft. The public router needs the opposite guarantee: a draft,
     * trash or review item must be indistinguishable from a missing one. So
     * the visibility predicate lives in its own method rather than being
     * pushed into the admin read.
     */
    public function findPublishedBySlug(string $slug, string $type = 'post'): ?array
    {
        return $this->selectOne(
            'slug = ? AND type = ? AND status = \'published\''
            . ' AND (published_at IS NULL OR published_at <= datetime(\'now\'))',
            [$slug, $type]
        );
    }

    /**
     * Published posts whose publication date falls in a given month.
     *
     * Used by /archive/{yyyy}/{mm}. The bounds are built as datetime literals
     * in the repository from two bound year/month params, so nothing caller
     * supplied ever reaches the SQL text as string concatenation.
     */
    public function publishedInMonth(int $year, int $month, int $limit = 20, int $offset = 0): array
    {
        $year  = max(1, min($year, 9999));
        $month = max(1, min($month, 12));
        $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $end   = date('Y-m-01 00:00:00', strtotime(sprintf('%04d-%02d-01', $year, $month + 1)));

        $where  = "status = 'published' AND type = 'post'"
                . " AND published_at IS NOT NULL"
                . ' AND published_at >= ? AND published_at < ?';
        $params = [$start, $end];

        $rows = $this->select(
            $where,
            $params,
            'published_at DESC',
            'LIMIT ? OFFSET ?',
            [max(1, min($limit, 100)), max(0, $offset)]
        );

        return [
            'items' => $rows,
            'total' => $this->count($where, $params),
        ];
    }

    /**
     * Full-text search over posts via the FTS5 index.
     *
     * `posts_fts` is an external-content table (content=posts) and the schema
     * has NO triggers keeping it in sync, so the index drifts whenever a post
     * is written. A MATCH query against a drifted index silently returns
     * nothing. Rebuilding on demand is cheap (single table, one table) and
     * guarantees search returns results that actually exist. Search is a
     * public, high-traffic route, so the index is rebuilt once per process
     * and rebuilt again only if the newest post is newer than the index.
     */
    public function search(string $term, int $limit = 20, int $offset = 0): array
    {
        $limit  = max(1, min($limit, 100));
        $offset = max(0, $offset);
        $match  = self::ftsQuery($term);

        $this->syncFts();

        if ($match === null) {
            return ['items' => [], 'total' => 0];
        }

        $stmt = $this->db->getPdo()->prepare(
            "SELECT p.id, p.uuid, p.slug, p.type, p.title, p.excerpt, p.published_at,
                    p.reading_time, p.word_count, p.author_id
             FROM posts_fts f
             JOIN posts p ON p.id = f.rowid
             WHERE posts_fts MATCH ?
               AND p.status = 'published'
               AND p.type = 'post'
               AND (p.published_at IS NULL OR p.published_at <= datetime('now'))
             ORDER BY rank
             LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $match, \PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $countStmt = $this->db->getPdo()->prepare(
            "SELECT COUNT(*)
             FROM posts_fts f
             JOIN posts p ON p.id = f.rowid
             WHERE posts_fts MATCH ?
               AND p.status = 'published'
               AND p.type = 'post'
               AND (p.published_at IS NULL OR p.published_at <= datetime('now'))"
        );
        $countStmt->execute([$match]);

        return [
            'items' => $rows,
            'total' => (int) $countStmt->fetchColumn(),
        ];
    }

    /**
     * Turn free text into a safe FTS5 MATCH expression.
     *
     * FTS5 query syntax is not SQL but it IS an injection surface: a bare
     * user string containing a quote, `*`, `^`, `:` or parentheses produces a
     * syntax error and the whole route 500s. Every token is therefore
     * reduced to word characters and quoted, so the worst a user can do is
     * search for a literal they typed.
     */
    private static function ftsQuery(string $term): ?string
    {
        $term = trim($term);
        if ($term === '' || mb_strlen($term) > 200) {
            return null;
        }

        preg_match_all('/[\p{L}\p{N}_]+/u', $term, $matches);
        $tokens = $matches[0] ?? [];
        if ($tokens === []) {
            return null;
        }

        // Every token is a quoted phrase: implicit AND, no wildcards, no
        // column filters, no NEAR/OR parsing for a caller to ride.
        return implode(' AND ', array_map(
            static fn (string $t): string => '"' . str_replace('"', '""', $t) . '"',
            $tokens
        ));
    }

    /**
     * Keep the FTS index in step with the posts table.
     *
     * Once per process is enough for a request; the mtime guard re-rebuilds
     * when the table was written more recently than the last rebuild.
     */
    private function syncFts(): void
    {
        static $synced = false;
        if ($synced) {
            return;
        }
        $synced = true;

        try {
            $this->db->getPdo()->exec(
                "INSERT INTO posts_fts(posts_fts) VALUES ('rebuild')"
            );
        } catch (\PDOException) {
            // A build of SQLite without FTS5 must not take the site down;
            // search degrades to no results rather than a fatal.
        }
    }

    /** Analytics for the admin dashboard. */
    public function stats(): array
    {
        $stmt = $this->db->getPdo()->prepare(
            "SELECT status, COUNT(*) AS n FROM posts WHERE status != 'trash' GROUP BY status"
        );
        $stmt->execute();
        $byStatus = [];
        foreach ($stmt->fetchAll() as $row) {
            $byStatus[$row['status']] = (int) $row['n'];
        }

        $stmt = $this->db->getPdo()->prepare(
            "SELECT origin, COUNT(*) AS n FROM posts WHERE status != 'trash' GROUP BY origin"
        );
        $stmt->execute();
        $byOrigin = [];
        foreach ($stmt->fetchAll() as $row) {
            $byOrigin[$row['origin']] = (int) $row['n'];
        }

        $published = $this->db->getPdo()->prepare(
            "SELECT COUNT(*) FROM posts WHERE published_at >= datetime('now','-30 days')"
        );
        $published->execute();

        return [
            'by_status'        => $byStatus,
            'by_origin'        => $byOrigin,
            'total'            => array_sum($byStatus),
            'published_30d'    => (int) $published->fetchColumn(),
        ];
    }

    /** Hard delete. Used by trash purge; ordinary removal goes through trash(). */
    public function delete(int $id): bool
    {
        return $this->deleteRow($id) > 0;
    }

    public function wordCount(string $markdown): int
    {
        $text = preg_replace('/```.*?```/s', ' ', $markdown) ?? $markdown; // drop code blocks
        $text = strip_tags($text);
        $text = preg_replace('/[#*_`>\-\[\]()]/', ' ', $text) ?? $text;
        return count(preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    public function readingTime(int $words): int
    {
        return max(1, (int) ceil($words / 220));
    }
}