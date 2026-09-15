import { confirmAction, notify, requestJson } from '../workspace/feedback.js';
import { flushDraftChanges } from './draftSync.js';
import { state } from '../state.js';
/**
 * @file js/chat/chatEditorBlockDelete.js
 * @description Delete selected or single blocks from the document editor draft.
 */

import { renderEditorBlocks } from './chatEditorRenderBlocks.js';
import { updateActiveTargetPill } from './chatEditorBlockSelection.js';

export async function deleteSelectedBlocks(targetIds = null) {
    const idsToDelete = targetIds || Array.from(window.activeToggledBlocks);
    if (state.generation) return;
    if (!window.activeEditFile || idsToDelete.length === 0) return;

    const confirmMsg = `Are you sure you want to permanently delete these ${idsToDelete.length} line(s) from the draft?`;
    if (!await confirmAction(confirmMsg, { confirmLabel: 'Delete lines', destructive: true })) return;

    try { await flushDraftChanges(); } catch { return; }
    const lockOverlay = document.getElementById('editor-lock-overlay');
    if (lockOverlay) {
        lockOverlay.classList.remove('opacity-0', 'pointer-events-none');
        lockOverlay.classList.add('opacity-100', 'pointer-events-auto');
    }

    requestJson('index.php?api_action=delete_draft_blocks', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            file: window.activeEditFile,
            block_ids: idsToDelete
        })
    })
    .then(data => {
        if (data.status === 'success') {
            window.activeBlocks = data.blocks;
            window.activeToggledBlocks.clear();
            sessionStorage.removeItem('activeToggledBlocks');

            renderEditorBlocks();
            updateActiveTargetPill();

            const deleteSelectionBtn = document.getElementById('editor-delete-selection-btn');
            const editSelectionBtn = document.getElementById('editor-edit-selection-btn');
            if (deleteSelectionBtn) deleteSelectionBtn.classList.add('hidden');
            if (editSelectionBtn) editSelectionBtn.classList.add('hidden');
        } else {
            notify(`Deletion failed: ${data.message}`);
        }
    })
    .catch(err => notify(`Deletion Error: ${err.message}`))
    .finally(() => {
        if (lockOverlay) {
            lockOverlay.classList.remove('opacity-100', 'pointer-events-auto');
            lockOverlay.classList.add('opacity-0', 'pointer-events-none');
        }
    });
}

export function deleteSingleBlockDirectly(blockId) {
    deleteSelectedBlocks([blockId]);
}
