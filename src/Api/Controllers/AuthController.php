<?php
namespace CMS\Api\Controllers;

use CMS\Api\Responder;
use CMS\Auth\AuthService;
use CMS\Auth\JwtService;
use CMS\Auth\SessionCookie;
use CMS\Database\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Auth endpoints: login, refresh, logout, me.
 *
 * Tokens: a short-lived HS256 access token carries identity claims;
 * an opaque refresh token is stored hashed in `sessions` and rotates on
 * every use so a stolen refresh token is single-use.
 */
class AuthController
{
    private AuthService $service;

    public function __construct(Connection $db, JwtService $jwt, array $authConfig)
    {
        $this->service = new AuthService($db, $jwt, $authConfig);
    }

    /** POST /api/v1/auth/login */
    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];

        $email    = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($email === '' || $password === '') {
            return Responder::error($response, 400, 'email and password are required');
        }

        $result = $this->service->login(
            $email,
            $password,
            $this->clientIp($request),
            $request->getHeaderLine('User-Agent') ?: null
        );

        if (isset($result['error'])) {
            // 401 for bad credentials; 423 while locked out.
            $status = str_contains($result['error'], 'locked') ? 423 : 401;
            return Responder::error($response, $status, $result['error']);
        }

        return $this->withSessionCookies(Responder::ok($response, $result), $request, $result);
    }

    /** POST /api/v1/auth/refresh */
    public function refresh(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body  = $request->getParsedBody() ?? [];
        $token = (string) ($body['refresh_token'] ?? $this->bearer($request));

        if ($token === '') {
            return Responder::error($response, 400, 'refresh_token is required');
        }

        $result = $this->service->refresh($token);

        if (isset($result['error'])) {
            return Responder::error($response, 401, $result['error']);
        }

        // Refresh rotates the session, so the cookies must rotate with it —
        // leaving the old access cookie in place would serve a token that is
        // no longer the live session.
        return $this->withSessionCookies(Responder::ok($response, $result), $request, $result);
    }

    /** POST /api/v1/auth/logout */
    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body  = $request->getParsedBody() ?? [];
        $token = (string) ($body['refresh_token'] ?? $this->bearer($request));

        if ($token !== '') {
            $this->service->logout($token);
        }

        // Always 200: telling the caller whether the token existed would
        // let an attacker probe for valid tokens.
        $out = Responder::ok($response, ['logged_out' => true]);

        // Clear the browser session too. The attributes must match the ones
        // used to SET the cookie or the browser treats it as a different
        // cookie and keeps the old one.
        $secure = SessionCookie::isSecureRequest($request);
        foreach ([SessionCookie::NAME, SessionCookie::REFRESH_NAME] as $name) {
            $out = $out->withAddedHeader('Set-Cookie', SessionCookie::expired($name, $secure, $name !== SessionCookie::REFRESH_NAME));
        }

        return $out->withAddedHeader('Set-Cookie', SessionCookie::expired(SessionCookie::CSRF_NAME, $secure, false));
    }

    /**
     * Attach the three cookies a browser session needs.
     *
     * cms_session   HttpOnly  — the credential. Unreadable by JS, which is why
     *                        /admin now works without localStorage.
     * cms_refresh   readable  — admin.js needs it to refresh the access token
     *                        when it expires mid-session.
     * cms_csrf      readable  — the session's csrf_token, echoed back in
     *                        X-CSRF-Token by CsrfMiddleware.
     *
     * The Max-Age of the session cookie tracks the JWT's own TTL rather than
     * the longer refresh TTL: the cookie carries the ACCESS token, and giving
     * it the refresh lifetime would leave a dead token sitting in the browser
     * for hours after it stopped being valid.
     */
    private function withSessionCookies(ResponseInterface $response, ServerRequestInterface $request, array $result): ResponseInterface
    {
        $secure  = SessionCookie::isSecureRequest($request);
        $accessTtl = (int) ($result['expires_in'] ?? 900);
        // The refresh cookie must outlive the access one or the session dies
        // at the moment it would be most useful to keep it.
        $refreshTtl = $accessTtl * 4;

        $response = $response
            ->withAddedHeader('Set-Cookie', SessionCookie::build(
                SessionCookie::NAME, (string) ($result['access_token'] ?? ''), $accessTtl, $secure
            ))
            ->withAddedHeader('Set-Cookie', SessionCookie::build(
                SessionCookie::REFRESH_NAME, (string) ($result['refresh_token'] ?? ''), $refreshTtl, $secure, false
            ));

        if (isset($result['csrf_token'])) {
            $response = $response->withAddedHeader('Set-Cookie', SessionCookie::build(
                SessionCookie::CSRF_NAME, (string) $result['csrf_token'], $refreshTtl, $secure, false
            ));
        }

        return $response;
    }

    /** POST /api/v1/auth/logout-all */
    public function logoutAll(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = (int) $request->getAttribute('user_id', 0);
        return Responder::ok($response, ['revoked' => $this->service->logoutAll($userId)]);
    }

    /** GET /api/v1/auth/me — proves the token is wired through middleware. */
    public function me(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Responder::ok($response, [
            'user' => [
                'id'    => (int) $request->getAttribute('user_id', 0),
                'uuid'  => $request->getAttribute('user_uuid'),
                'email' => $request->getAttribute('user_email'),
                'role'  => $request->getAttribute('user_role'),
            ],
        ]);
    }

    private function bearer(ServerRequestInterface $request): string
    {
        return preg_match('/^Bearer\s+(\S+)$/i', $request->getHeaderLine('Authorization'), $m)
            ? $m[1]
            : '';
    }

    /** First hop of X-Forwarded-For when behind a proxy, else the socket IP. */
    private function clientIp(ServerRequestInterface $request): ?string
    {
        $server = $request->getServerParams();
        $fwd    = $request->getHeaderLine('X-Forwarded-For');
        if ($fwd !== '') {
            $first = trim(explode(',', $fwd)[0]);
            if ($first !== '') {
                return $first;
            }
        }
        return $server['REMOTE_ADDR'] ?? null;
    }
}