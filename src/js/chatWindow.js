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
                        <!-- DYNAMIC LIVE EDIT TRIGGER BUTTON -->
                        ${!isImage ? `
                        <button type="button" class="flex items-center justify-center gap-1.5 px-3 py-1.5 text-[10px] font-extrabold tracking-wider uppercase bg-blue-950/40 hover:bg-blue-900/60 text-blue-400 border border-blue-500/20 hover:border-blue-400/50 rounded-lg transition-all cursor-pointer" onclick="window.openEditorDrawer('${filename}', this)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-blue-400"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            Edit Document
                        </button>
                        ` : ''}
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
            
            let toolQuery = null;
            const jsonRegex = /\{\s*"tool"\s*:\s*"search_files"\s*,\s*"query"\s*:\s*"([^"]+)"\s*\}/i;
            const match = rawMarkdown.match(jsonRegex);
            if (match) {
                toolQuery = match[1];
            }

            if (typeof marked !== 'undefined') {
                el.innerHTML = window.parseInlineFiles(marked.parse(rawMarkdown));
            }
            
            el.classList.add('markdown-content');

            if (toolQuery) {
                el.innerHTML = el.innerHTML.replace(/<pre><code[^>]*>[\s\S]*?"tool"\s*:\s*"search_files"[\s\S]*?<\/code><\/pre>/gi, '');
                el.innerHTML = el.innerHTML.replace(/<p>\s*\{[\s\S]*?"tool"\s*:\s*"search_files"[\s\S]*?\}\s*<\/p>/gi, '');
                el.innerHTML = el.innerHTML.replace(/Checking files\.\.\./gi, '');
                
                el.insertAdjacentHTML('afterbegin', `
                    <div class="text-[11px] text-cyan-400 bg-cyan-950/20 border border-cyan-500/20 px-3 py-2 rounded-lg italic mb-4 mt-1 flex items-center gap-2 max-w-sm shadow-sm select-none">
                        <uk-icon icon="search" class="w-3.5 h-3.5"></uk-icon> 
                        System automatically searched files for: "${toolQuery}"
                    </div>
                `);
                
                fetch(`index.php?api_action=search_files&query=${encodeURIComponent(toolQuery)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success' && data.files && data.files.length > 0) {
                            if (typeof window.renderFileChoices === 'function') {
                                window.renderFileChoices(data, el, document.getElementById('chatWindow'));
                            }
                        }
                    })
                    .catch(err => console.error("Error restoring file choices UI:", err));
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

    // Bind Close & Save actions inside DOMContentLoaded
    const closeBtn = document.getElementById('editor-close-btn');
    if (closeBtn) {
        closeBtn.addEventListener('click', window.closeEditorDrawer);
    }

    const saveBtn = document.getElementById('editor-save-btn');
    if (saveBtn) {
        saveBtn.addEventListener('click', window.saveEditorDraft);
    }
});

// -------------------------------------------------------------
// LIVE EDITOR DRAWER STATE & CONTROLLER METHODS
// -------------------------------------------------------------

window.activeEditFile = null;
window.activeBlocks = [];
window.activeToggledBlocks = new Set(); // Tracks clicked context blocks
let blockSaveTimeout = null;

/**
 * Calls the open_draft API, populates the editor blocks, and slides open the split drawer.
 */
window.openEditorDrawer = function(filename, button) {
    if (!filename) return;
    
    const originalHTML = button ? button.innerHTML : null;
    if (button) {
        button.disabled = true;
        button.innerHTML = `
            <svg class="animate-spin h-3.5 w-3.5 text-blue-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            Loading...
        `;
    }

    fetch(`index.php?api_action=open_draft&file=${encodeURIComponent(filename)}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                window.activeEditFile = filename;
                window.activeBlocks = data.blocks;
                
                // Persist active file in session storage to survive page reloads
                sessionStorage.setItem('activeEditFile', filename);

                // Update Header UI
                document.getElementById('editor-file-title').textContent = filename;

                // Open the drawer split-viewport
                const drawer = document.getElementById('chat-file-editor-drawer');
                drawer.classList.remove('w-0', 'border-transparent');
                drawer.classList.add('w-[60%]', 'border-slate-800/80');

                // Render the blocks
                window.renderEditorBlocks();
                
                // Restore any saved toggled blocks from session storage
                const savedToggles = sessionStorage.getItem('activeToggledBlocks');
                if (savedToggles) {
                    const array = JSON.parse(savedToggles);
                    array.forEach(id => {
                        window.activeToggledBlocks.add(id);
                        const card = document.getElementById(`block-card-${id}`);
                        if (card) {
                            card.classList.remove('border-transparent', 'bg-transparent');
                            card.classList.add('border-cyan-500/60', 'bg-cyan-950/20', 'shadow-[0_0_12px_rgba(6,182,212,0.15)]');
                        }
                    });
                }
                
                window.updateActiveTargetPill();
            } else {
                alert(`Error opening draft: ${data.message}`);
            }
        })
        .catch(err => alert(`Failed to load document: ${err.message}`))
        .finally(() => {
            if (button) {
                button.innerHTML = originalHTML;
                button.disabled = false;
            }
        });
};

