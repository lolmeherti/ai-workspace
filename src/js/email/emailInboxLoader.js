import { esc } from '../jobs/jobUtil.js';
import { rememberEmailDraft } from './emailReplyForm.js';
let requestSequence = 0;
/**
 * @file js/email/emailInboxLoader.js
 * @description Load and render the email inbox list for a selected account.
 */

export function loadInbox(accountId, page, targetUid = null) {
    const request = ++requestSequence;
    if (window.selectedEmailAccountId !== accountId) rememberEmailDraft();
    if (!accountId) {
        document.getElementById('email-list-container').innerHTML = `
            <div class="text-center py-20 text-slate-500 flex flex-col items-center justify-center gap-3 select-none">
                <uk-icon icon="mail" class="w-10 h-12 text-slate-700 opacity-30"></uk-icon>
                <p class="text-xs tracking-normal normal-case font-bold text-slate-600">Choose an email account</p>
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
            <span class="text-xs font-bold tracking-normal normal-case animate-pulse">Loading inbox…</span>
        </div>
    `;

    if (targetUid) {
        window.loadEmailBody(accountId, targetUid, null);
    }

    fetch(`index.php?api_action=get_emails&account_id=${accountId}&page=${page}`)
        .then(res => res.json())
        .then(data => {
            if (request !== requestSequence) return;
            if (data.status === 'success') {
                window.totalEmailPages = data.total_pages || 1;
                document.getElementById('email-page-num').textContent = `PAGE ${page} / ${window.totalEmailPages}`;
                document.getElementById('email-pagination-container').classList.remove('hidden');

                if (!data.emails || data.emails.length === 0) {
                    listContainer.innerHTML = `
                        <div class="text-center py-20 text-slate-500 flex flex-col items-center justify-center gap-3 select-none">
                            <uk-icon icon="mail" class="w-10 h-12 text-slate-700 opacity-30"></uk-icon>
                            <p class="text-xs tracking-normal normal-case font-bold text-slate-600">No emails found</p>
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
                            badgeHtml = '<span class="px-1.5 py-0.5 text-xs font-extrabold tracking-normal normal-case bg-cyan-950/50 border border-cyan-500/30 text-cyan-400 rounded-md shadow-[0_0_6px_rgba(6,182,212,0.15)] flex items-center gap-1 shrink-0"><span class="w-1 h-1 bg-cyan-400 rounded-full animate-pulse shadow-[0_0_4px_#22d3ee]"></span>UNREAD</span>';
                            textClasses = "text-slate-100 font-extrabold";
                        } else {
                            cardClasses = "border-slate-850/60 bg-[#091124]/30 hover:border-slate-700/60 opacity-75 hover:opacity-100";
                            badgeHtml = '<span class="px-1.5 py-0.5 text-xs font-bold tracking-normal normal-case bg-slate-900/60 border border-slate-800 text-slate-500 rounded-md shrink-0">READ</span>';
                            textClasses = "text-slate-400 font-medium";
                        }

                        item.className = `p-4 rounded-xl border ${cardClasses} cursor-pointer transition-all duration-150 select-none text-left relative flex justify-between items-start gap-3`;
                        item.tabIndex = 0; item.setAttribute('role', 'button');
                        item.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); item.click(); } });
                        item.onclick = () => window.loadEmailBody(accountId, email.uid, item);

                        if (targetUid && String(email.uid) === String(targetUid)) {
                            item.classList.add('border-cyan-500/40', 'bg-cyan-950/15', 'shadow-[inset_2px_0_0_#22d3ee]');
                        }

                        item.innerHTML = `
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-2.5">
                                    <div class="text-xs tracking-normal truncate ${textClasses} flex-1">
                                        ${esc(email.from)}
                                    </div>
                                    ${badgeHtml}
                                </div>
                                <div class="text-xs mt-1.5 leading-relaxed truncate ${!email.is_seen ? 'text-slate-200 font-bold' : 'text-slate-400'}">${esc(email.subject || '(No Subject)')}</div>
                                <div class="text-xs text-slate-500 font-mono mt-3.5 flex items-center gap-1.5 font-semibold">
                                    <uk-icon icon="clock" class="w-3.5 h-3.5 text-slate-600"></uk-icon>
                                    ${esc(email.date)}
                                </div>
                            </div>
                        `;
                        listContainer.appendChild(item);
                    });
                }
            } else {
                let displayMessage = data.message;
                if (data.type === 'AUTH_FAILED') {
                    displayMessage = 'Authentication failed — check your email credentials or app password.';
                } else if (data.type === 'CONNECTION_TIMEOUT') {
                    displayMessage = 'Connection timed out — the server may be unreachable or slow to respond.';
                }

                listContainer.innerHTML = `
                    <div class="text-center py-20 text-rose-400 flex flex-col items-center justify-center gap-2 select-none">
                        <uk-icon icon="alert-triangle" class="w-8 h-8 opacity-40"></uk-icon>
                        <p class="text-xs font-bold tracking-normal normal-case">${displayMessage}</p>
                        ${data.message !== displayMessage ? `<p class="text-xs text-slate-500 mt-2 break-all max-w-xs mx-auto">${data.message}</p>` : ''}
                    </div>
                `;
            }
        })
        .catch(err => {
            if (request !== requestSequence) return;
            listContainer.innerHTML = `
                <div class="text-center py-20 text-rose-400 flex flex-col items-center justify-center gap-2 select-none">
                    <uk-icon icon="alert-triangle" class="w-8 h-8 opacity-40"></uk-icon>
                    <p class="text-xs font-bold tracking-normal normal-case">Network Error: ${err.message}</p>
                </div>
            `;
        });
}
