<?php
declare(strict_types=1);

namespace CMS\Agents;

/**
 * Per-site provider credentials — the "bring your own key" layer.
 *
 * HOW THIS FITS WITH config/api.php
 *
 * config/api.php already reads KILO_API_KEY / OPENCODE_ZEN_KEY from .env, and
 * that remains the default. This class is the override: when an operator sets a
 * key here, it wins for that site, and .env is the fallback when it is unset.
 * Both are supported deliberately — .env suits a single-operator install where
 * the key lives next to the code, while BYOK suits the real deployment shape
 * where one codebase serves several sites with several different customers'
 * keys and nobody should be editing .env to switch between them.
 *
 * PRECEDENCE, highest first:
 *   1. this store (encrypted, per-site, set through an admin intent)
 *   2. .env / config/api.php (the existing behaviour, unchanged)
 *
 * WHY THIS IS NOT JUST A SETTINGS ROW
 *
 * A settings row would be simpler and wrong in three ways at once:
 *
 *   - `get_setting` is a reader-level intent. Anything that can read a setting
 *     could therefore read a live API key, and readers are the lowest role.
 *   - the value would be plaintext in a database dump, in the audit log, and in
 *     any endpoint that enumerates settings.
 *   - it would be settable through the generic update_setting intent, which
 *     takes an arbitrary key string. That is a footgun with a live credential
 *     in it: one mistyped key name writes a secret somewhere it will never be
 *     cleaned up.
 *
 * So the storage key is derived, not caller-supplied (see slotFor()), the value
 * is encrypted, and there is no generic write path.
 *
 * WHAT AN AGENT CAN AND CANNOT DO WITH THIS
 *
 * An agent can ASK which providers are configured — that is a boolean, safe to
 * return to a model. An agent cannot READ a key: there is no intent that returns
 * one, and ProviderKeyStore::resolve() hands the key straight to the HTTP call
 * that needs it. A model that decides to exfiltrate a credential has nothing to
 * exfiltrate, because the only way to obtain the plaintext is to be the code
 * path making an authenticated request.
 */
final class ProviderKeyStore
{
    /** settings.scope values this class owns. Anything else is another feature's. */
    private const SCOPE = 'byok';

    /**
     * Providers this install knows how to talk to.
     *
     * An allowlist, not a free string. The provider name becomes part of the
     * settings key AND part of the AEAD associated data, so an unlisted name
     * would produce a row nothing ever reads — and, worse, would let a caller
     * write an arbitrary key into the byok namespace.
     */
    private const PROVIDERS = [
        'kilo' => [
            'label'    => 'Kilo Code',
            'env'      => 'KILO_API_KEY',
            'endpoint' => 'https://api.kilo.ai/api/gateway/chat/completions',
        ],
        'zen' => [
            'label'    => 'OpenCode Zen',
            'env'      => 'OPENCODE_ZEN_KEY',
            'endpoint' => 'https://opencode.ai/zen/v1/chat/completions',
        ],
        'openai' => [
            'label'    => 'OpenAI',
            'env'      => 'OPENAI_API_KEY',
            'endpoint' => 'https://api.openai.com/v1/chat/completions',
        ],
        'anthropic' => [
            'label'    => 'Anthropic',
            'env'      => 'ANTHROPIC_API_KEY',
            'endpoint' => 'https://api.anthropic.com/v1/messages',
        ],
    ];

    public function __construct(private \CMS\Database\Connection $db)
    {
    }

