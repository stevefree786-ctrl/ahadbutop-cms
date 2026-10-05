<?php
declare(strict_types=1);

namespace CMS\Agents;

/**
 * SSRF-hardened HTTP client for fetching attacker-controlled URLs.
 *
 * WHY THIS CLASS EXISTS
 * ---------------------
 * Every URL this CMS fetches on an agent's behalf originated somewhere the
 * agent does not control: a model's suggestion, a user typing a task, a
 * `Location:` header chosen by whatever remote server we happen to talk to.
 * All three are attacker-controlled input. Handed straight to cURL, a single
 * URL turns this host into a proxy for the private network it sits in —
 * the classic SSRF pivot used to read cloud instance credentials from
 * http://169.254.169.254/, port-scan the LAN, or reach an internal admin
 * panel that trusts anything arriving from localhost.
 *
 * The defences below are deliberately layered, because each one alone is
 * bypassable:
 *
 *   1. SCHEME ALLOWLIST. Only http/https. `file://`, `gopher://`, `dict://`,
 *      `php://` and friends are not "untrusted web pages", they are local
 *      capability escalation — gopher in particular can drive arbitrary TCP
 *      payloads from a single GET.
 *   2. DNS RESOLUTION + IP CLASSIFICATION. Resolve the hostname ourselves and
 *      reject the request if ANY answer points into private, reserved,
 *      loopback, link-local or cloud-metadata space. Checking only the first
 *      answer is not enough: DNS returns several records routinely, and an
 *      attacker who controls the zone controls the whole set.
 *   3. DNS REBINDING (the part that is easy to forget). Resolving once and
 *      then letting cURL resolve again opens a window: the answer we vetted
 *      and the address we connect to can differ, because the attacker answers
 *      the first query with a public IP and the second with 127.0.0.1 (a TTL
 *      of 0 makes this trivial). CURLOPT_RESOLVE pins the hostname to the
 *      exact IP we validated, so there is no second lookup to poison.
 *   4. MANUAL REDIRECTS. CURLOPT_FOLLOWLOCATION is never enabled. A redirect
 *      target is a fresh, unvalidated URL, so each hop is re-parsed, its
 *      scheme re-checked, and its address re-resolved and re-classified
 *      before we connect. Hop count is capped.
 *
 * Everything here is deliberately allocation- and side-effect-light: this
 * class is used on request paths (an agent importing a hero image), not from
 * a batch job.
 */
final class SafeHttpClient
{
    /** Response shape shared by get() and head(). */
    public const ERROR_SCHEME       = 'scheme_not_allowed';
    public const ERROR_URL_MALFORMED = 'malformed_url';
    public const ERROR_NO_HOST      = 'url_has_no_host';
    public const ERROR_CREDENTIALS  = 'url_credentials_not_allowed';
    public const ERROR_PORT         = 'port_not_allowed';
    public const ERROR_RESOLVE      = 'dns_resolution_failed';
    public const ERROR_BLOCKED_IP   = 'address_blocked';
    public const ERROR_TIMEOUT      = 'timeout';
    public const ERROR_TOO_LARGE    = 'response_too_large';
    public const ERROR_HTTP         = 'http_error';
    public const ERROR_REDIRECT     = 'too_many_redirects';
    public const ERROR_TRANSPORT    = 'transport_error';

    /** Hard ceiling on a single response body: 25MB, matching MediaRepository. */
    public const MAX_BYTES = 25 * 1024 * 1024;

    /** Seconds to wait for the TCP/TLS handshake. */
    public const CONNECT_TIMEOUT = 5;

    /** Seconds for the whole request, including the body read. */
    public const TOTAL_TIMEOUT = 15;

    /** Redirect hops before we give up. */
    public const MAX_REDIRECTS = 3;

    /**
     * A browser-ish UA. Some CDNs 403 a default curl/… token outright, which
     * would make the fetcher useless rather than safer, and a UA is also a
     * small honesty signal to the remote operator about who is calling.
     */
    private const USER_AGENT = 'Mozilla/5.0 (compatible; HermesCMS-MediaAgent/1.0; +https://hermes-cms.local/bot)';

