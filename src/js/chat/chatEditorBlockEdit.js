/**
 * @file js/chat/chatEditorBlockEdit.js
 * @description Manual and fused range block editing in the document editor drawer.
 */

import { renderEditorBlocks } from './chatEditorRenderBlocks.js';
import { updateActiveTargetPill, isSelectionSequential } from './chatEditorBlockSelection.js';

let blockSaveTimeout = null;

export function enableManualBlockEdit(blockId) {
    const card = document.getElementById(`block-card-${blockId}`);
    if (!card) return;

    const textDiv = card.querySelector('.block-text');
    if (!textDiv) return;

    const originalContent = textDiv.textContent === '\u00A0' ? '' : textDiv.textContent;

    card.innerHTML = `
        <span class="absolute top-1 right-2 text-[9px] text-slate-500 select-none font-bold font-mono z-10">#${blockId.replace('b-', '')}</span>
        <textarea class="w-full bg-slate-950/90 text-slate-200 outline-none resize-none text-[12px] font-mono leading-relaxed border border-cyan-500/30 rounded px-2 py-1 focus:ring-1 focus:ring-cyan-500/50 focus:border-cyan-500/50 animate-fade-in" oninput="window.handleBlockInput('${blockId}', this)">${originalContent}</textarea>
    `;

    const textarea = card.querySelector('textarea');
    textarea.focus();
    textarea.style.height = '';
    textarea.style.height = textarea.scrollHeight + 'px';

    textarea.addEventListener('blur', () => {
        const finalValue = textarea.value.trim();
        const isSelected = window.activeToggledBlocks.has(blockId);

        card.innerHTML = `
            <div class="select-none text-[10px] font-mono ${isSelected ? 'text-cyan-400 font-bold' : 'text-slate-600'} group-hover:text-cyan-400/70 transition-colors w-7 text-right pr-2 border-r border-slate-800/80 shrink-0 self-stretch flex items-start justify-end pt-0.5 line-num-gutter">
                ${blockId.replace('b-', '')}
            </div>
            <div class="flex-1 min-w-0 pl-3">
                <div class="block-text text-slate-300 text-[12px] leading-relaxed font-mono whitespace-pre-wrap break-all">${finalValue || '&nbsp;'}</div>
            </div>
            <div class="absolute top-0.5 right-2 flex items-center gap-1 bg-[#0b1329]/95 border border-slate-800 rounded p-0.5 opacity-0 group-hover:opacity-100 transition-opacity z-10 block-action-bar shadow-md">
                <button type="button" class="p-0.5 text-slate-400 hover:bg-cyan-950 hover:text-cyan-400 rounded transition-colors cursor-pointer outline-none block-edit-trigger" onclick="event.stopPropagation(); window.enableManualBlockEdit('${blockId}')" title="Edit Line">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3 h-3"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                </button>
                <button type="button" class="p-0.5 text-slate-400 hover:bg-rose-950 hover:text-rose-400 rounded transition-colors cursor-pointer outline-none block-delete-trigger" onclick="event.stopPropagation(); window.deleteSingleBlockDirectly('${blockId}')" title="Delete Line">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="w-3 h-3"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2 2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
            </div>
        `;

        if (isSelected) {
            card.className = "group relative flex items-start px-4 py-0.5 select-none bg-cyan-950/25 border-y border-cyan-500/15 shadow-[inset_3px_0_0_#06b6d4,0_0_12px_rgba(6,182,212,0.15)]";
        } else {
            card.className = "group relative flex items-start px-4 py-0.5 transition-colors duration-75 select-none hover:bg-[#0c152d]/60";
        }
    });
}

export function handleBlockInput(blockId, textarea) {
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
    }, 600);
}

export function enableFusedRangeEdit() {
    if (window.activeToggledBlocks.size < 2 || !isSelectionSequential()) return;

    const sortedIds = Array.from(window.activeToggledBlocks).sort((a, b) => {
        return parseInt(a.replace('b-', ''), 10) - parseInt(b.replace('b-', ''), 10);
    });

    const targetBlockId = sortedIds[0];
    const rangeToRemove = sortedIds.slice(1);

    const targetCard = document.getElementById(`block-card-${targetBlockId}`);
    if (!targetCard) return;

    const fusedText = sortedIds.map(id => {
        const block = window.activeBlocks.find(b => b.id === id);
        return block ? block.content : '';
    }).join('\n');

    rangeToRemove.forEach(id => {
        const cardToHide = document.getElementById(`block-card-${id}`);
        if (cardToHide) cardToHide.classList.add('hidden');
    });

    targetCard.innerHTML = `
        <span class="absolute top-1.5 right-1.5 text-[9px] text-slate-500 select-none font-bold font-mono">#${targetBlockId.replace('b-', '')}</span>
        <textarea class="w-full bg-slate-950/70 text-slate-200 outline-none resize-none text-[12.5px] font-mono leading-relaxed border border-cyan-500/30 rounded p-2 focus:ring-1 focus:ring-cyan-500/50 focus:border-cyan-500/50 animate-fade-in" style="height: auto;">${fusedText}</textarea>
    `;

    const textarea = targetCard.querySelector('textarea');
    textarea.focus();
    textarea.style.height = '';
    textarea.style.height = textarea.scrollHeight + 'px';

    textarea.addEventListener('blur', () => {
        const finalValue = textarea.value.trim();

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

                renderEditorBlocks();
                updateActiveTargetPill();

                const editSelectionBtn = document.getElementById('editor-edit-selection-btn');
                if (editSelectionBtn) editSelectionBtn.classList.add('hidden');
            }
        });
    });
}
