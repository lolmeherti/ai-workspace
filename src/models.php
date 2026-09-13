<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';

use App\Config;
use App\Database;
use App\Services\ModelPerformanceReport;
use App\Actions\RateReplyAction;

Config::load(__DIR__);

$db = null;
$error = null;
try {
    $db = new Database();
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

// Reset statistics: clear ratings, per-turn speed metrics and model attribution
// so the dashboard starts fresh (chat history and the event log are kept).
if ($db && isset($_POST['reset_stats']) && $_POST['reset_stats'] === '1') {
    $db->executeStatement("UPDATE chat_history SET rating = NULL, rating_reason = NULL, perf_metrics = NULL, model = NULL WHERE role = 'assistant'");
    $db->executeStatement("UPDATE app_events SET model = NULL WHERE model IS NOT NULL");
    header('Location: models.php');
    exit;
}

$report = [];
if ($db) {
    $historyRows = $db->query(
        "SELECT model, perf_metrics, rating, rating_reason FROM chat_history WHERE role = 'assistant' AND model IS NOT NULL"
    );
    $eventRows = $db->query(
        "SELECT model, event_type FROM app_events WHERE model IS NOT NULL AND level IN ('warn','error','critical')"
    );
    $report = (new ModelPerformanceReport())->build($historyRows, $eventRows);
}

$FAULT_LABELS = [
    'llm_tool_parse_error' => 'Tool call parse error',
    'llm_tool_no_match' => 'Tool call no match',
    'llm_partial_response' => 'Partial response',
    'llm_empty_answer_firstpass' => 'Empty answer (first pass)',
    'llm_empty_answer_thinking_exhausted' => 'Empty answer (thinking exhausted)',
    'content_before_tool' => 'Content before tool',
];

function fmt1($v): string
{
    if ($v === null) {
        return '—';
    }
    return number_format((float)$v, 1);
}

function reasonLabel(string $key): string
{
    return RateReplyAction::DOWNVOTE_REASONS[$key] ?? $key;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Model Performance</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background: #020617; font-size: 14px; }
        details > summary { cursor: pointer; list-style: none; }
        details > summary::-webkit-details-marker { display: none; }
        details > summary .chev { transition: transform .15s ease; }
        details[open] > summary .chev { transform: rotate(90deg); }
    </style>
</head>
<body class="bg-slate-950 text-slate-200 antialiased">
    <div class="max-w-[1400px] mx-auto p-5">
        <div class="flex flex-wrap items-center gap-3 mb-1 pb-4 border-b border-slate-800">
            <h1 class="text-lg font-bold text-cyan-400">Model Performance</h1>
            <span class="text-sm text-slate-500 ml-1">raw per-model numbers — LLM speed, faults, satisfaction</span>
            <a href="logs.php" class="ml-auto px-3 py-1.5 text-sm rounded bg-slate-800 text-slate-300 border border-slate-700 hover:bg-slate-700">Event log →</a>
            <form method="POST" class="ml-2" onsubmit="return confirm('Reset all model statistics? This clears ratings, speed metrics and model attribution.');">
                <button type="submit" name="reset_stats" value="1" class="px-3 py-1.5 text-sm rounded bg-transparent text-rose-300 border border-rose-500/30 hover:bg-rose-500/10 transition-colors">Reset statistics</button>
            </form>
        </div>
        <p class="text-xs text-slate-600 mb-5">
            decode tok/s = llama.cpp <code>predicted_per_second</code> · prefill tok/s = <code>prompt_per_second</code> ·
            cache % = KV-cache hits / prompt tokens. Tool-decision emission is excluded from speed; only the answer pass counts.
            Faults are LLM-attributable only — connection errors and tool/bridge/OS failures are NOT counted against the model.
        </p>

        <?php if ($error): ?>
        <div class="bg-rose-500/10 border border-rose-500/30 rounded-lg p-4 text-rose-400 mb-5 text-sm">
            Database connection failed: <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <?php if (empty($report)): ?>
        <div class="bg-slate-900 border border-slate-800 rounded-lg p-10 text-center text-slate-500 text-sm">
            No attributed data yet. Turns (and errors) are attributed from the first time a model runs after this feature ships.
        </div>
        <?php else: ?>
        <div class="bg-slate-900 border border-slate-800 rounded-lg overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-left text-xs text-slate-500 uppercase tracking-wider">
                        <th class="p-3">Model</th>
                        <th class="p-3 text-right">turns</th>
                        <th class="p-3 text-right">decode tok/s</th>
                        <th class="p-3 text-right">prefill tok/s</th>
                        <th class="p-3 text-right">cache %</th>
                        <th class="p-3 text-right">model faults</th>
                        <th class="p-3 text-right">other errors</th>
                        <th class="p-3 text-right">▲</th>
                        <th class="p-3 text-right">▼</th>
                        <th class="p-3 text-right">% positive</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report as $r): ?>
                    <?php
                        $faultsUrl = 'logs.php?level=all&q=' . urlencode($r['model']);
                        $pct = $r['pct_positive'];
                        $pctClass = $pct === null ? 'text-slate-600' : ($pct >= 75 ? 'text-emerald-300' : ($pct >= 50 ? 'text-amber-300' : 'text-rose-300'));
                    ?>
                    <tr class="border-b border-slate-800/50 hover:bg-slate-800/30 align-top">
                        <td class="p-3 font-medium text-slate-100 whitespace-nowrap"><?php echo htmlspecialchars($r['model']); ?></td>
                        <td class="p-3 text-right text-slate-300"><?php echo number_format($r['turns']); ?></td>
                        <td class="p-3 text-right text-slate-300"><?php echo fmt1($r['avg_decode_tps']); ?></td>
                        <td class="p-3 text-right text-slate-300"><?php echo fmt1($r['avg_prefill_tps']); ?></td>
                        <td class="p-3 text-right text-slate-300"><?php echo fmt1($r['avg_cache_pct']); ?></td>
                        <td class="p-3 text-right">
                            <?php if ($r['model_faults'] > 0): ?>
                            <a href="<?php echo htmlspecialchars($faultsUrl); ?>" class="text-rose-300 font-medium hover:text-rose-200"><?php echo number_format($r['model_faults']); ?></a>
                            <?php else: ?><span class="text-slate-600">0</span><?php endif; ?>
                        </td>
                        <td class="p-3 text-right text-slate-500"><?php echo number_format($r['other_errors']); ?></td>
                        <td class="p-3 text-right text-emerald-300"><?php echo number_format($r['up']); ?></td>
                        <td class="p-3 text-right text-rose-300"><?php echo number_format($r['down']); ?></td>
                        <td class="p-3 text-right <?php echo $pctClass; ?>"><?php echo $pct === null ? '—' : number_format($pct, 0) . '%'; ?></td>
                    </tr>
                    <?php if (!empty($r['fault_breakdown']) || !empty($r['down_reasons'])): ?>
                    <tr class="border-b border-slate-800/50 bg-slate-900/40">
                        <td colspan="10" class="p-3 pt-0">
                            <div class="flex flex-wrap gap-4 text-xs text-slate-400">
                                <?php if (!empty($r['fault_breakdown'])): ?>
                                <div>
                                    <span class="text-slate-500 font-semibold uppercase tracking-wider">faults:</span>
                                    <?php foreach ($r['fault_breakdown'] as $type => $count): ?>
                                    <a href="logs.php?type=<?php echo htmlspecialchars($type); ?>&q=<?php echo urlencode($r['model']); ?>&level=all" class="text-rose-300 hover:text-rose-200"><?php echo htmlspecialchars($FAULT_LABELS[$type] ?? $type); ?> <?php echo $count; ?></a>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($r['down_reasons'])): ?>
                                <div>
                                    <span class="text-slate-500 font-semibold uppercase tracking-wider">downvote reasons:</span>
                                    <?php foreach ($r['down_reasons'] as $reason => $count): ?>
                                    <span class="text-slate-300"><?php echo htmlspecialchars(reasonLabel($reason)); ?> <?php echo $count; ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div class="mt-8 pt-4 border-t border-slate-800 text-sm text-slate-600 text-center">
            Access at /models or /models.php — not linked from the main UI
        </div>
    </div>
</body>
</html>
