/**
 * @file js/chat/chatEditorRenderBlocks.js
 * @description Render document block lines in the editor drawer.
 */

export function renderEditorBlocks() {
    const container = document.getElementById('editor-blocks-container');
    if (!container) return;

    container.innerHTML = '';

    window.activeBlocks.forEach(block => {
        const blockNode = document.createElement('div');
        blockNode.id = `block-card-${block.id}`;
        blockNode.setAttribute('data-block-id', block.id);

        const isSelected = window.activeToggledBlocks.has(block.id);
        const selectedClasses = isSelected
            ? "bg-cyan-950/25 border-y border-cyan-500/15 shadow-[inset_3px_0_0_#06b6d4,0_0_12px_rgba(6,182,212,0.15)]"
            : "border-transparent bg-transparent";

        blockNode.className = `group relative flex items-start px-4 py-0.5 transition-colors duration-75 select-none hover:bg-[#0c152d]/60 ${selectedClasses}`;

        const displayLineNum = block.id.replace('b-', '');

        blockNode.innerHTML = `
            <div class="select-none text-[10px] font-mono ${isSelected ? 'text-cyan-400 font-bold' : 'text-slate-600'} group-hover:text-cyan-400/70 transition-colors w-7 text-right pr-2 border-r border-slate-800/80 shrink-0 self-stretch flex items-start justify-end pt-0.5 line-num-gutter">
                ${displayLineNum}
            </div>
            <div class="flex-1 min-w-0 pl-3">
                <div class="block-text text-slate-300 text-[12px] leading-relaxed font-mono whitespace-pre-wrap break-all">${block.content || '&nbsp;'}</div>
            </div>
            <div class="absolute top-0.5 right-2 flex items-center gap-1 bg-[#0b1329]/95 border border-slate-800 rounded p-0.5 opacity-0 group-hover:opacity-100 transition-opacity z-10 block-action-bar shadow-md">
                <button type="button" class="p-0.5 text-slate-400 hover:bg-cyan-950 hover:text-cyan-400 rounded transition-colors cursor-pointer outline-none block-edit-trigger" onclick="event.stopPropagation(); window.enableManualBlockEdit('${block.id}')" title="Edit Line">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3 h-3"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                </button>
                <button type="button" class="p-0.5 text-slate-400 hover:bg-rose-950 hover:text-rose-400 rounded transition-colors cursor-pointer outline-none block-delete-trigger" onclick="event.stopPropagation(); window.deleteSingleBlockDirectly('${block.id}')" title="Delete Line">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="w-3 h-3"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2 2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
            </div>
        `;

        blockNode.addEventListener('click', (e) => {
            if (e.target.tagName === 'TEXTAREA' || e.target.closest('.block-action-bar')) return;
            window.toggleBlockSelection(block.id);
        });

        container.appendChild(blockNode);
    });
}
