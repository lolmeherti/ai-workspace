/**
 * @file js/chat/chatFileReferences.js
 * @description Manage file references attached to the chat input area.
 */

export function initFileReferences() {
    window.selectedFileReferences = window.selectedFileReferences || [];
}

export function addFileReference(file) {
    if (!window.selectedFileReferences) {
        window.selectedFileReferences = [];
    }
    if (window.selectedFileReferences.some(f => f.physical_name === file.physical_name)) return;

    window.selectedFileReferences.push(file);
    updateFileReferencesUI();
}

export function removeFileReference(physicalName) {
    window.selectedFileReferences = window.selectedFileReferences.filter(f => f.physical_name !== physicalName);
    updateFileReferencesUI();
}

export function updateFileReferencesUI() {
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
}

initFileReferences();
