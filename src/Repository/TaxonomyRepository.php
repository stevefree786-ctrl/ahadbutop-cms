<?php
namespace CMS\Repository;

/**
 * Tags and categories plus their many-to-many links to posts.
 *
 * `usage_count` is maintained on write rather than computed with a COUNT
 * join on every read — but recomputed() exists so the denormalised value
 * can never be trusted blindly.
 */
class TaxonomyRepository extends Repository
{
    protected const TABLE = 'tags';

    /**
     * Columns common to BOTH taxonomy tables.
     *
     * Deliberately the intersection. `tags` has a denormalised `usage_count`;
     * `categories` does not, but carries `description`, `parent_id` and
     * `sort_order`. Selecting either table's full column set means selecting a
     * column the other lacks, so all(), find() and friends raise
     * "no such column: usage_count" the moment this repository is pointed at
     * categories. Recounting is what recompute() is for, and it already refuses
     * to run against categories.
     */
    protected const COLUMNS = ['id', 'slug', 'name', 'created_at'];

    /** Tags and categories share this repository; TABLE is swapped per instance. */
    private string $tableName = 'tags';

    public function forTags(): self
    {
        $this->tableName = 'tags';
        return $this;
    }

    public function forCategories(): self
    {
        $this->tableName = 'categories';
        return $this;
    }

    protected function table(): string
    {
        return $this->tableName;
    }

    public function all(int $limit = 200): array
    {
        $isCategories = $this->tableName === 'categories';
        $order = $isCategories ? 'sort_order ASC, name ASC' : 'usage_count DESC, name ASC';

        return $this->select('', [], $order, 'LIMIT ?', [max(1, min($limit, 500))]);
    }

    public function find(int $id): ?array
    {
        return $this->selectOne('id = ?', [$id]);
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->selectOne('slug = ?', [$slug]);
    }

    public function create(string $name, ?string $slug = null): int
    {
        $slug = slugify($slug ?? $name);
        $data = ['slug' => $slug, 'name' => $name, 'created_at' => date('Y-m-d H:i:s')];

        if ($this->tableName === 'categories') {
            $data['sort_order'] = 0;
        }

        // Idempotent: a tag that already exists returns its id.
        $existing = $this->findBySlug($slug);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return $this->insertRow($data);
    }

    public function rename(int $id, string $name): bool
    {
        return $this->updateRow($id, ['name' => $name]) > 0;
    }

    public function delete(int $id): int
    {
        return $this->deleteRow($id);
    }

    /** Attach a term to a post. Re-attaching is a no-op (PK prevents dupes). */
    public function attach(int $postId, int $termId): bool
    {
        $link = $this->tableName === 'categories' ? 'post_categories' : 'post_tags';
        $fk   = $this->tableName === 'categories' ? 'category_id' : 'tag_id';

        $stmt = $this->db->getPdo()->prepare(
            "INSERT OR IGNORE INTO $link (post_id, $fk) VALUES (?, ?)"
        );
        $stmt->execute([$postId, $termId]);

        if ($this->tableName === 'tags') {
            $this->recompute($termId);
        }
        return $stmt->rowCount() > 0;
    }

    public function detach(int $postId, int $termId): bool
    {
        $link = $this->tableName === 'categories' ? 'post_categories' : 'post_tags';
        $fk   = $this->tableName === 'categories' ? 'category_id' : 'tag_id';

        $stmt = $this->db->getPdo()->prepare("DELETE FROM $link WHERE post_id = ? AND $fk = ?");
        $stmt->execute([$postId, $termId]);

        if ($this->tableName === 'tags') {
            $this->recompute($termId);
        }
        return $stmt->rowCount() > 0;
    }