    /**
     * IPv4 ranges that must never be reached through this client.
     *
     * Written as [network, mask] pairs compared with bitwise AND rather than
     * as FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE: the filter flags do not
     * cover 100.64.0.0/10 (CGNAT), and they are not the mechanism anyone
     * auditing this file will be looking for. Explicit lists are auditable;
     * a flag bit is not.
     */
    private const IPV4_BLOCKED = [
        ['0.0.0.0', 8],          // "this network" — routes nowhere useful
        ['10.0.0.0', 8],         // RFC1918
        ['100.64.0.0', 10],      // RFC6598 CGNAT — carrier NAT, not the internet
        ['127.0.0.0', 8],        // loopback, incl. 127.0.0.1
        ['169.254.0.0', 16],     // link-local, incl. 169.254.169.254 IMDS
        ['172.16.0.0', 12],      // RFC1918
        ['192.0.0.0', 24],       // IETF protocol assignments
        ['192.0.2.0', 24],       // TEST-NET-1
        ['192.168.0.0', 16],     // RFC1918
        ['198.18.0.0', 15],      // benchmarking
        ['198.51.100.0', 24],    // TEST-NET-2
        ['203.0.113.0', 24],     // TEST-NET-3
        ['224.0.0.0', 4],        // multicast
        ['240.0.0.0', 4],        // reserved, incl. 255.255.255.255
    ];

    /**
     * IPv6 ranges that must never be reached.
     *
     * fc00::/7 covers fc00::/8 AND fd00::/8 (unique-local); fe80::/10 is
     * link-local, whose ::ffff:169.254.169.254 form is the IPv6 spelling of
     * the cloud metadata endpoint. ::1 is loopback.
     */
    private const IPV6_BLOCKED = [
        ['::', 128],              // unspecified
        ['::1', 128],             // loopback
        ['fc00::', 7],            // unique-local (fc.. and fd..)
        ['fe80::', 10],           // link-local
        ['ff00::', 8],            // multicast
        ['2001:db8::', 32],       // documentation
    ];

    /** @var array{scheme:string,host:string,port:int,path:string,query:string}|null */
    private ?array $lastRequest = null;

    /**
     * GET a URL, following redirects manually and re-validating every hop.
     *
     * @param string $url  Caller-supplied URL. Untrusted by definition.
     * @param array  $opts timeout|connect_timeout|max_bytes|headers|max_redirects
     *                     Pass max_redirects => 0 to refuse to follow at all.
     *
     * @return array{ok:bool,status:int,body:string,mime:string,final_url:string,error:?string}
     */
    public function get(string $url, array $opts = []): array
    {
        return $this->request('GET', $url, ['max_redirects' => self::MAX_REDIRECTS] + $opts);
    }

    /**
     * HEAD a URL under the same rules.
     *
     * Useful for probing a candidate image (does it exist, what does the
     * server claim it is) without paying for the body. Note the returned
     * `mime` is still the server's claim — never trust it as a type.
     */
    public function head(string $url, array $opts = []): array
    {
        // max_bytes=0 means "headers only" to the write callback; a HEAD
        // request must not accumulate a body either way.
        return $this->request('HEAD', $url, ['max_bytes' => 0, 'max_redirects' => self::MAX_REDIRECTS] + $opts);
    }

    /**
     * The validated target of the last successful hop, or null.
     * Exposed for logging/tests; never use it to build SQL.
     */
    public function lastRequest(): ?array
    {
        return $this->lastRequest;
    }

    // ------------------------------------------------------------------
    // Request pipeline
    // ------------------------------------------------------------------

