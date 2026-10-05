<?php
/**
 * Global helper functions.
 *
 * Autoloaded via composer.json "files".
 */

if (!function_exists('env')) {
    /**
     * Values loaded from .env, kept by cms_env_load() because Dotenv v5 has no
     * way to read them back out.
     */
    function &cms_env_store(): array
    {
        static $store = [];
        return $store;
    }

    /**
     * Load .env into a process-wide array that env() can read.
     *
     * Exists because Dotenv v5's default adapters write to NEITHER $_ENV,
     * $_SERVER nor getenv() — they populate only the repository object, and
     * 5.7 exposes no getter for it. A helper that reads the superglobals
     * therefore sees nothing in .env and returns its default for every value
     * in it. That is not theoretical: it is why CMS_ENCRYPTION_KEY sat
     * set-and-correct in .env while the BYOK screen insisted it was unset.
     *
     * Values are returned verbatim rather than type-cast, so `true` stays a
     * string here and env() applies the same casting it always has.
     */
    function cms_env_load(string $path): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $store = &cms_env_store();

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eq));
            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $key)) {
                continue;
            }

            $value = trim(substr($line, $eq + 1));

            // Surrounding quotes are shell syntax, not part of the value.
            $len = strlen($value);
            if ($len >= 2
                && ($value[0] === '"' || $value[0] === "'")
                && $value[$len - 1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            // A variable already present in the real environment wins: that is
            // what makes a test or a CI job able to override .env.
            if ($value !== ''
                && ($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key)) !== false
                && (($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key)) ?? '') !== '') {
                continue;
            }

            $store[$key] = $value;
        }

        return true;
    }

    /**
     * Read an environment variable with an optional default.
     *
     * Order: the real process environment first, then values parsed out of
     * .env by cms_env_load(), so an explicit export or CI variable overrides
     * the file rather than the other way round.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            $store = &cms_env_store();
            $value = $store[$key] ?? null;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return match (strtolower((string) $value)) {
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