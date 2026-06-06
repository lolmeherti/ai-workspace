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
});