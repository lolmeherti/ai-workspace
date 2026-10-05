<?php

declare(strict_types=1);

/**
 * Localsy — production baseline route, replayed on a saved harness capture.
 *
 * This is the retrieval the app actually performs, run on the SAME capture the
 * other routes read, with no browser and no re-fetch:
 *
 *   pipeline-a.json content  = the production extractor's own output (the payload
 *                              the extension bridge returns to production)
 *   -> SearchPipeline::chunksFromBridgeContent()   entity/chunk construction
 *   -> ContentDeduplicator::evaluate()/keep()      near-duplicate source decision
 *   -> Bm25Retriever::rank()                       retrieval policy applied
 *   -> SearchPipeline::threeLevelFit()             exact / extractive / per-source
 *                                                  LLM condenser, by budget
 *
 * Every step is production code from src/App/Search. The two private methods are
 * reached with reflection because this script sits outside the pipeline; nothing
 * in production is modified. Model calls made by the level-3 condenser are counted
 * from AgentManager's own call log, so the route cost includes them.
 *
 * Usage (inside the web container):
 *   php /var/www/html/tests/extraction-compare/baseline.php --snapshot=<id> [--question="..."]
 */

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/vendor/autoload.php';

use App\AgentManager;
use App\Config;
use App\Search\SearchPipeline;
use App\Search\TokenCounter;

$snapshot = null;
$questionOverride = null;
$queryOverride = null;
$qdir = 'q01';
$asJson = false;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--snapshot=')) $snapshot = substr($arg, 11);
    if (str_starts_with($arg, '--question=')) $questionOverride = substr($arg, 11);
    if (str_starts_with($arg, '--query=')) $queryOverride = substr($arg, 11);
    if (str_starts_with($arg, '--qdir=')) $qdir = substr($arg, 7);
    if ($arg === '--json') $asJson = true;
}
if (!$snapshot) {
    fwrite(STDERR, "FAIL: --snapshot=<id> is required.\n");
    exit(1);
}

$runDir = __DIR__ . '/runs/' . $snapshot;
$extractorFile = $runDir . '/pipeline-a.json';
if (!is_file($extractorFile)) {
    fwrite(STDERR, "FAIL: {$extractorFile} not found (need the extractor output).\n");
    exit(1);
}
$extracted = json_decode((string) file_get_contents($extractorFile), true) ?: [];
$content = $extracted['content'] ?? null;
if (!is_array($content) || empty($content['entities'])) {
    fwrite(STDERR, "FAIL: pipeline-a.json carries no bridge content/entities.\n");
    exit(1);
}
$url = (string) ($content['url'] ?? '');
$title = (string) ($content['title'] ?? '');

$question = $questionOverride;
if ($question === null) {
    foreach (['q01/question.json', 'q01/stats.json'] as $rel) {
        $f = $runDir . '/' . $rel;
        if (!is_file($f)) continue;
        $j = json_decode((string) file_get_contents($f), true) ?: [];
        if (!empty($j['question'])) { $question = (string) $j['question']; break; }
    }
}
if ($question === null || $question === '') {
    fwrite(STDERR, "FAIL: no question found; pass --question=\"...\".\n");
    exit(2);
}

Config::load($ROOT);

$agent = new AgentManager();
$agent->resetCallLog();
$pipeline = new SearchPipeline($agent);
$counter = new TokenCounter();

$private = static function (string $name): ReflectionMethod {
    $m = new ReflectionMethod(SearchPipeline::class, $name);
    $m->setAccessible(true);
    return $m;
};
$prop = static function (string $name) use ($pipeline) {
    $p = new ReflectionProperty(SearchPipeline::class, $name);
    $p->setAccessible(true);
    return $p->getValue($pipeline);
};

$timings = [];
$t = microtime(true);
$chunks = $private('chunksFromBridgeContent')->invoke($pipeline, $content, 'S1', $url);
$timings['chunk_build_ms'] = (int) round((microtime(true) - $t) * 1000);

// ── dedup decision, exactly as the per-source loop does it ──────────────────
$sourceBody = '';
foreach ($chunks as $c) $sourceBody .= $c->text . "\n";
$dedup = $prop('contentDeduplicator');
$t = microtime(true);
$decision = $dedup->evaluate($sourceBody);
$timings['dedup_ms'] = (int) round((microtime(true) - $t) * 1000);
if (!$decision['duplicate']) {
    $dedup->keep($sourceBody, "S1 {$url}");
}
$allChunks = $decision['duplicate'] ? [] : $chunks;

