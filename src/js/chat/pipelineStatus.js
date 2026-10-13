/**
 * @file js/chat/pipelineStatus.js
 * @description Watches the app's own background work for the open conversation.
 *
 * Why it exists: the app now does work after an answer — building a fetched source's notes and contents index — and
 * messages sent during that window are held rather than refused. The browser cannot see any of that on the stream it
 * opened (that stream belongs to a different turn), so this polls the read-only status surface and turns it into two
 * things the user needs: a sentence saying what is happening, and the knowledge that their message will be answered.
 *
 * It also tells the difference between "the assistant is busy" (informational, the composer stays usable) and any other
 * state, because `availability()` reads it. Nothing here decides anything: the server owns the truth, this reports it.
 */
import { state } from '../state.js';
import { notify, requestJson } from '../workspace/feedback.js';
import { paintAvailability } from '../workspace/availability.js';
import { navigateConversation } from './chatNavigation.js';
import { markItemAtomizing, clearItemAtomizing, refreshContextItem } from './chatContextData.js';

const POLL_MS = 2000;
const SLOW_POLL_MS = 5000;
const IDLE_POLLS_BEFORE_STOP = 3;

let timer = null;
let currentInterval = POLL_MS;
let idlePolls = 0;
let unchangedPolls = 0;
let lastFingerprint = null;
let heldWhenStarted = 0;
/** The rows this page is showing a spinner for, and the notes-version they were marked at. */
const atomizingRows = new Set();
let evidenceVersion = null;
/** The state subscription, when one is open. */
let source = null;
let streamSession = 0;

/** Is the app (or the engine) busy with work that will finish on its own? */
export function pipelineWorking() {
    return !!(state.pipeline && (state.pipeline.working || state.pipeline.engine_busy));
}

/** How many messages are waiting to be answered, according to the last poll. */
export function pipelineHeld() {
    return Number(state.pipeline?.held || 0);
}

/**
 * Start watching. Idempotent, and safe to call when nothing is happening — it stops itself after a few quiet polls.
 * `held` seeds the count so an answer arriving from a worker can be recognised as "this was the batch".
 */
export function startPipelineWatch({ held = 0 } = {}) {
    if (held > 0) heldWhenStarted = held;
    idlePolls = 0;
    unchangedPolls = 0;
    if (timer) return;
    void poll();
    timer = setInterval(() => void poll(), POLL_MS);
}

export function stopPipelineWatch() {
    if (timer) clearInterval(timer);
    timer = null;
    currentInterval = POLL_MS;
    unchangedPolls = 0;
    lastFingerprint = null;
    clearPipelineNotice();
}

/**
 * Apply one state payload and return whether the app is busy. The stream and the fallback poll both come through here, so
 * a pushed state and a polled state are painted identically.
 */
function applyState(data) {
    const wasWorking = pipelineWorking();
    state.pipeline = data;
    const busy = pipelineWorking() || data.queued_jobs > 0 || data.running_jobs > 0;

    paintAvailability();
    paintPipelineNotice(data, busy);
    paintEvidenceWork(data);

    if (wasWorking && !busy && heldWhenStarted > 0) {
        // The batch we were waiting on has been answered. If this tab streamed it, the answer is already on screen; if
        // it was answered with no client attached, say so and offer to show it rather than reloading under the user.
        heldWhenStarted = 0;
        clearWaitingMarks();
        notify('Your queued messages have been answered.', {
            id: 'pipeline-answered',
            target: document.getElementById('composer-notices'),
            kind: 'info',
            action: 'Show the reply',
            onAction: () => navigateConversation(state.sessionId, { refresh: true }),
        });
    }

    return busy;
}

/**
 * Subscribe to the state stream — the primary path.
 *
 * Every point that changes this state publishes it now (job enqueued/claimed/released/completed, the model lock taken or
 * freed, a message held or drained), so a change is on screen in about a tenth of a second instead of up to two, and the
 * page stops asking: what used to be two polls per second (one for the pill, one for the pipeline) costs the server
 * ~450 ms per request (measured). The poll below stays as the fallback for a browser without EventSource, a stream that
 * will not connect, or a server that cannot keep it open.
 *
 * @returns true when a subscription was opened
 */
export function openStateStream() {
    if (typeof EventSource === 'undefined') return false;
    const sessionId = sessionIdForStream();
    if (!sessionId) return false;
    if (source && streamSession === sessionId) return true;
    closeStateStream();

    streamSession = sessionId;
    source = new EventSource('state-stream.php?session=' + encodeURIComponent(sessionId));

    source.addEventListener('state', e => {
        stopPollingOnly();
        try {
            applyState(JSON.parse(e.data));
        } catch (err) {
            // A malformed frame is not worth breaking the page over; the next one will be well formed.
        }
    });
    source.addEventListener('engine', e => {
        let payload;
        try {
            payload = JSON.parse(e.data);
        } catch (err) {
            return;
        }
        // The engine is global: another conversation's turn is exactly why this one's pill says "it will be your turn
        // soon", so this is applied on top of whatever this conversation's last payload said.
        state.pipeline = Object.assign({}, state.pipeline || {}, { engine_busy: !!payload.busy, engine_message: payload.message || '' });
        paintAvailability();
    });
    source.addEventListener('open', stopPollingOnly);
    source.addEventListener('error', () => {
        // The browser retries on its own (the relay sends `retry: 3000`); until it is back, poll as before.
        void startPipelineWatch();
    });

    return true;
}

