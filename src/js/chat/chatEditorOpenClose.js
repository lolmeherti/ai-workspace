/**
 * @file js/chat/chatEditorOpenClose.js
 * @description Open, close, and save the document editor drawer.
 */

import { renderEditorBlocks } from './chatEditorRenderBlocks.js';
import { updateActiveTargetPill } from './chatEditorBlockSelection.js';

export function initEditorState() {
    window.activeEditFile = window.activeEditFile ?? null;
    window.activeBlocks = window.activeBlocks ?? [];
    window.activeToggledBlocks = window.activeToggledBlocks ?? new Set();
}

export function openEditorDrawer(filename, button) {
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

                sessionStorage.setItem('activeEditFile', filename);

                document.getElementById('editor-file-title').textContent = filename;

                const drawer = document.getElementById('chat-file-editor-drawer');
                drawer.classList.remove('w-0', 'border-transparent');
                drawer.classList.add('w-[60%]', 'border-slate-800/80');

                renderEditorBlocks();

                const savedToggles = sessionStorage.getItem('activeToggledBlocks');
                if (savedToggles) {
                    const array = JSON.parse(savedToggles);
                    array.forEach(id => {
                        window.activeToggledBlocks.add(id);
                        const card = document.getElementById(`block-card-${id}`);
                        if (card) {
                            card.className = "group relative flex items-start px-4 py-0.5 select-none bg-cyan-950/25 border-y border-cyan-500/15 shadow-[inset_3px_0_0_#06b6d4,0_0_12px_rgba(6,182,212,0.15)]";
                            const gutter = card.querySelector('.line-num-gutter');
                            if (gutter) {
                                gutter.classList.remove('text-slate-600');
                                gutter.classList.add('text-cyan-400', 'font-bold');
                            }
                        }
                    });
                }

                updateActiveTargetPill();
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
}

export function closeEditorDrawer() {
    if (window.activeEditFile) {
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
    updateActiveTargetPill();
}

export function saveEditorDraft() {
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
                closeEditorDrawer();
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
}

initEditorState();
