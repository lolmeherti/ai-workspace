<!-- Settings Modal -->
<div id="settings-modal" class="uk-modal animate-fade-in" uk-modal>
    <!-- Added "relative" class to dialog to position the close button absolutely in the top-left -->
    <div class="uk-modal-dialog glass-modal rounded-xl overflow-hidden text-slate-200 uk-width-large w-full max-w-2xl relative">
        
        <!-- FIX: Relocated to top-left, enlarged target area, using custom styling & native UIKit modal close support -->
        <button class="absolute top-5 left-5 close-btn-futuristic uk-modal-close" type="button" aria-label="Close">&times;</button>
        
        <form method="POST" action="index.php?session_id=<?php echo $sessionId; ?>&tab=<?php echo $activeTab; ?>" class="flex flex-col h-[85vh] max-h-[700px]">
            <input type="hidden" name="save_settings" value="1">
            
            <!-- FIX: Added pl-14 to prevent header title overlapping the new top-left close button -->
            <div class="p-6 border-b border-slate-800/80 bg-slate-900/40 shrink-0 pl-14">
                <h2 class="text-xl font-bold tracking-tight text-white flex items-center gap-2">
                    <uk-icon icon="settings" class="w-5 h-5 text-cyan-400"></uk-icon> Environment Setup
                </h2>
                <p class="text-xs text-slate-400 mt-1">Configure your local API connections and limits (.env)</p>
            </div>

            <!-- Model Switcher Section -->
            <div class="px-6 pt-4 flex gap-4 items-end">
                <div class="flex-1">
                    <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5" for="model_id">Model</label>
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
                                $label = $mName;
                                if ($ctxSize >= 1000) {
                                    $label .= ' — ' . number_format($ctxSize) . ' ctx';
                                }
                                $isSelected = ($mId !== '' && $mId === $currentModelId && ($currentCtxSize === 0 || $ctxSize === $currentCtxSize)) ? 'selected' : '';
                            ?>
                                <option value="<?php echo htmlspecialchars($mId); ?>"
                                        data-ctx="<?php echo $ctxSize; ?>"
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
                    <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5" for="ctx_size">Ctx Size</label>
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
                    ?>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5" for="<?php echo htmlspecialchars($key); ?>">
                            <?php echo htmlspecialchars($label); ?>
                        </label>
                        <input type="text" 
                               id="<?php echo htmlspecialchars($key); ?>" 
                               name="<?php echo htmlspecialchars($key); ?>" 
                               class="input-futuristic w-full rounded-lg px-3 py-2 text-sm" 
                               value="<?php echo htmlspecialchars($value); ?>" 
                               required>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="p-6 border-t border-slate-800 bg-slate-900/40 shrink-0">
                <div id="switch-status" class="hidden items-center gap-3 mb-3">
                    <span id="switch-status-spinner" class="switch-spinner"></span>
                    <span id="switch-status-label" class="text-sm text-slate-300 whitespace-nowrap">Preparing…</span>
                    <progress id="switch-status-bar" class="flex-1 h-2 rounded-full" max="100" value="0"></progress>
                    <span id="switch-status-pct" class="text-sm text-cyan-400 font-semibold w-12 text-right"></span>
                </div>
                <div id="switch-error" class="hidden text-xs text-red-400 mb-3"></div>
                <div class="flex justify-end items-center gap-3">
                    <button type="button" class="px-4 py-2 rounded-lg text-sm font-medium text-slate-300 hover:bg-slate-800 transition-colors uk-modal-close">Cancel</button>
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
        });
    }

    const form = document.querySelector('#settings-modal form');
    if (!form) return;

    const statusRow = document.getElementById('switch-status');
    const statusLabel = document.getElementById('switch-status-label');
    const statusBar = document.getElementById('switch-status-bar');
    const statusPct = document.getElementById('switch-status-pct');
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
            const pct = Math.round(Number(st.progress) || 0);
            statusBar.value = pct;
            statusPct.textContent = pct + '%';
        } else {
            statusBar.classList.add('hidden');
            statusPct.classList.add('hidden');
        }
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
                        window.location.reload();
                    } else {
                        showError(st.error || 'Model switch failed.');
                    }
                    return;
                }
                updateProgress(st);
                setTimeout(tick, 2000);
            } catch (err) {
                setTimeout(tick, 3000);
            }
        };
        tick();
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
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
                window.location.reload();
            } else {
                showError(data.message || 'Failed to save settings.');
            }
        } catch (err) {
            showError('Network error: ' + err.message);
        }
    });
});
</script>