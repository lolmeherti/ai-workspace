import { state } from '../state.js';
import { notify, requestJson } from './feedback.js';

/** The model's own state. The pill's ONLY input — it is about the engine being loaded, never about the queue. */
let current = { state: 'checking', message: 'Checking AI availability…' };
/** The last refusal the server gave an action (`model_busy`): blocks the retry for a poll, never the pill. */
let refused = null;
let timer;
let pending;
let started = false;

/**
 * What an action must know before it acts — the composer's send button, the briefing, a context-data action.
 *
 * This is NOT what the pill shows. The queue is deliberately absent: a message sent while the app is working is held and
 * answered when the engine frees (the server's own rule), so nothing here refuses over it. What is left is the app's own
 * work that does block an action (a memory consolidation, a job run) and a refusal the server just gave.
 */
export function availability() {
    if (state.memoryConsolidating) return { state: 'busy', message: 'Consolidating your saved memories', local: true };
    if (state.jobRun?.unknown) return { state: 'unknown', message: 'Job search status is unavailable. Check Search activity.' };
    if (state.jobRun) return { state: 'busy', message: 'Working on your job search', local: true };
    if (refused) return refused;
    return current;
}

/**
 * The sidebar pill: the model, and nothing else. "AI Loaded" while it is loaded (including while it is answering
 * anything), "AI Offline" when it is not alive, and the check itself until an answer is in. Queue state belongs in the
 * chat window — in the spinner of the message that is waiting — never here.
 */
export function pillStatus() {
    return current;
}

export function paintAvailability() {
    const value = availability();
    const model = pillStatus();
    for (const status of document.querySelectorAll('[data-ai-status]')) {
        status.dataset.state = model.state;
        const label = status.querySelector('[data-ai-label]');
        const text = model.state === 'ready' ? 'AI Loaded' : model.state === 'offline' ? 'AI Offline' :
            model.state === 'checking' ? 'Checking AI…' : 'AI status unavailable';
        if (label && label.textContent !== text) label.textContent = text;
        const detail = status.querySelector('[data-ai-detail]');
        if (detail && detail.textContent !== model.message) detail.textContent = model.message;
        const link = status.querySelector('[data-return-to-task]');
        // A jump link is only useful when the task is elsewhere — offering it for a generation running in this very
        // conversation is a control that does nothing (measured).
        const taskElsewhere = state.generation ? !sameConversation(state.generation, state.sessionId) : false;
        if (link) link.hidden = !taskElsewhere && !state.jobRun && !state.memoryConsolidating;
    }
    const blocked = value.state === 'busy' || value.state === 'offline';
    document.querySelectorAll('[data-ai-action]').forEach(button => {
        const isOwnStop = button.id === 'send-btn' && sameConversation(state.generation, state.sessionId);
        button.setAttribute('aria-disabled', String(blocked && !isOwnStop));
        button.dataset.aiBlocked = String(blocked && !isOwnStop);
        if (blocked && !isOwnStop) button.setAttribute('aria-description', value.message);
        else button.removeAttribute('aria-description');
    });
    const send = document.getElementById('send-btn');
    if (send) {
        // Stop is for stopping a generation, Send is for sending — and the user needs both while an answer streams in
        // this conversation. The composer's own text decides which: empty means the click was aimed at the running
        // answer, text means it was aimed at the message. Guarding the comparison (ids arrive as strings and numbers
        // depending on the path) is what made the button disagree with the refusal it was showing (measured).
        const own = sameConversation(state.generation, state.sessionId);
        const stop = own && !composerHasText();
        send.disabled = !stop && !!(state.contextLocked || state.navigationPending || state.pendingUploads);
        send.dataset.mode = stop ? 'stop' : 'send';
        send.classList.toggle('stop', stop);
        send.setAttribute('aria-label', stop ? 'Stop generating' : 'Send message');
        send.title = stop ? 'Stop generating' : blocked ? value.message : 'Send message';
        const label = send.querySelector('.send-label');
        if (label) label.textContent = stop ? 'Stop' : 'Send';
    }
    document.querySelectorAll('.chat-session-item').forEach(row => {
        row.classList.toggle('is-working', sameConversation(state.generation, row.dataset.sessionId));
    });
}

/** One comparison for conversation ids: they arrive as numbers on load and as strings after a switch (measured). */
export function sameConversation(generation, sessionId) {
    return !!generation && Number(generation.sessionId) === Number(sessionId);
}

function composerHasText() {
    const input = document.getElementById('q');
    return !!input && input.value.trim() !== '';
}

export function ensureAIAvailable(target) {
    const value = availability();
    if (value.state !== 'busy' && value.state !== 'offline') return true;
    // Only offer the jump when the task is somewhere else. "View task" pointed at this same conversation for a
    // generation running here, so clicking it did nothing at all — a dead control reads as a broken app (measured).
    const elsewhere = (state.generation && !sameConversation(state.generation, state.sessionId))
        || state.jobRun || state.memoryConsolidating;
    notify(value.message + (value.state === 'busy' ? ' Your draft is kept; nothing has been queued.' : ''), {
        target, id: 'ai-unavailable', kind: value.state === 'busy' ? 'warning' : 'error',
        action: elsewhere ? 'View task' : undefined,
        onAction: viewCurrentTask
    });
    return false;
}

/** The server refused an action because the engine is busy. Blocks the retry until the next read, never the pill. */
export function reportBusy(message) {
    refused = { state: 'busy', message: message || 'AI is busy with another task.' };
    paintAvailability();
}

export async function refreshAvailability() {
    if (pending) return pending;
    clearTimeout(timer);

    // The pill follows the model, so this is the only thing that asks about it: one health read a minute (the engine probe
    // behind the endpoint is cached too), and a fast retry only while no answer is in yet. The queue is not part of this
    // question any more — the chat window's spinner reports that, where the message is.
    pending = (async () => {
        try {
            const data = await requestJson('index.php?api_action=get_ai_availability', { timeout: 9000 });
            current = {
                state: data.state === 'ready' ? 'ready' : data.state === 'offline' ? 'offline' : 'unknown',
                message: data.message || 'AI status unavailable. Your draft is kept.',
            };
        } catch {
            current = { state: 'unknown', message: 'AI status unavailable. Your draft is kept.' };
        } finally {
            refused = null;   // a fresh read supersedes the refusal that blocked the last attempt
            pending = null;
            paintAvailability();
            const settled = current.state === 'ready' || current.state === 'offline';
            if (!document.hidden) timer = setTimeout(refreshAvailability, settled ? 60000 : 5000);
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
    // Typing changes which action the send button carries (Stop while the composer is empty, Send once there is text),
    // so the button follows the composer rather than waiting for the next poll.
    document.addEventListener('input', e => {
        if (e.target && e.target.id === 'q') paintAvailability();
    });
    window.addEventListener('focus', () => { if (!document.hidden) refreshAvailability(); });
    refreshAvailability();
}

function viewCurrentTask() {
    if (state.generation) window.navigateConversation?.(state.generation.sessionId);
    else if (state.memoryConsolidating) { window.switchSidebarTab?.('memories'); window.openMemoryConsolidation?.(); }
    else if (state.jobRun) window.switchSidebarTab?.('jobs');
}
