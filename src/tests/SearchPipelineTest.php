<?php

declare(strict_types=1);

namespace App\Tests;

use App\AgentManager;
use App\Cache;
use App\Database;
use App\Agents\SemanticCacheEvaluator;
use App\Scraper;
use App\Services\Tools\SearchWebTool;
use App\Services\Tools\TodoistApiClient;

class SearchPipelineTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    private ?string $origCtxSize;

    public function __construct(
        private ?Database $db = null,
        private ?AgentManager $agent = null,
        private string $uploadDir = '',
    ) {}

    public function run(): bool
    {
        $this->runCalculateScrapeBudget();
        $this->runScraperClean();
        $this->runCacheRouting();

        echo "\n" . str_repeat('=', 55) . "\n";
        printf("Results: %d passed, %d failed, %d total\n", $this->passed, $this->failed, $this->passed + $this->failed);

        if (!empty($this->failures)) {
            echo "\nFAILURES:\n";
            foreach ($this->failures as $f) {
                echo "  - {$f['label']}\n";
            }
            echo "\nSOME TESTS FAILED\n";
        } else {
            echo "ALL TESTS PASSED\n";
        }

        return empty($this->failures);
    }

    // ==================================================================
    // Phase A: calculateScrapeBudgetStatic
    // ==================================================================
    private function runCalculateScrapeBudget(): void
    {
        echo "\n=== calculateScrapeBudgetStatic ===\n";

        $this->saveCtxSize();
        unset($_ENV['LLM_CTX_SIZE']); // force Config default (32768) for first batch

        // -- Default config (LLM_CTX_SIZE = 32768) --

        $this->testEq(
            'empty messages, 1 URL → 15000 (ceiling)',
            15000,
            $this->invokeBudget([], 1)
        );

        $this->testEq(
            'empty messages, 3 URLs → 15000 (ceiling, moderate split)',
            15000,
            $this->invokeBudget([], 3)
        );

        $this->testEq(
            'empty messages, 8 URLs → 13824 (proportional)',
            13824,
            $this->invokeBudget([], 8)
        );

        $this->testEq(
            'empty messages, 50 URLs → 2500 (floor from division)',
            2500,
            $this->invokeBudget([], 50)
        );

        // near-full context: remaining just under 10000 → floor
        // 32768 - consumed - 5120 = 9999  → consumed = 17649 tokens = 70596 chars
        $this->testEq(
            'near-full context (remaining 9999) → 2500 (floor)',
            2500,
            $this->invokeBudget([$this->msg(str_repeat('x', 70596))], 1)
        );

        // exactly at threshold: remaining = 10000 → NOT floored
        // 32768 - consumed - 5120 = 10000 → consumed = 17648 tokens = 70592 chars
        $this->testEq(
            'exactly at threshold (remaining 10000) → 15000 (not floored)',
            15000,
            $this->invokeBudget([$this->msg(str_repeat('x', 70592))], 1)
        );

        $this->testEq(
            'two short messages, 1 URL → 15000 (ceiling)',
            15000,
            $this->invokeBudget([$this->msg('hello world'), $this->msg('test')], 1)
        );

        // half context: consumed = 16384 tokens = 65536 chars
        $this->testEq(
            'half-full context, 2 URLs → 15000 (ceiling)',
            15000,
            $this->invokeBudget([$this->msg(str_repeat('x', 65536))], 2)
        );

        // partial fill with 10 URLs → proportional
        // consumed = 5000 tokens = 20000 chars
        // remaining = 32768 - 5000 - 5120 = 22648, rawBudget = 90592, /10 = 9059
        $this->testEq(
            '5000 tokens consumed, 10 URLs → 9059',
            9059,
            $this->invokeBudget([$this->msg(str_repeat('x', 20000))], 10)
        );

        $this->testEq(
            'message without content key → treated as empty',
            15000,
            $this->invokeBudget([['role' => 'user']], 1)
        );

        // Multimodal message (content is array of parts — text + image_url)
        $this->testEq(
            'multimodal message → sums text parts only',
            15000,
            $this->invokeBudget([[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'describe this image'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,xxx']],
                ]
            ]], 1)
        );

        // -- Config override: LLM_CTX_SIZE = 65536 --
        $this->setCtxSize('65536');

        $this->testEq(
            'CTX=65536, empty messages, 1 URL → 15000 (ceiling)',
            15000,
            $this->invokeBudget([], 1)
        );

        $this->testEq(
            'CTX=65536, empty messages, 20 URLs → 12083 (proportional)',
            12083,
            $this->invokeBudget([], 20)
        );

        $this->restoreCtxSize();
    }

    // ==================================================================
    // Phase B: Scraper::cleanAndTruncate
    // ==================================================================
    private function runScraperClean(): void
    {
        echo "\n=== Scraper::cleanAndTruncate ===\n";

        $fixture = $this->loadFixture();

        // 1. Empty input → empty output
        $this->testEq('empty HTML → empty string', '', $this->invokeClean(''));

        // 2. Whitespace-only → empty (early return before DOM parsing)
        $this->testEq('whitespace-only → empty', '', $this->invokeClean("  \n  \t  "));

        // 3. Layout tags stripped (script, style, nav, header, footer, aside)
        $layoutHtml = '<html><body>'
            . '<script>console.log("rm")</script>'
            . '<style>.x{}</style>'
            . '<nav>Nav links</nav>'
            . '<header>Site header</header>'
            . '<footer>Copyright</footer>'
            . '<aside>Sidebar</aside>'
            . '<p>Surviving text</p>'
            . '<main>Main area</main>'
            . '<article>Article body</article>'
            . '</body></html>';
        $layoutOut = $this->invokeClean($layoutHtml);
        $this->testNotContains('layout: script text removed', 'console.log', $layoutOut);
        $this->testNotContains('layout: style text removed', '.x{}', $layoutOut);
        $this->testNotContains('layout: nav text removed', 'Nav links', $layoutOut);
        $this->testNotContains('layout: header text removed', 'Site header', $layoutOut);
        $this->testNotContains('layout: footer text removed', 'Copyright', $layoutOut);
        $this->testNotContains('layout: aside text removed', 'Sidebar', $layoutOut);
        $this->testContains('layout: paragraph preserved', 'Surviving text', $layoutOut);
        $this->testContains('layout: main preserved', 'Main area', $layoutOut);
        $this->testContains('layout: article preserved', 'Article body', $layoutOut);

        // 4. Media tags stripped (img, video, svg, canvas, iframe)
        $mediaHtml = '<html><body>'
            . '<img src="x.jpg" alt="alt text">'
            . '<video>video fallback</video>'
            . '<svg><text>svg label</text></svg>'
            . '<canvas>canvas fallback</canvas>'
            . '<iframe>iframe content</iframe>'
            . '<p>Real content</p>'
            . '</body></html>';
        $mediaOut = $this->invokeClean($mediaHtml);
        $this->testNotContains('media: img alt stripped', 'alt text', $mediaOut);
        $this->testNotContains('media: video stripped', 'video fallback', $mediaOut);
        $this->testNotContains('media: svg stripped', 'svg label', $mediaOut);
        $this->testNotContains('media: canvas stripped', 'canvas fallback', $mediaOut);
        $this->testNotContains('media: iframe stripped', 'iframe content', $mediaOut);
        $this->testContains('media: real content preserved', 'Real content', $mediaOut);

        // 5. Whitespace collapse
        $wsHtml = "<p>line1</p>\n\n\n<p>line2</p>\t\t<p>line3</p>";
        $wsOut = $this->invokeClean($wsHtml);
        $this->testNotContains('whitespace: no double spaces', '  ', $wsOut);
        $this->testNotContains('whitespace: no tabs', "\t", $wsOut);
        $this->testContains('whitespace: line1 present', 'line1', $wsOut);
        $this->testContains('whitespace: line2 present', 'line2', $wsOut);
        $this->testContains('whitespace: line3 present', 'line3', $wsOut);

        // 6. Real page produces output
        $full = $this->invokeClean($fixture);
        $this->testTrue('fixture: produces non-empty output', $full !== '');

        // 7. Truncation at 20 tokens (80 chars) — output ≤ 95 (80 + 15 for suffix)
        $short = $this->invokeClean($fixture, 20);
        $this->testTrue('truncation: at most 95 chars', mb_strlen($short) <= 95);
        $this->testContains('truncation: ends with [TRUNCATED]', '[TRUNCATED]', $short);

        // 8. Large budget → no truncation
        $unlimited = $this->invokeClean($fixture, 99999);
        $this->testNotContains('no-truncation: no [TRUNCATED] marker', '[TRUNCATED]', $unlimited);

        // 9. Nested tags preserve all text
        $nestedHtml = '<div><p>outer <span>inner <em>deepest</em></span> tail</p></div>';
        $nestedOut = $this->invokeClean($nestedHtml);
        $this->testContains('nested: outer text', 'outer', $nestedOut);
        $this->testContains('nested: inner text', 'inner', $nestedOut);
        $this->testContains('nested: deepest text', 'deepest', $nestedOut);
        $this->testContains('nested: tail text', 'tail', $nestedOut);

        // 10. Nothing after truncation boundary leaks
        $zero = $this->invokeClean('<p>some content that should be gone</p>', 0);
        $this->testTrue('zero tokens: short result', mb_strlen($zero) <= 16);
    }

    // ==================================================================
    // Phase C: Cache evaluator routing (AUTO_USE / ASK_USER / guard)
    // ==================================================================
    private function runCacheRouting(): void
    {
        echo "\n=== Cache evaluator routing ===\n";

        if ($this->db === null || $this->agent === null) {
            echo "  SKIP: Database/AgentManager not injected (run via run.php for Phase C)\n";
            return;
        }

        $testCacheKeys = [];
        $testLedgerKeys = [];

        try {

        // Save real ledger, run tests against an isolated copy
        $realLedger = Cache::getSearchLedger();
        Cache::set('search_ledger', json_encode([]), 604800);

        // Prevent live search from hitting SearXNG during tests
        SearchWebTool::$testMode = true;

        // Also clear any stale test cache keys from previous aborted runs
        foreach ($realLedger as $entry) {
            $ck = $entry['cache_key'] ?? '';
            foreach (['ctx_hermes_', 'ctx_empty_', 'ctx_sem_', 'ctx_none_'] as $pfx) {
                if (str_starts_with($ck, $pfx)) {
                    Cache::delete($ck);
                    break;
                }
            }
        }

        $emitLog = [];
        $capture = function (string $event, array $data = []) use (&$emitLog): void {
            $emitLog[] = compact('event', 'data');
        };

        // ── Unique suffixes to prevent ledger cross-contamination ──
        $uid2 = 'hermes_lex_test_' . time();
        $uid3 = 'hermes_empty_test_' . (time() + 1);
        $uid4 = 'hermes_sem_test_' . (time() + 2);
        $uid5 = 'hermes_none_test_' . (time() + 3);

        // 1. Empty query → guard returns error message
        $tool = $this->makeTool(null);
        $emitLog = [];
        $result = $tool->execute(['query' => ''], 1, [], $capture, '');
        $this->testEq('empty query → error message', '[Web Search: No query provided.]', $result);

        // 2. Lexical hit with content → AskUserPolicy AUTO_USE (fresh = low volatility)
        $cacheKey = 'ctx_hermes_' . $uid2;
        $testCacheKeys[] = $cacheKey;
        $testLedgerKeys[] = $cacheKey;
        Cache::set($cacheKey, 'CACHED CONTENT FOR TEST');
        Cache::addToLedger($uid2, $cacheKey);
        $tool = $this->makeTool(null);
        $emitLog = [];
        $result = $tool->execute(['query' => $uid2], 1, [], $capture, '');
        $this->testEq('lexical hit + AUTO_USE → cached content', 'CACHED CONTENT FOR TEST', $result);
        $this->testTrue('lexical hit: cache_used emitted', $this->emitted($emitLog, 'cache_used'));

        // 3. Lexical hit with empty content → falls through to live search (testMode: placeholder)
        $emptyKey = 'ctx_empty_' . $uid3;
        $testLedgerKeys[] = $emptyKey;
        Cache::addToLedger($uid3, $emptyKey);
        $emitLog = [];
        $result = $tool->execute(['query' => $uid3], 1, [], $capture, '');
        $this->testContains('lexical hit empty content → live search placeholder', '[TEST_MODE', $result);

        // 4. Lexical miss → semantic evaluator USE → AskUserPolicy → cached content
        $semKey = 'ctx_sem_' . $uid4;
        $testCacheKeys[] = $semKey;
        $testLedgerKeys[] = $semKey;
        Cache::set($semKey, 'SEMANTIC CACHED CONTENT');
        Cache::addToLedger('sem_candidate_' . $uid4, $semKey);
        $mockEval = $this->makeMockEval(['decision' => 'USE']);
        $tool = $this->makeTool($mockEval);
        $emitLog = [];
        $result = $tool->execute(['query' => $uid4], 1, [], $capture, '');
        $this->testEq('semantic USE + AUTO_USE → cached content', 'SEMANTIC CACHED CONTENT', $result);
        $this->testTrue('semantic USE: cache_used emitted', $this->emitted($emitLog, 'cache_used'));

        // 5. Lexical miss → semantic evaluator NONE → falls through (testMode: placeholder)
        $noneKey = 'ctx_none_' . $uid5;
        $testCacheKeys[] = $noneKey;
        $testLedgerKeys[] = $noneKey;
        Cache::set($noneKey, 'NONE CACHED CONTENT');
        Cache::addToLedger('sem_candidate_' . $uid5, $noneKey);
        $mockEval = $this->makeMockEval(null);
        $tool = $this->makeTool($mockEval);
        $emitLog = [];
        $result = $tool->execute(['query' => $uid5], 1, [], $capture, '');
        $this->testContains('semantic NONE → live search placeholder', '[TEST_MODE', $result);

        // 6. Multi-query (comma-separated) → skips cache eval entirely (testMode: placeholder)
        $mockEval = $this->makeMockEval(['decision' => 'USE']);
        $tool = $this->makeTool($mockEval);
        $emitLog = [];
        $result = $tool->execute(['query' => 'a, b'], 1, [], $capture, '');
        $this->testContains('multi-query → live search placeholder', '[TEST_MODE', $result);

        } finally {
            // Restore real ledger + clean test cache keys
            SearchWebTool::$testMode = false;
            foreach ($testCacheKeys as $key) {
                @Cache::delete($key);
            }
            Cache::set('search_ledger', json_encode($realLedger), 604800);
        }
    }

    // ==================================================================
    // Phase C helpers
    // ==================================================================

    private function makeTool(?SemanticCacheEvaluator $evaluator): SearchWebTool
    {
        return new SearchWebTool(
            $this->db,
            $this->agent,
            $this->uploadDir,
            new TodoistApiClient(),
            $evaluator
        );
    }

    private function makeMockEval(?array $returnValue): SemanticCacheEvaluator
    {
        return new class($this->agent, $returnValue) extends SemanticCacheEvaluator {
            private ?array $value;
            public function __construct(AgentManager $agent, ?array $value)
            {
                parent::__construct($agent);
                $this->value = $value;
            }
            public function evaluateCandidates(string $newQuery, array $candidates): array
            {
                if ($this->value === null) {
                    return ['decision' => 'NONE', 'cache_key' => null];
                }
                $decision = $this->value['decision'] ?? 'NONE';
                if ($decision === 'USE') {
                    // Pick the first candidate's cache_key (only one entry in test ledger)
                    $firstKey = array_key_first($candidates);
                    return ['decision' => 'USE', 'cache_key' => $firstKey];
                }
                return ['decision' => 'NONE', 'cache_key' => null];
            }
        };
    }

    private function emitted(array $log, string $event): bool
    {
        foreach ($log as $entry) {
            if ($entry['event'] === $event) {
                return true;
            }
        }
        return false;
    }

    private function emitData(array $log, string $event): array
    {
        foreach ($log as $entry) {
            if ($entry['event'] === $event) {
                return $entry['data'];
            }
        }
        return [];
    }

    // ==================================================================
    // Shared helpers
    // ==================================================================

    private function invokeBudget(array $messages, int $urlCount): int
    {
        $ref = new \ReflectionMethod(SearchWebTool::class, 'calculateScrapeBudgetStatic');
        $ref->setAccessible(true);
        return $ref->invoke(null, $messages, $urlCount);
    }

    private function invokeClean(string $html, ?int $maxTokens = null): string
    {
        $ref = new \ReflectionMethod(Scraper::class, 'cleanAndTruncate');
        $ref->setAccessible(true);
        return $ref->invoke(null, $html, $maxTokens);
    }

    private function loadFixture(): string
    {
        $path = __DIR__ . '/sample-page.html';
        if (!file_exists($path)) {
            return '';
        }
        return file_get_contents($path);
    }

    private function msg(string $content): array
    {
        return ['role' => 'user', 'content' => $content];
    }

    private function saveCtxSize(): void
    {
        $this->origCtxSize = $_ENV['LLM_CTX_SIZE'] ?? null;
    }

    private function setCtxSize(string $value): void
    {
        $_ENV['LLM_CTX_SIZE'] = $value;
    }

    private function restoreCtxSize(): void
    {
        if ($this->origCtxSize !== null) {
            $_ENV['LLM_CTX_SIZE'] = $this->origCtxSize;
        } else {
            unset($_ENV['LLM_CTX_SIZE']);
        }
    }

    private function testEq(string $label, mixed $expected, mixed $actual): void
    {
        if ($expected === $actual) {
            $this->passed++;
            echo "  PASS: {$label}\n";
        } else {
            $this->failed++;
            $this->failures[] = compact('label', 'expected', 'actual');
            echo "  FAIL: {$label}\n";
            echo "    expected: " . json_encode($expected) . "\n";
            echo "    actual:   " . json_encode($actual) . "\n";
        }
    }

    private function testContains(string $label, string $needle, string $haystack): void
    {
        if (str_contains($haystack, $needle)) {
            $this->passed++;
            echo "  PASS: {$label}\n";
        } else {
            $this->failed++;
            $this->failures[] = ['label' => $label, 'needle' => $needle, 'haystack_snippet' => mb_substr($haystack, 0, 80)];
            echo "  FAIL: {$label}\n";
            echo "    expected to contain: " . json_encode($needle) . "\n";
            echo "    haystack start:      " . json_encode(mb_substr($haystack, 0, 80)) . "\n";
        }
    }

    private function testNotContains(string $label, string $needle, string $haystack): void
    {
        if (!str_contains($haystack, $needle)) {
            $this->passed++;
            echo "  PASS: {$label}\n";
        } else {
            $this->failed++;
            $this->failures[] = ['label' => $label, 'unexpected_needle' => $needle];
            echo "  FAIL: {$label}\n";
            echo "    should NOT contain: " . json_encode($needle) . "\n";
        }
    }

    private function testTrue(string $label, bool $condition): void
    {
        if ($condition) {
            $this->passed++;
            echo "  PASS: {$label}\n";
        } else {
            $this->failed++;
            $this->failures[] = ['label' => $label, 'condition' => 'false'];
            echo "  FAIL: {$label}\n";
        }
    }
}
