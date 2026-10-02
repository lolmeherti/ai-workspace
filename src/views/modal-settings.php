<!-- Settings Modal -->
<div id="settings-modal" class="uk-modal animate-fade-in" uk-modal>
    <div class="uk-modal-dialog glass-modal rounded-xl overflow-hidden text-slate-200 uk-width-large w-full max-w-2xl relative">
        
        <!-- One close affordance, top-right: positioned by .close-btn-futuristic in styles.css,
             not by a generated utility class (those aren't all present in the shipped bundle).
             A real icon instead of the &times; text glyph — a glyph never sits centred in its box. -->
        <button class="ui-icon-button close-btn-futuristic uk-modal-close" type="button" aria-label="Close"><uk-icon icon="x" class="w-4 h-4" aria-hidden="true"></uk-icon></button>
        
        <form method="POST" action="index.php?session_id=<?php echo $sessionId; ?>&tab=<?php echo $activeTab; ?>" class="flex flex-col h-[85vh] max-h-[700px]">
            <input type="hidden" name="save_settings" value="1">
            
            <!-- pr-16 keeps the title clear of the close button in the top-right corner. -->
            <div class="p-6 pr-16 border-b border-slate-800/80 bg-slate-900/40 shrink-0">
                <h2 class="text-xl font-bold tracking-tight text-white flex items-center gap-2">
                    <uk-icon icon="settings" class="w-5 h-5 text-cyan-400"></uk-icon> Environment Setup
                </h2>
                <p class="text-xs text-slate-400 mt-1">Configure your local API connections and limits (.env)</p>
            </div>

            <!-- Model Switcher Section -->
            <div class="px-6 pt-4 flex gap-4 items-end">
                <div class="flex-1">
                    <label class="block text-xs font-semibold text-slate-400 normal-case tracking-normal mb-1.5" for="model_id">Model</label>
                    <select name="model_id" id="model_id" class="input-futuristic w-full rounded-lg px-3 py-2 text-sm">
                        <option value="">— Select a model —</option>
                        <?php 
                        $envPath = __DIR__ . '/../.env';
                        $currentModelId = '';
                        $currentCtxSize = 0;
                        if (file_exists($envPath)) {
                            $envData = (new \App\EnvEditor($envPath))->read();
                            $currentModelId = trim($envData['LLM_MODEL_ID'] ?? '', '"\'\' ');
                            $currentCtxSize = (int)preg_replace('/[^0-9]/', '', $envData['LLM_CTX_SIZE'] ?? '0');
                        }

                        $grouped = [];
                        foreach ($modelsList as $m) {
                            $grouped[$m['vram_group'] ?? 'Any'][] = $m;
                        }

                        $groupOrder = ['32GB+', '24GB+', '16GB+', '12GB+', '8GB+', 'Any'];
                        foreach ($groupOrder as $group):
                            if (empty($grouped[$group])) continue;
                        ?>
                            <optgroup label="<?php echo htmlspecialchars($group); ?>">
                            <?php foreach ($grouped[$group] as $m): 
                                $mId = $m['model_id'] ?? '';
                                $mName = $m['name'] ?? $mId;
                                $ctxSize = (int)($m['ctx_size'] ?? 0);
                                $maxCtx = (int)($m['max_ctx'] ?? 0);
                                $label = $mName;
                                if ($ctxSize >= 1000) {
                                    $label .= ' — ' . number_format($ctxSize) . ' ctx';
                                }
                                $isSelected = ($mId !== '' && $mId === $currentModelId && ($currentCtxSize === 0 || $ctxSize === $currentCtxSize)) ? 'selected' : '';
                            ?>
                                <option value="<?php echo htmlspecialchars($mId); ?>"
                                        data-ctx="<?php echo $ctxSize; ?>"
                                        data-max-ctx="<?php echo $maxCtx; ?>"
                                        data-name="<?php echo htmlspecialchars($mName); ?>"
                                        <?php echo $isSelected; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                            <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="w-48">
                    <label class="block text-xs font-semibold text-slate-400 normal-case tracking-normal mb-1.5" for="ctx_size">Ctx Size</label>
                    <?php 
                    $currentCtxSize = '';
                    if (file_exists($envPath)) {
                        $currentCtxSize = trim($envData['LLM_CTX_SIZE'] ?? '', '"\'\' ');
                    }
                    ?>
                    <input type="number" name="ctx_size" id="ctx_size" value="<?php echo htmlspecialchars($currentCtxSize); ?>" class="input-futuristic w-full rounded-lg px-3 py-2 text-sm">
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-6 space-y-4 mt-2">
                <?php foreach ($envVars as $key => $value): ?>
                    <?php 
                        if (in_array($key, ['LLM_MODEL_NAME', 'LLM_CTX_SIZE'])) {
                            continue;
                        }
                        $label = ucwords(strtolower(str_replace('_', ' ', $key)));
                        // Structured values are resolved by the launcher at boot (LLM_SAMPLING,
                        // LLM_RUNTIME_POLICY). A one-line text field cannot round-trip JSON, and a
                        // mangled write silently degraded the Reasoning control to Off/On, so these
                        // are shown read-only instead of pretending to be editable.
                        $isStructured = json_decode((string) $value, true) !== null;
                    ?>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 normal-case tracking-normal mb-1.5" for="<?php echo htmlspecialchars($key); ?>">
                            <?php echo htmlspecialchars($label); ?><?php if ($isStructured): ?> <span class="text-slate-600 font-normal">· set by the launcher</span><?php endif; ?>
                        </label>
                        <input type="text" 
                               id="<?php echo htmlspecialchars($key); ?>" 
                               name="<?php echo htmlspecialchars($key); ?>" 
                               class="input-futuristic w-full rounded-lg px-3 py-2 text-sm<?php echo $isStructured ? ' opacity-60 cursor-not-allowed' : ''; ?>" 
                               value="<?php echo htmlspecialchars($value); ?>" 
                               <?php if ($isStructured): ?>readonly title="Resolved by the launcher at boot (per model). Edit it there, not here."<?php else: ?>required<?php endif; ?>>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="p-6 border-t border-slate-800 bg-slate-900/40 shrink-0">
                <div id="switch-status" class="hidden items-center gap-3 mb-3">
                    <span id="switch-status-spinner" class="switch-spinner"></span>
                    <span id="switch-status-label" class="text-sm text-slate-300 whitespace-nowrap">Preparing…</span>
                    <progress id="switch-status-bar" class="flex-1 h-2 rounded-full" max="100" value="0"></progress>
                    <span id="switch-status-pct" class="text-sm text-cyan-400 font-semibold w-12 text-right"></span>
                    <button type="button" id="switch-status-cancel" class="hidden text-xs px-2 py-1 rounded border border-rose-500/40 text-rose-400 hover:bg-rose-500/10 transition-colors cursor-pointer whitespace-nowrap">Cancel download</button>
                </div>
                <div id="switch-error" class="hidden text-xs text-red-400 mb-3"></div>
                <div class="flex justify-end items-center gap-3">
                    <button type="submit" id="save-settings-btn" name="save_settings" value="1" 
                            class="btn-futuristic px-5 py-2 rounded-lg text-sm font-semibold">Save Configuration</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modelSelect = document.getElementById('model_id');
    if (modelSelect) {
        modelSelect.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            const ctxInput = document.getElementById('ctx_size');
            if (opt && opt.dataset.ctx && parseInt(opt.dataset.ctx) > 0) {
                ctxInput.value = opt.dataset.ctx;
            } else if (opt && !opt.value) {
                ctxInput.value = '';
            }
            if (opt && opt.dataset.maxCtx && parseInt(opt.dataset.maxCtx) > 0) {
                ctxInput.max = opt.dataset.maxCtx;
            } else {
                ctxInput.removeAttribute('max');
            }
        });
    }

    const form = document.querySelector('#settings-modal form');
    if (!form) return;

    const statusRow = document.getElementById('switch-status');
    const statusLabel = document.getElementById('switch-status-label');
    const statusBar = document.getElementById('switch-status-bar');
    const statusPct = document.getElementById('switch-status-pct');
    const cancelBtn = document.getElementById('switch-status-cancel');
    const errorRow = document.getElementById('switch-error');
    const saveBtn = document.getElementById('save-settings-btn');

    const stageLabel = {
        resolving: 'Resolving model…',
        downloading: 'Downloading model…',
        starting: 'Starting llama-server… (large models take a moment)',
    };

    let polling = false;

    function showStatus() {
        statusRow.classList.remove('hidden');
        statusRow.classList.add('flex');
        errorRow.classList.add('hidden');
        errorRow.textContent = '';
    }
    function hideStatus() {
        statusRow.classList.add('hidden');
        statusRow.classList.remove('flex');
    }
    function setBusy(busy) {
        saveBtn.disabled = busy;
    }
    function showError(msg) {
        errorRow.textContent = msg;
        errorRow.classList.remove('hidden');
        hideStatus();
        setBusy(false);
    }
    function updateProgress(st) {
        const label = stageLabel[st.stage] || 'Working…';
        statusLabel.textContent = label;
        if (st.stage === 'downloading') {
            statusBar.classList.remove('hidden');
            statusPct.classList.remove('hidden');
            if (cancelBtn) cancelBtn.classList.remove('hidden');
            const pct = Math.round(Number(st.progress) || 0);
            statusBar.value = pct;
            statusPct.textContent = pct + '%';
        } else {
            statusBar.classList.add('hidden');
            statusPct.classList.add('hidden');
            if (cancelBtn) cancelBtn.classList.add('hidden');
        }
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', () => {
            fetch('index.php?api_action=cancel_switch', { headers: { 'Accept': 'application/json' } })
                .catch(() => {});
            statusLabel.textContent = 'Cancelling download…';
            cancelBtn.classList.add('hidden');
        });
    }

    // Reloading is not a user decision: the workspace has to re-read .env (model
    // name, ctx, sampling, endpoint) for the change to be visible at all, so a
    // button asking "shall I reload?" was asking about the only possible outcome.
    function reloadWorkspace(message, delayMs) {
        statusLabel.textContent = message;
        setBusy(false);
        setTimeout(() => window.location.reload(), delayMs);
    }

    function pollSwitchStatus() {
        if (polling) return;
        polling = true;
        const tick = async () => {
            try {
                const resp = await fetch('index.php?api_action=get_switch_status', {
                    headers: { 'Accept': 'application/json' },
                });
                const st = await resp.json();
                if (st.active === false) {
                    polling = false;
                    if (st.stage === 'loaded') {
                        reloadWorkspace('Model switched. Reloading the workspace…', 1200);
                    } else {
                        showError(st.error || 'Model switch failed.');
                    }
                    return;
                }
                updateProgress(st);
                setTimeout(tick, 2000);
            } catch (err) {
                statusLabel.textContent = 'Connection interrupted. Checking the model switch status…';
                setTimeout(tick, 3000);
            }
        };
        tick();
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (saveBtn.disabled) return;
        showStatus();
        statusLabel.textContent = 'Saving…';
        statusBar.classList.add('hidden');
        statusPct.classList.add('hidden');
        setBusy(true);

        try {
            const resp = await fetch(form.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: new FormData(form),
            });
            let data = {};
            try { data = await resp.json(); } catch (_) {}
            const status = data.status || 'error';

            if (status === 'switching') {
                statusLabel.textContent = 'Switching to ' + (data.name || 'model') + '…';
                pollSwitchStatus();
            } else if (status === 'busy') {
                statusLabel.textContent = 'Switch already in progress…';
                updateProgress({ stage: data.stage || 'downloading', progress: data.progress || 0 });
                pollSwitchStatus();
            } else if (status === 'saved') {
                reloadWorkspace('Saved. Reloading the workspace…', 600);
            } else {
                showError(data.message || 'Failed to save settings.');
            }
        } catch (err) {
            showError('Network error: ' + err.message);
        }
    });
});
</script>
