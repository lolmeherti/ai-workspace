<script>
    (function() {
        if (typeof marked !== 'undefined' && typeof markedKatex !== 'undefined') {
            marked.use(markedKatex({
                throwOnError: false,
                nonStandard: true
            }));
        }
    })();

    window.copyToClipboard = function(button) {
        const container = button.closest('.chat-message-container') || button.closest('.flex-col');
        if (!container) return;

        const bubble = container.querySelector('[data-raw]');
        if (!bubble) return;

        const textToCopy = bubble.getAttribute('data-raw') || bubble.dataset.raw || '';
        if (!textToCopy) return;

        navigator.clipboard.writeText(textToCopy).then(() => {
            const icon = button.querySelector('uk-icon');
            const labelSpan = button.querySelector('span');
            const originalText = labelSpan ? labelSpan.textContent : '';

            if (icon) {
                icon.setAttribute('icon', 'check');
                button.classList.add('text-emerald-400');
                button.classList.remove('text-slate-500', 'hover:text-cyan-400');

                if (labelSpan) {
                    labelSpan.textContent = 'Copied!';
                }

                setTimeout(() => {
                    icon.setAttribute('icon', 'copy');
                    button.classList.remove('text-emerald-400');
                    button.classList.add('text-slate-500', 'hover:text-cyan-400');
                    if (labelSpan) {
                        labelSpan.textContent = originalText;
                    }
                }, 1500);
            }
        }).catch(err => {
            console.error(err);
        });
    };

    document.addEventListener('DOMContentLoaded', () => {
        const syncBtn = document.getElementById('btn-sync-lmstudio');
        if (syncBtn) {
            syncBtn.addEventListener('click', () => {
                const originalHTML = syncBtn.innerHTML;
                syncBtn.innerHTML = '<span class="animate-spin text-cyan-400 flex items-center justify-center"><uk-icon icon="refresh" class="w-2.5 h-2.5"></uk-icon></span> <span>SYNCING...</span>';
                syncBtn.disabled = true;

                fetch('index.php?api_action=sync_lmstudio_limit')
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            window.location.reload();
                        } else {
                            alert(`Sync Failed: ${data.message}`);
                        }
                    })
                    .catch(err => {
                        alert(`Error connecting to server: ${err.message}`);
                    })
                    .finally(() => {
                        syncBtn.innerHTML = originalHTML;
                        syncBtn.disabled = false;
                    });
            });
        }

        const handleTextCheck = (bubble) => {
            const textLength = bubble.textContent.trim().length;
            if (textLength > 300) {
                const bottomCopy = bubble.querySelector('.bottom-copy-container');
                if (bottomCopy) {
                    bottomCopy.classList.remove('hidden');
                }
            }
        };

        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.type === 'childList') {
                    mutation.addedNodes.forEach((node) => {
                        if (node.nodeType === 1) {
                            const bubbles = node.querySelectorAll('.chat-user, .chat-assistant');
                            bubbles.forEach(handleTextCheck);
                        }
                    });
                } else if (mutation.type === 'characterData') {
                    const bubble = mutation.target.parentElement?.closest('.chat-user, .chat-assistant');
                    if (bubble) {
                        handleTextCheck(bubble);
                    }
                }
            });
        });

        const chatWindow = document.getElementById('chatWindow');
        if (chatWindow) {
            observer.observe(chatWindow, { childList: true, subtree: true, characterData: true });
        }
    });
