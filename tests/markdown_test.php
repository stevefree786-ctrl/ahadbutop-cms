<?php
/**
 * Markdown renderer tests.
 *
 * Run: php tests/markdown_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/Render/Markdown.php';

use CMS\Render\Markdown;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n       got: " . substr(str_replace("\n", ' ', $got), 0, 200) . "\n";
    }
}

// NOWDOC: backticks must survive verbatim, or the fenced block never forms.
$md = <<<'MD'
# Getting Started

Intro with **bold**, *italic*, `inline code`, a [link](https://example.com "Title") and an ![image](/media/a.png).

## Second Section

- first bullet
- second bullet

1. step one
2. step two

- [x] finished task
- [ ] pending task

> A quoted line.

| Feature | Supported |
| --- | --- |
| Agents | Yes |
| Queue | Yes |

```php
<?php echo "hi <script>"; ?>
```

---

Final words.
MD;

$html = Markdown::render($md);

echo "structure\n";
check('h1 gets an id', str_contains($html, '<h1 id="getting-started">'), $html);
check('h2 gets an id', str_contains($html, '<h2 id="second-section">'), $html);
check('strong', str_contains($html, '<strong>bold</strong>'), $html);
check('em', str_contains($html, '<em>italic</em>'), $html);
check('inline code', str_contains($html, '<code>inline code</code>'), $html);
check('link title survives escaping', str_contains($html, 'title="Title"'), $html);
check('external link gets rel', str_contains($html, 'rel="noopener noreferrer"'), $html);
check('image is lazy', str_contains($html, 'loading="lazy"'), $html);
check('exactly one plain ul', substr_count($html, '<ul>') === 1, $html);
check('ol present', str_contains($html, '<ol>'), $html);
check('task list classed', str_contains($html, '<ul class="task-list">'), $html);
check('checked checkbox', str_contains($html, 'disabled checked>'), $html);
check('unchecked checkbox', str_contains($html, 'disabled> pending task'), $html);
check('blockquote', str_contains($html, '<blockquote>'), $html);
check('table head', str_contains($html, '<table><thead><tr><th>Feature</th>'), $html);
check('table body', str_contains($html, '<td>Agents</td>'), $html);
check('fence language class', str_contains($html, 'class="language-php"'), $html);
check('fence content escaped', str_contains($html, '&lt;script&gt;'), $html);
check('hr', str_contains($html, '<hr>'), $html);

echo "\nescaping (content is untrusted)\n";
$xss = Markdown::render('Hi <script>alert(1)</script> and <img src=x onerror=alert(2)>');
check('script tag is inert', !str_contains($xss, '<script'), $xss);
check('img tag is inert', !str_contains($xss, '<img'), $xss);

$js = Markdown::render('[click](javascript:alert(1))');
check('javascript: link is inert text, not an anchor', !str_contains($js, '<a href'), $js);

$smuggled = Markdown::render("[click](java\tscript:alert(1))");
check('tab-smuggled javascript: is inert', !str_contains($smuggled, '<a href'), $smuggled);

$dataImg = Markdown::render('![x](data:text/html,<script>alert(1)</script>)');
check('data: image is inert text, not an img', !str_contains($dataImg, '<img'), $dataImg);

$obfuscated = Markdown::render('[click](vbscript:msgbox(1))');
check('vbscript: is inert', !str_contains($obfuscated, '<a href'), $obfuscated);

// Relative and fragment links must survive — over-blocking breaks real content.
$rel = Markdown::render('[docs](/docs/intro) and [top](#section)');
check('relative link kept', str_contains($rel, 'href="/docs/intro"'), $rel);
check('fragment link kept', str_contains($rel, 'href="#section"'), $rel);
check('relative link has no target=_blank', !str_contains($rel, 'target="_blank"'), $rel);

echo "\nedge cases\n";
// Two identical header rows: the table scan must not resume at the wrong index.
$twoTables = Markdown::render("| a | b |\n| --- | --- |\n| 1 | 2 |\n\n| a | b |\n| --- | --- |\n| 3 | 4 |");
check('two tables rendered', substr_count($twoTables, '<table>') === 2, $twoTables);
check('second table has its own body', str_contains($twoTables, '<td>3</td>'), $twoTables);

$dup = Markdown::render("## Setup\n\ntext\n\n## Setup\n\nmore");
check('duplicate headings both render', substr_count($dup, '<h2 id="setup">') === 2, $dup);

$empty = Markdown::render('');
check('empty input is empty output', trim($empty) === '', $empty);

$plain = Markdown::render('just a sentence');
check('plain text becomes one paragraph', $plain === '<p>just a sentence</p>', $plain);

$onlyFence = Markdown::render("```\nplain\n```");
check('fence without a language', str_contains($onlyFence, '<pre><code>plain</code></pre>'), $onlyFence);

echo "\nderived values\n";
$toc = Markdown::toc("## Alpha\n### Beta\n#### Gamma");
check('toc finds h2 and h3 only', count($toc) === 2 && $toc[0]['id'] === 'alpha' && $toc[1]['id'] === 'beta', (string) json_encode($toc));

$excerpt = Markdown::excerpt("# Title\n\nSome **body** text that runs on and on and should be cut at a word boundary nicely here.", 40);
check('excerpt cuts on a word boundary', !str_contains($excerpt, 'boundar'), $excerpt);
check('excerpt strips markdown syntax', !str_contains($excerpt, '**'), $excerpt);
check('excerpt ends with ellipsis', str_ends_with($excerpt, '…'), $excerpt);
check('short excerpt is not truncated', Markdown::excerpt('Short.', 100) === 'Short.', Markdown::excerpt('Short.', 100));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);