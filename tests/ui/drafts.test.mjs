import test from 'node:test';
import assert from 'node:assert/strict';
import { mount, json, deferred, settle } from './dom.mjs';
const dom = mount('<div id="editor-notices"></div>');
const { queueDraftChange, flushDraftChanges } = await import('../../src/js/chat/draftSync.js');
window.activeEditFile = 'draft.md'; window.activeBlocks = [];
test('draft writes serialize and capture the originating file', async () => {
    const first = deferred(); const sent = [];
    globalThis.fetch = (url, options) => { sent.push(JSON.parse(options.body)); return sent.length === 1 ? first.promise : Promise.resolve(json({ status: 'success', blocks: [{ id: 'b-2', content: 'Second' }] })); };
    queueDraftChange('b-1', 'First'); const flush = flushDraftChanges();
    await settle(); queueDraftChange('b-2', 'Second'); const next = flushDraftChanges();
    await settle(); assert.equal(sent.length, 1);
    window.activeEditFile = 'other.md';
    first.resolve(json({ status: 'success', blocks: [{ id: 'b-1', content: 'First' }] }));
    await Promise.all([flush, next]);
    assert.deepEqual(sent.map(x => x.file), ['draft.md', 'draft.md']); assert.deepEqual(window.activeBlocks, []);
});
test('failed draft changes survive for explicit retry', async () => {
    globalThis.fetch = () => Promise.reject(new Error('Network'));
    queueDraftChange('b-3', 'Keep this text');
    await assert.rejects(flushDraftChanges());
    let body;
    globalThis.fetch = (url, options) => { body = JSON.parse(options.body); return Promise.resolve(json({ status: 'success', blocks: [] })); };
    await flushDraftChanges(); assert.equal(body.content, 'Keep this text');
});
test('an older failed write cannot overwrite a newer queued edit on retry', async () => {
    const first = deferred(); const sent = [];
    globalThis.fetch = (url, options) => {
        sent.push(JSON.parse(options.body).content);
        return sent.length === 1 ? first.promise : Promise.resolve(json({ status: 'success', blocks: [] }));
    };
    queueDraftChange('b-4', 'Old text');
    const old = flushDraftChanges().catch(() => {});
    await settle();
    queueDraftChange('b-4', 'Newest text');
    const newest = flushDraftChanges();
    first.resolve(json({ status: 'error', message: 'First write failed' }, 503));
    await Promise.all([old, newest]);
    await flushDraftChanges();
    assert.deepEqual(sent, ['Old text', 'Newest text']);
});
test.after(() => dom.window.close());
