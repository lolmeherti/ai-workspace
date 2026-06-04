<div id="panel-queries" class="h-full overflow-y-auto p-4 space-y-3 hidden">
    <div class="bg-[#070b14] p-2.5 border border-slate-800 rounded-lg flex justify-between items-center mb-1">
        <span class="text-xs font-semibold text-slate-400">Active Ledger</span>
        <span class="text-[10px] font-bold text-indigo-400 px-2 py-0.5 rounded bg-indigo-500/15 border border-indigo-500/30">Redis Indexed</span>
    </div>

    <div class="space-y-2.5">
        <?php if (empty($queries)): ?>
            <div class="text-center py-6">
                <p class="text-xs text-slate-500">No cached search history found.</p>
                <p class="text-[10px] text-slate-600 mt-1">Queries are indexed on successful external searches.</p>
            </div>
        <?php else: ?>
            <?php foreach ($queries as $q): ?>
                <div class="bg-slate-900/40 border border-slate-800/80 rounded-lg p-3 text-xs relative group transition-all duration-200 hover:border-slate-700/60 shadow-sm">
                    <div class="flex justify-between items-start gap-3">
                        <div class="space-y-1 flex-1">
                            <span class="text-[10px] font-semibold text-cyan-400 tracking-wider uppercase">Query</span>
                            <p class="m-0 text-slate-100 font-semibold break-all leading-relaxed">"<?php echo htmlspecialchars($q['query']); ?>"</p>
                        </div>
                        <form method="POST" action="index.php?session_id=<?php echo $sessionId; ?>&tab=queries" onsubmit="return confirm('Purge this key from Cache & Ledger?');" class="shrink-0 mt-0.5">
                            <input type="hidden" name="delete_query" value="1">
                            <input type="hidden" name="cache_key" value="<?php echo htmlspecialchars($q['cache_key']); ?>">
                            <button type="submit" class="text-slate-500 hover:text-rose-400 transition-colors p-0.5" title="Purge Cache Key">
                                <uk-icon icon="x" class="w-3.5 h-3.5"></uk-icon>
                            </button>
                        </form>
                    </div>
                    
                    <div class="flex justify-between items-center text-[10px] text-slate-500 pt-2 mt-2 border-t border-slate-800/40">
                        <span class="truncate font-mono text-[9px]" title="Cache Key: <?php echo htmlspecialchars($q['cache_key']); ?>">
                            Key: <?php echo htmlspecialchars(substr($q['cache_key'], 0, 10)) . '...' . htmlspecialchars(substr($q['cache_key'], -6)); ?>
                        </span>
                        <span><?php echo htmlspecialchars($q['human_time'] ?? date('M d, H:i', $q['timestamp'])); ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>