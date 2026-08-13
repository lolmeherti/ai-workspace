<section class="flex-1 flex flex-col h-full relative bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-[#0d1526] via-[#070b14] to-[#070b14]">
    
    <header class="h-16 border-b border-slate-800/80 flex items-center justify-between px-6 glass-panel backdrop-blur-md z-10">
        <h2 class="m-0 text-base font-semibold truncate text-slate-100 flex items-center gap-3">
            <uk-icon icon="message-square" class="w-5 h-5 text-cyan-500"></uk-icon>
            <?php echo htmlspecialchars($activeSessionTitle); ?>
        </h2>
        <div class="flex items-center gap-4">
            <div id="token-counter-container" class="hidden md:flex items-center gap-2 bg-slate-900/60 border border-slate-800/80 px-3.5 py-1.5 rounded-full text-xs font-semibold tracking-wide">
                <uk-icon icon="cpu" class="w-3.5 h-3.5 text-cyan-400"></uk-icon>
                <span class="text-slate-400">Context: <strong id="token-counter-text" class="text-slate-200">0 / 0</strong> tokens</span>
                <div class="w-16 h-1.5 bg-slate-850 rounded-full overflow-hidden ml-1 border border-slate-800">
                    <div id="token-counter-bar" class="h-full bg-cyan-500 transition-all duration-300" style="width: 0%"></div>
                </div>
                <button type="button" id="btn-sync-lmstudio" class="group flex items-center justify-center gap-1.5 bg-transparent border border-slate-800/80 hover:border-cyan-500/40 text-slate-400 hover:text-cyan-400 px-2.5 py-0.5 rounded-full text-[10px] tracking-wider transition-all duration-300 font-bold cursor-pointer ml-1.5 outline-none" title="Sync Context Limit from LM Studio">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="w-3 h-3 transform group-hover:rotate-180 transition-transform duration-500 ease-out">
                        <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                        <path d="M3 3v5h5"/>
                        <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/>
                        <path d="M16 16h5v5"/>
                    </svg>
                    <span>SYNC LIMIT</span>
                </button>
                <button type="button" id="btn-manual-condense" class="group flex items-center justify-center gap-1.5 bg-transparent border border-slate-800/80 hover:border-cyan-500/40 text-slate-400 hover:text-cyan-400 px-2.5 py-0.5 rounded-full text-[10px] tracking-wider transition-all duration-300 font-bold cursor-pointer ml-1.5 outline-none" title="Manually Condense Chat History" onclick="triggerManualCondensation()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="w-3 h-3 transition-transform duration-300 group-hover:scale-110">
                        <polyline points="21 8 21 21 3 21 3 8"/>
                        <rect x="1" y="3" width="22" height="5"/>
                        <line x1="10" y1="12" x2="14" y2="12"/>
                    </svg>
                    <span>CONDENSE CHAT</span>
                </button>
            </div>

            <?php if (!$status->all_operational): ?>
                <span class="text-xs font-bold px-3 py-1 rounded-full bg-rose-500/10 border border-rose-500/30 text-rose-400 flex items-center gap-2 shadow-[0_0_10px_rgba(244,63,94,0.2)]">
                    <span class="w-2 h-2 rounded-full bg-rose-500 animate-pulse"></span> Offline
                </span>
            <?php else: ?>
                <span class="text-xs font-bold px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 flex items-center gap-2 shadow-[0_0_10px_rgba(16,185,129,0.2)]">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> Operational
                </span>
            <?php endif; ?>
        </div>
    </header>

    <!-- NEW SPLIT-PANE WRAPPER -->
    <div class="flex-1 flex h-full relative overflow-hidden">
        
        <!-- LEFT PANE: CONVERSATION HUB (100% width on load, shrinks to 40% when editor is active) -->
        <div class="flex-1 flex flex-col h-full min-w-0" id="chat-pane">
            
            <div class="flex-1 overflow-y-auto p-6 space-y-8" id="chatWindow">
                <?php if (empty($history)): ?>
                    <div class="flex flex-col items-center justify-center text-center h-full py-20 opacity-80" id="empty-state">
                        <div class="w-20 h-20 mb-6 rounded-full bg-gradient-to-tr from-cyan-500/20 to-blue-500/20 flex items-center justify-center border border-cyan-500/30 shadow-[0_0_30px_rgba(6,182,212,0.15)]">
                            <uk-icon icon="bot" class="w-10 h-10 text-cyan-400"></uk-icon>
                        </div>
                        <h3 class="text-2xl font-bold tracking-tight text-white mb-2">How can I assist you today?</h3>
                        <p class="text-sm text-slate-400 max-w-sm">Enter a prompt, ask a question, or attach a document/image to start the conversation.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($history as $msg): ?>
                        <?php 
                        $msgType = $msg['message_type'] ?? 'text';
                        if ($msgType === 'data_fetching'):
                            // Data fetching results are internal tool output — the model
                            // already summarized them in its response. Skip rendering.
                            continue;
                        endif;
                        if (($msg['role'] ?? '') === 'system') continue;
                        ?>
                        <div class="flex flex-col w-full max-w-[92%] mx-auto space-y-1 chat-message-container <?php echo $msg['role'] === 'user' ? 'items-end' : 'items-start'; ?>">
                            
                            <div class="flex items-center gap-2 <?php echo $msg['role'] === 'user' ? 'flex-row-reverse mr-1' : 'ml-1'; ?>">
                                <span class="text-xs text-slate-500 font-semibold uppercase tracking-wider flex items-center gap-2">
                                    <?php echo $msg['role'] === 'user' ? 'You' : htmlspecialchars($msg['model'] ?? $msg['model_name'] ?? \App\Config::get('LLM_MODEL_NAME', 'Assistant')); ?>
                                    <?php if ($msg['role'] !== 'user'): ?>
                                        <?php if (!empty($msg['search_query'])): ?>
                                            <span class="text-[0.65rem] px-2 py-0.5 rounded-full bg-blue-500/20 text-blue-400 border border-blue-500/30 flex items-center gap-1 normal-case tracking-normal shadow-sm">
                                                <uk-icon icon="globe" class="w-3.5 h-3.5"></uk-icon> Web Search
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </span>
                                <button class="text-slate-500 hover:text-cyan-400 p-0.5 rounded transition-colors duration-150 cursor-pointer flex items-center justify-center animate-fade-in" 
                                        onclick="copyToClipboard(this)" 
                                        title="Copy message">
                                    <uk-icon icon="copy" class="w-3.5 h-3.5"></uk-icon>
                                </button>
                            </div>
                            
                            <div class="<?php echo $msg['role'] === 'user' ? 'chat-user rounded-2xl rounded-tr-sm' : 'chat-assistant rounded-2xl rounded-tl-sm markdown-content flex flex-col items-stretch'; ?> px-5 py-4 text-[0.95rem] leading-relaxed max-w-[85%]"
                                 data-raw="<?php echo htmlspecialchars($msg['message']); ?>">
                                <?php if (!empty($msg['image_path'])): ?>
                                    <?php 
                                    $ext = strtolower(pathinfo($msg['image_path'], PATHINFO_EXTENSION));
                                    if (in_array($ext, ["png", "jpg", "jpeg", "gif", "webp"])): 
                                    ?>
                                        <img src="<?php echo htmlspecialchars($msg['image_path']); ?>" class="max-w-xs rounded-lg mb-3 border border-white/20 shadow-md block" alt="Uploaded image">
                                    <?php else: ?>
                                        <div class="flex items-center gap-2 bg-slate-900/60 border border-slate-800 p-3 rounded-lg max-w-xs mb-3">
                                            <uk-icon icon="file-text" class="w-6 h-6 text-cyan-400"></uk-icon>
                                            <span class="text-xs text-slate-300 font-medium truncate"><?php echo htmlspecialchars(basename($msg['image_path'])); ?></span>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php if ($msg['role'] === 'assistant'): ?>
                                    <div class="markdown-rendered" data-markdown="<?php echo htmlspecialchars($msg['message']); ?>"></div>
                                    <?php $sources = !empty($msg['source_map']) ? json_decode($msg['source_map'], true) : null; ?>
                                    <?php if (!empty($sources)): ?>
                                        <div class="sources-panel relative w-full mt-4 overflow-hidden rounded-xl border border-cyan-500/20 bg-gradient-to-b from-[#0d1321]/90 to-[#0d1321]/70 backdrop-blur-sm shadow-[0_0_25px_rgba(6,182,212,0.08),inset_0_1px_0_rgba(6,182,212,0.06)]">
                                            <span class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-cyan-400/60 to-transparent"></span>
                                            <div class="flex items-center gap-2 px-4 pt-3 pb-2">
                                                <span class="relative flex items-center justify-center w-6 h-6 rounded-md bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 shadow-[0_0_10px_rgba(6,182,212,0.12)]">
                                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                                                </span>
                                                <span class="text-[10px] font-semibold tracking-wider uppercase bg-gradient-to-r from-cyan-300 via-blue-400 to-emerald-400 bg-clip-text text-transparent">Sources</span>
                                                <span class="relative flex h-1.5 w-1.5">
                                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-cyan-400 opacity-75"></span>
                                                    <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-cyan-400"></span>
                                                </span>
                                                <span class="ml-auto text-[10px] px-1.5 py-0.5 rounded-full bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 font-mono"><?php echo count($sources); ?></span>
                                            </div>
                                            <div class="px-3 pb-3 flex flex-col gap-1.5">
                                                <?php foreach ($sources as $s): ?>
                                                    <?php
                                                    $srcUrl = $s['url'] ?? '';
                                                    $srcDomain = $s['domain'] ?? '';
                                                    $srcTitle = $s['title'] ?? '';
                                                    if ($srcTitle === '') {
                                                        $srcTitle = $srcDomain !== '' ? $srcDomain : $srcUrl;
                                                    }
                                                    ?>
                                                    <a href="<?php echo htmlspecialchars($srcUrl); ?>" target="_blank" rel="noopener noreferrer" class="group relative flex items-center gap-3 px-3 py-2 rounded-lg border border-slate-700/40 bg-slate-900/30 hover:border-cyan-500/30 hover:bg-cyan-500/5 hover:shadow-[0_0_16px_rgba(6,182,212,0.10)] transition-all duration-200">
                                                        <span class="flex items-center justify-center w-7 h-7 shrink-0 rounded-md bg-slate-800/60 border border-slate-700/50 text-cyan-400 group-hover:border-cyan-500/40 group-hover:text-cyan-300 group-hover:shadow-[0_0_12px_rgba(6,182,212,0.25)] transition-all">
                                                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                                                        </span>
                                                        <span class="flex flex-col min-w-0 flex-1">
                                                            <span class="text-xs text-slate-300 truncate group-hover:text-cyan-200 transition-colors"><?php echo htmlspecialchars($srcTitle); ?></span>
                                                            <?php if ($srcDomain !== '' && $srcDomain !== $srcTitle): ?>
                                                                <span class="text-[10px] text-slate-500 truncate font-mono group-hover:text-slate-400 transition-colors"><?php echo htmlspecialchars($srcDomain); ?></span>
                                                            <?php endif; ?>
                                                        </span>
                                                        <span class="shrink-0 text-slate-600 group-hover:text-cyan-400 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 transition-all">
                                                            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                                        </span>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                <?php endif; ?>

                                <?php if (strlen($msg['message']) > 300): ?>
                                    <div class="flex justify-end mt-4 pt-2 border-t border-slate-800/20 bottom-copy-container mt-auto">
                                        <button type="button" class="text-[10px] text-slate-500 hover:text-cyan-400 flex items-center gap-1 transition-colors duration-150 cursor-pointer bg-transparent border-none p-0.5 animate-fade-in flex items-center gap-1" 
                                                onclick="copyToClipboard(this)" 
                                                title="Copy message">
                                            <uk-icon icon="copy" class="w-3 h-3"></uk-icon>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
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
                            <span id="file-preview-type" class="text-[10px] text-slate-500 uppercase font-bold">Document</span>
                        </div>
                    </div>

                    <div id="referenced-files-container" class="flex flex-wrap gap-2 mb-3"></div>
                    
                    <form id="chatForm" onsubmit="event.preventDefault(); if (typeof handleChatSubmit === 'function') { handleChatSubmit(event); } else { console.error('handleChatSubmit is not defined. Intercepted reload to preserve console.'); }" class="relative">
                        <input type="hidden" name="session_id" value="<?php echo $sessionId; ?>">
                        <input type="file" id="fileInput" name="file" accept="image/*,.pdf,.docx,.txt,.py,.php,.js,.json,.css,.html,.md,.yml,.yaml,.xml" class="hidden" onchange="previewFile(this)">
                        
                        <div class="flex w-full items-end gap-2 bg-[#0f172a] border border-slate-700 rounded-xl p-1.5 focus-within:border-cyan-500 focus-within:ring-1 focus-within:ring-cyan-500 transition-all shadow-inner" <?php echo $status->all_operational ? '' : 'disabled'; ?>>
                            <button type="button" class="shrink-0 p-2.5 text-slate-400 hover:text-cyan-400 transition-colors rounded-lg hover:bg-slate-800" onclick="document.getElementById('fileInput').click()" title="Attach File">
                                <uk-icon icon="paperclip" class="w-5 h-5"></uk-icon>
                            </button>
                            
                            <textarea id="q" name="q" rows="1" class="flex-1 bg-transparent border-none text-slate-100 placeholder-slate-500 resize-none py-2.5 focus:outline-none focus:ring-0 max-h-32 min-h-[44px]" placeholder="Message AI Assistant..." required autocomplete="off" oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px'"></textarea>
                            
                            <button type="submit" class="btn-futuristic shrink-0 px-4 py-2 rounded-lg font-semibold flex items-center gap-2 h-[44px]">
                                Send <uk-icon icon="send" class="w-4 h-4"></uk-icon>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
        </div> <!-- END LEFT PANE -->

        <!-- RIGHT PANE: DRAWER WORKSPACE (Included modularly) -->
        <?php include 'chat-file-editor-drawer.php'; ?>

    </div> <!-- END NEW SPLIT-PANE WRAPPER -->

    <div id="condensation-modal" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-[#070b14]/90 backdrop-blur-sm">
        <div id="condensation-modal-card" class="bg-[#0f172a] border border-cyan-500/30 p-8 rounded-2xl max-w-md w-full shadow-[0_0_50px_rgba(6,182,212,0.2)] text-center transition-all duration-300">
            
            <div id="condensation-modal-content">
                <uk-icon icon="archive" class="w-12 h-12 text-cyan-400 mb-4 animate-pulse"></uk-icon>
                <h3 class="text-xl font-bold text-white mb-2">Context Limit Approaching</h3>
                <p class="text-sm text-slate-400 mb-6">This conversation is getting very long. Would you like me to condense older messages into a summary and extract facts into your long-term memory? This keeps the session fast and light.</p>
                <div class="flex gap-3 justify-center">
                    <button type="button" onclick="bypassCondensation()" class="px-4 py-2 text-slate-400 hover:text-white transition-colors cursor-pointer text-sm font-medium">Not now</button>
                    <button type="button" onclick="confirmCondensation()" class="btn-futuristic px-5 py-2 rounded-lg bg-cyan-600 text-white font-bold cursor-pointer text-sm">Yes, Optimize Memory</button>
                </div>
            </div>

            <div id="condensation-modal-review" class="hidden text-left flex flex-col items-stretch max-h-[85vh]">
                <div class="flex items-center gap-2 mb-4 border-b border-cyan-500/20 pb-3">
                    <uk-icon icon="brain" class="w-6 h-6 text-cyan-400 animate-pulse"></uk-icon>
                    <h3 class="text-lg font-bold text-white uppercase tracking-wider">Memory Approval Required</h3>
                </div>
                
                <p class="text-xs text-slate-400 mb-4">
                    The AI has extracted the following insights. Deselect any entries that are redundant, inaccurate, or that you do not wish to store permanently.
                </p>

                <div class="flex-1 overflow-y-auto pr-1 space-y-3 mb-6 max-h-[350px]" id="condensation-memories-list"></div>

                <div class="flex justify-between items-center border-t border-cyan-500/20 pt-4">
                    <button type="button" onclick="closeCondensationModal()" class="px-4 py-2 text-slate-400 hover:text-white transition-colors cursor-pointer text-xs uppercase font-bold tracking-wider">Cancel</button>
                    <button type="button" onclick="applyCondensation()" class="btn-futuristic px-5 py-2.5 rounded-lg text-white font-bold cursor-pointer text-xs uppercase tracking-wider flex items-center gap-2">
                        <uk-icon icon="check" class="w-4 h-4 text-cyan-400"></uk-icon>
                        Commit & Apply
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
                <span class="text-xs text-slate-500 font-semibold uppercase tracking-wider">You</span>
                <button type="button" class="text-slate-500 hover:text-cyan-400 p-0.5 rounded transition-colors duration-150 cursor-pointer flex items-center justify-center copy-btn" onclick="copyToClipboard(this)" title="Copy message">
                    <uk-icon icon="copy" class="w-3.5 h-3.5"></uk-icon>
                </button>
            </div>
            <div class="chat-user rounded-2xl rounded-tr-sm px-5 py-4 text-[0.95rem] leading-relaxed max-w-[85%] bubble-content" data-raw="">
                <img src="" class="max-w-xs rounded-lg mb-3 border border-white/20 shadow-md hidden upload-img" alt="Upload">
                <span class="msg-text"></span>
                <div class="flex justify-end mt-4 pt-2 border-t border-slate-800/20 hidden bottom-copy-container mt-auto">
                    <button type="button" class="text-[10px] text-slate-500 hover:text-cyan-400 flex items-center gap-1 transition-colors duration-150 cursor-pointer bg-transparent border-none p-0.5 flex items-center gap-1" 
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
                <span class="text-xs text-slate-500 font-semibold uppercase tracking-wider flex items-center gap-2 ai-label-container">
                    <?php echo htmlspecialchars(\App\Config::get('LLM_MODEL_NAME', 'Assistant')); ?>
                </span>
                <button type="button" class="text-slate-500 hover:text-cyan-400 p-0.5 rounded transition-colors duration-150 cursor-pointer flex items-center justify-center copy-btn" onclick="copyToClipboard(this)" title="Copy message">
                    <uk-icon icon="copy" class="w-3.5 h-3.5"></uk-icon>
                </button>
            </div>
            <div class="chat-assistant rounded-2xl rounded-tl-sm px-5 py-4 text-[0.95rem] leading-relaxed max-w-[85%] bubble-content markdown-content border border-transparent ai-bubble w-full flex flex-col items-stretch" data-raw="">
                <div class="flex justify-end mt-4 pt-2 border-t border-slate-800/20 hidden bottom-copy-container mt-auto">
                    <button type="button" class="text-[10px] text-slate-500 hover:text-cyan-400 flex items-center gap-1 transition-colors duration-150 cursor-pointer bg-transparent border-none p-0.5 flex items-center gap-1" 
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