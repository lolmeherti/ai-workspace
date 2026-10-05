<?php

declare(strict_types=1);

/**
 * Extraction-compare — answer stage.
 *
 * The question the earlier stages could not answer: does the model write a
 * better answer from the deterministic extractor's full output (Pipeline A) or
 * from the model-selected regions (Pipeline B)? Character counts cannot settle
 * that; two answers can.
 *
 * Both answers come from the SAME model, the SAME system prompt, the SAME
 * question, the SAME sampling settings and a FRESH single-turn message array —
 * no conversation history, no session state, nothing carried over. The only
 * difference between the two calls is the evidence text.
 *
 * Usage (inside the web container):
 *   php answers.php --snapshot=<snapshot-id> [--qdir=q01] [--max-chars=150000]
 *                   [--temperature=0] [--max-tokens=1024] [--mode=instruct]
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\AgentManager;
use App\Config;
use App\Search\TokenCounter;

$args = [];
foreach (array_slice($argv, 1) as $raw) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/i', $raw, $m)) {
        $args[strtolower($m[1])] = $m[2] ?? '1';
    }
}

$snapshot    = $args['snapshot'] ?? null;
$qdirName    = $args['qdir'] ?? 'q01';
$maxChars    = (int) ($args['max-chars'] ?? 150000);
$temperature = isset($args['temperature']) ? (float) $args['temperature'] : 0.0;
$maxTokens   = (int) ($args['max-tokens'] ?? 1024);
$mode        = $args['mode'] ?? 'instruct';
$runsDir     = rtrim($args['runs-dir'] ?? (__DIR__ . '/runs'), '/');

if (!$snapshot) {
    fwrite(STDERR, "FAIL: --snapshot=<id> is required.\n");
    exit(2);
}

$runDir = $runsDir . '/' . $snapshot;
$qDir   = $runDir . '/' . $qdirName;
foreach (['pipeline-a.md', 'pipeline-b.md'] as $required) {
    if (!is_file($qDir . '/' . $required)) {
        fwrite(STDERR, "FAIL: missing {$qDir}/{$required}\n");
        exit(2);
    }
}
// request.json is written by the server at the run root, not per question dir.
$requestFile = is_file($runDir . '/request.json') ? $runDir . '/request.json' : $qDir . '/request.json';
if (!is_file($requestFile)) {
    fwrite(STDERR, "FAIL: no request.json in {$runDir}\n");
    exit(2);
}

Config::load(__DIR__ . '/../..');

/**
 * Strip harness framing so neither side is identifiable and both carry the same
 * kind of structure. Pipeline A's body is already markdown (the extractor writes
 * "#" headings and GFM tables into it); Pipeline B's block markers are converted
 * into the equivalent markdown so the two are comparable rather than one being
 * handicapped by test scaffolding.
 */
