<?php
declare(strict_types=1);

namespace CMS\Render;

/**
 * Signals that a request path matched no route, or matched a route whose
 * resource does not exist / is not publicly visible.
 *
 * A dedicated type rather than a bare `return null` because "no such page"
 * and "render this page" are both successful outcomes of a lookup — a caller
 * that forgets to catch the miss would otherwise silently render an empty
 * 200 page, which is exactly the bug that leaks drafts. Throwing makes the
 * miss impossible to ignore, and handle() converts it to the theme's 404
 * with the correct HTTP status.
 */
final class NotFoundException extends \RuntimeException
{
    public function __construct(string $code = 'not_found')
    {
        parent::__construct($code);
    }
}