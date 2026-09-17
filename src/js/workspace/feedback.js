export class RequestError extends Error {
    constructor(message, code = 'request_failed', status = 0) {
        super(message);
        this.code = code;
        this.status = status;
    }
}

export async function requestJson(url, options = {}) {
    const { timeout = 0, ...init } = options;
    if (timeout && !init.signal) init.signal = AbortSignal.timeout(timeout);
    let response;
    try {
        response = await fetch(url, init);
    } catch (error) {
        if (error.name === 'AbortError') throw error;
        throw new RequestError('Connection interrupted. Check the current state before trying again.', 'connection');
    }
    let data;
    try { data = await response.json(); }
    catch { throw new RequestError('The server returned an unreadable response.', 'invalid_response', response.status); }
    if (!response.ok || data.status === 'error') {
        throw new RequestError(data.message || 'The operation could not be completed.', data.code || 'request_failed', response.status);
    }
    return data;
}

export function notify(message, { target, id = 'workspace-notice', kind = 'error', action, onAction } = {}) {
    let host = target || document.getElementById('workspace-notices');
    if (!host) {
        host = document.createElement('div');
        host.id = 'workspace-notices';
        host.className = 'notice-stack';
        document.body.append(host);
    }
    let notice = host.querySelector('[data-notice-id="' + id + '"]');
    if (!notice) {
        notice = document.createElement('div');
        notice.dataset.noticeId = id;
        host.append(notice);
    }
    notice.className = 'ui-notice ui-notice--' + kind;
    notice.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    notice.replaceChildren();
    const text = document.createElement('span');
    text.textContent = message;
    notice.append(text);
    if (action && onAction) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'ui-button';
        button.textContent = action;
        button.addEventListener('click', onAction);
        notice.append(button);
    }
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'ui-icon-button notice-close';
    close.setAttribute('aria-label', 'Dismiss notice');
    close.textContent = '×';
    close.addEventListener('click', () => notice.remove());
    notice.append(close);
    return notice;
}

export function clearNotice(id, root = document) {
    root.querySelectorAll('[data-notice-id="' + id + '"]').forEach(el => el.remove());
}

let dialogQueue = Promise.resolve();
export function confirmAction(message, { title = 'Confirm action', confirmLabel = 'Continue', destructive = false } = {}) {
    if (typeof message === 'object') { ({ title = title, confirmLabel = confirmLabel, destructive = destructive } = message); message = message.message; }
    const show = () => new Promise(resolve => {
        const previousFocus = document.activeElement;
        const dialog = document.createElement('dialog');
        dialog.className = 'ui-dialog';
        const heading = document.createElement('h2');
        heading.id = 'confirmation-heading';
        heading.textContent = title;
        const text = document.createElement('p');
        text.textContent = message;
        const actions = document.createElement('div');
        actions.className = 'ui-actions';
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'ui-button';
        cancel.textContent = 'Cancel';
        const confirm = document.createElement('button');
        confirm.type = 'button';
        confirm.className = destructive ? 'ui-button ui-button--danger' : 'ui-button ui-button--primary';
        confirm.textContent = confirmLabel;
        actions.append(cancel, confirm);
        dialog.append(heading, text, actions);
        dialog.setAttribute('aria-labelledby', heading.id);
        const finish = result => {
            dialog.close();
            dialog.remove();
            if (previousFocus?.isConnected) previousFocus.focus();
            resolve(result);
        };
        cancel.addEventListener('click', () => finish(false));
        confirm.addEventListener('click', () => finish(true));
        dialog.addEventListener('cancel', e => { e.preventDefault(); finish(false); });
        document.body.append(dialog);
        dialog.showModal();
        cancel.focus();
    });
    const result = dialogQueue.then(show);
    dialogQueue = result.catch(() => false);
    return result;
}

export async function withPending(button, work, options = {}) {
    if (button?.dataset.pending === 'true') return;
    const disabled = button?.disabled;
    const form = options.lockForm ? button?.closest('form') : null;
    const wasInert = form?.inert;
    if (form) form.inert = true;
    if (button) {
        button.dataset.pending = 'true';
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
    }
    try { return await work(); }
    catch (error) {
        notify(error.message || 'The operation failed.', { target: options.target || button?.closest('[data-feedback-region]') || undefined });
        return null;
    } finally {
        if (form) form.inert = !!wasInert;
        if (button) {
            delete button.dataset.pending;
            button.disabled = !!disabled;
            button.removeAttribute('aria-busy');
        }
    }
}