function evidenceFor(string $kind, string $md): string
{
    if ($kind === 'p') {
        // Production evidence is XML (<source>/<title>/<domain>/<fetched>/<chunk>).
        // Flatten it to the same shape A and B are rendered in, and remove its own
        // date lines: every route gets source dates from the shared SOURCE DATES
        // line instead, so dates cannot confound the comparison.
        $body = preg_replace_callback(
            '/<(\/?)chunk(?: id="[^"]*")?(?: section="([^"]*)")?>/',
            static function (array $m): string {
                if ($m[1] === '/') return '';
                return isset($m[2]) && $m[2] !== '' ? "\n### " . $m[2] . "\n" : '';
            },
            $md
        ) ?? $md;
        $body = preg_replace('/<title>\s*(.*?)\s*<\/title>/s', "# $1", $body) ?? $body;
        $body = preg_replace('/<\/?(source|domain|fetched|published)( id="[^"]*")?>/', '', $body) ?? $body;
        $body = preg_replace('/^\s*<(fetched|published)>.*$/m', '', $body) ?? $body;
        return trim(preg_replace('/\n{3,}/', "\n\n", $body) ?? $body);
    }
    if ($kind === 'a') {
        $start = strpos($md, '## entity');
        $body = $start === false ? $md : substr($md, $start);
        // drop the per-entity scaffold lines ("## entity 1 — type=article id=…")
        $body = preg_replace('/^## entity .*$/m', '', $body) ?? $body;
    } else {
        $start = strpos($md, '## [');
        $body = $start === false ? $md : substr($md, $start);
        // "## [e45] H3 (heading, 25 chars)" -> "###", other block markers -> nothing
        $body = preg_replace_callback(
            '/^## \[e\d+\] (H[1-6]) \([^)]*\)\s*$/m',
            static fn(array $m): string => str_repeat('#', (int) $m[1][1]),
            $body
        ) ?? $body;
        $body = preg_replace('/^## \[e\d+\] .*$/m', '', $body) ?? $body;
        // a heading marker followed by the heading text becomes one heading line
        $body = preg_replace('/^(#{1,6})\n+(\S)/m', '$1 $2', $body) ?? $body;
    }

    return trim($body);
}

/** @return array{0: string, 1: string} [title, url] */
function sourceTitle(string $runDir): array
{
    $meta = is_file($runDir . '/capture-meta.json')
        ? (json_decode((string) file_get_contents($runDir . '/capture-meta.json'), true) ?: [])
        : [];
    $url = (string) ($meta['url'] ?? '');
    $a = (string) file_get_contents($runDir . '/q01/pipeline-a.md');
    $title = '';
    if (preg_match('/^- page title: (.*)$/m', $a, $m)) {
        $title = trim($m[1]);
    } elseif (preg_match('/^- page: (.*?)(?: — |$)/m', (string) file_get_contents($runDir . '/q01/pipeline-b.md'), $mm)) {
        $title = trim($mm[1]);
    }
    return [$title, $url];
}

// The question lives with the question artifacts; older snapshots kept it in
// stats.json, so accept either.
$question = '';
if (is_file($qDir . '/question.json')) {
    $qj = json_decode((string) file_get_contents($qDir . '/question.json'), true) ?: [];
    $question = (string) ($qj['question'] ?? '');
}
if ($question === '' && is_file($qDir . '/stats.json')) {
    $st = json_decode((string) file_get_contents($qDir . '/stats.json'), true) ?: [];
    $question = (string) ($st['question'] ?? '');
}
if ($question === '') {
    $request = json_decode((string) file_get_contents($requestFile), true) ?: [];
    $question = (string) ($request['question'] ?? '');
}
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--question=')) $question = substr($arg, 11);
}
if ($question === '') {
    fwrite(STDERR, "FAIL: no question found in {$qDir}/question.json or stats.json.\n");
    exit(2);
}

[$title, $url] = sourceTitle($runDir);

// Source dates come from the separate chrome scan (date-probe.php), not from the
// evidence: the extractor discards the header/footer where a page states them.
$sourceDates = '';
$dp = is_file($runDir . '/date-probe.json')
    ? (json_decode((string) file_get_contents($runDir . '/date-probe.json'), true) ?: null)
    : null;
if (is_array($dp)) {
    $parts = [];
    foreach (['published' => 'publication', 'updated' => 'update'] as $key => $label) {
        $value = $dp[$key . 'At'] ?? null;
        if ($value) {
            $own = $dp[$key . '_evidence'] ?? '';
            $parts[] = $label . '=' . $value . ($own !== '' ? ' (page states: "' . $own . '")' : '');
        }
    }
    if (!empty($dp['ambiguous_date'])) $parts[] = 'ambiguous, kind unresolved="' . $dp['ambiguous_date'] . '"';
    $sourceDates = "\nSOURCE DATES: " . ($parts ? implode(' | ', $parts) : 'none stated on the page');
}