// ── BM25 retrieval across the fetched chunks ───────────────────────────────
// The retrieval query may differ from the question (a model-formatted query); both
// are recorded so a run is reproducible.
$query = $queryOverride ?? $question;
$retriever = $prop('retriever');
$policy = $prop('policy');
$t = microtime(true);
$selected = $allChunks ? $retriever->rank($allChunks, $query, $query, $policy) : [];
$timings['bm25_rank_ms'] = (int) round((microtime(true) - $t) * 1000);

// ── three-level evidence fitting (level 3 spends model calls) ──────────────
$t = microtime(true);
$fit = $private('threeLevelFit')->invoke($pipeline, $selected, $query, [['role' => 'user', 'content' => $question]]);
$timings['three_level_fit_ms'] = (int) round((microtime(true) - $t) * 1000);

$evidence = (string) ($fit['evidence'] ?? '');
$level = (string) ($fit['level'] ?? 'unknown');
$outDir = $runDir . '/' . $qdir;
if (!is_dir($outDir)) {
    fwrite(STDERR, "FAIL: question dir {$outDir} does not exist.\n");
    exit(2);
}
file_put_contents($outDir . '/pipeline-prod.md', $evidence);

$calls = $agent->callLog;
$callSummary = [];
foreach ($calls as $i => $c) {
    $callSummary[] = [
        'n' => $i + 1,
        'prompt_tokens' => (int) ($c['prompt_tokens'] ?? 0),
        'completion_tokens' => (int) ($c['completion_tokens'] ?? 0),
        'content_ms' => (int) ($c['content_ms'] ?? 0),
        'pred_ms' => (float) ($c['pred_ms'] ?? 0),
    ];
}

$stats = [
    'snapshot' => $snapshot,
    'route' => 'production (entity/chunk -> dedup -> BM25 -> threeLevelFit)',
    'url' => $url,
    'title' => $title,
    'question' => $question,
    'query_used' => $query,
    'query_is_question' => $query === $question,
    'question_dir' => $qdir,
    'chunks_built' => count($chunks),
    'chunk_text_chars' => strlen($sourceBody),
    'dedup' => [
        'duplicate' => (bool) $decision['duplicate'],
        'max_similarity' => round((float) ($decision['max_similarity'] ?? 0), 4),
        'threshold' => \App\Search\ContentDeduplicator::SIMILARITY_THRESHOLD,
    ],
    'chunks_after_dedup' => count($allChunks),
    'chunks_selected_bm25' => count($selected),
    'selected_chunk_ids' => array_map(static fn($c) => $c->chunkId, $selected),
    'selected_sections' => array_map(static fn($c) => implode(' > ', (array) $c->headingPath), $selected),
    'fit_level' => $level,
    'evidence_chars' => strlen($evidence),
    'evidence_tokens' => (int) ($counter->count($evidence) ?? 0),
    'model_calls' => count($calls),
    'model_call_detail' => $callSummary,
    'timings' => $timings,
    'deselected_chars' => max(0, strlen($sourceBody) - strlen($evidence)),
    'note' => 'Chunk construction, dedup, BM25 and budget fitting are deterministic and spend no model '
            . 'calls unless fitting falls through to the per-source LLM condenser (level 3). '
            . 'Incremental stop-rule evaluation is skipped: each capture holds a single source.',
];
file_put_contents($outDir . '/prod-stats.json', json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
file_put_contents($runDir . '/prod-stats.json', json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

if ($asJson) { echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n"; exit(0); }

printf("SNAPSHOT %s  (production baseline)\n", $snapshot);
printf("  question  : %s\n", mb_substr($question, 0, 90));
printf("  chunks    : %d built (%d chars) -> dedup %s (max sim %.3f vs %.2f) -> %d kept\n",
    count($chunks), strlen($sourceBody), $decision['duplicate'] ? 'DROP' : 'keep',
    (float) ($decision['max_similarity'] ?? 0), \App\Search\ContentDeduplicator::SIMILARITY_THRESHOLD, count($allChunks));
printf("  BM25      : %d of %d chunks selected\n", count($selected), count($allChunks));
printf("  fitting   : level=%s -> evidence %d chars (~%d tok), %d chars of chunk text not used\n",
    $level, strlen($evidence), $stats['evidence_tokens'], $stats['deselected_chars']);
printf("  model     : %d call(s) from the route itself\n", count($calls));
printf("  timing    : chunk %dms, dedup %dms, bm25 %dms, fit %dms\n",
    $timings['chunk_build_ms'], $timings['dedup_ms'], $timings['bm25_rank_ms'], $timings['three_level_fit_ms']);
