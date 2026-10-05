<?php

/**
 * Extraction inspector — a dev-only page, not linked from the UI.
 *
 * Paste a link, get the page on the left and two extractions on the right:
 *   • "Localsy pipeline"  — production route: extractor → dedup → BM25 → budget fitting
 *   • "Model selection"   — the experimental route: DOM map → model picks block ids
 * Both run on ONE capture, so the page you see is the page both routes read.
 *
 * It orchestrates the already-verified harness scripts (baseline.php for the
 * production route, compare.php for the model route) rather than reimplementing
 * them, so the numbers here are the same numbers the recorded runs produced.
 * Captures are queued to server.js and served by the harness extension inside the
 * browser you already have open — no new browsing mechanism, production untouched.
 *
 * Open: http://localhost:8080/inspect.php
 * Requires (on the host): node src/tests/extraction-compare/server.js
 *                         + the harness extension loaded in your browser
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';
App\Config::load(__DIR__);

$RUNS = __DIR__ . '/tests/extraction-compare/runs';
$HARNESS = __DIR__ . '/tests/extraction-compare';

// ── helpers ─────────────────────────────────────────────────────────────────

function jout(array $data, int $code = 200): void
{
    // Captured pages routinely contain bytes that are not valid UTF-8; without
    // JSON_INVALID_UTF8_SUBSTITUTE json_encode() returns false and the endpoint
    // answers with an empty body, which looks like a hang on the client.
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
    $json = json_encode($data, $flags);
    if ($json === false) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'json_encode failed: ' . json_last_error_msg(),
            'hint' => 'payload contains data that cannot be encoded (see json_last_error)',
        ]);
        exit;
    }
    http_response_code($code);
    header('Content-Type: application/json');
    echo $json;
    exit;
}

function jread(string $file)
{
    return is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
}

function runCli(string $cmd, int $timeoutSec = 300): array
{
    $out = [];
    $rc = 0;
    $t0 = microtime(true);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) return ['rc' => -1, 'out' => 'cannot start php cli', 'ms' => 0];
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $buf = '';
    $deadline = time() + $timeoutSec;
    while (true) {
        $status = proc_get_status($proc);
        $buf .= (string) stream_get_contents($pipes[1]);
        $buf .= (string) stream_get_contents($pipes[2]);
        if (!$status['running']) { $rc = (int) $status['exitcode']; break; }
        if (time() > $deadline) { proc_terminate($proc); $buf .= "\n[timeout after {$timeoutSec}s]"; $rc = -1; break; }
        usleep(150_000);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    // Xdebug prints a step-debug timeout on stderr in this image; it is noise here.
    $clean = [];
    foreach (preg_split('/\R/', $buf) ?: [] as $line) {
        if (str_contains($line, 'Xdebug:')) continue;
        $clean[] = $line;
    }
    return ['rc' => $rc, 'out' => trim(implode("\n", $clean)), 'ms' => (int) round((microtime(true) - $t0) * 1000)];
}

function newestQdir(string $runBase): ?string
{
    $dirs = glob($runBase . '/q*', GLOB_ONLYDIR) ?: [];
    usort($dirs, static fn($a, $b) => strcmp($a, $b));
    return $dirs ? end($dirs) : null;
}

$act = (string) ($_GET['act'] ?? '');

// ── endpoints ───────────────────────────────────────────────────────────────

if ($act === 'snapshots') {
    $list = [];
    foreach (glob($RUNS . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $id = basename($dir);
        if (str_starts_with($id, '_')) continue;
        $meta = jread($dir . '/capture-meta.json') ?? [];
        $pa = jread($dir . '/pipeline-a.json') ?? [];
        $list[] = [
            'snapshot' => $id,
            'url' => (string) ($meta['url'] ?? ''),
            'title' => (string) ($meta['title'] ?? ''),
            'ok' => (bool) ($meta['ok'] ?? false),
            'failure' => $meta['failure'] ?? null,
            'entities' => (int) ($pa['entity_count'] ?? 0),
            'body_chars' => (int) ($pa['body_chars'] ?? 0),
            'captured_at' => (string) ($meta['captured_at'] ?? ''),
            'newest_qdir' => newestQdir($dir) ? basename(newestQdir($dir)) : null,
        ];
    }
    usort($list, static fn($a, $b) => strcmp($b['snapshot'], $a['snapshot']));
    jout(['snapshots' => array_slice($list, 0, 60)]);
}

if ($act === 'queue') {
    $url = trim((string) ($_POST['url'] ?? ''));
    if ($url === '' || !preg_match('~^https?://~i', $url)) jout(['error' => 'a full http(s) URL is required'], 400);
    $status = jread($RUNS . '/_server-status.json');
    if (!$status) jout(['error' => 'capture server not running — on the host: node src/tests/extraction-compare/server.js'], 503);
    if (time() - (int) strtotime((string) ($status['updated_at'] ?? '1970-01-01')) > 15) {
        jout(['error' => 'capture server status is stale — is server.js still running?'], 503);
    }
    if (empty($status['harness_connected'])) {
        jout(['error' => 'server is up but no browser harness is connected — load harness/ via edge://extensions → Developer mode → Load unpacked'], 503);
    }
    $snapshot = date('Ymd-His') . '-' . substr(sha1($url), 0, 8);
    $dir = $RUNS . '/' . $snapshot;
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) jout(['error' => 'cannot create ' . $dir], 500);
    file_put_contents($dir . '/request.json', json_encode([
        'url' => $url,
        'queued_at' => date('c'),
        'requested_by' => 'inspect.php',
        'options' => null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    jout(['snapshot' => $snapshot, 'queue_length' => (int) ($status['queue_length'] ?? 0)]);
}

if ($act === 'status') {
    $snapshot = (string) ($_GET['snapshot'] ?? '');
    $dir = $RUNS . '/' . $snapshot;
    if (!is_dir($dir)) jout(['error' => 'unknown snapshot'], 404);
    $meta = jread($dir . '/capture-meta.json');
    $failed = jread($dir . '/capture-failed.json');
    $srv = jread($RUNS . '/_server-status.json') ?? [];
    $progress = is_file($dir . '/progress.log') ? trim((string) file_get_contents($dir . '/progress.log')) : '';
    $state = $meta ? ($meta['ok'] ?? false ? 'done' : 'failed') : ($failed ? 'failed' : ((string) ($srv['current'] ?? '') === $snapshot ? 'running' : 'queued'));
    jout([
        'snapshot' => $snapshot,
        'state' => $state,
        'failure' => $failed['failure'] ?? ($meta['failure'] ?? null),
        'progress' => $progress,
        'queue_length' => (int) ($srv['queue_length'] ?? 0),
        'current' => $srv['current'] ?? null,
    ]);
}

if ($act === 'page') {
    $snapshot = (string) ($_GET['snapshot'] ?? '');
    $dir = $RUNS . '/' . $snapshot;
    if (!is_dir($dir)) jout(['error' => 'unknown snapshot'], 404);
    $meta = jread($dir . '/capture-meta.json') ?? [];
    $map = jread($dir . '/dom-map.json') ?? [];
    $pa = jread($dir . '/pipeline-a.json') ?? [];
    $lim = jread($dir . '/capture-limitations.json') ?? ($map['limitations'] ?? []);
    jout([
        'snapshot' => $snapshot,
        'url' => (string) ($meta['url'] ?? ''),
        'title' => (string) ($meta['title'] ?? ''),
        'html' => is_file($dir . '/page.html')
            ? (string) preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{10FFFF}]/u', '', (string) file_get_contents($dir . '/page.html'))
            : '',
        'limitations' => $lim,
        'extractor' => [
            'status' => $pa['status'] ?? null,
            'entities' => (int) ($pa['entity_count'] ?? 0),
            'body_chars' => (int) ($pa['body_chars'] ?? 0),
            'ms' => $pa['ms'] ?? null,
        ],
        'map' => [
            'blocks' => count($map['blocks'] ?? []),
            'counts' => $map['counts'] ?? [],
            'options' => $map['options'] ?? [],
        ],
        'date_probe' => jread($dir . '/date-probe.json'),
    ]);
}

if ($act === 'extract') {
    set_time_limit(300);
    $snapshot = (string) ($_POST['snapshot'] ?? '');
    $question = trim((string) ($_POST['question'] ?? ''));
    $route = (string) ($_POST['route'] ?? 'localsy');
    $formatQuery = (string) ($_POST['format_query'] ?? '') === '1';
    $dir = $RUNS . '/' . $snapshot;
    if (!is_dir($dir)) jout(['error' => 'unknown snapshot'], 404);
    if (!is_file($dir . '/capture-meta.json')) jout(['error' => 'capture not finished'], 409);
    if ($question === '') jout(['error' => 'a question is required — it is the retrieval query both routes work from'], 400);

    // The question decides the extraction on both routes, so it is stored with the
    // capture first; the harness scripts keep their own qNN convention.
    // The production route gets its own directory: compare.php numbers its question
    // dirs q01, q02… on every run, and mixing the two would leave one route's
    // artifacts beside another question's stats.
    $qdirName = 'prod';
    if (!is_dir($dir . '/' . $qdirName)) mkdir($dir . '/' . $qdirName, 0777, true);
    file_put_contents($dir . '/' . $qdirName . '/question.json', json_encode(['question' => $question], JSON_PRETTY_PRINT));

    $queryUsed = $question;
    $querySource = 'the question as typed';
    if ($formatQuery) {
        $t0 = microtime(true);
        $agent = new App\AgentManager();
        $raw = (string) $agent->chat([
            ['role' => 'system', 'content' => 'Rewrite the user request into a short web search query: keywords only, no quotes, no explanation, one line.'],
            ['role' => 'user', 'content' => $question],
        ], false, null, 0.0, 'inspect-query', 'instruct', null, 64);
        $queryUsed = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);
        $querySource = 'model-formatted (' . (int) round((microtime(true) - $t0) * 1000) . ' ms)';
    }

    $php = 'php';
    $payload = ['snapshot' => $snapshot, 'route' => $route, 'question' => $question, 'query_used' => $queryUsed, 'query_source' => $querySource];

    if ($route === 'localsy') {
        // production route: stub note — filled below
        $cmd = $php . ' ' . escapeshellarg($HARNESS . '/baseline.php')
             . ' --snapshot=' . escapeshellarg($snapshot)
             . ' --qdir=' . escapeshellarg($qdirName)
             . ' --question=' . escapeshellarg($question);
        if ($queryUsed !== $question) {
            $cmd .= ' --query=' . escapeshellarg($queryUsed);
        }
        $r = runCli($cmd, 240);
        $md = is_file($dir . '/' . $qdirName . '/pipeline-prod.md') ? (string) file_get_contents($dir . '/' . $qdirName . '/pipeline-prod.md') : '';
        $stats = jread($dir . '/' . $qdirName . '/prod-stats.json');
        $payload += [
            'qdir' => $qdirName,
            'cli_ms' => $r['ms'],
            'cli_rc' => $r['rc'],
            'cli_tail' => mb_substr($r['out'], -1200),
            'markdown' => $md,
            'stats' => $stats,
            'calls' => (int) ($stats['model_calls'] ?? 0),
            'summary' => $stats ? sprintf(
                'extractor → %d chunk(s) → dedup %s → BM25 %d/%d → fit level %s → %s chars (%s of chunk text unused)',
                $stats['chunks_built'], ($stats['dedup']['duplicate'] ?? false) ? 'DROPPED' : 'kept',
                $stats['chunks_selected_bm25'], $stats['chunks_after_dedup'], $stats['fit_level'],
                number_format($stats['evidence_chars']), number_format($stats['deselected_chars'])
            ) : null,
        ];
    } elseif ($route === 'model') {
        $cmd = $php . ' ' . escapeshellarg($HARNESS . '/compare.php')
             . ' --snapshot=' . escapeshellarg($snapshot)
             . ' --question=' . escapeshellarg($question)
             . ' --mode=instruct';
        $r = runCli($cmd, 240);
        $qdir = null;
        if (preg_match('/question_dir=(\S+)/', $r['out'], $m)) {
            $qdir = $dir . '/' . basename($m[1]);
        } else {
            $qdir = newestQdir($dir);
        }
        $md = ($qdir && is_file($qdir . '/pipeline-b.md')) ? (string) file_get_contents($qdir . '/pipeline-b.md') : '';
        $stats = $qdir ? jread($qdir . '/stats.json') : null;
        $sel = $qdir ? jread($qdir . '/selection.json') : null;
        $payload += [
            'qdir' => $qdir ? basename($qdir) : null,
            'cli_ms' => $r['ms'],
            'cli_rc' => $r['rc'],
            'cli_tail' => mb_substr($r['out'], -1200),
            'markdown' => $md,
            'stats' => $stats,
            'selection' => $sel,
            'calls' => 1,
            'summary' => $stats ? sprintf(
                'map %d blocks (%d shown, %d omitted) → model picked %d block(s) with %d unknown id(s) → %s chars',
                $stats['pipeline_b']['blocks_total'], $stats['pipeline_b']['blocks_shown_to_model'],
                $stats['pipeline_b']['blocks_omitted_by_budget'], $stats['pipeline_b']['selected_blocks'],
                $stats['pipeline_b']['unknown_ids'], number_format($stats['pipeline_b']['artifact_chars'])
            ) : null,
        ];
    } else {
        jout(['error' => 'unknown route'], 400);
    }

    jout($payload);
}

if ($act === 'extract_both') {
    // Convenience: run both routes in one request so the page can show them together.
    set_time_limit(400);
    $snapshot = (string) ($_POST['snapshot'] ?? '');
    $question = trim((string) ($_POST['question'] ?? ''));
    $dir = $RUNS . '/' . $snapshot;
    if (!is_dir($dir) || !is_file($dir . '/capture-meta.json')) jout(['error' => 'capture missing or unfinished'], 409);
    if ($question === '') jout(['error' => 'a question is required'], 400);

    $qdirName = 'prod';
    if (!is_dir($dir . '/' . $qdirName)) mkdir($dir . '/' . $qdirName, 0777, true);
    file_put_contents($dir . '/' . $qdirName . '/question.json', json_encode(['question' => $question], JSON_PRETTY_PRINT));

    $localsy = runCli('php ' . escapeshellarg($HARNESS . '/baseline.php') . ' --snapshot=' . escapeshellarg($snapshot)
        . ' --qdir=' . escapeshellarg($qdirName) . ' --question=' . escapeshellarg($question), 240);
    $model = runCli('php ' . escapeshellarg($HARNESS . '/compare.php') . ' --snapshot=' . escapeshellarg($snapshot)
        . ' --question=' . escapeshellarg($question) . ' --mode=instruct', 300);

    $mq = null;
    if (preg_match('/question_dir=(\S+)/', $model['out'], $m)) $mq = $dir . '/' . basename($m[1]);
    if (!$mq) $mq = newestQdir($dir);

    jout([
        'snapshot' => $snapshot,
        'question' => $question,
        'localsy' => [
            'markdown' => (string) @file_get_contents($dir . '/' . $qdirName . '/pipeline-prod.md'),
            'stats' => jread($dir . '/' . $qdirName . '/prod-stats.json'),
            'qdir' => $qdirName,
            'cli_ms' => $localsy['ms'], 'cli_rc' => $localsy['rc'], 'cli_tail' => mb_substr($localsy['out'], -800),
        ],
        'model' => [
            'markdown' => $mq ? (string) @file_get_contents($mq . '/pipeline-b.md') : '',
            'stats' => $mq ? jread($mq . '/stats.json') : null,
            'selection' => $mq ? jread($mq . '/selection.json') : null,
            'qdir' => $mq ? basename($mq) : null,
            'cli_ms' => $model['ms'], 'cli_rc' => $model['rc'], 'cli_tail' => mb_substr($model['out'], -800),
        ],
    ]);
}

if ($act !== '') jout(['error' => 'unknown act: ' . $act], 404);

// ── UI ──────────────────────────────────────────────────────────────────────
$snapshots = [];
foreach (glob($RUNS . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
    $id = basename($dir);
    if (str_starts_with($id, '_')) continue;
    $meta = jread($dir . '/capture-meta.json') ?? [];
    $pa = jread($dir . '/pipeline-a.json') ?? [];
    $snapshots[] = ['id' => $id, 'url' => (string) ($meta['url'] ?? ''), 'ok' => (bool) ($meta['ok'] ?? false), 'entities' => (int) ($pa['entity_count'] ?? 0)];
}
usort($snapshots, static fn($a, $b) => strcmp($b['id'], $a['id']));
$snapshots = array_slice($snapshots, 0, 80);
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Extraction inspector</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<style>
  html, body { background:#0b0d10; color:#e6e8eb; }
  .ghost { background:transparent; border:1px solid #3a4150; color:#e6e8eb; }
  .ghost:hover { border-color:#7d8899; background:rgba(255,255,255,.03); }
  .ghost.on { border-color:#7dd3fc; color:#bae6fd; }
  .pane { border:1px solid #232935; background:#0f1218; }
  pre, code, .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
  .md h1,.md h2,.md h3 { font-weight:600; margin:.8em 0 .35em; }
  .md h1{font-size:1.15rem}.md h2{font-size:1.02rem}.md h3{font-size:.95rem}
  .md ul { list-style:disc; padding-left:1.15rem; margin:.3em 0; }
  .md ol { list-style:decimal; padding-left:1.3rem; margin:.3em 0; }
  .md p { margin:.4em 0; }
  .md table { border-collapse:collapse; margin:.5em 0; font-size:.85em; }
  .md th,.md td { border:1px solid #2b323f; padding:.22em .45em; }
  .md code { background:#1b212b; padding:.05em .3em; border-radius:3px; }
  .scroll { overflow:auto; }
  .badge { font-size:11px; padding:1px 6px; border-radius:999px; border:1px solid #2b323f; }
</style>
</head>
<body class="text-sm">
<div class="max-w-[1700px] mx-auto p-4">

  <div class="flex items-center justify-between mb-3">
    <div>
      <div class="text-base font-semibold">Extraction inspector</div>
      <div class="text-xs opacity-60">one capture, two routes · production untouched · dev page, not linked from the UI</div>
    </div>
    <div class="flex items-center gap-2">
      <span id="srvBadge" class="badge opacity-70">server: checking…</span>
      <a href="logs.php" class="ghost px-3 py-1 rounded text-xs">/logs</a>
    </div>
  </div>

  <div class="pane rounded p-3 mb-3">
    <div class="grid grid-cols-12 gap-2 items-end">
      <label class="col-span-5">
        <div class="text-xs opacity-70 mb-1">Link</div>
        <input id="url" class="w-full bg-[#0b0d10] border border-[#2b323f] rounded px-2 py-1" placeholder="https://…">
      </label>
      <label class="col-span-5">
        <div class="text-xs opacity-70 mb-1">Question <span class="opacity-50">(drives retrieval on both routes; required)</span></div>
        <input id="question" class="w-full bg-[#0b0d10] border border-[#2b323f] rounded px-2 py-1" placeholder="what do you want from this page?">
      </label>
      <div class="col-span-2 flex gap-2">
        <button id="btnCapture" class="ghost px-3 py-1 rounded flex-1">Capture page</button>
      </div>
    </div>
    <div class="flex items-center gap-3 mt-2 text-xs">
      <label class="flex items-center gap-1 opacity-80"><input type="checkbox" id="fmtq"> let the model format the query</label>
      <span class="opacity-50">·</span>
      <label class="flex items-center gap-1 opacity-80">replay:
        <select id="replay" class="bg-[#0b0d10] border border-[#2b323f] rounded px-1 py-0.5">
          <option value="">(recent captures)</option>
          <?php foreach ($snapshots as $s): ?>
            <option value="<?= htmlspecialchars($s['id'], ENT_QUOTES) ?>">
              <?= htmlspecialchars($s['id'] . '  ' . ($s['ok'] ? '' : '[failed] ') . mb_substr($s['url'], 0, 70), ENT_QUOTES) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="flex items-center gap-2 mt-2 text-xs">
      <span class="opacity-60">capture</span>
      <span id="snapChip" class="badge mono opacity-80">none</span>
      <button id="btnCopySnap" class="ghost px-2 py-0.5 rounded text-[11px]">copy id</button>
      <span class="opacity-50">every capture and every run is written to disk — quote the id to have it re-read</span>
    </div>
    <div id="status" class="text-xs mt-2 opacity-80"></div>
    <div id="failBanner" class="hidden mt-2 text-xs border border-red-800 bg-red-950/40 rounded p-2"></div>
  </div>

  <div class="grid grid-cols-2 gap-3">
    <!-- left: the page -->
    <div class="pane rounded flex flex-col" style="height:78vh">
      <div class="flex items-center justify-between px-3 py-2 border-b border-[#232935]">
        <div class="text-xs"><span class="opacity-60">page</span> <span id="pageTitle" class="opacity-90"></span></div>
        <div class="flex items-center gap-2">
          <span id="pageSize" class="badge opacity-70"></span>
          <a id="liveLink" href="#" target="_blank" class="ghost px-2 py-0.5 rounded text-xs">open live</a>
          <button id="btnRaw" class="ghost px-2 py-0.5 rounded text-xs">raw HTML</button>
        </div>
      </div>
      <div id="pageWrap" class="flex-1 scroll">
        <div class="p-3 text-xs opacity-60">Capture a page (or pick a replay) to see it here. The pane renders the DOM as it was captured — scripts are blocked, so it is a static view.</div>
      </div>
    </div>

    <!-- right: extractions -->
    <div class="pane rounded flex flex-col" style="height:78vh">
      <div class="flex items-center justify-between px-3 py-2 border-b border-[#232935]">
        <div class="flex gap-2">
          <button id="btnLocalsy" class="ghost px-3 py-1 rounded text-xs" disabled>Localsy pipeline</button>
          <button id="btnModel" class="ghost px-3 py-1 rounded text-xs" disabled>Model selection</button>
        </div>
        <div class="flex gap-2">
          <button id="btnBoth" class="ghost px-2 py-0.5 rounded text-xs" disabled>run both</button>
          <button id="btnRawEv" class="ghost px-2 py-0.5 rounded text-xs" title="production evidence is XML-wrapped markdown; render or show it verbatim">raw evidence</button>
          <button id="btnStats" class="ghost px-2 py-0.5 rounded text-xs">stats</button>
        </div>
      </div>
      <div id="statsBar" class="hidden px-3 py-2 border-b border-[#232935] text-xs mono"></div>
      <div class="flex-1 scroll">
        <div id="out" class="p-3 text-xs opacity-60">Run a route to see its extraction here. Both routes read the same capture.</div>
      </div>
    </div>
  </div>

  <div id="statsPanel" class="hidden pane rounded p-3 mt-3 text-xs mono scroll" style="max-height:40vh"></div>
</div>

<script>
const $ = (id) => document.getElementById(id);
let snap = null, rawHtml = '', showRaw = false;
let results = { localsy: null, model: null };
let rawEvidence = false;   // false = render the production evidence as markdown

async function api(act, data) {
  const qs = act.startsWith('page') || act === 'status' || act === 'snapshots' ? '&snapshot=' + encodeURIComponent(snap || '') : '';
  const res = await fetch('inspect.php?act=' + act + qs, data ? { method: 'POST', body: new URLSearchParams(data) } : {});
  const j = await res.json().catch(() => ({ error: 'bad JSON from ' + act }));
  if (j && j.error) throw new Error(j.error);
  return j;
}

function setStatus(t) { $('status').textContent = t || ''; }
function setSnap(id) {
  snap = id || null;
  $('snapChip').textContent = id || 'none';
  if (id) { try { history.replaceState(null, '', '#s=' + id); } catch (e) { location.hash = 's=' + id; } }
}
function showFail(t) { $('failBanner').classList.remove('hidden'); $('failBanner').textContent = t; }

async function checkServer() {
  try {
    const r = await fetch('tests/extraction-compare/runs/_server-status.json?t=' + Date.now());
    if (!r.ok) throw new Error('no status file');
    const s = await r.json();
    const fresh = (Date.now() - new Date(s.updated_at).getTime()) < 15000;
    $('srvBadge').textContent = 'server: ' + (fresh ? (s.harness_connected ? 'up · browser connected' : 'up · no browser') : 'stale');
    $('srvBadge').className = 'badge ' + (fresh && s.harness_connected ? 'text-emerald-300 border-emerald-800' : 'text-amber-300 border-amber-800');
  } catch (e) {
    $('srvBadge').textContent = 'server: not running';
    $('srvBadge').className = 'badge text-red-300 border-red-800';
  }
}

async function capture() {
  const url = $('url').value.trim();
  if (!url) return setStatus('paste a link first');
  setStatus('queueing capture…');
  try {
    const j = await api('queue', { url });
    setSnap(j.snapshot);
    setStatus('queued as ' + snap + ' — waiting for the browser harness…');
    poll();
  } catch (e) { showFail(e.message); }
}

async function poll() {
  try {
    const j = await api('status');
    setStatus('capture ' + j.state + (j.progress ? ' · ' + j.progress.split('\n').slice(-1)[0] : ''));
    if (j.state === 'done') { setStatus('capture done — pick a route'); await loadPage(); return; }
    if (j.state === 'failed') { showFail('capture failed: ' + (j.failure || 'unknown')); return; }
    setTimeout(poll, 1500);
  } catch (e) { showFail(e.message); }
}

async function loadPage() {
  const j = await api('page');
  rawHtml = j.html || '';
  $('pageTitle').textContent = j.title || j.url;
  $('pageSize').textContent = Math.round((rawHtml.length / 1024)) + ' KB captured';
  $('liveLink').href = j.url || '#';
  const lim = j.limitations || {};
  const facts = [
    'extractor: ' + (j.extractor.status || '?') + ' · ' + j.extractor.entities + ' entity(ies) · ' + j.extractor.body_chars + ' chars',
    'map: ' + j.map.blocks + ' blocks',
    'text: ' + (lim.innertext_chars ?? '?') + ' visible / ' + (lim.textcontent_chars ?? '?') + ' total chars, hidden ' + (lim.hidden_text_chars ?? '?'),
    (lim.iframe_content_read === false && (lim.iframes || 0) > 0) ? '⚠ ' + lim.iframes + ' iframe(s) not read' : '',
    (lim.shadow_dom_read === false && (lim.shadow_hosts || 0) > 0) ? '⚠ ' + lim.shadow_hosts + ' shadow host(s) not read' : '',
    j.date_probe ? ('date: published ' + (j.date_probe.publishedAt || '—') + ' · updated ' + (j.date_probe.updatedAt || '—') + ' (' + (j.date_probe.published_signal || j.date_probe.updated_signal || 'none') + ')') : '',
  ].filter(Boolean);
  $('pageWrap').innerHTML =
    '<div class="px-3 py-2 border-b border-[#232935] text-[11px] mono space-y-0.5" id="pageFacts">' +
    facts.map(f => '<div class="opacity-70">' + f.replace(/[<>&]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c])) + '</div>').join('') +
    '</div><div id="pageView" class="bg-white"></div>';
  renderPage();
  ['btnLocalsy','btnModel','btnBoth'].forEach(id => $(id).disabled = false);
}

function renderPage() {
  const host = $('pageView');
  if (showRaw) {
    host.className = 'p-2';
    host.innerHTML = '<pre class="text-[11px] whitespace-pre-wrap break-all opacity-80" style="color:#cbd5e1">' +
      (rawHtml.slice(0, 400000).replace(/[<>&]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c]))) + '</pre>';
    host.style.background = '#0b0d10';
  } else {
    host.className = 'bg-white';
    host.innerHTML = '<iframe sandbox="" style="width:100%;height:78vh;border:0;background:#fff" srcdoc="' +
      rawHtml.replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '"></iframe>';
  }
}

function esc(t) { return (t || '').replace(/[<>&]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c])); }
function md(t) { try { return marked.parse(t || ''); } catch (e) { return '<pre>' + esc(t) + '</pre>'; } }

// Production evidence is XML-wrapped markdown (<source>/<title>/<chunk>, text is
// HTML-escaped). Flattened so the two columns can be read the same way: comparing
// a rendered table against raw text makes the model column look better for a
// reason that has nothing to do with what was extracted.
function prodToMd(text) {
  let t = String(text || '')
    .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&');
  t = t.replace(/<source id="([^"]+)">/g, '\n\n**source $1**\n\n');
  t = t.replace(/<\/source>/g, '\n');
  t = t.replace(/<title>\s*([\s\S]*?)\s*<\/title>/g, '# $1\n');
  t = t.replace(/<domain>([^<]*)<\/domain>/g, '');
  t = t.replace(/<chunk id="([^"]+)"(?: section="([^"]*)")?>/g, (m, id, sec) => '\n\n*chunk ' + id + (sec ? ' · ' + sec : '') + '*\n\n');
  t = t.replace(/<\/chunk>/g, '');
  t = t.replace(/<(published|updated|fetched|entity_type|entity_id|entity_subtype|author|score)>([^<]*)<\/\1>/g,
    (m, k, v) => (v ? '* ' + k + ': ' + v + '\n' : ''));
  t = t.replace(/<[a-z_]+>[^<]*<\/[a-z_]+>/g, '');
  return t;
}

function renderRoute(route, text) {
  if (route !== 'localsy') return '<div class="md">' + md(text) + '</div>';
  return rawEvidence
    ? '<pre class="whitespace-pre-wrap text-[11px] opacity-90">' + esc(text) + '</pre>'
    : '<div class="md">' + md(prodToMd(text)) + '</div>';
}

function statsRow(route, s) {
  const parts = [];
  if (route === 'localsy' && s) {
    parts.push('chunks ' + s.chunks_built + ' → BM25 ' + s.chunks_selected_bm25 + '/' + s.chunks_after_dedup,
               'fit ' + s.fit_level,
               s.evidence_chars + ' ch (~' + s.evidence_tokens + ' tok)',
               'dropped ' + s.deselected_chars + ' ch',
               s.model_calls + ' model call(s)',
               'det. ' + Object.values(s.timings || {}).reduce((a, b) => a + b, 0) + ' ms');
  }
  if (route === 'model' && s) {
    parts.push('map ' + s.pipeline_b.blocks_shown_to_model + '/' + s.pipeline_b.blocks_total + ' blocks shown',
               'picked ' + s.pipeline_b.selected_blocks,
               s.pipeline_b.artifact_chars + ' ch',
               '1 model call · ' + (s.timings?.model_latency_ms || '?') + ' ms',
               'tokens ' + (s.cost?.usage?.prompt_tokens || '?') + ' in / ' + (s.cost?.usage?.completion_tokens || '?') + ' out');
  }
  return parts.join('  ·  ');
}

function reasonsHtml(sel) {
  if (!sel || !(sel.requested || []).length) return '';
  return '<div class="mt-3 text-[11px] mono opacity-70"><div class="opacity-90 mb-1">why each block was chosen</div>' +
    sel.requested.map(s => '<div>[' + s.id + '] ' + esc(s.reason || '') + '</div>').join('') + '</div>';
}

function updateStatsBar() {
  const bits = [];
  if (results.localsy) bits.push('LOCALSY: ' + statsRow('localsy', results.localsy.stats));
  if (results.model) bits.push('MODEL: ' + statsRow('model', results.model.stats));
  if (!bits.length) { $('statsBar').classList.add('hidden'); return; }
  $('statsBar').classList.remove('hidden');
  $('statsBar').textContent = bits.join('   ||   ');
}

function renderBoth() {
  const cols = [['localsy', 'Localsy pipeline · production route'], ['model', 'Model selection · experimental']].map(([k, label]) => {
    const r = results[k];
    let body;
    if (!r) {
      body = '<div class="text-xs opacity-50">not run yet — press ' +
        (k === 'localsy' ? 'Localsy pipeline' : 'Model selection') + ' to fill this column</div>';
    } else {
      body = '<div class="text-[11px] mono opacity-60 mb-2">' + esc(r.summary || '') +
        '<br>dir ' + (r.qdir || '?') + ' · cli ' + r.cli_ms + ' ms' +
        (r.query_used && r.query_used !== r.question ? '<br>query: ' + esc(r.query_used) + ' (' + esc(r.query_source) + ')' : '') +
        '</div>' + renderRoute(k, r.markdown) + (k === 'model' ? reasonsHtml(r.selection) : '');
    }
    return '<div><div class="text-xs uppercase tracking-wide opacity-60 mb-1">' + label +
      (r && r.qdir ? ' · ' + r.qdir : '') + '</div>' + body + '</div>';
  }).join('');
  $('out').innerHTML = '<div class="grid grid-cols-2 gap-4">' + cols + '</div>';
  updateStatsBar();
}

async function runRoute(route) {
  const question = $('question').value.trim();
  if (!question) return setStatus('a question is required — it is the retrieval query');
  const other = route === 'localsy' ? 'model' : 'localsy';
  $('out').innerHTML = '<div class="opacity-70">running ' + route + '…</div>' +
    (results[other] ? '<div class="text-xs opacity-50 mt-1">the ' + other + ' column stays as it was</div>' : '');
  const data = { snapshot: snap, question, route };
  if ($('fmtq').checked) data.format_query = '1';
  try {
    const j = await api('extract', data);
    results[route] = j;
    $('statsPanel').textContent = JSON.stringify(j.stats, null, 2);
    $('statsPanel').classList.remove('hidden');
    renderBoth();
    setStatus(route + ' done');
  } catch (e) { showFail(e.message); renderBoth(); }
}

async function runBoth() {
  const question = $('question').value.trim();
  if (!question) return setStatus('a question is required');
  $('out').innerHTML = '<div class="opacity-70">running both routes on the same capture (model route takes ~10–20 s)…</div>';
  try {
    const j = await api('extract_both', { snapshot: snap, question });
    results.localsy = { ...j.localsy, summary: null, question };
    results.model = { ...j.model, summary: null, question };
    $('statsPanel').textContent = JSON.stringify({ localsy: j.localsy.stats, model: j.model.stats, selection: j.model.selection }, null, 2);
    $('statsPanel').classList.remove('hidden');
    renderBoth();
    setStatus('both routes done');
  } catch (e) { showFail(e.message); $('out').innerHTML = '<div class="text-red-300 text-xs">' + e.message + '</div>'; }
}

$('btnCopySnap').onclick = async () => {
  if (!snap) return setStatus('no capture yet');
  try { await navigator.clipboard.writeText(snap); setStatus('copied ' + snap); } catch (e) { setStatus(snap); }
};
$('btnCapture').onclick = capture;
$('btnLocalsy').onclick = () => runRoute('localsy');
$('btnModel').onclick = () => runRoute('model');
$('btnBoth').onclick = runBoth;
$('btnRaw').onclick = () => { showRaw = !showRaw; $('btnRaw').classList.toggle('on', showRaw); renderPage(); };
$('btnStats').onclick = () => $('statsPanel').classList.toggle('hidden');
$('btnRawEv').onclick = () => { rawEvidence = !rawEvidence; $('btnRawEv').classList.toggle('on', rawEvidence); renderBoth(); };
$('replay').onchange = async (e) => {
  if (!e.target.value) return;
  setSnap(e.target.value);
  results = { localsy: null, model: null };
  setStatus('replaying ' + snap);
  await loadPage();
};
// a pasted link carrying #s=<snapshot> reopens that capture
const hashSnap = (location.hash.match(/s=([0-9A-Za-z\-]+)/) || [])[1];
if (hashSnap) {
  const sel = document.getElementById('replay');
  const opt = [...sel.options].find(o => o.value === hashSnap);
  if (opt) { sel.value = hashSnap; setSnap(hashSnap); loadPage(); }
  else setSnap(hashSnap);
}
checkServer();
</script>
</body>
</html>
