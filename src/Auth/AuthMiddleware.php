<?php
namespace CMS\Auth;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Verifies the JWT and enforces role-based access.
 *
 * Attaches `$request` attributes:
 *   user_id, user_uuid, user_role, user_email
 *
 * TWO WAYS IN
 *
 * The credential may arrive as `Authorization: Bearer` (the original
 * contract, used by every API client) or as the HttpOnly session cookie
 * (SessionCookie). Both are verified through the same $this->jwt->verify()
 * call against the same signing key, so the cookie grants no identity the
 * header does not and vice versa — it only makes the credential reachable
 * from a plain browser navigation, which cannot set a header.
 *
 * The header wins when both are present. A browser that happens to carry a
 * stale cookie while JS holds a fresher token must not be locked out by the
 * cookie, and an explicit header is the caller stating its intent directly.
 */
class AuthMiddleware implements MiddlewareInterface
{
    private JwtService $jwt;
    private array $config;
    private ResponseFactoryInterface $responseFactory;

    /**
     * Where an unauthenticated BROWSER is sent.
     *
     * Null means "answer with JSON", which is right for XHR and wrong for a
     * page: a 401 body of {"error":"..."} rendered in a browser is a dead end
     * with no way forward. AdminRoutes passes '/login'; API routes leave it
     * null and keep the original behaviour.
     */
    private ?string $loginRedirect;

    public function __construct(
        JwtService $jwt,
        array $config,
        ResponseFactoryInterface $responseFactory,
        ?string $loginRedirect = null
    ) {
        $this->jwt = $jwt;
        $this->config = $config;
        // PSR-7 requests have no getResponseFactory(); the app must supply one.
        $this->responseFactory = $responseFactory;
        $this->loginRedirect = $loginRedirect;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $claims = $this->verifyRequest($request);

        if ($claims === null) {
            return $this->deny($request, $this->failureReason($request), 401);
        }

        $request = $request
            ->withAttribute('user_id', (int) ($claims['sub'] ?? 0))
            ->withAttribute('user_uuid', $claims['uuid'] ?? null)
            ->withAttribute('user_role', $claims['role'] ?? 'author')
            ->withAttribute('user_email', $claims['email'] ?? null);

        return $handler->handle($request);
    }

    /**
     * The verified claims, or null when no valid credential was presented.
     */
    private function verifyRequest(ServerRequestInterface $request): ?array
    {
        $header = $request->getHeaderLine('Authorization');

        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1) {
            // An explicit header is authoritative. If it is present but bad,
            // do NOT silently fall through to the cookie — that would let a
            // stale bearer header be masked by a valid cookie, hiding the fact
            // that the header itself is expired.
            return $this->jwt->verify($m[1]);
        }

        $cookie = SessionCookie::read($request, SessionCookie::NAME);

        return $cookie !== null && $cookie !== '' ? $this->jwt->verify($cookie) : null;
    }

    /**
     * A message for the JSON path, phrased to match what was actually missing.
     */
    private function failureReason(ServerRequestInterface $request): string
    {
        $header = $request->getHeaderLine('Authorization');

        if ($header !== '') {
            return 'Invalid or expired token';
        }

        return SessionCookie::read($request, SessionCookie::NAME) !== null
            ? 'Invalid or expired session'
            : 'Authentication required';
    }

    private function deny(ServerRequestInterface $request, string $message, int $status): ResponseInterface
    {
        // A page request gets sent to the login screen; a programmatic one gets
        // JSON it can act on. Same middleware, same 401 semantics, different
        // rendering of the failure.
        if ($this->loginRedirect !== null && $this->prefersHtml($request)) {
            $location = $this->loginRedirect;

            // Carry the original URL so login can bounce back to it. Only a
            // path is preserved — an attacker-supplied absolute URL here
            // would turn the login screen into an open redirect.
            $target = $request->getUri()->getPath();
            $query  = $request->getUri()->getQuery();
            if ($target !== '' && $target !== '/' && $this->isLocalPath($target)) {
                $location .= '?next=' . rawurlencode($target . ($query !== '' ? '?' . $query : ''));
            }

            return $this->responseFactory->createResponse(302)
                ->withHeader('Location', $location);
        }

        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write(json_encode([
            'error'   => $message,
            'status'  => $status,
        ], JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Would a browser be showing this response to a person?
     *
     * XHR and fetch() advertise themselves with X-Requested-With or a JSON
     * Accept; a top-level navigation does not. The Accept check comes second
     * because a browser tab can legitimately ask for text/html while an
     * XHR asks for application/json.
     */
    private function prefersHtml(ServerRequestInterface $request): bool
    {
        if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
            return false;
        }

        $accept = strtolower($request->getHeaderLine('Accept'));

        return $accept === '' || str_contains($accept, 'text/html');
    }

    /**
     * Is this a same-site path rather than an absolute URL?
     *
     * Guards the `next` parameter against `//evil.example` and
     * `https://evil.example`, both of which are legal in a Location header
     * and would both be honoured by a browser.
     */
    private function isLocalPath(string $path): bool
    {
        if (!str_starts_with($path, '/')) {
            return false;
        }

        // "//host" and "/\host" are protocol-relative URLs, not local paths.
        if (str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            return false;
        }

        return !str_contains(substr($path, 0, 2), ':');
    }
}