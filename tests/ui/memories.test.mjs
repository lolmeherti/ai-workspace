import test from 'node:test';
import assert from 'node:assert/strict';
import { mount, fixture, json, deferred, settle } from './dom.mjs';

const dom = mount(fixture('session_id=3&tab=memories'));
globalThis.FormData = window.FormData;
const { state } = await import('../../src/js/state.js');
const { switchSidebarTab } = await import('../../src/js/tabs.js');
const ui = await import('../../src/js/ui.js');
const memories = await import('../../src/js/tabs/tabsMemoryEdit.js');
const availability = await import('../../src/js/workspace/availability.js');
window.switchSidebarTab = switchSidebarTab;
memories.initMemoryTab();
document.dispatchEvent(new Event('DOMContentLoaded'));
state.sessionId = 3;

test('Memories stays beside the chat, has a vertical list, and opens the sidebar on narrow screens', () => {
    document.body.classList.add('sidebar-collapsed');
    globalThis.matchMedia = () => ({ matches: true });
    document.getElementById('q').value = 'Keep my current draft';
    const chat = document.getElementById('chatWindow').innerHTML;
    switchSidebarTab('memories');
    assert.equal(document.getElementById('chat-workspace').classList.contains('hidden'), false);
    assert.equal(document.body.classList.contains('sidebar-collapsed'), false);
    assert.equal(document.querySelectorAll('.memory-card').length, 24);
    assert.equal(document.getElementById('chatWindow').innerHTML, chat);
    assert.equal(document.getElementById('q').value, 'Keep my current draft');
    globalThis.matchMedia = () => ({ matches: false });
});

test('consolidation exposes a running state, can be closed/reopened, and keeps a draft edit safe', async () => {
    let posts = 0;
    const response = deferred();
    globalThis.fetch = (url, init) => init?.method === 'POST' ? (posts++, response.promise) : Promise.resolve(json({ status: 'success', state: 'ready' }));
    ui.enableMemoryEdit(1);
    const editor = document.getElementById('memory-text-1');
    editor.value = 'Unfinished memory edit';
    editor.dispatchEvent(new Event('input', { bubbles: true }));
    memories.openMemoryConsolidation();
    const blocked = memories.runMemoryConsolidation();
    await settle();
    assert.equal(posts, 0);
    assert.equal(editor.value, 'Unfinished memory edit');
    ui.disableMemoryEdit(1);
    const running = memories.runMemoryConsolidation();
    await settle();
    assert.equal(posts, 1);
    assert.equal(availability.availability().state, 'busy');
    assert.equal(document.getElementById('new-memory-text').disabled, true);
    assert.equal(document.getElementById('consolidate-btn').disabled, false);
    document.querySelector('[data-memory-consolidation-close]').click();
    memories.openMemoryConsolidation();
    await memories.runMemoryConsolidation();
    assert.equal(posts, 1);
    response.resolve(json({ status: 'success', html: fixture('session_id=3&tab=memories'), message: 'Memory list refreshed.' }));
    await running;
    await blocked;
    assert.equal(state.memoryConsolidating, false);
    assert.equal(document.getElementById('new-memory-text').disabled, false);
});

test('select all and partial selection update the bulk action count', () => {
    switchSidebarTab('memories');
    const all = document.getElementById('select-all-memories');
    all.checked = true;
    all.dispatchEvent(new Event('change', { bubbles: true }));
    assert.equal(new FormData(document.getElementById('bulk-delete-form')).getAll('selected_memories[]').length, 24);
    const first = document.querySelector('.memory-checkbox');
    first.checked = false;
    first.dispatchEvent(new Event('change', { bubbles: true }));
    assert.equal(all.indeterminate, true);
    assert.equal(document.getElementById('selected-count').textContent, '23');
});

test('ambiguous consolidation requires read-back before retry', async () => {
    let writes = 0;
    globalThis.fetch = (url, init) => init?.method === 'POST' ? (writes++, Promise.reject(new Error('Connection dropped'))) : Promise.resolve(new Response(fixture('session_id=3&tab=memories')));
    await memories.runMemoryConsolidation();
    assert.equal(writes, 1);
    assert.equal(document.getElementById('memory-consolidation-start').disabled, true);
    await memories.runMemoryConsolidation();
    assert.equal(writes, 1);
    ui.disableMemoryEdit(1);
    document.getElementById('memory-consolidation-reload').click();
    await settle();
    assert.equal(document.getElementById('memory-consolidation-start').disabled, false);
});

test.after(() => dom.window.close());
