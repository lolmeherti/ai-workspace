/**
 * @file js/chat/chatEditorBlockSelection.js
 * @description Block selection, sequential check, and target pill UI in editor drawer.
 */

function baseToggleBlockSelection(blockId) {
    const card = document.getElementById(`block-card-${blockId}`);
    if (!card) return;

    const gutter = card.querySelector('.line-num-gutter');

    if (window.activeToggledBlocks.has(blockId)) {
        window.activeToggledBlocks.delete(blockId);
        card.className = "group relative flex items-start px-4 py-0.5 transition-colors duration-75 select-none hover:bg-[#0c152d]/60";
        if (gutter) {
            gutter.classList.remove('text-cyan-400', 'font-bold');
            gutter.classList.add('text-slate-600');
        }
    } else {
        window.activeToggledBlocks.add(blockId);
        card.className = "group relative flex items-start px-4 py-0.5 select-none bg-cyan-950/25 border-y border-cyan-500/15 shadow-[inset_3px_0_0_#06b6d4,0_0_12px_rgba(6,182,212,0.15)]";
        if (gutter) {
            gutter.classList.remove('text-slate-600');
            gutter.classList.add('text-cyan-400', 'font-bold');
        }
    }

    sessionStorage.setItem('activeToggledBlocks', JSON.stringify(Array.from(window.activeToggledBlocks)));
    updateActiveTargetPill();
}

export function toggleBlockSelection(blockId) {
    baseToggleBlockSelection(blockId);

    const editSelectionBtn = document.getElementById('editor-edit-selection-btn');
    const deleteSelectionBtn = document.getElementById('editor-delete-selection-btn');
    if (!editSelectionBtn || !deleteSelectionBtn) return;

    const count = window.activeToggledBlocks.size;

    if (count >= 1) {
        deleteSelectionBtn.classList.remove('hidden');

        if (count >= 2) {
            const isSequential = isSelectionSequential();
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

        if (count >= 2) {
            document.querySelectorAll('.block-action-bar').forEach(btn => btn.classList.add('hidden', '!opacity-0'));
        }
    } else {
        deleteSelectionBtn.classList.add('hidden');
        editSelectionBtn.classList.add('hidden');
        document.querySelectorAll('.block-action-bar').forEach(btn => btn.classList.remove('hidden', '!opacity-0'));
    }
}

export function isSelectionSequential() {
    if (window.activeToggledBlocks.size <= 1) return true;

    const numbers = Array.from(window.activeToggledBlocks).map(id => parseInt(id.replace('b-', ''), 10));
    numbers.sort((a, b) => a - b);

    for (let i = 0; i < numbers.length - 1; i++) {
        const currentNum = numbers[i];
        const nextNum = numbers[i + 1];

        for (let j = currentNum + 1; j < nextNum; j++) {
            const middleBlock = window.activeBlocks.find(b => b.id === 'b-' + j);
            if (middleBlock && middleBlock.content.trim() !== '') {
                return false;
            }
        }
    }
    return true;
}

export function updateActiveTargetPill() {
    const container = document.getElementById('referenced-files-container');
    if (!container) return;

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
}

export function clearActiveBlockToggles() {
    window.activeToggledBlocks.clear();
    sessionStorage.removeItem('activeToggledBlocks');
    document.querySelectorAll('[data-block-id]').forEach(card => {
        card.className = "group relative flex items-start px-4 py-0.5 transition-colors duration-75 select-none hover:bg-[#0c152d]/60";
        const gutter = card.querySelector('.line-num-gutter');
        if (gutter) {
            gutter.classList.remove('text-cyan-400', 'font-bold');
            gutter.classList.add('text-slate-600');
        }
    });
    updateActiveTargetPill();
}
