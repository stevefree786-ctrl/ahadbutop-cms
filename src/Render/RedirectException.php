<?php
declare(strict_types=1);

namespace CMS\Render;

/**
 * Signals that a path is not a page but a stored redirect to one.
 *
 * A sibling of NotFoundException rather than a return value, and distinct from
 * it, because the two lead to different responses: a miss renders the theme's
 * 404 with a 404 status, a redirect produces a 3xx and no body at all. Folding
 * them into one "not rendered" signal would force every handler to
 * re-discriminate, and the branch most likely to be got wrong is exactly the
 * one where a stale redirect silently turns into a 404 — reintroducing the
 * broken-link problem the redirect table exists to solve.
 */
final class RedirectException extends \RuntimeException
{
    public function __construct(
        public readonly string $target,
        public readonly int $status = 301,
        public readonly int $id = 0
    ) {
        parent::__construct('redirect');
    }
}