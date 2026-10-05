<?php
namespace CMS\Auth;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Verifies the CSRF token on cookie-authenticated writes.
 *
 * THE GAP THIS CLOSES
 *
 * The codebase minted a CSRF token per render (AdminController::issueCsrf)
 * and admin.js has sent it in an X-CSRF-Token header since both were written.
 * Nothing compared the two. While auth was bearer-only that was survivable —
 * a token in an Authorization header cannot be attached by a third-party
 * page, so classic CSRF did not apply. Adding the session cookie
 * (SessionCookie, so /admin works in a browser rather than only via fetch)
 * inverted that: cookies ARE attached cross-site automatically, which is
 * exactly what CSRF tokens exist to prevent. Shipping the cookie without
 * this would have traded an unreachable admin for an exploitable one.
 *
 * WHY THE SIGNED TOKEN, NOT sessions.csrf_token
 *
 * Two CSRF tokens already existed by the time this was written: the signed
 * per-render one the layout embeds as window.CSRF, and sessions.csrf_token,
 * written on every login since AuthService was created and never read back.
 *
 * This verifies the SIGNED one and reads its subject to bind the check to the
 * session making the request — a token issued to user A must not authorise a
 * write as user B. That binding needs no server-side storage and expires
 * with the access token.
 *
 * The column stays unused rather than becoming a second accepted format. Two
 * live CSRF schemes is one more than any system should have: an attacker
 * targets the weaker one, and a future reader cannot tell which is
 * authoritative.
 *
 * WHY BEARER CALLERS ARE EXEMPT
 *
 * A credential in a header is not ambient — no cross-site form or image tag
 * can cause a browser to attach one — so a bearer-authenticated write is not
 * a CSRF target in the first place. Requiring the token of API clients would
 * break every existing integration for no security gain. Only requests
 * authenticated by COOKIE, which are exactly the ambient ones, are checked.
 */
class CsrfMiddleware implements MiddlewareInterface
{
    /** Methods that cannot change state and so cannot need CSRF protection. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    private ResponseFactoryInterface $responseFactory;
    private JwtService $jwt;

    public function __construct(ResponseFactoryInterface $responseFactory, JwtService $jwt)
    {
        $this->responseFactory = $responseFactory;
        $this->jwt = $jwt;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        // Bearer-authenticated: not ambient, not a CSRF target. See the class
        // docblock — this is a deliberate exemption, not a skipped check.
        if ($this->hasBearer($request)) {
            return $handler->handle($request);
        }

        $sent = $request->getHeaderLine('X-CSRF-Token');
        if ($sent === '') {
            return $this->forbid($request, 'CSRF token missing');
        }

        $claims = $this->jwt->verify($sent);
        if ($claims === null || !isset($claims['csrf'])) {
            return $this->forbid($request, 'CSRF token invalid or expired');
        }

        // Bind the token to the session presenting it. Without this, a valid
        // token harvested from one user would authorise a write as another:
        // it would prove "some rendered page said so", not "THIS user's page
        // said so".
        $tokenUser = (int) ($claims['sub'] ?? 0);
        $actor     = (int) $request->getAttribute('user_id', 0);

        if ($tokenUser === 0 || $actor === 0 || $tokenUser !== $actor) {
            return $this->forbid($request, 'CSRF token does not belong to this session');
        }

        return $handler->handle($request);
    }

    private function hasBearer(ServerRequestInterface $request): bool
    {
        return preg_match('/^Bearer\s+\S+$/i', $request->getHeaderLine('Authorization')) === 1;
    }

    /**
     * 419 — "session expired, reload for a fresh token" — rather than 403, so
     * the client can tell a permission problem from a stale-token one and act
     * on each differently.
     */
    private function forbid(ServerRequestInterface $request, string $message): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(419);
        $response->getBody()->write(json_encode([
            'error'  => $message,
            'status' => 419,
        ], JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json');
    }
}