import test from 'node:test';
import assert from 'node:assert/strict';
import { mount, fixture, json, settle } from './dom.mjs';
const dom = mount(fixture());
const context = await import('../../src/js/chat/chatContextData.js');
context.initContextDataPanel();
const source = { status: 'success', id: 303, tool_name: 'search_web', search_query: 'Evidence', token_estimate: 120, atomic_context: [], raw_evicted: false, message: '<script>untrusted()</script>', sources: { S1: { title: 'Source', url: 'https://example.com' } } };
test('context preview is not committed and unsaved edits are guarded', async () => {
    let commits = 0;
    globalThis.fetch = (url, options) => {
        if (options?.method === 'POST') {
            if (options.body.get('op') === 'commit') commits++;
            return Promise.resolve(json({ status: 'preview', claims: [{ source_id: 'S1', claim: 'A fact' }] }));
        }
        return Promise.resolve(json(source));
    };
    await context.viewContextItem(303); await context.atomizeContextItem(303);
    assert.ok(document.getElementById('context-fact-editor')); assert.equal(commits, 0);
    assert.equal(document.querySelector('#context-detail-host script'), null);
    const leaving = context.canLeaveContext(); await settle();
    document.querySelector('dialog[open] button').click();
    assert.equal(await leaving, false); assert.equal(commits, 0);
    assert.match(document.getElementById('context-fact-editor').value, /A fact/);
});
test('invalid fact lines are rejected instead of silently discarded', () => {
    assert.throws(() => context.parseAtomLines('[S1] Valid\nInvalid line'), /Keep each key fact/);
});
test.after(() => dom.window.close());
