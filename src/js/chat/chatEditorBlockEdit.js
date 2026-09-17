import { renderEditorBlocks } from './chatEditorRenderBlocks.js';
import { updateActiveTargetPill, isSelectionSequential } from './chatEditorBlockSelection.js';
import { queueDraftChange, flushDraftChanges } from './draftSync.js';
import { state } from '../state.js';
function edit(blockId, content, replaceRange) {
    if (state.generation) return;
    const card = document.getElementById(`block-card-${blockId}`); if (!card || card.querySelector('textarea')) return;
    const textarea = document.createElement('textarea'); textarea.className = 'editor-textarea'; textarea.value = content; textarea.setAttribute('aria-label', replaceRange ? 'Edit selected lines' : `Edit line ${blockId}`);
    card.replaceChildren(textarea);
    if (replaceRange) replaceRange.forEach(id => document.getElementById(`block-card-${id}`)?.classList.add('hidden'));
    textarea.addEventListener('input', () => handleBlockInput(blockId, textarea, replaceRange));
    textarea.addEventListener('blur', async () => {
        try {
            queueDraftChange(blockId, textarea.value, replaceRange); await flushDraftChanges();
            if (!textarea.isConnected) return;
            if (replaceRange) { window.activeToggledBlocks.clear(); sessionStorage.removeItem('activeToggledBlocks'); }
            renderEditorBlocks(); updateActiveTargetPill();
        } catch { /* Keep the edit control and text available for retry. */ }
    });
    textarea.focus(); textarea.style.height = Math.max(80, textarea.scrollHeight) + 'px';
}
export function enableManualBlockEdit(blockId) {
    const block = window.activeBlocks.find(b => b.id === blockId); if (block) edit(blockId, block.content);
}
export function handleBlockInput(blockId, textarea, replaceRange) {
    textarea.style.height = 'auto'; textarea.style.height = Math.max(80, textarea.scrollHeight) + 'px';
    queueDraftChange(blockId, textarea.value, replaceRange);
}
export function enableFusedRangeEdit() {
    if (window.activeToggledBlocks.size < 2 || !isSelectionSequential()) return;
    const ids = [...window.activeToggledBlocks].sort((a,b) => Number(a.slice(2)) - Number(b.slice(2)));
    edit(ids[0], ids.map(id => window.activeBlocks.find(b => b.id === id)?.content || '').join('\n'), ids.slice(1));
}
