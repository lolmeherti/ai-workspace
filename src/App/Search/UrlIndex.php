<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Deterministic index of the URLs found in a user message.
 *
 * Runs on the PHP side so there is ONE implementation of "which URLs are in this text": the composer strip
 * asks the backend to index the box contents, the send path re-indexes the same way, and the two can never
 * disagree. Fetching is a separate decision the caller makes — this class only says what is there.
 *
 * Three things it guarantees:
 *   • every URL in the text is enumerated, in document order, before anything is fetched;
 *   • the same page reached twice (tracking params, fragment, trailing slash, param order, scheme/host case)
 *     is ONE entry, not two — so a URL is never parsed twice;
 *   • each entry carries whether it can be fetched at all, and why not, so a relative or private-host link is
 *     a countable row in the trace instead of a silent attempt.
 *
 * It is not the security boundary: BridgeFetcher::validateFetchUrl still rejects private hosts, odd ports and
 * credentialed URLs at fetch time. This is pre-filtering plus honest reporting.
 */
final class UrlIndex
{
    /**
     * Query parameters that never change which page you get. Dropped when computing the identity of a URL,
     * so "?utm_source=twitter" and the bare URL are the same entry.
     */
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'utm_name',
        'gclid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid', 'igshid', 'twclid', 'li_fat_id',
        'ref', 'ref_src', 'referrer', 'source', 'campaign_id', 'spm', 'si',
    ];

    /**
     * Index the text. Returns every distinct URL with its fetchability.
     *
     * @return array{entries: array<int, array{raw:string,url:string,key:string,domain:string,fetchable:bool,reason:?string}>, counts: array<string,int>}
     */
    public static function index(string $text): array
    {
        $seen = [];
        $entries = [];
        $duplicates = 0;

        foreach (self::extract($text) as $raw) {
            $url = self::normalize($raw);
            if ($url === '') {
                continue;
            }
            $key = self::key($url);
            if (isset($seen[$key])) {
                $duplicates++;
                continue;
            }
            $seen[$key] = true;
            [$ok, $reason] = self::fetchable($url);
            $entries[] = [
                'raw'      => $raw,
                'url'      => $url,
                'key'      => $key,
                'domain'   => (string) (parse_url($url, PHP_URL_HOST) ?: ''),
                'fetchable' => $ok,
                'reason'   => $reason,
            ];
        }

        $fetchable = 0;
        foreach ($entries as $e) {
            if ($e['fetchable']) $fetchable++;
        }

        return [
            'entries' => $entries,
            'counts'  => [
                'found'      => count($seen) + $duplicates,
                'unique'     => count($entries),
                'duplicates' => $duplicates,
                'fetchable'  => $fetchable,
                'skipped'    => count($entries) - $fetchable,
            ],
        ];
    }

    /**
     * Split indexed entries against URLs already parsed in this session: nothing gets fetched twice.
     *
     * @param array<int, array{key:string}> $entries
     * @param string[] $alreadyParsedUrls raw or normalised URLs already fetched earlier in the session
     * @return array{fresh: array<int, array>, already: array<int, array>}
     */
    public static function excluding(array $entries, array $alreadyParsedUrls): array
    {
        $parsed = [];
        foreach ($alreadyParsedUrls as $u) {
            $n = self::normalize((string) $u);
            if ($n !== '') $parsed[self::key($n)] = true;
        }
        $fresh = [];
        $already = [];
        foreach ($entries as $entry) {
            if (isset($parsed[$entry['key']])) $already[] = $entry;
            else $fresh[] = $entry;
        }
        return ['fresh' => $fresh, 'already' => $already];
    }

    /**
     * Which entries the composer strip should have ON by default.
     *
     * One implementation, used by the indexing endpoint and by the send path, so the chips the user sees and
     * the decision the backend makes can never disagree. Rule: a message that is essentially just a link
     * defaults to ON (pasting a link IS the instruction); anything longer — a pasted page, a paragraph quoting
     * links — defaults to OFF, and the user turns on what should actually be read.
     *
     * @param array{entries: array, counts: array} $index output of index()
     * @return array{on: string[], off: string[], prose: string, summarize: bool, basically_a_link: bool}
     */
    public static function defaultSelection(array $index, string $text): array
    {
        // A video is not fetchable as a page, but this turn can still open one: the watch path claims it. The
        // selection rules below therefore consider videos too, and only the page path filters on fetchable-ness.
        $fetchable = array_values(array_filter($index['entries'], static fn(array $e): bool =>
            $e['fetchable'] || ($e['reason'] ?? '') === 'video'));
        $prose = trim((string) preg_replace('~https?://\S+~iu', ' ', $text));
        $prose = trim((string) preg_replace('~\s+~u', ' ', $prose));
        $basicallyALink = count($fetchable) > 0 && count($fetchable) <= 2 && mb_strlen($prose) <= 200;

        $on = [];
        $off = [];
        foreach ($fetchable as $e) {
            if ($basicallyALink) $on[] = $e['key'];
            else $off[] = $e['key'];
        }
        return [
            'on' => $on,
            'off' => $off,
            'prose' => $prose,
            'summarize' => $prose === '' && $on !== [],
            'basically_a_link' => $basicallyALink,
        ];
    }

    /**
     * Raw URL candidates in document order: plain URLs plus markdown link targets.
     *
     * @return string[]
     */
    public static function extract(string $text): array
    {
        $hits = [];
        $markdownSpans = [];
        // markdown targets
        if (preg_match_all('~\]\(\s*(https?://[^)\s]+)~iu', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$u, $off]) {
                $hits[] = [(int) $off, $u];
                $markdownSpans[] = [(int) $off, (int) $off + strlen($u)];
            }
        }
        // bare URLs anywhere else — a URL that is a markdown target is not also a bare hit, otherwise the
        // same link is counted twice (once per pattern) and the duplicate counter lies
        if (preg_match_all('~(?<![\w@])https?://[^\s<>"\'`\]]+~iu', $text, $m2, PREG_OFFSET_CAPTURE)) {
            foreach ($m2[0] as [$u, $off]) {
                $inside = false;
                foreach ($markdownSpans as [$s, $e]) {
                    if ((int) $off >= $s && (int) $off < $e) { $inside = true; break; }
                }
                if (!$inside) $hits[] = [(int) $off, $u];
            }
        }
        // one list in DOCUMENT ORDER, not "all markdown, then all plain text"
        usort($hits, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $found = array_map([self::class, 'trimTrailing'], array_column($hits, 1));
        return array_values(array_filter($found, static fn(string $u): bool => $u !== ''));
    }

    /**
     * Drop punctuation that belongs to the sentence, not the URL. A closing bracket is kept when the URL
     * opened one, so ".../Llama_(language_model)" survives while "see https://x.com." loses its full stop.
     */
    public static function trimTrailing(string $url): string
    {
        $url = trim($url, " \t\n\r\0\x0B<>\u{201c}\u{201d}");
        // a URL never starts with one of these, so a leading wrapper is always the sentence's
        $url = ltrim($url, "([<\"'\u{2018}\u{2019}\u{201c}\u{201d}");
        while ($url !== '') {
            $last = mb_substr($url, -1);
            if (in_array($last, ['.', ',', ';', ':', '!', '?', "'", '"', "\u{2019}", "\u{201d}"], true)) {
                $url = mb_substr($url, 0, -1);
                continue;
            }
            if ($last === ')' && substr_count($url, '(') < substr_count($url, ')')) {
                $url = mb_substr($url, 0, -1);
                continue;
            }
            if ($last === ']' && substr_count($url, '[') < substr_count($url, ']')) {
                $url = mb_substr($url, 0, -1);
                continue;
            }
            break;
        }
        return $url;
    }

    /**
     * Canonical form used for display and fetching: default ports and tracking params removed, fragment
     * dropped, query params sorted, path untouched (paths are case-sensitive).
     */
    public static function normalize(string $url): string
    {
        $url = self::trimTrailing($url);
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path === '') $path = '/';

        $query = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str((string) $parts['query'], $params);
            foreach (array_keys($params) as $name) {
                if (in_array(strtolower((string) $name), self::TRACKING_PARAMS, true)) {
                    unset($params[$name]);
                }
            }
            ksort($params);
            $query = http_build_query($params);
        }

        $out = $scheme . '://' . $host . ($port !== null ? ':' . $port : '') . $path;
        return $query !== '' ? $out . '?' . $query : $out;
    }

    /** Identity of a URL: two entries with the same key are the same page and are fetched once. */
    public static function key(string $url): string
    {
        return rtrim(self::normalize($url), '/');
    }

    /**
     * The endpoint a page lives at: its path and query, no host. Used where the site is already the heading and
     * two pages on it have to be told apart. '' when the URL carries no path worth showing.
     */
    public static function endpoint(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $path = (string) ($parts['path'] ?? '');
        $endpoint = ($path === '/' ? '' : $path) . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');

        return $endpoint === '/' ? '' : $endpoint;
    }

    /**
     * Can this URL be fetched at all? Returns [ok, reason-when-not].
     *
     * @return array{0:bool,1:?string}
     */
    public static function fetchable(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return [false, 'unparseable'];
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return [false, 'scheme-not-http'];   // mailto:, file:, javascript:, ftp: …
        }
        if (empty($parts['host'])) {
            return [false, 'no-host'];
        }
        $host = strtolower((string) $parts['host']);
        if (VideoSources::match($url) !== null) {
            // A video is not a page. Marked here so the page path can never scrape it and hand the scrape over
            // as the video's content; the video pipeline claims the URL, and the URL stays a citation anchor.
            return [false, 'video'];
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return [false, 'localhost'];
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false && !self::isPublicIp($host)) {
            return [false, 'private-host'];
        }
        return [true, null];
    }

    private static function isPublicIp(string $ip): bool
    {
        // filter_var with these flags rejects private and reserved ranges in one call.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
