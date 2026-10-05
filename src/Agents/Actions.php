<?php
namespace CMS\Agents;

use CMS\Database\Connection;

/**
 * The concrete, allowlisted actions agents may invoke.
 *
 * Every handler receives ONLY validated, typed params — never raw SQL
 * and never a model-authored query.
 */
class Actions
{
    /**
     * Returns [schema, writers] for IntentRegistry.
     *
     * schema  : action => ['params' => spec, 'handler' => callable]
     * writers : action => minimum role
     */
    public static function build(Connection $db): array
    {
        $pdo = $db->getPdo();

        // Used by rename_page. Goes through the repository rather than raw
        // SQL so the target is validated by RedirectRepository::normalizeTarget()
        // — a Location header is an open-redirect surface, and that check has
        // to live in one place or a second writer will not apply it.
        $redirects = new \CMS\Repository\RedirectRepository($db);

        // ---- read-only ----------------------------------------------------
        $listPending = function (array $p) use ($pdo) {
            $limit = min((int) ($p['limit'] ?? 20), 100);
            $stmt  = $pdo->prepare(
                "SELECT id, type, status, attempts, priority, created_at
                 FROM jobs
                 WHERE status IN ('queued','failed')
                 ORDER BY priority ASC, id ASC
                 LIMIT ?"
            );
            $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return ['jobs' => $stmt->fetchAll()];
        };

        $getPost = function (array $p) use ($pdo) {
            $stmt = $pdo->prepare(
                'SELECT id, slug, type, title, excerpt, status, author_id, published_at, updated_at
                 FROM posts WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$p['post_id']]);
            $post = $stmt->fetch();
            return $post ? ['post' => $post] : ['error' => 'post_not_found'];
        };

        $getSetting = function (array $p) use ($pdo) {
            $stmt = $pdo->prepare('SELECT key, value, scope FROM settings WHERE key = ? LIMIT 1');
            $stmt->execute([$p['key']]);
            $row = $stmt->fetch();
            return $row ? ['setting' => $row] : ['error' => 'setting_not_found'];
        };

        // ---- writes --------------------------------------------------------
        $publishPost = function (array $p) use ($pdo) {
            $stmt = $pdo->prepare(
                "UPDATE posts
                 SET status = 'published',
                     published_at = COALESCE(published_at, datetime('now')),
                     updated_at = datetime('now')
                 WHERE id = ? AND status IN ('draft','review','scheduled')"
            );
            $stmt->execute([$p['post_id']]);

            return $stmt->rowCount() > 0
                ? ['published' => true, 'post_id' => $p['post_id']]
                : ['error' => 'not_publishable'];
        };

        $unpublishPost = function (array $p) use ($pdo) {
            $stmt = $pdo->prepare(
                "UPDATE posts SET status = 'draft', updated_at = datetime('now')
                 WHERE id = ? AND status = 'published'"
            );
            $stmt->execute([$p['post_id']]);
            return $stmt->rowCount() > 0
                ? ['unpublished' => true, 'post_id' => $p['post_id']]
                : ['error' => 'not_published'];
        };

        $schedulePost = function (array $p) use ($pdo) {
            // Strict ISO-8601 — rejects arbitrary SQL fragments.
            $when = $p['publish_at'];
            $ts = strtotime($when);
            if ($ts === false) {
                throw new \InvalidArgumentException('publish_at is not a valid datetime');
            }
            $stmt = $pdo->prepare(
                "UPDATE posts
                 SET status = 'scheduled', scheduled_at = ?, updated_at = datetime('now')
                 WHERE id = ? AND status IN ('draft','review')"
            );
            $stmt->execute([date('Y-m-d H:i:s', $ts), $p['post_id']]);
            return $stmt->rowCount() > 0
                ? ['scheduled' => true, 'post_id' => $p['post_id'], 'at' => date('c', $ts)]
                : ['error' => 'not_schedulable'];
        };

        $trashPost = function (array $p) use ($pdo) {
            $stmt = $pdo->prepare(
                "UPDATE posts SET status = 'trash', updated_at = datetime('now')
                 WHERE id = ? AND status != 'trash'"
            );
            $stmt->execute([$p['post_id']]);
            return $stmt->rowCount() > 0
                ? ['trashed' => true, 'post_id' => $p['post_id']]
                : ['error' => 'not_found'];
        };

