<style>
    .file-accordion-content {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s cubic-bezier(0.4, 0, 0.2, 1), padding 0.3s ease;
    }
    .file-accordion-content.expanded {
        max-height: 480px;
    }
    .accordion-arrow {
        transition: transform 0.2s ease;
    }
    .accordion-arrow.rotated {
        transform: rotate(90deg);
    }
</style>

<script>
    (function() {
        if (typeof marked !== 'undefined' && typeof markedKatex !== 'undefined') {
            marked.use(markedKatex({
                throwOnError: false,
                nonStandard: true
            }));
        }
    })();

    window.selectedFileReferences = [];

    window.toggleFileAccordion = function(headerElement) {
        const container = headerElement.closest('.file-accordion-item');
        if (!container) return;
        
        const contentPanel = container.querySelector('.file-accordion-content');
        const arrowIcon = container.querySelector('.accordion-arrow');
        
        if (!contentPanel) return;
        
        const isExpanded = contentPanel.classList.contains('expanded');
        
        const list = container.closest('.file-accordion-list');
        if (list) {
            list.querySelectorAll('.file-accordion-item').forEach(item => {
                if (item !== container) {
                    const siblingContent = item.querySelector('.file-accordion-content');
                    const siblingArrow = item.querySelector('.accordion-arrow');
                    if (siblingContent) siblingContent.classList.remove('expanded');
                    if (siblingArrow) siblingArrow.classList.remove('rotated');
                }
            });
        }
        
        if (isExpanded) {
            contentPanel.classList.remove('expanded');
            if (arrowIcon) arrowIcon.classList.remove('rotated');
        } else {
            contentPanel.classList.add('expanded');
            if (arrowIcon) arrowIcon.classList.add('rotated');
            
            const docPre = contentPanel.querySelector('.document-lazy-load');
            if (docPre && docPre.getAttribute('data-loaded') === 'false') {
                const filename = docPre.getAttribute('data-file');
                const loadingText = contentPanel.querySelector('.lazy-loading-indicator');
                
                fetch(`index.php?api_action=get_file_content&file=${encodeURIComponent(filename)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            docPre.textContent = data.content;
                            docPre.setAttribute('data-loaded', 'true');
                            if (loadingText) loadingText.classList.add('hidden');
                            docPre.classList.remove('hidden');
                        } else {
                            if (loadingText) loadingText.textContent = `Error: ${data.message}`;
                        }
                    })
                    .catch(err => {
                        if (loadingText) loadingText.textContent = `Error connecting to API: ${err.message}`;
                    });
            }
        }
    };

    window.showFileInExplorer = function(filename, button) {
        if (!filename) return;
        const originalHTML = button.innerHTML;
        button.disabled = true;
        button.innerHTML = `
            <svg class="animate-spin h-3.5 w-3.5 text-cyan-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            Locating...
        `;
        
        fetch(`index.php?api_action=show_in_explorer&file=${encodeURIComponent(filename)}`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    button.innerHTML = `
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-emerald-400"><polyline points="20 6 9 17 4 12"/></svg>
                        Opened!
                    `;
                } else if (data.status === 'fallback') {
                    window.open(data.url, '_blank');
                    navigator.clipboard.writeText(data.physical_path).catch(() => {});
                    button.innerHTML = `
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-amber-400"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                        Opened (Tab)!
                    `;
                } else {
                    alert(`Could not open path: ${data.message}`);
                    button.innerHTML = originalHTML;
                }
            })
            .catch(err => {
                alert(`Error communicating with system: ${err.message}`);
                button.innerHTML = originalHTML;
            })
            .finally(() => {
                setTimeout(() => {
                    button.innerHTML = originalHTML;
                    button.disabled = false;
                }, 2000);
            });
    };

    window.appendFileFromAccordion = function(button, file) {
        if (typeof window.addFileReference === 'function') {
            window.addFileReference(file);
            const originalHTML = button.innerHTML;
            button.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-emerald-400"><polyline points="20 6 9 17 4 12"/></svg>
                Appended!
            `;
            button.classList.add('border-emerald-500/40', 'text-emerald-400');
            setTimeout(() => {
                button.innerHTML = originalHTML;
                button.classList.remove('border-emerald-500/40', 'text-emerald-400');
            }, 1500);
        }
    };

    window.parseInlineFiles = function(content) {
        if (!content) return '';
        const fileRegex = /\[File:\s*([a-zA-Z0-9._\-]+)\]/g;
        return content.replace(fileRegex, (match, filename) => {
            const ext = filename.split('.').pop().toLowerCase();
            const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext);
            
            return `
            <div class="file-accordion-item my-4 max-w-xl bg-[#091124]/90 border border-cyan-500/20 hover:border-cyan-500/40 rounded-xl overflow-hidden shadow-md select-none text-left">
                <div class="flex items-center justify-between p-3 cursor-pointer bg-slate-900/40 hover:bg-slate-900/70 transition-colors duration-150" onclick="window.toggleFileAccordion(this)">
                    <div class="flex items-center gap-3 truncate flex-1 min-w-0">
                        <span class="flex items-center justify-center shrink-0 w-8 h-8 bg-slate-950/80 rounded border border-cyan-500/20 text-cyan-400">
                            ${isImage 
                                ? '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>'
                                : '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>'}
                        </span>
                        <div class="truncate flex-1 min-w-0">
                            <div class="text-[11px] font-bold text-slate-200 truncate tracking-wide">${filename}</div>
                            <div class="text-[9px] text-cyan-500/70 italic uppercase tracking-wider">${isImage ? 'Image Reference' : 'Document Reference'}</div>
                        </div>
                    </div>
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="accordion-arrow text-slate-400 shrink-0 ml-3"><polyline points="9 18 15 12 9 6"/></svg>
                </div>
                
                <div class="file-accordion-content bg-[#070b13]/55">
                    <div class="p-4 border-t border-slate-900/60 flex flex-col gap-3">
                        ${isImage 
                            ? `<img src="uploads/${filename}" class="w-full h-auto max-h-72 object-contain rounded-lg border border-slate-800 bg-slate-950/40 p-1 block" alt="${filename}"/>`
                            : `<div class="bg-slate-950/90 border border-slate-800 rounded-lg p-3 text-xs font-mono text-slate-300 max-h-60 overflow-y-auto whitespace-pre-wrap text-left relative min-h-[60px]">
                                 <span class="lazy-loading-indicator text-cyan-400 flex items-center gap-2 font-sans font-medium">
                                     <svg class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                     Retrieving text content from disk...
                                 </span>
                                 <pre class="document-lazy-load hidden text-[11px] leading-relaxed select-text" data-file="${filename}" data-loaded="false"></pre>
                               </div>`}
                        
                        <div class="flex items-center gap-2 select-none border-t border-slate-900/40 pt-3">
                            <button type="button" class="flex items-center justify-center gap-1.5 px-3 py-1.5 text-[10px] font-extrabold tracking-wider uppercase bg-slate-800 hover:bg-slate-750 text-slate-300 hover:text-cyan-400 border border-slate-700 hover:border-cyan-500/30 rounded-lg transition-all cursor-pointer" onclick="window.showFileInExplorer('${filename}', this)">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
                                Show in Explorer
                            </button>
                            <button type="button" class="flex items-center justify-center gap-1.5 px-3 py-1.5 text-[10px] font-extrabold tracking-wider uppercase bg-cyan-950/40 hover:bg-cyan-900/60 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 rounded-lg transition-all cursor-pointer" onclick="window.appendFileFromAccordion(this, {physical_name: '${filename}', file_type: '${isImage ? 'image' : 'document'}', generated_title: '${filename}', original_name: '${filename}'})">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>
                                Append to Chat
                            </button>
                        </div>
                    </div>
                </div>
            </div>`;
        });
    };

    window.addFileReference = function(file) {
        if (!window.selectedFileReferences) {
            window.selectedFileReferences = [];
        }
        if (window.selectedFileReferences.some(f => f.physical_name === file.physical_name)) return;
        
        window.selectedFileReferences.push(file);
        window.updateFileReferencesUI();
    };

    window.removeFileReference = function(physicalName) {
        window.selectedFileReferences = window.selectedFileReferences.filter(f => f.physical_name !== physicalName);
        window.updateFileReferencesUI();
    };

    window.updateFileReferencesUI = function() {
        const container = document.getElementById('referenced-files-container');
        if (!container) return;
        
        container.innerHTML = '';
        
        window.selectedFileReferences.forEach(file => {
            const badge = document.createElement('div');
            badge.className = "flex items-center gap-3 bg-[#091124]/90 border border-cyan-500/30 rounded-lg p-2 text-xs text-cyan-400 font-medium shadow-[0_0_12px_rgba(6,182,212,0.15)] select-none animate-fade-in max-w-sm";
            
            if (file.file_type === 'image') {
                badge.innerHTML = `
                    <img src="uploads/${file.physical_name}" class="w-8 h-8 object-cover rounded border border-cyan-500/30 shrink-0" alt="Preview"/>
                    <div class="truncate flex-1 min-w-0 text-left">
                        <div class="text-[10px] font-bold tracking-wider uppercase text-slate-200 truncate">${file.generated_title}</div>
                        <div class="text-[9px] text-cyan-500/70 truncate">${file.original_name}</div>
                    </div>
                    <button type="button" class="text-slate-500 hover:text-rose-400 transition-colors duration-150 focus:outline-none ml-1 font-extrabold text-xs cursor-pointer shrink-0" onclick="window.removeFileReference('${file.physical_name}')">×</button>
                `;
            } else {
                const previewSnippet = file.preview ? file.preview.substring(0, 45).trim() + '...' : '[No preview available]';
                badge.innerHTML = `
                    <span class="flex items-center justify-center shrink-0 w-8 h-8 bg-slate-900 rounded border border-cyan-500/20 text-cyan-400">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="text-cyan-400">
                            <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>
                        </svg>
                    </span>
                    <div class="truncate flex-1 min-w-0 text-left">
                        <div class="text-[10px] font-bold tracking-wider uppercase text-slate-200 truncate">${file.generated_title}</div>
                        <div class="text-[9px] text-slate-400/80 truncate italic">"${previewSnippet}"</div>
                    </div>
                    <button type="button" class="text-slate-500 hover:text-rose-400 transition-colors duration-150 focus:outline-none ml-1 font-extrabold text-xs cursor-pointer shrink-0" onclick="window.removeFileReference('${file.physical_name}')">×</button>
                `;
            }
            container.appendChild(badge);
        });
    };

    window.copyPathToClipboard = function(button, path) {
        navigator.clipboard.writeText(path).then(() => {
            const originalHTML = button.innerHTML;
            button.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="text-emerald-400 w-2.5 h-2.5">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            `;
            button.classList.add('border-emerald-500/30');
            setTimeout(() => {
                button.innerHTML = originalHTML;
                button.classList.remove('border-emerald-500/30');
            }, 1500);
        }).catch(err => {
            console.error(err);
        });
    };

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
        const parseAllCurrentMessages = () => {
            document.querySelectorAll('.markdown-rendered').forEach(el => {
                const rawMarkdown = el.getAttribute('data-markdown') || el.textContent;
                if (typeof marked !== 'undefined') {
                    el.innerHTML = window.parseInlineFiles(marked.parse(rawMarkdown));
                }
            });
            document.querySelectorAll('.chat-user').forEach(el => {
                el.innerHTML = window.parseInlineFiles(el.innerHTML);
            });
        };
        parseAllCurrentMessages();

        const syncBtn = document.getElementById('btn-sync-lmstudio');
        if (syncBtn) {
            syncBtn.addEventListener('click', () => {
                const originalHTML = syncBtn.innerHTML;
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

            <div id="referenced-files-container" class="flex flex-wrap gap-2 mb-3"></div>
            
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
        <div id="condensation-modal-card" class="bg-[#0f172a] border border-cyan-500/30 p-8 rounded-2xl max-w-md w-full shadow-[0_0_50px_rgba(6,182,212,0.2)] text-center transition-all duration-300">
            
            <!-- Panel 1: Ask Confirmation -->
            <div id="condensation-modal-content">
                <uk-icon icon="archive" class="w-12 h-12 text-cyan-400 mb-4 animate-pulse"></uk-icon>
                <h3 class="text-xl font-bold text-white mb-2">Context Limit Approaching</h3>
                <p class="text-sm text-slate-400 mb-6">This conversation is getting very long. Would you like me to condense older messages into a summary and extract facts into your long-term memory? This keeps the session fast and light.</p>
                <div class="flex gap-3 justify-center">
                    <button type="button" onclick="bypassCondensation()" class="px-4 py-2 text-slate-400 hover:text-white transition-colors cursor-pointer text-sm font-medium">Not now</button>
                    <button type="button" onclick="confirmCondensation()" class="btn-futuristic px-5 py-2 rounded-lg bg-cyan-600 text-white font-bold cursor-pointer text-sm">Yes, Optimize Memory</button>
                </div>
            </div>

            <!-- Panel 2: Memory Approval (Human in the Loop) -->
            <div id="condensation-modal-review" class="hidden text-left flex flex-col items-stretch max-h-[85vh]">
                <div class="flex items-center gap-2 mb-4 border-b border-cyan-500/20 pb-3">
                    <uk-icon icon="brain" class="w-6 h-6 text-cyan-400 animate-pulse"></uk-icon>
                    <h3 class="text-lg font-bold text-white uppercase tracking-wider">Memory Approval Required</h3>
                </div>
                
                <p class="text-xs text-slate-400 mb-4">
                    The AI has extracted the following insights. Deselect any entries that are redundant, inaccurate, or that you do not wish to store permanently.
                </p>

                <!-- Glowing Futuristic Checkbox List Container -->
                <div class="flex-1 overflow-y-auto pr-1 space-y-3 mb-6 max-h-[350px]" id="condensation-memories-list">
                    <!-- Checkbox items will be rendered dynamically here -->
                </div>

                <div class="flex justify-between items-center border-t border-cyan-500/20 pt-4">
                    <button type="button" onclick="closeCondensationModal()" class="px-4 py-2 text-slate-400 hover:text-white transition-colors cursor-pointer text-xs uppercase font-bold tracking-wider">Cancel</button>
                    <button type="button" onclick="applyCondensation()" class="btn-futuristic px-5 py-2.5 rounded-lg text-white font-bold cursor-pointer text-xs uppercase tracking-wider flex items-center gap-2">
                        <uk-icon icon="check" class="w-4 h-4 text-cyan-400"></uk-icon>
                        Commit & Apply
                    </button>
                </div>
            </div>
            
            <!-- Loading Indicator -->
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
                        <uk-icon icon="copy" class="w-3.5 h-3.5"></uk-icon> <span>Copy Entire Message</span>
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