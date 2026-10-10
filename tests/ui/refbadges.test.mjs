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

test('the label names the site, then the page', () => {
    const html = substituteRefBadges('#REFID_1', refs);
    const label = html.match(/<span class="ref-badge-label">([^<]*)<\/span>/)[1];
    assert.ok(label.startsWith('geizhals.at · '), `label was ${label}`);
    assert.ok(label.length <= 40, `label was ${label.length} chars`);
    assert.ok(label.endsWith('…'), 'long titles are truncated');
    // The chip leads with its number: that is what the bare numbers later in the answer point back to.
    assert.match(html, /<a class="ref-badge" data-ref="1"[^>]*><span class="ref-badge-num">1<\/span>/);
});

test('two pages of one site are told apart', () => {
    // The complaint that produced this: two hak5.org pages truncated to the same words on identical chips.
    const sameSite = {
        1: { url: 'https://hak5.org/enterprise', title: 'WiFi Pineapple Enterprise', domain: 'hak5.org' },
        2: { url: 'https://hak5.org/', title: 'WiFi Pineapple - Hak5', domain: 'hak5.org' }
    };
    const chip = (n) => substituteRefBadges(`#REFID_${n}`, sameSite).match(/<span class="ref-badge-label">([^<]*)<\/span>/)[1];
    const [a, b] = [chip(1), chip(2)];
    assert.notEqual(a, b);
    assert.ok(a.startsWith('hak5.org · ') && b.startsWith('hak5.org · '), `${a} / ${b}`);
    assert.match(a, /Enterprise/);
    assert.match(b, /Hak5$/);
});

test('the same page registered twice is named once', () => {
    // Measured fault: one turn registered shop.hak5.org/products/wifi-pineapple as both source 2 and source 4, so
    // the reader got two identical chips for one page.
    const dup = {
        2: { url: 'https://shop.hak5.org/products/wifi-pineapple', title: 'WiFi Pineapple - Hak5', domain: 'shop.hak5.org' },
        4: { url: 'https://shop.hak5.org/products/wifi-pineapple', title: 'WiFi Pineapple - Hak5', domain: 'shop.hak5.org' }
    };
    const html = substituteRefBadges('A #REFID_2. B #REFID_4. C #REFID_4.', dup);
    assert.equal((html.match(/class="ref-badge"/g) || []).length, 1, 'the page is named once');
    assert.equal((html.match(/class="ref-badge-sup"/g) || []).length, 2, 'both later citations are numbers');
    // the number it repeats is the one the page was named under, not the second registration's
    assert.match(html, /class="ref-badge-sup" data-ref="2"[^>]*>2</);
    assert.equal(substituteRefBadges(html, dup), html, 'a re-render changes nothing');
});

test('www, a trailing slash and a fragment are one page; a different query is not', () => {
    const same = {
        1: { url: 'https://shop.hak5.org/products/wifi-pineapple/', title: 'A', domain: 'shop.hak5.org' },
        2: { url: 'https://www.shop.hak5.org/products/wifi-pineapple#specs', title: 'B', domain: 'shop.hak5.org' }
    };
    assert.equal((substituteRefBadges('X #REFID_1 Y #REFID_2', same).match(/class="ref-badge"/g) || []).length, 1);

    // two videos on one path are two pages: the query is the page
    const videos = {
        1: { url: 'https://www.youtube.com/watch?v=AAA', title: 'One', domain: 'www.youtube.com' },
        2: { url: 'https://youtube.com/watch?v=BBB', title: 'Two', domain: 'youtube.com' }
    };
    assert.equal((substituteRefBadges('X #REFID_1 Y #REFID_2', videos).match(/class="ref-badge"/g) || []).length, 2);

    // and two pages of one site stay two chips
    const twoPages = {
        1: { url: 'https://shop.hak5.org/products/wifi-pineapple', title: 'Pineapple', domain: 'shop.hak5.org' },
        2: { url: 'https://shop.hak5.org/products/wifi-pineapple-enterprise', title: 'Enterprise', domain: 'shop.hak5.org' }
    };
    assert.equal((substituteRefBadges('X #REFID_1 Y #REFID_2', twoPages).match(/class="ref-badge"/g) || []).length, 2);
});

test('a source is named in full once, then just its number', () => {
    const html = substituteRefBadges('One #REFID_1. Two #REFID_1-C2. Three #REFID_2.', refs);
    assert.equal((html.match(/class="ref-badge"/g) || []).length, 2, 'each source named once');
    assert.equal((html.match(/class="ref-badge-sup"/g) || []).length, 1, 'the second mention is a number');
    assert.match(html, /class="ref-badge-sup" data-ref="1"/);
    // A second pass over this function's own output changes nothing: the streaming path rewrites its buffer with
    // it on every frame, and a source must not be handed a second full chip by that.
    assert.equal(substituteRefBadges(html, refs), html);
});

test('with no title it falls back to the host, minus www', () => {
    const html = substituteRefBadges('#REFID_2', refs);
    assert.match(html, /<span class="ref-badge-label">idealo\.at<\/span>/);
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
    assert.match(copied, /\[geizhals\.at · TerraMaster F2-425 ab € 276,03 \(2026\) \| Preisvergleich\]\(https:\/\/geizhals\.at\/x\)/);
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

test('the shapes the model actually writes become badges, brackets and all', () => {
    // The leak that started this: "[REFID_1-C2]" in brackets, without the hash. Every renderer missed it, so it
    // travelled into the answer as literal text.
    for (const token of ['#REFID_1', '[REFID_1]', '[#REFID_1]', '[REFID_1-C2]', '(REFID_1)', 'REFID_1']) {
        const html = substituteRefBadges(`Claim ${token}.`, refs);
        assert.equal((html.match(/class="ref-badge"/g) || []).length, 1, `${token} did not become a chip`);
        assert.doesNotMatch(html, /REFID/i, `${token} left text behind`);
        assert.match(html, /Claim <a class="ref-badge"/, `${token} lost the surrounding text`);
        assert.match(html, /<\/a>\.$/, `${token} ate the full stop`);
    }

    // An unknown number is dropped in every one of those shapes, brackets and all.
    for (const token of ['[REFID_9-C1]', '(REFID_9)', 'REFID_9']) {
        assert.equal(substituteRefBadges(`Claim ${token}.`, refs), 'Claim .');
    }

    // The clipboard agrees with the renderer.
    const copied = refsToLinks('Claim [REFID_1-C2].', refs);
    assert.doesNotMatch(copied, /REFID/i);
    assert.match(copied, /\]\(https:\/\/geizhals\.at\/x\)\.$/);
});

test('a bracketed or hash-less token is held while it arrives', () => {
    for (const prefix of ['[REFI', '[#REFI', '(REFID_1', '[REFID_1-C1']) {
        assert.equal(substituteRefBadges('Claim ' + prefix, refs, { holdPartial: true }), 'Claim ');
    }
    // a lone bracket is not a token and is left alone
    assert.equal(substituteRefBadges('array index [', refs, { holdPartial: true }), 'array index [');
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
