/**
 * @file js/streamer/streamFileChoices.js
 * @description Render file choice accordion when multiple files match a search.
 */

export function renderFileChoices(data, textContainer, chatWindow) {
    const files = data.files;
    if (!files || files.length === 0) return;

    const bubble = textContainer.closest('.chat-assistant');
    if (bubble) {
        const checkElement = Array.from(bubble.childNodes).find(node => node.nodeType === Node.TEXT_NODE && node.textContent.includes('Checking files'));
        if (checkElement) checkElement.remove();
    }

    const choiceContainer = document.createElement('div');
    choiceContainer.className = "flex flex-col gap-2 p-4 bg-[#0d1321]/85 border border-slate-800/80 rounded-xl mt-4 select-none relative w-full file-accordion-list";

    const header = document.createElement('div');
    header.className = "text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2 text-left flex items-center gap-1.5 border-b border-slate-850 pb-2";
    header.innerHTML = `
        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><path d="m21 16-4 4-4-4"/><path d="M17 20V4"/><path d="m3 8 4-4 4 4"/><path d="M7 4v16"/></svg>
        Multiple matching files found for '${data.query}'
    `;
    choiceContainer.appendChild(header);

    files.forEach(file => {
        const isImage = file.file_type === 'image';

        const fileItem = document.createElement('div');
        fileItem.className = "file-accordion-item border border-slate-850 rounded-lg overflow-hidden bg-slate-900/20";

        fileItem.innerHTML = `
            <div class="flex items-center justify-between p-3.5 cursor-pointer bg-slate-950/40 hover:bg-slate-950/80 transition-colors duration-150" onclick="window.toggleFileAccordion(this)">
                <div class="flex items-center gap-3 truncate flex-1 min-w-0">
                    <span class="flex items-center justify-center shrink-0 w-8 h-8 bg-slate-950 rounded border border-cyan-500/10 text-cyan-400">
                        ${isImage
                            ? '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>'
                            : '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>'}
                    </span>
                    <div class="truncate flex-1 min-w-0 text-left">
                        <div class="text-[11px] font-bold text-slate-200 truncate tracking-wide">${file.generated_title}</div>
                        <div class="text-[9px] text-slate-500 truncate">${file.original_name}</div>
                    </div>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="accordion-arrow text-slate-500 shrink-0 ml-3"><polyline points="9 18 15 12 9 6"/></svg>
            </div>

            <div class="file-accordion-content bg-[#070b13]/55">
                <div class="p-4 border-t border-slate-950 flex flex-col gap-3">
                    ${isImage
                        ? `<img src="uploads/${file.physical_name}" class="w-full h-auto max-h-72 object-contain rounded-lg border border-slate-850 bg-slate-950/40 p-1 block" alt="${file.generated_title}"/>`
                        : `<div class="bg-slate-950/95 border border-slate-850 rounded-lg p-3 text-xs font-mono text-slate-300 max-h-60 overflow-y-auto whitespace-pre-wrap text-left relative min-h-[60px]">
                             <span class="lazy-loading-indicator text-cyan-400 flex items-center gap-2 font-sans font-medium">
                                 <svg class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                 Reading full file contents...
                             </span>
                             <pre class="document-lazy-load hidden text-[11px] leading-relaxed select-text" data-file="${file.physical_name}" data-loaded="false"></pre>
                           </div>`}

                    <div class="flex items-center gap-2 select-none border-t border-slate-850/60 pt-3">
                        <button type="button" class="flex items-center justify-center gap-1.5 px-3 py-1.5 text-[10px] font-extrabold tracking-wider uppercase bg-slate-800 hover:bg-slate-750 text-slate-300 hover:text-cyan-400 border border-slate-700 hover:border-cyan-500/30 rounded-lg transition-all cursor-pointer" onclick="window.showFileInExplorer('${file.physical_name}', this)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
                            Show in Explorer
                        </button>
                        <button type="button" class="flex items-center justify-center gap-1.5 px-3 py-1.5 text-[10px] font-extrabold tracking-wider uppercase bg-cyan-950/40 hover:bg-cyan-900/60 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 rounded-lg transition-all cursor-pointer" onclick="window.appendFileFromAccordion(this, ${JSON.stringify(file).replace(/"/g, '&quot;')})">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>
                            Append to Chat
                        </button>
                        ${!isImage ? `
                        <button type="button" class="flex items-center justify-center gap-1.5 px-3 py-1.5 text-[10px] font-extrabold tracking-wider uppercase bg-blue-950/40 hover:bg-blue-900/60 text-blue-400 border border-blue-500/20 hover:border-blue-400/50 rounded-lg transition-all cursor-pointer" onclick="window.openEditorDrawer('${file.physical_name}', this)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-blue-400"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            Edit Document
                        </button>
                        ` : ''}
                    </div>
                </div>
            </div>
        `;
        choiceContainer.appendChild(fileItem);
    });

    textContainer.appendChild(choiceContainer);
    chatWindow.scrollTop = chatWindow.scrollHeight;
}

window.renderFileChoices = renderFileChoices;
