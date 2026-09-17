import test from 'node:test';
import assert from 'node:assert/strict';
import { mount, fixture, json, settle, deferred } from './dom.mjs';
const dom = mount(fixture());
await import('../../src/js/gallery/galleryBootstrap.js');
document.dispatchEvent(new Event('DOMContentLoaded'));
const doc = { id: 13, original_name: 'report.pdf', physical_name: 'report', file_type: 'application/pdf', snippet: 'Document on original page two' };
test('category changes request all matches from page one and stale pages cannot replace them', async () => {
    const calls = [];
    const old = deferred();
    let firstAll = true;
    globalThis.fetch = async url => {
        const params = new URL(url, location.href).searchParams; calls.push(params);
        if (params.get('category') === 'all' && firstAll) { firstAll = false; return old.promise; }
        if (params.get('category') === 'all') return json({ status: 'success', files: [], pagination: { total: 0, page: 1, pages: 1 } });
        return json({ status: 'success', files: [doc], pagination: { total: 1, page: 1, pages: 1 } });
    };
    document.dispatchEvent(new Event('gallery-opened'));
    document.getElementById('filter-btn-docs').click(); await settle(); await settle();
    assert.equal(calls.at(-1).get('category'), 'docs');
    assert.equal(calls.at(-1).get('page'), '1');
    assert.match(document.getElementById('gallery-grid').textContent, /report.pdf/);
    assert.equal(document.getElementById('filter-btn-docs').getAttribute('aria-pressed'), 'true');
    old.resolve(json({ status: 'success', files: [], pagination: { total: 13, page: 2, pages: 2 } })); await settle();
    assert.match(document.getElementById('gallery-grid').textContent, /report.pdf/);
    assert.equal(document.getElementById('pager-next').disabled, true);
    document.getElementById('gallery-clear-filters').click(); await settle();
    assert.equal(calls.at(-1).get('category'), 'all');
    assert.equal(calls.at(-1).get('page'), '1');
});
test.after(() => dom.window.close());