$systemPrompt = <<<'PROMPT'
You answer strictly from the evidence provided.
Use only the evidence. Do not add outside knowledge, and do not guess.
If the evidence does not contain something the question asks for, say so plainly.
Keep names, numbers and units exactly as they appear. Be concise and specific.
SOURCE DATES, when given, describe the page itself and were resolved separately from the evidence.
Use them freely: state them, compare their ages, and let them inform how much weight the evidence
deserves. Say which kind each is — a publication date is not an update date. Two limits only: do not
invent a date the page does not state, and do not date the page from a comment's relative timestamp,
a photo caption, or a date quoted from a cited reference.
PROMPT;

$agent   = new AgentManager();
$counter = new TokenCounter();
$results = [];

$routes = ['a' => 'pipeline-a.md', 'b' => 'pipeline-b.md'];
if (is_file($qDir . '/pipeline-prod.md')) $routes['p'] = 'pipeline-prod.md';
foreach ($routes as $kind => $file) {
    $raw = (string) file_get_contents($qDir . '/' . $file);
    $evidence = evidenceFor($kind, $raw);

    $truncated = 0;
    if (strlen($evidence) > $maxChars) {
        $truncated = strlen($evidence) - $maxChars;
        $evidence = substr($evidence, 0, $maxChars)
            . "\n\n[evidence truncated at {$maxChars} characters]";
    }

    // Fresh array every time: no history, no prior turns, nothing carried over.
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => "SOURCE: {$title}\nURL: {$url}{$sourceDates}\n\nEVIDENCE:\n{$evidence}\n\nQUESTION: {$question}"],
    ];

    file_put_contents($qDir . '/answer-' . $kind . '-prompt.txt',
        "===== SYSTEM =====\n{$systemPrompt}\n\n===== USER =====\n" . $messages[1]['content'] . "\n");

    $promptTokens = null;
    try {
        $promptTokens = $counter->count($systemPrompt . "\n" . $messages[1]['content']);
    } catch (\Throwable $e) {
        $promptTokens = null;
    }

    $t0 = microtime(true);
    $error = null;
    $answer = '';
    try {
        $answer = (string) $agent->chat($messages, false, null, $temperature, 'extraction-compare-answer', $mode, null, $maxTokens);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
    $latencyMs = (int) round((microtime(true) - $t0) * 1000);

    file_put_contents($qDir . "/answer-{$kind}.md", $error !== null
        ? "MODEL CALL FAILED: {$error}\n"
        : $answer);

    $results[$kind] = [
        'evidence_chars'    => strlen($evidence),
        'evidence_truncated_chars' => $truncated,
        'prompt_tokens'     => $promptTokens,
        'prompt_tokens_note'=> $promptTokens === null ? 'unavailable (no /tokenize)' : 'counted via /tokenize',
        'answer_chars'      => strlen($answer),
        'answer_est_tokens' => (int) round(strlen($answer) / 4),
        'latency_ms'        => $latencyMs,
        'error'             => $error,
    ];

    printf("[answer-%s] evidence %d chars | prompt %s tok | answer %d chars | %d ms%s\n",
        strtoupper($kind), strlen($evidence), (string) ($promptTokens ?? '?'), strlen($answer), $latencyMs,
        $error !== null ? " | ERROR: {$error}" : '');
}

file_put_contents(
    $qDir . '/answers.json',
    json_encode([
        'snapshot'    => $snapshot,
        'question'    => $question,
        'title'       => $title,
        'url'         => $url,
        'mode'        => $mode,
        'temperature' => $temperature,
        'max_tokens'  => $maxTokens,
        'history'     => 'fresh single-turn array per call — no conversation, no session state',
        'a'           => $results['a'],
        'b'           => $results['b'],
        'p'           => $results['p'] ?? null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo "[answers] wrote " . implode(', ', array_map(static fn($k) => "answer-{$k}.md", array_keys($results)))
    . " and answers.json in {$qDir}\n";
