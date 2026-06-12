/**
 * @file js/chat/chatDomInit.js
 * @description DOMContentLoaded initialization for chat window message parsing and UI wiring.
 */

import { parseInlineFiles } from './chatInlineFileParser.js';
import { openEditorDrawer, closeEditorDrawer, saveEditorDraft } from './chatEditorOpenClose.js';
import { deleteSelectedBlocks } from './chatEditorBlockDelete.js';
import { enableFusedRangeEdit } from './chatEditorBlockEdit.js';

export function initChatDom() {
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
                    el.innerHTML = parseInlineFiles(marked.parse(rawMarkdown));
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
                el.innerHTML = parseInlineFiles(el.innerHTML);
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

        const savedActiveFile = sessionStorage.getItem('activeEditFile');
        if (savedActiveFile) {
            openEditorDrawer(savedActiveFile);
        }

        const closeBtn = document.getElementById('editor-close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', closeEditorDrawer);
        }

        const saveBtn = document.getElementById('editor-save-btn');
        if (saveBtn) {
            saveBtn.addEventListener('click', saveEditorDraft);
        }

        const deleteSelectionBtn = document.getElementById('editor-delete-selection-btn');
        if (deleteSelectionBtn) {
            deleteSelectionBtn.addEventListener('click', () => deleteSelectedBlocks());
        }

        const editSelectionBtn = document.getElementById('editor-edit-selection-btn');
        if (editSelectionBtn) {
            editSelectionBtn.addEventListener('click', enableFusedRangeEdit);
        }
    });
}

initChatDom();
