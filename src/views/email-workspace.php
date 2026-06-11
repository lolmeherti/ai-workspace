<div class="flex-1 flex h-full overflow-hidden text-xs bg-[#040810]">
    <div class="w-1/3 border-r border-slate-850 flex flex-col h-full bg-[#080d16]/80 backdrop-blur-md">
        <div class="p-6 border-b border-slate-850 bg-[#0d1321]/60 select-none shrink-0">
            <h1 class="text-sm font-bold text-slate-100 flex items-center gap-2 tracking-wide uppercase">
                <uk-icon icon="mail" class="w-4 h-4 text-cyan-400"></uk-icon>
                Com Deck
            </h1>
            <p class="text-[10px] text-slate-400 mt-1">Select an indexed mail terminal to synchronize incoming links.</p>
            
            <div class="mt-4">
                <select id="workspace-account-select" onchange="window.loadInbox(this.value, 1)" class="w-full bg-[#0b1120] border border-cyan-500/10 hover:border-cyan-500/30 rounded-lg px-3 py-2 text-slate-200 outline-none transition-all text-xs font-mono font-bold tracking-wider">
                    <option value="">[Offline Mail Terminal]</option>
                    <?php
                    $wsAccounts = $db->query("SELECT id, label, email_address FROM email_accounts ORDER BY id DESC");
                    foreach ($wsAccounts as $wsAcc):
                    ?>
                        <option value="<?php echo (int)$wsAcc['id']; ?>">
                            [ONLINE] <?php echo htmlspecialchars($wsAcc['label'] . " <" . $wsAcc['email_address'] . ">"); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div id="email-list-container" class="flex-1 overflow-y-auto p-4 space-y-3">
            <div class="text-center py-20 text-slate-500 flex flex-col items-center justify-center gap-3 select-none">
                <uk-icon icon="mail" class="w-10 h-12 text-slate-700 opacity-30"></uk-icon>
                <p class="text-[10px] tracking-widest uppercase font-bold text-slate-600">Comms Link Offline</p>
            </div>
        </div>

        <div id="email-pagination-container" class="p-4 border-t border-slate-850 flex justify-between items-center bg-[#090e18]/80 hidden select-none shrink-0">
            <button id="email-prev-btn" onclick="window.navigateEmails(-1)" class="px-3.5 py-1.5 bg-[#091124] hover:bg-[#0c162f] text-slate-300 hover:text-cyan-400 border border-slate-800 hover:border-cyan-500/30 rounded-lg text-[10px] font-extrabold uppercase tracking-widest transition-all cursor-pointer outline-none">Prev</button>
            <span id="email-page-num" class="text-slate-400 font-mono font-extrabold text-[10px] tracking-widest bg-slate-950/60 border border-slate-900 rounded px-2.5 py-1">PAGE 1 / 1</span>
            <button id="email-next-btn" onclick="window.navigateEmails(1)" class="px-3.5 py-1.5 bg-[#091124] hover:bg-[#0c162f] text-slate-300 hover:text-cyan-400 border border-slate-800 hover:border-cyan-500/30 rounded-lg text-[10px] font-extrabold uppercase tracking-widest transition-all cursor-pointer outline-none">Next</button>
        </div>
    </div>

    <div class="flex-1 flex flex-col h-full bg-[#040810] relative">
        <div id="email-reader-pane" class="flex-1 flex flex-col h-full overflow-hidden hidden">
            <div class="p-6 border-b border-slate-850 bg-[#0d1321]/40 shrink-0">
                <div class="flex justify-between items-start gap-4">
                    <div class="min-w-0 flex-1">
                        <h2 id="read-subject" class="text-base font-bold text-slate-100 tracking-wide break-words"></h2>
                        <div class="flex flex-col gap-1.5 mt-3 text-[10px] text-slate-400 font-mono">
                            <div class="flex items-center gap-1.5"><span class="text-slate-500 uppercase font-sans tracking-widest font-extrabold">FROM:</span> <span id="read-from" class="text-cyan-400 font-bold bg-cyan-950/25 border border-cyan-500/10 px-2 py-0.5 rounded animate-fade-in"></span></div>
                            <div class="flex items-center gap-1.5"><span class="text-slate-500 uppercase font-sans tracking-widest font-extrabold">DATE:</span> <span id="read-date" class="text-slate-300 font-medium animate-fade-in"></span></div>
                        </div>
                    </div>
                    <button onclick="window.toggleReplyForm()" class="px-4 py-2 bg-cyan-950/40 hover:bg-cyan-900/60 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 rounded-lg cursor-pointer transition-all outline-none shrink-0 flex items-center gap-2 font-bold tracking-wider uppercase text-[10px] shadow-lg shadow-cyan-950/10">
                        <uk-icon icon="reply" class="w-3.5 h-3.5"></uk-icon> Reply
                    </button>
                </div>
            </div>

            <div class="flex-1 p-6 overflow-y-auto flex flex-col min-h-0 space-y-4">
                <div class="flex-1 bg-white rounded-xl overflow-hidden border border-slate-850 shadow-[inset_0_0_15px_rgba(0,0,0,0.8)] relative min-h-[220px]">
                    <iframe id="email-body-iframe" class="w-full h-full border-0 absolute inset-0 bg-white animate-fade-in"></iframe>
                </div>

                <div id="reply-form-container" class="mt-4 border border-cyan-500/15 rounded-xl p-5 bg-[#091124]/90 shadow-[0_0_20px_rgba(6,182,212,0.05)] hidden shrink-0 text-left">
                    <h4 class="text-[10px] font-extrabold text-cyan-400 uppercase tracking-widest mb-4 flex items-center gap-1.5">
                        <uk-icon icon="reply" class="w-3.5 h-3.5"></uk-icon> Transmission Output
                    </h4>
                    <form id="email-reply-form" onsubmit="window.submitEmailReply(event)" class="space-y-4">
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Recipient Header</label>
                            <input type="text" id="reply-to-input" required readonly class="w-full bg-[#060b13] border border-slate-850 rounded-lg px-3 py-2 text-slate-400 outline-none font-mono tracking-wider">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Transmission Subject</label>
                            <input type="text" id="reply-subject-input" required class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 focus:outline-none focus:border-cyan-500/30 transition-colors">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Message Crypt</label>
                            <textarea id="reply-body-input" required rows="6" class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2.5 text-slate-200 focus:outline-none focus:border-cyan-500/30 transition-colors font-mono leading-relaxed text-[11px]"></textarea>
                        </div>
                        <div class="flex justify-end gap-2.5 pt-3 border-t border-slate-850">
                            <button type="button" onclick="window.toggleReplyForm()" class="px-4 py-2 bg-slate-800 hover:bg-slate-750 border border-slate-700 rounded-lg text-slate-300 font-bold transition-all cursor-pointer outline-none">Cancel</button>
                            <button type="submit" id="reply-submit-btn" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 border border-cyan-500 rounded-lg text-white font-bold transition-all cursor-pointer outline-none">Reply</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div id="email-reader-empty" class="flex-1 flex flex-col justify-center items-center text-slate-500 select-none">
            <uk-icon icon="mail" class="w-12 h-12 text-slate-800 opacity-20 mb-3"></uk-icon>
            <p class="text-[10px] font-bold tracking-widest uppercase text-slate-600">Select link-stream to render content</p>
        </div>
    </div>