    /**
     * One hop of the pipeline, looped by redirect handling.
     *
     * @return array{ok:bool,status:int,body:string,mime:string,final_url:string,error:?string}
     */
    private function request(string $method, string $url, array $opts): array
    {
        $fail = static fn(string $error, int $status = 0, string $body = '', string $mime = ''): array => [
            'ok'        => false,
            'status'    => $status,
            'body'      => $body,
            'mime'      => $mime,
            'final_url' => $url,
            'error'     => $error,
        ];

        // ---- scheme check FIRST --------------------------------------
        //
        // Before parsing, because parse_url() is scheme-agnostic and will
        // happily explain "file:///etc/passwd" as scheme=file host=(none).
        // Reporting that as a malformed URL would be true but useless; the
        // accurate rejection is "that scheme is not allowed". The scheme is
        // everything up to the first ':', which is also the only part of the
        // string we trust enough to look at pre-parse.
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', trim($url), $m) !== 1) {
            return $fail(self::ERROR_URL_MALFORMED);
        }
        $rawScheme = strtolower($m[1]);
        if ($rawScheme !== 'http' && $rawScheme !== 'https') {
            // Rejected on sight rather than being "cleaned up" into a
            // fetchable one. gopher:// is the sharpest case: it can drive
            // arbitrary TCP payloads from a single GET, which is how an image
            // importer becomes an SMTP or Redis client.
            return $fail(self::ERROR_SCHEME);
        }

        // ---- parse ----------------------------------------------------
        $parsed = $this->parseUrl($url);
        if ($parsed === null) {
            return $fail(self::ERROR_URL_MALFORMED);
        }

        // Already proven http/https above; re-reading it from the parse keeps
        // the two checks independent rather than trusting one another's result.
        $scheme = $rawScheme;

        // Credentials in a URL are a phishing/credential-leak trick
        // (https://trusted.example@evil.example/) and a parser-confusion
        // trick. A URL this client receives has no legitimate reason to
        // carry them.
        if ($parsed['user'] !== null || $parsed['password'] !== null) {
            return $fail(self::ERROR_CREDENTIALS);
        }

        // Only the default HTTP/HTTPS ports. An attacker-controlled port is
        // how "fetch http://internal:6379/" turns an image importer into a
        // Redis/Postgres client.
        $port = $parsed['port'];
        if ($port !== null && !in_array($port, [80, 443], true)) {
            return $fail(self::ERROR_PORT);
        }

        $port = $port ?? ($scheme === 'https' ? 443 : 80);

        // ---- DNS: resolve, classify, pin -------------------------------
        $addresses = $this->resolve($parsed['host']);
        if ($addresses === []) {
            return $fail(self::ERROR_RESOLVE);
        }
        foreach ($addresses as $ip) {
            if (!self::isPublicIp($ip)) {
                // ANY answer being private fails the request. An attacker who
                // controls the zone controls all the records, so "the first
                // answer was fine" is not a defence.
                return $fail(self::ERROR_BLOCKED_IP);
            }
        }

        // ---- curl -------------------------------------------------------
        $ch = curl_init();
        if ($ch === false) {
            return $fail(self::ERROR_TRANSPORT);
        }

        $headers = [
            'Accept: image/*,*/*;q=0.8',
            'Accept-Encoding: gzip, deflate',
        ];
        foreach ((array) ($opts['headers'] ?? []) as $name => $value) {
            // Only well-formed header lines pass; a value with CRLF in it
            // would inject a second request header.
            if (is_string($name) && is_scalar($value) && preg_match('/^[A-Za-z0-9-]+$/', $name)) {
                $headers[] = $name . ': ' . str_replace(["\r", "\n"], '', (string) $value);
            }
        }