    /**
     * @return string[] Provider names this install accepts.
     */
    public static function providers(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public static function isKnown(string $provider): bool
    {
        return isset(self::PROVIDERS[$provider]);
    }

    /**
     * The settings key a provider's ciphertext lives under.
     *
     * Derived, never taken from a caller. prefix+name is enough to be
     * unambiguous and is scoped so it can never collide with a normal setting.
     */
    public static function slotFor(string $provider): string
    {
        return self::SCOPE . ':' . $provider;
    }

    /**
     * Store (or replace) a provider key.
     *
     * @return array{provider:string,stored:bool}
     * @throws \InvalidArgumentException on an unknown provider.
     * @throws \RuntimeException        when this install cannot encrypt.
     */
    public function put(string $provider, string $plaintext, ?int $userId = null): array
    {
        if (!self::isKnown($provider)) {
            throw new \InvalidArgumentException('unknown provider: ' . $provider);
        }

        // Seal BEFORE opening a transaction, so the only failure that can leave
        // a transaction half-open is the write itself.
        $sealed = SecretBox::sealFor(self::slotFor($provider), $plaintext);

        $this->db->getPdo()->prepare(
            'INSERT INTO settings (key, value, scope, is_public, updated_at, updated_by)
             VALUES (?, ?, ?, 0, datetime(\'now\'), ?)
             ON CONFLICT(key) DO UPDATE SET
                 value      = excluded.value,
                 scope      = excluded.scope,
                 is_public  = 0,
                 updated_at = excluded.updated_at,
                 updated_by = excluded.updated_by'
        )->execute([self::slotFor($provider), $sealed, self::SCOPE, $userId]);

        return ['provider' => $provider, 'stored' => true];
    }

    /**
     * Remove a stored key, falling back to .env if one is configured.
     *
     * "Cleared" and "now using .env" are different states and the UI needs to
     * tell them apart, so both are reported rather than just the deletion.
     */
    public function forget(string $provider): array
    {
        if (!self::isKnown($provider)) {
            throw new \InvalidArgumentException('unknown provider: ' . $provider);
        }

        $stmt = $this->db->getPdo()->prepare('DELETE FROM settings WHERE key = ? AND scope = ?');
        $stmt->execute([self::slotFor($provider), self::SCOPE]);
        $stmt->closeCursor();

        $removed = $stmt->rowCount() > 0;

        return [
            'provider'      => $provider,
            'removed'       => $removed,
            'falls_back_to' => $this->envKeyFor($provider) !== null ? 'environment' : null,
        ];
    }

    /**
     * The key to actually use, BYOK first and .env second.
     *
     * @return string|null null when neither source has one, which every caller
     *                     already treats as "provider not configured".
     */
    public function resolve(string $provider): ?string
    {
        if (!self::isKnown($provider)) {
            return null;
        }

        $stmt = $this->db->getPdo()->prepare('SELECT value FROM settings WHERE key = ? AND scope = ?');
        $stmt->execute([self::slotFor($provider), self::SCOPE]);
        $stored = $stmt->fetchColumn();
        $stmt->closeCursor();

        if (is_string($stored) && $stored !== '') {
            $plaintext = SecretBox::openFrom(self::slotFor($provider), $stored);

            // A row that will not decrypt is a real operational problem — wrong
            // CMS_ENCRYPTION_KEY, or a database restored onto another host — so
            // it is logged rather than silently skipped. It still returns null:
            // falling back to .env is the correct behaviour, and a stale
            // ciphertext must never be sent to a provider as if it were a key.
            if ($plaintext === null && SecretBox::isEncrypted($stored)) {
                error_log(sprintf(
                    '[cms.byok] provider "%s" has a stored key that will not decrypt; '
                    . 'check CMS_ENCRYPTION_KEY. Falling back to the environment.',
                    $provider
                ));
            } elseif ($plaintext !== null) {
                return $plaintext;
            }
        }

        return $this->envKeyFor($provider);
    }

    /**
     * Provider status for the settings screen and for an agent that needs to
     * know whether AI features are wired up.
     *
     * Deliberately carries NO secret material. `configured` is a boolean and
     * `source` is one of 'byok' | 'environment' | null — enough for a UI to
     * render, enough for a model to decide it can call out, and nothing an
     * agent could use to make a request the operator did not authorise.
     */
    public function status(): array
    {
        $out = [];

        foreach (self::PROVIDERS as $name => $meta) {
            $stmt = $this->db->getPdo()->prepare('SELECT value FROM settings WHERE key = ? AND scope = ?');
            $stmt->execute([self::slotFor($name), self::SCOPE]);
            $stored = $stmt->fetchColumn();
            $stmt->closeCursor();

            $byokPresent = is_string($stored) && $stored !== '';
            $byokUsable  = $byokPresent && SecretBox::openFrom(self::slotFor($name), $stored) !== null;
            $envPresent  = $this->envKeyFor($name) !== null;

            $out[$name] = [
                'label'      => $meta['label'],
                'configured' => $byokUsable || $envPresent,
                'source'     => $byokUsable ? 'byok' : ($envPresent ? 'environment' : null),
                // True when a row exists but is unreadable — the operator needs
                // to see this, because the key they set is silently not in use.
                'undecryptable' => $byokPresent && !$byokUsable,
                'can_store'  => SecretBox::available(),
            ];
        }

        return $out;
    }

    /**
     * The endpoint to use, allowing a BYOK override.
     *
     * A BYOK provider can point at a self-hosted or proxied gateway, which is
     * a legitimate reason to want this — so the endpoint is overridable, but
     * only to an https URL. An http:// override would put the key on the wire
     * in the clear, and a file:// or gopher:// one is not a thing to permit.
     */
    public function endpointFor(string $provider): ?string
    {
        if (!self::isKnown($provider)) {
            return null;
        }

        $key = self::SCOPE . ':' . $provider . ':endpoint';
        $stmt = $this->db->getPdo()->prepare('SELECT value FROM settings WHERE key = ? AND scope = ?');
        $stmt->execute([$key, self::SCOPE]);
        $custom = $stmt->fetchColumn();
        $stmt->closeCursor();

        if (is_string($custom) && $custom !== '') {
            if (preg_match('#^https://[^\s]+$#i', $custom) === 1) {
                return $custom;
            }
            error_log('[cms.byok] ignoring a non-https endpoint override for ' . $provider);
        }

        $config = require __DIR__ . '/../../config/api.php';

        return $config[$provider]['endpoint'] ?? self::PROVIDERS[$provider]['endpoint'];
    }

    /**
     * Is this string plausibly a provider key rather than a typo or a paste
     * accident?
     *
     * Advisory, not authoritative — the real check is that the provider accepts
     * it. This catches the two mistakes that otherwise cost a support round
     * trip: a key pasted with surrounding whitespace/quotes, and a value that
     * is plainly the wrong thing (a URL, a sentence, a placeholder).
     */
    public static function looksLikeKey(string $provider, string $raw): bool
    {
        $candidate = trim($raw, " \t\n\r\"");

        if ($candidate === '' || str_contains($candidate, ' ')) {
            return false;
        }

        // A key pasted from the wrong place — a dashboard URL, a whole config
        // block, an error message.
        if (str_starts_with($candidate, 'http') || str_contains($candidate, '://')) {
            return false;
        }

        if ($candidate === '' || $candidate === 'YOUR_API_KEY' || $candidate === 'changeme') {
            return false;
        }

        // Provider key formats are public and stable; anything wildly off-length
        // is a paste error worth reporting before it burns a request.
        return match ($provider) {
            'openai'    => str_starts_with($candidate, 'sk-') && strlen($candidate) >= 20,
            'anthropic' => str_starts_with($candidate, 'sk-ant-'),
            default     => strlen($candidate) >= 16,
        };
    }

    private function envKeyFor(string $provider): ?string
    {
        $name = self::PROVIDERS[$provider]['env'] ?? null;
        if ($name === null) {
            return null;
        }

        $value = env($name, '');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}