/**
 * Closes the drawer and resets state, automatically discarding the draft on disk.
 */
window.closeEditorDrawer = function() {
    if (window.activeEditFile) {
        // Fire-and-forget discard request to clean up the draft from disk
        fetch('index.php?api_action=discard_draft', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ file: window.activeEditFile })
        }).catch(err => console.error("Failed to discard draft on disk:", err));
    }

    const drawer = document.getElementById('chat-file-editor-drawer');
    if (drawer) {
        drawer.classList.remove('w-[60%]', 'border-slate-800/80');
        drawer.classList.add('w-0', 'border-transparent');
    }
    
    window.activeEditFile = null;
    window.activeBlocks = [];
    window.activeToggledBlocks.clear();
    sessionStorage.removeItem('activeEditFile');
    sessionStorage.removeItem('activeToggledBlocks');
    document.getElementById('editor-blocks-container').innerHTML = '';
    window.updateActiveTargetPill();
};

/**
 * Commits the draft changes to disk and collapses the drawer on success.
 */
window.saveEditorDraft = function() {
    if (!window.activeEditFile) return;

    const saveBtn = document.getElementById('editor-save-btn');
    const originalHTML = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = 'Saving...';

    fetch('index.php?api_action=save_draft', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ file: window.activeEditFile })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            saveBtn.innerHTML = 'Saved!';
            setTimeout(() => {
                window.closeEditorDrawer();
            }, 1000);
        } else {
            alert(`Save failed: ${data.message}`);
            saveBtn.innerHTML = originalHTML;
            saveBtn.disabled = false;
        }
    })
    .catch(err => {
        alert(`Save Error: ${err.message}`);
        saveBtn.innerHTML = originalHTML;
        saveBtn.disabled = false;
    });
};

/**
 * Generates and binds the interactive click-to-toggle block list.
 * Includes micro-edit and micro-delete controls inside the hover gutter.
 */
