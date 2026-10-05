<?php
declare(strict_types=1);

namespace CMS\Agents;

/**
 * The agent-facing web tool: fetch a page, read it as text, or search and read.
 *
 * WHY THIS IS NOT JUST SafeHttpClient::get()
 *
 * SafeHttpClient answers "can I fetch this URL safely?" — it is deliberately
 * dumb about content, because its one job is refusing to become an SSRF
 * primitive. This class sits on top and adds the three things a researching
 * agent actually needs, none of which belong in the transport:
 *
 *   1. HTML → readable text. A model handed 400KB of nav, scripts and cookie
 *      banners reasons worse about it than about the 4KB of article that
 *      matters. Extraction happens here so every caller gets the same text.
 *   2. Search. Agents need to start from keywords, not URLs, and guessing URLs
 *      is how research goes wrong — an agent "researching" a competitor simply
 *      invents a plausible path on a domain it half-remembers.
 *   3. A budget. Every fetch is a request to a stranger's server. An agent loop
 *      that retries on failure will happily make four hundred. The budget is
 *      enforced here, per instance, so it holds however many times it is
 *      called.
 *
 * THE SECURITY POSITION, EXPLICITLY
 *
 * Every URL reaching the network goes through SafeHttpClient, which resolves
 * DNS itself and refuses private, loopback, link-local and CGNAT ranges — so
 * `http://169.254.169.254/latest/meta-data/` (the cloud credential endpoint) and
 * `http://127.0.0.1:6379/` fail at the DNS layer no matter what the caller
 * said. This class adds no fetch path of its own and never calls curl directly.
 *
 * That matters here more than elsewhere, because this tool's URL comes from a
 * language model. "Summarise http://localhost:8000/admin" is a completely
 * reasonable thing for a model to produce when someone says "check my site",
 * and without the SSRF layer it would be a way to read the CMS's own admin.
 *
 * One deliberate limit: search results are only fetched from a fixed list of
 * engines. A caller cannot pass an arbitrary "search URL" and have it fetched,
 * because that would turn search into a general-purpose fetch with none of the
 * normalisation this class does — and would let a model aim the tool at an
 * internal host by disguising it as a search query string.
 */
final class WebResearcher
{
    /**
     * Total pages one instance will fetch.
     *
     * Small on purpose: a research task that needs more than this is a task
     * that wants a scraping pipeline, not an agent tool. Making the agent say
     * what it actually wants is the point of the limit.
     */
    private const PAGE_BUDGET = 12;

    /** Characters of extracted text returned per page. */
    private const TEXT_LIMIT = 20_000;

    /**
     * Search engines, as (url template, result-link regex).
     *
     * `{q}` is replaced by the URL-encoded query. Each engine gets a regex
     * because every one of them wraps results in its own redirect URL, and
     * unwrapping those is the only fiddly part of "search without an API".
     */
    private const ENGINES = [
        'duckduckgo' => [
            'url'   => 'https://html.duckduckgo.com/html/?q={q}',
            // Body only — the '#…#i' delimiters are added by the caller. These
            // patterns already carried their own, and wrapping them a second
            // time produced '##…##ii', where the trailing '#i' was parsed as a
            // modifier and every search failed with "Unknown modifier 'd'".
            'links' => 'uddg=(?P<target>[^&"]+)',
        ],
        'bing' => [
            'url'   => 'https://www.bing.com/search?q={q}',
            'links' => '<h2><a[^>]+href="(?P<target>https?://[^"]+)"',
        ],
    ];

    private int $fetchesUsed = 0;

    public function __construct(
        private ?SafeHttpClient $http = null,
        private string $userAgent = 'HermesCMS-ResearchBot/1.0 (+https://example.invalid/bot)'
    ) {
        $this->http ??= new SafeHttpClient();
    }

