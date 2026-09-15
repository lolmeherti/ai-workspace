import test from 'node:test';
import assert from 'node:assert/strict';
import { mount, fixture, json } from './dom.mjs';
const dom = mount(fixture());
globalThis.FormData = window.FormData;
const { state } = await import('../../src/js/state.js');
const { streamResponse } = await import('../../src/js/streamer/streamResponse.js');
const { paintAvailability, ensureAIAvailable, refreshAvailability } = await import('../../src/js/workspace/availability.js');
state.sessionId = 3;
test('busy rejection preserves the submitted draft and cleans generation controls', async () => {
    globalThis.fetch = (url, init) => Promise.resolve(init?.method === 'POST' ? json({ status: 'error', code: 'model_busy', message: 'AI is working on a CV.' }, 409) : json({ status: 'success', state: 'busy', message: 'AI is working on a CV.' }));
    const form = new FormData(); form.set('session_id', '3'); form.set('q', 'Hello');
    const result = await streamResponse(form, 'Hello');
    assert.equal(result.recoverDraft, true); assert.equal(result.started, false);
    assert.equal(state.generation, null); assert.equal(state.isGenerating, false);
    assert.equal(document.getElementById('send-btn').dataset.mode, 'send');
    assert.equal(document.querySelector('#chatWindow .ai-wrapper'), null);
});
test('Stop belongs only to the conversation running the task', () => {
    state.generation = { sessionId: 3, title: 'Research' }; state.sessionId = 3; paintAvailability();
    assert.equal(document.getElementById('send-btn').dataset.mode, 'stop');
    state.sessionId = 1; paintAvailability();
    assert.equal(document.getElementById('send-btn').dataset.mode, 'send');
    assert.equal(ensureAIAvailable(document.getElementById('composer-notices')), false);
    assert.match(document.getElementById('composer-notices').textContent, /nothing has been queued/);
    state.generation = null;
});
test('offline and unknown availability remain distinct', async () => {
    globalThis.fetch = () => Promise.resolve(json({ status: 'success', state: 'offline', message: 'AI service offline.' }));
    await refreshAvailability(); assert.equal(ensureAIAvailable(), false);
    globalThis.fetch = () => Promise.reject(new Error('Disconnected'));
    await refreshAvailability(); assert.equal(ensureAIAvailable(), true);
    assert.equal(document.querySelector('[data-ai-status]').dataset.state, 'unknown');
});
test.after(() => dom.window.close());
