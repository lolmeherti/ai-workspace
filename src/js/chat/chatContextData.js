/** Context inspector. Full-height drawer: query, actions, sources, facts and apply workflow. */
import { requestJson, notify, confirmAction, withPending } from '../workspace/feedback.js';
import { ensureAIAvailable, reportBusy, paintAvailability } from '../workspace/availability.js';
import { updateConversation } from './chatNavigation.js';
import { state } from '../state.js';

let epoch = 0;
let dirty = false;
let pending = false;
let currentData = null;
let returnFocus = null;
let editMode = null;   // null | 'evidence' | 'facts'
let factsPreview = false;

const labels = { raw: 'Full evidence', raw_atoms: 'Evidence + key facts', atomized: 'Key facts only', evicted: 'Excluded' };
const panel = () => document.getElementById('context-data-panel');
const host = () => document.getElementById('context-detail-host');
const list = () => document.getElementById('context-data-items');
function stateOf(d) { return d.raw_evicted ? d.atomic_context?.length ? 'atomized' : 'evicted' : d.atomic_context?.length ? 'raw_atoms' : 'raw'; }
function el(tag, cls = '', text) { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }

const ICON = path => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${path}</svg>`;
const ICONS = {
    search: ICON('<path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>'),
    copy: ICON('<path d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>'),
    bolt: ICON('<path d="M13 10V3L4 14h7v7l9-11h-7z"/>'),
    edit: ICON('<path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>'),
    globe: ICON('<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/>'),
    database: ICON('<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>'),
    file: ICON('<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>'),
    external: ICON('<path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>'),
    arrow: ICON('<path d="M10 19l-7-7m0 0l7-7m-7 7h18"/>'),
};
function icon(name, cls = '') {
    const s = document.createElement('span');
    s.className = 'inline-flex flex-shrink-0';
    s.setAttribute('aria-hidden', 'true');
    s.innerHTML = ICONS[name].replace('<svg ', `<svg class="${cls}" `);
    return s;
}

const fetchView = id => requestJson(`index.php?view_context=${encodeURIComponent(id)}&ajax=1`);
const post = (op, id, claims) => {
    const body = new URLSearchParams({ action: 'atomize_context', op, id: String(id) });
    if (claims !== undefined) body.set('claims', JSON.stringify(claims));
    return requestJson('index.php', { method: 'POST', body });
};
function applyContextTokens(res) {
    if (res && typeof res.total_session_tokens === 'number') {
        updateConversation(state.sessionId, { tokens: res.total_session_tokens });
    }
}
export async function canLeaveContext() {
    if (pending) { notify('Context is being updated. Wait for this operation to finish.', { target: host() }); return false; }
    return !dirty || await confirmAction({ title: 'Discard unsaved context changes?', message: 'Your edits or extracted preview have not been saved.', confirmLabel: 'Discard changes', destructive: true });
}
export function resetContextDetail() {
    epoch++; dirty = false; currentData = null; editMode = null; factsPreview = false;
    if (host()) { host().replaceChildren(); host().hidden = true; }
    if (list()) list().hidden = false;
}
export function openContextPanel() {
    returnFocus = document.activeElement;
    panel().hidden = false;
    document.getElementById('chat-file-editor-drawer')?.classList.add('inspector-hidden');
    document.getElementById('context-toggle')?.setAttribute('aria-expanded', 'true');
}
async function closeContext() {
    if (!await canLeaveContext()) return;
    resetContextDetail(); panel().hidden = true; panel().classList.remove('is-expanded');
    updateExpandButton(false);
    document.getElementById('chat-file-editor-drawer')?.classList.remove('inspector-hidden');
    document.getElementById('context-toggle')?.setAttribute('aria-expanded', 'false');
    if (returnFocus?.isConnected) returnFocus.focus();
}
function count() { const el = document.getElementById('context-data-count'); if (el) el.textContent = list()?.querySelectorAll('.context-item').length || 0; }
function fmtTok(n) { return Number(n || 0).toLocaleString('en-US'); }
function downArrow(cls) { return ICON('<path d="M12 5v14m0 0l-6-6m6 6l6-6"/>').replace('<svg ', `<svg class="${cls} text-emerald-400 shrink-0" `); }
function savedFor(d) {
    const st = stateOf(d);
    return st === 'atomized' ? Math.max(0, (Number(d.token_estimate) || 0) - (Number(d.atomic_tokens) || 0)) : 0;
}
function tokenLineHtml(d) {
    const raw = Number(d.token_estimate) || 0;
    const hasAtoms = !!d.atomic_context?.length;
    const atoms = hasAtoms ? (Number(d.atomic_tokens) || 0) : 0;
    const st = stateOf(d);
    if (st === 'atomized') {
        return `<div class="context-tokens mt-2 flex items-center gap-2 rounded-md bg-emerald-500/10 border border-emerald-500/20 px-2.5 py-1.5">${downArrow('w-3.5 h-3.5')}<span class="text-emerald-300 text-xl font-bold tabular-nums leading-none">${fmtTok(savedFor(d))}</span><span class="text-emerald-200/90 text-xs font-medium">tokens saved</span><span class="ml-auto text-emerald-400/70 text-xs font-mono tabular-nums">${fmtTok(raw)} → ${fmtTok(atoms)}</span></div>`;
    }
    if (st === 'raw_atoms') {
        return `<div class="context-tokens mt-2 flex items-center gap-2 text-xs"><span class="text-slate-300 font-semibold tabular-nums">~${fmtTok(raw + atoms)}</span><span class="text-slate-500">tokens (evidence + facts)</span></div>`;
    }
    if (st === 'evicted') {
        return `<div class="context-tokens mt-2 flex items-center gap-2 text-xs"><span class="text-rose-400/80 font-semibold tabular-nums">0</span><span class="text-slate-500">tokens — excluded</span></div>`;
    }
    return `<div class="context-tokens mt-2 flex items-center gap-2 text-xs"><span class="text-slate-300 font-semibold tabular-nums">~${fmtTok(raw)}</span><span class="text-slate-500">tokens in context</span></div>`;
}
function renderSavingsSummary(root = list()) {
    if (!root) return;
    let summary = root.querySelector('#context-savings-summary');
    const total = [...root.querySelectorAll('.context-item')].reduce((acc, row) => acc + (Number(row.dataset.saved) || 0), 0);
    if (total <= 0) { summary?.remove(); return; }
    if (!summary) {
        summary = el('div', 'flex items-center gap-3 mb-3 px-3 py-2.5 rounded-lg bg-emerald-500/10 border border-emerald-500/25');
        summary.id = 'context-savings-summary';
        root.prepend(summary);
    }
    summary.innerHTML = `${downArrow('w-4 h-4')}<div class="min-w-0"><div class="text-emerald-300 font-bold text-xl leading-tight"><span class="tabular-nums">${fmtTok(total)}</span> tokens saved</div><div class="text-emerald-200/60 text-xs">Key facts keep only the essentials in context</div></div>`;
}
function renderRow(id, d, root = list()) {
    const row = root?.querySelector(`.context-item[data-id="${Number(id)}"]`); if (!row) return;
    const st = stateOf(d);
    row.dataset.state = st;
    row.dataset.saved = String(savedFor(d));
    const badge = row.querySelector('.context-badge'); if (badge) badge.textContent = labels[st];
    const meta = row.querySelector('.context-meta');
    if (meta) {
        const srcCount = Number(d.source_count) || (d.sources && typeof d.sources === 'object' ? Object.keys(d.sources).length : 0);
        const parts = [toolLabel(d.tool_name)];
        if (srcCount > 0) parts.push(`${srcCount} source${srcCount === 1 ? '' : 's'}`);
        meta.textContent = parts.join(' · ');
    }
    let tokens = meta?.parentElement?.querySelector('.context-tokens');
    if (!tokens && meta?.parentElement) { tokens = el('div', 'context-tokens'); meta.parentElement.append(tokens); }
    if (tokens) tokens.innerHTML = tokenLineHtml(d);
    const actions = row.querySelector('.context-btns');
    if (actions) {
        actions.replaceChildren();
        for (const [action, label, variant] of [['view', 'View', ''], ['edit_raw', 'Edit evidence', 'secondary'], [d.atomic_context?.length ? 'reatomize' : 'atomize', d.atomic_context?.length ? 'Extract again' : 'Extract key facts', 'primary']]) {
            const btn = node('button', label, `ui-button${variant ? ` ui-button--${variant}` : ''}`); btn.type = 'button'; btn.dataset.action = action; btn.dataset.id = id; actions.append(btn);
        }
    }
    renderSavingsSummary(root);
}
export async function refreshContextItem(id, { root = list(), updateViewer = true } = {}) {
    const version = epoch;
    const d = await fetchView(id); renderRow(id, d, root);
    if (updateViewer && version === epoch && currentData?.id == id && !dirty) fill(d);
    return d;
}
export function addContextItem(item, root = list()) {
    if (!root || root.querySelector(`.context-item[data-id="${Number(item.id)}"]`)) return;
    root.querySelector('#context-data-empty')?.remove();
    const row = node('div', '', 'context-item'); row.dataset.id = item.id;
    const copy = node('div', '', 'min-w-0 flex-1');
    copy.append(node('div', item.query || item.tool_name || 'Context source'), node('div', '', 'context-meta text-xs text-slate-400'));
    const badge = node('span', '', 'context-badge');
    const actions = node('div', '', 'context-btns');
    const view = node('button', 'View', 'ui-button'); view.type = 'button'; view.dataset.action = 'view'; view.dataset.id = item.id;
    actions.append(view); row.append(copy, badge, actions); root.append(row);
    renderRow(item.id, item, root); if (root === list()) count();
}
export async function viewContextItem(id) {
    if (!await canLeaveContext()) return;
    resetContextDetail(); openContextPanel();
    const version = ++epoch;
    host().hidden = false; list().hidden = true; host().replaceChildren(renderLoadingEvidence());
    try { const d = await fetchView(id); if (version === epoch) fill(d); }
    catch (e) { if (version === epoch) notify(e.message, { target: host(), action: 'Retry', onAction: () => viewContextItem(id) }); }
}
export function parseAtomLines(text) {
    return text.split('\n').filter(x => x.trim()).map(line => {
        const m = line.trim().match(/^\[([^\]]+)\]\s*(.+)$/);
        if (!m) throw new Error('Keep each key fact in the form [source_id] fact. No lines have been saved.');
        return { source_id: m[1], claim: m[2] };
    });
}

/* ----------------------------------------------------------------------------
 * Drawer detail view (matches plans/concepts/context-data.html context-drawer)
 * ------------------------------------------------------------------------- */

function activeTokens(d) {
    const raw = d.raw_evicted ? 0 : (Number(d.token_estimate) || 0);
    const atoms = d.atomic_context?.length ? (Number(d.atomic_tokens) || 0) : 0;
    return raw + atoms;
}
function queryText(d) { return d.search_query || d.tool_name || 'Context source'; }
function estimateTokens(text) { return Math.max(1, Math.round(String(text || '').length / 4)); }
function validUrl(url) { try { const u = new URL(url); return ['http:', 'https:'].includes(u.protocol) ? u.href : ''; } catch { return ''; } }
function isMemoryTool(name) { return name === 'search_memories' || name === 'search_local'; }
function sourceKind(name) { return isMemoryTool(name) ? 'memory' : (name === 'file' ? 'file' : 'web'); }
function toolLabel(name) {
    if (name === 'search_memories') return 'Memories';
    if (name === 'search_local') return 'Local search';
    if (name === 'file') return 'Attached file';
    return name || 'Source';
}

/** Render markdown via the global `marked`. Escapes raw HTML first (evidence is scraped text, never trusted markup), then returns HTML or null when `marked` is unavailable. */
function renderMd(text) {
    const src = text == null ? '' : String(text);
    if (typeof marked !== 'undefined') {
        try {
            const escaped = src.replace(/</g, '&lt;').replace(/>/g, '&gt;');
            return marked.parse(escaped);
        } catch (e) { /* fall through to null */ }
    }
    return null;
}

/** Display cards: parsed sources first, source_map fallback, plain manual last. */
function sourceFeed(d) {
    const map = (d.sources && typeof d.sources === 'object') ? d.sources : {};
    if (d.parsed?.length) {
        return d.parsed.map(s => {
            const meta = map[s.id] || {};
            const text = (s.chunks || []).join('\n').trim();
            return { id: s.id, title: s.title || meta.title || s.id, domain: s.domain || meta.domain || '', url: meta.url || '', text, tokens: estimateTokens(text) };
        });
    }
    const ids = Object.keys(map);
    if (ids.length) {
        return ids.map(id => {
            const meta = map[id] || {};
            const text = d.message || '';
            return { id, title: meta.title || meta.domain || id, domain: meta.domain || '', url: meta.url || '', text, tokens: estimateTokens(text) };
        });
    }
    return [{ id: 'manual', title: d.tool_name === 'file' ? (d.search_query || 'Attached file') : (toolLabel(d.tool_name) || 'Evidence'), kind: sourceKind(d.tool_name), domain: '', url: '', text: d.message || '', tokens: estimateTokens(d.message) }];
}

/** Edit contract: source ids must match ContextDataViewAction::parseSources (or 'manual'). */
function editorSources(d) {
    if (d.parsed?.length) return d.parsed.map(s => ({ id: s.id, title: s.title || s.id, text: (s.chunks || []).join('\n') }));
    return [{ id: 'manual', title: 'Evidence', text: d.message || '' }];
}

async function copyQuery(d) {
    try { await navigator.clipboard.writeText(queryText(d)); notify('Query copied.', { target: host(), kind: 'info' }); }
    catch { notify('Could not copy the query.', { target: host() }); }
}

function renderBreadcrumb() {
    const wrap = el('div', 'flex items-center justify-between text-[11px] px-1 shrink-0');
    const back = el('button', 'context-link-btn text-cyan-400 hover:text-cyan-300 flex items-center gap-1 font-medium transition-colors');
    back.type = 'button';
    back.append(icon('arrow', 'w-3 h-3'), document.createTextNode('All context'));
    back.addEventListener('click', () => { withPending(back, async () => { if (await canLeaveContext()) resetContextDetail(); }, { target: host() }); });
    wrap.append(back, el('span', 'text-slate-500 text-[10px]', 'Evidence & extracted facts'));
    return wrap;
}

function renderLoadingEvidence() {
    const wrap = el('div', 'context-loading');
    wrap.setAttribute('role', 'status'); wrap.setAttribute('aria-live', 'polite');
    const row = el('div', 'context-skeleton-row');
    row.append(el('div', 'context-skeleton'), el('div', 'context-skeleton'));
    wrap.append(el('div', 'context-skeleton h-lg'), row, el('div', 'context-skeleton h-md'), el('div', 'context-skeleton h-md'));
    return wrap;
}

function renderQueryBox(d) {
    const box = el('div', 'context-query-box bg-[#0c1625] border border-[#172d47] rounded-lg p-2.5 text-xs space-y-1.5');
    const head = el('div', 'flex items-center justify-between');
    const label = el('span', 'flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400');
    label.append(icon('search', 'w-3 h-3 text-cyan-400'), el('span', '', 'Search Query'));
    const copy = el('button', 'context-icon-btn text-slate-400 hover:text-cyan-400 transition-colors');
    copy.type = 'button'; copy.title = 'Copy query';
    copy.append(icon('copy', 'w-3 h-3'));
    copy.addEventListener('click', () => copyQuery(d));
    head.append(label, copy);
    box.append(head, el('p', 'font-mono text-[11px] text-slate-200 truncate select-text', queryText(d)));
    return box;
}

async function toggleContextInclude(id) {
    if (pending || !await canLeaveContext()) return;
    const op = currentData?.raw_evicted ? 'restore' : 'evict_raw';
    pending = true;
    try {
        const res = await post(op, id); dirty = false;
        if (op === 'evict_raw') unlock();
        applyContextTokens(res);
        await refreshContextItem(id);
    } finally { pending = false; }
}

function renderToggle(d) {
    const wrap = el('div', 'flex items-center justify-between px-2.5 py-1.5 rounded-md bg-[#0a1422] border border-[#172c46] text-xs');
    const info = el('div', 'min-w-0');
    info.append(el('div', 'text-[11px] text-slate-300', 'Include in model context'));
    const sub = d.raw_evicted
        ? 'Disabled: context excluded from next prompt'
        : `Active: feeds ${activeTokens(d)} tokens into chat answers`;
    info.append(el('div', `text-[10px] ${d.raw_evicted ? 'text-slate-500' : 'text-slate-400'}`, sub));
    const sw = el('button', 'context-switch');
    sw.type = 'button'; sw.setAttribute('role', 'switch'); sw.setAttribute('aria-checked', String(!d.raw_evicted));
    sw.setAttribute('aria-label', 'Include in model context');
    sw.append(el('span', 'context-switch-knob'));
    sw.addEventListener('click', () => { withPending(sw, () => toggleContextInclude(d.id), { target: host() }); });
    wrap.append(info, sw);
    return wrap;
}

function renderActions(d) {
    const wrap = el('div', 'context-actions space-y-2');
    const grid = el('div', 'grid grid-cols-2 gap-2');
    const extract = el('button', 'py-1.5 px-2 rounded-md bg-cyan-400 hover:bg-cyan-300 text-[#07131e] font-semibold text-xs transition-colors flex items-center justify-center gap-1');
    extract.type = 'button'; extract.append(icon('bolt', 'w-3.5 h-3.5'), document.createTextNode('Extract key facts'));
    extract.addEventListener('click', () => { withPending(extract, () => d.atomic_context?.length ? reAtomizeContextItem(d.id) : atomizeContextItem(d.id), { target: host() }); });
    const editAll = el('button', 'py-1.5 px-2 rounded-md bg-[#112238] hover:bg-[#162d4a] border border-[#1e3b61] text-slate-200 text-xs font-medium transition-colors flex items-center justify-center gap-1');
    editAll.type = 'button'; editAll.append(icon('edit', 'w-3.5 h-3.5 text-slate-400'), document.createTextNode('Edit all evidence'));
    editAll.addEventListener('click', () => { withPending(editAll, async () => { if (await canLeaveContext()) { if (currentData?.id === d.id) startEvidenceEdit(d); else await editEvidenceContextItem(d.id); } }, { target: host() }); });
    grid.append(extract, editAll);
    wrap.append(grid, renderToggle(d));
    return wrap;
}

function renderSourceCard(src) {
    const card = el('div', 'context-source-card bg-[#0e1a2b] border border-[#172c46] rounded-lg overflow-hidden');
    const head = el('button', 'w-full flex items-start justify-between gap-1.5 p-2.5 text-left hover:bg-[#12213a] transition-colors');
    head.type = 'button';
    head.setAttribute('aria-expanded', 'false');
    const meta = el('div', 'flex items-start gap-1.5 min-w-0 flex-1');
    meta.append(icon(src.kind === 'memory' ? 'database' : src.kind === 'file' ? 'file' : 'globe', 'w-3.5 h-3.5 text-cyan-400 mt-0.5 flex-shrink-0'));
    const info = el('div', 'min-w-0 flex-1');
    const titleRow = el('div', 'flex items-center gap-1.5');
    titleRow.append(el('h4', 'text-xs font-semibold text-slate-100 truncate', src.title || src.id));
    titleRow.append(el('span', 'text-[10px] font-mono text-cyan-300 bg-[#102035] px-1 rounded flex-shrink-0', `~${src.tokens} tok`));
    info.append(titleRow);
    if (src.domain) info.append(el('span', 'text-[10px] text-slate-400 font-mono block truncate', src.domain));
    meta.append(info);
    const chevron = el('span', 'context-source-chevron text-slate-500 transition-transform flex-shrink-0 mt-0.5');
    chevron.innerHTML = ICON('<path d="M6 9l6 6 6-6"/>').replace('<svg ', '<svg class="w-3.5 h-3.5" ');
    head.append(meta, chevron);
    if (validUrl(src.url)) {
        const a = el('a', 'context-icon-btn text-slate-400 hover:text-white');
        a.href = validUrl(src.url); a.target = '_blank'; a.rel = 'noopener noreferrer'; a.title = 'Open link';
        a.append(icon('external', 'w-3 h-3'));
        a.addEventListener('click', e => e.stopPropagation());
        head.append(a);
    }

    const body = el('div', 'context-source-body hidden');
    const snippet = el('div', 'context-evidence-text p-3 border-t border-[#142337] overflow-y-auto text-xs text-slate-300 leading-relaxed max-h-48 select-text markdown-content');
    const snippetHtml = renderMd(src.text || 'No source text available.');
    if (snippetHtml !== null) snippet.innerHTML = snippetHtml; else snippet.textContent = src.text || 'No source text available.';
    body.append(snippet);

    head.addEventListener('click', () => {
        const open = body.classList.toggle('hidden');
        head.setAttribute('aria-expanded', String(!open));
        chevron.classList.toggle('rotate-180', !open);
    });
    card.append(head, body);
    return card;
}

function renderSources(d) {
    const wrap = el('div', 'context-sources space-y-2');
    const feed = sourceFeed(d);
    const kind = sourceKind(d.tool_name);
    const special = kind !== 'web';
    const iconName = kind === 'memory' ? 'database' : kind === 'file' ? 'file' : 'globe';
    const head = el('div', 'flex items-center justify-between text-xs text-slate-300 font-medium px-1');
    const left = el('div', 'flex items-center gap-1.5');
    left.append(icon(iconName, 'w-3.5 h-3.5 text-cyan-400'), el('span', '', special ? toolLabel(d.tool_name) : 'Sources & Evidence'));
    if (!special) left.append(el('span', 'px-1.5 py-0.2 rounded-full bg-[#10233b] text-cyan-300 text-[10px] font-mono border border-cyan-500/20', String(feed.length)));
    head.append(left, el('span', 'text-[10px] text-slate-400', special ? `~${Number(d.token_estimate) || 0} raw tok` : `${feed.length} source${feed.length === 1 ? '' : 's'} · ~${Number(d.token_estimate) || 0} raw tok`));
    wrap.append(head);
    for (const src of feed) wrap.append(renderSourceCard(src));
    return wrap;
}

function renderFacts(d) {
    if (!d.atomic_context?.length) return null;
    const rawTokens = Number(d.token_estimate) || 0;
    const factTokens = Number(d.atomic_tokens) || 0;
    const saved = Math.max(0, rawTokens - factTokens);
    const wrap = el('div', 'context-facts p-2.5 rounded-lg bg-[#0a1422] border border-[#172c46] space-y-1.5');
    const head = el('div', 'flex items-center justify-between text-xs');
    const left = el('span', 'font-semibold text-slate-300 flex items-center gap-1.5');
    left.append(el('span', 'w-1.5 h-1.5 rounded-full bg-slate-500 flex-shrink-0'), el('span', '', 'Extracted Facts'));
    const right = el('div', 'flex items-center gap-2');
    const edit = el('button', 'context-link-btn text-cyan-400 hover:text-cyan-300');
    edit.type = 'button'; edit.textContent = 'Edit';
    edit.addEventListener('click', () => { withPending(edit, async () => { if (await canLeaveContext()) startFactsEdit(d, d.atomic_context, false); }, { target: host() }); });
    const del = el('button', 'context-link-btn text-slate-400 hover:text-rose-300');
    del.type = 'button'; del.textContent = 'Delete';
    del.addEventListener('click', () => { withPending(del, () => deleteAtomsContextItem(d.id), { target: host() }); });
    right.append(edit, del);
    head.append(left, right);
    wrap.append(head);
    const summary = el('div', 'context-token-summary flex flex-wrap items-center gap-2 text-[10px] font-mono text-slate-400 bg-[#070d17] border border-[#142337] rounded p-1.5');
    summary.append(
        el('span', '', `raw ${rawTokens} tok`),
        el('span', 'text-slate-600', '→'),
        el('span', 'text-cyan-300', `facts ${factTokens} tok`),
        el('span', saved > 0 ? 'text-emerald-400' : 'text-slate-500', saved > 0 ? `(saves ~${saved} tok)` : '(no reduction)')
    );
    wrap.append(summary);
    const body = el('div', 'context-atoms');
    const raw = d.atomic_context.map(c => `[${c.source_id}] ${c.claim}`).join('\n');
    const html = renderMd(d.atomic_context.map(c => `- **${c.source_id}** ${c.claim}`).join('\n'));
    const md = el('div', 'markdown-content context-atoms-list');
    if (html !== null) md.innerHTML = html; else md.textContent = raw;
    body.append(md);
    wrap.append(body);
    return wrap;
}

function renderBody(d) {
    const body = el('div', 'context-detail-body');
    const facts = renderFacts(d);
    body.append(renderQueryBox(d), renderActions(d));
    if (facts) body.append(facts);
    body.append(renderSources(d));
    return body;
}

function renderFooter(d) {
    const f = el('div', 'context-footer p-3 border-t border-[#15253b] bg-[#070d17] space-y-2 shrink-0');
    const status = el('div', 'flex items-center justify-between text-[10px] font-mono');
    status.append(el('span', 'text-slate-400', `● ${activeTokens(d)} tokens in active chat`));
    status.append(el('span', dirty ? 'text-slate-400' : 'text-slate-500', dirty ? 'Unapplied changes staged' : 'Up to date'));
    const actions = el('div', 'flex items-center gap-2');
    if (editMode) {
        const cancel = el('button', 'flex-1 py-1.5 px-3 rounded-lg bg-[#112238] hover:bg-[#162d4a] border border-[#1e3b61] text-slate-200 text-xs font-medium transition-colors', 'Cancel');
        cancel.type = 'button';
        cancel.addEventListener('click', () => { withPending(cancel, async () => { if (await canLeaveContext()) fill(d); }, { target: host() }); });
        const save = el('button', 'py-1.5 px-3 rounded-lg bg-cyan-300 hover:bg-cyan-200 text-[#07131e] font-semibold text-xs transition-colors', 'Save & Apply');
        save.type = 'button';
        save.addEventListener('click', () => { withPending(save, () => applyEdits(d), { target: host() }); });
        actions.append(cancel, save);
    } else {
        const exclude = el('button', 'flex-1 py-1.5 px-3 rounded-lg bg-[#3a1b24] hover:bg-[#48202c] border border-rose-900/50 text-rose-300 font-medium text-xs transition-colors', d.raw_evicted ? 'Restore evidence' : 'Exclude context');
        exclude.type = 'button';
        exclude.addEventListener('click', () => { withPending(exclude, () => d.raw_evicted ? restoreContextItem(d.id) : evictRawContextItem(d.id), { target: host() }); });
        const applied = el('button', 'py-1.5 px-3 rounded-lg bg-[#102338] text-slate-400 border border-[#1d3759] text-xs cursor-default', '✓ Applied to Chat');
        applied.type = 'button'; applied.disabled = true;
        actions.append(exclude, applied);
    }
    f.append(status, actions);
    return f;
}
function rerenderFooter(d) { const old = host().querySelector('.context-footer'); if (old) old.replaceWith(renderFooter(d)); }

function fill(d) {
    currentData = d; dirty = false; editMode = null; factsPreview = false;
    const root = host(); root.hidden = false; list().hidden = true; root.replaceChildren();
    root.append(renderBreadcrumb(), renderBody(d), renderFooter(d));
}

function unlock() {
    state.contextLocked = false;
    const q = document.getElementById('q'); if (q) { q.disabled = false; q.placeholder = 'Message Localsy…'; }
    paintAvailability();
}

async function applyEdits(d) {
    if (pending) return;
    if (editMode === 'evidence') return saveEvidence(d);
    if (editMode === 'facts') return saveFacts(d);
}

function startEvidenceEdit(d) {
    editMode = 'evidence'; dirty = true;
    const area = host().querySelector('.context-sources'); if (!area) return;
    area.replaceChildren();
    const sources = editorSources(d);
    sources.forEach((s, i) => {
        const label = el('label', 'block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1', s.title);
        label.htmlFor = `context-evidence-${i}`;
        const ta = el('textarea', 'context-evidence-editor w-full min-h-24 p-2 rounded bg-[#070d17] border border-[#142337] font-mono text-[11px] text-slate-300 leading-relaxed resize-y');
        ta.id = label.htmlFor; ta.rows = 8; ta.value = s.text;
        ta.addEventListener('input', () => { dirty = true; });
        area.append(label, ta);
    });
    area.append(el('p', 'text-[11px] text-slate-500 italic', 'Editing replaces the retained evidence and clears existing key facts so removed text is not reused.'));
    rerenderFooter(d);
    area.querySelector('textarea')?.focus();
}

async function saveEvidence(d) {
    if (pending) return;
    const editors = [...host().querySelectorAll('.context-evidence-editor')];
    const sources = editorSources(d);
    const evidence = sources.map((s, i) => ({ id: s.id, text: editors[i]?.value ?? '' }));
    pending = true;
    try {
        const body = new URLSearchParams({ action: 'atomize_context', op: 'edit_raw', id: String(d.id), base_message: d.message || '', evidence: JSON.stringify(evidence) });
        const res = await requestJson('index.php', { method: 'POST', body });
        dirty = false; editMode = null;
        applyContextTokens(res);
        await refreshContextItem(d.id);
        notify('Evidence saved. Extract key facts when you are ready.', { target: host(), kind: 'info' });
    } finally { pending = false; }
}

async function runPreview(id, op) {
    if (pending || !ensureAIAvailable(host()) || !await canLeaveContext()) return;
    if (currentData?.id != id) await viewContextItem(id);
    if (currentData?.id != id) return;
    const d = currentData; const version = epoch; pending = true;
    const progress = el('div', 'context-progress'); progress.id = 'context-extraction-progress';
    const spinner = el('span', 'ui-spinner'); spinner.setAttribute('aria-hidden', 'true');
    const copy = el('span'); copy.append(el('strong', '', 'Extracting key facts…'), el('span', '', 'The AI is reviewing the retained evidence. This can take a moment.'));
    progress.append(spinner, copy); progress.setAttribute('role', 'status'); progress.setAttribute('aria-live', 'polite');
    host().prepend(progress); host().setAttribute('aria-busy', 'true');
    const area = host().querySelector('.context-atoms');
    if (area) { area.replaceChildren(el('p', 'text-slate-400', 'Extracting key facts… Your saved evidence is unchanged.')); area.setAttribute('aria-busy', 'true'); }
    try {
        const res = await post(op, id);
        if (version !== epoch) return;
        progress.remove();
        if (res.status === 'preview') startFactsEdit(d, res.claims || [], true);
        else { fill(d); notify(res.message || 'No key facts were found.', { target: host(), kind: 'info' }); }
    } catch (e) { if (version === epoch) { fill(d); notify(e.message, { target: host() }); if (e.code === 'model_busy') reportBusy(e.message); } }
    finally { pending = false; progress.remove(); host()?.removeAttribute('aria-busy'); area?.removeAttribute('aria-busy'); }
}

function startFactsEdit(d, claims, preview) {
    editMode = 'facts'; factsPreview = preview; dirty = true;
    let area = host().querySelector('.context-facts');
    if (!area) {
        area = el('div', 'context-facts p-2.5 rounded-lg bg-[#0a1422] border border-[#172c46] space-y-1.5');
        const body = host().querySelector('.context-detail-body');
        const sources = host().querySelector('.context-sources');
        if (body && sources) body.insertBefore(area, sources);
        else if (body) body.append(area);
        else host().append(area);
    }
    area.replaceChildren();
    const ta = el('textarea', 'context-fact-editor w-full min-h-28 p-2 rounded bg-[#070d17] border border-[#142337] font-mono text-[11px] text-slate-300 leading-relaxed resize-y');
    ta.id = 'context-fact-editor'; ta.rows = 7;
    ta.value = claims.map(c => `[${c.source_id}] ${c.claim}`).join('\n');
    ta.addEventListener('input', () => { dirty = true; });
    area.append(ta, el('p', 'text-[11px] text-slate-500 italic', preview ? 'Preview — review before applying. Saving replaces the full evidence with these facts.' : 'One [source_id] fact per line.'));
    rerenderFooter(d);
    ta.focus();
}

async function saveFacts(d) {
    const ta = host().querySelector('#context-fact-editor'); if (!ta) return;
    const parsed = parseAtomLines(ta.value);
    if (!parsed.length) throw new Error('Add at least one key fact, or cancel.');
    pending = true;
    try {
        const res = await post(factsPreview ? 'commit' : 'edit_atoms', d.id, parsed);
        dirty = false; editMode = null;
        applyContextTokens(res);
        await refreshContextItem(d.id);
        unlock();
    } finally { pending = false; }
}

async function mutate(id, op) {
    if (pending || !await canLeaveContext()) return;
    if (['delete_atoms', 'evict_raw'].includes(op) && !await confirmAction({ title: op === 'delete_atoms' ? 'Delete these key facts?' : 'Exclude full evidence?', message: op === 'delete_atoms' ? 'The extracted key facts will be deleted. Original evidence remains available to restore.' : 'The full source text will stop being sent to the AI. Saved key facts remain active. You can restore the evidence later.', confirmLabel: op === 'delete_atoms' ? 'Delete key facts' : 'Exclude evidence', destructive: true })) return;
    pending = true;
    try {
        const res = await post(op, id); dirty = false;
        applyContextTokens(res);
        await refreshContextItem(id);
        if (op !== 'restore') unlock();
    }
    finally { pending = false; }
}
export const atomizeContextItem = id => runPreview(id, 'atomize');
export const reAtomizeContextItem = id => runPreview(id, 're-atomize');
export const evictRawContextItem = id => mutate(id, 'evict_raw');
export const restoreContextItem = id => mutate(id, 'restore');
export const deleteAtomsContextItem = id => mutate(id, 'delete_atoms');
export async function editAtomsContextItem(id, claims) { await post('edit_atoms', id, claims); dirty = false; return refreshContextItem(id); }
export async function editEvidenceContextItem(id) {
    await viewContextItem(id);
    if (currentData?.id == id && !dirty && !pending) startEvidenceEdit(currentData);
}

function node(tag, text, cls = '') { const e = document.createElement(tag); e.textContent = text; e.className = cls; return e; }

let initialized = false;
function updateExpandButton(expanded) {
    const btn = document.getElementById('context-expand'); if (!btn) return;
    const label = expanded ? 'Compact reading area' : 'Expand reading area';
    btn.setAttribute('aria-label', label); btn.title = label; btn.setAttribute('aria-pressed', String(expanded));
}
export function initContextDataPanel() {
    if (initialized || !panel()) return; initialized = true;
    window.canLeaveContext = canLeaveContext; window.resetContextDetail = resetContextDetail;
    document.getElementById('context-toggle')?.addEventListener('click', () => panel().hidden ? openContextPanel() : closeContext());
    document.getElementById('context-close')?.addEventListener('click', closeContext);
    document.getElementById('context-expand')?.addEventListener('click', () => updateExpandButton(panel().classList.toggle('is-expanded')));
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !panel().hidden && !document.querySelector('dialog[open]')) closeContext(); });
    document.addEventListener('click', e => {
        const btn = e.target.closest('#context-data-items [data-action]'); if (!btn) return;
        const actions = { view: viewContextItem, edit_raw: editEvidenceContextItem, atomize: atomizeContextItem, reatomize: reAtomizeContextItem, restore: restoreContextItem };
        const action = actions[btn.dataset.action]; if (action) withPending(btn, () => action(Number(btn.dataset.id)), { target: panel() });
    });
}
