<?php
declare(strict_types=1);

namespace CMS\Agents;

use CMS\Database\Connection;
use CMS\Repository\MediaRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * Media Agent: pulls images off the internet and files them in the media
 * library so a post can use them.
 *
 * The threat model drives every decision in this file. The URL being fetched
 * is attacker-controlled — it may come from a model's suggestion, from a user
 * typing a task, or from a redirect chosen by a remote server. Two classes of
 * abuse follow, and the agent is built to make both fail closed:
 *
 *   SSRF. A URL like http://169.254.169.254/latest/meta-data/iam/ would turn
 *   the CMS into a proxy for its own private network. SafeHttpClient owns
 *   that defence: scheme allowlist, DNS resolution with private/reserved
 *   address rejection, and a CURLOPT_RESOLVE pin so the address we validated
 *   is the address we connect to. This agent never calls curl itself.
 *
 *   TYPE CONFUSION. A remote server's Content-Type header, and the filename
 *   in its URL, are both claims by the attacker. The ONLY trusted statement
 *   about the bytes is what finfo_buffer() reads out of the body. So:
 *     - the sniffed MIME, never the header, is what validateUpload() is given;
 *     - the file extension is DERIVED from that sniffed MIME, never copied
 *       from the URL path (a .png that is really a .php payload is the classic
 *       way this goes wrong);
 *     - the sniffed MIME must be in MediaRepository::SAFE_IMAGE_MIME, which
 *       excludes image/svg+xml even though ALLOWED accepts it for human
 *       uploads — an SVG is a document that can carry <script>, and this agent
 *       has no way to know an SVG came from a trusted designer.
 *
 * Writes go through the IntentRegistry's `register_media` action with bound
 * parameters, exactly like SeoAgent's save_seo_meta. The model never supplies
 * SQL, and neither does a URL.
 */
class MediaAgent extends BaseAgent
{
    /** How many images one task may import. A model that returns 200 URLs is
     *  either broken or hostile, and both deserve to be cut off. */
    private const MAX_IMPORTS = 4;

    /** Upper bound on a task string before we bother parsing it for URLs. */
    private const MAX_TASK_LENGTH = 4000;

    private Connection $db;
    private IntentRegistry $registry;
    private MediaRepository $media;
    private SafeHttpClient $http;

    public function __construct(?Connection $db = null, string $provider = 'kilo')
    {
        parent::__construct($provider);
        $this->db = $db ?? new Connection(require __DIR__ . '/../../config/database.php');
        $built = Actions::build($this->db);
        $this->registry = new IntentRegistry($this->db, $built['schema'], $built['writers']);
        $this->media    = new MediaRepository($this->db);
        $this->http     = new SafeHttpClient();
    }

    /**
     * BaseAgent contract.
     *
     * Task forms:
     *   "https://cdn.example.com/photo.jpg"        -> import that URL
     *   "mountain landscape hero"                   -> ask the model, then import
     *   "import these: url1 url2"                  -> import each
     *
     * Returns ['status' => 'ok', 'images' => [...]] on success, or a
     * ['status' => 'failed'|'rejected', 'error' => ...] when nothing could be
     * filed — the two shapes every agent in this namespace returns.
     */
    public function execute(string $task, array $context = []): array
    {
        $ctx = Context::fromArray($context);
        $task = trim(mb_substr($task, 0, self::MAX_TASK_LENGTH));

        if ($task === '') {
            return ['status' => 'failed', 'error' => 'Empty task'];
        }

        /*
         * Fail before touching the network, not after. Importing media is an
         * editor action; a caller who cannot register the result should not be
         * able to make the server fetch an arbitrary URL on their behalf
         * (that is a free SSRF oracle even when the write is refused).
         * The registry re-checks the role; this is the early exit, not the
         * authorisation.
         */
        if (!$ctx->isAtLeast('editor')) {
            return [
                'status' => 'rejected',
                'error'  => "Importing media requires the 'editor' role; caller is '{$ctx->userRole}'",
            ];
        }

        $candidates = $this->extractUrls($task);

        $suggested = [];
        if ($candidates === []) {
            // No usable URL in the task: the model has to propose some. Its
            // suggestions are treated exactly like a typed-in URL — same
            // fetcher, same validation, same write path.
            $suggestion = $this->suggest($task, $ctx);
            if (($suggestion['status'] ?? '') !== 'ok') {
                return $suggestion;
            }
            $suggested = $suggestion['images'];
            $candidates = [];
            foreach ($suggested as $entry) {
                if (isset($entry['url']) && is_string($entry['url'])) {
                    $candidates[] = trim($entry['url']);
                }
            }
        }

        $candidates = array_values(array_unique(array_filter($candidates)));
        if ($candidates === []) {
            return ['status' => 'failed', 'error' => 'No image URLs to import (none in the task, none suggested)'];
        }

        // Stable alt-text hints keyed by URL, so a suggestion's alt text
        // survives even when the same URL was typed explicitly.
        $altByUrl = [];
        foreach ($suggested as $entry) {
            if (isset($entry['url'], $entry['alt']) && is_string($entry['alt'])) {
                $altByUrl[$entry['url']] = trim($entry['alt']);
            }
        }

        $imported = [];
        $skipped  = [];

        foreach (array_slice($candidates, 0, self::MAX_IMPORTS) as $url) {
            $result = $this->importOne($url, $task, $altByUrl[$url] ?? null, $ctx);
            if (($result['status'] ?? '') === 'ok') {
                $imported[] = $result['image'];
            } else {
                // Keep going: one dead URL should not discard three good
                // ones, but the reason must survive for the caller.
                $skipped[] = ['url' => $url, 'error' => $result['error'] ?? 'unknown'];
            }
        }

        if ($imported === []) {
            return [
                'status'  => 'failed',
                'error'   => 'No image could be imported: ' . ($skipped[0]['error'] ?? 'unknown'),
                'skipped' => $skipped,
            ];
        }

        return [
            'status'   => 'ok',
            'task'     => $task,
            'images'   => $imported,
            'imported' => count($imported),
            'skipped'  => $skipped,
        ];
    }

