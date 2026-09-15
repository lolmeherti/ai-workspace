<section id="chat-section" class="flex-1 flex flex-col h-full relative bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-[#0d1526] via-[#070b14] to-[#070b14]">
    
    <header class="h-16 border-b border-slate-800/80 flex items-center justify-between px-6 glass-panel backdrop-blur-md z-10">
        <h2 class="m-0 text-base font-semibold truncate text-slate-100 flex items-center gap-3">
            <uk-icon icon="message-square" class="w-5 h-5 text-cyan-500"></uk-icon>
            <span id="conversation-title"><?php echo htmlspecialchars($activeSessionTitle); ?></span>
        </h2>
        <div class="flex items-center gap-4">
            <div id="token-counter-container" class="hidden md:flex items-center gap-2 bg-slate-900/60 border border-slate-800/80 px-3.5 py-1.5 rounded-full text-xs font-semibold tracking-normal">
                <uk-icon icon="cpu" class="w-3.5 h-3.5 text-cyan-400"></uk-icon>
                <span class="text-slate-400">Context: <strong id="token-counter-text" class="text-slate-200"><?php echo number_format((int)($totalSessionTokens ?? 0)); ?> / <?php echo number_format((int)\App\Config::get('LLM_CTX_SIZE', 32768)); ?></strong> tokens</span>
                <div class="w-16 h-1.5 bg-slate-850 rounded-full overflow-hidden ml-1 border border-slate-800">
                    <div id="token-counter-bar" class="h-full bg-cyan-500 transition-all duration-300" style="width: 0%"></div>
                </div>
                <button type="button" id="btn-sync-lmstudio" class="group flex items-center justify-center gap-1.5 bg-transparent border border-slate-800/80 hover:border-cyan-500/40 text-slate-400 hover:text-cyan-400 px-2.5 py-0.5 rounded-full text-xs tracking-normal transition-all duration-300 font-bold cursor-pointer ml-1.5 outline-none" title="Sync Context Limit from LM Studio">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="w-3 h-3 transform group-hover:rotate-180 transition-transform duration-500 ease-out">
                        <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                        <path d="M3 3v5h5"/>
                        <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/>
                        <path d="M16 16h5v5"/>
                    </svg>
                    <span>SYNC LIMIT</span>
                </button>
                <button type="button" id="btn-manual-condense" data-ai-action class="group flex items-center justify-center gap-1.5 bg-transparent border border-slate-800/80 hover:border-cyan-500/40 text-slate-400 hover:text-cyan-400 px-2.5 py-0.5 rounded-full text-xs tracking-normal transition-all duration-300 font-bold cursor-pointer ml-1.5 outline-none" title="Manually Condense Chat History" onclick="triggerManualCondensation()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="w-3 h-3 transition-transform duration-300 group-hover:scale-110">
                        <polyline points="21 8 21 21 3 21 3 8"/>
                        <rect x="1" y="3" width="22" height="5"/>
                        <line x1="10" y1="12" x2="14" y2="12"/>
                    </svg>
                    <span>CONDENSE CHAT</span>
                </button>
            </div>

            <button type="button" id="context-toggle" class="ui-button" aria-controls="context-data-panel" aria-expanded="false">
                <uk-icon icon="database" class="w-4 h-4" aria-hidden="true"></uk-icon>
                Context Data <span id="context-data-count" class="ui-count"><?php echo count(array_filter($history ?? [], fn($m) => ($m['message_type'] ?? '') === 'data_fetching')); ?></span>
            </button>
        </div>
    </header>

    <!-- NEW SPLIT-PANE WRAPPER -->
    <div class="chat-split flex-1 flex h-full relative overflow-hidden">
        
        <!-- LEFT PANE: CONVERSATION HUB (100% width on load, shrinks to 40% when editor is active) -->
        <div class="flex-1 flex flex-col h-full min-w-0" id="chat-pane">
            
            <div class="flex-1 overflow-y-auto p-6 space-y-8" id="chatWindow">
                <?php include __DIR__ . '/chat-history.php'; ?>
            </div>

            <div class="p-4 border-t border-slate-800/80 glass-panel backdrop-blur-md relative z-10">
                <div class="max-w-[92%] mx-auto relative">
                    
                    <div id="image-preview-container" class="hidden absolute bottom-full left-0 mb-3 p-2 bg-[#0f172a] border border-slate-700 rounded-lg flex items-center gap-3 shadow-xl">
                        <div class="relative">
                            <div id="file-icon-preview" class="hidden h-16 w-16 bg-slate-800 rounded-md border border-slate-600 flex items-center justify-center">
                                <uk-icon icon="file-text" class="w-8 h-8 text-cyan-400"></uk-icon>
                            </div>
                            <img id="image-preview" src="" class="hidden h-16 w-16 object-cover rounded-md border border-slate-600" alt="Preview">
                            <button type="button" class="absolute -top-2 -right-2 bg-rose-500 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs hover:bg-rose-400 shadow-md" onclick="removeFile()">×</button>
                        </div>
                        <div class="flex flex-col pr-2">
                            <span id="file-preview-name" class="text-xs text-slate-300 font-medium truncate max-w-[150px]">File attached</span>
                            <span id="file-preview-type" class="text-xs text-slate-500 normal-case font-bold">Document</span>
                        </div>
                    </div>

                    <div id="referenced-files-container" class="flex flex-wrap gap-2 mb-3"></div>
                    
                    <style>
                        .effort-btn { transition: all .15s ease; }
                        .effort-btn.effort-active { background: rgba(34,211,238,0.14); color: #67e8f9; border-color: rgba(34,211,238,0.45); }
                    </style>
                    <div id="effort-control" class="flex items-center justify-end gap-2 mb-2">
                        <span class="text-xs normal-case tracking-normal text-slate-500 font-semibold">Reasoning</span>
                        <div id="effort-graduated" style="display:none" class="items-center gap-0.5 bg-[#0f172a] border border-slate-700 rounded-lg p-0.5">
                            <button type="button" data-effort="low" class="effort-btn px-2.5 py-1 text-xs rounded-md text-slate-400 hover:text-cyan-300 border border-transparent">Low</button>
                            <button type="button" data-effort="medium" class="effort-btn px-2.5 py-1 text-xs rounded-md text-slate-400 hover:text-cyan-300 border border-transparent">Medium</button>
                            <button type="button" data-effort="high" class="effort-btn px-2.5 py-1 text-xs rounded-md text-slate-400 hover:text-cyan-300 border border-transparent">High</button>
                        </div>
                        <div id="effort-binary" style="display:none" class="items-center gap-0.5 bg-[#0f172a] border border-slate-700 rounded-lg p-0.5">
                            <button type="button" data-effort="off" class="effort-btn px-2.5 py-1 text-xs rounded-md text-slate-400 hover:text-cyan-300 border border-transparent">Off</button>
                            <button type="button" data-effort="medium" class="effort-btn px-2.5 py-1 text-xs rounded-md text-slate-400 hover:text-cyan-300 border border-transparent">On</button>
                        </div>
                    </div>
                    <div id="composer-notices" class="composer-notices"></div>
                    <form id="chatForm" class="relative">
                        <input type="hidden" name="session_id" value="<?php echo $sessionId; ?>">
                        <input type="hidden" name="effort" id="effort-input" value="medium">
                        <input type="file" id="fileInput" name="file" accept="image/*,.pdf,.docx,.txt,.py,.php,.js,.json,.css,.html,.md,.yml,.yaml,.xml" class="hidden" onchange="previewFile(this)">
                        
                        <div class="flex w-full items-end gap-2 bg-[#0f172a] border border-slate-700 rounded-xl p-1.5 focus-within:border-cyan-500 focus-within:ring-1 focus-within:ring-cyan-500 transition-all shadow-inner" <?php echo $status->all_operational ? '' : 'disabled'; ?>>
                            <button type="button" class="shrink-0 p-2.5 text-slate-400 hover:text-cyan-400 transition-colors rounded-lg hover:bg-slate-800" onclick="document.getElementById('fileInput').click()" title="Attach File">
                                <uk-icon icon="paperclip" class="w-5 h-5"></uk-icon>
                            </button>
                            
                            <label class="sr-only" for="q">Message</label>
                            <textarea aria-label="Message" id="q" name="q" rows="1" class="flex-1 bg-transparent border-none text-slate-100 placeholder-slate-500 resize-none py-2.5 focus:outline-none focus:ring-0 max-h-32 min-h-[44px]" placeholder="Message your assistant…" autocomplete="off" oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px'"></textarea>
                            
                            <button type="submit" id="send-btn" data-ai-action aria-label="Send message" class="send-btn-futuristic shrink-0 w-11 h-11 rounded-full flex items-center justify-center" title="Send">
                                <span class="send-spinner" aria-hidden="true"></span>
                                <svg class="w-[18px] h-[18px] send-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
                                <svg class="w-[14px] h-[14px] stop-icon" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
        </div> <!-- END LEFT PANE -->

        <!-- RIGHT PANE: DRAWER WORKSPACE (Included modularly) -->
        <?php include __DIR__ . '/context-inspector.php'; ?>
        <?php include __DIR__ . '/chat-file-editor-drawer.php'; ?>

    </div> <!-- END NEW SPLIT-PANE WRAPPER -->

    <div id="condensation-modal" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-[#070b14]/90 backdrop-blur-sm">
        <div id="condensation-modal-card" class="bg-[#0f172a] border border-cyan-500/30 p-8 rounded-2xl max-w-md w-full shadow-[0_0_50px_rgba(6,182,212,0.2)] text-center transition-all duration-300">
            
            <div id="condensation-modal-content">
                <uk-icon icon="archive" class="w-12 h-12 text-cyan-400 mb-4 animate-pulse"></uk-icon>
                <h3 class="text-xl font-bold text-white mb-2">Context Limit Approaching</h3>
                <p class="text-sm text-slate-400 mb-6">This conversation is getting very long. Would you like me to condense older messages into a summary and extract facts into your long-term memory? This keeps the session fast and light.</p>
                <div class="flex gap-3 justify-center">
                    <button type="button" id="condensation-bypass" onclick="bypassCondensation()" class="px-4 py-2 text-slate-400 hover:text-white transition-colors cursor-pointer text-sm font-medium">Send without condensing</button>
                    <button type="button" onclick="confirmCondensation()" class="btn-futuristic px-5 py-2 rounded-lg bg-cyan-600 text-white font-bold cursor-pointer text-sm">Review condensation</button>
                </div>
            </div>

            <div id="condensation-modal-review" class="hidden text-left flex flex-col items-stretch max-h-[85vh]">
                <div class="flex items-center gap-2 mb-4 border-b border-cyan-500/20 pb-3">
                    <uk-icon icon="brain" class="w-6 h-6 text-cyan-400 animate-pulse"></uk-icon>
                    <h3 class="text-lg font-bold text-white normal-case tracking-normal">Review extracted memories</h3>
                </div>
                
                <p class="text-xs text-slate-400 mb-4">
                    The AI has extracted the following insights. Deselect any entries that are redundant, inaccurate, or that you do not wish to store permanently.
                </p>

                <div class="flex-1 overflow-y-auto pr-1 space-y-3 mb-6 max-h-[350px]" id="condensation-memories-list"></div>

                <div class="flex justify-between items-center border-t border-cyan-500/20 pt-4">
                    <button type="button" onclick="closeCondensationModal()" class="px-4 py-2 text-slate-400 hover:text-white transition-colors cursor-pointer text-xs normal-case font-bold tracking-normal">Cancel</button>
                    <button type="button" onclick="applyCondensation()" class="btn-futuristic px-5 py-2.5 rounded-lg text-white font-bold cursor-pointer text-xs normal-case tracking-normal flex items-center gap-2">
                        <uk-icon icon="check" class="w-4 h-4 text-cyan-400"></uk-icon>
                        Save selected memories & condense
                    </button>
                </div>
            </div>
            
            <div id="condensation-modal-loading" class="hidden flex flex-col items-center gap-4 py-4">
                <span class="uk-spinner uk-spinner-medium text-cyan-500 animate-spin" uk-spinner="ratio: 1.2"></span>
                <p class="text-cyan-400 font-medium animate-pulse text-sm" id="condensation-loading-text">Analyzing context...</p>
            </div>
        </div>
    </div>

    <template id="tpl-user-message">
        <div class="flex flex-col w-full max-w-[92%] mx-auto space-y-1 items-end mb-4 chat-message-container">
            <div class="flex items-center gap-2 flex-row-reverse mr-1">
                <span class="text-xs text-slate-500 font-semibold normal-case tracking-normal">You</span>
                <button type="button" class="text-slate-500 hover:text-cyan-400 p-0.5 rounded transition-colors duration-150 cursor-pointer flex items-center justify-center copy-btn" onclick="copyToClipboard(this)" title="Copy message">
                    <uk-icon icon="copy" class="w-3.5 h-3.5"></uk-icon>
                </button>
            </div>
            <div class="chat-user rounded-2xl rounded-tr-sm px-5 py-4 text-[0.95rem] leading-relaxed max-w-[85%] bubble-content" data-raw="">
                <img src="" class="max-w-xs rounded-lg mb-3 border border-white/20 shadow-md hidden upload-img" alt="Upload">
                <span class="msg-text"></span>
                <div class="flex justify-end mt-4 pt-2 border-t border-slate-800/20 hidden bottom-copy-container mt-auto">
                    <button type="button" class="text-xs text-slate-500 hover:text-cyan-400 flex items-center gap-1 transition-colors duration-150 cursor-pointer bg-transparent border-none p-0.5 flex items-center gap-1"
                            onclick="copyToClipboard(this)" 
                            title="Copy message">
                        <uk-icon icon="copy" class="w-3.5 h-3.5"></uk-icon> <span>Copy Entire Message</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    <template id="tpl-ai-message">
        <div class="flex flex-col w-full max-w-[92%] mx-auto space-y-1 items-start mb-4 chat-message-container ai-wrapper">
            <div class="flex items-center gap-2 ml-1">
                <span class="text-xs text-slate-500 font-semibold normal-case tracking-normal flex items-center gap-2 ai-label-container">
                    <?php echo htmlspecialchars(\App\Config::get('LLM_MODEL_NAME', 'Assistant')); ?>
                </span>
                <button type="button" class="text-slate-500 hover:text-cyan-400 p-0.5 rounded transition-colors duration-150 cursor-pointer flex items-center justify-center copy-btn" onclick="copyToClipboard(this)" title="Copy message">
                    <uk-icon icon="copy" class="w-3.5 h-3.5"></uk-icon>
                </button>
            </div>
            <div class="chat-assistant rounded-2xl rounded-tl-sm px-5 py-4 text-[0.95rem] leading-relaxed max-w-[85%] bubble-content markdown-content border border-transparent ai-bubble w-full flex flex-col items-stretch" data-raw="">
                <div class="flex justify-end mt-4 pt-2 border-t border-slate-800/20 hidden bottom-copy-container mt-auto">
                    <button type="button" class="text-xs text-slate-500 hover:text-cyan-400 flex items-center gap-1 transition-colors duration-150 cursor-pointer bg-transparent border-none p-0.5 flex items-center gap-1"
                            onclick="copyToClipboard(this)" 
                            title="Copy message">
                        <uk-icon icon="copy" class="w-3.5 h-3.5"></uk-icon> <span>Copy Entire Message</span>
                    </button>
                </div>
            </div>
        </div>
    </template>
</section>

<script type="module" src="js/chat/chatWindowBootstrap.js"></script>
