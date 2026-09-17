import test from 'node:test';
import assert from 'node:assert/strict';
import { mount, fixture } from './dom.mjs';
test('PHP renders the real templates with unique IDs and labelled main controls', () => {
    const html = fixture(); assert.deepEqual(html.match(/(?:Warning:|Fatal error:|Deprecated:)[^\n]+/g) || [], []);
    const dom = mount(html); const seen = new Set(), duplicates = [];
    document.querySelectorAll('[id]').forEach(el => { if (seen.has(el.id)) duplicates.push(el.id); seen.add(el.id); });
    assert.deepEqual(duplicates, []);
    for (const id of ['send-btn', 'sidebar-toggle', 'context-toggle', 'context-close']) assert.ok(document.getElementById(id));
    assert.equal(document.querySelector('script[src*="cdn.tailwindcss.com"]'), null);
    assert.ok(document.querySelector('link[href="css/utilities.css"]'));
    assert.equal(document.querySelectorAll('#job-categories').length, 1);
    assert.ok(document.querySelector('.mailbox-header .mailbox-add-button'));
    assert.ok(document.querySelector('.mailbox-controls .mailbox-read-toggle'));
    dom.window.close();
});
