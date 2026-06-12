/**
 * @file js/chat/chatClipboard.js
 * @description Clipboard copy helpers for chat messages and file paths.
 */

export function copyPathToClipboard(button, path) {
    navigator.clipboard.writeText(path).then(() => {
        const originalHTML = button.innerHTML;
        button.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="text-emerald-400 w-2.5 h-2.5">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
        `;
        button.classList.add('border-emerald-500/30');
        setTimeout(() => {
            button.innerHTML = originalHTML;
            button.classList.remove('border-emerald-500/30');
        }, 1500);
    }).catch(err => {
        console.error(err);
    });
}

export function copyChatMessageToClipboard(button) {
    const container = button.closest('.chat-message-container') || button.closest('.flex-col');
    if (!container) return;

    const bubble = container.querySelector('[data-raw]');
    if (!bubble) return;

    const textToCopy = bubble.getAttribute('data-raw') || bubble.dataset.raw || '';
    if (!textToCopy) return;

    navigator.clipboard.writeText(textToCopy).then(() => {
        const icon = button.querySelector('uk-icon');
        const labelSpan = button.querySelector('span');
        const originalText = labelSpan ? labelSpan.textContent : '';

        if (icon) {
            icon.setAttribute('icon', 'check');
            button.classList.add('text-emerald-400');
            button.classList.remove('text-slate-500', 'hover:text-cyan-400');

            if (labelSpan) {
                labelSpan.textContent = 'Copied!';
            }

            setTimeout(() => {
                icon.setAttribute('icon', 'copy');
                button.classList.remove('text-emerald-400');
                button.classList.add('text-slate-500', 'hover:text-cyan-400');
                if (labelSpan) {
                    labelSpan.textContent = originalText;
                }
            }, 1500);
        }
    }).catch(err => {
        console.error(err);
    });
}
