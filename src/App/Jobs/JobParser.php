<?php

namespace App\Jobs;

use App\AgentManager;
use App\JsonParser;
use App\Logger;
use App\Search\BridgeFetcher;
use App\Search\BridgeFetchResult;
use App\Search\HostCooldown;
use DOMDocument;

class JobParser
{
    public const MAX_PAGE_CHARS = 8000;

    private const TEMPERATURE = 0.3;

    private ?string $lastFailureReason = null;

    private const PARSE_SYSTEM_PROMPT = <<<'PROMPT'
You extract structured job-posting data from a scraped web page. Output a single JSON object with exactly these keys: title (string), company (string), posted_at (an absolute date in YYYY-MM-DD format; if the page shows a relative date such as '3 days ago' or 'Vor 3 Tagen', convert it to an absolute date using the current date provided in the input; null if the page shows no date), description (string), location (string or null), city (string or null), country (string or null), work_mode ("remote", "hybrid", or "on_site", or null), employment_type (string or null), salary (string or null), applicant_count (string or null), is_listing (boolean). If the page is primarily a list of multiple separate job postings (search results, a category page, or a company's job-list page), set is_listing to true and leave every other field null. If it is a single job posting, set is_listing to false and fill the fields for that one job (a single posting that also shows related jobs at the bottom is still a single job). If city or country is not present on the page, take your best guess from the URL domain, page language, or location/description text rather than leaving it null. For all other fields, leave null rather than inventing it. Do not add commentary. Output only the JSON object.
PROMPT;

    public function __construct(
        private AgentManager $agentManager,
        private ?BridgeFetcher $bridgeFetcher = null,
    ) {
    }

    public function parse(string $url, ?string $prefetchedText = null): ?array
    {
        $this->lastFailureReason = null;

        $text = $prefetchedText ?? $this->fetchText($url);
        if ($text === null) {
            $this->lastFailureReason = 'fetch failed';
            return null;
        }

        $response = $this->agentManager->chat([
            ['role' => 'system', 'content' => self::PARSE_SYSTEM_PROMPT],
            ['role' => 'user', 'content' => "Today's date: " . date('Y-m-d') . "\n\nURL: {$url}\n\nPAGE:\n{$text}"],
        ], false, null, self::TEMPERATURE);

        $record = JsonParser::extractAndDecode($response);
        if ($record === null) {
            $this->lastFailureReason = 'invalid JSON from LLM';
            Logger::logEvent('job_parse_invalid_json', 'JobParser: LLM returned no decodable JSON', [
                'url' => $url,
                'response_preview' => mb_substr($response, 0, 500),
            ], 'warn', 'JobParser::parse');
            return null;
        }

        if (filter_var($record['is_listing'] ?? false, FILTER_VALIDATE_BOOL)) {
            $this->lastFailureReason = 'listing page';
            Logger::logEvent('job_parse_listing', 'JobParser: page is a listing, not a single job', [
                'url' => $url,
            ], 'info', 'JobParser::parse');
            return null;
        }

        $normalized = self::validateRecord($record, $url);
        if ($normalized === null) {
            $this->lastFailureReason = self::describeRecordFailure($record, $url);
            Logger::logEvent('job_parse_invalid_record', 'JobParser: record failed validation', [
                'url' => $url,
                'record' => $record,
            ], 'warn', 'JobParser::parse');
            return null;
        }

        return $normalized;
    }

    public function lastFailureReason(): ?string
    {
        return $this->lastFailureReason;
    }

    public static function validateRecord(array $record, string $url): ?array
    {
        if (filter_var($record['is_listing'] ?? false, FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $title = self::cleanString($record['title'] ?? null);
        $company = self::cleanString($record['company'] ?? null);
        $postedAt = self::normalizePostedAt($record['posted_at'] ?? null);

        if ($title === null || $company === null || $postedAt === null) {
            return null;
        }

        $domain = parse_url($url, PHP_URL_HOST);
        if (!is_string($domain) || $domain === '') {
            return null;
        }

        $workMode = self::normalizeWorkMode($record['work_mode'] ?? null);
        $city = self::cleanString($record['city'] ?? null);
        $country = self::cleanString($record['country'] ?? null);

        if ($workMode !== null && $workMode !== 'remote' && $city === null && $country === null) {
            return null;
        }

        return [
            'source_domain' => $domain,
            'url' => $url,
            'posted_at' => $postedAt,
            'title' => $title,
            'company' => $company,
            'description' => self::cleanMultiline($record['description'] ?? null) ?? '',
            'location' => self::cleanString($record['location'] ?? null),
            'city' => $city,
            'country' => $country,
            'work_mode' => $workMode,
            'employment_type' => self::cleanString($record['employment_type'] ?? null),
            'salary' => self::cleanString($record['salary'] ?? null),
            'applicant_count' => self::cleanString($record['applicant_count'] ?? null) ?? 'Unknown',
            'metadata' => is_array($record['metadata'] ?? null) ? $record['metadata'] : null,
        ];
    }

    private static function describeRecordFailure(array $record, string $url): string
    {
        if (self::cleanString($record['title'] ?? null) === null) {
            return 'missing title';
        }
        if (self::cleanString($record['company'] ?? null) === null) {
            return 'missing company';
        }
        if (self::normalizePostedAt($record['posted_at'] ?? null) === null) {
            return 'missing/invalid posted_at';
        }
        if (!is_string(parse_url($url, PHP_URL_HOST))) {
            return 'invalid URL';
        }
        return 'missing city/country for non-remote role';
    }

    public static function normalizeHtml(string $html, int $maxChars = self::MAX_PAGE_CHARS): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOBLANKS | LIBXML_NOERROR);
        libxml_clear_errors();

        foreach (['script', 'style', 'nav', 'header', 'footer', 'aside', 'form', 'noscript', 'svg'] as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                if ($node !== null && $node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $text = strip_tags($dom->saveHTML());
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);

        if (mb_strlen($text) > $maxChars) {
            $text = mb_substr($text, 0, $maxChars) . ' ... [TRUNCATED]';
        }

        return $text;
    }

    public static function extractBridgeText(?array $content): ?string
    {
        if ($content === null) {
            return null;
        }

        $body = null;
        $entities = $content['entities'] ?? null;
        if (is_array($entities) && isset($entities[0]['body']) && is_string($entities[0]['body'])) {
            $body = trim($entities[0]['body']);
        }
        if (($body === null || $body === '') && isset($content['body']) && is_string($content['body'])) {
            $body = trim($content['body']);
        }
        if ($body === null || $body === '') {
            return null;
        }

        $datePosted = $content['date_posted'] ?? null;
        if (is_string($datePosted) && trim($datePosted) !== '') {
            $body = 'Posted date: ' . trim($datePosted) . "\n\n" . $body;
        }

        return mb_substr($body, 0, self::MAX_PAGE_CHARS);
    }

    public function fetchText(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: 'unknown';
        $result = new BridgeFetchResult('disconnected', null, 'bridge not connected');

        $bridge = $this->bridgeFetcher ?? new BridgeFetcher();
        if ($bridge->isConnected()) {
            $result = $bridge->fetch($url);
            if ($result->isSuccess()) {
                $text = self::extractBridgeText($result->content);
                if ($text !== null) {
                    return $text;
                }
            }
            if ($result->status === 'cooldown' || HostCooldown::isBlockSignal($result->status)) {
                return null;
            }
        }

        if ((new HostCooldown())->isCoolingDown($host)) {
            return null;
        }

        Logger::logEvent('job_parse_fetch_failed', 'JobParser: bridge could not fetch page text', [
            'url' => $url,
            'bridge_status' => $result->status,
            'bridge_error' => $result->error,
        ], 'warn', 'JobParser::parse');

        return null;
    }

    private static function cleanString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        return $value === '' ? null : $value;
    }

    private static function cleanMultiline(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[ \t]+/', ' ', $value);
        $value = preg_replace('/ ?\n ?/', "\n", $value);
        $value = preg_replace('/\n{3,}/', "\n\n", $value);
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private static function normalizePostedAt(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        return date(self::hasExplicitTime($value) ? 'Y-m-d H:i:s' : 'Y-m-d', $timestamp);
    }

    private static function hasExplicitTime(string $value): bool
    {
        return preg_match('/\d{1,2}:\d{2}/', $value) === 1;
    }

    private static function normalizeWorkMode(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = mb_strtolower(trim($value));
        return match (true) {
            $value === 'remote' => 'remote',
            $value === 'hybrid' => 'hybrid',
            in_array($value, ['on_site', 'on-site', 'onsite', 'on site'], true) => 'on_site',
            default => null,
        };
    }
}
