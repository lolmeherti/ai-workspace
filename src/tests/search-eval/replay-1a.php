<?php
/**
 * Phase 1A: Offline Replay
 *
 * Reads frozen Phase 0 raw-pages, runs new extraction → chunk → BM25 → evidence pipeline.
 * Falls back to existing ContextCondenser when evidence exceeds budget.
 * Compares new answer against Phase 0 baseline.
 *
 * Usage (inside Docker):
 *   php replay-1a.php                    # all fixtures
 *   php replay-1a.php --query-id=specs-iphone
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\AgentManager;
use App\Config;
use App\Agents\ContextCondenser;
use App\Search\ContentExtractor;
use App\Search\StructuralChunker;
use App\Search\Bm25Retriever;
use App\Search\EvidenceBuilder;
use App\Search\RetrievalPolicy;
use App\Search\TokenCounter;
use App\Search\ExtractiveCompressor;
use App\Search\SourceCondenser;

Config::load(__DIR__ . '/../..');

$opts = getopt('', ['query-id:']);
$targetId = $opts['query-id'] ?? null;

$queryIds = $targetId ? [$targetId] : [
    'specs-iphone', 'long-article', 'comparison-table', 'news-event',
    'conflicting', 'blocked-page', 'prompt-injection', 'two-facts',
    'obscure-query', 'code-docs',
];

$baseDir = __DIR__;
$agent = new AgentManager();
$extractor = new ContentExtractor();
$chunker = new StructuralChunker();
$retriever = new Bm25Retriever();
$evBuilder = new EvidenceBuilder();
$fallbackCondenser = new ContextCondenser($agent);
$sourceCondenser = new SourceCondenser($agent);
$policy = RetrievalPolicy::default();
$tokenCounter = new TokenCounter();

$contextWindow = (int) Config::get('LLM_CTX_SIZE', 32768);
$completionReserve = 4096;
$safetyMargin = 512;
$maxWebTokens = (int) Config::get('MAX_WEB_CONTEXT_TOKENS', 8192);

$summarySystem = <<<'PROMPT'
You are a helpful AI assistant. Answer the user's question using only the provided
RETRIEVED DATA. Cite source IDs [S1] where applicable. If evidence is incomplete
or conflicting, say so. Do not use knowledge outside the provided data.
PROMPT;

$summaries = [];

foreach ($queryIds as $qid) {
    $fixtureDir = "{$baseDir}/{$qid}";
    echo "=== {$qid} ===\n";

    if (!is_dir($fixtureDir)) {
        echo "  SKIP: fixture dir not found\n";
        continue;
    }

    $question = trim(file_get_contents("{$fixtureDir}/original-question.txt"));
    $searchQuery = trim(file_get_contents("{$fixtureDir}/search-query.txt"));
    $urls = array_filter(explode("\n", trim(file_get_contents("{$fixtureDir}/urls-selected.txt"))));

    $rawDir = "{$fixtureDir}/raw-pages";
    $phase0Answer = file_exists("{$fixtureDir}/current-answer.txt")
        ? file_get_contents("{$fixtureDir}/current-answer.txt") : '';

    // ── Load frozen raw pages ────────────────────────────────────────────────
    $rawPages = [];
    foreach ($urls as $i => $url) {
        $hash = hash('sha256', $url);
        $rawPath = "{$rawDir}/{$hash}.json";
        if (!file_exists($rawPath)) {
            $altFiles = glob("{$rawDir}/*.json");
            $rawPath = $altFiles[$i] ?? null;
        }
        if (!$rawPath || !file_exists($rawPath)) {
            echo "  WARN: raw page not found for URL {$i}\n";
            continue;
        }

        $meta = json_decode(file_get_contents($rawPath), true);
        $html = base64_decode($meta['body_base64'] ?? '');
        $rawPages[] = [
            'url' => $meta['requested_url'] ?? $url,
            'finalUrl' => $meta['final_url'] ?? $url,
            'html' => $html,
            'fetchedAt' => $meta['fetched_at'] ?? date('c'),
        ];
    }

    if (empty($rawPages)) {
        echo "  SKIP: no raw pages found\n";
        continue;
    }

    // ── Phase 1A pipeline ────────────────────────────────────────────────────
    $sourceId = 0;
    $allChunks = [];
    $allExtracted = [];

    foreach ($rawPages as $rp) {
        $sourceId++;
        $sid = "S{$sourceId}";

        $doc = $extractor->extract($rp['html'], $rp['url'], $rp['finalUrl'], $rp['fetchedAt']);
        $allExtracted[] = $doc;

        $chunks = $chunker->chunk($doc, $sid);
        $allChunks = array_merge($allChunks, $chunks);

        echo "  {$sid}: {$doc->title} — {$doc->extractionMethod} — {$doc->contentLength} chars → " . count($chunks) . " chunks\n";
    }

    echo "  Total chunks: " . count($allChunks) . "\n";

    // ── BM25 retrieval ───────────────────────────────────────────────────────
    $selected = $retriever->rank($allChunks, $question, $searchQuery, $policy);
    echo "  Retrieved: " . count($selected) . " chunks\n";

    foreach ($selected as $chunk) {
        echo "    {$chunk->chunkId} [{$chunk->domain}] \"{$chunk->title}\" heading=" . implode(' > ', $chunk->headingPath) . " type={$chunk->sectionType} chars=" . strlen($chunk->text) . "\n";
    }

    // ── Three-level evidence fitting (Phase 1B) ──────────────────────────────
    $compressor = new ExtractiveCompressor($question . ' ' . $searchQuery);

    // Budget: compute from actual prompt tokens
    $userTemplate = "RETRIEVED DATA:\n\n__EVIDENCE__\n\nUSER QUESTION: {$question}";
    $basePromptTokens = $tokenCounter->count($summarySystem)
                      + $tokenCounter->count(str_replace('__EVIDENCE__', '', $userTemplate));

    $webBudget = min(
        $maxWebTokens,
        max(0, $contextWindow - $basePromptTokens - $completionReserve - $safetyMargin)
    );

    $chunkTokens = $tokenCounter->countChunks($selected);
    $evidenceBlock = $evBuilder->build($selected);
    $evidenceTokens = $tokenCounter->count($evidenceBlock);

    $condensationLevel = 'exact';
    $usedCondenser = false;

    if ($evidenceTokens <= $webBudget) {
        $contextForAnswer = $evidenceBlock;
        $condensationLevel = 'exact';
    } else {
        // Level 2: extractive compression
        $compressedTexts = $compressor->compress($selected, $tokenCounter,
            $webBudget, $chunkTokens);
        $compressedEvBlock = $evBuilder->buildTexts($selected, $compressedTexts[0]);
        $compressedTokens = $tokenCounter->count($compressedEvBlock);

        if ($compressedTokens <= $webBudget) {
            $evidenceBlock = $compressedEvBlock;
            $contextForAnswer = $compressedEvBlock;
            $condensationLevel = 'extractive';
            $evidenceTokens = $compressedTokens;
            echo "  Extractive compression: {$chunkTokens} → {$compressedTokens} tokens\n";
        } else {
            // Level 3: per-source LLM condenser (Phase 1C)
            echo "  Evidence exceeds budget (extractive {$compressedTokens} > {$webBudget}) — per-source condenser\n";
            $usedCondenser = true;
            $condensationLevel = 'condenser';

            // Filter out chunks too large for the condenser LLM
            $condenserChunks = array_filter($selected, fn($c) => strlen($c->text) < 50000);
            if (count($condenserChunks) < count($selected)) {
                echo "  Skipped " . (count($selected) - count($condenserChunks)) . " oversized chunks for condenser\n";
            }
            $ledger = $sourceCondenser->condense(
                empty($condenserChunks) ? $selected : array_values($condenserChunks),
                $searchQuery
            );

            // Build evidence from per-source ledger claims
            $ledgerLines = ["RETRIEVED DATA — UNTRUSTED EXTERNAL CONTENT:\n"];
            foreach ($ledger as $entry) {
                $sourceChunks = array_filter($selected, fn($c) => $c->sourceId === $entry['sourceId']);
                $first = reset($sourceChunks);
                if (!$first) continue;

                $ledgerLines[] = "<source id=\"{$entry['sourceId']}\">";
                $ledgerLines[] = "<domain>{$first->domain}</domain>";

                foreach ($entry['items'] as $item) {
                    $ledgerLines[] = '<claim refs="' . implode(',', $item['chunkIds']) . '">';
                    $ledgerLines[] = htmlspecialchars($item['claim'], ENT_XML1);
                    $ledgerLines[] = '</claim>';
                }
                $ledgerLines[] = '</source>';
                $ledgerLines[] = '';
            }

            $fullLedger = implode("\n", $ledgerLines);

            // Deterministic post-fitting: trim lowest-relevance source until fits
            $contextForAnswer = $fullLedger;
            $ledgerTokens = $tokenCounter->count($fullLedger);

            if ($ledgerTokens > $webBudget && count($ledger) > 1) {
                // Remove sources with fewest claims first
                usort($ledger, fn($a, $b) => count($a['items']) <=> count($b['items']));
                while ($ledgerTokens > $webBudget && count($ledger) > 1) {
                    array_pop($ledger); // remove least-claim source
                    $slimLines = ["RETRIEVED DATA — UNTRUSTED EXTERNAL CONTENT:\n"];
                    foreach ($ledger as $entry) {
                        $sourceChunks = array_filter($selected, fn($c) => $c->sourceId === $entry['sourceId']);
                        $first = reset($sourceChunks);
                        if (!$first) continue;
                        $slimLines[] = "<source id=\"{$entry['sourceId']}\">";
                        foreach ($entry['items'] as $item) {
                            $slimLines[] = '<claim refs="' . implode(',', $item['chunkIds']) . '">';
                            $slimLines[] = htmlspecialchars($item['claim'], ENT_XML1);
                            $slimLines[] = '</claim>';
                        }
                        $slimLines[] = '</source>';
                        $slimLines[] = '';
                    }
                    $contextForAnswer = implode("\n", $slimLines);
                    $ledgerTokens = $tokenCounter->count($contextForAnswer);
                }
                echo "  Post-fitting: trimmed to {$ledgerTokens} tokens\n";
            }

            $evidenceBlock = $contextForAnswer;
            $evidenceTokens = $ledgerTokens;
        }
    }

    // ── Generate answer ──────────────────────────────────────────────────────
    if (empty(trim($contextForAnswer))) {
        $phase1Answer = "No usable content found in search results.";
    } else {
        $phase1Answer = $agent->chat([
            ['role' => 'system', 'content' => $summarySystem],
            ['role' => 'user', 'content' =>
                "RETRIEVED DATA:\n\n{$contextForAnswer}\n\n" .
                "USER QUESTION: {$question}\n\n" .
                "Provide a thorough answer based on the RETRIEVED DATA above."
            ],
        ], false, null, 0.5);
    }

    // ── Save Phase 1 outputs ─────────────────────────────────────────────────
    file_put_contents("{$fixtureDir}/phase1-answer.txt", $phase1Answer);
    file_put_contents("{$fixtureDir}/phase1-evidence.txt", $evidenceBlock);
    file_put_contents("{$fixtureDir}/phase1-chunks.json",
        json_encode(array_map(fn($c) => [
            'chunkId' => $c->chunkId, 'domain' => $c->domain,
            'title' => $c->title, 'sectionType' => $c->sectionType,
            'headingPath' => $c->headingPath, 'textLen' => strlen($c->text),
        ], $selected), JSON_PRETTY_PRINT));

    $condenserNote = $usedCondenser ? ' (condenser fallback)' : '';
    echo "  Budget: {$webBudget} tokens, Evidence: {$evidenceTokens} tokens [{$condensationLevel}]{$condenserNote}\n";
    echo "  Answer: " . strlen($phase1Answer) . " chars\n";

    $summaries[$qid] = [
        'chunks_total' => count($allChunks),
        'chunks_selected' => count($selected),
        'condensation_level' => $condensationLevel,
        'phase0_answer_len' => strlen($phase0Answer),
        'phase1_answer_len' => strlen($phase1Answer),
    ];
}

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 70) . "\n";
echo sprintf("%-22s %6s %6s %10s %9s %9s\n",
    'fixture', 'chunks', 'sel', 'level', 'p0_ans', 'p1_ans');
echo str_repeat('-', 70) . "\n";

foreach ($summaries as $qid => $s) {
    echo sprintf("%-22s %6d %6d %10s %9d %9d\n",
        $qid, $s['chunks_total'], $s['chunks_selected'],
        $s['condensation_level'], $s['phase0_answer_len'], $s['phase1_answer_len']);
}

$exactCount = count(array_filter($summaries, fn($s) => $s['condensation_level'] === 'exact'));
$extractiveCount = count(array_filter($summaries, fn($s) => $s['condensation_level'] === 'extractive'));
$condenserCount = count($summaries) - $exactCount - $extractiveCount;
echo "\nExact: {$exactCount}, Extractive: {$extractiveCount}, Condenser: {$condenserCount}\n";
