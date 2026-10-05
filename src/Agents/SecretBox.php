<?php
declare(strict_types=1);

namespace CMS\Agents;

/**
 * Authenticated encryption for operator-supplied API keys (BYOK).
 *
 * THE PROBLEM THIS SOLVES
 *
 * BYOK means a site owner types their own provider key into a settings screen.
 * The obvious storage is the existing `settings` table, which is:
 *
 *   - readable by any SQL that touches the table, including a bug or an agent
 *     intent that ends up interpolating a value into a WHERE clause;
 *   - dumped whole by `sqlite3 cms.db .dump`, which is the single most common
 *     way a CMS database gets copied, backed up, or attached to a bug report;
 *   - returned verbatim by any endpoint that reads settings, because the table
 *     has an `is_public` column but no notion of "secret";
 *   - present in plaintext in the audit log, if the write is logged the way
 *     other settings writes are.
 *
 * So a key stored there is a key that leaks. Encrypting it fixes the dump case
 * and the casual-read case. It does NOT fix the SQL case — which is why the
 * design constraint below matters more than the cipher choice.
 *
 * THE CONSTRAINT THAT SHAPES EVERYTHING ELSE
 *
 * A ciphertext is non-deterministic (a fresh IV every time), so you cannot
 * query by it, index it, or compare it in SQL. That is a feature. It means a
 * stored key can ONLY be read by code holding the encryption key, and the only
 * code that holds it is this class plus the agent that is about to make an API
 * call. No agent can express "SELECT ... WHERE value = <key>" because the value
 * it would have to supply is not in the database and never could be.
 *
 * That is why BYOK is deliberately NOT routed through IntentRegistry as a
 * generic "set setting key value". It has its own narrow intents
 * (set_provider_key / clear_provider_key / list_providers) which are the only
 * paths that can write a secret, and they refuse to echo one back.
 *
 * WHAT THE CIPHER IS, AND WHY NOT SOMETHING FANCIER
 *
 * XChaCha20-Poly1305 via libsodium's crypto_aead_xchacha20poly1305_ietf, chosen
 * over AES-GCM because it has a 192-bit nonce — random nonces colliding is not
 * a thing that can happen, so the cipher needs no key rotation dance to stay
 * safe. Overkill for "hide a key from a database dump"? Yes. The cost is
 * 32 bytes of nonce per record and one extra function call.
 *
 * The reason to pay it: this key is long-lived, stored forever, and written by
 * possibly many different operators over the CMS's life. The failure mode of a
 * nonce reuse under AES-GCM is total plaintext recovery for that record, and
 * it is silent — there is no error, the data just decrypts to garbage. Choosing
 * a cipher where that cannot happen removes a class of bug that would
 * otherwise live here forever, silently, in the one component nobody tests.
 *
 * WHEN libsodium IS ABSENT
 *
 * Everything degrades to refusing to store rather than storing in plaintext.
 * A CMS that cannot encrypt does not silently become a CMS that doesn't.
 */
final class SecretBox
{
    private const PREFIX = 'enc:v1:';

    public const ERROR_UNAVAILABLE = 'encryption_unavailable';
    public const ERROR_NO_KEY      = 'no_encryption_key_configured';
    public const ERROR_BAD_PAYLOAD = 'not_a_valid_ciphertext';
    public const ERROR_DECRYPT     = 'decryption_failed';
    public const ERROR_NOT_ENCRYPTED = 'not_encrypted';

    /**
     * The master key, as raw bytes, or null when the deployment has none.
     *
     * Null is a normal, supported state: it means "this install has not been
     * given a key", and every write is refused. Reads of already-stored
     * ciphertext also fail, which is the honest outcome — a database restored
     * onto a host with a different (or missing) CMS_ENCRYPTION_KEY cannot be
     * read, and pretending otherwise would mean handing back a wrong key.
     */
    private static ?string $key = null;

    private static bool $keyLoaded = false;

    /** Overridable so tests can pin a key without touching the environment. */
    public static function useTestKey(?string $rawKey): void
    {
        self::$key = $rawKey === null ? null : self::deriveKey($rawKey);
        self::$keyLoaded = true;
    }

    /** Forget the loaded key, so the next call re-reads the environment. */
    public static function reset(): void
    {
        self::$key = null;
        self::$keyLoaded = false;
    }

    /**
     * Is encryption possible on this install at all?
     *
     * Checked before any UI renders a key field, so the failure is stated up
     * front rather than discovered when a save silently does nothing.
     */
    public static function available(): bool
    {
        return self::key() !== null;
    }

    /**
     * Encrypt a secret for storage.
     *
     * @throws \RuntimeException with one of the ERROR_* codes when this
     *                           install cannot encrypt, or the input is empty.
     */
    public static function seal(string $plaintext): string
    {
        $key = self::key();

        if ($key === null) {
            throw new \RuntimeException(
                self::ERROR_UNAVAILABLE . ': libsodium is not available on this host'
            );
        }

        $plaintext = trim($plaintext);
        if ($plaintext === '') {
            throw new \RuntimeException('refusing to store an empty secret');
        }

        // A cap so a caller cannot accidentally encrypt a whole file into a
        // settings row. Provider keys are hundreds of characters at most.
        if (strlen($plaintext) > 4096) {
            throw new \RuntimeException('secret is implausibly long (>4096 bytes)');
        }

        $nonce   = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $payload = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            self::associatedData(),
            $nonce,
            $key
        );