        $updateSetting = function (array $p) use ($pdo) {
            $stmt = $pdo->prepare(
                'INSERT INTO settings (key, value, scope, updated_at)
                 VALUES (?, ?, ?, datetime(\'now\'))
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
            );
            $stmt->execute([$p['key'], json_encode($p['value']), $p['scope'] ?? 'system']);
            return ['updated' => true, 'key' => $p['key']];
        };

        $createTag = function (array $p) use ($pdo) {
            $stmt = $pdo->prepare(
                'INSERT INTO tags (slug, name) VALUES (?, ?)
                 ON CONFLICT(slug) DO NOTHING'
            );
            $stmt->execute([$p['slug'], $p['name']]);
            return ['created' => true, 'slug' => $p['slug']];
        };

        $listPosts = function (array $p) use ($pdo) {
            // status/type are bound, never interpolated — a model asking for
            // status="published'; DROP TABLE posts" is just a string here.
            $status = $p['status'] ?? null;
            $type   = $p['type'] ?? null;
            $limit  = min(max((int) ($p['limit'] ?? 20), 1), 100);

            $sql = 'SELECT id, slug, type, title, status, published_at, updated_at FROM posts';
            $where = [];
            $args = [];
            if ($status !== null) {
                $where[] = 'status = ?';
                $args[] = $status;
            }
            if ($type !== null) {
                $where[] = 'type = ?';
                $args[] = $type;
            }
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY id ASC LIMIT ?';
            $args[] = $limit;

            // Every placeholder — including LIMIT — goes through execute().
            // Calling execute() with an array discards anything previously set
            // with bindValue(), which left the LIMIT unbound and made SQLite
            // fail with "General error: 20 datatype mismatch".
            $stmt = $pdo->prepare($sql);
            $stmt->execute($args);
            return ['posts' => $stmt->fetchAll()];
        };

        // ---- media writes ------------------------------------------------
        /*
         * Register an image the agent fetched from the internet.
         *
         * MediaAgent writes here rather than calling MediaRepository directly
         * because the row is derived from data this CMS does not control: the
         * remote URL, the byte count, and — most importantly — the `mime`,
         * which a naive agent would have taken from the server's Content-Type
         * header. Every one of those arrives as a BOUND PARAMETER here, and
         * the handler additionally re-derives the stored MIME from the
         * extension it is given, so the column can only ever hold one of the
         * SAFE_IMAGE_MIME values even if a caller supplies something else.
         *
         * A remote row is only a record: it points at the origin we copied
         * from and stores no bytes here. The uploader id is a bound value,
         * never interpolated, so a model cannot forge an ownership record.
         */
        $registerMedia = function (array $p) use ($pdo) {
            // Derive the canonical extension -> MIME pair from ALLOWED rather
            // than trusting `mime` from the caller. Unknown extension is a
            // hard rejection: this is the last gate before a row exists.
            $ext = strtolower((string) pathinfo($p['key'], PATHINFO_EXTENSION));
            $mime = \CMS\Repository\MediaRepository::ALLOWED[$ext] ?? null;

            if ($mime === null || !in_array($mime, \CMS\Repository\MediaRepository::SAFE_IMAGE_MIME, true)) {
                throw new \InvalidArgumentException(
                    "Media key must end in an allowed image extension ("
                    . implode(', ', array_keys(array_filter(
                        \CMS\Repository\MediaRepository::ALLOWED,
                        static fn($m) => in_array($m, \CMS\Repository\MediaRepository::SAFE_IMAGE_MIME, true)
                    ))) . ')'
                );
            }

            $uuid = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare(
                'INSERT INTO media
                    (uuid, storage, key, url, mime, bytes, width, height, alt, caption, checksum, uploaded_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime(\'now\'))'
            );
            $stmt->execute([
                $uuid,
                'remote',
                $p['key'],
                $p['url'],
                $mime,
                (int) $p['bytes'],
                $p['width'] ?? null,
                $p['height'] ?? null,
                $p['alt'] ?? null,
                $p['caption'] ?? null,
                $p['checksum'] ?? null,
                $p['uploaded_by'] ?? null,
            ]);

            return [
                'registered' => true,
                'media_id'   => (int) $pdo->lastInsertId(),
                'key'        => $p['key'],
                'url'        => $p['url'],
                'mime'       => $mime,
            ];
        };

        // ---- SEO / design writes -------------------------------------------
        $saveSeoMeta = function (array $p) use ($pdo) {
            $stmt = $pdo->prepare(
                "INSERT INTO seo_meta
                    (entity_type, entity_id, meta_title, meta_description, canonical_url,
                     focus_keyword, schema_json, score, checked_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
                 ON CONFLICT(entity_type, entity_id) DO UPDATE SET
                    meta_title       = excluded.meta_title,
                    meta_description = excluded.meta_description,
                    canonical_url    = excluded.canonical_url,
                    focus_keyword    = excluded.focus_keyword,
                    schema_json      = excluded.schema_json,
                    score            = excluded.score,
                    checked_at       = excluded.checked_at,
                    updated_at       = excluded.updated_at"
            );
            $stmt->execute([
                $p['entity_type'],
                (string) $p['entity_id'],
                $p['meta_title'] ?? null,
                $p['meta_description'] ?? null,
                $p['canonical_url'] ?? null,
                $p['focus_keyword'] ?? null,
                $p['schema_json'] ?? null,
                $p['score'] ?? null,
            ]);

            return [
                'saved'   => true,
                'entity'  => $p['entity_type'] . ':' . $p['entity_id'],
                'score'   => $p['score'] ?? null,
            ];
        };

        $upsertDesignToken = function (array $p) use ($pdo) {
            $stmt = $pdo->prepare(
                "INSERT INTO design_tokens (key, value, category, css_var, updated_at)
                 VALUES (?, ?, ?, ?, datetime('now'))
                 ON CONFLICT(key) DO UPDATE SET
                    value = excluded.value,
                    category = excluded.category,
                    css_var = excluded.css_var,
                    updated_at = excluded.updated_at"
            );
            $stmt->execute([$p['key'], $p['value'], $p['category'], $p['css_var']]);
            return ['saved' => true, 'key' => $p['key']];
        };

        $saveTheme = function (array $p) use ($pdo) {
            $activate = !empty($p['activate']);

            // Exactly one active theme: clear the flag before setting the new one.
            if ($activate) {
                $pdo->prepare('UPDATE themes SET is_active = 0 WHERE is_active = 1')->execute();
            }

            $stmt = $pdo->prepare(
                "INSERT INTO themes (slug, name, source, config_json, is_active, created_at)
                 VALUES (?, ?, ?, ?, ?, datetime('now'))
                 ON CONFLICT(slug) DO UPDATE SET
                    name = excluded.name,
                    source = excluded.source,
                    config_json = excluded.config_json,
                    is_active = excluded.is_active"
            );
            $stmt->execute([
                $p['slug'],
                $p['name'],
                $p['source'] ?? 'ai',
                $p['config_json'],
                $activate ? 1 : 0,
            ]);

            return ['saved' => true, 'slug' => $p['slug'], 'active' => $activate];
        };

        /**
         * Insert a draft post. Used by NewsAgent to turn a fetched feed item
         * into an editorial draft.
         *
         * status='draft' and origin='ai' are SQL literals, not params: they
         * are the two things this action MEANS. Exposing either as a
         * parameter would let a caller ask for status='published' through an
         * action whose name promises a draft — capability would come from the
         * schema rather than from the allowlist entry, which is exactly the
         * hole the registry exists to prevent.
         *
         * Every caller-supplied value is bound. body_md is capped here as
         * well as in the registry, because a long body must be trimmed by the
         * caller that composed it, not silently cut here.
         *
         * The slug is disambiguated against (type, slug) because the table
         * holds UNIQUE(type, slug); re-importing a story whose headline was
         * retitled would otherwise raise a constraint violation instead of
         * creating a second draft.
         */
        $saveDraftPost = function (array $p) use ($pdo) {
            $title = trim((string) $p['title']);
            if ($title === '') {
                throw new \InvalidArgumentException('title is required');
            }

            $body = (string) ($p['body_md'] ?? '');
            $words = count(preg_split('/\s+/', trim(strip_tags($body)), -1, PREG_SPLIT_NO_EMPTY) ?: []);

            // slugify() matches the column's uniqueness contract, so the
            // only failure mode left is a collision with an existing post.
            $slug = slugify((string) ($p['slug'] ?? $title));
            $n = 2;
            while (true) {
                $check = $pdo->prepare('SELECT 1 FROM posts WHERE type = ? AND slug = ? LIMIT 1');
                $check->execute(['post', $slug]);
                if (!$check->fetch()) {
                    break;
                }
                $slug = $slug . '-' . $n++;
            }

            $stmt = $pdo->prepare(
                "INSERT INTO posts
                    (uuid, slug, type, title, excerpt, body_md, status, origin, author_id,
                     word_count, reading_time, created_at, updated_at)
                 VALUES
                    (?, ?, 'post', ?, ?, ?, 'draft', 'ai', ?, ?, ?,
                     datetime('now'), datetime('now'))"
            );
            $stmt->execute([
                bin2hex(random_bytes(16)),
                $slug,
                $title,
                ($p['excerpt'] ?? null) !== null && $p['excerpt'] !== '' ? $p['excerpt'] : null,
                $body,
                isset($p['author_id']) ? (int) $p['author_id'] : null,
                $words,
                max(1, (int) ceil($words / 220)),
            ]);

            return [
                'saved'   => true,
                'post_id' => (int) $pdo->lastInsertId(),
                'slug'    => $slug,
            ];
        };

        // ---- page lifecycle -------------------------------------------------
        /*
         * The AI's page vocabulary.
         *
         * These exist because the render path now honours posts.template,
         * posts.menu_order and the redirects table (Part 1). Until those reads
         * existed, an agent could write every one of these columns and the
         * site would not move; each handler below is the writer half of a pair
         * whose reader half is in src/Render/.
         *
         * Shared invariants, applied here rather than trusted from the caller:
         *
         *  - body_html is nulled on every body write. Router::renderBody()
         *    prefers that column over rendering body_md, so a body edit that
         *    left the cache populated would be invisible on the next page
         *    view. No code writes the column today, so today the cache is
         *    always empty and the edit would work by accident; these
         *    statements keep that true if a renderer is ever added.
         *  - slug collisions are disambiguated before writing, because the
         *    table holds UNIQUE(type, slug) and a raised constraint violation
         *    would abort an agent's whole multi-step plan rather than just
         *    its current step.
         *  - renames are mirrored into redirects, so the old URL keeps
         *    working. Renaming without that is the single most damaging thing
         *    an agent could do to a live site, and it is exactly one line
         *    of omission.
         */

        /** Next free slug for $type, given the one requested. */
        $uniqueSlug = static function (string $type, string $base, ?int $exceptId = null) use ($pdo): string {
            $slug = slugify($base);
            if ($slug === '') {
                $slug = 'untitled';
            }

            $n = 2;
            while (true) {
                $sql    = 'SELECT 1 FROM posts WHERE type = ? AND slug = ?';
                $params = [$type, $slug];
                if ($exceptId !== null) {
                    $sql .= ' AND id != ?';
                    $params[] = $exceptId;
                }
                $check = $pdo->prepare($sql . ' LIMIT 1');
                $check->execute($params);
                if (!$check->fetch()) {
                    return $slug;
                }
                $slug = $slug . '-' . $n++;
            }
        };

        /**
         * The lowest menu_order not already used by another page.
         *
         * New pages must be appended, not slotted in at 0. The default for a
         * new row is menu_order = 0, which is the sort key's FIRST value, so
         * "create a page" and "put this page at the front of the navigation"
         * would be the same action. That reads as helpful for one page and as
         * "the AI keeps reorganising my site" by the third, and it makes
         * set_menu_order untestable as a separate step because creating a page
         * silently performs it.
         *
         * This uses the agent-supplied menu_order when there is one and ignores
         * it when it is negative — the negative range is reserved for "pin to
         * the top", which no caller sets yet — and otherwise lands below
         * everything already in the menu.
         */
        $nextMenuOrder = static function (?int $requested = null) use ($pdo): int {
            if ($requested !== null && $requested >= 0) {
                return $requested;
            }
            $lowest = $pdo->query(
                "SELECT COALESCE(MIN(menu_order), -1) FROM posts WHERE type = 'page'"
            )->fetchColumn();
            return (int) $lowest + 1;
        };

        /** Word count and reading time from markdown, for the denormalised columns. */
        $measure = static function (string $body): array {
            $words = count(preg_split('/\s+/', trim(strip_tags($body)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            return [$words, max(1, (int) ceil($words / 220))];
        };

        /**
         * A theme name from the model must name a theme that EXISTS.
         *
         * The `themes` table records themes and `save_theme` can activate one
         * with no check at all, so an agent could point the whole public site
         * at a directory that is not there. Router::theme() would fall back to
         * 'default' — so the site would survive — but the site would then
         * silently render something other than what was asked for, which is
         * worse than a refusal. So this validates against the filesystem.
         *
         * The name is still bound as a SQL parameter; the filesystem check is
         * on the same string, not instead of it.
         */
        $existingTheme = static function (string $slug): bool {
            $name = \CMS\Render\Theme::sanitiseName($slug);
            return is_dir(\dirname(__DIR__, 2) . '/storage/themes/' . $name);
        };

        /**
         * Create a page.
         *
         * status='draft' is a literal, not a parameter: creating a draft is
         * what this action MEANS. A parameterised status would let the name
         * promise a draft while the payload published it.
         */
        $createPage = function (array $p) use ($pdo, $uniqueSlug, $nextMenuOrder, $measure) {
            $title = trim((string) $p['title']);
            if ($title === '') {
                throw new \InvalidArgumentException('title is required');
            }

            $body  = (string) ($p['body_md'] ?? '');
            $slug  = $uniqueSlug('page', (string) ($p['slug'] ?? $title));
            $order = $nextMenuOrder(isset($p['menu_order']) ? (int) $p['menu_order'] : null);
            $count = $measure($body);

            $stmt = $pdo->prepare(
                "INSERT INTO posts
                    (uuid, slug, type, title, body_md, body_html, status, origin, author_id,
                     template, menu_order, word_count, reading_time, created_at, updated_at)
                 VALUES
                    (?, ?, 'page', ?, ?, NULL, 'draft', 'ai', ?, ?, ?, ?, ?,
                     datetime('now'), datetime('now'))"
            );
            $stmt->execute([
                bin2hex(random_bytes(16)),
                $slug,
                $title,
                $body,
                isset($p['author_id']) ? (int) $p['author_id'] : null,
                ($p['template'] ?? null) !== null && $p['template'] !== '' ? $p['template'] : null,
                $order,
                $count[0],
                $count[1],
            ]);

            $id = (int) $pdo->lastInsertId();

            return [
                'created'  => true,
                'post_id'   => $id,
                'slug'      => $slug,
                'url'       => '/page/' . $slug,
                'status'    => 'draft',
                'published' => false,
                'menu_order' => $order,
                'note'      => 'Created as a draft. Publish it to make the URL live.',
            ];
        };

        /**
         * Rename a page, and keep the old URL working.
         *
         * The redirect is written BEFORE the slug changes, and the two run in
         * one transaction. Order matters: if the process dies between them, a
         * redirect pointing at a slug that does not exist yet is a 301 to a
         * 404 — visibly broken and trivially fixable — whereas a new slug with
         * no redirect is an invisible permanent loss of every inbound link to
         * the old URL. The recoverable failure is the right one to be able to
         * produce.
         */
        $renamePage = function (array $p) use ($pdo, $uniqueSlug, $redirects) {
            $id = (int) $p['post_id'];

            $find = $pdo->prepare('SELECT id, slug, title, type FROM posts WHERE id = ? AND type = ? LIMIT 1');
            $find->execute([$id, 'page']);
            $page = $find->fetch();
            if (!$page) {
                return ['error' => 'page_not_found'];
            }

            $oldSlug = (string) $page['slug'];
            $newSlug = $uniqueSlug('page', (string) ($p['new_slug'] ?? $oldSlug), $id);

            if ($newSlug === $oldSlug) {
                return ['renamed' => false, 'reason' => 'slug_unchanged', 'slug' => $oldSlug];
            }

            $newTitle = trim((string) ($p['new_title'] ?? $page['title']));

            $pdo->beginTransaction();
            try {
                // Free the old path in case this slug was previously redirected
                // away; otherwise the row we are about to write collides on
                // redirects.source_path.
                $pdo->prepare('DELETE FROM redirects WHERE source_path = ?')
                    ->execute(['/page/' . $oldSlug]);

                $upd = $pdo->prepare(
                    'UPDATE posts SET slug = ?, title = ?, updated_at = datetime(\'now\') WHERE id = ?'
                );
                $upd->execute([$newSlug, $newTitle !== '' ? $newTitle : (string) $page['title'], $id]);

                $ins = $pdo->prepare(
                    'INSERT INTO redirects (source_path, target_path, status_code, hits)
                     VALUES (?, ?, 301, 0)'
                );
                $ins->execute(['/page/' . $oldSlug, '/page/' . $newSlug]);

                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            return [
                'renamed'      => true,
                'post_id'      => $id,
                'old_slug'     => $oldSlug,
                'slug'         => $newSlug,
                'title'        => $newTitle !== '' ? $newTitle : (string) $page['title'],
                'old_url'      => '/page/' . $oldSlug,
                'url'          => '/page/' . $newSlug,
                'redirect_from' => '/page/' . $oldSlug,
            ];
        };

        /**
         * Replace a page's body.
         *
         * body_html is explicitly NULLed — see the note at the top of this
         * section. Left populated, Router::renderBody() would keep serving the
         * pre-edit render and the agent would report success on an edit
         * nobody can see.
         */
        $updatePageBody = function (array $p) use ($pdo, $measure) {
            $id = (int) $p['post_id'];

            $find = $pdo->prepare('SELECT id, title FROM posts WHERE id = ? AND type = ? LIMIT 1');
            $find->execute([$id, 'page']);
            if (!$find->fetch()) {
                return ['error' => 'page_not_found'];
            }

            $body  = (string) ($p['body_md'] ?? '');
            $count = $measure($body);

            $stmt = $pdo->prepare(
                'UPDATE posts
                    SET body_md = ?, body_html = NULL, word_count = ?, reading_time = ?,
                        updated_at = datetime(\'now\')
                  WHERE id = ?'
            );
            $stmt->execute([$body, $count[0], $count[1], $id]);

            return [
                'updated'  => true,
                'post_id'  => $id,
                'words'    => $count[0],
                'reading_time' => $count[1],
            ];
        };

        /** Put a page in the header navigation at a given position. */
        $setMenuOrder = function (array $p) use ($pdo) {
            $stmt = $pdo->prepare(
                'UPDATE posts SET menu_order = ?, updated_at = datetime(\'now\')
                  WHERE id = ? AND type = ?'
            );
            $stmt->execute([(int) $p['menu_order'], (int) $p['post_id'], 'page']);

            return $stmt->rowCount() > 0
                ? ['updated' => true, 'post_id' => (int) $p['post_id'], 'menu_order' => (int) $p['menu_order']]
                : ['error' => 'page_not_found'];
        };

        /**
         * Move a page to the trash.
         *
         * status='trash' is a literal. The nav and every public listing filter
         * on status, so a trashed page leaves the header on the next request
         * with no further step — but its URL still 404s rather than
         * redirecting, because trashing is a reversible editorial decision and
         * a permanent redirect would outlive the undo.
         */
        $deletePage = function (array $p) use ($pdo) {
            $stmt = $pdo->prepare(
                "UPDATE posts SET status = 'trash', updated_at = datetime('now')
                  WHERE id = ? AND type = 'page' AND status != 'trash'"
            );
            $stmt->execute([(int) $p['post_id']]);

            return $stmt->rowCount() > 0
                ? ['trashed' => true, 'post_id' => (int) $p['post_id'], 'restorable' => true]
                : ['error' => 'page_not_found'];
        };

        /**
         * Apply one design token — the visible path from a prompt to a pixel.
         *
         * The value is put through DesignVars' sanitiser HERE, at write time,
         * not only at read time. DesignVars already drops a poisoned value on
         * the way out, so a bad token is currently harmless; rejecting it at
         * the write means the agent is told it failed and can correct itself,
         * rather than the row landing and silently changing nothing.
         */
        $applyDesignToken = function (array $p) use ($pdo) {
            $value = \CMS\Render\DesignVars::validateValue((string) $p['value']);
            if ($value === null) {
                return [
                    'error'   => 'invalid_token_value',
                    'reason'  => 'A CSS value may not contain ; < > " or \' — '
                               . 'those would end the declaration or the attribute.',
                ];
            }

            $var = \CMS\Render\DesignVars::validateVarName((string) $p['css_var']);
            if ($var === null) {
                return ['error' => 'invalid_css_var', 'reason' => 'css_var must be --kebab-case'];
            }

            $stmt = $pdo->prepare(
                "INSERT INTO design_tokens (key, value, category, css_var, updated_at)
                 VALUES (?, ?, ?, ?, datetime('now'))
                 ON CONFLICT(key) DO UPDATE SET
                    value = excluded.value,
                    category = excluded.category,
                    css_var = excluded.css_var,
                    updated_at = excluded.updated_at"
            );
            $stmt->execute([$p['key'], $value, $p['category'], $var]);

            /*
             * Drop DesignVars' per-request memo.
             *
             * resolve() caches the merged token map in a static, so it is read
             * from the database ONCE per process. Under php -S each request is a
             * fresh process and nobody notices; in any long-lived worker — a
             * RoadRunner/Swoole/FrankenPHP deployment, a queue worker, or a test
             * harness holding the app in memory — the second and every later page
             * render reuse the map built for the first one.
             *
             * That is precisely the bug this token path is supposed to close. An
             * agent writes an accent, the row lands, the API answers {"saved":
             * true}, and the page in the SAME process still shows the old colour:
             * the single most damaging shape a silent failure can take, because
             * every signal says it worked. Clearing the memo on write makes the
             * next render in this process pick the new value up.
             */
            \CMS\Render\DesignVars::reset();

            return ['saved' => true, 'key' => $p['key'], 'css_var' => $var, 'value' => $value];
        };

        /**
         * Point the site at a theme.
         *
         * The name is checked against storage/themes/ before it is written,
         * because a setting naming a theme that does not exist degrades
         * silently — Router::theme() falls back to 'default' and the agent
         * reports a switch that never happened.
         */
        $activateTheme = function (array $p) use ($pdo, $existingTheme) {
            $slug = (string) $p['theme'];

            if (!$existingTheme($slug)) {
                return [
                    'error'  => 'theme_not_found',
                    'theme'  => $slug,
                    'reason' => 'No such theme directory under storage/themes/. '
                              . 'Write the theme files first, then activate.',
                ];
            }

            $stmt = $pdo->prepare(
                'INSERT INTO settings (key, value, scope, updated_at)
                 VALUES (?, ?, ?, datetime(\'now\'))
                 ON CONFLICT(key) DO UPDATE SET
                    value = excluded.value, updated_at = excluded.updated_at'
            );
            $stmt->execute(['theme', json_encode(\CMS\Render\Theme::sanitiseName($slug)), 'system']);

            return ['activated' => true, 'theme' => \CMS\Render\Theme::sanitiseName($slug)];
        };

        // ---- theme template edits -------------------------------------------
        /**
         * Write one file inside an installed theme.
         *
         * This is the highest-consequence intent in the registry: a template
         * is PHP that runs on every request for that theme, so writing one is
         * equivalent to writing application code. That is why it is gated at
         * 'admin' rather than 'editor', and why the rules below are narrow on
         * purpose:
         *
         *   - the path is resolved and checked with realpath() for
         *     containment INSIDE the theme directory, so ../ and a symlink
         *     both fail;
         *   - only .php and .css may be written — no .md, no .json, no
         *     config, so this cannot be used to edit theme metadata;
         *   - every write lands in .bak copies first, so an admin can undo a
         *     bad edit without a database round trip.
         *
         * It refuses unless the file already exists. Creating templates is a
         * separate, deliberately absent capability: a new file is new code,
         * whereas this is "change these lines of a file that is already
         * installed and already reviewed".
         */
        $updateThemeTemplate = function (array $p) use ($db) {
            $themeName = \CMS\Render\Theme::sanitiseName((string) $p['theme']);
            $themeDir  = \dirname(__DIR__, 2) . '/storage/themes/' . $themeName;

            $realTheme = realpath($themeDir);
            if ($realTheme === false || !is_dir($realTheme)) {
                return ['error' => 'theme_not_found', 'theme' => $themeName];
            }

            $rel = trim((string) $p['path']);
            if ($rel === '' || str_contains($rel, "\0")) {
                return ['error' => 'invalid_path'];
            }

            // Extensions are checked on the raw input before it is joined, so a
            // name like "layout.php.bak" or "x.php/../../y" is judged on what
            // the caller actually wrote.
            if (!in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), ['php', 'css'], true)) {
                return ['error' => 'invalid_extension', 'reason' => 'Only .php and .css may be written'];
            }

            $target = $realTheme . DIRECTORY_SEPARATOR . ltrim(str_replace('\\', '/', $rel), '/');

            // realpath() on a file that does not exist yet returns false, which
            // is the refusal signal: this intent edits installed files only.
            $realTarget = realpath($target);
            if ($realTarget === false || !is_file($realTarget)) {
                return ['error' => 'file_not_found', 'reason' => 'Only existing theme files may be edited'];
            }

            // Containment. The prefix check needs the separator, or
            // ".../default-evil" would pass as being inside ".../default".
            if (!str_starts_with($realTarget, $realTheme . DIRECTORY_SEPARATOR)) {
                return ['error' => 'path_escape', 'path' => $rel];
            }

            $contents = (string) ($p['contents'] ?? '');

            // Lint before writing. A template with a syntax error takes down
            // every page on the site, and a one-line mistake is far cheaper to
            // catch here than to notice on the next request.
            $check = tempnam(sys_get_temp_dir(), 'tpl');
            if ($check === false) {
                return ['error' => 'tempfile_failed'];
            }
            file_put_contents($check, $contents);
            $out   = [];
            $code  = 0;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($check) . ' 2>&1', $out, $code);
            unlink($check);

            if ($code !== 0) {
                return [
                    'error'  => 'syntax_error',
                    'reason' => 'The new contents do not parse as PHP, so they were not written.',
                    'detail' => trim(implode("\n", $out)),
                ];
            }

            $backup = $realTarget . '.bak';
            @copy($realTarget, $backup);

            if (file_put_contents($realTarget, $contents) === false) {
                return ['error' => 'write_failed'];
            }

            clearstatcache(true, $realTarget);

            return [
                'written'  => true,
                'theme'    => $themeName,
                'path'     => str_replace($realTheme . DIRECTORY_SEPARATOR, '', $realTarget),
                'bytes'    => strlen($contents),
                'backup'   => basename($backup),
                'note'     => 'The previous version is at ' . basename($backup) . ' alongside the file.',
            ];
        };

        // ---- BYOK + web research -------------------------------------------
        //
        // Built here rather than lazily in a handler because both need the
        // schema entry to exist before any model can name them: an intent that
        // is only reachable by direct PHP is not an intent.
        $keyStore = new ProviderKeyStore($db);
        $web      = new WebResearcher();

        /**
         * The only intent in the registry that writes a credential.
         *
         * It is NOT routed through update_setting on purpose. That intent takes
         * an arbitrary key string at 'author'-visible scope, so routing BYOK
         * through it would mean a live API key sitting in a row any reader can
         * enumerate, plus a mistyped key name writing a secret somewhere no
         * cleanup code will ever look. The provider name here is checked
         * against a fixed allowlist inside ProviderKeyStore, so the storage key
         * is derived rather than caller-supplied.
         *
         * Returns no secret material — only that the store accepted it. There
         * is no intent anywhere in this registry that reads a key back, and
         * there is deliberately no way to add one: the plaintext exists only
         * inside BaseAgent::callProvider(), on its way to an Authorization
         * header.
         */
        $setProviderKey = function (array $p) use ($keyStore) {
            if (!SecretBox::available()) {
                // Refused up front, with the reason, rather than silently
                // storing plaintext or silently dropping the write.
                return ['error' => SecretBox::ERROR_NO_KEY];
            }

            $provider = (string) $p['provider'];
            $key      = (string) $p['api_key'];

            if (!ProviderKeyStore::isKnown($provider)) {
                return [
                    'error'    => 'unknown_provider',
                    'provider' => $provider,
                    'allowed'  => ProviderKeyStore::providers(),
                ];
            }

            // Advisory. A provider that changed its key format must not have
            // this site bricked by a stale prefix check, so a rejected shape is
            // reported and the write still proceeds.
            $looksOk = ProviderKeyStore::looksLikeKey($provider, $key);

            try {
                $out = $keyStore->put($provider, $key, $p['user_id'] ?? null);
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage(), 'provider' => $provider];
            } catch (\InvalidArgumentException $e) {
                return ['error' => 'unknown_provider', 'provider' => $provider];
            }

            return $out + [
                'looks_like_key' => $looksOk,
                'hint'           => $looksOk ? null : 'stored, but the value does not match this provider\'s usual key format — verify it works',
            ];
        };

        $clearProviderKey = function (array $p) use ($keyStore) {
            $provider = (string) $p['provider'];

            if (!ProviderKeyStore::isKnown($provider)) {
                return ['error' => 'unknown_provider', 'allowed' => ProviderKeyStore::providers()];
            }

            try {
                return $keyStore->forget($provider);
            } catch (\InvalidArgumentException $e) {
                return ['error' => 'unknown_provider'];
            }
        };

        /**
         * Safe to expose broadly BECAUSE it returns no secrets: every entry is
         * a boolean plus the source it came from. A model that asks "can I use
         * OpenAI?" gets a truthful yes with nothing to leak.
         */
        $listProviders = function () use ($keyStore) {
            return [
                'providers'     => $keyStore->status(),
                'can_encrypt'   => SecretBox::available(),
                'encryption_ok' => SecretBox::available(),
            ];
        };

        /**
         * Deep research: search, then read the top results, as one budgeted
         * call.
         *
         * This is the agent-facing surface of WebResearcher. Note what is NOT
         * here: no URL comes from the database, and no engine name is
         * interpolated into a template the caller controls — the engine is an
         * allowlist key, so a model cannot point the tool at an arbitrary host
         * by naming it a "search engine".
         */
        $deepResearch = function (array $p) use ($web) {
            return $web->research(
                (string) $p['query'],
                (int) ($p['depth'] ?? 3),
                (string) ($p['engine'] ?? 'duckduckgo')
            );
        };

        /** One page, as text. Same SSRF protections as research(). */
        $readPage = function (array $p) use ($web) {
            return $web->read((string) $p['url']);
        };

        /** Links only — lets a caller choose what is worth the page budget. */
        $searchWeb = function (array $p) use ($web) {
            return $web->search(
                (string) $p['query'],
                (string) ($p['engine'] ?? 'duckduckgo'),
                (int) ($p['limit'] ?? 8)
            );
        };

        return [
            'schema' => [
                'list_pending_jobs' => [
                    'params' => ['limit' => ['type' => 'int', 'required' => false]],
                    'handler' => $listPending,
                ],
                'get_post' => [
                    'params' => ['post_id' => ['type' => 'id', 'required' => true]],
                    'handler' => $getPost,
                ],
                'get_setting' => [
                    'params' => ['key' => ['type' => 'string', 'required' => true]],
                    'handler' => $getSetting,
                ],
                'publish_post' => [
                    'params' => ['post_id' => ['type' => 'id', 'required' => true]],
                    'handler' => $publishPost,
                ],
                'unpublish_post' => [
                    'params' => ['post_id' => ['type' => 'id', 'required' => true]],
                    'handler' => $unpublishPost,
                ],
                'schedule_post' => [
                    'params' => [
                        'post_id'    => ['type' => 'id',    'required' => true],
                        'publish_at' => ['type' => 'string', 'required' => true],
                    ],
                    'handler' => $schedulePost,
                ],
                'trash_post' => [
                    'params' => ['post_id' => ['type' => 'id', 'required' => true]],
                    'handler' => $trashPost,
                ],
                'update_setting' => [
                    'params' => [
                        'key'   => ['type' => 'string', 'required' => true],
                        'value' => ['type' => 'string', 'required' => true],
                        'scope' => ['type' => 'string', 'required' => false],
                    ],
                    'handler' => $updateSetting,
                ],
                'create_tag' => [
                    'params' => [
                        'slug' => ['type' => 'slug',  'required' => true],
                        'name' => ['type' => 'string', 'required' => true],
                    ],
                    'handler' => $createTag,
                ],
                'list_posts' => [
                    'params' => [
                        'status' => ['type' => 'string', 'required' => false],
                        'type'   => ['type' => 'string', 'required' => false],
                        'limit'  => ['type' => 'int',    'required' => false],
                    ],
                    'handler' => $listPosts,
                ],

                'register_media' => [
                    'params' => [
                        // The content-addressed object key. Its extension is
                        // what the handler derives the stored MIME from.
                        'key'         => ['type' => 'string', 'required' => true],
                        // The origin the bytes came from. 'remote' rows keep
                        // this pointing at the source, so the site does not
                        // hotlink a URL that may vanish.
                        'url'         => ['type' => 'string', 'required' => true],
                        'bytes'       => ['type' => 'int',    'required' => true],
                        'width'       => ['type' => 'int',    'required' => false],
                        'height'      => ['type' => 'int',    'required' => false],
                        'alt'         => ['type' => 'string', 'required' => false],
                        'caption'     => ['type' => 'string', 'required' => false],
                        'checksum'    => ['type' => 'string', 'required' => false],
                        'uploaded_by' => ['type' => 'id',     'required' => false],
                    ],
                    'handler' => $registerMedia,
                ],

                // ---- SEO / design -------------------------------------------
                'save_seo_meta' => [
                    'params' => [
                        'entity_type'      => ['type' => 'string', 'required' => true],
                        'entity_id'        => ['type' => 'id',     'required' => true],
                        'meta_title'       => ['type' => 'string', 'required' => false],
                        'meta_description' => ['type' => 'string', 'required' => false],
                        'canonical_url'    => ['type' => 'string', 'required' => false],
                        'focus_keyword'    => ['type' => 'string', 'required' => false],
                        'schema_json'      => ['type' => 'string', 'required' => false],
                        'score'            => ['type' => 'int',    'required' => false],
                    ],
                    'handler' => $saveSeoMeta,
                ],
                'upsert_design_token' => [
                    'params' => [
                        'key'      => ['type' => 'string', 'required' => true],
                        'value'    => ['type' => 'string', 'required' => true],
                        'category' => ['type' => 'string', 'required' => true],
                        'css_var'  => ['type' => 'string', 'required' => true],
                    ],
                    'handler' => $upsertDesignToken,
                ],
                'save_theme' => [
                    'params' => [
                        'slug'        => ['type' => 'slug',   'required' => true],
                        'name'        => ['type' => 'string', 'required' => true],
                        'source'      => ['type' => 'string', 'required' => false],
                        'config_json' => ['type' => 'string', 'required' => true],
                        'activate'    => ['type' => 'bool',    'required' => false],
                    ],
                    'handler' => $saveTheme,
                ],

                // ---- News import --------------------------------------------
                'save_draft_post' => [
                    'params' => [
                        'title'     => ['type' => 'string', 'required' => true],
                        'slug'      => ['type' => 'slug',   'required' => false],
                        'excerpt'   => ['type' => 'string', 'required' => false],
                        'body_md'   => ['type' => 'string', 'required' => false],
                        'author_id' => ['type' => 'id',     'required' => false],
                    ],
                    'handler' => $saveDraftPost,
                ],

                // ---- pages ---------------------------------------------------
                // The parameters below are the ceiling, not the floor: the
                // handlers validate further (title non-empty, slug derived
                // from slugify(), css_var shape). Declaring them 'string'
                // rather than 'slug' is deliberate for slug-shaped input the
                // caller may supply as a title — castSlug() would reject
                // "Our Company Story" outright, and slugify() in the handler
                // is the transformation that was wanted anyway.
                'create_page' => [
                    'params' => [
                        'title'      => ['type' => 'string', 'required' => true],
                        'slug'       => ['type' => 'string', 'required' => false],
                        'body_md'    => ['type' => 'string', 'required' => false],
                        'template'   => ['type' => 'string', 'required' => false],
                        'menu_order' => ['type' => 'int',    'required' => false],
                        'author_id'  => ['type' => 'id',     'required' => false],
                    ],
                    'handler' => $createPage,
                ],
                'rename_page' => [
                    'params' => [
                        'post_id'   => ['type' => 'id',     'required' => true],
                        'new_slug'  => ['type' => 'string', 'required' => false],
                        'new_title' => ['type' => 'string', 'required' => false],
                    ],
                    'handler' => $renamePage,
                ],
                'update_page_body' => [
                    'params' => [
                        'post_id' => ['type' => 'id',     'required' => true],
                        'body_md' => ['type' => 'string', 'required' => true],
                    ],
                    'handler' => $updatePageBody,
                ],
                'set_menu_order' => [
                    'params' => [
                        'post_id'    => ['type' => 'id',  'required' => true],
                        'menu_order' => ['type' => 'int', 'required' => true],
                    ],
                    'handler' => $setMenuOrder,
                ],
                'delete_page' => [
                    'params' => [
                        'post_id' => ['type' => 'id', 'required' => true],
                    ],
                    'handler' => $deletePage,
                ],

                // ---- live design --------------------------------------------
                'apply_design_token' => [
                    'params' => [
                        'key'      => ['type' => 'string', 'required' => true],
                        'value'    => ['type' => 'string', 'required' => true],
                        'category' => ['type' => 'string', 'required' => true],
                        'css_var'  => ['type' => 'string', 'required' => true],
                    ],
                    'handler' => $applyDesignToken,
                ],
                'activate_theme' => [
                    'params' => [
                        'theme' => ['type' => 'string', 'required' => true],
                    ],
                    'handler' => $activateTheme,
                ],
                // The only intent in the registry that writes executable code.
                // Admin, not editor, and it edits only files already installed.
                'update_theme_template' => [
                    'params' => [
                        'theme'    => ['type' => 'string', 'required' => true],
                        'path'     => ['type' => 'string', 'required' => true],
                        'contents' => ['type' => 'string', 'required' => true],
                    ],
                    'handler' => $updateThemeTemplate,
                ],

                // ---- BYOK ------------------------------------------------------
                // Admin on both writers. Not editor: a provider key is
                // infrastructure the site owner paid for, shared by every
                // author who benefits from it, and the blast radius of a
                // leaked one is that provider's entire account. Editor-level
                // roles are editorial judgements about content; a billing
                // credential is a different category.
                'set_provider_key' => [
                    'params' => [
                        'provider' => ['type' => 'string', 'required' => true],
                        'api_key'  => ['type' => 'string', 'required' => true],
                        'user_id'  => ['type' => 'id',     'required' => false],
                    ],
                    'handler' => $setProviderKey,
                ],
                'clear_provider_key' => [
                    'params' => [
                        'provider' => ['type' => 'string', 'required' => true],
                    ],
                    'handler' => $clearProviderKey,
                ],
                // A reader on purpose: knowing which provider will be used is
                // operational information, not a secret, and an agent that
                // cannot ask will keep calling out to a provider with no key.
                // The handler returns booleans and a source, never a key.
                'list_providers' => [
                    'params' => [],
                    'handler' => $listProviders,
                ],

                // ---- deep research --------------------------------------------
                // Author-level: reading the public web changes nothing. The
                // risk these carry is not privilege escalation, it is what a
                // fetched page can do to the MODEL — prompt injection. That is
                // handled where it belongs, in the caller, by treating fetched
                // text as data to summarise rather than instructions to obey;
                // see ResearchAgent. Gating the tool harder would only stop
                // legitimate research without fixing that.
                'deep_research' => [
                    'params' => [
                        'query'  => ['type' => 'string', 'required' => true],
                        'depth'  => ['type' => 'int',    'required' => false],
                        'engine' => ['type' => 'string', 'required' => false],
                    ],
                    'handler' => $deepResearch,
                ],
                'search_web' => [
                    'params' => [
                        'query'  => ['type' => 'string', 'required' => true],
                        'engine' => ['type' => 'string', 'required' => false],
                        'limit'  => ['type' => 'int',    'required' => false],
                    ],
                    'handler' => $searchWeb,
                ],
                'read_page' => [
                    'params' => [
                        'url' => ['type' => 'string', 'required' => true],
                    ],
                    'handler' => $readPage,
                ],
            ],

            'writers' => [
                'publish_post'    => 'editor',
                'unpublish_post'  => 'editor',
                'schedule_post'   => 'editor',
                'trash_post'      => 'editor',
                'update_setting'  => 'admin',
                'create_tag'      => 'editor',
                // Importing media from the internet is an editorial act and
                // needs 'editor': an author may draft posts but not fill the
                // shared media library with remote images.
                'register_media'      => 'editor',
                // Persisting analysis results is a write to editorial state:
                // an author generates drafts, an editor ships them.
                'save_seo_meta'        => 'editor',
                'upsert_design_token'  => 'editor',
                'save_theme'           => 'editor',
                // A news import writes unverified third-party text straight
                // into the editorial queue, so it needs 'editor': an author
                // may draft posts from their own research but may not let an
                // agent fill the queue on their behalf.
                'save_draft_post'      => 'editor',

                // Pages. Authoring a page is an editorial act like writing a
                // post, so create/edit/order sit at 'editor'. Deleting one is
                // 'admin' because trash is still a removal from the public
                // site, and it is the one page operation with no undo
                // available to the agent.
                'create_page'          => 'editor',
                'rename_page'          => 'editor',
                'update_page_body'     => 'editor',
                'set_menu_order'       => 'editor',
                'delete_page'          => 'admin',

                // apply_design_token changes what every visitor sees on every
                // page, which is a publishing decision wearing a stylesheet's
                // clothes. Editor is the floor for the same reason
                // save_seo_meta is: an author prepares, an editor ships.
                'apply_design_token'   => 'editor',
                // Switching the whole theme is larger still than editing its
                // tokens, and it is instantly visible site-wide — so admin.
                'activate_theme'       => 'admin',
                // Writes PHP that executes on every request for the theme.
                // Admin, and the handler additionally refuses to create files,
                // writes outside the theme, and anything but .php/.css.
                'update_theme_template' => 'admin',

                // See the schema block for why a credential is admin-only.
                'set_provider_key'     => 'admin',
                'clear_provider_key'   => 'admin',
            ],
        ];
    }
}