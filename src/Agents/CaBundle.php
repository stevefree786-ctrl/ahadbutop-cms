<?php
declare(strict_types=1);

namespace CMS\Agents;

/**
 * Locates a CA bundle that OpenSSL-backed PHP curl can verify against.
 *
 * WHY THIS EXISTS
 *
 * PHP's curl and the CLI's curl are not the same curl on Windows. The CLI
 * binary in Git for Windows links against Schannel, which reads the Windows
 * certificate store, so `curl https://...` succeeds from a terminal. PHP links
 * against OpenSSL, which knows nothing about the Windows store and has no
 * default CA path compiled in on this build — so the identical URL from PHP
 * fails with curl 60, "unable to get local issuer certificate".
 *
 * That is an environment gap, not a certificate problem, and the tempting fix
 * is CURLOPT_SSL_VERIFYPEER = false. That must never happen here: these
 * fetches carry API keys and receive untrusted remote bytes. Verification stays
 * ON; this class only tells OpenSSL where its trust anchors live.
 *
 * Extracted from SafeHttpClient, which solved this for RSS fetches, so
 * BaseAgent's LLM calls would not carry a second, subtly different copy.
 */
final class CaBundle
{
    /** Memoised, including the negative result: a machine with no bundle
     *  should not stat the filesystem on every request. */
    private static ?string $resolved = null;
    private static bool $looked = false;

    /**
     * The first usable CA bundle path, or null when none is found.
     *
     * Returning null is not an error — it just means TLS verification will be
     * attempted against OpenSSL's built-in default, which is correct on a
     * machine configured properly and will simply fail there.
     */
    public static function path(): ?string
    {
        if (self::$looked) {
            return self::$resolved;
        }
        self::$looked  = true;
        self::$resolved = self::find();

        return self::$resolved;
    }

    /**
     * Point a curl handle at the bundle, if one was found.
     *
     * Safe to call on every handle: it is a no-op when no bundle exists, and
     * it never touches VERIFYPEER/VERIFYHOST, which stay at curl's secure
     * defaults.
     */
    public static function apply(\CurlHandle $ch): void
    {
        $ca = self::path();
        if ($ca !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
    }

    /**
     * Test seam: forget the memo so a test can point the ini at a fixture.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$looked    = false;
        self::$resolved = null;
    }

    private static function find(): ?string
    {
        $candidates = [];

        // Explicit configuration wins. An operator who set curl.cainfo meant it.
        foreach (['curl.cainfo', 'openssl.cafile'] as $key) {
            $ini = (string) ini_get($key);
            if ($ini !== '') {
                $candidates[] = $ini;
            }
        }

        $candidates = array_merge($candidates, [
            '/etc/ssl/certs/ca-certificates.crt',          // Debian / Ubuntu
            '/etc/pki/tls/certs/ca-bundle.crt',            // RHEL / Fedora
            '/etc/ssl/ca-bundle.pem',                      // SUSE / Alpine
            '/usr/local/etc/openssl/cert.pem',              // Homebrew / macOS
            '/etc/pki/tls/certs/ca-bundle.crt',             // RHEL, second path
            '/mingw64/etc/ssl/certs/ca-bundle.crt',        // Git for Windows (MSYS root)
            '/usr/ssl/certs/ca-bundle.crt',                // Git for Windows (usr)
            'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt',
            'C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt',
            'C:/msys64/etc/ssl/certs/ca-bundle.crt',
        ]);

        foreach ($candidates as $candidate) {
            if (!is_file($candidate) || !is_readable($candidate)) {
                continue;
            }

            // A candidate must be a parseable PEM bundle, not merely a file.
            // Some Git-for-Windows bundles open with a "# ..." comment before
            // the first BEGIN CERTIFICATE; OpenSSL rejects those with curl 77,
            // which would turn one bad candidate into a failure on every
            // single request. Cheaper to check once here.
            $head = (string) @file_get_contents($candidate, false, null, 0, 4096);
            if (trim($head) === '' || !str_contains($head, 'BEGIN CERTIFICATE')) {
                continue;
            }

            return $candidate;
        }

        return null;
    }
}