        return self::PREFIX . base64_encode($nonce . $payload);
    }

    /**
     * Decrypt a stored secret.
     *
     * Returns null for anything that is not a well-formed ciphertext rather
     * than throwing, because the callers are rendering a settings screen that
     * must still work when a key is absent, undecryptable, or predates BYOK.
     */
    public static function open(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        // Not one of ours: a plaintext value from before BYOK existed, or a
        // hand-edited row. Never guess — treating it as a key would mean
        // sending garbage to a provider.
        if (!str_starts_with($stored, self::PREFIX)) {
            return null;
        }

        $key = self::key();
        if ($key === null) {
            return null;
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            return null;
        }

        $nonce   = substr($raw, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $payload = substr($raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $payload,
            self::associatedData(),
            $nonce,
            $key
        );

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * A display form that proves a key exists without revealing it.
     *
     * A settings screen needs to answer "is a key configured?" — never "what is
     * the key?". Even a suffix helps an attacker confirm a guessed key, so this
     * shows only presence, and always as the same four characters regardless of
     * the real length: a per-character mask leaks the key's exact length, which
     * is more than a confirmation needs.
     *
     * Takes the slot because the stored value is bound to it (see
     * associatedData()) — decrypting without it correctly fails, which would
     * make every configured key display as absent.
     */
    public static function mask(string $slot, ?string $stored): string
    {
        return self::openFrom($slot, $stored) === null ? '' : str_repeat('•', 4);
    }

    public static function isEncrypted(?string $stored): bool
    {
        return is_string($stored) && str_starts_with($stored, self::PREFIX);
    }

    /**
     * Data bound to every ciphertext, so a record cannot be moved between
     * providers or slots.
     *
     * Without this, an operator (or a SQL bug) could copy provider A's stored
     * ciphertext over provider B's row and both would decrypt cleanly — the
     * Poly1305 tag proves integrity, not that the value belongs in THAT slot.
     * Binding the provider name into the tag makes that swap fail to decrypt.
     */
    private static function associatedData(string $slot = ''): string
    {
        return 'hermes.byok.v1' . ($slot !== '' ? ':' . $slot : '');
    }

    /**
     * Seal into a named slot.
     *
     * Every real caller uses this. The single-argument seal() exists only so
     * the primitive is testable in isolation.
     */
    public static function sealFor(string $slot, string $plaintext): string
    {
        return self::sealWithData(self::associatedData($slot), $plaintext);
    }

    public static function openFrom(string $slot, ?string $stored): ?string
    {
        return self::openWithData(self::associatedData($slot), $stored);
    }

    private static function sealWithData(string $aad, string $plaintext): string
    {
        $key = self::key();
        if ($key === null) {
            throw new \RuntimeException(self::ERROR_UNAVAILABLE);
        }

        $plaintext = trim($plaintext);
        if ($plaintext === '') {
            throw new \RuntimeException('refusing to store an empty secret');
        }
        if (strlen($plaintext) > 4096) {
            throw new \RuntimeException('secret is implausibly long (>4096 bytes)');
        }

        $nonce   = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $payload = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $key);

        return self::PREFIX . base64_encode($nonce . $payload);
    }

    private static function openWithData(string $aad, ?string $stored): ?string
    {
        if ($stored === null || $stored === '' || !str_starts_with($stored, self::PREFIX)) {
            return null;
        }

        $key = self::key();
        if ($key === null) {
            return null;
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            return null;
        }

        $nonce   = substr($raw, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $payload = substr($raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($payload, $aad, $nonce, $key);

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * The master key, derived to exactly 32 bytes.
     *
     * Any passphrase of any length is accepted: the operator types whatever
     * they have, and HKDF stretches it. The alternative — demanding exactly 32
     * bytes of high-entropy input — produces support burden and "my key is the
     * wrong length" reports, and buys nothing here, because this key protects
     * a database file that is already only as safe as the host it sits on.
     */
    private static function key(): ?string
    {
        if (self::$keyLoaded) {
            return self::$key;
        }

        self::$keyLoaded = true;

        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            self::$key = null;
            return null;
        }

        $raw = env('CMS_ENCRYPTION_KEY', null);

        // An unset key and an empty-string key are the same failure, and the
        // empty string is the more dangerous of the two: it is what an operator
        // leaves behind when they `echo > .env` a blank line. Treat both as absent.
        if (!is_string($raw) || trim($raw) === '') {
            self::$key = null;
            return null;
        }

        self::$key = self::deriveKey($raw);

        return self::$key;
    }

    private static function deriveKey(string $raw): string
    {
        // A fixed, application-specific info string: the same passphrase used
        // for something else in the same deployment must not produce the same
        // key here.
        return hash_hkdf('sha256', trim($raw), 32, 'hermes-cms-byok-v1');
    }
}