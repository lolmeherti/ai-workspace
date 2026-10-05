import { groupBySite, endpointOf } from './sourceGroups.js';

const SOURCE_GLOBE_ICON = '<svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>';
const SOURCE_EXT_ICON = '<svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';

const SOURCE_WARN_ICON = '<svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';

/**
 * The chat source panel: what the turn read, and what it could not.
 *
 * Sources that were fetched but could not be read — or were read and contributed nothing the answer could
 * use — used to disappear from this panel entirely, so an unreadable page looked like a page that was never
 * found. `failures` carries one entry per such source: {domain, url, label, tooltip, solvable}. The reason is
 * rendered from a server-provided label (never phrased by a model) and the URL is only ever a link the user
 * can open themselves.
 */
export function renderSourcesList(bubble, sources, failures = []) {
    // The caller passes everything the turn fetched, so a page that was read and contributed nothing would be
    // listed as successful and failed at once. It stays one row, under Failed sources. The citation anchors
    // upstream keep the full list — only this panel is filtered.
    const failedUrls = new Set(failures.map(f => f.url).filter(Boolean));
    const shown = failedUrls.size ? sources.filter(s => !failedUrls.has(s.url)) : sources;

    const panel = document.createElement('div');
    panel.className = 'sources-panel relative w-full mt-4 overflow-hidden rounded-xl border border-cyan-500/20 bg-gradient-to-b from-[#0d1321]/90 to-[#0d1321]/70 backdrop-blur-sm shadow-[0_0_25px_rgba(6,182,212,0.08),inset_0_1px_0_rgba(6,182,212,0.06)]';

    const accent = document.createElement('span');
    accent.className = 'absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-cyan-400/60 to-transparent';
    panel.appendChild(accent);

    const header = document.createElement('div');
    header.className = 'flex items-center gap-2 px-4 pt-3 pb-2';
    header.innerHTML = `
        <span class="relative flex items-center justify-center w-6 h-6 rounded-md bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 shadow-[0_0_10px_rgba(6,182,212,0.12)]">${SOURCE_GLOBE_ICON}</span>
        <span class="text-xs font-semibold tracking-normal normal-case bg-gradient-to-r from-cyan-300 via-blue-400 to-emerald-400 bg-clip-text text-transparent">Sources</span>
        <span class="relative flex h-1.5 w-1.5">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-cyan-400 opacity-75"></span>
            <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-cyan-400"></span>
        </span>
        <span class="ml-auto text-xs px-1.5 py-0.5 rounded-full bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 font-mono">${shown.length}</span>
        ${failures.length ? `<span class="source-unread-count text-xs px-1.5 py-0.5 rounded-full border font-mono" title="${failures.length} source${failures.length === 1 ? '' : 's'} could not be used">${failures.length}</span>` : ''}
    `;
    panel.appendChild(header);

    const list = document.createElement('div');
    list.className = 'px-3 pb-3 flex flex-col gap-1.5';

    if (failures.length && shown.length) {
        list.appendChild(groupLabel('Successful sources'));
    }

    for (const group of groupBySite(shown)) {
        // A source with no site (a memory, an attached file, a URL that will not parse) has nothing to head
        // with: it stays the bare row it always was.
        if (group.site === '') {
            for (const s of group.pages) list.appendChild(sourceRow(s, null));
            continue;
        }

        // Every site heads its block, even when it answered once: with a header on some groups and not others
        // the list read as two different things stacked together. The count only earns its place above one page.
        const head = document.createElement('div');
        head.className = 'source-site-head';
        head.appendChild(document.createTextNode(group.site));
        if (group.pages.length > 1) {
            const count = document.createElement('span');
            count.className = 'source-site-count';
            count.textContent = String(group.pages.length);
            head.appendChild(count);
        }
        list.appendChild(head);

        const holder = document.createElement('div');
        holder.className = 'source-site-pages';
        for (const s of group.pages) {
            // Inside a site group the endpoint is what tells the pages apart — the site is already the heading.
            holder.appendChild(sourceRow(s, endpointOf(s.url)));
        }
        list.appendChild(holder);
    }

    if (failures.length) {
        list.appendChild(groupLabel('Failed sources'));
        for (const f of failures) {
            // Two weights of one notice, matching the server-rendered panel: a page the user can clear
            // themselves takes the warm accent, everything else stays recessed slate.
            const fix = !!f.solvable;
            const row = document.createElement(f.url ? 'a' : 'div');
            if (f.url) {
                row.href = f.url;
                row.target = '_blank';
                row.rel = 'noopener noreferrer';
            }
            if (f.tooltip) row.title = f.url ? `${f.tooltip} — ${f.url}` : f.tooltip;
            row.className = 'source-unread' + (fix ? ' source-unread--fixable' : '') + ' relative flex items-center gap-3 px-3 py-2 rounded-lg border transition-all duration-200';

            const iconBox = document.createElement('span');
            iconBox.className = 'source-unread-icon flex items-center justify-center w-7 h-7 shrink-0 rounded-md border';
            iconBox.innerHTML = SOURCE_WARN_ICON;
            row.appendChild(iconBox);

            const textWrap = document.createElement('span');
            textWrap.className = 'flex flex-col min-w-0 flex-1';

            const label = document.createElement('span');
            label.className = 'source-unread-label text-xs truncate transition-colors';
            label.textContent = f.label || 'could not be read';
            textWrap.appendChild(label);

            // Which page this was: several pages on one domain is normal, so the domain alone would make the
            // rows indistinguishable. The full URL is on the row's tooltip.
            const identity = document.createElement('span');
            identity.className = 'flex items-center gap-1.5 min-w-0';
            if (f.title) {
                const page = document.createElement('span');
                page.className = 'source-unread-page text-xs truncate transition-colors';
                page.textContent = f.title;
                identity.appendChild(page);
            }
            const host = document.createElement('span');
            host.className = 'source-unread-host text-xs shrink-0 font-mono transition-colors';
            host.textContent = f.domain || f.url || '';
            identity.appendChild(host);
            textWrap.appendChild(identity);

            row.appendChild(textWrap);

            if (fix) {
                const hint = document.createElement('span');
                hint.className = 'source-unread-hint shrink-0 text-[10px] font-mono';
                hint.textContent = 'you can fix this';
                row.appendChild(hint);
            }

            list.appendChild(row);
        }
    }

    panel.appendChild(list);
    bubble.appendChild(panel);
}