</script>

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
                <div class="flex flex-col w-full max-w-[92%] mx-auto space-y-1 chat-message-container <?php echo $msg['role'] === 'user' ? 'items-end' : 'items-start'; ?>">
                    
                    <div class="flex items-center gap-2 <?php echo $msg['role'] === 'user' ? 'flex-row-reverse mr-1' : 'ml-1'; ?>">
                        <span class="text-xs text-slate-500 font-semibold uppercase tracking-wider flex items-center gap-2">
                            <?php echo $msg['role'] === 'user' ? 'You' : htmlspecialchars($msg['model'] ?? $msg['model_name'] ?? \App\Config::get('LLM_MODEL_NAME', 'Assistant')); ?>
                            <?php if ($msg['role'] !== 'user'): ?>
                                <?php if (!empty($msg['cache_used'])): ?>
                                    <span class="text-[0.65rem] px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-400 border border-amber-500/30 flex items-center gap-1 normal-case tracking-normal shadow-sm">
                                        <uk-icon icon="zap" class="w-3 h-3"></uk-icon> Memory Cached
                                    </span>
                                <?php elseif (!empty($msg['search_query'])): ?>
                                    <span class="text-[0.65rem] px-2 py-0.5 rounded-full bg-blue-500/20 text-blue-400 border border-blue-500/30 flex items-center gap-1 normal-case tracking-normal shadow-sm">
                                        <uk-icon icon="globe" class="w-3 h-3"></uk-icon> Web Search
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
                            <?php if (!empty($msg['scraped_urls']) || !empty($msg['search_query']) || !empty($msg['cache_used'])): ?>
                                <details class="w-full bg-slate-900/40 border border-slate-800/80 rounded-lg mb-4 overflow-hidden group">
                                    <summary class="flex items-center justify-between px-4 py-3 text-xs font-semibold text-slate-400 hover:text-slate-200 hover:bg-slate-800/30 cursor-pointer select-none">
                                        <span class="flex items-center gap-2">
                                            <uk-icon icon="settings" class="w-3.5 h-3.5 group-open:rotate-90 transition-transform duration-200"></uk-icon>
                                            Agent Execution Trace
                                        </span>
                                        <span class="text-[0.65rem] text-slate-500 font-normal">Click to expand</span>
                                    </summary>
                                    <div class="px-4 pb-4 pt-2 border-t border-slate-800/50 space-y-2">
                                        <?php if (!empty($msg['cache_used'])): ?>
                                            <div class="text-xs text-amber-400 flex items-center gap-1.5 font-medium">
                                                <uk-icon icon="zap" class="w-3.5 h-3.5"></uk-icon> Memory Cache matched successfully
                                            </div>
                                        <?php elseif (!empty($msg['search_query'])): ?>
                                            <div class="text-xs text-blue-400 flex items-center gap-1.5 font-medium">
                                                <uk-icon icon="globe" class="w-3.5 h-3.5"></uk-icon> Web Search Triggered: "<?php echo htmlspecialchars($msg['search_query']); ?>"
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($msg['scraped_urls'])): ?>
                                            <?php $urls = json_decode($msg['scraped_urls'], true); ?>
                                            <?php if (is_array($urls) && !empty($urls)): ?>
                                                <div class="flex flex-col gap-1.5">
                                                    <?php foreach ($urls as $url): ?>
                                                        <a href="<?php echo htmlspecialchars($url); ?>" target="_blank" class="flex items-center gap-2 text-xs text-emerald-400 bg-slate-950/40 p-2 rounded border border-slate-850 hover:bg-slate-800/30 transition-colors w-full font-medium">
                                                            <uk-icon icon="check-circle" class="w-3.5 h-3.5"></uk-icon>
                                                            <span class="truncate max-w-full"><?php echo htmlspecialchars($url); ?></span>
                                                        </a>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            <?php endif; ?>
                            <div class="markdown-rendered" data-markdown="<?php echo htmlspecialchars($msg['message']); ?>"></div>
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
            
            <form id="chatForm" onsubmit="handleChatSubmit(event)" class="relative">
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

    <div id="condensation-modal" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-[#070b14]/90 backdrop-blur-sm">
        <div class="bg-[#0f172a] border border-cyan-500/30 p-8 rounded-2xl max-w-md w-full shadow-[0_0_50px_rgba(6,182,212,0.2)] text-center">
            <div id="condensation-modal-content">
                <uk-icon icon="archive" class="w-12 h-12 text-cyan-400 mb-4 animate-pulse"></uk-icon>
                <h3 class="text-xl font-bold text-white mb-2">Context Limit Approaching</h3>
                <p class="text-sm text-slate-400 mb-6">This conversation is getting very long. Would you like me to condense older messages into a summary and extract facts into your long-term memory? This keeps the session fast and light.</p>
                <div class="flex gap-3 justify-center">
                    <button type="button" onclick="bypassCondensation()" class="px-4 py-2 text-slate-400 hover:text-white transition-colors cursor-pointer text-sm font-medium">Not now</button>
                    <button type="button" onclick="confirmCondensation()" class="btn-futuristic px-5 py-2 rounded-lg bg-cyan-600 text-white font-bold cursor-pointer text-sm">Yes, Optimize Memory</button>
                </div>
            </div>
            <div id="condensation-modal-loading" class="hidden flex flex-col items-center gap-4 py-4">
                <span class="uk-spinner uk-spinner-medium text-cyan-500 animate-spin" uk-spinner="ratio: 1.2"></span>
                <p class="text-cyan-400 font-medium animate-pulse text-sm">Condensing history & extracting memories...</p>
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
                        <uk-icon icon="copy" class="w-3 h-3"></uk-icon> <span>Copy Entire Message</span>
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
                        <uk-icon icon="copy" class="w-3 h-3"></uk-icon> <span>Copy Entire Message</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    <template id="tpl-ask-user">
        <div class="flex flex-col w-full max-w-[92%] mx-auto space-y-1 items-start mb-4 cache-prompt-bubble">
            <div class="flex items-center gap-2 ml-1">
                <span class="text-xs text-slate-500 font-semibold uppercase tracking-wider flex items-center gap-2">
                    System Cache Routing
                    <span class="text-[0.65rem] px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-400 border border-indigo-500/30 flex items-center gap-1 normal-case tracking-normal shadow-sm">
                        <uk-icon icon="help-circle" class="w-3 h-3"></uk-icon> Action Required
                    </span>
                </span>
            </div>
            <div class="chat-assistant rounded-2xl rounded-tl-sm px-5 py-4 text-[0.95rem] leading-relaxed max-w-[85%] border border-indigo-500/30 shadow-[0_0_15px_rgba(99,102,241,0.15)] relative overflow-hidden flex flex-col items-stretch">
                <div class="absolute inset-0 bg-indigo-900/10 z-0"></div>
                <div class="relative z-10 flex flex-col items-stretch">
                    <p class="mb-4 text-slate-200">I have compiled notes on a highly relevant topic recently: <br><em class="text-white font-medium ask-topic"></em></p>
                    <div class="flex flex-wrap gap-3 mt-4">
                        <button type="button" class="btn-use-cache bg-indigo-600 hover:bg-indigo-500 text-white px-4 py-2 rounded-lg shadow-md text-sm transition-colors border border-indigo-400/50 flex items-center gap-2 font-medium">
                            <uk-icon icon="zap" class="w-4 h-4"></uk-icon> Fetch from Memory (Instant)
                        </button>
                        <button type="button" class="btn-force-live bg-slate-700 hover:bg-slate-600 text-slate-200 px-4 py-2 rounded-lg shadow text-sm transition-colors border border-slate-600 flex items-center gap-2">
                            <uk-icon icon="globe" class="w-4 h-4"></uk-icon> Force Live Search (Slower)
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</section>