    // ------------------------------------------------------------------
    // Import one URL
    // ------------------------------------------------------------------

    /**
     * Fetch -> sniff -> validate -> write to disk -> register via the intent.
     *
     * Every early return here is a rejection with a stated reason. There is
     * no path that writes a byte to disk or a row to the DB before the
     * sniffed MIME has been checked against SAFE_IMAGE_MIME.
     */
    private function importOne(string $url, string $task, ?string $altHint, Context $ctx): array
    {
        // ---- fetch ------------------------------------------------------
        //
        // Redirects are followed, because image CDNs redirect constantly
        // (a resized /large/ variant, a moved asset, an http->https upgrade).
        // SafeHttpClient re-runs the full SSRF check on every hop, so a
        // redirect into private space is refused rather than followed; the
        // explicit opt-in just documents that the hop cap is intended here.
        $response = $this->http->get($url, ['max_redirects' => 3]);

        if (!$response['ok']) {
            return [
                'status' => 'failed',
                'error'  => "Fetch failed for {$url}: " . ($response['error'] ?? 'unknown'),
            ];
        }

        $body = $response['body'];
        if ($body === '') {
            return ['status' => 'failed', 'error' => "Empty response body from {$url}"];
        }

        /*
         * STEP 1 — trust the bytes, not the claims.
         *
         * $response['mime'] is the server's Content-Type and the URL's
         * extension is the path the server chose. Both are attacker-authored.
         * finfo_buffer() is the only statement here that is about the content.
         * If fileinfo is unavailable the honest answer is to refuse, not to
         * guess from the header.
         */
        $sniffed = $this->sniffMime($body);
        if ($sniffed === null) {
            return ['status' => 'failed', 'error' => "Could not determine the content type of {$url}"];
        }

        // STEP 2 — the type allowlist. SAFE_IMAGE_MIME is the gate; ALLOWED
        // is not, and that difference is the point: ALLOWED admits
        // image/svg+xml for uploads a human made through the admin UI, and an
        // SVG fetched from the open internet is an active document.
        if (!in_array($sniffed, MediaRepository::SAFE_IMAGE_MIME, true)) {
            return [
                'status' => 'failed',
                'error'  => "{$sniffed} is not an importable image type (allowed: "
                    . implode(', ', MediaRepository::SAFE_IMAGE_MIME) . ')',
            ];
        }

        $ext = self::EXTENSION_FOR_MIME[$sniffed] ?? null;
        if ($ext === null) {
            return ['status' => 'failed', 'error' => "No file extension is known for {$sniffed}"];
        }

        // STEP 3 — the filename is built from OUR OWN data: a slug of the task
        // plus the checksum, and an extension derived from the sniffed MIME.
        // Nothing from the URL path survives into the name, so `..%2f`, `.php`
        // and a `../../` traversal all die here rather than at the filesystem.
        $checksum = hash('sha256', $body);
        $filename = $this->safeStem($task) . '-' . substr($checksum, 0, 12) . '.' . $ext;

        // STEP 4 — the shared upload rules still apply on top of the checks
        // above: size ceiling and extension/content agreement. Passing the
        // SNIFFED mime (not the header) is what makes this a real check
        // rather than a tautology.
        $validation = MediaRepository::validateUpload($filename, strlen($body), $sniffed);
        if (!$validation['ok']) {
            return ['status' => 'failed', 'error' => "Rejected {$url}: " . ($validation['error'] ?? 'unknown')];
        }

        $key = MediaRepository::buildKey($filename, $checksum);

        // STEP 5 — content-addressed idempotency. buildKey() embeds the
        // checksum, so re-importing the same bytes produces the same key, and
        // (storage, key) is UNIQUE. Registering twice would raise a PDO
        // exception; finding the existing row is the useful answer anyway.
        $existing = $this->media->findByKey('remote', $key);
        if ($existing !== null) {
            return [
                'status' => 'ok',
                'image'  => $this->describe($url, (int) $existing['id'], $key, $sniffed, $body, $altHint, true),
            ];
        }

        // STEP 6 — write the bytes. LOCK_EX so two concurrent agent runs
        // importing the same image cannot interleave into one file.
        $stored = $this->store($key, $body);
        if (($stored['status'] ?? '') !== 'ok') {
            return ['status' => 'failed', 'error' => $stored['error']];
        }

        // STEP 7 — the row, via the allowlisted intent with bound params.
        $dimensions = @getimagesizefromstring($body);
        $alt = $this->altText($altHint, $task);

        try {
            $registered = $this->registry->execute([
                'action' => 'register_media',
                'params' => [
                    'key'         => $key,
                    'url'         => $url,
                    'bytes'       => strlen($body),
                    'width'       => $dimensions[0] ?? null,
                    'height'      => $dimensions[1] ?? null,
                    'alt'         => $alt,
                    'checksum'    => $checksum,
                    'uploaded_by' => $ctx->userId,
                ],
            ], $ctx->userRole);
        } catch (InvalidArgumentException|RuntimeException $e) {
            // The row did not happen, so the bytes we just wrote are orphans.
            // Remove them rather than leaving unreferenced files on disk that
            // nothing will ever garbage-collect.
            @unlink($stored['path']);
            return ['status' => 'rejected', 'error' => $e->getMessage()];
        }

        return [
            'status' => 'ok',
            'image'  => $this->describe($url, (int) ($registered['media_id'] ?? 0), $key, $sniffed, $body, $altHint, false),
        ];
    }

