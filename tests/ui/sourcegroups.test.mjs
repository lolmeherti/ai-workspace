/**
 * Sources grouped by site: the domain is the category, the pages it answered with are the entries inside it.
 *
 * Pure functions, so this runs with plain `node --test tests/ui/sourcegroups.test.mjs` — no PHP fixture, no dom.
 */
import test from 'node:test';
import assert from 'node:assert/strict';

const { siteOf, endpointOf, groupBySite } = await import('../../src/js/chat/sourceGroups.js');

test('a source names its site from the domain field', () => {
    assert.equal(siteOf({ domain: 'benchlm.ai', url: 'https://benchlm.ai/x' }), 'benchlm.ai');
});

test('and from its URL when the field is missing', () => {
    assert.equal(siteOf({ url: 'https://www.reddit.com/r/LocalLLM/comments/1whihgi/x/' }), 'reddit.com');
});

test('www never becomes its own category', () => {
    assert.equal(siteOf({ domain: 'www.huggingface.co' }), 'huggingface.co');
    assert.equal(siteOf({ url: 'https://www.huggingface.co/Qwen' }), 'huggingface.co');
});

test('a source with no site has none', () => {
    assert.equal(siteOf({ title: 'a memory' }), '');
    assert.equal(siteOf({ url: 'not a url' }), '');
});

test('an endpoint is the path, without the host', () => {
    assert.equal(endpointOf('https://huggingface.co/Qwen/Qwen3.8-Flash-Next'), '/Qwen/Qwen3.8-Flash-Next');
    assert.equal(endpointOf('https://qwen.ai/blog?id=qwen3.8-flash-next'), '/blog?id=qwen3.8-flash-next');
});

test('a bare host has no endpoint to show', () => {
    assert.equal(endpointOf('https://example.com/'), '');
    assert.equal(endpointOf('https://example.com'), '');
    assert.equal(endpointOf('not a url'), '');
});

test('three pages on one host are one category holding three pages', () => {
    const groups = groupBySite([
        { id: 'S1', domain: 'benchlm.ai', url: 'https://benchlm.ai/models/x', title: 'Benchmarks' },
        { id: 'S2', domain: 'huggingface.co', url: 'https://huggingface.co/Qwen/x', title: 'Model card' },
        { id: 'S3', domain: 'huggingface.co', url: 'https://huggingface.co/Qwen/x/tree/main', title: 'Files' },
        { id: 'S4', domain: 'qwen.ai', url: 'https://qwen.ai/blog?id=x', title: 'Qwen' },
        { id: 'S5', domain: 'www.huggingface.co', url: 'https://www.huggingface.co/Qwen/y', title: 'Older' },
    ]);

    assert.equal(groups.length, 3);
    assert.deepEqual(groups.map(g => g.site), ['benchlm.ai', 'huggingface.co', 'qwen.ai']);
    assert.deepEqual(groups[1].pages.map(p => p.id), ['S2', 'S3', 'S5']);
    assert.deepEqual(groups[2].pages.map(p => p.id), ['S4']);
});

test('sites keep the order the turn read them, not alphabetical', () => {
    const groups = groupBySite([
        { domain: 'z.example', url: 'https://z.example/a' },
        { domain: 'a.example', url: 'https://a.example/b' },
        { domain: 'z.example', url: 'https://z.example/c' },
    ]);
    assert.deepEqual(groups.map(g => g.site), ['z.example', 'a.example']);
    assert.equal(groups[0].pages.length, 2);
});

test('entries with no site stay separate, never merged into one category', () => {
    const groups = groupBySite([{ title: 'memory one' }, { title: 'memory two' }]);
    assert.equal(groups.length, 2);
    assert.deepEqual(groups.map(g => g.site), ['', '']);
    assert.equal(groups[0].pages.length, 1);
});

test('an empty list has no groups and no crash', () => {
    assert.deepEqual(groupBySite([]), []);
    assert.deepEqual(groupBySite(undefined), []);
});
