import test from 'node:test';
import assert from 'node:assert/strict';
import { mount, json, deferred, settle } from './dom.mjs';
const dom = mount('<button id="trigger">Delete</button><div id="reply"></div>');
const { confirmAction } = await import('../../src/js/workspace/feedback.js');
const { renderReplyRating, initReplyRating, postRating } = await import('../../src/js/chat/replyRating.js');
initReplyRating();
test('confirmation cancellation resolves and restores focus', async () => {
    const trigger = document.getElementById('trigger'); trigger.focus();
    const result = confirmAction('Remove this item?', { confirmLabel: 'Delete', destructive: true }); await settle();
    assert.equal(document.querySelector('dialog').open, true);
    document.querySelector('dialog').dispatchEvent(new Event('cancel', { cancelable: true }));
    assert.equal(await result, false); assert.equal(document.activeElement, trigger);
});
test('downvote reasons filter tool-only choices and Escape cancels without writing', async () => {
    let calls = 0; globalThis.fetch = () => { calls++; return Promise.resolve(json({})); };
    const c = renderReplyRating(document.getElementById('reply'), { message_id: 42 });
    c.querySelector('[data-rate="0"]').click();
    assert.ok(c.querySelector('[data-reason="incorrect"]'));
    assert.equal(c.querySelector('[data-reason="tool_failed"]'), null);
    c.querySelector('[data-reason]').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert.equal(c.querySelector('.reason-menu').classList.contains('hidden'), true); assert.equal(calls, 0);
});
test('duplicate feedback submits are suppressed and a lost response is reconciled', async () => {
    const c = document.querySelector('.reply-rating'), wait = deferred(); let writes = 0, reads = 0;
    globalThis.fetch = (url, init) => { if (init?.method === 'POST') { writes++; return wait.promise; } reads++; return Promise.resolve(json({ status: 'ok', rating: 0, reason: 'incorrect' })); };
    const pending = postRating(c, 0, 'incorrect'); await postRating(c, 1); assert.equal(writes, 1);
    wait.resolve(new Response('Lost response', { status: 502 })); await pending;
    assert.equal(reads, 1); assert.equal(c.dataset.rating, '0'); assert.equal(c.dataset.reason, 'incorrect');
    assert.equal(c.querySelector('[data-rate="0"]').getAttribute('aria-pressed'), 'true'); assert.equal(c.querySelector('[data-rate="0"]').disabled, false);
});
test.after(() => dom.window.close());