    /**
     * Persist the body under the content-addressed key.
     *
     * The containment check is belt-and-braces: `key` is generated by
     * buildKey() and is already known-safe, but this is a write driven by
     * remote data, and a traversal that escaped storage/ would be a full
     * file-write primitive. Cheap to verify, expensive to discover later.
     *
     * @return array{status:string,path?:string,error?:string}
     */
    private function store(string $key, string $body): array
    {
        $root = rtrim(str_replace('\\', '/', base_path('storage')), '/') . '/';
        $path = $root . str_replace('\\', '/', $key);

        if (str_contains($key, '..') || !str_starts_with($path, $root)) {
            return ['status' => 'failed', 'error' => 'Refusing to write outside storage/'];
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['status' => 'failed', 'error' => "Could not create media directory {$dir}"];
        }

        if (@file_put_contents($path, $body, LOCK_EX) === false) {
            return ['status' => 'failed', 'error' => "Could not write {$path}"];
        }

        return ['status' => 'ok', 'path' => $path];
    }

    // ------------------------------------------------------------------
    // Model-assisted discovery
    // ------------------------------------------------------------------

    /**
     * Ask the model for candidate image URLs plus alt text.
     *
     * The prompt says explicitly that the URLs are untrusted, because the
     * model's own suggestions are the most likely place a prompt-injection
     * payload would try to smuggle an internal address. SafeHttpClient would
     * reject it either way; saying so in the prompt just wastes fewer tokens.
     *
     * @return array{status:string,images?:array,error?:string}
     */
    private function suggest(string $task, Context $ctx): array
    {
        $system = <<<'PROMPT'
You are a media agent inside a CMS. Suggest up to 4 images that would suit the described content.

Respond with ONLY raw JSON — no prose, no markdown fences — in exactly this shape:
{"images":[{"url":"https://...","alt":"short descriptive alt text"}]}

Rules:
- "url" MUST be an absolute https:// or http:// URL to a real image file
  ending in .jpg/.jpeg/.png/.webp/.gif/.avif.
- NEVER emit a private, loopback, link-local or metadata URL
  (no 127.x, 10.x, 192.168.x, 169.254.169.254, no [::1], no .local names).
- NEVER emit a URL you invented. If you are unsure a URL exists, omit it.
- "alt" describes what is visible in the picture, not what the URL is called.
If you cannot suggest a real URL, respond with {"images":[]}.
PROMPT;

        $content = $this->callLLM($system, $ctx->toPromptBlock() . "\n\n" . $task, [
            'max_tokens' => 600,
            'temperature' => 0.3,
        ]);

        if ($content === null) {
            return ['status' => 'failed', 'error' => 'No LLM provider available (missing API key or rate limited)'];
        }

        $decoded = $this->extractJson($content);
        if ($decoded === null) {
            return ['status' => 'failed', 'error' => 'Model did not return valid JSON'];
        }

        $images = $decoded['images'] ?? null;
        if (!is_array($images) || $images === []) {
            return ['status' => 'failed', 'error' => 'Model suggested no image URLs'];
        }

        $clean = [];
        foreach (array_slice($images, 0, self::MAX_IMPORTS) as $entry) {
            if (!is_array($entry) || !isset($entry['url']) || !is_string($entry['url'])) {
                continue;
            }
            $clean[] = [
                'url' => trim($entry['url']),
                'alt' => isset($entry['alt']) && is_string($entry['alt'])
                    ? mb_substr(trim($entry['alt']), 0, 300)
                    : '',
            ];
        }

        return $clean === []
            ? ['status' => 'failed', 'error' => 'Model suggested no usable image URLs']
            : ['status' => 'ok', 'images' => $clean];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** Sniffed MIME -> the extension ALLOWED maps to that same MIME. */
    private const EXTENSION_FOR_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'image/avif' => 'avif',
    ];