    /**
     * Fetch a URL and return it as readable text.
     *
     * @return array{ok:bool,url:string,title:string,text:string,status:int,
     *               mime:string,error:?string,remaining:int}
     */
    public function read(string $url, array $opts = []): array
    {
        $remaining = $this->budgetLeft();

        $fail = static fn(string $error): array => [
            'ok' => false, 'url' => $url, 'title' => '', 'text' => '', 'status' => 0,
            'mime' => '', 'error' => $error, 'remaining' => $remaining,
        ];

        if ($remaining <= 0) {
            return $fail('page_budget_exhausted');
        }

        $res = $this->http->get($url, [
            'max_bytes' => 2 * 1024 * 1024,
            'headers'   => ['User-Agent: ' . $this->userAgent],
        ] + $opts);

        if (!$res['ok']) {
            // SafeHttpClient's error codes are already precise (address_blocked,
            // scheme_not_allowed, timeout…). Passing them through unchanged means
            // the caller can tell "the site refused" from "you tried to reach a
            // private IP", which are very different problems.
            return $fail((string) $res['error']);
        }

        $this->fetchesUsed++;

        $mime = strtolower((string) $res['mime']);
        $body = (string) $res['body'];

        // Only text-ish content is worth extracting. A model cannot read a PDF
        // or a JPEG, and sending the raw bytes wastes the budget for a garbled
        // result, so the type is checked before the body is used.
        $isHtml = str_contains($mime, 'html') || (($mime === '' || str_contains($mime, 'text/plain')) && self::looksLikeHtml($body));
        $isText = $isHtml || str_contains($mime, 'text/') || str_contains($mime, 'json') || $mime === '';

        if (!$isText) {
            return [
                'ok' => true, 'url' => $res['final_url'], 'title' => '', 'text' => '',
                'status' => (int) $res['status'], 'mime' => $mime, 'error' => 'unsupported_content_type',
                'remaining' => $this->budgetLeft(),
            ];
        }

        $title = $isHtml ? self::extractTitle($body) : '';
        $text  = $isHtml ? self::htmlToText($body) : $body;
        $text  = self::tidy($text);

        return [
            'ok'        => true,
            'url'       => $res['final_url'],
            'title'     => $title,
            'text'      => self::truncate($text, (int) ($opts['text_limit'] ?? self::TEXT_LIMIT)),
            'status'    => (int) $res['status'],
            'mime'      => $mime,
            'error'     => null,
            'remaining' => $this->budgetLeft(),
        ];
    }

