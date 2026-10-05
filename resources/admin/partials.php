<?php
/**
 * Shared render helpers for admin screens.
 *
 * Defined once by partials.php's includer and used by every screen. Keeping
 * them here rather than inlining each screen means a stat tile looks the same
 * everywhere and there is one place to fix when it needs to.
 */

if (!function_exists('e')) {
    /** HTML-escape. Every dynamic value printed by an admin screen. */
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('bytes')) {
    /** Human-readable size. Media lists are unreadable in raw bytes. */
    function bytes(int $n): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i     = 0;
        $v     = (float) $n;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) (int) $v : number_format($v, 1)) . ' ' . $units[$i];
    }
}

if (!function_exists('ago')) {
    /** Relative time. "3d ago" beats "2026-09-28 14:02:11" in a table. */
    function ago(?string $when): string
    {
        if ($when === null || $when === '') {
            return '—';
        }
        $ts   = strtotime($when);
        $diff = time() - $ts;
        if ($diff < 0)    { return 'scheduled'; }
        if ($diff < 60)   { return 'just now'; }
        if ($diff < 3600) { return intdiv($diff, 60) . 'm ago'; }
        if ($diff < 86400){ return intdiv($diff, 3600) . 'h ago'; }
        if ($diff < 604800){ return intdiv($diff, 86400) . 'd ago'; }
        return date('j M Y', $ts);
    }
}

if (!function_exists('badge')) {
    /**
     * A status pill.
     *
     * The class is derived from a fixed map rather than from the raw status, so
     * a status string can never become a class name straight from the database.
     */
    function badge(string $status): string
    {
        $map = [
            'published'  => 'ok',
            'active'     => 'ok',
            'succeeded'  => 'ok',
            'draft'      => 'muted',
            'queued'     => 'info',
            'running'    => 'info',
            'scheduled'  => 'info',
            'review'     => 'warn',
            'pending'    => 'warn',
            'failed'     => 'bad',
            'dead'       => 'bad',
            'error'      => 'bad',
            'trash'      => 'bad',
            'suspended'  => 'bad',
            'cancelled'  => 'muted',
        ];
        $cls = $map[strtolower($status)] ?? 'muted';
        return '<span class="badge badge-' . $cls . '">' . e($status) . '</span>';
    }
}

if (!function_exists('stat_tile')) {
    /** One number on the dashboard. */
    function stat_tile(string $label, int|float $value, string $sub = '', string $tone = ''): string
    {
        $sub = $sub !== '' ? '<small>' . e($sub) . '</small>' : '';
        return '<a class="tile ' . e($tone) . '" href="' . e($sub !== '' ? '#' : '#') . '">'
             . '<span class="tile-value">' . e((string) $value) . '</span>'
             . '<span class="tile-label">' . e($label) . '</span>'
             . $sub
             . '</a>';
    }
}

if (!function_exists('empty_state')) {
    /** Shown when a list has nothing in it — never a bare empty table. */
    function empty_state(string $message, string $hint = '', string $action = ''): string
    {
        $h = $hint !== '' ? '<p>' . e($hint) . '</p>' : '';
        $a = $action !== '' ? '<p>' . $action . '</p>' : '';
        return '<div class="empty"><p>' . e($message) . '</p>' . $h . $a . '</div>';
    }
}

if (!function_exists('pagination')) {
    /** Prev/next pager driven by ?page=, shown only when there is a next page. */
    function pagination(int $page, int $total, int $limit, string $base): string
    {
        $pages = (int) ceil($total / max(1, $limit));
        if ($pages <= 1) {
            return '';
        }
        $prev = $page > 1 ? $page - 1 : null;
        $next = $page < $pages ? $page + 1 : null;

        $link = static function (?int $p, string $label, bool $disabled): string {
            if ($disabled || $p === null) {
                return '<span class="page disabled">' . e($label) . '</span>';
            }
            return '<a class="page" href="' . e($base . '?page=' . $p) . '">' . e($label) . '</a>';
        };

        return '<nav class="pager" aria-label="Pagination">'
             . $link($prev, '← Previous', $page <= 1)
             . '<span class="page of">Page ' . (int) $page . ' of ' . $pages . '</span>'
             . $link($next, 'Next →', $page >= $pages)
             . '</nav>';
    }
}

if (!function_exists('param')) {
    /** Rebuild a query string with one value replaced — keeps filters on paging. */
    function param(array $query, string $key, $value): string
    {
        $query[$key] = $value;
        return '?' . http_build_query(array_filter(
            $query,
            static fn ($v) => $v !== null && $v !== ''
        ));
    }
}