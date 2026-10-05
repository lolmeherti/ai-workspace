<?php

namespace App\Search;

/**
 * Per-source outcomes for the chat source panel.
 *
 * The panel used to show successes only, so a page the bridge could not read — or one it read and got nothing
 * usable from — simply disappeared from the turn. The agreed behaviour (browsing-rework problemset, P4-S2) is
 * that the user is told which sources failed and why, with the reason rendered by the UI and phrased by nobody:
 * the model never sees these URLs, and no label here comes from a model.
 *
 * Labels live on the server so the live payload and the reloaded view cannot drift apart, and so a status the
 * bridge adds later degrades to a readable string instead of an empty cell.
 */
final class SourceFailures
{
    /**
     * status => [short label, tooltip, solvable by the user]
     *
     * "Solvable" is the P4-S2 distinction: a consent wall or a CAPTCHA is something the human can clear in
     * their own browser, a geo-block or a thin page is not.
     */
    private const REASONS = [
        'challenge_required' => ['needs your attention in the browser', 'The site asked for a CAPTCHA or verification. The tab is open — clear it there and ask again.', true],
        'consent_required'   => ['needs a consent click in the browser', 'A cookie or consent wall blocked the content. Accept it in the tab and ask again.', true],
        'login_required'     => ['needs a sign-in', 'The page requires being logged in. Sign in there and ask again.', true],
        'paywall'            => ['behind a paywall', 'The article is paywalled, so only what was public could be read.', true],
        'rejected'           => ['the site refused the request', 'The server rejected the fetch (blocked, forbidden or rate-limited).', false],
        'http_error'         => ['the site returned an error', 'The address answered with an HTTP error.', false],
        'timeout'            => ['timed out', 'The page did not finish loading in time.', true],
        'disconnected'       => ['the browser bridge was not connected', 'The browser bridge was closed, so nothing could be opened.', true],
        'ws_error'           => ['the browser bridge dropped', 'The connection to the browser bridge failed mid-fetch.', true],
        'no_usable_content'  => ['read, but no usable content', 'The page was fetched but contributed nothing the answer could use — too little text, or it was ranked out.', false],
        'error'              => ['could not be read', 'The fetch failed for an unclassified reason.', false],
    ];

    public static function label(?string $status): string
    {
        return self::REASONS[(string) $status][0] ?? 'could not be read';
    }

    public static function tooltip(?string $status, ?string $detail = null): string
    {
        $base = self::REASONS[(string) $status][1] ?? 'The fetch failed for an unclassified reason.';
        return $detail !== null && trim($detail) !== '' ? $base . ' (' . trim($detail) . ')' : $base;
    }

    public static function solvable(?string $status): bool
    {
        return (bool) (self::REASONS[(string) $status][2] ?? false);
    }