    /**
     * Search, returning titles and URLs without fetching each result.
     *
     * Two-phase on purpose: search returns links, and the caller decides which
     * are worth spending the page budget on. Fetching every hit would burn the
     * budget on ten link-farm pages before reaching the one real source.
     *
     * @return array{ok:bool,engine:string,query:string,results:array<int,array{title:string,url:string}>,
     *               error:?string,remaining:int}
     */
    public function search(string $query, string $engine = 'duckduckgo', int $limit = 8): array
    {
        $remaining = $this->budgetLeft();

        $fail = static fn(string $error): array => [
            'ok' => false, 'engine' => $engine, 'query' => $query, 'results' => [],
            'error' => $error, 'remaining' => $remaining,
        ];

        if ($remaining <= 0) {
            return $fail('page_budget_exhausted');
        }

        if (!isset(self::ENGINES[$engine])) {
            // Never fall back to a default here: silently searching a different
            // engine than the one asked for would make the result set
            // unreproducible, and "which engine" is not a detail.
            return $fail('unknown_engine');
        }

        $limit = max(1, min(15, $limit));
        $spec  = self::ENGINES[$engine];

        // rawurlencode, not urlencode: a space must become %20, not '+', or
        // engines that do not decode '+' return results for the literal "+".
        $url = str_replace('{q}', rawurlencode($query), $spec['url']);

        $res = $this->http->get($url, [
            'max_bytes' => 1024 * 1024,
            'headers'   => ['User-Agent: ' . $this->userAgent],
        ]);

        if (!$res['ok']) {
            return $fail((string) $res['error']);
        }

        $this->fetchesUsed++;

        $html = (string) $res['body'];
        $out  = [];
        $seen = [];

        // DDG's redirect form carries the real URL percent-encoded in `uddg`.
        // preg_match_all returns the match COUNT (0 when nothing matched), and
        // false only on a malformed pattern — so `=== false` is the error
        // check, not `falsy`, which would treat a page with no results as a
        // parse failure.
        if (preg_match_all('#' . $spec['links'] . '#i', $html, $matches) === false) {
            return $fail('parse_failed');
        }

        foreach ($matches['target'] ?? [] as $candidate) {
            $target = html_entity_decode(rawurldecode($candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // A search engine's own nav is not a result. Filtering here keeps
            // the agent from spending budget on the engine's privacy policy.
            if ($target === '' || isset($seen[$target])) {
                continue;
            }
            if (preg_match('#^https?://#i', $target) !== 1) {
                continue;
            }

            $seen[$target] = true;
            $out[] = ['title' => self::titleNear($html, $target), 'url' => $target];

            if (count($out) >= $limit) {
                break;
            }
        }

        return [
            'ok'        => true,
            'engine'    => $engine,
            'query'     => $query,
            'results'   => $out,
            'error'     => null,
            'remaining' => $this->budgetLeft(),
        ];
    }

    /**
     * Search, then read the top results, as one budgeted operation.
     *
     * This is the shape a researching agent actually wants, and doing it in one
     * call is what keeps the budget honest — a loop of search-then-read across
     * two tool calls has no way to see its own total.
     *
     * @return array{query:string,results:array<int,array{url:string,title:string,
     *               text:string,error:?string}>,engine:string}
     */
    public function research(string $query, int $depth = 3, string $engine = 'duckduckgo'): array
    {
        $depth = max(1, min(5, $depth));

        $found = $this->search($query, $engine, $depth);

        if (!$found['ok']) {
            return ['query' => $query, 'engine' => $engine, 'results' => [], 'error' => $found['error']];
        }

        $read = [];

        foreach ($found['results'] as $hit) {
            if (count($read) >= $depth) {
                break;
            }

            $page = $this->read($hit['url']);
            $read[] = [
                'url'   => $page['url'] !== '' ? $page['url'] : $hit['url'],
                'title' => $page['title'] !== '' ? $page['title'] : $hit['title'],
                'text'  => $page['text'],
                'error' => $page['error'],
            ];
        }

        return ['query' => $query, 'engine' => $engine, 'results' => $read, 'error' => null];
    }

    public function budgetLeft(): int
    {
        return max(0, self::PAGE_BUDGET - $this->fetchesUsed);
    }

    public function fetchesUsed(): int
    {
        return $this->fetchesUsed;
    }

    // ------------------------------------------------------------------
    // HTML -> text
    // ------------------------------------------------------------------

    /**
     * Reduce a document to the text a model can actually use.
     *
     * Order matters and is deliberate: <script>/<style> are removed by CONTENT
     * rather than by tag, because a regex for `<script\b[^>]*>.*?</script>` is
     * defeated by a `<` inside a JS string — which is extremely common, and
     * turns the "script" body into visible garbage text. Counting characters
     * between markers is immune to that.
     */
    public static function htmlToText(string $html): string
    {
        // Drop the parts that are never prose.
        //
        // The hasElement() gate is not an optimisation — it is what makes a
        // single destructive pass safe. "head" is a prefix of "header", and a
        // page that opens <head> and then uses <header> three times would
        // otherwise have every masthead swallowed by that one call, because
        // "</head>" matches the "</header>" that comes later.
        foreach (['head', 'script', 'style', 'noscript', 'template', 'svg', 'iframe'] as $tag) {
            if (self::hasElement($html, $tag)) {
                $html = self::dropElementByContent($html, $tag);
            }
        }

        // Comments can carry conditional markup and, historically, data.
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

        // Block-level tags become newlines so paragraphs do not run together,
        // which is the single biggest readability win for a model.
        $html = preg_replace('#<(br|hr)\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|section|article|h[1-6]|li|tr|blockquote|pre)>#i', "\n", $html) ?? $html;
        $html = preg_replace('#<(p|div|section|article|h[1-6]|li|tr|blockquote|pre)\b[^>]*>#i', "\n", $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Tidied here rather than at each call site: this is a public static,
        // and "remember to call tidy() after htmlToText()" is the kind of
        // contract a caller forgets. Idempotent, so read() may still tidy the
        // non-HTML branch.
        return self::tidy($text);
    }

    /**
     * Remove an element and its body, by locating character offsets rather than
     * matching the body with a regex.
     *
     * @see self::htmlToText() for why a regex over the body is not safe.
     */
    private static function dropElementByContent(string $html, string $tag): string
    {
        $out  = '';
        $pos  = 0;
        $len  = strlen($html);
        $name = strlen($tag);

        // Invariant: $out is everything from the start of $html up to $pos.
        // Every place $pos moves forward must move $out with it, or the
        // trailing substr($html, $pos) resumes mid-token and emits the tail
        // of a skipped tag — which is how "<header>" once came out as "er>".
        while (($start = stripos($html, '<' . $tag, $pos)) !== false) {
            // The tag name must END here, or this is a different element that
            // merely starts with the same letters: "<head" would otherwise
            // match "<header" and swallow the page masthead.
            //
            // An attribute after the name is part of the same tag, so it does
            // NOT disqualify it: "<head lang=" is a real <head> to remove.
            $after = $html[$start + $name + 1] ?? '';

            if ($after !== '' && $after !== '>' && $after !== '/' && !ctype_space($after)) {
                $skip = $start + $name + 1;
                $out .= substr($html, $pos, $skip - $pos);
                $pos  = $skip;
                continue;
            }

            $gt = strpos($html, '>', $start);
            if ($gt === false) {
                break;
            }

            // Everything before the element goes to the output verbatim. The
            // element itself is dropped by simply resuming the scan past its
            // closing tag — nothing inside [$start .. closeEnd] is ever
            // copied, so there is no window arithmetic here to get wrong.
            $out .= substr($html, $pos, $start - $pos);

            // Self-closing (<script src="x"/>), or an unterminated start tag:
            // there is no body to skip.
            if (substr($html, $gt - 1, 1) === '/') {
                $pos = $gt + 1;
                continue;
            }

            $close = self::findCloseTag($html, $tag, $gt + 1);

            // Unterminated: everything after the opening tag is body, and the
            // element is never closed, so all of it goes.
            if ($close === -1) {
                return $out;
            }

            // "</tag" is $name + 2 bytes; the '>' may be followed by others
            // ("</style><!-- -->"), so resume after the whole closing tag.
            $closeEnd = strpos($html, '>', $close);
            $pos = $closeEnd === false ? $len : $closeEnd + 1;
        }

        return $out . substr($html, $pos);
    }

    /**
     * Find "</tag>" as a WHOLE tag name, or -1.
     *
     * The name-boundary check is the entire reason this is a loop and not a
     * stripos. "</head" is a prefix of "</header", so a naive search finds
     * the header's closing tag, believes it closes the <head>, and deletes
     * everything between — which on a real page is the masthead, the
     * navigation, and often the opening of the article.
     */
    private static function findCloseTag(string $html, string $tag, int $from): int
    {
        $name = strlen($tag);
        $scan = $from;

        while (($candidate = stripos($html, '</' . $tag, $scan)) !== false) {
            $after = $html[$candidate + $name + 2] ?? '';

            if ($after === '' || $after === '>' || $after === '/' || ctype_space($after)) {
                return $candidate;
            }

            $scan = $candidate + $name + 2;
        }

        return -1;
    }

    /**
     * True when a tag name appears only as the whole tag — the check that
     * makes a single pass safe.
     *
     * "<head>" counts, "<header>" does not, "<head lang=" does. The attribute
     * case matters because dropping "<head>" would swallow everything up to
     * the next "</head>" that merely STARTS the name, and most pages with a
     * <header> have no real </head> to stop at.
     */
    public static function hasElement(string $html, string $tag): bool
    {
        $offset = 0;

        while (($start = stripos($html, '<' . $tag, $offset)) !== false) {
            $after = $html[$start + strlen($tag) + 1] ?? '';

            if ($after === '' || $after === '>' || $after === '/' || ctype_space($after)) {
                return true;
            }

            $offset = $start + strlen($tag) + 1;
        }

        return false;
    }

    public static function extractTitle(string $html): string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) === 1) {
            return self::tidy(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // No <title> (common on JSON endpoints and hand-rolled pages): fall back
        // to the first h1, which is what a human would call the page's title.
        if (preg_match('#<h1[^>]*>(.*?)</h1>#is', $html, $m) === 1) {
            return self::tidy(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    /**
     * The anchor text for a result URL, found by walking the anchor tags.
     *
     * Not a regex lookup keyed on the URL: engines percent-encode, redirect or
     * reorder the href, so matching the exact string finds nothing. Matching on
     * the decoded form and reading the tag's own text is what actually works.
     */
    private static function titleNear(string $html, string $target): string
    {
        if (preg_match_all('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $m, PREG_SET_ORDER) === false) {
            return $target;
        }

        foreach ($m as $anchor) {
            $href = html_entity_decode(rawurldecode($anchor[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (rtrim($href, '/') === rtrim($target, '/')) {
                $text = self::tidy(html_entity_decode(strip_tags($anchor[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return $target;
    }

    /**
     * Normalise whitespace without destroying paragraph breaks.
     *
     * Horizontal runs collapse to a single space; newlines are left alone.
     * Collapsing ALL whitespace would put "Widget Guide" and two paragraphs
     * back on one line, which is exactly the run-together problem the block
     * level newline substitution above exists to prevent.
     */
    private static function tidy(string $text): string
    {
        // Strip control characters (including the NULs that survive naive
        // tag-stripping) but keep newlines and tabs.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;

        // Collapse horizontal runs only — see the docblock.
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;

        // Trailing spaces on each line, then 3+ newlines down to a blank line.
        $text = preg_replace('/[ \t]+$/m', '', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private static function truncate(string $text, int $limit): string
    {
        if ($limit <= 0 || strlen($text) <= $limit) {
            return $text;
        }

        // Cut on a UTF-8 boundary and say so, rather than returning half a
        // multi-byte character that the model would read as corruption.
        $cut = substr($text, 0, $limit);
        $last = mb_strrpos($cut, "\n", 0, 'UTF-8');
        if ($last !== false && $last > $limit * 0.6) {
            $cut = substr($cut, 0, $last);
        }

        return $cut . "\n\n[... truncated at {$limit} characters ...]";
    }

    /** A last-resort sniff for servers that send no useful Content-Type. */
    private static function looksLikeHtml(string $body): bool
    {
        $head = ltrim(substr($body, 0, 512));

        return $head !== ''
            && (stripos($head, '<!doctype html') === 0
                || stripos($head, '<html') === 0
                || stripos($head, '<head') === 0);
    }
}