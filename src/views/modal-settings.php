<div id="settings-modal" class="uk-modal" uk-modal>
    <div class="uk-modal-dialog glass-modal rounded-xl overflow-hidden text-slate-200">
        <button class="uk-modal-close-default text-slate-400 hover:text-white" type="button" uk-close></button>
        
        <div class="p-6 border-b border-slate-800/80 bg-slate-900/40">
            <h2 class="text-xl font-bold tracking-tight text-white flex items-center gap-2">
                <uk-icon icon="settings" class="w-5 h-5 text-cyan-400"></uk-icon> Environment Setup
            </h2>
            <p class="text-xs text-slate-400 mt-1">Configure your local API connections and limits (.env)</p>
        </div>
        
        <form method="POST" action="index.php?session_id=<?php echo $sessionId; ?>&tab=<?php echo $activeTab; ?>" class="p-6 space-y-5">
            <input type="hidden" name="save_settings" value="1">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5" for="llm_api_url">LLM API URL</label>
                    <input type="text" id="llm_api_url" name="llm_api_url" class="input-futuristic w-full rounded-lg px-3 py-2 text-sm" value="<?php echo htmlspecialchars($envVars['LLM_API_URL'] ?? 'http://host.docker.internal:1234/v1'); ?>" required>
                </div>
                
                <div>
                    <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5" for="llm_model_name">LLM Model Name</label>
                    <input type="text" id="llm_model_name" name="llm_model_name" class="input-futuristic w-full rounded-lg px-3 py-2 text-sm" value="<?php echo htmlspecialchars($envVars['LLM_MODEL_NAME'] ?? 'local-model'); ?>" required>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5" for="max_scrape_tokens">Max Scrape Tokens</label>
                        <input type="number" id="max_scrape_tokens" name="max_scrape_tokens" class="input-futuristic w-full rounded-lg px-3 py-2 text-sm" value="<?php echo htmlspecialchars($envVars['MAX_SCRAPE_TOKENS'] ?? '2500'); ?>" required>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5" for="memory_threshold">Memory Threshold</label>
                        <input type="number" id="memory_threshold" name="memory_threshold" class="input-futuristic w-full rounded-lg px-3 py-2 text-sm" value="<?php echo htmlspecialchars($envVars['MEMORY_EXTRACTION_THRESHOLD_TOKENS'] ?? '15000'); ?>" required>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5" for="max_search_results">Scrape Search Limit</label>
                    <input type="number" id="max_search_results" name="max_search_results" class="input-futuristic w-full rounded-lg px-3 py-2 text-sm" value="<?php echo htmlspecialchars($envVars['MAX_SEARCH_RESULTS_TO_SCRAPE'] ?? '3'); ?>" required>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-slate-800">
                <button type="button" class="px-4 py-2 rounded-lg text-sm font-medium text-slate-300 hover:bg-slate-800 transition-colors uk-modal-close">Cancel</button>
                <button type="submit" class="btn-futuristic px-5 py-2 rounded-lg text-sm font-semibold">Save Configuration</button>
            </div>
        </form>
    </div>
</div>