</div>

<script>
window.currentEmailPage = 1;
window.totalEmailPages = 1;
window.selectedEmailAccountId = null;
window.selectedEmailUid = null;

window.loadInbox = function(accountId, page, targetUid = null) {
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
};

window.navigateEmails = function(dir) {
    const nextPage = window.currentEmailPage + dir;
    if (nextPage >= 1 && nextPage <= window.totalEmailPages) {
        window.loadInbox(window.selectedEmailAccountId, nextPage);
    }
};

window.loadEmailBody = function(accountId, uid, element) {
    document.querySelectorAll('#email-list-container > div').forEach(el => {
        el.classList.remove('border-cyan-500/40', 'bg-cyan-950/15', 'shadow-[inset_2px_0_0_#22d3ee]');
    });
    if (element) {
        element.classList.add('border-cyan-500/40', 'bg-cyan-950/15', 'shadow-[inset_2px_0_0_#22d3ee]');
    }

    window.selectedEmailUid = uid;

    document.querySelectorAll('.sent-transmission-card').forEach(el => el.remove());

    const iframe = document.getElementById('email-body-iframe');
    
    document.getElementById('email-reader-empty').classList.add('hidden');
    document.getElementById('email-reader-pane').classList.remove('hidden');
    document.getElementById('reply-form-container').classList.add('hidden');

    fetch(`index.php?api_action=get_email_body&account_id=${accountId}&uid=${uid}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                document.getElementById('read-subject').textContent = data.subject || '(No Subject)';
                document.getElementById('read-from').textContent = data.from;
                document.getElementById('read-date').textContent = data.date;

                const unreadDot = element ? element.querySelector('span[class*="bg-cyan-400"]') : null;
                if (unreadDot) {
                    unreadDot.remove();
                }

                let cleanSenderName = 'Sender';
                const nameMatch = data.from.match(/^([^<]+)/);
                if (nameMatch) {
                    cleanSenderName = nameMatch[1].trim().replace(/['"]/g, '');
                } else {
                    cleanSenderName = data.from;
                }

                const parser = new DOMParser();
                const doc = parser.parseFromString(data.body, 'text/html');
                
                const clone = doc.cloneNode(true);
                clone.querySelectorAll('blockquote, .gmail_quote').forEach(q => q.remove());
                const rawLatestText = clone.body.innerText || '';
                
                const cleanLatestText = rawLatestText.replace(/On\s+.*wrote:\s*/gi, '')
                                                     .replace(/Original Message\s*(from\s+.*)?:\s*/gi, '')
                                                     .replace(/---\s*/g, '')
                                                     .replace(/---------- Forwarded message ----------/gi, '')
                                                     .replace(/\s+/g, '')
                                                     .trim();

                const quotes = doc.querySelectorAll('blockquote, .gmail_quote');
                const hasQuotes = quotes.length > 0;
                
                const isThread = (hasQuotes && cleanLatestText.length > 0) || (data.replies && data.replies.length > 0);

                if (isThread) {
                    iframe.style.backgroundColor = '#040810';
                    if (iframe.parentElement) {
                        iframe.parentElement.style.backgroundColor = '#040810';
                        iframe.parentElement.classList.remove('bg-white');
                    }

                    if (hasQuotes && cleanLatestText.length > 0) {
                        const details = doc.createElement('details');
                        details.className = 'history-details';
                        details.innerHTML = `
                            <summary class="history-summary">▶ VIEW HISTORICAL TRANSCRIPTS</summary>
                            <div class="history-content"></div>
                        `;
                        const contentDiv = details.querySelector('.history-content');
                        
                        quotes.forEach(q => {
                            contentDiv.appendChild(q.cloneNode(true));
                            q.remove();
                        });
                        
                        doc.body.appendChild(details);
                    }

                    const latestContainer = doc.createElement('div');
                    latestContainer.className = 'latest-message-container';
                    
                    const children = Array.from(doc.body.childNodes);
                    children.forEach(child => {
                        if (child.className !== 'history-details') {
                            latestContainer.appendChild(child);
                        }
                    });
                    
                    doc.body.prepend(latestContainer);

                    if (data.replies && data.replies.length > 0) {
                        data.replies.forEach(reply => {
                            const replyDiv = doc.createElement('div');
                            replyDiv.className = 'bubble outgoing';
                            replyDiv.innerHTML = `
                                <div class="bubble-header">YOU ◀ BROADCAST SENT ▶</div>
                                <div class="bubble-content">${reply.body}</div>
                            `;
                            doc.body.appendChild(replyDiv);
                        });
                    }

                    const styledBody = `
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset="utf-8">
                        <style>
                            html, body {
                                background-color: #040810 !important;
                                color: #cbd5e1 !important;
                                font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace !important;
                                font-size: 11px !important;
                                line-height: 1.6 !important;
                                padding: 12px !important;
                                margin: 0 !important;
                                box-sizing: border-box !important;
                                display: flex !important;
                                flex-direction: column !important;
                                gap: 16px !important;
                            }
                            *, *:before, *:after {
                                box-sizing: inherit !important;
                            }
                            
                            .latest-message-container {
                                display: block !important;
                                background-color: #0c152d !important;
                                border: 1px solid rgba(6, 182, 212, 0.25) !important;
                                border-radius: 12px !important;
                                padding: 16px !important;
                                max-width: 85% !important;
                                align-self: flex-start !important;
                                box-shadow: 0 0 15px rgba(6, 182, 212, 0.05) !important;
                            }
                            .latest-message-container::before {
                                content: "${cleanSenderName} ◀ TRANSMISSION RECEIVED ▶" !important;
                                display: block !important;
                                font-size: 8px !important;
                                font-weight: bold !important;
                                color: #22d3ee !important;
                                letter-spacing: 0.15em !important;
                                margin-bottom: 10px !important;
                                border-bottom: 1px solid rgba(34, 211, 238, 0.15) !important;
                                padding-bottom: 4px !important;
                            }
                            .latest-message-container p, .latest-message-container span, .latest-message-container div {
                                color: #cbd5e1 !important;
                            }

                            .bubble.outgoing {
                                display: block !important;
                                background-color: #050912 !important;
                                border: 1px solid rgba(99, 102, 241, 0.25) !important;
                                border-radius: 12px !important;
                                padding: 16px !important;
                                max-width: 85% !important;
                                align-self: flex-end !important;
                                box-shadow: 0 0 15px rgba(99, 102, 241, 0.05) !important;
                            }
                            .bubble.outgoing .bubble-header {
                                display: block !important;
                                font-size: 8px !important;
                                font-weight: bold !important;
                                color: #6366f1 !important;
                                letter-spacing: 0.15em !important;
                                margin-bottom: 8px !important;
                                padding-bottom: 4px !important;
                                border-bottom: 1px solid rgba(99, 102, 241, 0.15) !important;
                            }
                            .bubble.outgoing p, .bubble.outgoing span, .bubble.outgoing div {
                                color: #cbd5e1 !important;
                            }

                            .history-details {
                                display: block !important;
                                border: 1px solid rgba(148, 163, 184, 0.15) !important;
                                background-color: #070b13 !important;
                                border-radius: 8px !important;
                                max-width: 100% !important;
                                align-self: stretch !important;
                            }
                            
                            .history-summary {
                                padding: 12px 16px !important;
                                font-size: 9px !important;
                                font-weight: bold !important;
                                color: #94a3b8 !important;
                                cursor: pointer !important;
                                letter-spacing: 0.1em !important;
                                outline: none !important;
                                user-select: none !important;
                            }
                            .history-summary:hover {
                                color: #cbd5e1 !important;
                                background-color: rgba(255, 255, 255, 0.02) !important;
                            }
                            
                            .history-content {
                                padding: 16px !important;
                                border-top: 1px solid rgba(148, 163, 184, 0.1) !important;
                                max-height: 250px !important;
                                overflow-y: auto !important;
                            }
                            
                            .history-content blockquote, .history-content .gmail_quote {
                                border-left: 2px solid rgba(6, 182, 212, 0.3) !important;
                                background-color: rgba(6, 182, 212, 0.02) !important;
                                padding-left: 12px !important;
                                margin-left: 0 !important;
                                color: #94a3b8 !important;
                            }

                            img {
                                max-width: 100% !important;
                                height: auto !important;
                                border-radius: 8px !important;
                            }
                            ::-webkit-scrollbar {
                                width: 6px;
                                height: 6px;
                            }
                            ::-webkit-scrollbar-track {
                                background: #040810;
                            }
                            ::-webkit-scrollbar-thumb {
                                background: #1e293b;
                                border-radius: 3px;
                            }
                            ::-webkit-scrollbar-thumb:hover {
                                background: #334155;
                            }
                        </style>
                    </head>
                    <body>
                        ${doc.body.innerHTML}
                    </body>
                    </html>`;

                    iframe.srcdoc = styledBody;
                } else {
                    iframe.style.backgroundColor = '#ffffff';
                    if (iframe.parentElement) {
                        iframe.parentElement.style.backgroundColor = '#ffffff';
                        iframe.parentElement.classList.add('bg-white');
                    }

                    const styledBody = `
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset="utf-8">
                        <style>
                            html, body {
                                background-color: #ffffff !important;
                                padding: 12px !important;
                                margin: 0 !important;
                            }
                            ::-webkit-scrollbar {
                                width: 6px;
                                height: 6px;
                            }
                            ::-webkit-scrollbar-track {
                                background: #ffffff;
                            }
                            ::-webkit-scrollbar-thumb {
                                background: #cccccc;
                                border-radius: 3px;
                            }
                            ::-webkit-scrollbar-thumb:hover {
                                background: #aaaaaa;
                            }
                        </style>
                    </head>
                    <body>
                        ${data.body}
                    </body>
                    </html>`;

                    iframe.srcdoc = styledBody;
                }
            } else {
                iframe.srcdoc = `
                    <div style="font-family: sans-serif; font-size: 12px; color: #f43f5e; padding: 30px; text-align: center; background-color: #040810; height: 100vh;">
                        Link Failure: ${data.message}
                    </div>
                `;
            }
        })
        .catch(err => {
            iframe.srcdoc = `
                <div style="font-family: sans-serif; font-size: 12px; color: #f43f5e; padding: 30px; text-align: center; background-color: #040810; height: 100vh;">
                    Communication Error: ${err.message}
                </div>
            `;
        });
};


window.toggleReplyForm = function() {
    const container = document.getElementById('reply-form-container');
    const isHidden = container.classList.contains('hidden');

    if (isHidden) {
        const rawFrom = document.getElementById('read-from').textContent;
        let cleanEmail = rawFrom;
        
        const match = rawFrom.match(/<([^>]+)>/);
        if (match) {
            cleanEmail = match[1];
        }

        document.getElementById('reply-to-input').value = cleanEmail;
        
        const originalSubject = document.getElementById('read-subject').textContent;
        const replySubject = originalSubject.toLowerCase().startsWith('re:') ? originalSubject : 'Re: ' + originalSubject;
        document.getElementById('reply-subject-input').value = replySubject;
        
        document.getElementById('reply-body-input').value = "\n\n---\nOriginal Message from " + rawFrom + ":\n";
        document.getElementById('reply-body-input').focus();
        document.getElementById('reply-body-input').setSelectionRange(0, 0);

        container.classList.remove('hidden');
        container.scrollIntoView({ behavior: 'smooth', block: 'end' });

        const buttonContainer = document.querySelector('#reply-form-container .flex.justify-end');
        if (buttonContainer && !document.getElementById('ai-assist-btn')) {
            const aiBtn = document.createElement('button');
            aiBtn.type = 'button';
            aiBtn.id = 'ai-assist-btn';
            aiBtn.className = "px-4 py-2 bg-indigo-950/40 hover:bg-indigo-900/60 text-indigo-400 border border-indigo-500/30 hover:border-indigo-400/50 rounded-lg cursor-pointer transition-all outline-none shrink-0 flex items-center gap-1.5 font-bold tracking-wider uppercase text-[10px]";
            aiBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-indigo-400"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg> AI Assist`;
            aiBtn.onclick = window.triggerAiReplyAssist;
            buttonContainer.prepend(aiBtn);
        }
    } else {
        container.classList.add('hidden');
    }
};

