/**
 * @file js/email/emailInboxLoader.js
 * @description Load and render the email inbox list for a selected account.
 */

export function loadInbox(accountId, page, targetUid = null) {
    if (!accountId) {
        document.getElementById('email-list-container').innerHTML = `
            <div class="text-center py-20 text-slate-500 flex flex-col items-center justify-center gap-3 select-none">
                <uk-icon icon="mail" class="w-10 h-12 text-slate-700 opacity-30"></uk-icon>
                <p class="text-[10px] tracking-widest uppercase font-bold text-slate-600">Comms Link Offline</p>
            </div>
        `;
        document.getElementById('email-pagination-container').classList.add('hidden');
        document.getElementById('email-reader-pane').classList.add('hidden');
        document.getElementById('email-reader-empty').classList.remove('hidden');
        return;
    }

    window.selectedEmailAccountId = accountId;
    window.currentEmailPage = page;

    const listContainer = document.getElementById('email-list-container');
    listContainer.innerHTML = `
        <div class="text-center py-20 text-cyan-400 flex flex-col items-center justify-center gap-3 select-none">
            <svg class="animate-spin h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            <span class="text-[10px] font-bold tracking-widest uppercase animate-pulse">Syncing incoming links...</span>
        </div>
    `;

    if (targetUid) {
        window.loadEmailBody(accountId, targetUid, null);
    }

    fetch(`index.php?api_action=get_emails&account_id=${accountId}&page=${page}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                window.totalEmailPages = data.total_pages || 1;
                document.getElementById('email-page-num').textContent = `PAGE ${page} / ${window.totalEmailPages}`;
                document.getElementById('email-pagination-container').classList.remove('hidden');

                if (!data.emails || data.emails.length === 0) {
                    listContainer.innerHTML = `
                        <div class="text-center py-20 text-slate-500 flex flex-col items-center justify-center gap-3 select-none">
                            <uk-icon icon="mail" class="w-10 h-12 text-slate-700 opacity-30"></uk-icon>
                            <p class="text-[10px] tracking-widest uppercase font-bold text-slate-600">No datablocks indexed</p>
                        </div>
                    `;
                } else {
                    listContainer.innerHTML = '';
                    data.emails.forEach(email => {
                        const item = document.createElement('div');

                        let cardClasses = '';
                        let badgeHtml = '';
                        let textClasses = '';

                        if (!email.is_seen) {
                            cardClasses = "border-cyan-500/30 bg-[#0e1a30]/80 shadow-[0_0_12px_rgba(6,182,212,0.1)] hover:border-cyan-500/50 shadow-[inset_3px_0_0_#22d3ee]";
                            badgeHtml = '<span class="px-1.5 py-0.5 text-[8px] font-extrabold tracking-widest uppercase bg-cyan-950/50 border border-cyan-500/30 text-cyan-400 rounded-md shadow-[0_0_6px_rgba(6,182,212,0.15)] flex items-center gap-1 shrink-0"><span class="w-1 h-1 bg-cyan-400 rounded-full animate-pulse shadow-[0_0_4px_#22d3ee]"></span>UNREAD</span>';
                            textClasses = "text-slate-100 font-extrabold";
                        } else {
                            cardClasses = "border-slate-850/60 bg-[#091124]/30 hover:border-slate-700/60 opacity-75 hover:opacity-100";
                            badgeHtml = '<span class="px-1.5 py-0.5 text-[8px] font-bold tracking-widest uppercase bg-slate-900/60 border border-slate-800 text-slate-500 rounded-md shrink-0">READ</span>';
                            textClasses = "text-slate-400 font-medium";
                        }

                        item.className = `p-4 rounded-xl border ${cardClasses} cursor-pointer transition-all duration-150 select-none text-left relative flex justify-between items-start gap-3`;
                        item.onclick = () => window.loadEmailBody(accountId, email.uid, item);

                        if (targetUid && String(email.uid) === String(targetUid)) {
                            item.classList.add('border-cyan-500/40', 'bg-cyan-950/15', 'shadow-[inset_2px_0_0_#22d3ee]');
                        }

                        item.innerHTML = `
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-2.5">
                                    <div class="text-[10px] tracking-wide truncate ${textClasses} flex-1">
                                        ${email.from}
                                    </div>
                                    ${badgeHtml}
                                </div>
                                <div class="text-[11px] mt-1.5 leading-relaxed truncate ${!email.is_seen ? 'text-slate-200 font-bold' : 'text-slate-400'}">${email.subject || '(No Subject)'}</div>
                                <div class="text-[9px] text-slate-500 font-mono mt-3.5 flex items-center gap-1.5 font-semibold">
                                    <uk-icon icon="clock" class="w-3.5 h-3.5 text-slate-600"></uk-icon>
                                    ${email.date}
                                </div>
                            </div>
                        `;
                        listContainer.appendChild(item);
                    });
                }
            } else {
                listContainer.innerHTML = `
                    <div class="text-center py-20 text-rose-400 flex flex-col items-center justify-center gap-2 select-none">
                        <uk-icon icon="alert-triangle" class="w-8 h-8 opacity-40"></uk-icon>
                        <p class="text-[10px] font-bold tracking-wider uppercase">Link Failure: ${data.message}</p>
                    </div>
                `;
            }
        })
        .catch(err => {
            listContainer.innerHTML = `
                <div class="text-center py-20 text-rose-400 flex flex-col items-center justify-center gap-2 select-none">
                    <uk-icon icon="alert-triangle" class="w-8 h-8 opacity-40"></uk-icon>
                    <p class="text-[10px] font-bold tracking-wider uppercase">Network Error: ${err.message}</p>
                </div>
            `;
        });
}
