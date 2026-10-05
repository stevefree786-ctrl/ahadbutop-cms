<?php
namespace CMS\Auth;

/**
 * The browser half of authentication.
 *
 * WHY THIS EXISTS
 *
 * The admin UI was reachable only by a caller that could set an
 * `Authorization: Bearer` header. A browser navigation cannot: you cannot make
 * Chrome attach a bearer header to a GET /admin. The token lives in
 * localStorage and admin.js attaches it to fetch() calls, so every
 * server-rendered screen — /admin, /admin/posts, the whole nav — returned a
 * JSON 401 to an actual browser. The admin was effectively API-only.
 *
 * This closes that gap by also accepting the SAME access token from an
 * HttpOnly cookie. The bearer path is untouched, so every existing API client
 * keeps working; a cookie simply becomes a second way to present the same
 * credential for the same verification.
 *
 * WHY THE FLAGS ARE WHAT THEY ARE
 *
 * HttpOnly — JavaScript cannot read it, so an XSS bug cannot exfiltrate the
 *   session the way it could read localStorage. The token is still handed to
 *   JS on login (that is the existing contract), so this is defence in depth
 *   rather than a cure, but it is strictly better than one more readable
 *   storage slot.
 *
 * SameSite=Lax — the cookie rides ordinary top-level navigation (a GET to
 *   /admin after login) but NOT a cross-site POST, which is the CSRF shape
 *   this system would be vulnerable to. Strict would break the login
 *   redirect itself, because the first request after a form post is
 *   cross-site.
 *
 * Secure — set whenever the request arrived over TLS, including behind a
 *   Cloudflare proxy. Set unconditionally when the app is configured for
 *   production HTTPS, because otherwise a misconfigured proxy silently
 *   downgrades the cookie to plaintext over the wire.
 *
 * Path=/ — the cookie must reach /admin/* and /api/v1/*, which are different
 *   subtrees; scoping it to one of them would break the other.
 */
final class SessionCookie
{
    /** Short and greppable so it is obvious in DevTools what it is. */
    public const NAME = 'cms_session';

    /**
     * Separately-named cookie for the browser-readable refresh token.
     *
     * Kept out of the HttpOnly cookie on purpose: admin.js must be able to
     * read it to call /api/v1/auth/refresh when the short-lived access token
     * expires mid-session. A refresh token that JS cannot read would mean
     * re-prompting for a password on every token expiry.
     */
    public const REFRESH_NAME = 'cms_refresh';

    /**
     * Cross-site request forgery token, mirrored into a readable cookie.
     *
     * This is the value AuthService already generates per session (the
     * `csrf_token` column). It is NOT the credential — the HttpOnly cookie
     * is — it is the proof that a state-changing request came from a page
     * that could read our cookies. An attacker's page cannot read it, so it
     * cannot put the right value in the header.
     */
    public const CSRF_NAME = 'cms_csrf';

    private function __construct()
    {
    }

    /**
     * Is this request safe to set a Secure cookie on?
     *
     * Honours X-Forwarded-Proto because the site is expected to sit behind
     * Cloudflare, where the origin connection is HTTP even though the browser
     * spoke HTTPS. Trusting the header unconditionally is only safe because
     * the deployment puts a proxy that sets it — see note in
     * SessionCookieTest for the residual risk.
     */
    public static function isSecureRequest(\Psr\Http\Message\ServerRequestInterface $request): bool
    {
        if ($request->getUri()->getScheme() === 'https') {
            return true;
        }

        $forwarded = $request->getHeaderLine('X-Forwarded-Proto');

        return strtolower(trim(explode(',', $forwarded)[0])) === 'https';
    }

    /**
     * Build the Set-Cookie header value.
     *
     * Returns a header string rather than mutating a response so callers can
     * compose several cookies onto one response (login sets three).
     */
    public static function build(
        string $name,
        string $value,
        int $maxAge,
        bool $secure,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): string {
        $parts = [
            rawurlencode($name) . '=' . rawurlencode($value),
            'Path=/',
            'Max-Age=' . max(0, $maxAge),
            'SameSite=' . $sameSite,
        ];

        if ($secure) {
            $parts[] = 'Secure';
        }
        // HttpOnly is omitted rather than sent as "HttpOnly=0" for readable
        // cookies: the attribute is a flag, and emitting it on the refresh
        // token would defeat the reason it is readable.
        if ($httpOnly) {
            $parts[] = 'HttpOnly';
        }

        return implode('; ', $parts);
    }

    /**
     * Extract a cookie value from the raw header, percent-decoded.
     *
     * Returns null when absent. Deliberately does not parse $_COOKIE: PSR-7
     * requests are not guaranteed to be backed by superglobals, and tests
     * construct requests by hand.
     */
    public static function read(\Psr\Http\Message\ServerRequestInterface $request, string $name): ?string
    {
        $header = $request->getHeaderLine('Cookie');
        if ($header === '') {
            return null;
        }

        $target = rawurlencode($name);

        foreach (explode(';', $header) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $pair, 2);
            if (trim($key) === $target) {
                return rawurldecode(trim($value));
            }
        }

        return null;
    }

    /**
     * An expired cookie that instructs the browser to delete what it holds.
     *
     * Used by logout. The attributes must otherwise MATCH the cookie being
     * cleared — a different Path or SameSite is a different cookie as far as
     * the browser is concerned, and it would sit there untouched.
     */
    public static function expired(string $name, bool $secure, bool $httpOnly = true): string
    {
        return self::build($name, '', 0, $secure, $httpOnly);
    }
}