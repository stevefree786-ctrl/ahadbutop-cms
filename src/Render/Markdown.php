<?php
namespace CMS\Render;

/**
 * Markdown -> HTML renderer.
 *
 * Deliberately not a CommonMark-complete implementation. It covers the
 * constructs a CMS actually stores in `posts.body_md`, and every output
 * path is escaped by default — raw HTML in content is only emitted when
 * the caller explicitly opts in via Markdown::render($md, ['html' => true]).
 *
 * Security stance: content is untrusted (AI-generated, or authored by a
 * lower-privileged role). XSS in the rendered page is the threat, so the
 * default is escape-everything.
 */
class Markdown
{
    /**
     * @param array{html?:bool, breaks?:bool, toc?:bool} $options
     */
    public static function render(string $markdown, array $options = []): string
    {
        $allowHtml = (bool) ($options['html'] ?? false);
        $breaks    = (bool) ($options['breaks'] ?? false);

        // Extract fenced code first so its contents are never re-parsed.
        $blocks = [];
        $markdown = preg_replace_callback(
            '/^```([a-zA-Z0-9_+-]*)\s*\n(.*?)^```\s*$/ms',
            static function ($m) use (&$blocks) {
                $token = "\x00CODE" . count($blocks) . "\x00";
                $blocks[$token] = '<pre><code'
                    . ($m[1] !== '' ? ' class="language-' . htmlspecialchars($m[1], ENT_QUOTES) . '"' : '')
                    . '>' . htmlspecialchars(rtrim($m[2]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '</code></pre>';
                return $token;
            },
            $markdown
        ) ?? $markdown;

        // Protect inline code spans so their contents escape the other rules.
        $spans = [];
        $markdown = preg_replace_callback('/`([^`\n]+)`/', static function ($m) use (&$spans) {
            $token = "\x00CODE_SPAN" . count($spans) . "\x00";
            $spans[$token] = '<code>' . htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
            return $token;
        }, $markdown) ?? $markdown;

        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $total = count($lines);

        $html    = [];
        $listType = null;   // 'ul' | 'ol' | null
        $inQuote = false;

        $closeList   = static function () use (&$listType, &$html) {
            if ($listType !== null) {
                $html[] = ($listType === 'ul' ? '</ul>' : '</ol>');
                $listType = null;
            }
        };
        $closeQuote = static function () use (&$inQuote, &$html) {
            if ($inQuote) {
                $html[] = '</blockquote>';
                $inQuote = false;
            }
        };

        for ($i = 0; $i < $total; $i++) {
            $line = $lines[$i];
            // Blank line closes any open list / quote.
            if (trim($line) === '') {
                $closeList();
                $closeQuote();
                continue;
            }

            // ATX heading
            if (preg_match('/^(#{1,6})\s+(.*?)\s*#*$/', $line, $m)) {
                $closeList();
                $closeQuote();
                $level = strlen($m[1]);
                $text  = self::inline($m[2], $allowHtml);
                $id    = self::slugifyHeading($m[2]);
                $headingLines[] = ['level' => $level, 'text' => strip_tags($text), 'id' => $id];
                $html[] = "<h{$level} id=\"" . htmlspecialchars($id, ENT_QUOTES) . "\">{$text}</h{$level}>";
                continue;
            }

            // Horizontal rule
            if (preg_match('/^\s{0,3}([-*_])(\s*\1){2,}\s*$/', $line)) {
                $closeList();
                $closeQuote();
                $html[] = '<hr>';
                continue;
            }

            // Blockquote
            if (preg_match('/^\s{0,3}>\s?(.*)$/', $line, $m)) {
                $closeList();
                if (!$inQuote) {
                    $html[] = '<blockquote>';
                    $inQuote = true;
                }
                $html[] = '<p>' . self::inline($m[1], $allowHtml) . '</p>';
                continue;
            }

            // Table: header row + delimiter row, then body rows until the table ends.
            if (preg_match('/^\s*\|(.+)\|\s*$/', $line)
                && isset($lines[$i + 1])
                && preg_match('/^\s*\|[\s:\-|]+\|\s*$/', $lines[$i + 1])) {
                $closeList();
                $closeQuote();
                $rows = [];
                while ($i < $total && preg_match('/^\s*\|(.+)\|\s*$/', trim($lines[$i]))) {
                    $rows[] = trim($lines[$i]);
                    $i++;
                }
                $i--; // the outer for-loop's $i++ consumes the terminator
                $html[] = self::renderTable($rows, $allowHtml);
                continue;
            }

            // Task list — must be tested BEFORE the plain bullet rule, or
            // "- [ ] ship it" renders as a bullet whose text is "[ ] ship it".
            if (preg_match('/^\s{0,3}[-*+]\s+\[([ xX])\]\s+(.*)$/', $line, $m)) {
                $closeQuote();
                if ($listType !== 'ul') {
                    $closeList();
                    $html[] = '<ul class="task-list">';
                    $listType = 'ul';
                }
                $checked = strtolower($m[1]) === 'x' ? ' checked' : '';
                $html[] = '<li><input type="checkbox" disabled' . $checked . '> ' . self::inline($m[2], $allowHtml) . '</li>';
                continue;
            }

            // Unordered list item
            if (preg_match('/^\s{0,3}([*+-])\s+(.*)$/', $line, $m)) {
                $closeQuote();
                if ($listType !== 'ul') {
                    $closeList();
                    $html[] = '<ul>';
                    $listType = 'ul';
                }
                $html[] = '<li>' . self::inline($m[2], $allowHtml) . '</li>';
                continue;
            }

            // Ordered list item
            if (preg_match('/^\s{0,3}\d+[.)]\s+(.*)$/', $line, $m)) {
                $closeQuote();
                if ($listType !== 'ol') {
                    $closeList();
                    $html[] = '<ol>';
                    $listType = 'ol';
                }
                $html[] = '<li>' . self::inline($m[1], $allowHtml) . '</li>';
                continue;
            }

            // Paragraph line
            $closeList();
            $closeQuote();
            $html[] = '<p>' . self::inline($line, $allowHtml, $breaks) . '</p>';
        }

        $closeList();
        $closeQuote();

        $out = implode("\n", $html);

        // Restore protected tokens.
        foreach ($spans as $token => $code) {
            $out = str_replace($token, $code, $out);
        }
        foreach ($blocks as $token => $code) {
            $out = str_replace($token, $code, $out);
        }

        return $out;
    }

    /**
     * Inline formatting: bold, italic, strikethrough, links, images, autolinks.
     * Text is escaped unless raw HTML is explicitly allowed.
     */
    private static function inline(string $text, bool $allowHtml, bool $breaks = false): string
    {
        if (!$allowHtml) {
            $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        // Images before links: ![alt](src) — src sanitised against javascript:.
        // The title group must accept &quot; too: htmlspecialchars() has already
        // rewritten the quotes by the time this runs.
        $title = '(?:\s+(?:"|&quot;)([^"]*?)(?:"|&quot;))?';

        $text = preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)\s]+)' . $title . '\)/',
            static function ($m) {
                $src = self::safeUrl($m[2]);
                if ($src === null) {
                    return $m[0]; // already escaped; stays inert visible text
                }
                $t = $m[3] ?? '';
                return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"'
                     . ' alt="' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '"'
                     . ($t !== '' ? ' title="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '"' : '')
                     . ' loading="lazy">';
            },
            $text
        ) ?? $text;

