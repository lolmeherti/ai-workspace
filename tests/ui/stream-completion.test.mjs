import test from 'node:test';
import assert from 'node:assert/strict';
import { mount, fixture, json, settle } from './dom.mjs';

const dom = mount(fixture());
globalThis.FormData = window.FormData;
const { state } = await import('../../src/js/state.js');
const { streamResponse } = await import('../../src/js/streamer/streamResponse.js');
const { initChatNavigation, navigateConversation } = await import('../../src/js/chat/chatNavigation.js');
window.switchSidebarTab = () => {};
initChatNavigation();

function startStream() {
    let controller;
    const response = new Response(new ReadableStream({ start(value) { controller = value; } }));
    globalThis.fetch = (url, options) => {
        if (options?.body instanceof FormData) return Promise.resolve(response);
        if (String(url).includes('get_conversation')) return Promise.resolve(json({
            status: 'success', title: 'Other conversation', tokens: 0,
            messages_html: '<p>Other conversation</p>', context_html: ''
        }));
        return Promise.resolve(json({ status: 'success', state: 'ready', message: 'AI ready' }));
    };
    const body = new FormData(); body.set('session_id', '3'); body.set('q', 'Hello');
    return {
        result: streamResponse(body, 'Hello'),
        emit(event, data = {}) { controller.enqueue(new TextEncoder().encode('data: ' + JSON.stringify({ event, data }) + '\n\n')); },
        close() { controller.close(); }
    };
}

test('completed background streams keep output, evidence and ratings in their conversation', async () => {
    const stream = startStream();
    await settle(); await navigateConversation(2);
    stream.emit('title_updated', { title: 'Completed research' });
    stream.emit('token', { chunk: 'A completed answer.' });
    stream.emit('context_data_added', { id: 999, query: 'Background evidence' });
    stream.emit('done', { session_id: 3, message_id: 888, message: 'A completed answer.', total_session_tokens: 20 });
    stream.close();
    assert.equal(await stream.result, null);
    assert.equal(state.sessionId, 2);
    assert.equal(document.getElementById('conversation-title').textContent, 'Other conversation');
    assert.equal(document.querySelector('[data-message-id="888"]'), null);
    await navigateConversation(3);
    assert.equal(document.getElementById('conversation-title').textContent, 'Completed research');
    assert.match(document.getElementById('chatWindow').textContent, /A completed answer/);
    assert.ok(document.querySelector('.reply-rating[data-message-id="888"]'));
    assert.ok(document.querySelector('#context-data-items [data-id="999"]'));
});

test('background context warnings do not disable the selected chat or open its modal', async () => {
    const stream = startStream();
    await settle(); await navigateConversation(2);
    stream.emit('limit_warning'); stream.close();
    assert.equal((await stream.result).recoverDraft, true);
    assert.equal(document.getElementById('q').disabled, false);
    assert.equal(document.getElementById('condensation-modal').classList.contains('hidden'), true);
    assert.match(document.querySelector('[data-notice-id="context-warning-3"]').textContent, /Review conversation/);
    await navigateConversation(3);
});

test('interrupted streams preserve partial output and display the failure after the loader is gone', async () => {
    const stream = startStream();
    stream.emit('token', { chunk: 'Keep this partial answer.' });
    stream.close();
    const result = await stream.result;
    assert.equal(result.started, true);
    const bubble = document.querySelector('#chatWindow .ai-wrapper:last-child .ai-bubble');
    assert.match(bubble.textContent, /Keep this partial answer/);
    assert.match(bubble.querySelector('.ui-notice').textContent, /before completion/);
    assert.equal(state.generation, null);
    assert.equal(document.getElementById('send-btn').dataset.mode, 'send');
});

test.after(() => dom.window.close());
