/**
 * @file js/email/emailAiReplyAssist.js
 * @description AI-assisted email reply suggestion.
 */

export function triggerAiReplyAssist() {
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
}
