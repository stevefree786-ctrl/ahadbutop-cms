<?php
namespace CMS\Agents;

use CMS\Database\Connection;

/**
 * The single registry mapping job `type` strings to callables.
 *
 * BaseAgent::enqueueJob() already writes rows into `jobs`; this is the other
 * half — what a worker runs when it dequeues one. Handlers receive the
 * decoded payload plus a Context (the identity of whoever caused the job,
 * or of the system for cron-enqueued work) and return an array result. They
 * MUST use bound parameters and the IntentRegistry; nothing here takes SQL.
 *
 * A worker that lands later should consume this map directly rather than
 * re-deriving it:
 *
 *     $handler = JobTypes::handlers($db)[$row['type']] ?? null;
 *     $result  = $handler($row['payload'], $context);
 *
 * `cms.jobs.handlers()` exposes the same thing so a worker that does not
 * want a hard dependency on this namespace can still reach it.
 */
final class JobTypes
{
    /**
     * Payload contract shared by every handler.
     *
     * @param array    $payload  Decoded `jobs.payload`.
     * @param Context  $context  Acting identity (user_role drives authorization).
     */
    public const HANDLER_DOC = 'function (array $payload, Context $context): array';

    /**
     * job.type => [label, required payload keys, callable factory].
     *
     * The factory takes a Connection and returns the handler closure, so each
     * handler closes over its own prepared statements.
     */
    public static function definitions(): array
    {
        return [
            'generate_daily_post' => [
                'label'    => 'Daily blog post',
                'params'   => ['topic'],
                'required' => false,
                'factory'  => [self::class, 'dailyPost'],
            ],
            'seo.persist' => [
                'label'    => 'Persist an SEO meta pack',
                'params'   => ['entity_type', 'entity_id', 'seo'],
                'required' => true,
                'factory'  => [self::class, 'seoPersist'],
            ],
            'seo_score' => [
                'label'    => 'Score one post',
                'params'   => ['post_id'],
                'required' => true,
                'factory'  => [self::class, 'seoScore'],
            ],
            'design_tokens' => [
                'label'    => 'Generate and persist design tokens',
                'params'   => ['brief'],
                'required' => true,
                'factory'  => [self::class, 'designTokens'],
            ],
            'generate_sitemap' => [
                'label'    => 'Write sitemap.xml to storage',
                'params'   => ['base_url'],
                'required' => false,
                'factory'  => [self::class, 'generateSitemap'],
            ],
        ];
    }

    /**
     * type => callable. Each handler takes (payload, Context) so a worker can
     * pass the acting identity straight through.
     *
     * @return array<string, callable(array, Context): array>
     */
    public static function handlers(Connection $db): array
    {
        $handlers = [];

        foreach (self::definitions() as $type => $def) {
            $handlers[$type] = ($def['factory'])($db);
        }

        return $handlers;
    }

    /** Bound to $context for direct calls: $handler($payload). */
    public static function handler(Connection $db, string $type, Context $context): ?callable
    {
        $def = self::definitions()[$type] ?? null;
        if ($def === null) {
            return null;
        }

        $handler = ($def['factory'])($db);

        return static fn(array $payload): array => $handler($payload, $context);
    }

    public static function supports(string $type): bool
    {
        return isset(self::definitions()[$type]);
    }