    /** Replace all terms on a post in one transaction. */
    public function syncPost(int $postId, array $tagIds, array $categoryIds = []): void
    {
        $pdo = $this->db->getPdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM post_tags WHERE post_id = ?')->execute([$postId]);
            $pdo->prepare('DELETE FROM post_categories WHERE post_id = ?')->execute([$postId]);

            foreach ($tagIds as $tid) {
                $pdo->prepare('INSERT OR IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)')
                    ->execute([$postId, (int) $tid]);
            }
            foreach ($categoryIds as $cid) {
                $pdo->prepare('INSERT OR IGNORE INTO post_categories (post_id, category_id) VALUES (?, ?)')
                    ->execute([$postId, (int) $cid]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        foreach ($tagIds as $tid) {
            $this->recompute((int) $tid);
        }
    }

    public function forPost(int $postId): array
    {
        $fk    = $this->tableName === 'categories' ? 'category_id' : 'tag_id';
        $link  = $this->tableName === 'categories' ? 'post_categories' : 'post_tags';
        $table = $this->tableName;

        $stmt = $this->db->getPdo()->prepare(
            "SELECT t.id, t.slug, t.name
             FROM $table t
             JOIN $link l ON l.$fk = t.id
             WHERE l.post_id = ?
             ORDER BY t.name ASC"
        );
        $stmt->execute([$postId]);
        return $stmt->fetchAll();
    }

    /** Recompute the denormalised usage_count for one tag. */
    public function recompute(int $tagId): int
    {
        if ($this->tableName !== 'tags') {
            return 0;
        }
        $stmt = $this->db->getPdo()->prepare(
            "SELECT COUNT(*) FROM post_tags pt
             JOIN posts p ON p.id = pt.post_id
             WHERE pt.tag_id = ? AND p.status != 'trash'"
        );
        $stmt->execute([$tagId]);
        $n = (int) $stmt->fetchColumn();

        $this->updateRow($tagId, ['usage_count' => $n]);
        return $n;
    }

    /** Rebuild every tag's usage_count — repair for the denormalised field. */
    public function recomputeAll(): int
    {
        $stmt = $this->db->getPdo()->prepare('SELECT id FROM tags');
        $stmt->execute();
        $n = 0;
        foreach ($stmt->fetchAll() as $row) {
            $this->recompute((int) $row['id']);
            $n++;
        }
        return $n;
    }

    /**
     * Posts carrying a term, for a public taxonomy archive.
     *
     * Unlike postsFor(), this one also reports the total so the router can
     * page the archive without a second COUNT query of its own, and unlike
     * postsFor() it is explicit that only *published* posts are visible.
     */
    public function publishedPostsFor(int $termId, int $limit = 20, int $offset = 0): array
    {
        $fk   = $this->tableName === 'categories' ? 'category_id' : 'tag_id';
        $link = $this->tableName === 'categories' ? 'post_categories' : 'post_tags';

        $limit  = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $stmt = $this->db->getPdo()->prepare(
            "SELECT p.id, p.uuid, p.slug, p.type, p.title, p.excerpt, p.published_at,
                    p.reading_time, p.word_count, p.author_id
             FROM posts p
             JOIN $link l ON l.post_id = p.id
             WHERE l.$fk = ? AND p.status = 'published' AND p.type = 'post'
               AND (p.published_at IS NULL OR p.published_at <= datetime('now'))
             ORDER BY p.published_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $termId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $count = $this->db->getPdo()->prepare(
            "SELECT COUNT(*)
             FROM posts p
             JOIN $link l ON l.post_id = p.id
             WHERE l.$fk = ? AND p.status = 'published' AND p.type = 'post'
               AND (p.published_at IS NULL OR p.published_at <= datetime('now'))"
        );
        $count->bindValue(1, $termId, \PDO::PARAM_INT);
        $count->execute();

        return [
            'items' => $rows,
            'total' => (int) $count->fetchColumn(),
        ];
    }

    /** Posts carrying a tag, for taxonomy archive pages. */
    public function postsFor(int $termId, int $limit = 20): array
    {
        $fk   = $this->tableName === 'categories' ? 'category_id' : 'tag_id';
        $link = $this->tableName === 'categories' ? 'post_categories' : 'post_tags';

        $stmt = $this->db->getPdo()->prepare(
            "SELECT p.id, p.slug, p.title, p.excerpt, p.published_at, p.reading_time
             FROM posts p
             JOIN $link l ON l.post_id = p.id
             WHERE l.$fk = ? AND p.status = 'published' AND p.type = 'post'
             ORDER BY p.published_at DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $termId, \PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min($limit, 100)), \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}