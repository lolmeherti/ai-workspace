/**
 * @file js/email/emailBodyLoader.js
 * @description Load and render a single email body in the reader pane.
 */

export function loadEmailBody(accountId, uid, element) {
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

                    const styledBody = buildThreadStyledBody(doc, cleanSenderName);
                    iframe.srcdoc = styledBody;
                } else {
                    iframe.style.backgroundColor = '#ffffff';
                    if (iframe.parentElement) {
                        iframe.parentElement.style.backgroundColor = '#ffffff';
                        iframe.parentElement.classList.add('bg-white');
                    }

                    iframe.srcdoc = buildPlainStyledBody(data.body);
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
}

function buildThreadStyledBody(doc, cleanSenderName) {
    return `
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
}

function buildPlainStyledBody(bodyHtml) {
    return `
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
                        ${bodyHtml}
                    </body>
                    </html>`;
}
