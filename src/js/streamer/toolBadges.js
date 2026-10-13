/**
 * One badge per KIND of access per turn.
 *
 * The label row beside a turn is the summary — it went to the web, to local storage, to stored evidence, to the
 * calendar — and the Execution Trace beside it is the detail: every call, the query the model typed, the timing.
 * So a badge carries no query text (the trace already does) and does not stack per call: four browsing calls in
 * one turn are one badge. A calendar action is one badge whether it read, created, updated or deleted, because
 * what happened in detail is the trace's business.
 *
 * Pure functions plus one small factory, so the rule is testable without a DOM:
 * `node --test tests/ui/toolbadges.test.mjs`.
 */

export const TOOL_BADGE_GROUP = {
    search_web:              'web',
    search_local:            'local',
    search_memories:         'local',
    search_files:            'local',
    search_session_evidence: 'evidence',
    search_calendar:         'calendar',
    get_calendar_tasks:      'calendar',
    create_calendar_task:    'calendar',
    update_calendar_task:    'calendar',
    delete_calendar_task:    'calendar',
};

export const BADGE_GROUP_LABEL = {
    web:      'Searched the web',
    local:    'Searched files & memories',
    evidence: 'Read stored evidence',
    calendar: 'Calendar',
};

/** Which summary a tool call belongs to. A tool with no group stands for itself. */
export function badgeGroupFor(tool) {
    return TOOL_BADGE_GROUP[tool] || tool;
}

/** The badge text for a tool — never the model's query, which the trace carries. */
export function badgeLabelFor(tool) {
    return BADGE_GROUP_LABEL[badgeGroupFor(tool)] || tool;
}

/**
 * Per-turn tracker: the first call of a kind renders, later calls of the same kind stay silent. A factory rather
 * than module state, so one turn cannot leak into the next.
 */
export function createBadgeTracker() {
    const rendered = new Set();

    return {
        /** True the first time this kind of access happens in the turn, false afterwards. */
        claim(tool) {
            const group = badgeGroupFor(tool);
            if (rendered.has(group)) {
                return false;
            }
            rendered.add(group);
            return true;
        },
    };
}