window.triggerAiReplyAssist = function() {
    const aiBtn = document.getElementById('ai-assist-btn');
    if (!aiBtn) return;

    const originalText = aiBtn.innerHTML;
    aiBtn.disabled = true;
    aiBtn.textContent = "THINKING...";

    const iframe = document.getElementById('email-body-iframe');
    const originalBody = iframe.contentDocument ? iframe.contentDocument.body.innerText : '';
    const userDraft = document.getElementById('reply-body-input').value;

    const formData = new FormData();
    formData.append('action', 'ai_reply_assist');
    formData.append('original_subject', document.getElementById('read-subject').textContent);
    formData.append('original_from', document.getElementById('read-from').textContent);
    formData.append('original_body', originalBody);
    formData.append('user_draft', userDraft);

    fetch('index.php?api_action=ai_reply_assist', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        },
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            document.getElementById('reply-body-input').value = data.suggested_reply;
            const textarea = document.getElementById('reply-body-input');
            textarea.style.height = '';
            textarea.style.height = textarea.scrollHeight + 'px';
        } else {
            alert(`AI Assist failed: ${data.message}`);
        }
    })
    .catch(err => {
        alert(`AI Assist connection error: ${err.message}`);
    })
    .finally(() => {
        aiBtn.disabled = false;
        aiBtn.innerHTML = originalText;
    });
};

