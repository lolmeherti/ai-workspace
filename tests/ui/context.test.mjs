import test from 'node:test';
import assert from 'node:assert/strict';
import { mount, fixture, json, settle } from './dom.mjs';
const dom = mount(fixture());
const context = await import('../../src/js/chat/chatContextData.js');
context.initContextDataPanel();
const source = { status: 'success', id: 303, tool_name: 'search_web', search_query: 'Evidence', token_estimate: 120, atomic_context: [], raw_evicted: false, message: '<script>untrusted()</script>', sources: { S1: { title: 'Source', url: 'https://example.com' } } };
test('expand retains its SVG icon and updates accessible state', async () => {
    const expand = document.getElementById('context-expand');
    const icon = expand.querySelector('svg');
    expand.click();
    assert.equal(expand.querySelector('svg'), icon);
    assert.equal(expand.textContent, '');
    assert.equal(expand.getAttribute('aria-pressed'), 'true');
    assert.equal(expand.getAttribute('aria-label'), 'Compact reading area');
    expand.click();
    assert.equal(expand.getAttribute('aria-pressed'), 'false');
});
test('evidence editor preserves unsaved text on failure and saves the full snapshot', async () => {
    let saved;
    let fail = true;
    globalThis.fetch = async (url, options) => {
        if (options?.method === 'POST') {
            if (fail) return json({ status: 'error', message: 'Save failed' }, 500);
            saved = options.body;
            return json({ status: 'success' });
        }
        return json(source);
    };
    await context.editEvidenceContextItem(303);
    const ta = document.querySelector('.context-evidence-editor');
    assert.equal(ta.value, source.message);
    ta.value = 'Pasted replacement'; ta.dispatchEvent(new Event('input'));
    const save = [...document.querySelectorAll('#context-detail-host button')].find(b => b.textContent === 'Save evidence');
    save.click(); await settle(); await settle();
    assert.equal(document.querySelector('.context-evidence-editor').value, 'Pasted replacement');
    assert.equal(save.disabled, false);
    fail = false; save.click(); await settle(); await settle();
    assert.equal(saved.get('op'), 'edit_raw');
    assert.equal(saved.get('base_message'), source.message);
    assert.deepEqual(JSON.parse(saved.get('evidence')), [{ id: 'manual', text: 'Pasted replacement' }]);
    assert.equal(document.querySelector('.context-evidence-editor'), null);
    assert.ok(document.querySelector('#context-detail-host .sources-panel a'));
    assert.equal(document.querySelector('#context-detail-host script'), null);
});
test('list extraction performs extraction directly without a separate View click', async () => {
    let extracted = 0;
    globalThis.fetch = async (url, options) => {
        if (options?.method === 'POST') { extracted++; return json({ status: 'empty' }); }
        return json(source);
    };
    context.resetContextDetail();
    context.addContextItem({ ...source, id: 999 });
    // Use the fixture row's ID for the returned context data.
    const row = document.querySelector('.context-item[data-id="999"]');
    row.querySelector('[data-action="atomize"]').dataset.id = 303;
    row.querySelector('[data-action="atomize"]').click(); await settle(); await settle();
    assert.equal(extracted, 1);
});
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
