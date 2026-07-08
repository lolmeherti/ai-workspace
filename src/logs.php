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

// Handle clear action
if ($db && isset($_POST['clear']) && $_POST['clear'] === '1') {
    $db->executeStatement("TRUNCATE TABLE app_events");
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle clear single event type
if ($db && isset($_POST['clear_type'])) {
    $db->executeStatement("DELETE FROM app_events WHERE event_type = ?", [$_POST['clear_type']]);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$summary = [];
$recent = [];
$totalCount = 0;
$lastHourCount = 0;
$errorCount = 0;

if ($db) {
    // Summary by event type
    $summary = $db->query("
        SELECT event_type, level, COUNT(*) as cnt, MAX(created_at) as last_seen
        FROM app_events
        GROUP BY event_type, level
        ORDER BY cnt DESC
    ");

    // Recent events (last 100)
    $recent = $db->query("
        SELECT id, event_type, message, context, level, source, created_at
        FROM app_events
        ORDER BY id DESC
        LIMIT 100
    ");

    $totalCount = $db->query("SELECT COUNT(*) as cnt FROM app_events")[0]['cnt'] ?? 0;
    $lastHourCount = $db->query("SELECT COUNT(*) as cnt FROM app_events WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)")[0]['cnt'] ?? 0;
    $errorCount = $db->query("SELECT COUNT(*) as cnt FROM app_events WHERE level = 'error'")[0]['cnt'] ?? 0;
}

// Collapse summary by event_type (merge levels)
$typeSummary = [];
foreach ($summary as $row) {
    $et = $row['event_type'];
    if (!isset($typeSummary[$et])) {
        $typeSummary[$et] = ['count' => 0, 'last_seen' => $row['last_seen']];
    }
    $typeSummary[$et]['count'] += (int)$row['cnt'];
    if ($row['last_seen'] > $typeSummary[$et]['last_seen']) {
        $typeSummary[$et]['last_seen'] = $row['last_seen'];
    }
}

// Sort by count desc
uasort($typeSummary, fn($a, $b) => $b['count'] <=> $a['count']);

// Level badge colors
$levelColors = [
    'debug' => 'slate',
    'info' => 'cyan',
    'warn' => 'amber',
    'error' => 'rose',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnostics — Event Log</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background: #020617; }
        .expand-row { display: none; }
        .expand-row.open { display: table-row; }
    </style>
</head>
<body class="bg-slate-950 text-slate-200 font-mono text-sm antialiased">
    <div class="max-w-7xl mx-auto p-6">
        <!-- Header -->
        <div class="flex items-center justify-between mb-8 pb-4 border-b border-slate-800">
            <div>
                <h1 class="text-xl font-bold text-cyan-400">Diagnostics — Event Log</h1>
                <p class="text-slate-500 text-xs mt-1">Manual refresh — use the button</p>
            </div>
            <div class="flex gap-2">
                <a href="logs.php" class="px-3 py-1.5 text-xs rounded bg-cyan-500/10 text-cyan-400 border border-cyan-500/30 hover:bg-cyan-500/20 transition-colors">Refresh</a>
                <form method="POST" onsubmit="return confirm('Clear ALL events?')" class="inline">
                    <input type="hidden" name="clear" value="1">
                    <button class="px-3 py-1.5 text-xs rounded bg-rose-500/10 text-rose-400 border border-rose-500/30 hover:bg-rose-500/20 transition-colors">
                        Clear All
                    </button>
                </form>
            </div>
        </div>

        <?php if ($error): ?>
        <div class="bg-rose-500/10 border border-rose-500/30 rounded-lg p-4 text-rose-400 mb-6">
            Database connection failed: <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <!-- Stats bar -->
        <div class="grid grid-cols-4 gap-4 mb-8">
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <div class="text-slate-500 text-xs uppercase tracking-wider mb-1">Total Events</div>
                <div class="text-2xl font-bold text-slate-200"><?php echo number_format($totalCount); ?></div>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <div class="text-slate-500 text-xs uppercase tracking-wider mb-1">Last Hour</div>
                <div class="text-2xl font-bold text-cyan-400"><?php echo number_format($lastHourCount); ?></div>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <div class="text-slate-500 text-xs uppercase tracking-wider mb-1">Errors</div>
                <div class="text-2xl font-bold <?php echo $errorCount > 0 ? 'text-rose-400' : 'text-emerald-400'; ?>"><?php echo number_format($errorCount); ?></div>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <div class="text-slate-500 text-xs uppercase tracking-wider mb-1">Event Types</div>
                <div class="text-2xl font-bold text-slate-200"><?php echo count($typeSummary); ?></div>
            </div>
        </div>

        <!-- Event type summary -->
        <div class="mb-8">
            <h2 class="text-sm font-semibold text-slate-400 uppercase tracking-wider mb-3">Event Types</h2>
            <?php if (empty($typeSummary)): ?>
                <div class="bg-slate-900 border border-slate-800 rounded-lg p-6 text-center text-slate-500">
                    No events recorded yet. Use the app and events will appear here.
                </div>
            <?php else: ?>
            <div class="bg-slate-900 border border-slate-800 rounded-lg overflow-hidden">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-800 text-left text-xs text-slate-500 uppercase tracking-wider">
                            <th class="p-3 w-16">Count</th>
                            <th class="p-3">Event Type</th>
                            <th class="p-3 w-48">Last Seen</th>
                            <th class="p-3 w-24"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($typeSummary as $eventType => $info): ?>
                        <tr class="border-b border-slate-800/50 hover:bg-slate-800/30 transition-colors cursor-pointer" onclick="var nr=this.nextElementSibling; nr.classList.toggle('open')">
                            <td class="p-3">
                                <span class="px-2 py-0.5 rounded text-xs font-bold <?php echo $info['count'] > 5 ? 'bg-rose-500/10 text-rose-400' : 'bg-slate-800 text-slate-300'; ?>">
                                    <?php echo $info['count']; ?>
                                </span>
                            </td>
                            <td class="p-3">
                                <span class="text-cyan-400"><?php echo htmlspecialchars($eventType); ?></span>
                            </td>
                            <td class="p-3 text-slate-500 text-xs"><?php echo htmlspecialchars($info['last_seen']); ?></td>
                            <td class="p-3">
                                <form method="POST" onsubmit="event.stopPropagation(); return confirm('Clear all <?php echo htmlspecialchars($eventType); ?> events?')" class="inline">
                                    <input type="hidden" name="clear_type" value="<?php echo htmlspecialchars($eventType); ?>">
                                    <button class="text-xs text-slate-600 hover:text-rose-400 transition-colors">clear</button>
                                </form>
                            </td>
                        </tr>
                        <?php
                        // Show recent samples for this event type (inline expand)
                        $samples = $db->query(
                            "SELECT id, message, context, level, source, created_at FROM app_events WHERE event_type = ? ORDER BY id DESC LIMIT 5",
                            [$eventType]
                        );
                        ?>
                        <tr class="expand-row bg-slate-950/50">
                            <td colspan="4" class="p-3">
                                <div class="space-y-2">
                                    <?php foreach ($samples as $s): ?>
                                    <div class="bg-slate-900 border border-slate-800 rounded p-3">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="text-xs text-slate-500"><?php echo htmlspecialchars($s['created_at']); ?></span>
                                            <span class="text-xs px-1.5 py-0.5 rounded <?php echo $s['level'] === 'error' ? 'bg-rose-500/10 text-rose-400' : ($s['level'] === 'warn' ? 'bg-amber-500/10 text-amber-400' : 'bg-slate-800 text-slate-400'); ?>"><?php echo htmlspecialchars($s['level']); ?></span>
                                        </div>
                                        <div class="text-sm text-slate-300 whitespace-pre-wrap break-all"><?php echo htmlspecialchars($s['message']); ?></div>
                                        <?php if (!empty($s['context'])): ?>
                                        <?php $ctx = json_decode($s['context'], true); ?>
                                        <?php if (!empty($ctx)): ?>
                                        <details class="mt-2">
                                            <summary class="text-xs text-slate-500 cursor-pointer hover:text-slate-400">Context</summary>
                                            <pre class="mt-1 text-xs text-slate-500 bg-slate-950 p-2 rounded overflow-x-auto whitespace-pre-wrap"><?php echo htmlspecialchars(json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
                                        </details>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Recent events stream -->
        <div>
            <h2 class="text-sm font-semibold text-slate-400 uppercase tracking-wider mb-3">Recent Events (last 100)</h2>
            <?php if (empty($recent)): ?>
                <div class="bg-slate-900 border border-slate-800 rounded-lg p-6 text-center text-slate-500">
                    No events yet.
                </div>
            <?php else: ?>
            <div class="space-y-1">
                <?php foreach ($recent as $event): ?>
                <div class="bg-slate-900 border border-slate-800 rounded p-3 flex items-start gap-3 hover:bg-slate-800/50 transition-colors">
                    <span class="text-xs text-slate-600 w-16 shrink-0 pt-0.5"><?php echo htmlspecialchars(date('H:i:s', strtotime($event['created_at']))); ?></span>
                    <span class="text-xs px-1.5 py-0.5 rounded shrink-0 <?php echo $event['level'] === 'error' ? 'bg-rose-500/10 text-rose-400' : ($event['level'] === 'warn' ? 'bg-amber-500/10 text-amber-400' : ($event['level'] === 'debug' ? 'bg-slate-800 text-slate-500' : 'bg-cyan-500/10 text-cyan-400')); ?>"><?php echo htmlspecialchars($event['level']); ?></span>
                    <span class="text-xs text-cyan-400 shrink-0 w-44 truncate" title="<?php echo htmlspecialchars($event['event_type']); ?>"><?php echo htmlspecialchars($event['event_type']); ?></span>
                    <?php if (!empty($event['source'])): ?>
                    <span class="text-xs text-slate-600 shrink-0 w-40 truncate" title="<?php echo htmlspecialchars($event['source']); ?>"><?php echo htmlspecialchars($event['source']); ?></span>
                    <?php endif; ?>
                    <span class="text-sm text-slate-300 flex-1 break-all"><?php echo htmlspecialchars($event['message']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="mt-8 pt-4 border-t border-slate-800 text-xs text-slate-600 text-center">
            Access at /logs or /logs.php — not linked from the main UI
        </div>
    </div>

</body>
</html>
