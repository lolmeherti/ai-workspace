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
                        <div class="flex items-center gap-1.5 shrink-0 mt-0.5">
                            <button type="button" 
                                    class="text-slate-500 hover:text-cyan-400 transition-colors p-0.5 view-cache-btn" 
                                    data-key="<?php echo htmlspecialchars($q['cache_key']); ?>" 
                                    data-query="<?php echo htmlspecialchars($q['query']); ?>"
                                    title="View Cache Value">
                                <uk-icon icon="eye" class="w-3.5 h-3.5"></uk-icon>
                            </button>
                            <form method="POST" action="index.php?session_id=<?php echo $sessionId; ?>&tab=queries" onsubmit="return confirm('Purge this key from Cache & Ledger?');" class="m-0">
                                <input type="hidden" name="delete_query" value="1">
                                <input type="hidden" name="cache_key" value="<?php echo htmlspecialchars($q['cache_key']); ?>">
                                <button type="submit" class="text-slate-500 hover:text-rose-400 transition-colors p-0.5" title="Purge Cache Key">
                                    <uk-icon icon="x" class="w-3.5 h-3.5"></uk-icon>
                                </button>
                            </form>
                        </div>
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

<button id="trigger-modal-cache-viewer" class="hidden" uk-toggle="target: #modal-cache-viewer"></button>

<div id="modal-cache-viewer" uk-modal>
    <div class="uk-modal-dialog uk-modal-body bg-slate-900 border border-slate-800 rounded-xl shadow-2xl p-6 text-slate-100 max-w-3xl w-full relative">
        <button class="uk-modal-close-default text-slate-400 hover:text-white absolute right-4 top-4" type="button">
            <uk-icon icon="x" class="w-4 h-4"></uk-icon>
        </button>
        <h2 class="text-sm font-semibold text-slate-100 flex items-center gap-2 mb-4">
            <span class="text-indigo-400 uppercase tracking-wider text-xs">Cache Entry Details</span>
        </h2>
        <div class="space-y-4">
            <div>
                <span class="text-[10px] font-semibold text-slate-400 block mb-1">SEARCH QUERY</span>
                <p id="modal-cache-query" class="text-xs bg-slate-950 p-2.5 rounded-lg border border-slate-800 font-medium text-slate-200 leading-relaxed"></p>
            </div>
            <div>
                <span class="text-[10px] font-semibold text-slate-400 block mb-1">REDIS KEY</span>
                <code id="modal-cache-key" class="text-[10px] bg-slate-950 p-2.5 rounded-lg border border-slate-800 block text-cyan-400 font-mono break-all"></code>
            </div>
            <div>
                <div class="flex justify-between items-center mb-2">
                    <div class="flex gap-1 border-b border-slate-800">
                        <button type="button" id="tab-btn-render" class="px-3 py-1.5 text-xs font-semibold border-b-2 border-cyan-500 text-cyan-400 transition-all focus:outline-none">Formatted Render</button>
                        <button type="button" id="tab-btn-raw" class="px-3 py-1.5 text-xs font-semibold border-b-2 border-transparent text-slate-400 hover:text-slate-200 transition-all focus:outline-none">Raw / JSON Source</button>
                    </div>
                    <button id="modal-cache-copy" class="text-[10px] text-slate-400 hover:text-indigo-400 flex items-center gap-1 transition-colors">
                        <uk-icon icon="copy" class="w-3.5 h-3.5"></uk-icon> Copy Raw Payload
                    </button>
                </div>
                
                <div class="relative">
                    <div id="modal-cache-render-pane" class="chat-assistant markdown-content text-[0.95rem] leading-relaxed p-4 bg-slate-950 border border-slate-800 rounded-lg overflow-y-auto max-h-96 min-h-[150px] text-slate-200"></div>
                    
                    <div id="modal-cache-raw-pane" class="hidden">
                        <pre class="bg-slate-950 border border-slate-800 rounded-lg p-3 text-xs overflow-auto max-h-96 font-mono text-emerald-400 leading-normal whitespace-pre-wrap"><code id="modal-cache-value" class="language-json"></code></pre>
                    </div>
                </div>
            </div>
        </div>
        <div class="mt-6 flex justify-end">
            <button class="uk-button uk-button-default uk-modal-close text-xs text-slate-300 border-slate-800 hover:bg-slate-800/50 px-4 py-2 rounded-lg" type="button">Close</button>
        </div>
    </div>
</div>
