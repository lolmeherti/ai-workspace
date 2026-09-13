<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';

use App\Config;
use App\Database;

Config::load(__DIR__);

$db = null;
$error = null;

try {
    $db = new Database();
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

// --- Clear actions (POST, global) -------------------------------------------------
if ($db && isset($_POST['clear']) && $_POST['clear'] === '1') {
    $db->executeStatement("TRUNCATE TABLE app_events");
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
if ($db && isset($_POST['clear_type'])) {
    $db->executeStatement("DELETE FROM app_events WHERE event_type = ?", [$_POST['clear_type']]);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// --- Filters ----------------------------------------------------------------------
$ALL_LEVELS = ['debug', 'info', 'warn', 'error', 'critical'];
$sessionFilter = (int)($_GET['session_id'] ?? 0);
$q             = trim((string)($_GET['q'] ?? ''));
$typeFilter    = trim((string)($_GET['type'] ?? ''));
$sourceFilter  = trim((string)($_GET['source'] ?? ''));
$view          = (($_GET['view'] ?? 'events') === 'domains') ? 'domains' : 'events';

// Level filter. Default = warnings + errors (hide the info/debug noise).
$levelParam = (string)($_GET['level'] ?? '');
if ($levelParam === 'all') {
    $levels = $ALL_LEVELS;
} elseif ($levelParam === '') {
    $levels = ['warn', 'error', 'critical'];
} else {
    $levels = array_values(array_intersect(explode(',', $levelParam), $ALL_LEVELS));
    if (empty($levels)) $levels = ['warn', 'error', 'critical'];
}
$levels = array_values(array_unique($levels));

// --- Query ------------------------------------------------------------------------
// Build a WHERE clause from the active filters, optionally excluding one facet
// dimension so that dimension's counts reflect every OTHER active filter. This
// keeps facet counts consistent with what a click actually reveals (the bug
// where a facet showed "1" but clicking it returned nothing was the facet
// ignoring the level filter).
function buildFiltersWhere(int $sessionFilter, array $levels, array $allLevels, string $typeFilter, string $sourceFilter, string $q, array $exclude = []): array {
    $where = [];
    $params = [];
    if ($sessionFilter > 0) { $where[] = 'session_id = ?'; $params[] = $sessionFilter; }
    if (!in_array('level', $exclude, true) && count($levels) < count($allLevels)) {
        $ph = implode(',', array_fill(0, count($levels), '?'));
        $where[] = "level IN ($ph)";
        foreach ($levels as $l) $params[] = $l;
    }
    if (!in_array('type', $exclude, true) && $typeFilter !== '') { $where[] = 'event_type = ?'; $params[] = $typeFilter; }
    if (!in_array('source', $exclude, true) && $sourceFilter !== '') { $where[] = 'source = ?'; $params[] = $sourceFilter; }
    if (!in_array('q', $exclude, true) && $q !== '') {
        $where[] = "(message LIKE ? OR event_type LIKE ? OR source LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(context, '$.domain')) LIKE ?)";
        $like = "%{$q}%";
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    }
    return [empty($where) ? '' : 'WHERE ' . implode(' AND ', $where), $params];
}

[$whereSql, $params] = buildFiltersWhere($sessionFilter, $levels, $ALL_LEVELS, $typeFilter, $sourceFilter, $q);
[$levelWhere, $levelParams] = buildFiltersWhere($sessionFilter, $levels, $ALL_LEVELS, $typeFilter, $sourceFilter, $q, ['level']);
[$typeWhere, $typeParams] = buildFiltersWhere($sessionFilter, $levels, $ALL_LEVELS, $typeFilter, $sourceFilter, $q, ['type']);
[$srcWhere, $srcParams] = buildFiltersWhere($sessionFilter, $levels, $ALL_LEVELS, $typeFilter, $sourceFilter, $q, ['source']);

$events = [];
$sessionExists = false;
$sessionMeta = null;
if ($db) {
    $orderDir = $sessionFilter > 0 ? 'ASC' : 'DESC';
    $events = $db->query(
        "SELECT id, event_type, session_id, message, context, level, source, created_at
         FROM app_events
         {$whereSql}
         ORDER BY id {$orderDir}
         LIMIT 500",
        $params
    );
    if ($sessionFilter > 0) {
        $m = $db->query("SELECT id, title, context_tokens, created_at FROM chat_sessions WHERE id = ?", [$sessionFilter]);
        $sessionExists = !empty($m);
        if ($sessionExists) $sessionMeta = $m[0];
    }
}

// --- Facet counts (scoped to every filter EXCEPT the facet's own dimension) -------
$levelCounts = [];
$typeCounts = [];
$sourceCounts = [];
if ($db) {
    foreach ($db->query("SELECT level, COUNT(*) c FROM app_events {$levelWhere} GROUP BY level", $levelParams) as $r) {
        $levelCounts[$r['level']] = (int)$r['c'];
    }
    foreach ($db->query("SELECT event_type, COUNT(*) c FROM app_events {$typeWhere} GROUP BY event_type ORDER BY c DESC", $typeParams) as $r) {
        if ((int)$r['c'] > 0) $typeCounts[$r['event_type']] = (int)$r['c'];
    }
    foreach ($db->query("SELECT source, COUNT(*) c FROM app_events {$srcWhere} GROUP BY source ORDER BY c DESC LIMIT 30", $srcParams) as $r) {
        if ((int)$r['c'] > 0) $sourceCounts[(string)$r['source']] = (int)$r['c'];
    }
}

// --- Domain ranking (weighted score per site, from bridge_fetch outcomes) --------
$domainRanking = [];
if ($db && $view === 'domains') {
    $bw = $sessionFilter > 0 ? 'AND session_id = ?' : '';
    $bp = $sessionFilter > 0 ? [$sessionFilter] : [];
    foreach ($db->query("SELECT context, message, created_at FROM app_events WHERE event_type = 'bridge_fetch' {$bw}", $bp) as $r) {
        $ctx = json_decode($r['context'] ?? '', true);
        if (!is_array($ctx)) continue;
        $domain = trim((string)($ctx['domain'] ?? ''));
        if ($domain === '') $domain = (string)(parse_url((string)($ctx['url'] ?? ''), PHP_URL_HOST) ?: '');
        if ($domain === '') continue;
        if ($q !== '' && stripos($domain, $q) === false) continue;

        $outcome = bridgeOutcome($ctx, $r['message']);
        if (!isset($domainRanking[$domain])) {
            $domainRanking[$domain] = ['domain' => $domain, 'score' => 0, 'outcomes' => [], 'total' => 0, 'last_seen' => null];
        }
        $domainRanking[$domain]['outcomes'][$outcome] = ($domainRanking[$domain]['outcomes'][$outcome] ?? 0) + 1;
        $domainRanking[$domain]['score'] += $BRIDGE_SCORES[$outcome] ?? 0;
        $domainRanking[$domain]['total']++;
        if ($domainRanking[$domain]['last_seen'] === null || $r['created_at'] > $domainRanking[$domain]['last_seen']) {
            $domainRanking[$domain]['last_seen'] = $r['created_at'];
        }
    }
    uasort($domainRanking, function ($a, $b) {
        if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
        $ab = $a['outcomes']['blocked'] ?? 0; $bb = $b['outcomes']['blocked'] ?? 0;
        if ($ab !== $bb) return $bb <=> $ab;
        return ($b['last_seen'] ?? '') <=> ($a['last_seen'] ?? '');
    });
}

// --- Display helpers --------------------------------------------------------------
function urlWith(array $overrides): string {
    $p = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') unset($p[$k]);
        else $p[$k] = $v;
    }
    $q = http_build_query($p);
    return 'logs.php' . ($q !== '' ? '?' . $q : '');
}

function levelToggleUrl(string $level, array $current): string {
    if (in_array($level, $current, true)) {
        $next = array_values(array_diff($current, [$level]));
    } else {
        $next = array_merge($current, [$level]);
    }
    $order = ['debug', 'info', 'warn', 'error', 'critical'];
    usort($next, fn($a, $b) => array_search($a, $order) <=> array_search($b, $order));
    if (count($next) === count($order)) return urlWith(['level' => 'all']);
    if (empty($next)) return urlWith(['level' => 'all']);
    return urlWith(['level' => implode(',', $next)]);
}

$LEVEL_STYLE = [
    'debug'    => ['dot' => 'bg-slate-500',    'text' => 'text-slate-400', 'chip' => 'bg-slate-500/10 text-slate-300 border-slate-500/30', 'label' => 'Debug'],
    'info'     => ['dot' => 'bg-sky-400',      'text' => 'text-sky-300',   'chip' => 'bg-sky-500/10 text-sky-300 border-sky-500/30',        'label' => 'Info'],
    'warn'     => ['dot' => 'bg-amber-400',    'text' => 'text-amber-300', 'chip' => 'bg-amber-500/15 text-amber-300 border-amber-500/40',  'label' => 'Warn'],
    'error'    => ['dot' => 'bg-rose-400',     'text' => 'text-rose-300',  'chip' => 'bg-rose-500/15 text-rose-300 border-rose-500/40',     'label' => 'Error'],
    'critical' => ['dot' => 'bg-red-500',      'text' => 'text-red-300',   'chip' => 'bg-red-500/20 text-red-300 border-red-500/50',        'label' => 'Critical'],
];

function humanLabel(string $t): string {
    static $map = [
        'turn_start' => 'Turn started', 'user_message_persisted' => 'User message stored',
        'prompt_assembled' => 'Prompt assembled', 'stream_complete' => 'Response delivered',
        'context_overflow' => 'Context limit reached',
        'llm_request_start' => 'LLM request sent', 'llm_response_done' => 'LLM response received',
        'llm_tool_capable_done' => 'Tool decision', 'llm_tool_request_start' => 'LLM tool request',
        'llm_tool_response_done' => 'LLM tool response', 'llm_tool_parse_error' => 'LLM tool parse error',
        'llm_partial_response' => 'Short response', 'llm_connection_error' => 'LLM connection error',
        'llm_empty_answer_firstpass' => 'Empty answer (first pass)',
        'llm_empty_answer_thinking_exhausted' => 'Empty answer (thinking exhausted)',
        'content_before_tool' => 'Content before tool call',
        'tool_executed' => 'Tool executed', 'tool_execution_failed' => 'Tool failed',
        'search_start' => 'Search started', 'search_pipeline_ok' => 'Search pipeline OK', 'search_no_results' => 'Search: no results',
        'bridge_check_failed' => 'Bridge check failed', 'bridge_serp_fail' => 'Bridge SERP failed',
        'bridge_fetch' => 'Bridge fetch', 'bridge_stop' => 'Bridge stopped', 'bridge_stop_check' => 'Bridge stop check',
        'bridge_dedup' => 'Bridge dedup', 'bridge_serp' => 'Bridge SERP', 'bridge_evidence' => 'Bridge evidence',
        'bridge_host_cooldown' => 'Host cooldown',
        'consolidation_ok' => 'Evidence atomized', 'consolidation_empty' => 'Atomization empty',
        'consolidation_failed' => 'Atomization failed', 'atomization_backlog' => 'Atomization backlog',
        'file_ingested' => 'File ingested', 'file_ingest_failed' => 'File ingest failed',
        'reindex_aborted' => 'Reindex aborted', 'reindex_failed' => 'Reindex failed',
        'job_evaluated' => 'Job evaluated', 'job_parse_invalid_record' => 'Job record invalid',
        'job_parse_invalid_json' => 'Job parse: invalid JSON', 'job_parse_listing' => 'Job listing',
        'job_parse_fetch_failed' => 'Job fetch failed', 'job_listing_fetch' => 'Listing fetched',
        'job_listing_blocked' => 'Listing blocked', 'job_listing_bridge_fail' => 'Listing bridge failed',
        'job_source_failed' => 'Job source failed', 'job_cancel_failed' => 'Job cancel failed', 'job_run_error' => 'Job run error',
        'progress_init' => 'Progress init', 'progress_init_failed' => 'Progress init failed',
        'progress_json_failed' => 'Progress JSON failed', 'progress_write_failed' => 'Progress write failed',
        'info' => 'Info', 'warn' => 'Warning', 'error' => 'Error', 'critical' => 'Critical',
    ];
    return $map[$t] ?? $t;
}

function categoryOf(string $t): string {
    if (str_starts_with($t, 'llm_')) return 'LLM';
    if (str_starts_with($t, 'tool_')) return 'Tools';
    if (str_starts_with($t, 'search_') || str_starts_with($t, 'bridge_')) return 'Search';
    if (str_starts_with($t, 'consolidation_') || $t === 'atomization_backlog') return 'Memory';
    if (str_starts_with($t, 'file_') || str_starts_with($t, 'reindex_')) return 'Files';
    if (str_starts_with($t, 'job_')) return 'Jobs';
    if (str_starts_with($t, 'progress_')) return 'System';
    if (in_array($t, ['turn_start', 'user_message_persisted', 'prompt_assembled', 'stream_complete', 'context_overflow'], true)) return 'Lifecycle';
    return 'System';
}

// Domain-issue scoring: weight each bridge fetch outcome so a domain that keeps
// getting blocked / failing to parse ranks up in points.
$BRIDGE_SCORES = [
    'ok' => 0, 'cooldown' => 1, 'rejected' => 2, 'empty' => 3,
    'timeout' => 4, 'http_error' => 5, 'error' => 8, 'blocked' => 10,
];

// Resolve a bridge fetch's outcome, falling back to the pre-`outcome` event
// shape (where the raw status was the message and `empty` flagged no content).
function bridgeOutcome(array $ctx, string $message): string {
    $o = $ctx['outcome'] ?? null;
    if (is_string($o) && $o !== '') return $o;
    $m = strtolower(trim($message));
    if ($m === 'success') {
        return ((int)($ctx['entity_count'] ?? 0) > 0) ? 'ok' : 'empty';
    }
    if (in_array($m, ['challenge_required', 'consent_required'], true)) return 'blocked';
    if ($m === 'cooldown') return 'cooldown';
    if ($m === 'timeout') return 'timeout';
    if ($m === 'rejected') return 'rejected';
    if (str_starts_with($m, 'http_')) return 'http_error';
    if ($m === 'error' || $m === 'parse_failed') return 'error';
    return !empty($ctx['empty']) ? 'empty' : 'ok';
}

// Strip the " | Context: {json}" suffix the Logger bakes into the message column,
// so we show a clean one-liner and render the structured context separately.
function cleanMessage(string $message): string {
    $pos = strrpos($message, ' | Context: ');
    if ($pos !== false) $message = substr($message, 0, $pos);
    return $message;
}

function fmtNum($v): string {
    if (is_int($v) || is_float($v)) return number_format((float)$v);
    return (string)$v;
}

function fmtMsVal($v): string {
    if ($v === null || $v === '') return '—';
    return fmtNum($v) . 'ms';
}

function fmtScalar($v): string {
    if ($v === null) return '<span class="text-slate-600">—</span>';
    if (is_bool($v)) return $v ? 'true' : 'false';
    if (is_int($v) || is_float($v)) return htmlspecialchars(number_format((float)$v));
    $s = (string)$v;
    if ($s === '') return '<span class="text-slate-600">—</span>';
    return htmlspecialchars(mb_strlen($s) > 160 ? mb_substr($s, 0, 157) . '…' : $s);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnostics — Event Log</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background: #020617; font-size: 15px; }
        .event-card { transition: border-color .15s ease; }
        .event-card:hover { border-color: rgba(34, 211, 238, 0.25); }
        details > summary { cursor: pointer; list-style: none; }
        details > summary::-webkit-details-marker { display: none; }
        details > summary .chev { transition: transform .15s ease; }
        details[open] > summary .chev { transform: rotate(90deg); }
        a.facet:hover { background: rgba(34, 211, 238, 0.08); }
    </style>
</head>
<body class="bg-slate-950 text-slate-200 antialiased">
    <div class="max-w-[1400px] mx-auto p-5">

        <!-- Header -->
        <div class="flex flex-wrap items-center gap-3 mb-4 pb-4 border-b border-slate-800">
            <h1 class="text-lg font-bold text-cyan-400">Diagnostics — Event Log</h1>

            <form method="GET" class="flex items-center gap-2 ml-2">
                <label class="text-sm text-slate-500">Session</label>
                <input type="number" name="session_id" value="<?php echo $sessionFilter > 0 ? $sessionFilter : ''; ?>"
                       placeholder="paste id"
                       class="px-3 py-1.5 text-sm rounded bg-slate-900 border border-slate-700 text-slate-200 focus:border-cyan-500/50 focus:outline-none w-28" />
                <button class="px-3 py-1.5 text-sm rounded bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 hover:bg-cyan-500/20">Go</button>
            </form>

            <form method="GET" class="flex items-center gap-2 flex-1 min-w-[200px]">
                <input type="hidden" name="session_id" value="<?php echo $sessionFilter; ?>" />
                <?php if ($typeFilter) echo '<input type="hidden" name="type" value="' . htmlspecialchars($typeFilter) . '">'; ?>
                <?php if ($sourceFilter) echo '<input type="hidden" name="source" value="' . htmlspecialchars($sourceFilter) . '">'; ?>
                <input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search message, type, source…"
                       class="px-3 py-1.5 text-sm rounded bg-slate-900 border border-slate-700 text-slate-200 focus:border-cyan-500/50 focus:outline-none flex-1" />
                <button class="px-3 py-1.5 text-sm rounded bg-slate-800 text-slate-300 border border-slate-700 hover:bg-slate-700">Search</button>
            </form>

            <div class="flex items-center gap-2">
                <a href="<?php echo htmlspecialchars(urlWith(['view' => ($view === 'domains' ? null : 'domains')])); ?>"
                   class="px-3 py-1.5 text-sm rounded bg-violet-500/10 text-violet-300 border border-violet-500/30 hover:bg-violet-500/20">
                    <?php echo $view === 'domains' ? 'Events' : 'Site ranking'; ?>
                </a>
                <a href="logs.php" class="px-3 py-1.5 text-sm rounded bg-slate-800 text-slate-300 border border-slate-700 hover:bg-slate-700">Reset</a>
                <form method="POST" onsubmit="return confirm('Clear ALL events?')">
                    <input type="hidden" name="clear" value="1">
                    <button class="px-3 py-1.5 text-sm rounded bg-rose-500/10 text-rose-400 border border-rose-500/30 hover:bg-rose-500/20">Clear All</button>
                </form>
            </div>
        </div>

        <?php if ($error): ?>
        <div class="bg-rose-500/10 border border-rose-500/30 rounded-lg p-4 text-rose-400 mb-5 text-sm">
            Database connection failed: <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <?php if ($view === 'events'): ?>
        <!-- Level filter chips -->
        <div class="flex flex-wrap items-center gap-2 mb-5">
            <span class="text-sm text-slate-500 mr-1">Show:</span>
            <?php foreach ($ALL_LEVELS as $lvl): ?>
            <?php $s = $LEVEL_STYLE[$lvl]; $on = in_array($lvl, $levels, true); $c = $levelCounts[$lvl] ?? 0; ?>
            <a href="<?php echo htmlspecialchars(levelToggleUrl($lvl, $levels)); ?>"
               class="text-sm px-3 py-1 rounded-full border transition-colors <?php echo $on ? $s['chip'] : 'bg-slate-900 text-slate-500 border-slate-700 hover:border-slate-500'; ?>">
                <?php echo $s['label']; ?> <span class="opacity-60"><?php echo number_format($c); ?></span>
            </a>
            <?php endforeach; ?>
            <a href="<?php echo htmlspecialchars(urlWith(['level' => 'all'])); ?>"
               class="text-sm px-3 py-1 rounded-full border <?php echo count($levels) === count($ALL_LEVELS) ? 'bg-slate-300 text-slate-900 border-slate-300' : 'bg-slate-900 text-slate-500 border-slate-700 hover:border-slate-500'; ?>">All</a>

            <?php if ($typeFilter || $sourceFilter || $q !== '' || $sessionFilter > 0): ?>
            <span class="text-sm text-slate-600 ml-2">· active filters:</span>
            <?php if ($sessionFilter > 0): ?><a href="<?php echo htmlspecialchars(urlWith(['session_id' => null])); ?>" class="text-sm px-2 py-0.5 rounded bg-cyan-500/10 text-cyan-300 border border-cyan-500/30">session #<?php echo $sessionFilter; ?> ✕</a><?php endif; ?>
            <?php if ($typeFilter): ?><a href="<?php echo htmlspecialchars(urlWith(['type' => null])); ?>" class="text-sm px-2 py-0.5 rounded bg-violet-500/10 text-violet-300 border border-violet-500/30"><?php echo htmlspecialchars($typeFilter); ?> ✕</a><?php endif; ?>
            <?php if ($sourceFilter): ?><a href="<?php echo htmlspecialchars(urlWith(['source' => null])); ?>" class="text-sm px-2 py-0.5 rounded bg-teal-500/10 text-teal-300 border border-teal-500/30"><?php echo htmlspecialchars($sourceFilter); ?> ✕</a><?php endif; ?>
            <?php if ($q !== ''): ?><a href="<?php echo htmlspecialchars(urlWith(['q' => null])); ?>" class="text-sm px-2 py-0.5 rounded bg-slate-700 text-slate-300 border border-slate-600">“<?php echo htmlspecialchars($q); ?>” ✕</a><?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($sessionFilter > 0): ?>
        <!-- Session banner -->
        <div class="bg-slate-900 border border-cyan-500/20 rounded-lg p-4 mb-5">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold text-cyan-300">Session #<?php echo $sessionFilter; ?></h2>
                    <?php if ($sessionMeta): ?>
                    <p class="text-sm text-slate-400 mt-0.5"><?php echo htmlspecialchars($sessionMeta['title'] ?? ''); ?> · created <?php echo htmlspecialchars($sessionMeta['created_at'] ?? ''); ?> · context <?php echo number_format((int)($sessionMeta['context_tokens'] ?? 0)); ?> tok</p>
                    <?php else: ?>
                    <p class="text-sm text-slate-500 mt-0.5">Session row not found — showing events that carry this id only.</p>
                    <?php endif; ?>
                </div>
                <div class="text-sm text-slate-400"><?php echo number_format(count($events)); ?> events shown (of the filtered set)</div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($view === 'domains'): ?>
        <div>
            <h2 class="text-base font-semibold text-cyan-300 mb-1">Domain ranking — problem sites</h2>
            <p class="text-sm text-slate-500 mb-4">Weighted by fetch trouble. Scoring: blocked +10 · error +8 · HTTP error +5 · timeout +4 · no content +3 · rejected +2 · cooldown +1. Click a domain to see its fetches.</p>
            <?php if (empty($domainRanking)): ?>
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-10 text-center text-slate-500 text-sm">
                No web-fetch events yet. Run some searches and problematic domains will surface here.
            </div>
            <?php else: ?>
            <div class="bg-slate-900 border border-slate-800 rounded-lg overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-800 text-left text-xs text-slate-500 uppercase tracking-wider">
                            <th class="p-3 w-12">#</th>
                            <th class="p-3">Domain</th>
                            <th class="p-3 w-20 text-right">Score</th>
                            <th class="p-3 w-20 text-right">blocked</th>
                            <th class="p-3 w-20 text-right">timeout</th>
                            <th class="p-3 w-24 text-right">no content</th>
                            <th class="p-3 w-20 text-right">HTTP err</th>
                            <th class="p-3 w-16 text-right">error</th>
                            <th class="p-3 w-16 text-right">rejected</th>
                            <th class="p-3 w-20 text-right">parsed OK</th>
                            <th class="p-3 w-16 text-right">total</th>
                            <th class="p-3">last seen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank = 0; foreach ($domainRanking as $d): $rank++; $o = $d['outcomes']; $sc = $d['score']; ?>
                        <tr class="border-b border-slate-800/50 hover:bg-slate-800/30">
                            <td class="p-3 text-slate-500"><?php echo $rank; ?></td>
                            <td class="p-3"><a href="<?php echo htmlspecialchars(urlWith(['view' => null, 'type' => 'bridge_fetch', 'q' => $d['domain'], 'level' => 'all'])); ?>" class="text-cyan-300 hover:text-cyan-200 font-medium"><?php echo htmlspecialchars($d['domain']); ?></a></td>
                            <td class="p-3 text-right"><span class="px-2 py-0.5 rounded text-xs font-bold <?php echo $sc >= 20 ? 'bg-red-500/25 text-red-300' : ($sc >= 8 ? 'bg-amber-500/15 text-amber-300' : 'bg-slate-800 text-slate-300'); ?>"><?php echo $sc; ?></span></td>
                            <td class="p-3 text-right <?php echo ($o['blocked'] ?? 0) > 0 ? 'text-rose-300 font-medium' : 'text-slate-600'; ?>"><?php echo $o['blocked'] ?? 0; ?></td>
                            <td class="p-3 text-right <?php echo ($o['timeout'] ?? 0) > 0 ? 'text-amber-300' : 'text-slate-600'; ?>"><?php echo $o['timeout'] ?? 0; ?></td>
                            <td class="p-3 text-right <?php echo ($o['empty'] ?? 0) > 0 ? 'text-amber-300' : 'text-slate-600'; ?>"><?php echo $o['empty'] ?? 0; ?></td>
                            <td class="p-3 text-right <?php echo ($o['http_error'] ?? 0) > 0 ? 'text-orange-300' : 'text-slate-600'; ?>"><?php echo $o['http_error'] ?? 0; ?></td>
                            <td class="p-3 text-right <?php echo ($o['error'] ?? 0) > 0 ? 'text-rose-300' : 'text-slate-600'; ?>"><?php echo $o['error'] ?? 0; ?></td>
                            <td class="p-3 text-right <?php echo ($o['rejected'] ?? 0) > 0 ? 'text-slate-400' : 'text-slate-600'; ?>"><?php echo $o['rejected'] ?? 0; ?></td>
                            <td class="p-3 text-right <?php echo ($o['ok'] ?? 0) > 0 ? 'text-emerald-300' : 'text-slate-600'; ?>"><?php echo $o['ok'] ?? 0; ?></td>
                            <td class="p-3 text-right text-slate-300"><?php echo $d['total']; ?></td>
                            <td class="p-3 text-slate-500"><?php echo htmlspecialchars($d['last_seen'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-[260px_1fr] gap-5 items-start">

            <!-- Facets -->
            <div class="space-y-5">
                <div>
                    <h3 class="text-sm font-semibold text-slate-400 uppercase tracking-wider mb-2">Event types</h3>
                    <div class="max-h-[420px] overflow-y-auto pr-1 space-y-0.5">
                        <?php foreach ($typeCounts as $t => $c): ?>
                        <?php $active = $t === $typeFilter; ?>
                        <a href="<?php echo htmlspecialchars($active ? urlWith(['type' => null]) : urlWith(['type' => $t])); ?>"
                           class="facet flex items-center justify-between gap-2 px-2 py-1 rounded text-sm <?php echo $active ? 'bg-cyan-500/15 text-cyan-200' : 'text-slate-300'; ?>">
                            <span class="truncate" title="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars(humanLabel($t)); ?></span>
                            <span class="text-xs <?php echo $active ? 'text-cyan-400' : 'text-slate-600'; ?>"><?php echo number_format($c); ?></span>
                        </a>
                        <?php endforeach; ?>
                        <?php if (empty($typeCounts)): ?><p class="text-sm text-slate-600 px-2">No events yet.</p><?php endif; ?>
                    </div>
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-slate-400 uppercase tracking-wider mb-2">Sources</h3>
                    <div class="max-h-[280px] overflow-y-auto pr-1 space-y-0.5">
                        <?php foreach ($sourceCounts as $src => $c): ?>
                        <?php $active = $src === $sourceFilter; $label = $src === '' ? '(none)' : $src; ?>
                        <a href="<?php echo htmlspecialchars($active ? urlWith(['source' => null]) : urlWith(['source' => $src])); ?>"
                           class="facet flex items-center justify-between gap-2 px-2 py-1 rounded text-sm <?php echo $active ? 'bg-teal-500/15 text-teal-200' : 'text-slate-300'; ?>">
                            <span class="truncate" title="<?php echo htmlspecialchars($label); ?>"><?php echo htmlspecialchars($label); ?></span>
                            <span class="text-xs <?php echo $active ? 'text-teal-400' : 'text-slate-600'; ?>"><?php echo number_format($c); ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Event stream -->
            <div>
                <?php if (empty($events)): ?>
                <div class="bg-slate-900 border border-slate-800 rounded-lg p-10 text-center text-slate-500 text-sm">
                    No events match the current filters.
                    <div class="mt-3"><a href="logs.php<?php echo $sessionFilter ? '?session_id=' . $sessionFilter : ''; ?>" class="text-cyan-400 hover:text-cyan-300">Reset filters</a></div>
                </div>
                <?php else: ?>
                <div class="text-sm text-slate-500 mb-3"><?php echo number_format(count($events)); ?> events <?php echo $sessionFilter > 0 ? '(chronological)' : '(most recent first)'; ?></div>

                <?php
                $shown = 0;
                foreach ($events as $ev):
                    $shown++;
                    $ctx = json_decode($ev['context'] ?? '', true);
                    if (!is_array($ctx)) $ctx = [];
                    $ls = $LEVEL_STYLE[$ev['level']] ?? $LEVEL_STYLE['info'];
                    $msg = cleanMessage($ev['message']);
                    $cat = categoryOf($ev['event_type']);
                ?>
                <div class="event-card bg-slate-900 border border-slate-800 rounded-lg p-4 mb-2">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="w-2 h-2 rounded-full shrink-0 <?php echo $ls['dot']; ?>"></span>
                        <span class="text-base font-semibold text-slate-100"><?php echo htmlspecialchars(humanLabel($ev['event_type'])); ?></span>
                        <span class="text-xs text-slate-600 font-mono"><?php echo htmlspecialchars($ev['event_type']); ?></span>
                        <span class="text-xs px-1.5 py-0.5 rounded border <?php echo $ls['chip']; ?> uppercase tracking-wide"><?php echo $ls['label']; ?></span>
                        <span class="ml-auto text-sm text-slate-500"><?php echo htmlspecialchars($ev['created_at']); ?></span>
                        <?php if (!empty($ev['session_id'])): ?>
                        <a href="logs.php?session_id=<?php echo (int)$ev['session_id']; ?>" class="text-sm text-cyan-400 hover:text-cyan-300">#<?php echo (int)$ev['session_id']; ?></a>
                        <?php endif; ?>
                        <?php if (!empty($ev['source'])): ?>
                        <a href="<?php echo htmlspecialchars(urlWith(['source' => $ev['source']])); ?>" class="text-sm text-slate-500 hover:text-slate-300"><?php echo htmlspecialchars($ev['source']); ?></a>
                        <?php endif; ?>
                    </div>

                    <div class="text-base text-slate-300 mt-2 whitespace-pre-wrap break-words leading-relaxed"><?php echo htmlspecialchars($msg); ?></div>

                    <?php
                    // Inline metric summary for LLM/turn-metrics events (readable at a glance).
                    $inline = '';
                    if ($ev['event_type'] === 'turn_metrics') {
                        $parts = [];
                        if (isset($ctx['total_ms'])) $parts[] = 'total ' . fmtMsVal($ctx['total_ms']);
                        if (isset($ctx['ttft_ms'])) $parts[] = 'ttft ' . fmtMsVal($ctx['ttft_ms']);
                        if (isset($ctx['calls']) && is_array($ctx['calls'])) $parts[] = count($ctx['calls']) . ' call(s)';
                        $inline = implode(' · ', $parts);
                    } elseif (str_starts_with($ev['event_type'], 'llm_')) {
                        $parts = [];
                        if (isset($ctx['reasoning_ms']) && $ctx['reasoning_ms'] > 0) $parts[] = 'think ' . fmtMsVal($ctx['reasoning_ms']);
                        if (isset($ctx['content_ms']) && $ctx['content_ms'] > 0) $parts[] = 'text ' . fmtMsVal($ctx['content_ms']);
                        if (isset($ctx['elapsed_ms'])) $parts[] = 'wall ' . fmtMsVal($ctx['elapsed_ms']);
                        $u = $ctx['tokens_used'] ?? null;
                        if (is_array($u)) {
                            $tok = [];
                            if (isset($u['prompt_tokens'])) $tok[] = 'prompt ' . number_format((int)$u['prompt_tokens']);
                            if (isset($u['completion_tokens'])) $tok[] = 'completion ' . number_format((int)$u['completion_tokens']);
                            if ($tok) $parts[] = implode(' · ', $tok);
                        }
                        if (isset($ctx['finish_reason'])) $parts[] = 'finish: ' . htmlspecialchars((string)$ctx['finish_reason']);
                        $inline = implode(' · ', $parts);
                    }
                    if ($inline !== ''): ?>
                    <div class="text-sm text-slate-400 mt-1.5"><?php echo $inline; ?></div>
                    <?php endif; ?>

                    <?php if (!empty($ctx)): ?>
                    <details class="mt-2">
                        <summary class="flex items-center gap-1 text-sm text-slate-500 hover:text-slate-300 select-none">
                            <span class="chev inline-block">▸</span> details
                        </summary>
                        <div class="mt-2 pl-4 border-l border-slate-800 space-y-1.5">
                            <?php
                            foreach ($ctx as $k => $v):
                                if (is_array($v)):
                                    // Special-case: per-call metrics table.
                                    if ($k === 'calls' && is_array($v) && isset($v[0]['purpose'])): ?>
                                        <table class="w-full text-sm text-slate-400 border-collapse">
                                            <thead><tr class="text-slate-500 text-left">
                                                <th class="py-1 pr-3 font-medium">purpose</th>
                                                <th class="py-1 pr-3 font-medium">time</th>
                                                <th class="py-1 pr-3 font-medium">prefill</th>
                                                <th class="py-1 pr-3 font-medium">think</th>
                                                <th class="py-1 pr-3 font-medium">text</th>
                                                <th class="py-1 pr-3 font-medium">prompt tok</th>
                                                <th class="py-1 font-medium">comp tok</th>
                                            </tr></thead>
                                            <tbody>
                                            <?php foreach ($v as $c): ?>
                                                <tr class="border-t border-slate-800/50">
                                                    <td class="py-1 pr-3"><?php echo htmlspecialchars((string)($c['purpose'] ?? '?')); ?></td>
                                                    <td class="py-1 pr-3"><?php echo fmtMsVal($c['elapsed_ms'] ?? null); ?></td>
                                                    <td class="py-1 pr-3"><?php echo ($c['prompt_ms'] ?? 0) > 0 ? fmtMsVal($c['prompt_ms']) . ' · ' . number_format((int)($c['prompt_n'] ?? 0)) . ' tok' . (($c['cache_n'] ?? 0) > 0 ? ' · ' . number_format((int)$c['cache_n']) . ' cached' : '') : '—'; ?></td>
                                                    <td class="py-1 pr-3"><?php echo ($c['reasoning_ms'] ?? 0) > 0 ? fmtMsVal($c['reasoning_ms']) . ' · ' . number_format((int)($c['reasoning_tok'] ?? 0)) . ' tok' : '—'; ?></td>
                                                    <td class="py-1 pr-3"><?php echo ($c['content_ms'] ?? 0) > 0 ? fmtMsVal($c['content_ms']) . ' · ' . number_format((int)($c['content_tok'] ?? 0)) . ' tok' : '—'; ?></td>
                                                    <td class="py-1 pr-3"><?php echo number_format((int)($c['prompt_tokens'] ?? 0)); ?></td>
                                                    <td class="py-1"><?php echo number_format((int)($c['completion_tokens'] ?? 0)); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php elseif ($k === 'tokens_used' && is_array($v)): ?>
                                        <div class="text-sm"><span class="text-slate-500">tokens:</span> prompt <?php echo number_format((int)($v['prompt_tokens'] ?? 0)); ?> · completion <?php echo number_format((int)($v['completion_tokens'] ?? 0)); ?> · total <?php echo number_format((int)($v['total_tokens'] ?? 0)); ?></div>
                                    <?php else: ?>
                                        <details class="text-sm">
                                            <summary class="text-slate-500 hover:text-slate-300"><span class="text-slate-400"><?php echo htmlspecialchars((string)$k); ?></span> <span class="text-slate-600">(<?php echo count($v); ?> items)</span></summary>
                                            <pre class="mt-1 text-xs text-slate-500 bg-slate-950 p-2 rounded overflow-x-auto whitespace-pre-wrap"><?php echo htmlspecialchars(json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
                                        </details>
                                    <?php endif;
                                else: ?>
                                    <div class="text-sm flex gap-2"><span class="text-slate-500 shrink-0"><?php echo htmlspecialchars((string)$k); ?>:</span><span class="text-slate-300"><?php echo fmtScalar($v); ?></span></div>
                                <?php endif;
                            endforeach; ?>
                        </div>
                    </details>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="mt-8 pt-4 border-t border-slate-800 text-sm text-slate-600 text-center">
            Access at /logs or /logs.php — not linked from the main UI
        </div>
    </div>
</body>
</html>