export function closeStateStream() {
    if (source) {
        source.close();
        source = null;
    }
    streamSession = 0;
}

/** Stop the fallback poll without touching the notices the stream is still driving. */
function stopPollingOnly() {
    if (timer) clearInterval(timer);
    timer = null;
    currentInterval = POLL_MS;
}

/**
 * The conversation this page is showing.
 *
 * `state.sessionId` is set by the app's own bootstrap, which may run after this module is evaluated, so the address bar is
 * the second source — a chat page always carries `?session_id=`. Either is enough to subscribe.
 */
function sessionIdForStream() {
    return Number(state.sessionId) || Number(new URLSearchParams(location.search).get('session_id')) || 0;
}

async function poll() {
    if (!sessionIdForStream()) { stopPipelineWatch(); return; }

    let data;
    try {
        data = await requestJson('index.php?api_action=get_session_pipeline&session_id=' + encodeURIComponent(sessionIdForStream()), { timeout: 9000 });
    } catch (e) {
        // A failed poll is not worth a notice: the next one will either work or the page is going away.
        return;
    }
    if (!data || data.status !== 'success') return;

    // The stream may not have been able to open when the page loaded (the app sets the conversation id as it boots), so
    // the first successful poll is also the moment to try again. Once it is open, the stream stops this poll.
    openStateStream();

    const busy = applyState(data);

    idlePolls = busy ? 0 : idlePolls + 1;
    if (!busy && idlePolls >= IDLE_POLLS_BEFORE_STOP) stopPipelineWatch();

    // Back off while nothing moves. Each poll costs the server real time (~450 ms, measured — it is PHP boot plus a
    // handful of reads, not the engine), and a state that has not changed for a while is unlikely to change in the next
    // second: after five identical answers the cadence relaxes from 2 s to 5 s, and any change snaps it back.
    const fingerprint = [data.working, data.queued_jobs, data.running_jobs, data.held, data.engine_busy, data.evidence_version].join('|');
    unchangedPolls = fingerprint === lastFingerprint ? unchangedPolls + 1 : 0;
    lastFingerprint = fingerprint;
    const wanted = unchangedPolls >= 5 ? SLOW_POLL_MS : POLL_MS;
    if (wanted !== currentInterval && timer) {
        currentInterval = wanted;
        clearInterval(timer);
        timer = setInterval(() => void poll(), wanted);
    }
}

/**
 * The evidence rows this conversation currently has a job for, painted as "Atomizing…" with a spinner — and the row whose
 * job just finished put back to whatever the server now says. The drawer's HTML is server-rendered, so this poll is the
 * only thing that can tell an open page that background work started or finished.
 */
function paintEvidenceWork(data) {
    const rows = Array.isArray(data.rows) ? data.rows.map(Number).filter(n => n > 0) : [];
    const version = Number(data.evidence_version || 0);
    const notesLanded = evidenceVersion !== null && version !== evidenceVersion;

    for (const id of rows) {
        atomizingRows.add(id);
        markItemAtomizing(id);
    }

    for (const id of [...atomizingRows]) {
        if (rows.includes(id)) continue;
        atomizingRows.delete(id);
        // Notes landed → re-read the row from the server (it now says "Key facts only"). Work ended without notes — a
        // skip, a failure — → just take the spinner away and leave the badge as it was.
        if (notesLanded) refreshContextItem(id).catch(() => {});
        else clearItemAtomizing(id);
    }

    evidenceVersion = version;
}

function paintPipelineNotice(data, busy) {
    // No notice for this any more. The queue state is shown where the state actually is: a one-word badge on the held
    // bubble and the spinner under the answer. A toast saying "your message is queued" is noise — the owner's rule is
    // that a state the page already shows does not get a popup. The watcher keeps polling because it drives the status
    // chip, clears the badge when the answer lands, and tells us when a reply arrived with nobody watching.
    if (!busy) clearPipelineNotice();
}

function clearPipelineNotice() {
    document.querySelectorAll('[data-notice-id="pipeline"]').forEach(node => node.remove());
}

// A page opened while the app is working should follow it by itself. The stream is the primary path; the poll is the
// fallback for a browser without EventSource or a stream that cannot be opened.
//
// Run now if the document is already parsed: this module can be imported after DOMContentLoaded (the chat window is
// bootstrapped late), and a listener registered at that point would never fire — the page would sit there with no state
// at all, which is exactly what a bare DOMContentLoaded listener did here.
if (typeof document !== 'undefined') {
    const start = () => {
        let opened = false;
        try {
            opened = openStateStream();
        } catch (err) {
            opened = false;   // a stream that cannot be opened must not stop the fallback
        }
        if (!opened) startPipelineWatch();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
}

/**
 * The held messages have been answered (or are being answered on this stream): drop the "waiting for the assistant"
 * mark, label and all, so a bubble does not keep claiming it is waiting after its answer is on screen. "not sent" marks
 * are left alone — those messages were dropped and stay dropped.
 */
export function clearWaitingMarks() {
    document.querySelectorAll('.chat-message-container.is-waiting').forEach(node => {
        node.classList.remove('is-waiting');
        const badge = node.querySelector('.msg-state');
        if (badge) badge.remove();
    });
}
