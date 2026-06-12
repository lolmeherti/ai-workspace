/**
 * @file js/chat/chatEditorBlockStream.js
 * @description Stream and commit block edits during AI-assisted document editing.
 */

import { clearActiveBlockToggles } from './chatEditorBlockSelection.js';

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
}

export function evaluateStreamCompletion(hasAppliedEdit, bubble, textContainer) {
    if (hasAppliedEdit || window.activeToggledBlocks.size === 0) return;

    if (bubble.querySelector('.manual-apply-trigger')) return;

    const toggledArray = Array.from(window.activeToggledBlocks);

    const applyBtn = document.createElement('button');
    applyBtn.type = 'button';
    applyBtn.className = "manual-apply-trigger flex items-center justify-center gap-1.5 px-4 py-2 mt-4 text-[10px] font-extrabold tracking-wider uppercase bg-cyan-950/40 hover:bg-cyan-900/60 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 rounded-lg transition-all cursor-pointer outline-none w-fit self-start shadow-md select-none";
    applyBtn.innerHTML = `
        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><polyline points="20 6 9 17 4 12"/></svg>
        Apply Suggestion to Selected Blocks (${toggledArray.join(', ')})
    `;

    applyBtn.onclick = function() {
        const rawText = bubble.getAttribute('data-raw') || bubble.textContent;
        const cleanedText = rawText.replace(/user has toggled[\s\S]*?prompt:/gi, '').trim();
        const suggestionLines = cleanedText.split("\n").map(l => l.trim()).filter(l => l !== '');

        let lineIdx = 0;
        toggledArray.forEach(blockId => {
            const replacementText = suggestionLines[lineIdx] || suggestionLines[suggestionLines.length - 1] || '';
            if (replacementText) {
                commitBlockEditDirectly(blockId, replacementText);
            }
            lineIdx++;
        });

        applyBtn.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-emerald-400"><polyline points="20 6 9 17 4 12"/></svg>
            Applied!
        `;
        applyBtn.className = applyBtn.className.replace('text-cyan-400', 'text-emerald-400').replace('border-cyan-500/30', 'border-emerald-500/40');

        setTimeout(() => {
            clearActiveBlockToggles();
            applyBtn.remove();
        }, 1500);
    };

    textContainer.appendChild(applyBtn);
}