    public static function types(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * Validate a payload against its declared required keys without running
     * anything. A worker can call this first and dead-letter a malformed job
     * instead of throwing inside the handler.
     */
    public static function validatePayload(string $type, array $payload): array
    {
        $def = self::definitions()[$type] ?? null;

        if ($def === null) {
            return ['ok' => false, 'error' => 'Unknown job type: ' . $type];
        }

        $missing = [];
        foreach ($def['params'] as $key) {
            if (($def['required'] ?? false) && (!isset($payload[$key]) || $payload[$key] === '')) {
                $missing[] = $key;
            }
        }

        return $missing === []
            ? ['ok' => true]
            : ['ok' => false, 'error' => "Missing payload key(s): " . implode(', ', $missing)];
    }

    // ------------------------------------------------------------------
    // Handlers
    // ------------------------------------------------------------------

    public static function dailyPost(Connection $db): callable
    {
        return static function (array $payload, Context $context) use ($db): array {
            $topic = trim((string) ($payload['topic'] ?? '')) ?: 'a topic relevant to this site';

            $result = (new BlogAgent($db))->execute('Write a blog post about: ' . $topic, $context->toArray());

            if (($result['status'] ?? '') !== 'created') {
                return ['status' => 'failed', 'error' => $result['error'] ?? 'daily post generation failed'];
            }

            return ['status' => 'ok', 'post_id' => $result['post_id']];
        };
    }

    public static function seoPersist(Connection $db): callable
    {
        return static function (array $payload, Context $context) use ($db): array {
            $check = self::validatePayload('seo.persist', $payload);
            if (!$check['ok']) {
                return ['status' => 'failed', 'error' => $check['error']];
            }

            $entityType = (string) $payload['entity_type'];
            $entityId   = $payload['entity_id'];
            $seo        = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];

            $built    = Actions::build($db);
            $registry = new IntentRegistry($db, $built['schema'], $built['writers']);

            $params = [
                'entity_type'      => $entityType,
                'entity_id'        => $entityId,
                'meta_title'       => self::str($seo['meta_title'] ?? null),
                'meta_description' => self::str($seo['meta_description'] ?? null),
                'canonical_url'    => self::str($seo['canonical_url'] ?? null),
                'focus_keyword'    => self::str($seo['keywords'][0] ?? ($seo['focus_keyword'] ?? null)),
                'schema_json'      => isset($seo['schema_type']) ? json_encode(['@type' => $seo['schema_type']]) : null,
            ];
            $params = array_filter($params, static fn($v) => $v !== null);

            try {
                return ['status' => 'ok', 'result' => $registry->execute(
                    ['action' => 'save_seo_meta', 'params' => $params],
                    $context->userRole
                )];
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                return ['status' => 'rejected', 'error' => $e->getMessage()];
            }
        };
    }

    public static function seoScore(Connection $db): callable
    {
        return static function (array $payload, Context $context) use ($db): array {
            $check = self::validatePayload('seo_score', $payload);
            if (!$check['ok']) {
                return ['status' => 'failed', 'error' => $check['error']];
            }

            $result = (new SeoAgent($db))->audit((int) $payload['post_id'], $context);

            return $result['status'] === 'ok'
                ? ['status' => 'ok', 'score' => $result['score']]
                : ['status' => $result['status'], 'error' => $result['error'] ?? null];
        };
    }

    public static function designTokens(Connection $db): callable
    {
        return static function (array $payload, Context $context) use ($db): array {
            $check = self::validatePayload('design_tokens', $payload);
            if (!$check['ok']) {
                return ['status' => 'failed', 'error' => $check['error']];
            }

            return (new DesignAgent($db))->generateTokens((string) $payload['brief'], $context);
        };
    }

    public static function generateSitemap(Connection $db): callable
    {
        return static function (array $payload, Context $context): array {
            $baseUrl = rtrim((string) ($payload['base_url'] ?? $context->siteTitle), '/');

            if ($baseUrl === '') {
                return ['status' => 'failed', 'error' => 'base_url is required'];
            }

            $xml = (new SeoAgent($db))->generateSitemap($baseUrl);
            $dir = dirname(__DIR__, 2) . '/storage/';
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                return ['status' => 'failed', 'error' => 'Could not create storage directory'];
            }

            $path = $dir . 'sitemap.xml';
            if (file_put_contents($path, $xml) === false) {
                return ['status' => 'failed', 'error' => 'Could not write sitemap.xml'];
            }

            return ['status' => 'ok', 'path' => $path, 'bytes' => strlen($xml)];
        };
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}