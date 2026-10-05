<?php
namespace CMS\Agents;

/**
 * Base agent with shared LLM call logic (Kilo + OpenCode Zen BYOK)
 */
abstract class BaseAgent
{
    protected array $apiConfig;
    protected string $provider;

    /**
     * BYOK override for the provider this agent uses.
     *
     * Built lazily rather than in the constructor because BaseAgent is
     * constructed all over the place — including in tests that never make an
     * API call — and opening a SQLite handle for every one of those would make
     * the constructor the most expensive thing in the agent layer.
     */
    private ?ProviderKeyStore $keyStore = null;

    /**
     * @param ProviderKeyStore|null $keys Injectable so tests can supply a
     *        store without touching the real database. Tests pass a subclass
     *        or a store backed by a temp DB; production passes null.
     */
    public function __construct(
        string $provider = 'kilo',
        private ?ProviderKeyStore $keys = null
    ) {
        $this->apiConfig = require __DIR__ . '/../../config/api.php';
        $this->provider = $provider;
    }

    /**
     * The BYOK store, opened on first use.
     *
     * A failure to connect is not fatal: it degrades to .env keys, which is
     * the pre-BYOK behaviour. Refusing to construct an agent because an
     * optional override layer cannot open its database would turn a
     * convenience feature into a hard dependency.
     */
    protected function keyStore(): ?ProviderKeyStore
    {
        if ($this->keyStore !== null || $this->keys !== null) {
            return $this->keyStore = $this->keys;
        }

        try {
            $this->keyStore = new ProviderKeyStore(
                new \CMS\Database\Connection(require __DIR__ . '/../../config/database.php')
            );
        } catch (\Throwable $e) {
            error_log('[cms.llm] BYOK store unavailable, falling back to .env keys: ' . $e->getMessage());
            return null;
        }

        return $this->keyStore;
    }

    abstract public function execute(string $task, array $context = []): array;

    /**
     * Call LLM API with automatic failover: Kilo -> Zen
     * Respects free-tier rate limits (Kilo 200/hr, Zen 100/day)
     *
     * protected, not private, so tests can subclass an agent and stub the
     * network seam without touching config/api.php or the live keys.
     */
    protected function callLLM(string $systemPrompt, string $userPrompt, array $opts = []): ?string
    {
        $providers = [$this->provider, $this->provider === 'kilo' ? 'zen' : 'kilo'];

        foreach ($providers as $provider) {
            $response = $this->callProvider($provider, $systemPrompt, $userPrompt, $opts);
            if ($response !== null) {
                return $response;
            }
        }
        return null;
    }

    private function callProvider(string $provider, string $system, string $user, array $opts = []): ?string
    {
        // BYOK first, .env second.
        //
        // Order matters and is the whole point of the feature: a BYOK key is
        // the operator's explicit choice for THIS site, so it wins. The .env
        // value stays the fallback so an install that never touches the BYOK
        // screen behaves exactly as it did before this existed.
        //
        // The resolved key never leaves this method. It goes into a header and
        // is not logged, not returned, and not put in $this — so there is no
        // code path, including an error path, that surfaces it.
        $apiKey = $this->keyStore()?->resolve($provider)
            ?? ($this->apiConfig[$provider]['api_key'] ?? null);

        if (!is_string($apiKey) || trim($apiKey) === '') {
            return null;
        }

        // Same precedence for the endpoint: a BYOK install may point at a
        // self-hosted gateway. endpointFor() only ever returns https.
        $endpoint = $this->keyStore()?->endpointFor($provider)
            ?? ($this->apiConfig[$provider]['endpoint'] ?? null);

        if (!is_string($endpoint) || $endpoint === '') {
            return null;
        }

        $model = $opts['model'] ?? ($provider === 'kilo' ? 'kilo-auto/free' : 'deepseek-v4-flash-free');
        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];

        $payload = json_encode([
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $opts['max_tokens'] ?? 2048,
            'temperature' => $opts['temperature'] ?? 0.7,
        ]);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];
        if ($provider === 'kilo') {
            $headers[] = 'x-kilocode-mode: ' . ($opts['mode'] ?? 'code');
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
        ]);

        /*
         * Certificate verification stays ON — these requests carry an API key
         * and receive untrusted bytes. What varies between machines is whether
         * OpenSSL has a CA bundle to verify AGAINST: PHP's OpenSSL build has no
         * default on Windows, so every provider call fails with curl 60 while
         * the CLI's curl (Schannel, Windows cert store) succeeds. CaBundle
         * only supplies the trust anchors; it never disables verification.
         */
        CaBundle::apply($ch);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Surfaced so a provider outage is diagnosable rather than looking
        // identical to "no API key configured" — both return null today, and
        // that ambiguity is what makes this class of bug hard to find.
        if ($response === false) {
            error_log(sprintf(
                '[cms.llm] %s request failed: curl %d (%s)',
                $provider,
                curl_errno($ch),
                curl_strerror(curl_errno($ch))
            ));
        }
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return null;
        }

        $data = json_decode($response, true);
        return $data['choices'][0]['message']['content'] ?? null;
    }

    protected function enqueueJob(string $jobType, array $payload, int $delaySeconds = 0, ?string $idempotencyKey = null): int
    {
        $pdo = (new \CMS\Database\Connection(require __DIR__ . '/../../config/database.php'))->getPdo();

        // SQLite's datetime('now') is UTC, so run_after must be UTC too — PHP's
        // date() follows the process timezone, which would skew delayed jobs.
        // Two static statements rather than one with a NULL modifier: passing
        // NULL for the modifier makes datetime() return NULL, which violates
        // run_after NOT NULL and makes INSERT OR IGNORE drop the row silently.
        $sql = $delaySeconds > 0
            ? "INSERT OR IGNORE INTO jobs (type, payload, status, priority, run_after, idempotency_key)
               VALUES (?, ?, 'queued', 100, datetime('now', ?), ?)"
            : "INSERT OR IGNORE INTO jobs (type, payload, status, priority, run_after, idempotency_key)
               VALUES (?, ?, 'queued', 100, datetime('now'), ?)";

        // idempotency_key is UNIQUE — a duplicate insert is a no-op, which is
        // what makes cron-driven jobs safe to enqueue more than once.
        $stmt = $pdo->prepare($sql);
        $stmt->execute(
            $delaySeconds > 0
                ? [$jobType, json_encode($payload), sprintf('%+d seconds', $delaySeconds), $idempotencyKey]
                : [$jobType, json_encode($payload), $idempotencyKey]
        );

        // On a duplicate, rowCount() is 0 and lastInsertId() still reports the
        // PREVIOUS row's id — returning that would hand the caller a job id
        // pointing at somebody else's job. Look the real id up by key instead.
        if ($stmt->rowCount() === 0 && $idempotencyKey !== null) {
            $find = $pdo->prepare('SELECT id FROM jobs WHERE idempotency_key = ? LIMIT 1');
            $find->execute([$idempotencyKey]);
            $existing = $find->fetchColumn();
            return $existing !== false ? (int) $existing : 0;
        }

        return (int)$pdo->lastInsertId();
    }
}