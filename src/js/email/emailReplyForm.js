import { notify, requestJson } from '../workspace/feedback.js';
const drafts = new Map();
export function rememberEmailDraft() {
    const form = document.getElementById('email-reply-form');
    if (form?.dataset.replyKey) drafts.set(form.dataset.replyKey, ['reply-to-input', 'reply-subject-input', 'reply-body-input'].map(id => document.getElementById(id).value));
}
/**
 * @file js/email/emailReplyForm.js
 * @description Email reply form toggle and submission.
 */

import { triggerAiReplyAssist } from './emailAiReplyAssist.js';

export function toggleReplyForm() {
    const container = document.getElementById('reply-form-container');
    const isHidden = container.classList.contains('hidden');

    if (isHidden) {
        const key = `${window.selectedEmailAccountId}:${window.selectedEmailUid}`;
        document.getElementById('email-reply-form').dataset.replyKey = key;
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
        const draft = drafts.get(key);
        if (draft) ['reply-to-input', 'reply-subject-input', 'reply-body-input'].forEach((id, i) => { document.getElementById(id).value = draft[i]; });
        document.getElementById('reply-body-input').focus();
        document.getElementById('reply-body-input').setSelectionRange(0, 0);

        container.classList.remove('hidden');
        container.scrollIntoView({ behavior: 'smooth', block: 'end' });

        const buttonContainer = document.querySelector('#reply-form-container .flex.justify-end');
        if (buttonContainer && !document.getElementById('ai-assist-btn')) {
            const aiBtn = document.createElement('button');
            aiBtn.type = 'button';
            aiBtn.id = 'ai-assist-btn';
            aiBtn.dataset.aiAction = 'true';
            aiBtn.className = "px-4 py-2 bg-indigo-950/40 hover:bg-indigo-900/60 text-indigo-400 border border-indigo-500/30 hover:border-indigo-400/50 rounded-lg cursor-pointer transition-all outline-none shrink-0 flex items-center gap-1.5 font-bold tracking-normal normal-case text-xs";
            aiBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-indigo-400"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg> AI Assist`;
            aiBtn.onclick = triggerAiReplyAssist;
            buttonContainer.prepend(aiBtn);
        }
    } else {
        rememberEmailDraft();
        container.classList.add('hidden');
    }
}

export function submitEmailReply(event) {
    event.preventDefault();

    const submitBtn = document.getElementById('reply-submit-btn');
    if (submitBtn.disabled) return;
    const account = window.selectedEmailAccountId, uid = window.selectedEmailUid;
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.setAttribute('aria-busy', 'true');
    submitBtn.textContent = "Sending…";

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

    requestJson('index.php', {
        method: 'POST',
        body: formData
    })
    .then(data => {
        if (data.status === 'success') {
            drafts.delete(`${account}:${uid}`);
            notify('Reply sent.', { kind: 'success' });
            if (account !== window.selectedEmailAccountId || uid !== window.selectedEmailUid) return;
            document.getElementById('reply-form-container').classList.add('hidden');
            form.reset();
            statusDiv.className = 'hidden';

            window.loadEmailBody(window.selectedEmailAccountId, window.selectedEmailUid, null);
        } else {
            statusDiv.className = "p-3 mb-4 rounded-lg bg-rose-950/20 border border-rose-500/30 text-rose-400 text-xs font-semibold select-none animate-fade-in text-left";
            statusDiv.textContent = `Could not send: ${data.message}`;
        }
    })
    .catch(err => {
        statusDiv.className = "p-3 mb-4 rounded-lg bg-rose-950/20 border border-rose-500/30 text-rose-400 text-xs font-semibold select-none animate-fade-in text-left";
        statusDiv.textContent = `Delivery could not be confirmed. Check your Sent folder before sending again. ${err.message}`;
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.removeAttribute('aria-busy');
        submitBtn.textContent = originalText;
    });
}