    /**
     * The real content type of the bytes, or null when it cannot be known.
     *
     * Reads only the first chunk: libmagic needs a header, not a whole file,
     * and this runs before the size is otherwise considered.
     */
    private function sniffMime(string $body): ?string
    {
        if (!function_exists('finfo_open')) {
            return null;
        }

        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        try {
            $mime = @finfo_buffer($finfo, substr($body, 0, 8192));
        } finally {
            @finfo_close($finfo);
        }

        return is_string($mime) && $mime !== '' ? strtolower(trim($mime)) : null;
    }

    /**
     * Absolute http(s) URLs mentioned in the task, in order, de-duplicated
     * and capped. Trailing punctuation ("see https://x/y.jpg.") is trimmed so
     * a sentence-final full stop does not become part of the path.
     *
     * @return string[]
     */
    private function extractUrls(string $task): array
    {
        if (preg_match_all('#\bhttps?://[^\s<>"\'`\\\\]+#i', $task, $matches) === false) {
            return [];
        }

        $urls = [];
        foreach ($matches[0] as $candidate) {
            $candidate = rtrim($candidate, '.,;:!?)]}\'"');
            if ($candidate !== '' && !in_array($candidate, $urls, true)) {
                $urls[] = $candidate;
                if (count($urls) >= self::MAX_IMPORTS) {
                    break;
                }
            }
        }

        return $urls;
    }

    /** kebab-case stem from the task, so the filename is descriptive but inert. */
    private function safeStem(string $task): string
    {
        $stem = slugify(mb_substr($task, 0, 60));
        // slugify() can return a long run of digits; keep it short and always
        // start with a letter so the name is safe on every filesystem.
        $stem = trim(preg_replace('/^[0-9-]+/', '', $stem) ?? '', '-');
        return mb_substr($stem !== '' ? $stem : 'image', 0, 60);
    }

    /**
     * Alt text: the model's suggestion if there is one, otherwise a short
     * description built from the task with any URL stripped out. Never empty
     * when we can help it — an empty alt attribute is worse than a generic one
     * for accessibility.
     */
    private function altText(?string $hint, string $task): string
    {
        $alt = trim((string) $hint);
        if ($alt !== '') {
            return mb_substr($alt, 0, 300);
        }

        $alt = preg_replace('/\bhttps?:\/\/\S+/i', '', $task) ?? $task;
        $alt = trim(preg_replace('/\s+/', ' ', $alt) ?? '');

        return mb_substr($alt !== '' ? $alt : 'Imported image', 0, 300);
    }

    /**
     * The caller-facing shape of one imported image.
     *
     * @return array<string,mixed>
     */
    private function describe(
        string $url,
        int $mediaId,
        string $key,
        string $mime,
        string $body,
        ?string $altHint,
        bool $alreadyRegistered
    ): array {
        $dimensions = @getimagesizefromstring($body);

        return [
            'media_id' => $mediaId,
            'url'      => $url,
            'key'      => $key,
            'mime'     => $mime,
            'bytes'    => strlen($body),
            'width'    => $dimensions[0] ?? null,
            'height'   => $dimensions[1] ?? null,
            'alt'      => $this->altText($altHint, $url),
            'existing' => $alreadyRegistered,
        ];
    }

    /**
     * Strip markdown fences and pull the outermost JSON object out of a model
     * response. Returns null for prose — never throws on malformed input.
     */
    private function extractJson(string $content): ?array
    {
        $content = trim($content);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $content, $m)) {
            $content = trim($m[1]);
        }
        if (!str_starts_with($content, '{')) {
            if (preg_match('/\{.*\}/s', $content, $m)) {
                $content = $m[0];
            }
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }
}