/**
 * One page row: its title, and either the endpoint it lives at — inside a site group, where the site already
 * heads the group — or its host when the row stands on its own. `endpoint` is null for a standalone row;
 * a grouped row with no path (the site root) simply grows no second line.
 */
function sourceRow(s, endpoint = null) {
    const a = document.createElement('a');
    try {
        const url = new URL(s.url);
        if (['http:', 'https:'].includes(url.protocol)) a.href = url.href;
    } catch {}
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    a.className = 'group relative flex items-center gap-3 px-3 py-2 rounded-lg border border-slate-700/40 bg-slate-900/30 hover:border-cyan-500/30 hover:bg-cyan-500/5 hover:shadow-[0_0_16px_rgba(6,182,212,0.10)] transition-all duration-200';

    const iconBox = document.createElement('span');
    iconBox.className = 'flex items-center justify-center w-7 h-7 shrink-0 rounded-md bg-slate-800/60 border border-slate-700/50 text-cyan-400 group-hover:border-cyan-500/40 group-hover:text-cyan-300 group-hover:shadow-[0_0_12px_rgba(6,182,212,0.25)] transition-all';
    iconBox.innerHTML = SOURCE_GLOBE_ICON;
    a.appendChild(iconBox);

    const textWrap = document.createElement('span');
    textWrap.className = 'flex flex-col min-w-0 flex-1';

    const titleText = s.title || s.domain || s.url || '';
    const title = document.createElement('span');
    title.className = 'text-xs text-slate-300 truncate group-hover:text-cyan-200 transition-colors';
    title.textContent = titleText;
    textWrap.appendChild(title);

    const grouped = endpoint !== null;
    const second = grouped ? endpoint : (s.domain || (a.href ? new URL(a.href).hostname : ''));
    if (second && second !== titleText) {
        const line = document.createElement('span');
        line.className = grouped
            ? 'source-endpoint text-xs truncate'
            : 'text-xs text-slate-500 truncate font-mono group-hover:text-slate-400 transition-colors';
        line.textContent = second;
        textWrap.appendChild(line);
    }

    a.appendChild(textWrap);

    const ext = document.createElement('span');
    ext.className = 'shrink-0 text-slate-600 group-hover:text-cyan-400 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 transition-all';
    ext.innerHTML = SOURCE_EXT_ICON;
    a.appendChild(ext);

    return a;
}

function groupLabel(text) {
    const el = document.createElement('div');
    el.className = 'px-1 pt-1 pb-0.5 text-[10px] uppercase tracking-wide text-slate-500';
    el.textContent = text;
    return el;
}