        // Links — only http(s), mailto, relative and #fragment survive.
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)' . $title . '\)/',
            static function ($m) {
                $href = self::safeUrl($m[2]);
                if ($href === null) {
                    return $m[0]; // already escaped; stays inert visible text
                }
                $t = $m[3] ?? '';
                $external = preg_match('#^https?://#i', $href) === 1;
                return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'
                     . ($t !== '' ? ' title="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '"' : '')
                     . ($external ? ' rel="noopener noreferrer" target="_blank"' : '')
                     . '>' . $m[1] . '</a>';
            },
            $text
        ) ?? $text;

        // Bare URLs
        $text = preg_replace(
            '#(?<![\w"\'>])(https?://[^\s<]+)#i',
            '<a href="$1" rel="noopener noreferrer" target="_blank">$1</a>',
            $text
        ) ?? $text;

        $text = preg_replace('/\*\*\*(.+?)\*\*\*/s', '<strong><em>$1</em></strong>', $text) ?? $text;
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $text) ?? $text;

        if ($breaks) {
            $text = str_replace("\n", '<br>', $text);
        }

        return $text;
    }

    /**
     * Reject javascript:, data:, vbscript: and other script-bearing URLs.
     * Returns null when the URL is unsafe.
     */
    public static function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        // Strip control characters used to smuggle "java\0script:".
        $probe = strtolower(preg_replace('/[\x00-\x20]/', '', $url) ?? $url);
        if (preg_match('/^(javascript|data|vbscript|file):/i', $probe)) {
            return null;
        }
        return $url;
    }

    private static function renderTable(array $rows, bool $allowHtml): string
    {
        $out = '<table><thead><tr>';
        $cells = self::splitRow($rows[0]);
        foreach ($cells as $cell) {
            $out .= '<th>' . self::inline($cell, $allowHtml) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        foreach (array_slice($rows, 2) as $row) {
            $out .= '<tr>';
            foreach (self::splitRow($row) as $cell) {
                $out .= '<td>' . self::inline($cell, $allowHtml) . '</td>';
            }
            $out .= '</tr>';
        }
        return $out . '</tbody></table>';
    }

    private static function splitRow(string $row): array
    {
        $row = trim($row, " \t|");
        return array_map('trim', explode('|', $row));
    }

    private static function slugifyHeading(string $text): string
    {
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text; // strip link syntax
        $text = strtolower(strip_tags($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $slug = trim($slug, '-');
        return $slug === '' ? 'section' : substr($slug, 0, 80);
    }

    /** Headings for a table of contents. */
    public static function toc(string $markdown): array
    {
        preg_match_all('/^(#{2,3})\s+(.*)$/m', $markdown, $matches, PREG_SET_ORDER);
        return array_map(static fn ($m) => [
            'level' => strlen($m[1]),
            'text'  => trim(strip_tags($m[2])),
            'id'    => self::slugifyHeading($m[2]),
        ], $matches);
    }

    /** Plain-text excerpt, markdown syntax removed. */
    public static function excerpt(string $markdown, int $length = 160): string
    {
        $text = preg_replace('/^---.*?---/s', '', $markdown) ?? $markdown;
        $text = preg_replace('/```.*?```/s', ' ', $text) ?? $text;
        $text = preg_replace('/[#*_`>\[\]()]/', '', $text) ?? $text;
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');

        if (mb_strlen($text) <= $length) {
            return $text;
        }
        // Cut on a word boundary rather than mid-word.
        $cut = mb_substr($text, 0, $length);
        $sp  = mb_strrpos($cut, ' ');
        return rtrim($sp ? mb_substr($cut, 0, $sp) : $cut, " ,.;:") . '…';
    }
}