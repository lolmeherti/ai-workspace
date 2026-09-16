import { state } from '../state.js';
import { notify, requestJson } from './feedback.js';

let current = { state: 'checking', message: 'Checking AI availability…' };
let timer;
let pending;
let started = false;

export function availability() {
    if (state.generation) {
        return { state: 'busy', message: 'Working in ' + (state.generation.title || 'another conversation'), local: true };
    }
    if (state.memoryConsolidating) return { state: 'busy', message: 'Consolidating your saved memories', local: true };
    if (state.jobRun?.unknown) return { state: 'unknown', message: 'Job search status is unavailable. Check Search activity.' };
    if (state.jobRun) return { state: 'busy', message: 'Working on your job search', local: true };
    return current;
}

export function paintAvailability() {
    const value = availability();
    for (const status of document.querySelectorAll('[data-ai-status]')) {
        status.dataset.state = value.state;
        const label = status.querySelector('[data-ai-label]');
        const text = value.state === 'busy' ? 'AI busy' : value.state === 'ready' ? 'AI ready' :
            value.state === 'offline' ? 'AI offline' : value.state === 'checking' ? 'Checking AI…' : 'AI status unavailable';
        if (label && label.textContent !== text) label.textContent = text;
        const detail = status.querySelector('[data-ai-detail]');
        if (detail && detail.textContent !== value.message) detail.textContent = value.message;
        const link = status.querySelector('[data-return-to-task]');
        if (link) link.hidden = !state.generation && !state.jobRun && !state.memoryConsolidating;
    }
    const blocked = value.state === 'busy' || value.state === 'offline';
    document.querySelectorAll('[data-ai-action]').forEach(button => {
        const isOwnStop = button.id === 'send-btn' && state.generation && state.generation.sessionId === state.sessionId;
        button.setAttribute('aria-disabled', String(blocked && !isOwnStop));
        button.dataset.aiBlocked = String(blocked && !isOwnStop);
        if (blocked && !isOwnStop) button.setAttribute('aria-description', value.message);
        else button.removeAttribute('aria-description');
    });
    const send = document.getElementById('send-btn');
    if (send) {
        const stop = !!state.generation && state.generation.sessionId === state.sessionId;
        send.disabled = !stop && !!(state.contextLocked || state.navigationPending || state.pendingUploads);
        send.dataset.mode = stop ? 'stop' : 'send';
        send.classList.toggle('stop', stop);
        send.setAttribute('aria-label', stop ? 'Stop generating' : 'Send message');
        send.title = stop ? 'Stop generating' : blocked ? value.message : 'Send message';
        const label = send.querySelector('.send-label');
        if (label) label.textContent = stop ? 'Stop' : 'Send';
    }
    document.querySelectorAll('.chat-session-item').forEach(row => {
        row.classList.toggle('is-working', !!state.generation && Number(row.dataset.sessionId) === state.generation.sessionId);
    });
}

export function ensureAIAvailable(target) {
    const value = availability();
    if (value.state !== 'busy' && value.state !== 'offline') return true;
    notify(value.message + (value.state === 'busy' ? ' Your draft is kept; nothing has been queued.' : ''), {
        target, id: 'ai-unavailable', kind: value.state === 'busy' ? 'warning' : 'error',
        action: state.generation || state.jobRun || state.memoryConsolidating ? 'View task' : undefined,
        onAction: viewCurrentTask
    });
    return false;
}

export function reportBusy(message) {
    current = { state: 'busy', message: message || 'AI is busy with another task.' };
    paintAvailability();
}

export async function refreshAvailability() {
    if (pending) return pending;
    clearTimeout(timer);
    pending = (async () => {
        try {
            const data = await requestJson('index.php?api_action=get_ai_availability', { timeout: 9000 });
            current = { state: ['ready', 'busy', 'offline'].includes(data.state) ? data.state : 'unknown',
                message: data.message || 'AI status unavailable. Your draft is kept.' };
        } catch {
            current = { state: 'unknown', message: 'AI status unavailable. Your draft is kept.' };
        } finally {
            pending = null;
            paintAvailability();
            if (!document.hidden) timer = setTimeout(refreshAvailability, availability().state === 'busy' ? 2500 : 30000);
        }
    })();
    return pending;
}

export function initAvailability() {
    if (started) return;
    started = true;
    document.addEventListener('click', e => {
        const action = e.target.closest('[data-ai-action]');
        if (action && action.dataset.mode !== 'stop' && !ensureAIAvailable(action.closest('#chat-section') ? document.getElementById('composer-notices') : action.closest('dialog') || document.getElementById('job-flash') || undefined)) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
        if (e.target.closest('[data-return-to-task]')) {
            viewCurrentTask();
        }
    }, true);
    document.addEventListener('visibilitychange', () => {
        clearTimeout(timer);
        if (!document.hidden) refreshAvailability();
    });
    window.addEventListener('focus', () => { if (!document.hidden) refreshAvailability(); });
    refreshAvailability();
}

function viewCurrentTask() {
    if (state.generation) window.navigateConversation?.(state.generation.sessionId);
    else if (state.memoryConsolidating) { window.switchSidebarTab?.('memories'); window.openMemoryConsolidation?.(); }
    else if (state.jobRun) window.switchSidebarTab?.('jobs');
}