window.renderEditorBlocks = function() {
    const container = document.getElementById('editor-blocks-container');
    if (!container) return;

    container.innerHTML = '';

    window.activeBlocks.forEach(block => {
        const blockNode = document.createElement('div');
        blockNode.id = `block-card-${block.id}`;
        blockNode.setAttribute('data-block-id', block.id);
        
        blockNode.className = "group relative p-2.5 border border-transparent bg-transparent rounded-lg cursor-pointer transition-all duration-150 select-none hover:bg-cyan-500/5 hover:border-cyan-500/20";

        blockNode.innerHTML = `
            <!-- High-tech micro control bar, visible on block hover -->
            <div class="absolute top-2 right-2 flex items-center gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity z-10 block-action-bar">
                <!-- Edit Pen -->
                <button type="button" class="p-1 bg-slate-900 border border-slate-800 rounded text-slate-500 hover:text-cyan-400 cursor-pointer outline-none block-edit-trigger" onclick="event.stopPropagation(); window.enableManualBlockEdit('${block.id}')" title="Edit Line">
                    <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="w-2.5 h-2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                </button>
                <!-- Delete Trash -->
                <button type="button" class="p-1 bg-slate-900 border border-slate-800 rounded text-slate-500 hover:text-rose-400 cursor-pointer outline-none block-delete-trigger" onclick="event.stopPropagation(); window.deleteSingleBlockDirectly('${block.id}')" title="Delete Line">
                    <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="w-2.5 h-2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
            </div>
            <div class="block-text text-slate-300 text-xs leading-relaxed font-sans pr-16 whitespace-pre-wrap">${block.content || '&nbsp;'}</div>
        `;

        // Single-Click: Toggle Selection (Context targeting)
        blockNode.addEventListener('click', (e) => {
            if (e.target.tagName === 'TEXTAREA' || e.target.closest('.block-action-bar')) return;
            window.toggleBlockSelection(block.id);
        });

        container.appendChild(blockNode);
    });
};

/**
 * Toggles a block's selection state and updates visual borders.
 */
window.toggleBlockSelection = function(blockId) {
    const card = document.getElementById(`block-card-${blockId}`);
    if (!card) return;

    if (window.activeToggledBlocks.has(blockId)) {
        window.activeToggledBlocks.delete(blockId);
        card.classList.remove('border-cyan-500/60', 'bg-cyan-950/20', 'shadow-[0_0_12px_rgba(6,182,212,0.15)]');
        card.classList.add('border-transparent', 'bg-transparent');
    } else {
        window.activeToggledBlocks.add(blockId);
        card.classList.remove('border-transparent', 'bg-transparent');
        card.classList.add('border-cyan-500/60', 'bg-cyan-950/20', 'shadow-[0_0_12px_rgba(6,182,212,0.15)]');
    }

    // Persist active selections to survive reloads
    sessionStorage.setItem('activeToggledBlocks', JSON.stringify(Array.from(window.activeToggledBlocks)));
    
    window.updateActiveTargetPill();
};

/**
 * Transforms standard text element into an editable textarea.
 */
window.enableManualBlockEdit = function(blockId) {
    const card = document.getElementById(`block-card-${blockId}`);
    if (!card) return;

    const textDiv = card.querySelector('.block-text');
    if (!textDiv) return;

    const originalContent = textDiv.textContent === '\u00A0' ? '' : textDiv.textContent;

    // Replace div with raw textarea
    card.innerHTML = `
        <span class="absolute top-2 right-2 text-[9px] text-slate-700 select-none font-bold">#${blockId}</span>
        <textarea class="w-full bg-transparent text-slate-200 outline-none resize-none text-xs leading-relaxed font-sans border-none p-0 focus:ring-0" oninput="window.handleBlockInput('${blockId}', this)">${originalContent}</textarea>
    `;

    const textarea = card.querySelector('textarea');
    textarea.focus();
    textarea.style.height = '';
    textarea.style.height = textarea.scrollHeight + 'px';

    // Save and revert to div on blur
    textarea.addEventListener('blur', () => {
        const finalValue = textarea.value.trim();
        card.innerHTML = `
            <button type="button" class="absolute top-2 right-2 p-1 bg-slate-900 border border-slate-800 rounded text-slate-500 hover:text-cyan-400 opacity-0 group-hover:opacity-100 transition-opacity cursor-pointer outline-none z-10 block-edit-trigger" onclick="event.stopPropagation(); window.enableManualBlockEdit('${blockId}')" title="Edit Line">
                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="w-2.5 h-2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
            </button>
            <div class="block-text text-slate-300 text-xs leading-relaxed font-sans pr-12 whitespace-pre-wrap">${finalValue || '&nbsp;'}</div>
        `;
        
        // Re-apply selected styles if active
        if (window.activeToggledBlocks.has(blockId)) {
            card.classList.add('border-cyan-500/60', 'bg-cyan-950/20', 'shadow-[0_0_12px_rgba(6,182,212,0.15)]');
        }
    });
};

