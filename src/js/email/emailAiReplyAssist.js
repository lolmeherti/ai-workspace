import { ensureAIAvailable } from '../workspace/availability.js';
import { requestJson, notify, withPending, confirmAction } from '../workspace/feedback.js';
export async function triggerAiReplyAssist() {
    const button = document.getElementById('ai-assist-btn');
    const form = document.getElementById('email-reply-form');
    if (!button || !ensureAIAvailable(form)) return;
    const key = `${window.selectedEmailAccountId}:${window.selectedEmailUid}`;
    const textarea = document.getElementById('reply-body-input'); const draft = textarea.value;
    const data = new FormData();
    let body = '';
    try { body = document.getElementById('email-body-iframe').contentDocument?.body?.innerText || ''; } catch {}
    data.append('action', 'ai_reply_assist'); data.append('original_subject', document.getElementById('read-subject').textContent); data.append('original_from', document.getElementById('read-from').textContent); data.append('original_body', body); data.append('user_draft', draft);
    await withPending(button, async () => {
        const result = await requestJson('index.php?api_action=ai_reply_assist', { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: data });
        const apply = async () => {
            if (`${window.selectedEmailAccountId}:${window.selectedEmailUid}` !== key) { notify('Open the original email to use this suggestion.'); return; }
            if (textarea.value !== draft && !await confirmAction('Replace the edits you made while the AI was working?', { confirmLabel: 'Use suggestion' })) return;
            textarea.value = result.suggested_reply; textarea.dispatchEvent(new Event('input', { bubbles: true })); textarea.focus();
        };
        notify('Reply suggestion ready. Review it before sending.', { target: form, kind: 'info', action: 'Use suggestion', onAction: apply });
    }, { target: form });
}
