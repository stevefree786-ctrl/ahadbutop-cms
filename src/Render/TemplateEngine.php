<?php
namespace CMS\Render;

/**
 * Renders theme templates.
 *
 * Templates are PHP files. They're executed through an output buffer with
 * $vars extracted into the local scope and a $this-ish helper object bound
 * to them, so a template can call $this->e('...'), $this->url('...') and
 * $this->partial(...) without globals leaking.
 *
 * If the requested template is missing, render() falls back through a
 * chain (requested -> type -> 'default') and finally to a built-in minimal
 * page. A broken theme should degrade, not fatal — the site is often
 * being viewed precisely because someone is debugging it.
 */
class TemplateEngine
{
    private Theme $theme;

    public function __construct(Theme $theme)
    {
        $this->theme = $theme;
    }

    /**
     * Render a template name (without .php) wrapped in the layout.
     *
     * @param array $vars  exposed to the template and the layout
     * @param string $layout  'layout' to wrap, or '' for a bare fragment
     */
    public function render(string $template, array $vars = [], string $layout = 'layout'): string
    {
        $file = $this->resolve($template);
        $body = $file !== null ? $this->evaluate($file, $vars) : '';

        if ($layout === '') {
            return $body;
        }

        $layoutFile = $this->resolve($layout);
        if ($layoutFile === null) {
            // No layout: wrap in a minimal shell so the page is still valid.
            return "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>"
                 . $this->e((string) ($vars['title'] ?? $vars['site']['title'] ?? 'Untitled'))
                 . "</title></head><body>\n" . $body . "\n</body></html>";
        }

        // The layout gets the rendered view plus its own $content.
        return $this->evaluate($layoutFile, array_merge($vars, ['content' => $body]));
    }

    public function renderPartial(string $name, array $vars = []): string
    {
        $file = $this->pathFor('partials/' . $name);
        return $file === null ? '' : $this->evaluate($file, $vars);
    }

    /**
     * Find a template file, trying the bare name then the type then 'default'.
     */
    private function resolve(string $template): ?string
    {
        $template = preg_replace('/[^a-z0-9_\/-]/i', '', $template) ?? '';
        if ($template === '') {
            return null;
        }
        foreach ([$template, 'default'] as $candidate) {
            $file = $this->pathFor($candidate);
            if ($file !== null) {
                return $file;
            }
        }
        return null;
    }

    private function pathFor(string $relative): ?string
    {
        $path = $this->theme->path($relative . '.php');
        $real = realpath($path);
        $base = realpath($this->theme->path());
        // Defence in depth: even after sanitising the name, confirm the
        // resolved file really lives inside the theme directory.
        if ($real === false || $base === false || !str_starts_with($real, $base)) {
            return null;
        }
        return $real;
    }

    /**
     * Include a template with $vars in scope and an escaping helper.
     */
    private function evaluate(string $file, array $vars): string
    {
        $render = new self($this->theme);
        $vars['theme_name'] = $this->theme->name();

        ob_start();
        try {
            // NOT `static`: the closure is rebound to $render below so the
            // template can reach $this->e(). PHP 8 refuses to bind an object
            // to a static closure ("Cannot bind an instance to a static
            // closure"), which would leak a warning into the rendered body.
            (function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                require $__file;
            })->call($render, $file, $vars);
        } catch (\Throwable $e) {
            ob_end_clean();
            return '<!-- template error: ' . $this->e($e->getMessage()) . ' -->';
        }
        return (string) ob_get_clean();
    }

    // ---- helpers available inside templates as $this-> ... ---------------

    /** HTML-escape. Every dynamic value in a template goes through this. */
    public function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Attribute-escape is the same in HTML5; kept separate for intent. */
    public function attr(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Root-relative URL for a path, respecting an optional subdirectory install. */
    public function url(string $path = ''): string
    {
        $base = defined('BASE_PATH') ? rtrim((string) constant('BASE_PATH'), '/') : '';
        return $base . '/' . ltrim($path, '/');
    }

    public function partial(string $name, array $vars = []): string
    {
        return $this->renderPartial($name, $vars);
    }

    /** Format a stored datetime as YYYY-MM-DD. */
    public function date(?string $value, string $format = 'Y-m-d'): string
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000')) {
            return '';
        }
        $ts = strtotime($value);
        return $ts === false ? '' : date($format, $ts);
    }

    /** "3 days ago" style. */
    public function ago(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return '';
        }
        $diff = time() - $ts;
        if ($diff < 60)     return 'just now';
        if ($diff < 3600)   return intdiv($diff, 60) . 'm ago';
        if ($diff < 86400)  return intdiv($diff, 3600) . 'h ago';
        if ($diff < 2592000) return intdiv($diff, 86400) . 'd ago';
        if ($diff < 31536000) return intdiv($diff, 2592000) . 'mo ago';
        return intdiv($diff, 31536000) . 'y ago';
    }

    /** Render markdown inside a template: <?php echo $this->md($post['body_md']); ?> */
    public function md(?string $markdown): string
    {
        return Markdown::render((string) $markdown);
    }
}