/**
 * Debounces keystrokes and auto-saves the updated block on disk.
 */
window.handleBlockInput = function(blockId, textarea) {
    textarea.style.height = '';
    textarea.style.height = textarea.scrollHeight + 'px';

    clearTimeout(blockSaveTimeout);

    blockSaveTimeout = setTimeout(() => {
        if (!window.activeEditFile) return;

        fetch('index.php?api_action=update_draft', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                file: window.activeEditFile,
                block_id: blockId,
                content: textarea.value
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                window.activeBlocks = data.blocks;
                
                const card = document.getElementById(`block-card-${blockId}`);
                if (card) {
                    card.classList.add('border-emerald-500/20');
                    setTimeout(() => card.classList.remove('border-emerald-500/20'), 1000);
                }
            }
        });
    }, 600); // 600ms debounce
};

/**
 * Renders the glowing active context pill above your chat input.
 */
window.updateActiveTargetPill = function() {
    const container = document.getElementById('referenced-files-container');
    if (!container) return;

    // Remove any existing target pill
    const existingPill = document.getElementById('active-target-pill');
    if (existingPill) existingPill.remove();

    if (window.activeToggledBlocks.size === 0) return;

    const toggledArray = Array.from(window.activeToggledBlocks);
    const count = toggledArray.length;

    const pill = document.createElement('div');
    pill.id = 'active-target-pill';
    pill.className = "flex items-center gap-2.5 bg-indigo-950/40 border border-indigo-500/30 rounded-lg p-2 text-xs text-indigo-400 font-medium shadow-[0_0_12px_rgba(99,102,241,0.15)] select-none animate-fade-in max-w-sm";
    
    pill.innerHTML = `
        <span class="flex items-center justify-center shrink-0 w-8 h-8 bg-indigo-900/10 rounded border border-indigo-500/20 text-indigo-400 animate-pulse">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
        </span>
        <div class="truncate flex-1 min-w-0 text-left">
            <div class="text-[10px] font-bold tracking-wider uppercase text-slate-200 truncate">Targeting Context</div>
            <div class="text-[9px] text-indigo-500/70 truncate font-semibold uppercase tracking-wider">${count} Block(s) Selected (${toggledArray.join(', ')})</div>
        </div>
        <button type="button" class="text-slate-500 hover:text-rose-400 transition-colors duration-150 focus:outline-none ml-1 font-extrabold text-xs cursor-pointer shrink-0" onclick="window.clearActiveBlockToggles()">×</button>
    `;

    container.appendChild(pill);
};

/**
 * Resets all selection parameters.
 */
window.clearActiveBlockToggles = function() {
    window.activeToggledBlocks.clear();
    sessionStorage.removeItem('activeToggledBlocks');
    document.querySelectorAll('[data-block-id]').forEach(card => {
        card.classList.remove('border-cyan-500/60', 'bg-cyan-950/20', 'shadow-[0_0_12px_rgba(6,182,212,0.15)]');
        card.classList.add('border-transparent', 'bg-transparent');
    });
    window.updateActiveTargetPill();
};

// AUTO-RESTORE ACTIVE WORKSPACE WORKFLOW ON PAGE LOAD
document.addEventListener('DOMContentLoaded', () => {
    const savedActiveFile = sessionStorage.getItem('activeEditFile');
    if (savedActiveFile) {
        // Automatically reopen editor drawer on reload/sync redirect
        window.openEditorDrawer(savedActiveFile);
    }

    const closeBtn = document.getElementById('editor-close-btn');
    if (closeBtn) {
        closeBtn.addEventListener('click', window.closeEditorDrawer);
    }

    const saveBtn = document.getElementById('editor-save-btn');
    if (saveBtn) {
        saveBtn.addEventListener('click', window.saveEditorDraft);
    }
});

