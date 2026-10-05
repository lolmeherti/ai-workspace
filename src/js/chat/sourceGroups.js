/**
 * @file js/chat/sourceGroups.js
 * @description Group sources by the site that served them: one category per domain, the endpoints it answered
 * with listed inside it.
 *
 * A turn can read several pages on one host — measured: three huggingface.co pages in a single 3-query turn.
 * Rendered as unrelated rows that looks like three sources and hides that one site answered three times, so the
 * site is the category and the pages it served are the entries. Order is first appearance, the order the turn
 * read them. A source with no site (a memory, an attached file) is its own group and never gets a header.
 */

/** The site a source came from: its `domain`, else the URL's host, with "www." removed. '' when neither known. */
export function siteOf(source) {
    const domain = String(source?.domain || '').trim();
    if (domain) return domain.replace(/^www\./i, '');
    try {
        return new URL(String(source?.url || '')).hostname.replace(/^www\./i, '');
    } catch {
        return '';
    }
}

/** The endpoint a page lives at — its path and query, so two pages on one site are told apart. '' if none. */
export function endpointOf(url) {
    try {
        const u = new URL(String(url || ''));
        const path = u.pathname === '/' ? '' : u.pathname;
        const endpoint = path + (u.search || '');
        return endpoint === '/' ? '' : endpoint;
    } catch {
        return '';
    }
}

/**
 * @param {Array<{domain?: string, url?: string}>} sources
 * @returns {Array<{site: string, pages: Array}>} groups in order of first appearance
 */
export function groupBySite(sources) {
    const groups = [];
    const byKey = new Map();

    for (const source of sources || []) {
        const site = siteOf(source);
        // Two pages of one site share a group; anything without a site keeps its own, so it never merges into
        // an unrelated category or inherits a header it has no site for.
        const key = site !== '' ? site : '#entry-' + groups.length;
        if (!byKey.has(key)) {
            const group = { site, pages: [] };
            byKey.set(key, group);
            groups.push(group);
        }
        byKey.get(key).pages.push(source);
    }

    return groups;
}
