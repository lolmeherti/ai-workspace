/**
 * The sidebar status: the model, and nothing else.
 *
 * The rule this pins (owner's design): the pill at the bottom of the sidebar is a health read — "AI Loaded" while the
 * engine is loaded, "AI Offline" when it is not. It does not report the queue, and no queue state may repaint it.
 * "It will be your turn soon" belongs in the chat window, in the spinner of the message that is waiting
 * (ChatStreamAction::QUEUED_NOTICE, rendered by the `queued`/`status` handlers in streamResponse.js).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { mount, json } from './dom.mjs';

const dom = mount(`<div class="ai-status" data-ai-status data-state="checking">
    <div class="ai-status-heading"><span class="status-dot" aria-hidden="true"></span><span data-ai-label>Checking AI…</span></div>
    <p data-ai-detail>Checking AI availability…</p>
</div>`);

// No refresh timer: this file drives the pill by hand (the pill's paint never depends on visibility).
Object.defineProperty(document, 'hidden', { value: true, configurable: true });

const { state } = await import('../../src/js/state.js');
const { availability, paintAvailability, refreshAvailability, reportBusy } = await import('../../src/js/workspace/availability.js');

const pill = () => document.querySelector('[data-ai-status]');
const label = () => document.querySelector('[data-ai-label]').textContent;
const detail = () => document.querySelector('[data-ai-detail]').textContent;

state.sessionId = 3;
state.pipeline = null;
state.generation = null;
state.memoryConsolidating = false;
state.jobRun = null;

test('a loaded model reads "AI Loaded"', async () => {
    globalThis.fetch = () => Promise.resolve(json({ status: 'success', state: 'ready', message: 'AI Loaded' }));
    await refreshAvailability();
    assert.equal(pill().dataset.state, 'ready');
    assert.equal(label(), 'AI Loaded');
});

test('a dead model reads "AI Offline"', async () => {
    globalThis.fetch = () => Promise.resolve(json({ status: 'success', state: 'offline', message: 'The AI service is offline. Check the launcher.' }));
    await refreshAvailability();
    assert.equal(pill().dataset.state, 'offline');
    assert.equal(label(), 'AI Offline');
    assert.equal(detail(), 'The AI service is offline. Check the launcher.');
});

test('the queue does not touch the pill: work in flight, a busy engine and a streaming turn all keep it loaded', async () => {
    globalThis.fetch = () => Promise.resolve(json({ status: 'success', state: 'ready', message: 'AI Loaded' }));
    await refreshAvailability();

    state.pipeline = { working: true, queued_jobs: 2, running_jobs: 1, engine_busy: true,
        engine_message: 'Indexing the source you just handed over — notes and contents index' };
    state.generation = { sessionId: 3, title: 'this conversation' };
    paintAvailability();
    assert.equal(pill().dataset.state, 'ready');
    assert.equal(label(), 'AI Loaded');

    state.generation = { sessionId: 9, title: 'Holiday planning' };
    paintAvailability();
    assert.equal(pill().dataset.state, 'ready');
    assert.equal(label(), 'AI Loaded');
    assert.equal(availability().state, 'ready', 'the queue is not a reason to block an action either');

    state.generation = null;
    state.pipeline = null;
});

test('an unanswerable read is not "offline"', async () => {
    globalThis.fetch = () => Promise.reject(new Error('Disconnected'));
    await refreshAvailability();
    assert.equal(pill().dataset.state, 'unknown');
    assert.equal(label(), 'AI status unavailable');
});

test('the app\'s own blocking work and a server refusal still block an action, and never repaint the pill', async () => {
    globalThis.fetch = () => Promise.resolve(json({ status: 'success', state: 'ready', message: 'AI Loaded' }));
    await refreshAvailability();

    state.memoryConsolidating = true;
    assert.equal(availability().state, 'busy');
    state.memoryConsolidating = false;

    reportBusy('AI is busy with another task.');
    assert.equal(availability().state, 'busy');
    assert.equal(label(), 'AI Loaded', 'a blocked action never repaints the pill');
});

test.after(() => dom.window.close());
