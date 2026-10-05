<?php
namespace CMS\Agents;

/**
 * Validates and normalizes a model-produced design token tree.
 *
 * This is the boundary that makes it safe to store whatever an LLM returns:
 * every entry is checked against the shape the design_tokens table can
 * actually hold, and anything that fails is DROPPED rather than written.
 * A model that answers with prose yields an empty token list, which
 * DesignAgent reports as a clean 'failed' — never a parse fatal, never a
 * half-written row.
 *
 * Pure functions only — no DB, no LLM, no I/O.
 */
final class Tokens
{
    /** group key in the model's JSON => design_tokens.category */
    private const GROUPS = [
        'colors'  => 'color',
        'fonts'   => 'font',
        'space'   => 'space',
        'radius'  => 'radius',
        'shadows' => 'shadow',
        'layout'  => 'layout',
    ];

    private const MAX_TOKENS = 200;
    private const MAX_VALUE_LENGTH = 200;

    /**
     * @return array{
     *   name:string, slug:string,
     *   tokens:list<array{key:string,value:string,category:string,css_var:string}>,
     *   config:array, rejected:list<array{key:string,reason:string}>
     * }
     */
    public static function normalize(array $raw): array
    {
        $name = self::stringOr((string) ($raw['name'] ?? ''), 'Untitled Theme', 120);
        $slug = self::slugOr((string) ($raw['slug'] ?? ''), $name);

        $tokens = [];
        $config = [];
        $rejected = [];

        foreach (self::GROUPS as $group => $category) {
            $entries = $raw[$group] ?? null;

            // Tolerate a nested {"color": {...}} form as well as the flat one.
            if (!is_array($entries)) {
                continue;
            }

            $accepted = [];
            foreach ($entries as $name => $value) {
                if (!is_string($name) || trim($name) === '') {
                    $rejected[] = ['key' => (string) $name, 'reason' => 'empty token name'];
                    continue;
                }
                if (count($tokens) >= self::MAX_TOKENS) {
                    $rejected[] = ['key' => $name, 'reason' => 'token limit reached'];
                    break 2;
                }
                if (!is_string($value)) {
                    $rejected[] = ['key' => $name, 'reason' => 'value is not a string'];
                    continue;
                }

                $value = trim($value);
                $clean = match ($category) {
                    'color'  => self::color($value),
                    'space', 'radius', 'layout' => self::length($value),
                    // Font stacks and shadows are free text, but still bounded.
                    default  => self::plain($value),
                };

                if ($clean === null) {
                    $rejected[] = ['key' => $name, 'reason' => "invalid {$category} value"];
                    continue;
                }

                $token = [
                    'key'      => $category . '.' . $name,
                    'value'    => $clean,
                    'category' => $category,
                    'css_var'  => self::cssVar($category, $name),
                ];
                $tokens[] = $token;
                $accepted[$name] = $clean;
            }

            if ($accepted !== []) {
                $config[$group] = $accepted;
            }
        }

        return ['name' => $name, 'slug' => $slug, 'tokens' => $tokens, 'config' => $config, 'rejected' => $rejected];
    }

    /** #RGB / #RRGGBB only — the renderer cannot do anything else. */
    private static function color(string $value): ?string
    {
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1
            ? strtolower($value)
            : null;
    }

    /** A CSS length: 0, 12px, 1.5rem, 80%, 1200px. */
    private static function length(string $value): ?string
    {
        return preg_match('/^(?:0|-?\d+(?:\.\d+)?)(?:px|rem|em|%|vh|vw|ch)$/', $value) === 1
            ? $value
            : null;
    }

    /** Bounded free text for font stacks and shadows. */
    private static function plain(string $value): ?string
    {
        if ($value === '' || mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            return null;
        }
        // Strip control characters that have no business in a stylesheet.
        return preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
    }

    /** color.primary -> --color-primary ; font.heading -> --font-heading */
    private static function cssVar(string $category, string $name): string
    {
        $kebab = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
        return '--' . $category . '-' . ($kebab !== '' ? $kebab : 'x');
    }

    private static function slugOr(string $raw, string $name): string
    {
        $slug = strtolower(trim($raw));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = slugify($name);
        }
        return mb_substr($slug !== '' ? $slug : 'theme', 0, 60);
    }

    private static function stringOr(string $raw, string $fallback, int $max): string
    {
        $raw = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $raw) ?? '');
        if ($raw === '') {
            return $fallback;
        }
        return mb_substr($raw, 0, $max);
    }
}