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
                    <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5" for="model_name">Model</label>
                    <!-- FIX: Removed overriding inline styles to let custom select CSS take charge cleanly -->
                    <select name="model_name" id="model_name" class="input-futuristic w-full rounded-lg px-3 py-2 text-sm">
                        <option value="">— Select a model —</option>
                        <?php 
                        $envPath = __DIR__ . '/../.env';
                        $currentModelName = '';
                        if (file_exists($envPath)) {
                            $envData = (new \App\EnvEditor($envPath))->read();
                            $currentModelName = trim($envData['LLM_MODEL_NAME'] ?? '', '"\' ');
                        }
                        
                        $currentModelClean = strtolower(preg_replace('/-Q\d+(_\d+|_K_M)?$/i', '', preg_replace('/\.gguf$/i', '', $currentModelName)));

                        foreach ($modelsList as $m): 
                            $filename = $m['file'] ?? '';
                            $modelValueClean = preg_replace('/\.gguf$/i', '', $filename);
                            $modelValueClean = preg_replace('/-Q\d+(_\d+|_K_M)?$/i', '', $modelValueClean);
                            $modelValueClean = strtolower($modelValueClean);

                            $isSelected = (
                                strtolower($currentModelName) === strtolower($m['name']) || 
                                strtolower($currentModelName) === strtolower($modelValueClean) ||
                                $currentModelClean === $modelValueClean
                            ) ? 'selected' : '';
                        ?>
                            <option value="<?php echo htmlspecialchars($m['name']); ?>" 
                                    data-ctx="<?php echo (int)($m['ctx_size'] ?? 0); ?>"
                                    <?php echo $isSelected; ?>>
                                <?php echo htmlspecialchars($m['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="w-48">
                    <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5" for="ctx_size">Ctx Size</label>
                    <?php 
                    $currentCtxSize = '';
                    if (file_exists($envPath)) {
                        $currentCtxSize = trim($envData['LLM_CTX_SIZE'] ?? '', '"\' ');
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
            
            <div class="flex justify-between items-center gap-3 p-6 border-t border-slate-800 bg-slate-900/40 shrink-0">
                <button type="submit" name="switch_model" value="1" 
                        onclick="const opt = document.querySelector('#model_name option:checked'); if (!opt) return false; const v = parseInt(opt.dataset.ctx); if (!isNaN(v) && v > 0) { document.getElementById('ctx_size').value = v; } return true;"
                        class="px-5 py-2 rounded-lg text-sm font-semibold bg-cyan-600 hover:bg-cyan-500 text-white shadow-md transition-colors">
                    Switch Model & Restart
                </button>
                <div class="flex gap-3">
                    <button type="button" class="px-4 py-2 rounded-lg text-sm font-medium text-slate-300 hover:bg-slate-800 transition-colors uk-modal-close">Cancel</button>
                    <button type="submit" name="save_settings" value="1" 
                            onclick="document.getElementById('model_name').value=''; document.getElementById('ctx_size').value=''"
                            class="btn-futuristic px-5 py-2 rounded-lg text-sm font-semibold">Save Configuration</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modelSelect = document.getElementById('model_name');
    if (modelSelect) {
        modelSelect.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            const ctxInput = document.getElementById('ctx_size');
            if (opt && opt.dataset.ctx && parseInt(opt.dataset.ctx) > 0) {
                if (!ctxInput.value || parseInt(ctxInput.value) < 512) {
                    ctxInput.value = opt.dataset.ctx;
                }
            } else {
                ctxInput.value = '';
            }
        });
    }
});
</script>