    /** One entry for the UI, from a raw fetch failure. */
    public static function entry(string $url, ?string $status, ?string $detail = null, ?string $title = null): array
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        return [
            'url'      => $url,
            'domain'   => $host,
            'title'    => (string) ($title ?? ''),
            'status'   => (string) $status,
            'label'    => self::label($status),
            'tooltip'  => self::tooltip($status, $detail),
            'solvable' => self::solvable($status),
        ];
    }

    /**
     * Sources that were fetched and registered but produced no evidence block at all.
     *
     * Derived, not stored: the evidence names the sources that actually reached the model, so a source in the
     * map with no block is one the answer never saw (measured case: a page that extracted 60 chars and was
     * ranked out). No new fetches, no new bookkeeping.
     *
     * @param array<string, array{url?: string}> $sourceMap
     */
    public static function unused(array $sourceMap, string $evidence): array
    {
        if ($sourceMap === []) {
            return [];
        }
        $present = [];
        if (preg_match_all('/<source\s+id="([^"]+)"/', $evidence, $m)) {
            $present = $m[1];
        }

        $out = [];
        foreach ($sourceMap as $sid => $meta) {
            $url = (string) ($meta['url'] ?? '');
            if ($url === '' || in_array((string) $sid, $present, true)) {
                continue;
            }
            // Carry the page's title so the panel can say which page this was. A turn that searched twice can
            // register several pages on one domain, and a row that only names the domain tells them apart no
            // better than a list of identical lines.
            $out[] = self::entry($url, 'no_usable_content', null, (string) ($meta['title'] ?? ''));
        }
        return $out;
    }

    /**
     * The sources the panel shows as successful: the fetched map minus every entry it also lists as failed,
     * and one row per page.
     *
     * The map is "everything this turn fetched", so two things have to come out of it before it can be shown:
     * a page that was read and contributed nothing (it appears under Failed sources instead), and the second
     * id of a page this turn fetched twice — a turn with two searches registers the same URL under two ids,
     * and listing it twice reads as two different sources.
     *
     * @param array<string, array{url?: string}> $sourceMap
     * @param array<int, array<string, mixed>> $failed
     * @return array<string, array<string, mixed>>
     */
    public static function used(array $sourceMap, array $failed): array
    {
        $failedUrls = [];
        foreach ($failed as $f) {
            $url = (string) ($f['url'] ?? '');
            if ($url !== '') {
                $failedUrls[$url] = true;
            }
        }

        $out = [];
        $seen = [];
        foreach ($sourceMap as $sid => $meta) {
            $url = (string) ($meta['url'] ?? '');
            if ($url !== '') {
                if (isset($failedUrls[$url]) || isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
            }
            $out[$sid] = $meta;
        }
        return $out;
    }

    /**
     * The panel's page rows as site groups: one entry per domain, the pages it answered with inside it, in the
     * order the turn read them.
     *
     * Several pages on one site are one category, not several sources — a turn that reads three huggingface.co
     * pages has one source, huggingface.co, listed once with three endpoints. The view shows a header only for
     * a site that answered more than once, so the ordinary one-page case gains no chrome.
     *
     * @param array<string, array{url?: string, title?: string, domain?: string}> $sources
     * @return array<int, array{site: string, pages: array<int, array<string, mixed>>}>
     */
    public static function groupBySite(array $sources): array
    {
        $groups = [];
        $index = [];

        foreach ($sources as $source) {
            $site = (string) ($source['domain'] ?? '');
            if ($site === '') {
                $site = (string) (parse_url((string) ($source['url'] ?? ''), PHP_URL_HOST) ?: '');
            }
            $site = preg_replace('/^www\./i', '', $site) ?? $site;

            // A source with no site of its own (a memory, an attached file) stays its own group, so it never
            // inherits a domain heading it does not belong to.
            $key = $site !== '' ? $site : "\0entry" . count($groups);
            if (!isset($index[$key])) {
                $index[$key] = count($groups);
                $groups[] = ['site' => $site, 'pages' => []];
            }
            $groups[$index[$key]]['pages'][] = $source;
        }

        return $groups;
    }

    /**
     * The failed list the panel renders: fetch failures first, then sources that were read but contributed
     * nothing, de-duplicated by identity so one page cannot appear twice.
     *
     * @param array<int, array{url?: string, status?: string, reason?: ?string, error?: ?string}> $fetchFailures
     * @param array<string, array{url?: string}> $sourceMap
     * @return array<int, array<string, mixed>>
     */
    public static function finalize(array $fetchFailures, array $sourceMap, string $evidence): array
    {
        $out = [];
        $seen = [];

        foreach ($fetchFailures as $f) {
            $url = (string) ($f['url'] ?? '');
            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $out[] = self::entry($url, (string) ($f['status'] ?? 'error'), $f['reason'] ?? $f['error'] ?? null);
        }

        foreach (self::unused($sourceMap, $evidence) as $u) {
            if (isset($seen[$u['url']])) {
                continue;
            }
            $seen[$u['url']] = true;
            $out[] = $u;
        }

        return $out;
    }
}