/**
 * Updates a block's text area in real-time as tokens stream in.
 */
window.streamUpdateBlockContent = function(blockId, partialText) {
    const card = document.getElementById(`block-card-${blockId}`);
    if (!card) return;

    // Mutate the local memory array
    const blockObj = window.activeBlocks.find(b => b.id === blockId);
    if (blockObj) {
        blockObj.content = partialText;
    }

    // Update the DOM element
    const textDiv = card.querySelector('.block-text');
    if (textDiv) {
        textDiv.textContent = partialText || '\u00A0';
    }
};

/**
 * Commits the completed block content directly to the draft on disk.
 */
window.commitBlockEditDirectly = function(blockId, finalContent) {
    const card = document.getElementById(`block-card-${blockId}`);
    if (card) {
        card.classList.remove('border-cyan-500/60', 'bg-cyan-950/20');
        const textDiv = card.querySelector('.block-text');
        if (textDiv) textDiv.textContent = finalContent || '\u00A0';
    }

    fetch('index.php?api_action=update_draft', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            file: window.activeEditFile,
            block_id: blockId,
            content: finalContent
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            window.activeBlocks = data.blocks;
        }
    });
};

/**
 * Evaluates if the stream completed without applying any edits.
 * If true, renders the manual "Apply" fallback button inside the chat bubble.
 */
