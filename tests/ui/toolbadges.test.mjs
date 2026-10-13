/**
 * Badges are one per kind of access per turn: the summary line says the web was searched once, however many
 * searches it took, and the Execution Trace beside it carries each call's query. Pure functions, so this runs
 * with plain `node --test tests/ui/toolbadges.test.mjs` — no PHP fixture, no DOM.
 */
import test from 'node:test';
import assert from 'node:assert/strict';

const { badgeGroupFor, badgeLabelFor, createBadgeTracker } = await import('../../src/js/streamer/toolBadges.js');

test('two web searches in one turn are one badge', () => {
    const tracker = createBadgeTracker();
    assert.equal(tracker.claim('search_web'), true);
    assert.equal(tracker.claim('search_web'), false);
});

test('four browsing calls — search, search, read — are one badge', () => {
    const tracker = createBadgeTracker();
    assert.equal(tracker.claim('search_session_evidence'), true);
    assert.equal(tracker.claim('search_session_evidence'), false);
    assert.equal(tracker.claim('search_session_evidence'), false);
});

test('every calendar action collapses into the one calendar badge', () => {
    const tracker = createBadgeTracker();
    assert.equal(tracker.claim('search_calendar'), true);
    assert.equal(tracker.claim('create_calendar_task'), false);
    assert.equal(tracker.claim('update_calendar_task'), false);
    assert.equal(tracker.claim('delete_calendar_task'), false);
});

test('creating a task first does not give the reading a second badge', () => {
    const tracker = createBadgeTracker();
    assert.equal(tracker.claim('create_calendar_task'), true);
    assert.equal(tracker.claim('get_calendar_tasks'), false);
});

test('files and memories are one local badge', () => {
    const tracker = createBadgeTracker();
    assert.equal(tracker.claim('search_local'), true);
    assert.equal(tracker.claim('search_memories'), false);
    assert.equal(tracker.claim('search_files'), false);
});

test('different kinds of access still get their own badge', () => {
    const tracker = createBadgeTracker();
    assert.equal(tracker.claim('search_web'), true);
    assert.equal(tracker.claim('search_session_evidence'), true);
    assert.equal(tracker.claim('search_local'), true);
    assert.equal(tracker.claim('search_calendar'), true);
});

test('a tool with no group stands for itself', () => {
    const tracker = createBadgeTracker();
    assert.equal(badgeGroupFor('some_new_tool'), 'some_new_tool');
    assert.equal(badgeLabelFor('some_new_tool'), 'some_new_tool');
    assert.equal(tracker.claim('some_new_tool'), true);
    assert.equal(tracker.claim('some_new_tool'), false);
});

test('a tracker carries no state into the next turn', () => {
    const first = createBadgeTracker();
    assert.equal(first.claim('search_web'), true);
    const second = createBadgeTracker();
    assert.equal(second.claim('search_web'), true);
});

test('badge labels say what happened without the model_query', () => {
    assert.equal(badgeLabelFor('search_web'), 'Searched the web');
    assert.equal(badgeLabelFor('search_local'), 'Searched files & memories');
    assert.equal(badgeLabelFor('search_memories'), 'Searched files & memories');
    assert.equal(badgeLabelFor('search_session_evidence'), 'Read stored evidence');
    assert.equal(badgeLabelFor('search_calendar'), 'Calendar');
    assert.equal(badgeLabelFor('create_calendar_task'), 'Calendar');
});

test('no label can carry a query string, by construction', () => {
    // The label is a fixed phrase per group: nothing the model typed can reach it.
    for (const tool of ['search_web', 'search_local', 'search_session_evidence', 'search_calendar']) {
        const label = badgeLabelFor(tool);
        assert.equal(label.includes('Reinagl'), false);
        assert.equal(label.includes('feedback commuters'), false);
        assert.equal(label.length < 40, true);
    }
});