        $resolve = [];
        foreach ($addresses as $ip) {
            // CURLOPT_RESOLVE is the rebinding pin: libcurl will use this
            // literal address for this hostname instead of asking the resolver
            // again. Without it, everything above is a TOCTOU on a socket we
            // do not control the timing of.
            $resolve[] = $parsed['host'] . ':' . $port . ':' . self::bracketIfIpv6($ip);
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $authority   = self::hostForUrl($parsed['host']) . ($port === $defaultPort ? '' : ':' . $port);
        $target      = $scheme . '://' . $authority . $parsed['path'] . $parsed['query'];

        $maxBytes = (int) ($opts['max_bytes'] ?? self::MAX_BYTES);
        $overflow = false;
        $buffer   = '';
        $responseHeaders = [];

        curl_setopt_array($ch, [
            CURLOPT_URL            => $target,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,   // ALWAYS. See the class docblock.
            CURLOPT_MAXREDIRS      => 0,        // Belt and braces.
            CURLOPT_HEADER         => false,
            CURLOPT_CONNECTTIMEOUT => (int) ($opts['connect_timeout'] ?? self::CONNECT_TIMEOUT),
            CURLOPT_TIMEOUT        => (int) ($opts['timeout'] ?? self::TOTAL_TIMEOUT),
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RESOLVE        => $resolve,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ACCEPT_ENCODING=> '',
        ]);

        // Certificate verification stays ON (see CURLOPT_SSL_VERIFYPEER
        // above). What varies between machines is whether the TLS backend has
        // a CA bundle to verify AGAINST, and PHP's OpenSSL build has no default
        // on Windows — every https:// fetch fails with curl 60,
        // "unable to get local issuer certificate", even though the CLI's curl
        // works fine (it uses Schannel and the Windows certificate store).
        // That is an environment gap, not a security downgrade, so point
        // OpenSSL at a bundle when we can find one. Turning verification OFF
        // would be the alternative and is exactly what this class must not do.
        $ca = CaBundle::path();
        if ($ca !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }

        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }

        /*
         * Collect RESPONSE headers separately from the body.
         *
         * Redirect handling must read Location from the headers. The previous
         * version scanned the body for a "Location:" line, which is wrong in
         * two ways: a 301/302 body is usually HTML, and an HTML page is free
         * to contain the text "Location:" anywhere — so a server could steer a
         * redirect by putting that string in its body, to a target the header
         * never authorised. Header and body are different trust domains here
         * and only the header may drive a redirect.
         *
         * Headers are capped because a hostile origin can stream header lines
         * indefinitely ("X-Pad: aaaa...") to exhaust memory before the body
         * limit is ever reached.
         */
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($_ch, string $line) use (&$responseHeaders): int {
            $trimmed = trim($line);

            // A blank line ends the header block. Any header lines after it
            // belong to a redirect's own body (e.g. a 1xx informational
            // response) and must not be treated as this response's headers.
            if ($trimmed === '') {
                return strlen($line);
            }

            if (count($responseHeaders) < 64 && strlen($trimmed) < 8192) {
                $responseHeaders[] = $trimmed;
            }
            return strlen($line);
        });

        /*
         * Stream into a bounded buffer instead of letting PHP materialise the
         * whole response. A hostile (or merely broken) origin can declare a
         * 4GB body; without this cap the process dies on memory long before
         * curl's own timeout fires. The callback returns a short count once
         * the cap is hit, which aborts the transfer.
         *
         * CURLOPT_RETURNTRANSFER is deliberately OFF: when a WRITEFUNCTION is
         * set, curl_exec() returns TRUE and the body lands in $buffer here.
         * Leaving RETURNTRANSFER on makes curl_exec() return `true`, which
         * reads as a valid non-empty body of the literal text "1".
         */
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($_ch, string $chunk) use (&$buffer, &$overflow, $maxBytes): int {
            // HEAD and max_bytes=0 mean "headers only" — consume the data
            // without accumulating it.
            if ($maxBytes <= 0) {
                return strlen($chunk);
            }
            if ($overflow) {
                return 0;
            }
            $room = $maxBytes - strlen($buffer);
            if ($room <= 0) {
                $overflow = true;
                return 0;
            }
            if (strlen($chunk) >= $room) {
                $buffer .= substr($chunk, 0, $room);
                $overflow = true;
                return 0;
            }
            $buffer .= $chunk;
            return strlen($chunk);
        });

        $ok     = curl_exec($ch);
        $errno  = curl_errno($ch);
        // Read the message BEFORE curl_close(): curl_error() needs the live
        // handle, and curl_error_str() (nicer, but ext-curl >= 8.2 only) is
        // not something a shared codebase can assume exists.
        $detail = $errno === 0 ? '' : (string) curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
        curl_close($ch);

        $body = $buffer;

        if ($ok === false) {
            if ($overflow) {
                return $fail(self::ERROR_TOO_LARGE);
            }

            /*
             * A bare 'transport_error' is unactionable at 3am. cURL knows
             * precisely why it gave up (DNS, TLS, refused connection,
             * protocol mismatch) and errno 60 alone will not tell you which.
             * Appending it costs one concat and turns the most common
             * operational failure into a self-describing log line.
             *
             * The error CODE stays stable — callers switch on it — so only
             * the human-readable half carries the extra detail.
             */
            $code    = $errno === CURLE_OPERATION_TIMEDOUT ? self::ERROR_TIMEOUT : self::ERROR_TRANSPORT;
            $result  = $fail($code);
            if ($detail !== '') {
                $result['error'] = $code . ': ' . $detail . ' (curl ' . $errno . ')';
            }
            return $result;
        }

        // ---- redirect handling -----------------------------------------
        //
        // Redirects are followed ONLY when the caller opts in via
        // max_redirects, which defaults to 0. get()/head() therefore do not
        // follow anything: they stop at the 3xx and report the Location so
        // the CALLER can decide. Two reasons for that default:
        //
        //   - a redirect is a SECOND, entirely unvalidated URL, and letting
        //     it happen implicitly means a caller cannot see or constrain it;
        //   - it keeps the client safe by default even for a caller who has
        //     not thought about redirects at all.
        //
        // When the caller DOES opt in, handleRedirect() recurses into
        // request(), which re-runs the entire check on the new target —
        // scheme, credentials, port, DNS classification, the CURLOPT_RESOLVE
        // pin. A 302 to http://169.254.169.254/ or file:///etc/passwd is
        // refused before a connection is attempted.
        if ($status >= 300 && $status < 400) {
            $maxRedirects = (int) ($opts['max_redirects'] ?? 0);
            $hops         = (int) ($opts['_hops'] ?? 0);

            if ($maxRedirects <= 0 || $hops >= $maxRedirects) {
                return [
                    'ok'        => false,
                    'status'    => $status,
                    'body'      => '',
                    'mime'      => '',
                    'final_url' => $url,
                    // Not an error condition: the caller is told the URL moved
                    // and where to, so it can follow deliberately if it wants.
                    'error'     => null,
                    'redirect'  => $this->headerValue($responseHeaders, 'location'),
                ];
            }

            return $this->handleRedirect($method, $status, $responseHeaders, $url, $opts + ['_hops' => $hops + 1]);
        }

        if ($status < 200 || $status >= 300) {
            return $fail(self::ERROR_HTTP, $status, $body, $ctype);
        }

        $this->lastRequest = [
            'scheme' => $scheme,
            'host'   => $parsed['host'],
            'port'   => $port,
            'path'   => $parsed['path'],
            'query'  => $parsed['query'],
        ];

        return [
            'ok'        => true,
            'status'    => $status,
            'body'      => (string) $body,
            // Server-advertised type. ADVISORY ONLY — callers MUST re-derive
            // the real type from the bytes (see MediaAgent's finfo_buffer).
            'mime'      => self::mimeFromHeader($ctype),
            'final_url' => $url,
            'error'     => null,
        ];
    }

    /**
     * Follow a 3xx by RECURSING into request(), not by asking cURL to do it.
     *
     * This is the load-bearing comment for the whole class: the redirect
     * target is a brand-new untrusted URL, so it gets the identical scheme
     * check, DNS classification and CURLOPT_RESOLVE pin as the original.
     * A 302 to http://169.254.169.254/ is stopped here.
     *
     * @param string[] $responseHeaders Raw header lines from the 3xx response.
     */
    private function handleRedirect(string $method, int $status, array $responseHeaders, string $url, array $opts): array
    {
        $location = $this->headerValue($responseHeaders, 'location');

        // A 3xx with no usable Location cannot be followed safely, and
        // guessing one is how an open redirector becomes an SSRF gadget. The
        // hop cap itself is enforced by the caller in request().
        if ($location === '') {
            return [
                'ok' => false, 'status' => $status, 'body' => '', 'mime' => '',
                'final_url' => $url, 'error' => self::ERROR_REDIRECT,
            ];
        }

        // Relative targets are resolved against the current URL. A protocol-
        // relative "//host/path" keeps the current scheme, which the recursive
        // call re-validates anyway.
        $next = $this->resolveReference($url, $location);

        return $this->request($method, $next, $opts);
    }

    /** Case-insensitive single header lookup over raw "Name: value" lines. */
    private function headerValue(array $lines, string $name): string
    {
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            if (strcasecmp(substr($line, 0, $colon), $name) === 0) {
                return trim(substr($line, $colon + 1), " \t\"'");
            }
        }
        return '';
    }

    // ------------------------------------------------------------------
    // URL parsing / DNS
    // ------------------------------------------------------------------

    /**
     * @return array{scheme:string,host:string,port:?int,path:string,query:string,user:?string,password:?string}|null
     */
    private function parseUrl(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            return null;
        }

        // Control characters (including NUL and embedded newlines) inside a
        // URL are a parser-confusion primitive: some consumers strip them and
        // others don't, so "http://ok.example/\n@evil.example" can be read two
        // different ways. Refuse rather than normalise.
        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $host = strtolower(trim($parts['host']));
        /*
         * An IPv6 literal arrives bracketed ("[::1]"). Strip the brackets so
         * the value reaches resolve() and isPublicIp() as a bare address.
         * Leaving them on would fail both: inet_pton("[::1]") is false, and a
         * bracket-prefixed host fails the hostname regex below. That failure
         * is silent — it surfaces as "dns_resolution_failed" rather than as
         * the "address_blocked" it actually is, so a caller auditing the logs
         * would read an allowlist gap that is really a blocked address.
         */
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        // A trailing dot is legal DNS but confuses string equality checks
        // elsewhere; normalise it away before any comparison. (Not applied to
        // IPv6 literals — "::1." is not an address.)
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            $host = rtrim($host, '.');
        }
        if ($host === '') {
            return null;
        }

        return [
            'scheme'   => strtolower((string) $parts['scheme']),
            'host'     => $host,
            'port'     => isset($parts['port']) ? (int) $parts['port'] : null,
            'path'     => $parts['path'] ?? '/',
            'query'    => isset($parts['query']) ? '?' . $parts['query'] : '',
            'user'     => isset($parts['user']) ? rawurldecode((string) $parts['user']) : null,
            'password' => isset($parts['password']) ? rawurldecode((string) $parts['password']) : null,
        ];
    }

    /**
     * Resolve a hostname to every address it answers with.
     *
     * A literal IP in the URL is returned as-is (no DNS needed, and an IP
     * literal cannot be rebound). A name goes through dns_get_record for
     * A/AAAA, with gethostbyname() as a fallback for hosts the platform
     * resolver handles but dns_get_record does not report (notably /etc/hosts
     * aliases on some builds, where returning [] would silently mean "no
     * addresses" rather than "blocked").
     *
     * @return string[] plain IP strings, IPv6 unbracketed
     */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // A name that is not a plausible hostname never reaches the resolver.
        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/i', $host) !== 1) {
            return [];
        }

        $ips = [];

        $records = @dns_get_record($host, DNS_A);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }
            }
        }

        $records6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($records6)) {
            foreach ($records6 as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $fallback = @gethostbyname($host);
            if (is_string($fallback) && $fallback !== $host && filter_var($fallback, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $fallback;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * True when an address is a routable public one.
     *
     * Public test is the INVERSE of every block we know about, plus an
     * outright reject for anything unparseable — an address we cannot
     * classify is an address we must not connect to.
     */
    public static function isPublicIp(string $ip): bool
    {
        $ip = trim($ip, '[]');
        if ($ip === '') {
            return false;
        }

        // Normalise an IPv4-mapped/compatible IPv6 address (::ffff:127.0.0.1,
        // ::127.0.0.1) back to IPv4 and judge it on the v4 rules. Judging the
        // v6 text directly would let a mapped loopback slip past an IPv6-only
        // blocklist — the classic bypass for IPv4-only SSRF filters.
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16) {
            $isMapped = str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff");
            $isCompat = substr_count(substr($packed, 0, 12), "\x00") === 12;
            if ($isMapped) {
                $ip = inet_ntop(substr($packed, 12)) ?: $ip;
                $packed = @inet_pton($ip);
                if ($packed === false || strlen($packed) !== 4) {
                    return false;
                }
            } elseif ($isCompat && substr($packed, 12) !== "\x00\x00\x00\x00") {
                $ip = inet_ntop(substr($packed, 12)) ?: $ip;
                $packed = @inet_pton($ip);
                if ($packed === false || strlen($packed) !== 4) {
                    return false;
                }
            }
        }

        if (strlen($packed) === 4) {
            $long = unpack('N', $packed)[1] ?? 0;
            foreach (self::IPV4_BLOCKED as [$network, $bits]) {
                $net = unpack('N', @inet_pton($network) ?: "\x00\x00\x00\x00")[1] ?? 0;
                $mask = $bits === 0 ? 0 : (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;
                if (($long & $mask) === ($net & $mask)) {
                    return false;
                }
            }
            return true;
        }

        if (strlen($packed) === 16) {
            $binary = $packed;
            foreach (self::IPV6_BLOCKED as [$network, $bits]) {
                if (self::ipv6InRange($binary, @inet_pton($network) ?: '', $bits)) {
                    return false;
                }
            }
            // IPv4-compatible ::/96 outside ::/128 and ::1/128 is a dead range
            // that some stacks still route; refuse the whole thing.
            if (substr_count(substr($binary, 0, 12), "\x00") === 12) {
                return false;
            }
            return true;
        }

        return false;
    }

    /** Prefix match on packed 16-byte addresses. */
    private static function ipv6InRange(string $addr, string $network, int $bits): bool
    {
        if ($network === '' || strlen($network) !== 16) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;
        if ($bytes > 0 && strncmp($addr, $network, $bytes) !== 0) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = ~((1 << (8 - $rem)) - 1) & 0xFF;
        return (ord($addr[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    /** Resolve a possibly-relative redirect target against the current URL. */
    private function resolveReference(string $base, string $reference): string
    {
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $reference) === 1) {
            return $reference;
        }
        if (str_starts_with($reference, '//')) {
            $scheme = (parse_url($base, PHP_URL_SCHEME) ?: 'http');
            return $scheme . ':' . $reference;
        }

        $parts = parse_url($base);
        if ($parts === false || !isset($parts['host'])) {
            return $reference;
        }
        $origin = (parse_url($base, PHP_URL_SCHEME) ?: 'http') . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($reference, '/')) {
            return $origin . $reference;
        }
        if (str_starts_with($reference, '?')) {
            $path = $parts['path'] ?? '/';
            return $origin . $path . $reference;
        }

        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');
        return $origin . ($dir === '' ? '/' : $dir . '/') . $reference;
    }

    /** Host needs brackets in a URL when it is an IPv6 literal. */
    private static function hostForUrl(string $host): string
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $host . ']' : $host;
    }

    private static function bracketIfIpv6(string $ip): string
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $ip . ']' : $ip;
    }

    /**
     * Reduce a Content-Type header to its bare media type.
     *
     * This is only ever reported as ADVISORY metadata. A header is data the
     * remote server chose; it is not evidence about the bytes. Callers must
     * sniff the body (finfo_buffer) before acting on a type.
     */
    private static function mimeFromHeader(string $header): string
    {
        $header = trim($header);
        if ($header === '') {
            return '';
        }
        $semi = strpos($header, ';');
        if ($semi !== false) {
            $header = substr($header, 0, $semi);
        }
        return strtolower(trim($header));
    }
}