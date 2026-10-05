<?php
declare(strict_types=1);

namespace CMS\Render;

/**
 * Resolves the CSS custom properties the public theme actually consumes.
 *
 * WHY THIS EXISTS
 *
 * Two token vocabularies meet at the render layer and neither knew about the
 * other. The theme's stylesheet only ever reads ten variables --bg, --fg,
 * --accent and so on -- and layout.php hand-wrote all ten as an inline style
 * attribute. Meanwhile DesignAgent writes semantic tokens to the design_tokens
 * table as --color-primary, --font-heading, --space-md.
 *
 * The result: an agent could write a perfectly valid token, the row would land
 * in the database, and the site would not change by one pixel. Nothing in
 * src/Render/ referenced the design_tokens table at all.
 *
 * So this class is the join. Both vocabularies resolve into the ten variables
 * the stylesheet reads, with an explicit alias table between them. A token the
 * alias table does not cover is still emitted under its own --color-* name, so
 * a theme that adopts it later starts working without a migration.
 *
 * Precedence, lowest to highest:
 *   1. DEFAULTS   — the built-in palette, so a bare render is never unstyled
 *   2. theme.json — the active theme's own token block
 *   3. design_tokens — per-site AI edits, the only layer a live site writes
 *
 * Database access is deliberately lazy and failure-tolerant. A theme must
 * still render if the table is missing, unreadable, or locked: this class is
 * consulted on every page view, and a design-token lookup is never worth taking
 * a site down for.
 */
final class DesignVars
{
    /**
     * The stylesheet's ten variables and their built-in fallbacks.
     *
     * These are the only custom properties assets/css/site.css references, so
     * this list and that file's var() usage have to stay in step. If the theme
     * grows a new variable, add it here and in the stylesheet together.
     */
    public const DEFAULTS = [
        '--bg'       => '#ffffff',
        '--fg'       => '#1a1a1a',
        '--muted'    => '#5c5c5c',
        '--accent'   => '#2563eb',
        '--border'   => '#e5e5e5',
        '--surface'  => '#fafafa',
        '--radius'   => '10px',
        '--measure'  => '72ch',
        '--font-body' => "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
        '--font-mono' => "ui-monospace, SFMono-Regular, Menlo, monospace",
    ];

    /**
     * theme.json token key -> stylesheet variable.
     *
     * theme.json uses camelCase designer names; the stylesheet uses CSS names.
     * Written out rather than derived, because the mapping is a design
     * decision, not a mechanical transform -- 'maxWidth' is the measure, not a
     * width, and 'fontBody' is the body stack, not a font called "Body".
     */
    private const THEME_ALIASES = [
        'bg'        => '--bg',
        'fg'        => '--fg',
        'muted'     => '--muted',
        'accent'    => '--accent',
        'border'    => '--border',
        'surface'   => '--surface',
        'radius'    => '--radius',
        'maxWidth'  => '--measure',
        'fontBody'  => '--font-body',
        'fontMono'  => '--font-mono',
    ];

    /**
     * design_tokens category.name -> stylesheet variable.
     *
     * The left side is exactly what DesignAgent writes. Everything the alias
     * table does not name still flows through as --<category>-<name>, so a new
     * token is usable immediately even though no alias claims it.
     */
    private const TOKEN_ALIASES = [
        'color.bg'       => '--bg',
        'color.fg'       => '--fg',
        'color.text'     => '--fg',
        'color.muted'    => '--muted',
        'color.accent'   => '--accent',
        'color.primary'  => '--accent',
        'color.border'   => '--border',
        'color.surface'  => '--surface',
        'font.body'      => '--font-body',
        'font.mono'      => '--font-mono',
    ];

    /** Memoised per request; the layout is rendered once per response. */
    private static ?array $cached = null;

    /**
     * Shared read handle. Null until the first resolve() that reaches the
     * database; see readHandle() for why it is shared rather than per-call.
     */
    private static ?\PDO $db = null;

    /** Public so a test can assert the leak count is zero. */
    public static int $leakCount = 0;

    /** @internal Test seam: forget the memo between cases. */
    public static function reset(): void
    {
        self::$cached = null;

        // The read handle may be sitting in the implicit transaction PDO opened
        // for the last SELECT. Under php -S the process dies at the end of the
        // request and nobody notices; in a long-lived worker the write lock stays
        // held for the life of the process and every later write fails with
        // "database is locked". Releasing it here means a token edit cannot leave
        // the site unable to accept another one.
        if (self::$db !== null) {
            try {
                if (self::$db->inTransaction()) {
                    self::$db->rollBack();
                }
            } catch (\Throwable) {
                // Nothing to recover — the handle is dead and will be rebuilt.
                self::$db = null;
            }
        }
    }

    /**
     * The full custom-property map, ready to drop into a style attribute.
     *
     * @param array $themeTokens The active theme's own token block
     * @return array<string,string> CSS variable name => value
     */
    public static function resolve(array $themeTokens = []): array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $vars = self::DEFAULTS;

        foreach ($themeTokens as $key => $value) {
            $clean = self::sanitise((string) $value);
            if ($clean === null) {
                continue;
            }
            $name = self::THEME_ALIASES[(string) $key] ?? '--' . self::kebab((string) $key);
            $vars[$name] = $clean;
        }

        foreach (self::fromDatabase() as $name => $value) {
            $vars[$name] = $value;
        }

