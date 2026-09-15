import { queueDraftChange, flushDraftChanges } from './draftSync.js';
/**
 * @file js/chat/chatEditorBlockStream.js
 * @description Stream and commit block edits during AI-assisted document editing.
 */

import { clearActiveBlockToggles } from './chatEditorBlockSelection.js';
import { renderEditorBlocks } from './chatEditorRenderBlocks.js';
import { notify, withPending } from '../workspace/feedback.js';
import { state } from '../state.js';

export function streamUpdateBlockContent(blockId, partialText) {
    const card = document.getElementById(`block-card-${blockId}`);
    if (!card) return;

    const blockObj = window.activeBlocks.find(b => b.id === blockId);
    if (blockObj) {
        blockObj.content = partialText;
    }

    const textDiv = card.querySelector('.block-text');
    if (textDiv) {
        textDiv.textContent = partialText || '\u00A0';
    }
}

export function commitBlockEditDirectly(blockId, finalContent) {
    const card = document.getElementById(`block-card-${blockId}`);
    if (card) {
        card.classList.remove('border-slate-700/60', 'border-l-cyan-400', 'bg-cyan-950/25');
        const textDiv = card.querySelector('.block-text');
        if (textDiv) textDiv.textContent = finalContent || '\u00A0';
    }

    queueDraftChange(blockId, finalContent);
    return flushDraftChanges().catch(() => {});
}

export function evaluateStreamCompletion(hasAppliedEdit, bubble, textContainer) {
    if (hasAppliedEdit || window.activeToggledBlocks.size === 0) return;

    if (bubble.querySelector('.manual-apply-trigger')) return;

    const filename = window.activeEditFile;
    const toggledArray = Array.from(window.activeToggledBlocks);

    const applyBtn = document.createElement('button');
    applyBtn.type = 'button';
    applyBtn.className = "manual-apply-trigger flex items-center justify-center gap-1.5 px-4 py-2 mt-4 text-xs font-extrabold tracking-normal normal-case bg-cyan-950/40 hover:bg-cyan-900/60 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 rounded-lg transition-all cursor-pointer outline-none w-fit self-start shadow-md select-none";
    applyBtn.innerHTML = `
        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><polyline points="20 6 9 17 4 12"/></svg>
        Apply Suggestion to Selected Blocks (${toggledArray.join(', ')})
    `;

    applyBtn.onclick = async function() {
        if (window.activeEditFile !== filename) {
            notify('Open the original document before applying this suggestion.');
            return;
        }
        if (state.generation || state.editorSaving) {
            notify('Wait for the current document operation to finish.');
            return;
        }
        state.editorSaving = true;
        try {
            const applied = await withPending(applyBtn, async () => {
                const rawText = bubble.getAttribute('data-raw') || bubble.textContent;
                const cleanedText = rawText.replace(/user has toggled[\s\S]*?prompt:/gi, '').trim();
                const suggestionLines = cleanedText.split('\n').map(line => line.trim()).filter(Boolean);
                if (!suggestionLines.length) throw new Error('There is no suggestion text to apply.');
                toggledArray.forEach((blockId, index) => {
                    const replacement = suggestionLines[index] || suggestionLines.at(-1);
                    queueDraftChange(blockId, replacement);
                });
                await flushDraftChanges();
                clearActiveBlockToggles();
                renderEditorBlocks();
                applyBtn.textContent = 'Applied to document draft';
                return true;
            }, { target: document.getElementById('editor-notices') });
            if (applied) applyBtn.disabled = true;
        } finally { state.editorSaving = false; }
    };

    textContainer.appendChild(applyBtn);
}
