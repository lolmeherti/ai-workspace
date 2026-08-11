<?php
/**
 * Phase 0: Frozen Evaluation Fixture Capture Script
 *
 * Captures the existing search pipeline's outputs for a single query.
 * Uses reflection to access private pipeline methods — no pipeline code changes.
 *
 * CLI usage (inside Docker container):
 *   php capture.php --query-id=specs-iphone --question="What are the..."
 *
 * Programmatic usage:
 *   require_once 'capture.php';
 *   captureFixture('specs-iphone', 'What are the...');
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\AgentManager;
use App\Config;
use App\Agents\ContextCondenser;

/**
 * Capture all pipeline outputs for a query into src/tests/search-eval/<queryId>/
 *
 * @return array{success: bool, fixture_dir: string, error?: string}
 */
function captureFixture(string $queryId, string $question): array
{
    Config::load(__DIR__ . '/../..');

    $fixtureDir = __DIR__ . '/' . $queryId;
    $rawPagesDir = $fixtureDir . '/raw-pages';
    $extractedDir = $fixtureDir . '/extracted-text';

    @mkdir($fixtureDir, 0777, true);
    @mkdir($rawPagesDir, 0777, true);
    @mkdir($extractedDir, 0777, true);

    echo "=== Phase 0 Capture: {$queryId} ===\n";

    try {
        // ── 1. Save original question ────────────────────────────────────────
        file_put_contents($fixtureDir . '/original-question.txt', $question);

        // ── 2. Generate search query via LLM ─────────────────────────────────
        $agent = new AgentManager();

        $systemPrompt = <<<'PROMPT'
You extract search-engine keywords from user questions.
Output ONLY space-delimited keywords. No quotes, punctuation, line breaks,
or any other text — just the raw keywords separated by single spaces.
Example: "iPhone 16 Pro camera specifications megapixels sensor"
PROMPT;

        $searchQueryRaw = $agent->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $question],
        ], false, null, 0.2);

        $searchQuery = trim(preg_replace('/["\'\n\r`]/', ' ', $searchQueryRaw));
        $searchQuery = preg_replace('/\s+/', ' ', $searchQuery);
        $searchQuery = trim($searchQuery);

        file_put_contents($fixtureDir . '/search-query.txt', $searchQuery);
        echo "  search-query: \"{$searchQuery}\"\n";

        // ── 3. Query SearXNG + capture full JSON response ────────────────────
        $searxngHost = rtrim(getenv('SEARXNG_HOST') ?: 'http://searxng:8080', '/');
        $searxngUrl = $searxngHost . '/search?q=' . urlencode($searchQuery) . '&format=json';

        $ch = curl_init($searxngUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $searxngRaw = curl_exec($ch);
        $searxngHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($searxngHttpCode !== 200 || !$searxngRaw) {
            throw new \RuntimeException("SearXNG returned HTTP {$searxngHttpCode}");
        }

        $searxngPretty = json_encode(json_decode($searxngRaw, true),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($fixtureDir . '/searxng-response.json', $searxngPretty);
        echo "  searxng-response: " . strlen($searxngRaw) . " bytes\n";

        // ── 4. Extract URLs ──────────────────────────────────────────────────
        $searxngData = json_decode($searxngRaw, true);
        $results = $searxngData['results'] ?? [];
        $limit = (int) Config::get('MAX_SEARCH_RESULTS_TO_SCRAPE', 3);
        $urls = [];

        foreach ($results as $result) {
            if (isset($result['url'])) {
                $urls[] = $result['url'];
            }
            if (count($urls) >= $limit) break;
        }

        file_put_contents($fixtureDir . '/urls-selected.txt', implode("\n", $urls) . "\n");
        echo "  urls-selected: " . count($urls) . " URLs\n";

        if (empty($urls)) {
            throw new \RuntimeException("No URLs found in SearXNG response");
        }

        // ── 5+6. Fetch raw pages AND extract text for each URL ───────────────
        $flareHost = rtrim(getenv('FLARESOLVERR_HOST') ?: 'http://flaresolverr:8191', '/');
        $flareEndpoint = $flareHost . '/v1';
        $maxTokens = (int)(getenv('MAX_SCRAPE_TOKENS') ?: 2500);

        $cleanMethod = new \ReflectionMethod(\App\Scraper::class, 'cleanAndTruncate');
        $cleanMethod->setAccessible(true);

        $scrapedPages = [];

        foreach ($urls as $i => $url) {
            $urlHash = hash('sha256', $url);
            $idx = $i + 1;

            // Call FlareSolverr
            $payload = json_encode([
                'cmd' => 'request.get',
                'url' => $url,
                'maxTimeout' => 15000,
                'disableMedia' => true,
            ]);

            $ch = curl_init($flareEndpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 25,
            ]);
            $flareRaw = curl_exec($ch);
            curl_close($ch);

            $flareData = json_decode($flareRaw, true);
            $solution = $flareData['solution'] ?? [];
            $rawHtml = $solution['response'] ?? '';
            $finalUrl = $solution['url'] ?? $url;
            $statusCode = $solution['status'] ?? 0;
            $responseHeaders = $solution['headers'] ?? [];

            // Detect content-type from response headers
            $contentType = 'unknown';
            $charset = null;
            if (is_array($responseHeaders)) {
                foreach ($responseHeaders as $key => $val) {
                    $keyLower = is_string($key) ? strtolower($key) : '';
                    if ($keyLower === 'content-type') {
                        $contentType = is_array($val) ? ($val[0] ?? 'unknown') : (string)$val;
                        if (preg_match('/charset=([^\s;]+)/i', $contentType, $m)) {
                            $charset = trim($m[1], '"\'');
                        }
                        break;
                    }
                }
            }

            // Save raw page metadata + body
            $rawPageMeta = [
                'requested_url' => $url,
                'final_url' => $finalUrl,
                'http_status' => $statusCode,
                'headers' => $responseHeaders,
                'content_type' => $contentType,
                'charset' => $charset,
                'fetch_method' => 'flaresolverr',
                'fetched_at' => date('c'),
                'content_hash' => hash('sha256', $rawHtml),
                'body_base64' => base64_encode($rawHtml),
            ];

            file_put_contents(
                $rawPagesDir . '/' . $urlHash . '.json',
                json_encode($rawPageMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            // Extract text using existing pipeline (reflection for private method)
            $extractedText = '';
            if (!empty($rawHtml)) {
                $extractedText = $cleanMethod->invoke(null, $rawHtml, $maxTokens);
            }
            file_put_contents($extractedDir . '/' . $urlHash . '.txt', $extractedText);

            if (!empty(trim($extractedText))) {
                $scrapedPages[] = "[Source: {$url}]\n\n" . $extractedText;
            }

            echo "  url {$idx}/" . count($urls) . ": {$urlHash} (" . strlen($rawHtml) . "B body, " . strlen($extractedText) . " chars extracted)\n";
        }

        // ── 7. Run ContextCondenser ──────────────────────────────────────────
        $condenser = new ContextCondenser($agent);
        $condensedOutput = '';

        if (!empty($scrapedPages)) {
            $condensedOutput = $condenser->condense($scrapedPages, $searchQuery);
        }

        file_put_contents($fixtureDir . '/condenser-output.txt', $condensedOutput);
        echo "  condenser-output: " . strlen($condensedOutput) . " chars\n";

        // ── 8. Generate final answer via LLM ─────────────────────────────────
        if (empty($condensedOutput)) {
            $finalAnswer = "Web search for '{$searchQuery}' returned no usable content.";
        } else {
            $answerSystem = <<<'PROMPT'
You are a helpful AI assistant. Answer the user's question using the provided web
search results. Be factual. If the results are incomplete or conflicting, say so.
PROMPT;

            $finalAnswer = $agent->chat([
                ['role' => 'system', 'content' => $answerSystem],
                ['role' => 'user', 'content' =>
                    "WEB SEARCH RESULTS:\n\n{$condensedOutput}\n\n" .
                    "USER QUESTION: {$question}\n\n" .
                    "Provide a thorough answer based on the search results above."
                ],
            ], false, null, 0.5);
        }

        file_put_contents($fixtureDir . '/current-answer.txt', $finalAnswer);
        echo "  current-answer: " . strlen($finalAnswer) . " chars\n";

    } catch (\Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
        return ['success' => false, 'fixture_dir' => $fixtureDir, 'error' => $e->getMessage()];
    }

    return ['success' => true, 'fixture_dir' => $fixtureDir];
}

// ── CLI entry point ──────────────────────────────────────────────────────────

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    $opts = getopt('', ['query-id:', 'question:']);
    $queryId = $opts['query-id'] ?? null;
    $question = $opts['question'] ?? null;

    if (!$queryId || !$question) {
        fwrite(STDERR, "Usage: php capture.php --query-id=<id> --question=\"<question>\"\n");
        exit(1);
    }

    $result = captureFixture($queryId, $question);

    if ($result['success']) {
        echo "\n=== Capture complete: {$queryId} ===\n";
    } else {
        fwrite(STDERR, "Capture failed: {$result['error']}\n");
        exit(1);
    }
}
