<div id="settings-modal" class="uk-modal animate-fade-in" uk-modal>
    <div class="uk-modal-dialog glass-modal rounded-xl overflow-hidden text-slate-200 uk-width-large w-full max-w-2xl">
        <button class="uk-modal-close-default text-slate-400 hover:text-white" type="button" uk-close></button>
        
        <form method="POST" action="index.php?session_id=<?php echo $sessionId; ?>&tab=<?php echo $activeTab; ?>" class="flex flex-col h-[85vh] max-h-[700px]">
            <input type="hidden" name="save_settings" value="1">
            
            <div class="p-6 border-b border-slate-800/80 bg-slate-900/40 shrink-0">
                <h2 class="text-xl font-bold tracking-tight text-white flex items-center gap-2">
                    <uk-icon icon="settings" class="w-5 h-5 text-cyan-400"></uk-icon> Environment Setup
                </h2>
                <p class="text-xs text-slate-400 mt-1">Configure your local API connections and limits (.env)</p>
            </div>
            
            <div class="flex-1 overflow-y-auto p-6 space-y-4">
                <?php foreach ($envVars as $key => $value): ?>
                    <?php 
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
            
            <div class="flex justify-end gap-3 p-6 border-t border-slate-800 bg-slate-900/40 shrink-0">
                <button type="button" class="px-4 py-2 rounded-lg text-sm font-medium text-slate-300 hover:bg-slate-800 transition-colors uk-modal-close">Cancel</button>
                <button type="submit" class="btn-futuristic px-5 py-2 rounded-lg text-sm font-semibold">Save Configuration</button>
            </div>
        </form>
    </div>
</div>