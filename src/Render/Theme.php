<?php
namespace CMS\Render;

/**
 * Theme loader.
 *
 * A theme is a directory under storage/themes/<name>/ containing:
 *   theme.json          metadata + optional design tokens
 *   layout.php          the page shell ($content is the rendered view)
 *   home.php  post.php  page.php  archive.php  404.php
 *   partials/*.php      reusable fragments (nav, footer, card)
 *   assets/*            copied verbatim to /assets/themes/<name>/
 *
 * Templates are plain PHP evaluated in a closure scope with $vars extracted
 * in. They are trusted files (they ship with the CMS, they are not user
 * content) — but the CONTENT rendered into them is not, which is why
 * everything arriving in $post['body_html'] is already escaped upstream.
 *
 * A theme may also be stored entirely as raw strings in the database (the
 * AI design agent writes themes this way); TemplateEngine handles both.
 */
class Theme
{
    private string $name;
    private string $root;
    private array $meta = [];
    private array $vars = [];

    public function __construct(string $name = 'default')
    {
        $this->name = $this->sanitiseName($name);
        $this->root = \dirname(__DIR__, 2) . '/storage/themes/' . $this->name;
    }

    /** A theme name comes from settings; never let it escape the directory. */
    public static function sanitiseName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9_-]+/', '', $name) ?? '';
        return $name === '' ? 'default' : substr($name, 0, 40);
    }

    public function exists(): bool
    {
        return is_dir($this->root);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function path(string $relative = ''): string
    {
        return $this->root . ($relative === '' ? '' : '/' . ltrim($relative, '/'));
    }

    public function meta(): array
    {
        if ($this->meta === []) {
            $file = $this->path('theme.json');
            $this->meta = is_file($file)
                ? (json_decode((string) file_get_contents($file), true) ?: [])
                : [];
        }
        return $this->meta;
    }

    /** Variables available to every template. */
    public function share(array $vars): void
    {
        $this->vars = array_merge($this->vars, $vars);
    }

    public function render(string $template, array $vars = []): string
    {
        $engine = new TemplateEngine($this);
        return $engine->render($template, array_merge($this->vars, $vars));
    }

    /** Resolve a partial: returns '' rather than throwing when it's absent. */
    public function partial(string $name, array $vars = []): string
    {
        $engine = new TemplateEngine($this);
        return $engine->renderPartial($name, $vars);
    }

    /** Every template name a theme provides, for the admin theme picker. */
    public function availableTemplates(): array
    {
        if (!is_dir($this->root)) {
            return [];
        }
        $out = [];
        foreach (scandir($this->root) ?: [] as $entry) {
            if (str_ends_with($entry, '.php')) {
                $out[] = basename($entry, '.php');
            }
        }
        sort($out);
        return $out;
    }

    /** Themes on disk, for the settings screen. */
    public static function installed(): array
    {
        $dir = \dirname(__DIR__, 2) . '/storage/themes';
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry[0] === '.') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (!is_dir($path)) {
                continue;
            }
            $meta = [];
            if (is_file($path . '/theme.json')) {
                $meta = json_decode((string) file_get_contents($path . '/theme.json'), true) ?: [];
            }
            $out[] = [
                'slug'    => $entry,
                'name'    => $meta['name'] ?? ucfirst($entry),
                'version' => $meta['version'] ?? '0',
                'author'  => $meta['author'] ?? 'unknown',
            ];
        }
        usort($out, static fn ($a, $b) => strcmp($a['slug'], $b['slug']));
        return $out;
    }
}