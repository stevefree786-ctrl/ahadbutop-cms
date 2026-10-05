<?php
namespace CMS\Repository;

/**
 * Permanent and temporary redirects between paths.
 *
 * WHY THIS EXISTS
 *
 * The `redirects` table has been in the schema since the beginning and nothing
 * has ever read it. That makes one of the most ordinary CMS operations
 * impossible: renaming a page's slug silently breaks every link to it. Search
 * engines already indexed the old URL, other people have bookmarked it, and
 * there is nothing to catch the traffic.
 *
 * So this repository is the read side of that table, and the write side of
 * rename_page. Renaming a page without writing a redirect row here is the
 * bug this class exists to make impossible.
 *
 * Path safety, on both sides
 *
 * `source_path` is looked up by exact string and only ever comes from the
 * request path, so the lookup itself cannot inject anything — it is a bound
 * parameter. `target_path` is the dangerous side: it comes from an AI agent's
 * decision, ends up in a Location header, and a `target_path` of
 * "//evil.example" or "https://evil.example" would turn our 301 into an open
 * redirect on our own domain's authority. normalizeTarget() refuses both, and
 * refuses anything that is not a same-site absolute path.
 */
class RedirectRepository extends Repository
{
    protected const TABLE = 'redirects';
    protected const COLUMNS = ['id', 'source_path', 'target_path', 'status_code', 'hits', 'created_at'];

    /** Only these may ever be issued. Matches the table's CHECK constraint. */
    private const ALLOWED_STATUSES = [301, 302, 307, 308];

    /**
     * Canonical form of a path for storage and lookup.
     *
     * Both sides go through this, which is what makes the lookup reliable: a
     * redirect stored as "/about" is found by a request for "/about", for
     * "/about/" and for "//about", because they all normalise to the same key.
     * Without that, the table would silently miss on trailing-slash variance
     * and the whole feature would look broken in exactly the case it exists
     * for.
     */
    public static function normalizePath(string $path): string
    {
        // Query and fragment are not part of a path identity, and keeping them
        // would let "/about?x" and "/about?y" be two different redirect rows.
        $path = strtok($path, '?#');

        if (!is_string($path) || $path === '') {
            return '/';
        }

        // Collapse duplicate separators, then resolve any traversal segments
        // before normalising, so "/a/b/../about" and "/about" are one key.
        $path = preg_replace('#/+#', '/', $path) ?? '/';

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        $out = '/' . implode('/', $segments);

        // The trailing slash is deliberately NOT preserved, and that is the
        // load-bearing decision rather than an oversight. Router::segments()
        // strips it, so "/about/" and "/about" reach the same page; if
        // normalizePath() kept the slash, two spellings of one URL would
        // produce two redirect rows and a rename would repair only half the
        // inbound links. One canonical key per URL.
        return $out === '' ? '/' : $out;
    }

    /**
     * Validate and canonicalise a redirect target.
     *
     * Returns null for anything that is not a plain same-site path. The two
     * that matter:
     *
     *   - "//host" and "https://host" are protocol-relative and absolute
     *     URLs. A Location header holding either sends the visitor off-site
     *     while the redirect still reads as ours, so this is the open-redirect
     *     hole and it is refused outright.
     *   - a backslash is treated as a slash by some user agents, so
     *     "/\evil.example" must not survive as a path either.
     */
    public static function normalizeTarget(string $target): ?string
    {
        $target = trim($target);

        if ($target === '') {
            return null;
        }

        // Backslashes and control characters never belong in a Location value.
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $target) === 1) {
            return null;
        }

        // Must be an absolute, same-site path. This rejects "//evil",
        // "https://evil", "http://evil" and "javascript:..." in one test,
        // because none of them begin with a single "/".
        if (!str_starts_with($target, '/')) {
            return null;
        }

        // "//" after the leading slash is the protocol-relative form.
        if (str_starts_with($target, '//')) {
            return null;
        }

        return self::normalizePath($target);
    }

    /**
     * The redirect for a path, or null.
     *
     * Lookup is on the normalised source, so trailing-slash and
     * traversal variance resolve to the same row.
     */
    public function find(string $path): ?array
    {
        $row = $this->selectOne('source_path = ?', [self::normalizePath($path)]);

        if ($row === null) {
            return null;
        }

        // A row whose target no longer passes validation (poisoned before
        // normalizeTarget existed, or hand-edited in the database) is treated
        // as no redirect at all. Falling back to the 404 is strictly better
        // than emitting a Location the caller chose.
        if (self::normalizeTarget((string) $row['target_path']) === null) {
            return null;
        }

        return $row;
    }

    /**
     * Record a redirect, replacing any existing row for the same source.
     *
     * Returns null when the target is refused, so a caller cannot mistake a
     * rejected target for a successful write.
     *
     * @param int $status One of 301/302/307/308; anything else is coerced.
     */
    public function record(
        string $source,
        string $target,
        int $status = 301
    ): ?array {
        $sourcePath = self::normalizePath($source);
        $targetPath = self::normalizeTarget($target);

        if ($targetPath === null) {
            return null;
        }

        // A redirect to itself is an infinite loop in the browser, not a
        // shortcut — refuse to store one.
        if ($sourcePath === $targetPath) {
            return null;
        }

        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            $status = 301;
        }

        $pdo = $this->db->getPdo();

        // source_path is UNIQUE, so a second rename from the same old URL
        // updates the first rather than raising. INSERT ... ON CONFLICT keeps
        // that a single statement instead of a read-then-write race.
        $stmt = $pdo->prepare(
            'INSERT INTO redirects (source_path, target_path, status_code, hits)'
            . ' VALUES (:source, :target, :status, 0)'
            . ' ON CONFLICT(source_path) DO UPDATE SET'
            . ' target_path = excluded.target_path,'
            . ' status_code = excluded.status_code'
        );
        $stmt->execute([
            ':source' => $sourcePath,
            ':target' => $targetPath,
            ':status' => $status,
        ]);

        return $this->selectOne('source_path = ?', [$sourcePath]);
    }

    /**
     * Drop the redirect for a source path, so a slug can be reused.
     *
     * Called when a page is renamed BACK to a slug that was previously
     * redirected away; otherwise the fresh page would be shadowed by a stale
     * rule sending every visitor in a loop.
     */
    public function forget(string $source): bool
    {
        $stmt = $this->db->getPdo()
            ->prepare('DELETE FROM redirects WHERE source_path = ?');
        $stmt->execute([self::normalizePath($source)]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Count a hit.
     *
     * Best-effort by design: the counter is diagnostics, not behaviour, so a
     * failure here must never turn a working redirect into a 500. The caller
     * therefore sends the response either way.
     */
    public function countHit(int $id): void
    {
        try {
            $stmt = $this->db->getPdo()
                ->prepare('UPDATE redirects SET hits = hits + 1 WHERE id = ?');
            $stmt->execute([$id]);
        } catch (\Throwable) {
            // A read-only replica or a locked database loses the counter, not
            // the redirect.
        }
    }

    /**
     * Newest redirects first, for an admin listing.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));

        $stmt = $this->db->getPdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM redirects'
            . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }
}