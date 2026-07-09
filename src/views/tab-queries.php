<style>
.in-query-manage .query-item {
    transition: border-color 0.2s, background-color 0.2s, box-shadow 0.2s;
}
.in-query-manage .query-item:hover {
    border-color: rgba(244, 63, 94, 0.2) !important;
    background-color: rgba(244, 63, 94, 0.05) !important;
}
.in-query-manage .query-item .btn-delete-single,
.in-query-manage .query-item .view-cache-btn {
    display: none !important;
}
.query-item .select-check-icon { display: none; }
.in-query-manage .query-item .select-check-icon { display: inline-flex; }
</style>

<div id="panel-queries" class="h-full flex flex-col hidden overflow-hidden relative">

    <div class="flex justify-between items-center px-4 py-3 border-b border-slate-800/40 bg-[#0b101f]">
        <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider select-none">Active Ledger</span>
        <button onclick="toggleQueryManage()" id="btn-manage-queries" class="text-xs text-slate-400 hover:text-cyan-400 font-medium transition-colors cursor-pointer flex items-center gap-1">
            <uk-icon icon="file-edit" class="w-3.5 h-3.5"></uk-icon> Manage
        </button>
    </div>

    <div class="flex-1 overflow-y-auto p-4 space-y-2.5 pb-16">
        <?php if (empty($queries)): ?>
            <div class="text-center py-6">
                <p class="text-xs text-slate-500">No cached search history found.</p>
                <p class="text-[10px] text-slate-600 mt-1">Queries are indexed on successful external searches.</p>
            </div>
        <?php else: ?>
            <?php foreach ($queries as $q): ?>
                <div class="query-item bg-slate-900/40 border border-slate-800/80 rounded-lg p-3 text-xs relative transition-all duration-200 hover:border-slate-700/60 shadow-sm"
                     data-cache-key="<?php echo htmlspecialchars($q['cache_key']); ?>">
                    <div class="flex justify-between items-start gap-3">
                        <div class="flex items-start gap-2 flex-1">
                            <span class="select-check-icon shrink-0 mt-0.5 text-rose-400">
                                <uk-icon icon="check" class="w-3.5 h-3.5"></uk-icon>
                            </span>
                            <div class="space-y-1 flex-1">
                                <span class="text-[10px] font-semibold text-cyan-400 tracking-wider uppercase">Query</span>
                                <p class="m-0 text-slate-100 font-semibold break-all leading-relaxed">"<?php echo htmlspecialchars($q['query']); ?>"</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5 shrink-0 mt-0.5">
                            <button type="button"
                                    class="view-cache-btn text-slate-500 hover:text-cyan-400 transition-colors p-0.5"
                                    data-key="<?php echo htmlspecialchars($q['cache_key']); ?>"
                                    data-query="<?php echo htmlspecialchars($q['query']); ?>"
                                    title="View Cache Value">
                                <uk-icon icon="eye" class="w-3.5 h-3.5"></uk-icon>
                            </button>
                            <form method="POST" action="index.php?session_id=<?php echo $sessionId; ?>&tab=queries" onsubmit="return confirm('Purge this key from Cache & Ledger?');" class="btn-delete-single m-0">
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

    <div id="multi-delete-queries-bar" class="absolute bottom-0 left-0 right-0 p-3 bg-[#0d1321]/95 border-t border-slate-800/80 backdrop-blur-md translate-y-full transition-all duration-300 flex justify-between items-center z-20 shadow-[0_-5px_15px_rgba(0,0,0,0.4)]">
        <span class="text-xs text-slate-400">
            <span id="selected-queries-count" class="font-bold text-cyan-400">0</span> selected
        </span>
        <div class="flex gap-2">
            <button onclick="toggleQueryManage()" class="px-2.5 py-1 text-xs text-slate-400 hover:text-slate-200 transition-colors cursor-pointer">
                Cancel
            </button>
            <button onclick="submitQueryMultiDelete()" id="btn-submit-query-multi-delete" disabled class="px-3 py-1 text-xs font-semibold bg-rose-500/10 text-rose-400/50 border border-rose-500/10 rounded transition-colors flex items-center gap-1 opacity-50 cursor-not-allowed">
                <uk-icon icon="trash" class="w-3.5 h-3.5"></uk-icon> Delete
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    let selectedQueries = [];
    let manageMode = false;

    window.toggleQueryManage = function() {
        manageMode = !manageMode;
        const panel = document.getElementById('panel-queries');
        const bar = document.getElementById('multi-delete-queries-bar');
        const btn = document.getElementById('btn-manage-queries');

        if (manageMode) {
            panel.classList.add('in-query-manage');
            bar.classList.remove('translate-y-full');
            btn.innerHTML = '<uk-icon icon="x" class="w-3.5 h-3.5"></uk-icon> Done';
            btn.classList.remove('text-slate-400', 'hover:text-cyan-400');
            btn.classList.add('text-rose-400', 'hover:text-rose-300');
        } else {
            panel.classList.remove('in-query-manage');
            bar.classList.add('translate-y-full');
            btn.innerHTML = '<uk-icon icon="file-edit" class="w-3.5 h-3.5"></uk-icon> Manage';
            btn.classList.add('text-slate-400', 'hover:text-cyan-400');
            btn.classList.remove('text-rose-400', 'hover:text-rose-300');
            selectedQueries = [];
            updateQuerySelectionUI();
        }
    };

    document.getElementById('panel-queries').addEventListener('click', function(e) {
        if (!manageMode) return;
        const item = e.target.closest('.query-item');
        if (!item) return;
        e.preventDefault();

        const key = item.getAttribute('data-cache-key');
        const idx = selectedQueries.indexOf(key);
        if (idx > -1) {
            selectedQueries.splice(idx, 1);
            item.classList.remove('border-rose-500/40', 'bg-rose-950/20', 'shadow-[0_0_10px_rgba(244,63,94,0.1)]');
        } else {
            selectedQueries.push(key);
            item.classList.add('border-rose-500/40', 'bg-rose-950/20', 'shadow-[0_0_10px_rgba(244,63,94,0.1)]');
        }
        updateQuerySelectionUI();
    });

    function updateQuerySelectionUI() {
        const count = selectedQueries.length;
        document.getElementById('selected-queries-count').textContent = count;
        const btn = document.getElementById('btn-submit-query-multi-delete');
        if (count > 0) {
            btn.disabled = false;
            btn.classList.remove('opacity-50', 'cursor-not-allowed', 'text-rose-400/50', 'bg-rose-500/10', 'border-rose-500/10');
            btn.classList.add('text-rose-400', 'bg-rose-500/20', 'border-rose-500/30', 'hover:bg-rose-500/30');
        } else {
            btn.disabled = true;
            btn.classList.add('opacity-50', 'cursor-not-allowed', 'text-rose-400/50', 'bg-rose-500/10', 'border-rose-500/10');
            btn.classList.remove('text-rose-400', 'bg-rose-500/20', 'border-rose-500/30', 'hover:bg-rose-500/30');
        }
    }

    window.submitQueryMultiDelete = function() {
        if (selectedQueries.length === 0) return;
        if (!confirm('Delete these ' + selectedQueries.length + ' cached queries permanently?')) return;

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'index.php?tab=queries';

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'delete_multiple_queries';
        actionInput.value = '1';
        form.appendChild(actionInput);

        selectedQueries.forEach(function(key) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_queries[]';
            input.value = key;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
    };
})();
</script>

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