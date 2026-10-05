<?php
/**
 * Global helper functions.
 *
 * Autoloaded via composer.json "files".
 */

if (!function_exists('env')) {
    /**
     * Read an environment variable with an optional default.
     *
     * Uses a cached dotenv repository when one has been booted, otherwise
     * falls back to getenv()/$_ENV/$_SERVER so the CLI still works when
     * .env is absent.
     */
    function env(string $key, mixed $default = null): mixed
    {
        static $repository = null;

        if ($repository instanceof \Dotenv\Repository\RepositoryInterface) {
            return $repository->get($key) ?? $default;
        }

        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return match (strtolower($value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}

if (!function_exists('env_bool')) {
    function env_bool(string $key, bool $default = false): bool
    {
        $value = env($key, $default);
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = dirname(__DIR__);
        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}

if (!function_exists('json_out')) {
    /**
     * Write a JSON response body and return the response.
     */
    function json_out($response, mixed $data, int $status = 200): \Psr\Http\Message\ResponseInterface
    {
        $response = $response->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status);
    }
}

if (!function_exists('slugify')) {
    /**
     * URL slug. Matches the `slug` column's uniqueness contract so a
     * generated slug can never contain SQL or path-hostile characters.
     */
    function slugify(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return $slug === '' ? 'untitled' : $slug;
    }
}