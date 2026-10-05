// Citation badges: the marker the model writes must become a source chip, never visible text.
// Pure-function checks — no fixture, no DOM, so this runs anywhere node does.
import test from 'node:test';
import assert from 'node:assert/strict';
const mod = await import('../../src/js/markdown.js');
const { substituteRefBadges, refMapFromSources, refsToLinks, refsOnBubble } = mod;

const refs = {
    1: { url: 'https://geizhals.at/x', title: 'TerraMaster F2-425 ab € 276,03 (2026) | Preisvergleich', domain: 'geizhals.at' },
    2: { url: 'https://www.idealo.at/y', title: '', domain: 'www.idealo.at' }
};

test('a token becomes a chip that opens the source in a new tab', () => {
    const html = substituteRefBadges('Prices start lower than that. #REFID_1', refs);
    assert.match(html, /class="ref-badge"/);
    assert.match(html, /href="https:\/\/geizhals\.at\/x"/);
    assert.match(html, /target="_blank"/);
    assert.match(html, /rel="noopener noreferrer"/);
    assert.doesNotMatch(html, /#REFID_/);
});

test('the label is the truncated page title', () => {
    const html = substituteRefBadges('#REFID_1', refs);
    const label = html.match(/<span>([^<]*)<\/span>/)[1];
    assert.ok(label.length <= 24, `label was ${label.length} chars`);
    assert.ok(label.endsWith('…'), 'long titles are truncated');
});

test('with no title it falls back to the host, minus www', () => {
    const html = substituteRefBadges('#REFID_2', refs);
    assert.match(html, /<span>idealo\.at<\/span>/);
});

test('a token with no source behind it is dropped, not shown', () => {
    assert.equal(substituteRefBadges('text #REFID_9', refs), 'text ');
    assert.equal(substituteRefBadges('text #REFID_1', {}), 'text ');
});

test('older citation shapes are still removed', () => {
    assert.doesNotMatch(substituteRefBadges('claim [S1].', refs), /\[S1\]/);
    assert.doesNotMatch(substituteRefBadges('S2/S3 are eBay US', refs), /S\d/);
    assert.doesNotMatch(substituteRefBadges('the S2-C1 claim', refs), /S2-C1/);
});

test('real names that look like ids survive', () => {
    assert.match(substituteRefBadges('upload it to AWS S3', refs), /AWS S3/);
    assert.match(substituteRefBadges('CS2 runs faster', refs), /CS2/);
});

test('a half-arrived token is withheld while streaming', () => {
    assert.equal(substituteRefBadges('text #REFI', refs, { holdPartial: true }), 'text ');
    assert.equal(substituteRefBadges('text #REFID_', refs, { holdPartial: true }), 'text ');
    // and a complete one is not mistaken for a partial
    assert.match(substituteRefBadges('text #REFID_1 more', refs, { holdPartial: true }), /ref-badge/);
});

test('a title cannot break out of the chip markup', () => {
    const html = substituteRefBadges('#REFID_1', { 1: { url: 'https://x/"onmouseover="alert(1)', title: '<img src=x onerror=1>', domain: 'x' } });
    assert.doesNotMatch(html, /onmouseover="alert/);
    assert.doesNotMatch(html, /<img/);
});

test('the live sources payload maps to the same numbering', () => {
    const map = refMapFromSources([{ id: 'S1', url: 'u', title: 't', domain: 'd' }, { id: 'S7', url: 'u7', title: '', domain: 'd7' }]);
    assert.deepEqual(Object.keys(map), ['1', '7']);
    assert.equal(map[7].url, 'u7');
});

test('copied text carries links, not tokens', () => {

    const copied = refsToLinks('It is 39 EUR. #REFID_1 Unsupported: #REFID_9', refs);
    assert.match(copied, /\[TerraMaster F2-425 ab € 276,03 \(2026\) \| Preisvergleich\]\(https:\/\/geizhals\.at\/x\)/);
    assert.doesNotMatch(copied, /#REFID_/);
    assert.match(copied, /It is 39 EUR\./);
    // an unknown token leaves no broken link behind
    assert.match(copied, /Unsupported: $/);
});

test('copied text falls back to the host when a page has no title', () => {

    assert.match(refsToLinks('#REFID_2', refs), /\[idealo\.at\]\(https:\/\/www\.idealo\.at\/y\)/);
});

test('copied text drops older citation shapes', () => {

    const copied = refsToLinks('claim [S1] and S2/S3 here', refs);
    assert.doesNotMatch(copied, /\[S1\]/);
    assert.doesNotMatch(copied, /S2\/S3/);
});

test('a bubble carries its own numbering for copy before a reload', () => {

    const bubble = { dataset: { refs: JSON.stringify({ 3: { url: 'u3', title: 't3' } }) } };
    assert.equal(refsOnBubble(bubble)[3].url, 'u3');
    assert.deepEqual(refsOnBubble({ dataset: {} }), {});
    assert.deepEqual(refsOnBubble({ dataset: { refs: 'not json' } }), {});
});

test('chunk-suffixed tokens become complete badges and clipboard links', () => {
    const html = substituteRefBadges('Claim #REFID_1-C1, #REFID_2-C12.', refs);
    assert.equal((html.match(/class="ref-badge"/g) || []).length, 2);
    assert.doesNotMatch(html, /#REFID_|-C\d+/);
    assert.match(html, /<\/a>, /);
    const copied = refsToLinks('Claim #REFID_1-C1, #REFID_2-C12.', refs);
    assert.doesNotMatch(copied, /#REFID_|-C\d+/);
    assert.match(copied, /\]\(https:\/\/geizhals\.at\/x\), /);
    assert.match(copied, /\]\(https:\/\/www\.idealo\.at\/y\)\.$/);
});

test('an unknown chunk-suffixed token leaves no fragment behind', () => {
    for (const render of [substituteRefBadges, refsToLinks]) {
        assert.equal(render('Claim #REFID_9-C1.', refs), 'Claim .');
        assert.equal(render('Claim #REFID_1-C1.', {}), 'Claim .');
        assert.equal(render('The device code is AB-C1.', refs), 'The device code is AB-C1.');
    }
});

test('streaming holds a trailing source number and each chunk suffix prefix', () => {
    const token = '#REFID_1-C12';
    for (let end = 1; end <= token.length; end++) {
        assert.equal(substituteRefBadges('Claim ' + token.slice(0, end), refs, { holdPartial: true }), 'Claim ');
    }
    const html = substituteRefBadges('Claim ' + token + '.', refs, { holdPartial: true });
    assert.match(html, /ref-badge/);
    assert.doesNotMatch(html, /-C\d+/);
    assert.match(substituteRefBadges(token, refs), /ref-badge/);
});