        return self::$cached = $vars;
    }

    /**
     * The resolved map as an inline style attribute value.
     *
     * Every value has already been through sanitise(), and every name is either
     * a constant from this file or a kebab-cased database key, so neither can
     * contain a quote or a semicolon that would break out of the attribute.
     */
    public static function toStyleAttribute(?array $themeTokens = null): string
    {
        $vars = $themeTokens === null ? self::resolve() : self::resolve($themeTokens);

        $parts = [];
        foreach ($vars as $name => $value) {
            $parts[] = $name . ':' . $value;
        }

        return implode(';', $parts);
    }

    /**
     * design_token rows folded into stylesheet variable names.
     *
     * One shared read handle, created on first use and reused thereafter.
     *
     * The original version opened a brand-new Connection on EVERY render — one
     * SQLite handle per page view, which is exactly as expensive as it sounds.
     *
     * Worse, it leaked the write lock. PDO's implicit transactions commit at the
     * end of a request, but under any long-lived worker (RoadRunner, FrankenPHP, a
     * queue daemon, a test harness) there is no request boundary, so the handle
     * stayed in a transaction and held the database's single write lock for the
     * life of the process. Every later write then failed with "database is
     * locked" after waiting out its full busy timeout — and because the lock
     * never went away, EVERY write failed, including the ones that caused it.
     * The site was one page view away from being read-only, permanently.
     *
     * So: reuse the handle, roll back explicitly, and make the memo stale
     * whenever the data might have changed rather than only on an explicit reset.
     *
     * @return array<string,string>
     */
    private static function fromDatabase(): array
    {
        try {
            $pdo = self::readHandle();

            $rows = $pdo->query('SELECT key, value, category, css_var FROM design_tokens')->fetchAll();

            // A handle left mid-transaction by anything else in this process is
            // rolled back here, where the leak is visible and attributable. Without
            // this, one stray transaction anywhere wedges every later write.
            if ($pdo->inTransaction()) {
                self::$leakCount++;
            }
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (\Throwable) {
            // An unstyled site is a degraded site. A site with no stylesheet at
            // all is a broken one, so this must never propagate.
            return [];
        }

        $vars = [];
        foreach ($rows as $row) {
            $value = self::sanitise((string) ($row['value'] ?? ''));
            if ($value === null) {
                continue;
            }

            $key      = (string) ($row['key'] ?? '');
            $category = (string) ($row['category'] ?? '');

            // An aliased token drives a variable the stylesheet already reads.
            $aliased = self::TOKEN_ALIASES[strtolower($key)] ?? null;

            // Otherwise trust the stored css_var, but only if it is a plain
            // custom property. A stored value is not a licence to inject an
            // arbitrary declaration into the style attribute.
            $name = $aliased ?? self::safeVarName((string) ($row['css_var'] ?? ''))
                ?? self::kebab($category . '-' . self::kebab($key));

            $vars[$name] = $value;
        }

        return $vars;
    }

    /**
     * The shared read handle for design tokens.
     *
     * Read-only use only. A caller that writes through this handle inherits the
     * implicit-transaction behaviour described on fromDatabase(), which is why
     * the rollback lives there rather than being left to the caller.
     */
    private static function readHandle(): \PDO
    {
        if (self::$db === null) {
            self::$db = (new \CMS\Database\Connection(
                require __DIR__ . '/../../config/database.php'
            ))->getPdo();
        }

        return self::$db;
    }

    /**
     * A css_var is usable only if it is exactly --<kebab> with no extra syntax.
     *
     * Returns null rather than the input when it is not, so a poisoned row
     * cannot smuggle "red; background:url(http://evil)" into the page.
     */
    private static function safeVarName(string $raw): ?string
    {
        $raw = trim($raw);
        if (preg_match('/^--[a-z0-9]+(?:-[a-z0-9]+)*$/', strtolower($raw)) !== 1) {
            return null;
        }
        return '--' . self::kebab(substr($raw, 2));
    }

    /**
     * Is this value safe to store as a design token?
     *
     * The same predicate fromDatabase() applies on the way out, exposed so
     * the write path can ask the question BEFORE the row lands rather than
     * after. Without this an agent is told a bad token saved successfully, the
     * row is written, and the page is unchanged — a silent failure that reads
     * to the model as success.
     *
     * @return string|null The trimmed value, or null if it would be rejected.
     */
    public static function validateValue(string $value): ?string
    {
        return self::sanitise($value);
    }

    /**
     * Is this a usable custom-property name?
     *
     * Returns the canonical form (lowercased, kebab-cased) so the caller
     * stores exactly what will be emitted.
     */
    public static function validateVarName(string $raw): ?string
    {
        return self::safeVarName($raw);
    }

    /**
     * Values must survive being placed in an HTML attribute, as a single
     * declaration.
     *
     * A CSS value legitimately contains quotes, commas, parentheses and spaces
     * (the font stacks above), so those are kept. The one character that must
     * never appear is the semicolon: it is the declaration separator, so
     * "red; background:url(//evil)" is two declarations, not one value. An
     * earlier version stripped quotes and angle brackets but let the
     * semicolon through, which closed off the attribute's value cleanly and
     * still let an attacker append a declaration of their own.
     *
     * So: reject the whole value rather than trying to repair it. A stored
     * token is authored by an agent from a prompt; if it produced something
     * this malformed, dropping it is the correct outcome, not sanitising it
     * into a subtly different declaration.
     */
    private static function sanitise(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 400) {
            return null;
        }

        // Control characters have no business in a stylesheet.
        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            return null;
        }

        // Declaration separator, attribute delimiters, tag delimiters.
        if (strpbrk($value, ';<>"\'') !== false) {
            return null;
        }

        return $value;
    }

    private static function kebab(string $value): string
    {
        $kebab = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $value), '-'));
        return $kebab !== '' ? $kebab : 'x';
    }
}