<?php

/**
 * Localsy — standalone extraction comparison (Pipeline A vs Pipeline B).
 *
 * NOT a production script. It does not modify or call the browsing pipeline:
 *   • capture happens in a standalone harness extension (harness/) that runs the
 *     UNMODIFIED deterministic extractor (harness/deterministic-extractor.js, a
 *     byte-identical copy — the copy hash is verified and recorded),
 *   • this script only reads the captured snapshot and runs ONE model selection
 *     pass for Pipeline B.
 *
 * Pipeline A = the deterministic extractor's raw output (pre-condensation).
 * Pipeline B = model picks snapshot-local IDs from a compact DOM map; content is
 *              then extracted deterministically from the full content store.
 * The model never rewrites or summarizes content; it only returns IDs.
 *
 * Usage (inside the web container):
 *   php /var/www/html/tests/extraction-compare/compare.php \
 *       --url="https://example.com/article" --question="What is the battery life?"
 *
 *   # replay the same snapshot with a different question (no capture, no browser):
 *   php /var/www/html/tests/extraction-compare/compare.php \
 *       --snapshot=20261002-153000-1a2b3c4d --question="What does it say about charging?"
 *
 * Requires: node src/tests/extraction-compare/server.js (on the host) and the
 * harness extension connected in a browser. See README.md.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);                 // /var/www/html
require_once $ROOT . '/vendor/autoload.php';

use App\AgentManager;
use App\Config;
use App\Search\TokenCounter;

// ── args ────────────────────────────────────────────────────────────────────
$args = [];
foreach (array_slice($argv, 1) as $raw) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/i', $raw, $m)) {
        $args[strtolower($m[1])] = $m[2] ?? '1';
    }
}
$url            = $args['url'] ?? null;
$snapshotArg    = $args['snapshot'] ?? null;
$question       = $args['question'] ?? null;
$budgetChars    = (int) ($args['budget-chars'] ?? 60000);
$temperature    = isset($args['temperature']) ? (float) $args['temperature'] : 0.0;
$maxTokens      = (int) ($args['max-tokens'] ?? 2048);
$effort         = $args['effort'] ?? null;
$mode           = $args['mode'] ?? null;   // 'instruct' = reasoning off (policy off_value)
$noModel        = array_key_exists('no-model', $args);
$captureTimeout = (int) ($args['capture-timeout'] ?? 180);
// Map-builder knobs for THIS capture (recorded in dom-map.json options).
$mapFloor       = isset($args['map-floor']) ? (int) $args['map-floor'] : null;
$previewChars   = isset($args['preview-chars']) ? (int) $args['preview-chars'] : null;
$runsDir        = rtrim($args['runs-dir'] ?? (__DIR__ . '/runs'), '/');

if (!$question) {
    fwrite(STDERR, "FAIL: --question=\"...\" is required (the comparison is per question).\n");
    exit(2);
}
if (!$url && !$snapshotArg) {
    fwrite(STDERR, "FAIL: pass either --url=<page to capture> or --snapshot=<existing snapshot id>.\n");
    exit(2);
}

Config::load($ROOT);

// ── tiny local logger (this script intentionally avoids the app DB) ─────────
$logLines = [];
function note(string $line): void
{
    global $logLines;
    $logLines[] = date('c') . ' ' . $line;
    fwrite(STDOUT, $line . "\n");
}
function fail(string $message, ?string $runDir = null): void
{
    global $logLines;
    $logLines[] = date('c') . " FAIL " . $message;
    if ($runDir) {
        @file_put_contents($runDir . '/compare.log', implode("\n", $logLines) . "\n");
    }
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(2);
}
function writeJson(string $file, $data): void
{
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
function readJson(string $file)
{
    if (!is_file($file)) return null;
    $decoded = json_decode((string) file_get_contents($file), true);
    return is_array($decoded) ? $decoded : null;
}
function slug(string $s, int $max = 48): string
{
    $s = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $s));
    return trim(substr($s, 0, $max), '-');
}
function chrono(callable $fn): array
{
    $t0 = microtime(true);
    $value = $fn();
    return [$value, (int) round((microtime(true) - $t0) * 1000)];
}

// ── 1. capture (or reuse) ───────────────────────────────────────────────────
$snapshotId = $snapshotArg;
$runBase = null;

if ($url && !$snapshotArg) {
    $statusFile = $runsDir . '/_server-status.json';
    $status = readJson($statusFile);
    if (!$status) {
        fail("capture server not running. On the host run:\n"
            . "      node src/tests/extraction-compare/server.js");
    }
    $ageSec = time() - (int) strtotime((string) ($status['updated_at'] ?? '1970-01-01'));
    if ($ageSec > 15) {
        fail("capture server status file is stale ({$ageSec}s old) — is server.js still running?");
    }
    if (empty($status['harness_connected'])) {
        fail("capture server is up but no browser harness is connected.\n"
            . "    Load it once into the browser you already use: edge://extensions → Developer mode →\n"
            . "    Load unpacked → " . $runsDir . "/../harness");
    }

    $snapshotId = date('Ymd-His') . '-' . substr(sha1($url), 0, 8);
    $runBase = $runsDir . '/' . $snapshotId;
    if (!is_dir($runBase) && !mkdir($runBase, 0777, true) && !is_dir($runBase)) {
        fail("cannot create run directory {$runBase}");
    }
    $mapOptions = [];
    if ($mapFloor !== null)     $mapOptions['minBlockChars'] = $mapFloor;
    if ($previewChars !== null) $mapOptions['previewChars'] = $previewChars;
    writeJson($runBase . '/request.json', [
        'url' => $url,
        'queued_at' => date('c'),
        'requested_by' => 'compare.php',
        'options' => $mapOptions ?: null
    ]);
    if ($mapOptions) {
        note('[capture] map options: ' . json_encode($mapOptions));
    }
    note("[capture] queued {$url} as {$snapshotId}");

    $deadline = time() + $captureTimeout;
    while (time() < $deadline) {
        if (is_file($runBase . '/capture-meta.json')) break;
        sleep(1);
    }
    if (!is_file($runBase . '/capture-meta.json')) {
        $failFile = readJson($runBase . '/capture-failed.json');
        fail("capture did not finish within {$captureTimeout}s"
            . ($failFile ? ' (harness reported: ' . ($failFile['failure'] ?? 'unknown') . ')' : ''), $runBase);
    }
    $captureMeta = readJson($runBase . '/capture-meta.json') ?? [];
    if (empty($captureMeta['ok'])) {
        fail("capture failed: " . ($captureMeta['failure'] ?? 'unknown')
            . " — artifacts (if any) are in {$runBase}", $runBase);
    }
    note("[capture] done: " . json_encode(readJson($runBase . '/capture-timings.json') ?? []));
} else {
    $runBase = $runsDir . '/' . $snapshotId;
    if (!is_dir($runBase)) {
        fail("snapshot '{$snapshotId}' not found under {$runsDir}");
    }
}

// ── 2. per-question run directory ───────────────────────────────────────────
$qDirs = glob($runBase . '/q*', GLOB_ONLYDIR) ?: [];
$qNum = count($qDirs) + 1;
$qDir = $runBase . sprintf('/q%02d', $qNum);
if (!mkdir($qDir, 0777, true) && !is_dir($qDir)) {
    fail("cannot create question directory {$qDir}", $runBase);
}
writeJson($qDir . '/question.json', [
    'snapshot_id' => $snapshotId,
    'url' => $url,
    'question' => $question,
    'run_at' => date('c'),
    'budget_chars' => $budgetChars,
    'temperature' => $temperature,
    'max_tokens' => $maxTokens,
    'effort' => $effort,
    'mode' => $mode,
    'no_model' => $noModel
]);
note("[run] snapshot={$snapshotId} question_dir=" . basename($qDir));

$captureMeta  = readJson($runBase . '/capture-meta.json') ?? [];
$limitations  = readJson($runBase . '/capture-limitations.json') ?? [];
$captureTimes = readJson($runBase . '/capture-timings.json') ?? [];
$pipelineA    = readJson($runBase . '/pipeline-a.json') ?? [];
$map          = readJson($runBase . '/dom-map.json') ?? [];
$store        = readJson($runBase . '/content-store.json') ?? [];

if (!is_file($runBase . '/dom-map.json') || !is_file($runBase . '/content-store.json')) {
    fail("snapshot {$snapshotId} is missing dom-map.json / content-store.json — capture payload incomplete", $runBase);
}

// A page that served a block/consent screen is not a comparison: Pipeline A has no
// content and a model pass would measure the challenge page, not the article.
$blockedExtractorStatuses = ['challenge_required', 'consent_required', 'rejected', 'rejected_redirect'];
$extractorStatus = (string) ($pipelineA['status'] ?? 'unknown');
if (in_array($extractorStatus, $blockedExtractorStatuses, true)) {
    fail("capture landed on a block/consent screen (extractor status '{$extractorStatus}') — Pipeline A extracted nothing,"
        . " so a model pass would measure the challenge page. Artifacts kept in {$runBase} for inspection.", $runBase);
}
if (empty($map['blocks']) || empty($store)) {
    fail("snapshot {$snapshotId} has an empty DOM map (0 selectable blocks) — nothing to compare", $runBase);
}

// ── 3. Pipeline A artifact (complete extractor output, pre-condensation) ────
$timings = [];
[$pipelineAMd, $timings['pipeline_a_render_ms']] = chrono(function () use ($pipelineA, $captureMeta, $limitations) {
    $content = $pipelineA['content'] ?? [];
    $entities = $content['entities'] ?? [];
    $out = [];
    $out[] = '# Pipeline A — deterministic extractor output (unmodified, pre-condensation)';
    $out[] = '';
    $out[] = '- extractor status: `' . ($pipelineA['status'] ?? 'unknown') . '`';
    $out[] = '- entities: ' . count($entities) . ' | body chars: ' . ($pipelineA['body_chars'] ?? 0);
    $out[] = '- page title: ' . ($content['title'] ?? '(none)');
    $out[] = '- page url: ' . ($content['url'] ?? ($captureMeta['url'] ?? '(unknown)'));
    $out[] = '- date_posted (extractor): ' . ($content['date_posted'] ?? '(none)');
    $out[] = '- links collected: ' . (is_array($content['links'] ?? null) ? count($content['links']) : 0);
    $out[] = '';
    $out[] = 'Stages INCLUDED: extension DOM extraction, heading/table rendering, 120k body cap.';
    $out[] = 'Stages EXCLUDED (by design — downstream condensation): PHP chunking, BM25 selection,';
    $out[] = 'extractive compression, per-source LLM condensation, cross-source de-duplication.';
    $out[] = '';
    foreach ($entities as $i => $entity) {
        $out[] = '## entity ' . ($i + 1)
            . ' — type=' . ($entity['entity_type'] ?? '?')
            . ' id=' . ($entity['entity_id'] ?? '?')
            . ' author=' . ($entity['author'] ?? '-')
            . ' score=' . ($entity['score'] ?? '-')
            . ' published=' . ($entity['published'] ?? '-');
        $out[] = '';
        $out[] = (string) ($entity['body'] ?? '');
        $out[] = '';
    }
    return implode("\n", $out);
});
file_put_contents($qDir . '/pipeline-a.md', $pipelineAMd);

// ── 4. Pipeline B — compact map + budgeted prompt ───────────────────────────
$blocks = $map['blocks'] ?? [];
$mapRenderStart = microtime(true);
$lines = [];
$usedChars = 0;
$omittedBlocks = [];
$renderedRuntimeMs = 0;
foreach ($blocks as $idx => $block) {
    $line = '[' . $block['id'] . '] ' . ($block['tag'] ?? '?');
    if (!empty($block['role'])) $line .= ' role=' . $block['role'];
    $line .= ' d=' . ($block['depth'] ?? 0);
    $line .= ' own=' . ($block['own_len'] ?? 0) . ' total=' . ($block['total_len'] ?? 0);
    if (!empty($block['table'])) {
        $t = $block['table'];
        $line .= ' table=' . ($t['rows'] ?? 0) . 'x' . ($t['cols'] ?? 0);
        if (!empty($t['headers'])) $line .= ' headers="' . implode(' | ', $t['headers']) . '"';
    }
    if (!empty($block['hidden'])) $line .= ' hidden=1';
    $line .= ' :: ' . (string) ($block['preview'] ?? '');
    if (!empty($block['links'])) {
        $cue = [];
        foreach ($block['links'] as $l) {
            $cue[] = '"' . $l['text'] . '"→' . $l['href'];
        }
        $line .= ' || links: ' . implode(' ; ', $cue);
    }
    if ($usedChars + strlen($line) > $budgetChars) {
        $omittedBlocks = array_slice($blocks, $idx);
        break;
    }
    $lines[] = $line;
    $usedChars += strlen($line) + 1;
}
$timings['map_prompt_render_ms'] = (int) round((microtime(true) - $mapRenderStart) * 1000);

$omittedChars = 0;
foreach ($omittedBlocks as $b) {
    $omittedChars += (int) ($b['total_len'] ?? 0);
}
$omissionSection = '';
if ($omittedBlocks) {
    $first = $omittedBlocks[0]['id'] ?? '?';
    $last = $omittedBlocks[count($omittedBlocks) - 1]['id'] ?? '?';
    $omissionSection = "\nOMITTED FROM THIS MAP (known to exist, NOT selectable in this run):\n"
        . '- ' . count($omittedBlocks) . ' blocks (' . $first . ' … ' . $last . '), '
        . $omittedChars . ' chars of page text, dropped by the input budget of ' . $budgetChars . " chars.\n"
        . "- If the answer needs content from these blocks, say so via the \"insufficient\" field; do not guess.\n";
}

$systemPrompt = <<<'PROMPT'
You select which parts of a captured web page are worth reading to answer a question.

You receive a compact DOM map of one page. Each line is one selectable block:

  [e12] tag role=<aria/implicit role> d=<nesting depth> own=<chars of text owned by this block>
  total=<chars incl. descendants> <table=|hidden=> :: <preview, own text only, truncated with …[+N chars]>
  || links: "<anchor text>"→<absolute destination>

Rules:
- Decide from the previews only; you cannot see full text. Select the blocks a careful reader
  would need in order to answer the question from this page.
- Ancestors never repeat their descendants' text, so pick the most specific block that carries
  the answer, plus any block needed for context (heading, table header row, the paragraph that
  qualifies a number, the comment that contradicts another). Selecting both a block and its
  descendant is allowed but wasteful; the validator will collapse the descendant.
- Do not select navigation, cookie, footer, newsletter, ad or "related posts" blocks.
- Prefer structural blocks for structure: a table block carries its headers; a heading block
  carries its heading text.
- If the map (including the OMITTED section) does not contain what the question needs, say so
  with "insufficient": true instead of guessing.

Output JSON only, no prose, in exactly this shape:
{"selections":[{"id":"e12","reason":"one short clause why this block is needed"}],"insufficient":false}

Never restate, summarize or rewrite page content. IDs and short reasons only.
Each reason is plain single-line text: no double quotes inside a reason, no newlines.
PROMPT;

$userPrompt = "QUESTION ABOUT THIS PAGE:\n" . $question . "\n\n"
    . "PAGE: " . ($map['snapshot']['title'] ?? '') . "\n"
    . "URL: " . ($map['snapshot']['url'] ?? ($captureMeta['url'] ?? '')) . "\n"
    . "MAP: " . count($lines) . " of " . count($blocks) . " blocks shown, "
    . count($map['counts'] ?? []) . " counters: " . json_encode($map['counts'] ?? []) . "\n\n"
    . "SELECTABLE BLOCKS:\n" . implode("\n", $lines) . "\n"
    . $omissionSection . "\n"
    . "Return the JSON object now.";

$messages = [
    ['role' => 'system', 'content' => $systemPrompt],
    ['role' => 'user', 'content' => $userPrompt],
];

file_put_contents($qDir . '/model-input.txt',
    "===== SYSTEM =====\n{$systemPrompt}\n\n===== USER =====\n{$userPrompt}\n");
writeJson($qDir . '/model-input-meta.json', [
    'system_chars' => strlen($systemPrompt),
    'user_chars' => strlen($userPrompt),
    'total_chars' => strlen($systemPrompt) + strlen($userPrompt),
    'blocks_total' => count($blocks),
    'blocks_shown' => count($lines),
    'blocks_omitted' => count($omittedBlocks),
    'omitted_chars_of_page_text' => $omittedChars,
    'budget_chars' => $budgetChars,
    'preview_cap_chars' => $map['options']['previewChars'] ?? null,
    'map_build_ms' => $captureTimes['map_build_ms'] ?? null,
    'notes' => $omittedBlocks
        ? 'Input budget truncated the map; the OMISSIONS section was shown to the model.'
        : 'Whole map fit inside the budget.'
]);

// ── 5. one model selection pass ─────────────────────────────────────────────
if ($noModel) {
    file_put_contents($qDir . '/pipeline-b.md', "(skipped: --no-model)\n");
    writeJson($qDir . '/timings.json', $timings);
    note('[model] skipped (--no-model). Pipeline A + map artifacts written.');
    exit(0);
}

$agent = new AgentManager();
$modelName = method_exists($agent, 'getModelName') ? $agent->getModelName() : null;
$servedModels = null;
$apiUrl = rtrim((string) Config::get('LLM_API_URL', 'http://host.docker.internal:1234/v1'), '/');
$ch = curl_init($apiUrl . '/models');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$modelsRaw = curl_exec($ch);
$modelsCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($modelsCode === 200 && is_string($modelsRaw)) {
    $decoded = json_decode($modelsRaw, true);
    if (isset($decoded['data']) && is_array($decoded['data'])) {
        $servedModels = array_values(array_filter(array_map(
            static fn($m) => is_array($m) ? ($m['id'] ?? null) : null,
            $decoded['data']
        )));
    }
}
if ($modelsCode !== 200) {
    fail("model endpoint {$apiUrl}/models unreachable (HTTP {$modelsCode}) — start llama.cpp, or pass --no-model for capture-only runs", $qDir);
}

$modelError = null;
$rawResponse = null;
$timings['model_latency_ms'] = null;
try {
    [$rawResponse, $timings['model_latency_ms']] = chrono(static function () use ($agent, $messages, $temperature, $mode, $effort, $maxTokens) {
        return $agent->chat($messages, false, null, $temperature, 'extraction-compare', $mode, $effort, $maxTokens);
    });
} catch (\Throwable $e) {
    $modelError = $e->getMessage();
}

$usage = $agent->lastUsage ?? null;
$agentTimings = $agent->lastTimings ?? null;
$reasoningApplied = $agent->lastReasoningApplied ?? null;

writeJson($qDir . '/model-request.json', [
    'endpoint' => $apiUrl . '/chat/completions',
    'model_config_name' => $modelName,
    'models_served_by_endpoint' => $servedModels,
    'sampling' => [
        'temperature' => $temperature,
        'max_tokens' => $maxTokens,
        'effort_override' => $effort,
        'mode_override' => $mode,
        'llm_sampling_config' => Config::get('LLM_SAMPLING'),
        'default_chat_temp' => Config::get('DEFAULT_CHAT_TEMP'),
        'runtime_policy' => Config::get('LLM_RUNTIME_POLICY')
    ],
    'reasoning_applied' => $reasoningApplied,
    'messages' => $messages
]);
writeJson($qDir . '/model-response.json', [
    'ok' => $modelError === null,
    'error' => $modelError,
    'raw' => $rawResponse,
    'usage' => $usage,
    'endpoint_timings' => $agentTimings,
    'latency_ms' => $timings['model_latency_ms']
]);

if ($modelError !== null) {
    // No silent fallback to Pipeline A — the failure is the result.
    file_put_contents($qDir . '/pipeline-b.md', "MODEL CALL FAILED: {$modelError}\n");
    writeJson($qDir . '/timings.json', $timings);
    fail("model call failed: {$modelError}", $qDir);
}

// ── 6. parse + validate selection ───────────────────────────────────────────
$parseErrors = [];
$selection = null;
$text = trim((string) $rawResponse);
$text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;
$start = strpos($text, '{');
$end = strrpos($text, '}');
$parseMode = 'strict';
if ($start === false || $end === false || $end <= $start) {
    $parseErrors[] = 'no JSON object found in the response';
} else {
    $json = substr($text, $start, $end - $start + 1);
    $selection = json_decode($json, true);
    if (!is_array($selection)) {
        // A model slip must not zero out a run — but it must stay visible. The known
        // failure is an unescaped double quote inside a reason string, so repair that
        // deterministically first, then fall back to a plain id scan.
        $strictError = json_last_error_msg();
        $repaired = preg_replace_callback(
            '/"(reason|id)"\s*:\s*"(.*?)"\s*(?=[,}])/s',
            static function (array $m): string {
                $inner = preg_replace('/(?<!\\\\)"/', '\\"', $m[2]);
                return '"' . $m[1] . '":"' . $inner . '"';
            },
            $json
        );
        if (is_string($repaired)) {
            $selection = json_decode($repaired, true);
            if (is_array($selection)) $parseMode = 'quote_repair';
        }
        if (!is_array($selection)) {
            preg_match_all('/"id"\s*:\s*"(e[0-9]+)"/', $json, $idHits);
            if (!empty($idHits[1])) {
                $ids = array_values(array_unique($idHits[1]));
                $selection = ['selections' => array_map(
                    static fn(string $id): array => ['id' => $id, 'reason' => null],
                    $ids
                )];
                $parseMode = 'id_regex';
            }
        }
        if (is_array($selection)) {
            $parseErrors[] = "strict JSON parse failed ({$strictError}); selection recovered by '{$parseMode}'";
        } else {
            $parseErrors[] = "response JSON did not decode ({$strictError}) and could not be recovered";
        }
    }
}

$requested = [];
if (is_array($selection)) {
    $list = $selection['selections'] ?? $selection['ids'] ?? $selection;
    if (is_string($list)) $list = [$list];
    if (is_array($list)) {
        foreach ($list as $item) {
            if (is_string($item)) { $requested[] = ['id' => trim($item, '[] '), 'reason' => null]; continue; }
            if (is_array($item)) {
                $id = $item['id'] ?? $item['element'] ?? null;
                if ($id !== null) $requested[] = ['id' => trim((string) $id, '[] '), 'reason' => $item['reason'] ?? null];
            }
        }
    }
}
$insufficient = is_array($selection) ? !empty($selection['insufficient']) : false;

$byId = [];
foreach ($blocks as $b) {
    $byId[$b['id']] = $b;
}
$parentOf = [];
foreach ($blocks as $b) {
    $parentOf[$b['id']] = $b['parent_id'] ?? null;
}

$accepted = [];
$unknownIds = [];
$duplicates = [];
$collapsed = [];   // descendant dropped because an ancestor was selected
$seen = [];
foreach ($requested as $entry) {
    $id = $entry['id'];
    if (!isset($store[$id]) || !isset($byId[$id])) { $unknownIds[] = $id; continue; }
    if (isset($seen[$id])) { $duplicates[] = $id; continue; }
    $seen[$id] = true;
    $accepted[$id] = $entry;
}
// Deduplicate overlapping parent/child selections: an accepted ancestor wins.
foreach (array_keys($accepted) as $id) {
    $p = $parentOf[$id] ?? null;
    $guard = 0;
    while ($p !== null && $guard++ < 64) {
        if (isset($accepted[$p])) { $collapsed[] = ['dropped' => $id, 'kept_ancestor' => $p]; unset($accepted[$id]); break; }
        $p = $parentOf[$p] ?? null;
    }
}

$order = [];
foreach ($blocks as $b) {
    if (isset($accepted[$b['id']])) $order[] = $b['id'];
}

writeJson($qDir . '/selection.json', [
    'parse_mode' => $parseMode,
    'insufficient' => $insufficient,
    'requested' => $requested,
    'accepted_ids_in_document_order' => $order,
    'unknown_ids' => $unknownIds,
    'duplicate_ids' => $duplicates,
    'collapsed_descendants' => $collapsed,
    'parse_errors' => $parseErrors,
    'raw_json_block' => is_array($selection) ? $selection : null
]);

// ── 7. deterministic extraction of the selected blocks ──────────────────────
[$pipelineBMd, $timings['pipeline_b_assemble_ms']] = chrono(function () use ($order, $store, $byId, $map, $question) {
    $out = [];
    $out[] = '# Pipeline B — model-selected regions (content extracted deterministically)';
    $out[] = '';
    $out[] = '- selection: ' . count($order) . ' block(s)';
    $out[] = '- page: ' . ($map['snapshot']['title'] ?? '') . ' — ' . ($map['snapshot']['url'] ?? '');
    $out[] = '';
    foreach ($order as $id) {
        $entry = $store[$id];
        $block = $byId[$id];
        $out[] = '## [' . $id . '] ' . ($block['tag'] ?? '?')
            . ' (' . ($block['role'] ?? 'no role') . ', ' . ($entry['text_len'] ?? 0) . ' chars)';
        $out[] = '';
        $out[] = (string) ($entry['markdown'] ?? '');
        $out[] = '';
    }
    return implode("\n", $out);
});
file_put_contents($qDir . '/pipeline-b.md', $pipelineBMd);

// ── 8. sizes / token estimates ──────────────────────────────────────────────
// Token counts: the production TokenCounter POSTs to {host}/tokenize and silently
// falls back to a chars/4 estimate when that endpoint is missing. Probe it so the
// artifact says which one actually ran (strata has no /tokenize).
$tokenizeUrl = dirname($apiUrl) . '/tokenize';
$chTok = curl_init($tokenizeUrl);
curl_setopt_array($chTok, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['content' => 'tokenizer probe']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 5,
]);
$tokOut = curl_exec($chTok);
$tokCode = (int) curl_getinfo($chTok, CURLINFO_HTTP_CODE);
curl_close($chTok);
$tokenizerReal = ($tokCode === 200 && is_string($tokOut));

$tokens = ['available' => false];
try {
    $counter = new TokenCounter();
    $tokens = [
        'available' => true,
        'endpoint_supports_tokenize' => $tokenizerReal,
        'tokenize_endpoint' => $tokenizeUrl,
        'tokenize_http_code' => $tokCode,
        'pipeline_a_tokens' => $counter->count($pipelineAMd),
        'pipeline_b_tokens' => $counter->count($pipelineBMd),
        'model_input_tokens' => $counter->count($systemPrompt . "\n" . $userPrompt),
        'note' => $tokenizerReal
            ? 'real token counts from the engine tokenizer'
            : 'ESTIMATE — the configured engine has no /tokenize endpoint, so TokenCounter fell back to chars/4'
    ];
} catch (\Throwable $e) {
    $tokens['error'] = $e->getMessage();
}

$stats = [
    'snapshot_id' => $snapshotId,
    'url' => $map['snapshot']['url'] ?? ($captureMeta['url'] ?? null),
    'question' => $question,
    'pipeline_a' => [
        'extractor_status' => $pipelineA['status'] ?? null,
        'body_chars' => $pipelineA['body_chars'] ?? null,
        'artifact_chars' => strlen($pipelineAMd),
        'entities' => is_array($pipelineA['content']['entities'] ?? null) ? count($pipelineA['content']['entities']) : 0
    ],
    'pipeline_b' => [
        'blocks_total' => count($blocks),
        'blocks_shown_to_model' => count($lines),
        'blocks_omitted_by_budget' => count($omittedBlocks),
        'selected_blocks' => count($order),
        'unknown_ids' => count($unknownIds),
        'collapsed_overlaps' => count($collapsed),
        'artifact_chars' => strlen($pipelineBMd)
    ],
    'map' => [
        'preview_cap_chars' => $map['options']['previewChars'] ?? null,
        'budget_chars' => $budgetChars,
        'map_json_chars' => strlen((string) json_encode($map)),
        'store_json_chars' => strlen((string) json_encode($store)),
        'counts' => $map['counts'] ?? null
    ],
    'timings' => $timings,
    'cost' => [
        'model_latency_ms' => $timings['model_latency_ms'],
        'usage' => $usage,
        'endpoint_timings' => $agentTimings,
        'tokens' => $tokens
    ],
    'failures' => [
        'model_error' => $modelError,
        'parse_errors' => $parseErrors,
        'capture_limitations' => $limitations
    ]
];
writeJson($qDir . '/stats.json', $stats);

// ── 9. side-by-side report (facts only — no automated winner) ───────────────
$reasons = [];
foreach ($requested as $entry) {
    $id = $entry['id'];
    if (isset($accepted[$id])) $reasons[] = '- `' . $id . '` — ' . ($entry['reason'] ?? '(no reason given)');
}
$report = [];
$report[] = '# Extraction comparison — ' . $snapshotId . ' / ' . basename($qDir);
$report[] = '';
$report[] = 'Page: ' . ($stats['url'] ?? '(unknown)');
$report[] = 'Question: ' . $question;
$report[] = 'Run at: ' . date('c');
$report[] = '';
$report[] = '## Inputs and costs (measured)';
$report[] = '';
$report[] = '| metric | value |';
$report[] = '|---|---|';
$report[] = '| capture (nav + extractor) | ' . ($captureTimes['nav_and_extract_ms'] ?? '?') . ' ms |';
$report[] = '| DOM map build | ' . ($captureTimes['map_build_ms'] ?? '?') . ' ms |';
$report[] = '| DOM serialize | ' . ($captureTimes['dom_serialize_ms'] ?? '?') . ' ms |';
$report[] = '| map → prompt render | ' . ($timings['map_prompt_render_ms'] ?? '?') . ' ms |';
$report[] = '| model latency | ' . ($timings['model_latency_ms'] ?? '?') . ' ms |';
$report[] = '| Pipeline A output | ' . strlen($pipelineAMd) . ' chars (' . ($tokens['pipeline_a_tokens'] ?? 'n/a') . ' tok) |';
$report[] = '| Pipeline B output | ' . strlen($pipelineBMd) . ' chars (' . ($tokens['pipeline_b_tokens'] ?? 'n/a') . ' tok) |';
$report[] = '| model input | ' . ($stats['map']['budget_chars'] ?? '?') . ' char budget, ' . count($lines) . ' of ' . count($blocks) . ' blocks shown |';
$report[] = '| model usage | ' . json_encode($usage) . ' |';
$report[] = '';
$report[] = '## Pipeline A — deterministic extractor';
$report[] = '- status `' . ($pipelineA['status'] ?? '?') . '`, ' . ($pipelineA['body_chars'] ?? '?') . ' body chars, '
    . count($pipelineA['content']['entities'] ?? []) . ' entities';
$report[] = '- full output: `pipeline-a.md` (pre-condensation; PHP-side BM25/extractive/condenser fitting excluded)';
$report[] = '';
$report[] = '## Pipeline B — model-guided selection';
$report[] = '- blocks offered: ' . count($lines) . ' shown / ' . count($blocks) . ' captured'
    . ($omittedBlocks ? ' (' . count($omittedBlocks) . ' omitted by budget, disclosed to the model)' : '');
$report[] = '- selected: ' . count($order) . ' → ' . json_encode($order);
$report[] = '- unknown ids: ' . (count($unknownIds) ? json_encode($unknownIds) : 'none')
    . ' | duplicate ids: ' . (count($duplicates) ? json_encode($duplicates) : 'none')
    . ' | collapsed overlaps: ' . (count($collapsed) ? json_encode($collapsed) : 'none');
$report[] = '- model flagged insufficient evidence: ' . ($insufficient ? 'YES' : 'no');
$report[] = '- parse errors: ' . ($parseErrors ? implode('; ', $parseErrors) : 'none');
$report[] = '';
$report[] = '### Model reasons (verbatim)';
$report[] = $reasons ? implode("\n", $reasons) : '(none)';
$report[] = '';
$report[] = '## Capture limitations (affect BOTH pipelines)';
$report[] = '```json';
$report[] = json_encode($limitations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$report[] = '```';
$report[] = '';
$report[] = '## Human review required — no automated winner';
$report[] = 'Neither output length nor token count establishes a winner. Inspect `pipeline-a.md`';
$report[] = 'and `pipeline-b.md` and fill in:';
$report[] = '';
$report[] = '- Relevant information recovered by A only:';
$report[] = '- Relevant information recovered by B only:';
$report[] = '- Important information missed by A:';
$report[] = '- Important information missed by B:';
$report[] = '- Irrelevant/boilerplate text included by A:';
$report[] = '- Irrelevant/boilerplate text included by B:';
$report[] = '- Qualifications, caveats or conditions lost by A:';
$report[] = '- Qualifications, caveats or conditions lost by B:';
$report[] = '- Table relationships / headers lost by A:';
$report[] = '- Table relationships / headers lost by B:';
$report[] = '- Cost/benefit judgement:';
file_put_contents($qDir . '/report.md', implode("\n", $report));

writeJson($qDir . '/timings.json', $timings);
file_put_contents($qDir . '/compare.log', implode("\n", $logLines) . "\n");

note("[done] artifacts: {$qDir}");
note("[done] report:    {$qDir}/report.md");
exit(0);