window.submitEmailReply = function(event) {
    event.preventDefault();

    const submitBtn = document.getElementById('reply-submit-btn');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = "BROADCASTING...";

    const statusDiv = document.getElementById('email-reply-status') || document.createElement('div');
    statusDiv.id = 'email-reply-status';
    statusDiv.className = 'hidden';
    
    const form = document.getElementById('email-reply-form');
    if (!document.getElementById('email-reply-status')) {
        form.prepend(statusDiv);
    }

    const toVal = document.getElementById('reply-to-input').value;
    const subjectVal = document.getElementById('reply-subject-input').value;
    const bodyVal = document.getElementById('reply-body-input').value;

    const formData = new FormData();
    formData.append('action', 'send_reply');
    formData.append('account_id', window.selectedEmailAccountId);
    formData.append('to', toVal);
    formData.append('subject', subjectVal);
    formData.append('body', bodyVal);
    formData.append('parent_uid', window.selectedEmailUid);

    fetch('index.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            document.getElementById('reply-form-container').classList.add('hidden');
            form.reset();
            statusDiv.className = 'hidden';

            window.loadEmailBody(window.selectedEmailAccountId, window.selectedEmailUid, null);
        } else {
            statusDiv.className = "p-3 mb-4 rounded-lg bg-rose-950/20 border border-rose-500/30 text-rose-400 text-xs font-semibold select-none animate-fade-in text-left";
            statusDiv.textContent = `Transmission Failure: ${data.message}`;
        }
    })
    .catch(err => {
        statusDiv.className = "p-3 mb-4 rounded-lg bg-rose-950/20 border border-rose-500/30 text-rose-400 text-xs font-semibold select-none animate-fade-in text-left";
        statusDiv.textContent = `Satellite Authentication Failure: ${err.message}`;
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    });
};
</script>