window.evaluateStreamCompletion = function(hasAppliedEdit, bubble, textContainer) {
    if (hasAppliedEdit || window.activeToggledBlocks.size === 0) return;

    // Check if the apply button already exists in this bubble
    if (bubble.querySelector('.manual-apply-trigger')) return;

    const toggledArray = Array.from(window.activeToggledBlocks);

    const applyBtn = document.createElement('button');
    applyBtn.type = 'button';
    applyBtn.className = "manual-apply-trigger flex items-center justify-center gap-1.5 px-4 py-2 mt-4 text-[10px] font-extrabold tracking-wider uppercase bg-cyan-950/40 hover:bg-cyan-900/60 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 rounded-lg transition-all cursor-pointer outline-none w-fit self-start shadow-md select-none";
    applyBtn.innerHTML = `
        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><polyline points="20 6 9 17 4 12"/></svg>
        Apply Suggestion to Selected Blocks (${toggledArray.join(', ')})
    `;

    // When clicked, grab the suggestion text inside the bubble and overwrite selected blocks
    applyBtn.onclick = function() {
        const rawText = bubble.getAttribute('data-raw') || bubble.textContent;
        
        // Clean out conversational notes, keeping only lists or core code
        const cleanedText = rawText.replace(/user has toggled[\s\S]*?prompt:/gi, '').trim();

        // Split suggestion by lines
        const suggestionLines = cleanedText.split("\n").map(l => l.trim()).filter(l => l !== '');

        let lineIdx = 0;
        toggledArray.forEach(blockId => {
            const replacementText = suggestionLines[lineIdx] || suggestionLines[suggestionLines.length - 1] || '';
            if (replacementText) {
                window.commitBlockEditDirectly(blockId, replacementText);
            }
            lineIdx++;
        });

        // Change button to success state
        applyBtn.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-emerald-400"><polyline points="20 6 9 17 4 12"/></svg>
            Applied!
        `;
        applyBtn.className = applyBtn.className.replace('text-cyan-400', 'text-emerald-400').replace('border-cyan-500/30', 'border-emerald-500/40');
        
        setTimeout(() => {
            window.clearActiveBlockToggles();
            applyBtn.remove();
        }, 1500);
    };

    textContainer.appendChild(applyBtn);
};

/**
 * Validates if the selected blocks are strictly sequential (adjacent on disk).
 * Ignores empty whitespace blocks in between.
 */
window.isSelectionSequential = function() {
    if (window.activeToggledBlocks.size <= 1) return true;

    const numbers = Array.from(window.activeToggledBlocks).map(id => parseInt(id.replace('b-', ''), 10));
    numbers.sort((a, b) => a - b);

    for (let i = 0; i < numbers.length - 1; i++) {
        const currentNum = numbers[i];
        const nextNum = numbers[i + 1];

        // Check if there are non-empty blocks in the gaps between selections
        for (let j = currentNum + 1; j < nextNum; j++) {
            const middleBlock = window.activeBlocks.find(b => b.id === 'b-' + j);
            if (middleBlock && middleBlock.content.trim() !== '') {
                return false; // Non-empty gap found! Selections are non-sequential
            }
        }
    }
    return true;
};

/**
 * Dispatches a single or multi-block deletion request to the server and refreshes the canvas.
 */
window.deleteSelectedBlocks = function(targetIds = null) {
    const idsToDelete = targetIds || Array.from(window.activeToggledBlocks);
    if (!window.activeEditFile || idsToDelete.length === 0) return;

    const confirmMsg = `Are you sure you want to permanently delete these ${idsToDelete.length} line(s) from the draft?`;
    if (!confirm(confirmMsg)) return;

    // Show locking spinner
    const lockOverlay = document.getElementById('editor-lock-overlay');
    if (lockOverlay) {
        lockOverlay.classList.remove('opacity-0', 'pointer-events-none');
        lockOverlay.classList.add('opacity-100', 'pointer-events-auto');
    }

    fetch('index.php?api_action=delete_draft_blocks', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            file: window.activeEditFile,
            block_ids: idsToDelete
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            window.activeBlocks = data.blocks;
            window.activeToggledBlocks.clear();
            sessionStorage.removeItem('activeToggledBlocks');

            // Re-render the newly consolidated document blocks
            window.renderEditorBlocks();
            window.updateActiveTargetPill();

            const deleteSelectionBtn = document.getElementById('editor-delete-selection-btn');
            const editSelectionBtn = document.getElementById('editor-edit-selection-btn');
            if (deleteSelectionBtn) deleteSelectionBtn.classList.add('hidden');
            if (editSelectionBtn) editSelectionBtn.classList.add('hidden');
        } else {
            alert(`Deletion failed: ${data.message}`);
        }
    })
    .catch(err => alert(`Deletion Error: ${err.message}`))
    .finally(() => {
        if (lockOverlay) {
            lockOverlay.classList.remove('opacity-100', 'pointer-events-auto');
            lockOverlay.classList.add('opacity-0', 'pointer-events-none');
        }
    });
};

/**
 * Wrapper to delete a single block immediately from the hover gutter trash icon.
 */
window.deleteSingleBlockDirectly = function(blockId) {
    window.deleteSelectedBlocks([blockId]);
};

// Bind Delete actions inside DOMContentLoaded
document.addEventListener('DOMContentLoaded', () => {
    const deleteSelectionBtn = document.getElementById('editor-delete-selection-btn');
    if (deleteSelectionBtn) {
        deleteSelectionBtn.addEventListener('click', () => window.deleteSelectedBlocks());
    }
});

/**
 * Recalculates selection state, toggles edit-selection buttons, and handles warnings.
 */
const originalToggleSelection = window.toggleBlockSelection;
window.toggleBlockSelection = function(blockId) {
    originalToggleSelection(blockId);

    const editSelectionBtn = document.getElementById('editor-edit-selection-btn');
    const deleteSelectionBtn = document.getElementById('editor-delete-selection-btn');
    if (!editSelectionBtn || !deleteSelectionBtn) return;

    const count = window.activeToggledBlocks.size;

    if (count >= 1) {
        // Show Crimson Delete Button
        deleteSelectionBtn.classList.remove('hidden');
        
        if (count >= 2) {
            const isSequential = window.isSelectionSequential();
            editSelectionBtn.classList.remove('hidden');
            if (isSequential) {
                editSelectionBtn.disabled = false;
                editSelectionBtn.className = editSelectionBtn.className.replace('text-rose-400/50', 'text-blue-400').replace('border-rose-500/10', 'border-blue-500/30');
                editSelectionBtn.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-blue-400"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    Edit Selection
                `;
            } else {
                editSelectionBtn.disabled = true;
                editSelectionBtn.className = editSelectionBtn.className.replace('text-blue-400', 'text-rose-400/50').replace('border-blue-500/30', 'border-rose-500/10');
                editSelectionBtn.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-rose-500/50"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                    Non-Sequential Selection
                `;
            }
        } else {
            editSelectionBtn.classList.add('hidden');
        }

        // Hide individual hover buttons when multiple items are active
        if (count >= 2) {
            document.querySelectorAll('.block-action-bar').forEach(btn => btn.classList.add('hidden', '!opacity-0'));
        }
    } else {
        deleteSelectionBtn.classList.add('hidden');
        editSelectionBtn.classList.add('hidden');
        document.querySelectorAll('.block-action-bar').forEach(btn => btn.classList.remove('hidden', '!opacity-0'));
    }
};

/**
 * Fuses all adjacent selected blocks into a single editable textarea.
 */
window.enableFusedRangeEdit = function() {
    if (window.activeToggledBlocks.size < 2 || !window.isSelectionSequential()) return;

    const sortedIds = Array.from(window.activeToggledBlocks).sort((a, b) => {
        return parseInt(a.replace('b-', ''), 10) - parseInt(b.replace('b-', ''), 10);
    });

    const targetBlockId = sortedIds[0];
    const rangeToRemove = sortedIds.slice(1); // Rest of the blocks will be deleted on save

    const targetCard = document.getElementById(`block-card-${targetBlockId}`);
    if (!targetCard) return;

    // Combine contents of selected blocks
    const fusedText = sortedIds.map(id => {
        const block = window.activeBlocks.find(b => b.id === id);
        return block ? block.content : '';
    }).join('\n');

    // Hide all other cards in the selected range to prevent layout shift
    rangeToRemove.forEach(id => {
        const cardToHide = document.getElementById(`block-card-${id}`);
        if (cardToHide) cardToHide.classList.add('hidden');
    });

    // Replace the first card's content with a single multi-line textarea
    targetCard.innerHTML = `
        <span class="absolute top-2 right-2 text-[9px] text-slate-700 select-none font-bold">#${targetBlockId}</span>
        <textarea class="w-full bg-transparent text-slate-200 outline-none resize-none text-xs leading-relaxed font-sans border-none p-0 focus:ring-0" style="height: auto;">${fusedText}</textarea>
    `;

    const textarea = targetCard.querySelector('textarea');
    textarea.focus();
    textarea.style.height = '';
    textarea.style.height = textarea.scrollHeight + 'px';

    // Save and re-split on blur
    textarea.addEventListener('blur', () => {
        const finalValue = textarea.value.trim();

        // 1. Instantly write merged content to disk, removing target sub-range
        fetch('index.php?api_action=update_draft', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                file: window.activeEditFile,
                block_id: targetBlockId,
                content: finalValue,
                replace_range: rangeToRemove
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                window.activeBlocks = data.blocks;
                window.activeToggledBlocks.clear();
                sessionStorage.removeItem('activeToggledBlocks');

                // Re-render fresh, newly re-split block elements
                window.renderEditorBlocks();
                window.updateActiveTargetPill();

                const editSelectionBtn = document.getElementById('editor-edit-selection-btn');
                if (editSelectionBtn) editSelectionBtn.classList.add('hidden');
            }
        });
    });
};

// Bind "Edit Selection" actions inside DOMContentLoaded
document.addEventListener('DOMContentLoaded', () => {
    const editSelectionBtn = document.getElementById('editor-edit-selection-btn');
    if (editSelectionBtn) {
        editSelectionBtn.addEventListener('click', window.enableFusedRangeEdit);
    }
});