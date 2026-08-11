<?php

namespace App;

use DOMDocument;
use App\Search\FetchSafety;
use App\Search\FetchResult;
use App\Search\OutboundScheduler;

class Scraper
{
    private static ?OutboundScheduler $scheduler = null;

    private static function scheduler(): OutboundScheduler
    {
        return self::$scheduler ??= new OutboundScheduler();
    }
    /**
     * Fetch via direct curl first. Fall back to FlareSolverr only
     * when direct fetch fails, returns a challenge page, or produces
     * insufficient content. Records fetch method for coverage measurement.
     *
     * @param string $targetUrl The URL to scrape
     * @param string &$fetchMethod Out-param: 'curl' or 'flaresolverr'
     * @return string The cleaned, truncated text
     */
    public static function fetchAndClean(string $targetUrl, ?int $maxTokens = null, ?string &$fetchMethod = null): string
    {
        // 2b: Try direct curl first
        try {
            $result = FetchSafety::safeFetchUrl($targetUrl);
            if (FetchSafety::isUseful($result)) {
                $fetchMethod = 'curl';
                return self::cleanAndTruncate($result->body, $maxTokens);
            }
        } catch (\App\Search\UnsafeUrlException $e) {
            // URL validation failed — don't fall through to FlareSolverr
            // (private IP, credentials, bad scheme, etc.)
            error_log("Scraper: unsafe URL rejected — {$e->getMessage()}");
            return "";
        } catch (\App\Search\FetchException $e) {
            // DNS failure, too many redirects, curl error — fall through to FlareSolverr
            error_log("Scraper: direct fetch failed for {$targetUrl} — {$e->getMessage()}, falling back to FlareSolverr");
        }

        // Fall back to FlareSolverr
        $fetchMethod = 'flaresolverr';
        return self::fetchViaFlareSolverr($targetUrl, $maxTokens);
    }

    /**
     * Fetch raw HTML body via HTTP-first + FlareSolverr fallback.
     * Returns FetchResult on success, null when both paths fail.
     * Same safety logic as fetchAndClean() — no strip/truncate.
     */
    public static function fetchRaw(string $targetUrl, ?string &$fetchMethod = null): ?FetchResult
    {
        $host = parse_url($targetUrl, PHP_URL_HOST) ?: 'unknown';
        self::scheduler()->waitForSlot($host);

        try {
            $result = FetchSafety::safeFetchUrl($targetUrl);
            if (FetchSafety::isUseful($result)) {
                $fetchMethod = 'curl';
                return $result;
            }
        } catch (\App\Search\UnsafeUrlException $e) {
            error_log("Scraper: unsafe URL rejected — {$e->getMessage()}");
            return null;
        } catch (\App\Search\FetchException $e) {
            error_log("Scraper: direct fetch failed for {$targetUrl} — {$e->getMessage()}, falling back to FlareSolverr");
        }

        $fetchMethod = 'flaresolverr';
        $html = self::fetchViaFlareSolverrRaw($targetUrl);
        if ($html === null || empty(trim($html))) {
            return null;
        }

        return new FetchResult(
            statusCode: 200,
            body: $html,
            finalUrl: $targetUrl,
            resolvedIp: '',
            contentType: 'text/html',
        );
    }

    /**
     * Fetch via FlareSolverr, return raw HTML string (no cleaning).
     */
    private static function fetchViaFlareSolverrRaw(string $targetUrl): ?string
    {
        $flareHost = rtrim(getenv('FLARESOLVERR_HOST') ?: 'http://flaresolverr:8191', '/');
        $endpoint = $flareHost . '/v1';

        $payload = json_encode([
            "cmd" => "request.get",
            "url" => $targetUrl,
            "maxTimeout" => 15000,
            "disableMedia" => true
        ]);

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);
        return $data['solution']['response'] ?? null;
    }

    /**
     * Fetch via FlareSolverr (headless Chromium). Used as fallback when
     * direct curl fails, returns a challenge page, or the page requires JS.
     */
    private static function fetchViaFlareSolverr(string $targetUrl, ?int $maxTokens = null): string
    {
        $flareHost = rtrim(getenv('FLARESOLVERR_HOST') ?: 'http://flaresolverr:8191', '/');
        $endpoint = $flareHost . '/v1';

        $payload = json_encode([
            "cmd" => "request.get",
            "url" => $targetUrl,
            "maxTimeout" => 15000,
            "disableMedia" => true
        ]);

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return ""; 
        }

        $data = json_decode($response, true);
        if (!isset($data['solution']['response'])) {
            return "";
        }

        $html = $data['solution']['response'];

        return self::cleanAndTruncate($html, $maxTokens);
    }

    /**
     * Surgically removes layout tags, strips HTML, and enforces the token limit.
     */
    private static function cleanAndTruncate(string $html, ?int $maxTokens = null): string
    {
        if (empty(trim($html))) {
            return "";
        }

        $dom = new DOMDocument();
        
        libxml_use_internal_errors(true);
        
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOBLANKS | LIBXML_NOERROR);
        libxml_clear_errors();

        $tagsToRemove = [
            // Layout & Boilerplate
            'script', 'style', 'nav', 'header', 'footer', 
            'aside', 'menu', 'noscript', 'form', 
            // Media & Embeds (New additions)
            'img', 'video', 'picture', 'source', 'svg', 
            'canvas', 'embed', 'object', 'iframe', 'audio', 'track'
        ];

        foreach ($tagsToRemove as $tag) {
            $elements = $dom->getElementsByTagName($tag);
            for ($i = $elements->length - 1; $i >= 0; $i--) {
                $node = $elements->item($i);
                if ($node && $node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $cleanedHtml = $dom->saveHTML();

        $text = strip_tags($cleanedHtml);

        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        $maxTokens = $maxTokens ?? (int)(getenv('MAX_SCRAPE_TOKENS') ?: 2500);
        $maxCharacters = $maxTokens * 4;

        if (mb_strlen($text) > $maxCharacters) {
            $text = mb_substr($text, 0, $maxCharacters) . '... [TRUNCATED]';
        }

        return $text;
    }
}