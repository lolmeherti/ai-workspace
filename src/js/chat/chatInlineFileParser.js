/**
 * @file js/chat/chatInlineFileParser.js
 * @description Parse inline file, email, and Todoist markers in chat message content.
 */

export function parseInlineFiles(content) {
    if (!content) return '';

    content = content.replace(/<code>\s*\[?\[?Email:\s*([0-9]+):([a-zA-Z0-9._\-]+)\]\]?\]?\s*<\/code>/gi, '[Email:$1:$2]');
    content = content.replace(/`\s*\[?\[?Email:\s*([0-9]+):([a-zA-Z0-9._\-]+)\]\]?\]?\s*`/gi, '[Email:$1:$2]');
    content = content.replace(/\(\s*\[?\[?Email:\s*([0-9]+):([a-zA-Z0-9._\-]+)\]\]?\]?\s*\)/gi, '[Email:$1:$2]');
    content = content.replace(/\[+Email:\s*([0-9]+):([a-zA-Z0-9._\-]+)\]+/gi, '[Email:$1:$2]');

    const fileRegex = /\[File:\s*([a-zA-Z0-9._\-\/]+)\]/g;
    let parsedContent = content.replace(fileRegex, (match, filename) => {
        filename = filename.replace(/^uploads\//i, '');
        const ext = filename.split('.').pop().toLowerCase();
        const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext);

        return `
        <div class="file-accordion-item my-4 max-w-xl bg-[#091124]/90 border border-cyan-500/20 hover:border-cyan-500/40 rounded-xl overflow-hidden shadow-md select-none text-left">
            <div class="flex items-center justify-between p-3 cursor-pointer bg-slate-900/40 hover:bg-slate-900/70 transition-colors duration-150" onclick="window.toggleFileAccordion(this)">
                <div class="flex items-center gap-3 truncate flex-1 min-w-0">
                    <span class="flex items-center justify-center shrink-0 w-8 h-8 bg-slate-950/80 rounded border border-cyan-500/20 text-cyan-400">
                        ${isImage
                            ? '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>'
                            : '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>'}
                    </span>
                    <div class="truncate flex-1 min-w-0">
                        <div class="text-[11px] font-bold text-slate-200 truncate tracking-wide">${filename}</div>
                        <div class="text-[9px] text-cyan-500/70 italic uppercase tracking-wider">${isImage ? 'Image Reference' : 'Document Reference'}</div>
                    </div>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="accordion-arrow text-slate-400 shrink-0 ml-3"><polyline points="9 18 15 12 9 6"/></svg>
            </div>

            <div class="file-accordion-content bg-[#070b13]/55">
                <div class="p-4 border-t border-slate-900/60 flex flex-col gap-3">
                    ${isImage
                        ? `<img src="uploads/${filename}" class="w-full h-auto max-h-72 object-contain rounded-lg border border-slate-800 bg-slate-950/40 p-1 block" alt="${filename}"/>`
                        : `<div class="bg-slate-950/90 border border-slate-800 rounded-lg p-3 text-xs font-mono text-slate-300 max-h-60 overflow-y-auto whitespace-pre-wrap text-left relative min-h-[60px]">
                             <span class="lazy-loading-indicator text-cyan-400 flex items-center gap-2 font-sans font-medium">
                                 <svg class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                 Retrieving text content from disk...
                             </span>
                             <pre class="document-lazy-load hidden text-[11px] leading-relaxed select-text" data-file="${filename}" data-loaded="false"></pre>
                           </div>`}

                    <div class="flex items-center gap-2 select-none border-t border-slate-900/40 pt-3">
                        <button type="button" class="flex items-center justify-center gap-1.5 px-3 py-1.5 text-[10px] font-extrabold tracking-wider uppercase bg-slate-800 hover:bg-slate-750 text-slate-300 hover:text-cyan-400 border border-slate-700 hover:border-cyan-500/30 rounded-lg transition-all cursor-pointer" onclick="window.showFileInExplorer('${filename}', this)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
                            Show in Explorer
                        </button>
                        <button type="button" class="flex items-center justify-center gap-1.5 px-3 py-1.5 text-[10px] font-extrabold tracking-wider uppercase bg-cyan-950/40 hover:bg-cyan-900/60 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 rounded-lg transition-all cursor-pointer" onclick="window.appendFileFromAccordion(this, {physical_name: '${filename}', file_type: '${isImage ? 'image' : 'document'}', generated_title: '${filename}', original_name: '${filename}'})">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>
                            Append to Chat
                        </button>
                        ${!isImage ? `
                        <button type="button" class="flex items-center justify-center gap-1.5 px-3 py-1.5 text-[10px] font-extrabold tracking-wider uppercase bg-blue-950/40 hover:bg-blue-900/60 text-blue-400 border border-blue-500/20 hover:border-blue-400/50 rounded-lg transition-all cursor-pointer" onclick="window.openEditorDrawer('${filename}', this)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-blue-400"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            Edit Document
                        </button>
                        ` : ''}
                    </div>
                </div>
            </div>
        </div>`;
    });

    const deleteRegex = /\[TodoistDelete:\s*([a-zA-Z0-9_\-]+)\]/g;
    parsedContent = parsedContent.replace(deleteRegex, (match, taskId) => {
        return `
        <div class="todoist-delete-card my-4 p-4 max-w-xl bg-slate-950/60 border border-rose-500/20 rounded-xl shadow-md text-left flex flex-col gap-3 select-none animate-fade-in relative overflow-hidden">
            <div class="text-[10px] text-rose-400 font-extrabold uppercase tracking-wider flex items-center gap-1.5 animate-pulse">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-rose-400"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                Confirm Task Deletion
            </div>
            <p class="text-slate-300 text-xs leading-relaxed font-sans">Are you absolutely sure you want to permanently delete this task from your Todoist schedule?</p>
            <button type="button" class="btn-delete-todoist flex items-center justify-center gap-1.5 px-4 py-2 text-[10px] font-extrabold tracking-wider uppercase bg-rose-950/40 hover:bg-rose-900/60 text-rose-400 border border-rose-500/30 hover:border-rose-400/50 rounded-lg transition-all cursor-pointer outline-none w-fit self-start shadow-md select-none" onclick="window.deleteTodoistTaskDirectly('${taskId}', this)">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-rose-400"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2 2h4a2 2 0 0 1 2 2v2"/></svg>
                Yes, delete task
            </button>
        </div>`;
    });

    const suggestRegex = /\[TodoistSuggest:\s*([^|\]]+)\s*\|\s*([^\]]+)\]/g;
    parsedContent = parsedContent.replace(suggestRegex, (match, taskContent, dueString) => {
        taskContent = taskContent.trim();
        dueString = dueString.trim();
        const escapedContent = taskContent.replace(/'/g, "\\'");
        const escapedDue = dueString.replace(/'/g, "\\'");

        return `
        <div class="todoist-suggest-card my-4 p-4 max-w-xl bg-slate-950/60 border border-indigo-500/30 rounded-xl shadow-md text-left flex flex-col gap-3 select-none animate-fade-in relative overflow-hidden">
            <div class="text-[10px] text-indigo-400 font-extrabold uppercase tracking-wider flex items-center gap-1.5">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-indigo-400"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Suggested Appointment Reminder
            </div>
            <div class="flex flex-col gap-1">
                <p class="text-slate-200 text-xs font-bold leading-relaxed font-sans">${taskContent}</p>
                <p class="text-indigo-400/80 text-[10px] font-mono">Suggested schedule: ${dueString}</p>
            </div>
            <button type="button" class="btn-create-todoist flex items-center justify-center gap-1.5 px-4 py-2 text-[10px] font-extrabold tracking-wider uppercase bg-indigo-950/40 hover:bg-indigo-900/60 text-indigo-400 border border-indigo-500/30 hover:border-indigo-400/50 rounded-lg transition-all cursor-pointer outline-none w-fit self-start shadow-md select-none" onclick="window.createTodoistTaskDirectly('${escapedContent}', '${escapedDue}', this)">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-indigo-400"><polyline points="20 6 9 17 4 12"/></svg>
                Accept & Create Task
            </button>
        </div>`;
    });

    const emailRegex = /\[Email:\s*([0-9]+):([a-zA-Z0-9._\-]+)\][.\s]*/gi;
    parsedContent = parsedContent.replace(emailRegex, (match, accountId, uid) => {
        const uniqueId = `inline-email-${accountId}-${uid}`;
        const cacheKey = `${accountId}-${uid}`;

        if (window.emailBodyCache && window.emailBodyCache[cacheKey]) {
            const data = window.emailBodyCache[cacheKey];

            let tempDiv = document.createElement("div");
            tempDiv.innerHTML = data.body;

            const styles = tempDiv.querySelectorAll("style, script");
            styles.forEach(s => s.remove());

            let text = tempDiv.textContent || tempDiv.innerText || "";
            text = text.replace(/\s+/g, ' ').trim();
            if (text.length > 160) {
                text = text.substring(0, 160) + '...';
            }

            return `
            <div id="${uniqueId}" class="inline-email-card my-4 max-w-xl bg-[#091124]/90 border border-cyan-500/20 hover:border-cyan-500/40 rounded-xl overflow-hidden shadow-md select-none text-left transition-all">
                <div class="p-4 flex flex-col gap-3">
                    <div class="flex items-center gap-3">
                        <span class="flex items-center justify-center shrink-0 w-8 h-8 bg-slate-950/80 rounded border border-cyan-500/20 text-cyan-400">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="text-[11px] font-bold text-slate-200 truncate tracking-wide">${data.subject || '(No Subject)'}</div>
                            <div class="text-[9px] text-cyan-500/70 font-mono truncate mt-0.5">${data.from}</div>
                        </div>
                    </div>

                    <div class="bg-slate-950/40 border border-slate-900 rounded-lg p-3 text-[10px] font-mono text-slate-400 leading-relaxed text-left min-h-[40px]">
                        <div class="line-clamp-2">${text || '[No preview content]'}</div>
                    </div>

                    <div class="flex items-center justify-between border-t border-slate-900/40 pt-3 select-none">
                        <span class="text-[9px] text-slate-500 font-mono">${data.date}</span>
                        <button type="button" class="flex items-center justify-center gap-1.5 px-3 py-1.5 text-[10px] font-extrabold tracking-wider uppercase bg-cyan-950/40 hover:bg-cyan-900/60 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 rounded-lg transition-all cursor-pointer outline-none shadow-md" onclick="window.openEmailDirectly(${accountId}, '${uid}')">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
                            Open in Mails
                        </button>
                    </div>
                </div>
            </div>`;
        }

        if (!window.activeEmailFetches.has(cacheKey)) {
            window.activeEmailFetches.add(cacheKey);

            fetch(`index.php?api_action=get_email_body&account_id=${accountId}&uid=${uid}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        window.emailBodyCache[cacheKey] = data;

                        const card = document.getElementById(uniqueId);
                        if (card) {
                            const loader = card.querySelector('.email-lazy-loader');
                            const content = card.querySelector('.email-lazy-content');

                            if (loader && content) {
                                loader.classList.add('hidden');
                                content.classList.remove('hidden');

                                card.querySelector('.email-subject').textContent = data.subject || '(No Subject)';
                                card.querySelector('.email-from').textContent = data.from;
                                card.querySelector('.email-date').textContent = data.date;

                                let tempDiv = document.createElement("div");
                                tempDiv.innerHTML = data.body;

                                const styles = tempDiv.querySelectorAll("style, script");
                                styles.forEach(s => s.remove());

                                let text = tempDiv.textContent || tempDiv.innerText || "";
                                text = text.replace(/\s+/g, ' ').trim();
                                if (text.length > 160) {
                                    text = text.substring(0, 160) + '...';
                                }
                                card.querySelector('.email-snippet').textContent = text || '[No preview content]';
                            }
                        }
                    }
                }).catch(err => console.error(err));
        }

        return `
        <div id="${uniqueId}" class="inline-email-card my-4 max-w-xl bg-[#091124]/90 border border-cyan-500/20 hover:border-cyan-500/40 rounded-xl overflow-hidden shadow-md select-none text-left transition-all">
            <div class="email-lazy-loader p-4 flex items-center justify-center gap-2 select-none text-cyan-400">
                <svg class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                <span class="text-[10px] font-bold tracking-widest uppercase animate-pulse">Linking email stream...</span>
            </div>

            <div class="email-lazy-content hidden p-4 flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <span class="flex items-center justify-center shrink-0 w-8 h-8 bg-slate-950/80 rounded border border-cyan-500/20 text-cyan-400">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="email-subject text-[11px] font-bold text-slate-200 truncate tracking-wide"></div>
                        <div class="email-from text-[9px] text-cyan-500/70 font-mono truncate mt-0.5"></div>
                    </div>
                </div>

                <div class="bg-slate-950/40 border border-slate-900 rounded-lg p-3 text-[10px] font-mono text-slate-400 leading-relaxed text-left min-h-[40px]">
                    <div class="email-snippet line-clamp-2"></div>
                </div>

                <div class="flex items-center justify-between border-t border-slate-900/40 pt-3 select-none">
                    <span class="email-date text-[9px] text-slate-500 font-mono"></span>
                    <button type="button" class="flex items-center justify-center gap-1.5 px-3 py-1.5 text-[10px] font-extrabold tracking-wider uppercase bg-cyan-950/40 hover:bg-cyan-900/60 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 rounded-lg transition-all cursor-pointer outline-none shadow-md" onclick="window.openEmailDirectly(${accountId}, '${uid}')">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
                        Open in Mails
                    </button>
                </div>
            </div>
        </div>`;
    });

    return parsedContent;
}
