/** Context inspector. Detached conversation nodes remain isolated while a stream runs. */
import { requestJson, notify, confirmAction, withPending } from '../workspace/feedback.js';
import { ensureAIAvailable, reportBusy, paintAvailability } from '../workspace/availability.js';
import { state } from '../state.js';

let epoch = 0;
let dirty = false;
let pending = false;
let currentData = null;
let returnFocus = null;
const labels = { raw: 'Full evidence', raw_atoms: 'Evidence + key facts', atomized: 'Key facts only', evicted: 'Excluded' };
const panel = () => document.getElementById('context-data-panel');
const host = () => document.getElementById('context-detail-host');
const list = () => document.getElementById('context-data-items');
function stateOf(d) { return d.raw_evicted ? d.atomic_context?.length ? 'atomized' : 'evicted' : d.atomic_context?.length ? 'raw_atoms' : 'raw'; }
function node(tag, text, cls = '') { const el = document.createElement(tag); el.textContent = text; el.className = cls; return el; }
function button(text, fn) { const el = node('button', text, 'ui-button'); el.type = 'button'; el.addEventListener('click', () => withPending(el, fn, { target: host() })); return el; }
const fetchView = id => requestJson(`index.php?view_context=${encodeURIComponent(id)}&ajax=1`);
const post = (op, id, claims) => {
    const body = new URLSearchParams({ action: 'atomize_context', op, id: String(id) });
    if (claims !== undefined) body.set('claims', JSON.stringify(claims));
    return requestJson('index.php', { method: 'POST', body });
};
export async function canLeaveContext() {
    if (pending) { notify('Context is being updated. Wait for this operation to finish.', { target: host() }); return false; }
    return !dirty || await confirmAction({ title: 'Discard these key fact edits?', message: 'Your saved evidence is unchanged. This preview has not been applied.', confirmLabel: 'Discard preview' });
}
export function resetContextDetail() {
    epoch++; dirty = false; currentData = null;
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
    document.getElementById('chat-file-editor-drawer')?.classList.remove('inspector-hidden');
    document.getElementById('context-toggle')?.setAttribute('aria-expanded', 'false');
    if (returnFocus?.isConnected) returnFocus.focus();
}
function count() { const el = document.getElementById('context-data-count'); if (el) el.textContent = list()?.querySelectorAll('.context-item').length || 0; }
function renderRow(id, d, root = list()) {
    const row = root?.querySelector(`.context-item[data-id="${Number(id)}"]`); if (!row) return;
    row.dataset.state = stateOf(d);
    const badge = row.querySelector('.context-badge'); if (badge) badge.textContent = labels[stateOf(d)];
    const meta = row.querySelector('.context-meta');
    if (meta) meta.textContent = `${d.tool_name || 'Source'} · ~${Number(d.raw_evicted ? d.atomic_tokens : d.token_estimate) || 0} active tokens`;
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
    host().hidden = false; list().hidden = true; host().replaceChildren(node('p', 'Loading evidence…', 'text-slate-400'));
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
function factsEditor(d, claims, preview) {
    const area = host().querySelector('.context-atoms'); area.replaceChildren(); dirty = preview;
    const label = node('label', preview ? 'Preview — review before applying' : 'Edit key facts'); label.htmlFor = 'context-fact-editor';
    const ta = node('textarea', ''); ta.id = 'context-fact-editor'; ta.rows = 9;
    ta.value = claims.map(c => `[${c.source_id}] ${c.claim}`).join('\n'); ta.addEventListener('input', () => { dirty = true; });
    const actions = node('div', '', 'flex flex-wrap gap-2');
    actions.append(button(preview ? 'Use key facts instead of full evidence' : 'Save key facts', async () => {
        const parsed = parseAtomLines(ta.value);
        if (!parsed.length) throw new Error('Add at least one key fact, or cancel.');
        pending = true;
        try { await post(preview ? 'commit' : 'edit_atoms', d.id, parsed); dirty = false; await refreshContextItem(d.id); unlock(); }
        finally { pending = false; }
    }), button('Cancel', () => { dirty = false; fill(d); }));
    area.append(label, ta, node('p', 'One [source_id] fact per line. Applying a preview removes the full evidence from the active context; you can restore it later.', 'text-xs text-slate-400'), actions);
    ta.focus();
}
function unlock() {
    state.contextLocked = false;
    const q = document.getElementById('q'); if (q) { q.disabled = false; q.placeholder = 'Message Localsy…'; }
    paintAvailability();
}
async function runPreview(id, op) {
    if (pending || !ensureAIAvailable(host()) || !await canLeaveContext()) return;
    if (currentData?.id != id) await viewContextItem(id);
    if (currentData?.id != id) return;
    const d = currentData; const version = epoch; pending = true;
    const area = host().querySelector('.context-atoms'); area.replaceChildren(node('p', 'Extracting key facts… Your saved evidence is unchanged.', 'text-slate-400')); area.setAttribute('aria-busy', 'true');
    try {
        const res = await post(op, id);
        if (version !== epoch) return;
        if (res.status === 'preview') factsEditor(d, res.claims || [], true);
        else { fill(d); notify(res.message || 'No key facts were found.', { target: host(), kind: 'info' }); }
    } catch (e) { if (version === epoch) { fill(d); notify(e.message, { target: host() }); if (e.code === 'model_busy') reportBusy(e.message); } }
    finally { pending = false; area.removeAttribute('aria-busy'); }
}
async function mutate(id, op) {
    if (pending || !await canLeaveContext()) return;
    if (['delete_atoms', 'evict_raw'].includes(op) && !await confirmAction({ title: op === 'delete_atoms' ? 'Delete these key facts?' : 'Exclude full evidence?', message: op === 'delete_atoms' ? 'The extracted key facts will be deleted. Original evidence remains available to restore.' : 'The full source text will stop being sent to the AI. Saved key facts remain active. You can restore the evidence later.', confirmLabel: op === 'delete_atoms' ? 'Delete key facts' : 'Exclude evidence' })) return;
    pending = true;
    try {
        await post(op, id); dirty = false; await refreshContextItem(id);
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
function fill(d) {
    currentData = d; dirty = false; const root = host(); root.hidden = false; list().hidden = true; root.replaceChildren();
    root.append(button('← All context', async () => { if (await canLeaveContext()) resetContextDetail(); }), node('h3', d.search_query || d.tool_name || 'Context source'), node('p', labels[stateOf(d)], 'context-badge'));
    if (d.sources) for (const [id, source] of Object.entries(d.sources)) {
        const a = node('a', source.title || source.domain || id, 'context-source');
        try { const url = new URL(source.url); if (['http:', 'https:'].includes(url.protocol)) { a.href = url.href; a.target = '_blank'; a.rel = 'noopener noreferrer'; } } catch {}
        root.append(a);
    }
    const raw = node('details', '', 'context-evidence'); raw.append(node('summary', `Full evidence · ~${Number(d.token_estimate) || 0} tokens${d.raw_evicted ? ' · excluded' : ''}`));
    // Evidence is untrusted retrieved text; render it as text, preserving line breaks.
    const text = d.parsed?.length ? d.parsed.map(s => [s.title || s.id, ...(s.chunks || [])].join('\n')).join('\n\n') : d.message;
    raw.append(node('pre', text || 'No source text available.', 'whitespace-pre-wrap break-words')); root.append(raw);
    root.append(node('h4', `Key facts · ~${Number(d.atomic_tokens) || 0} tokens`));
    const atoms = node('div', '', 'context-atoms'); atoms.append(node('pre', d.atomic_context?.length ? d.atomic_context.map(c => `[${c.source_id}] ${c.claim}`).join('\n') : 'No key facts extracted yet.', 'whitespace-pre-wrap break-words')); root.append(atoms);
    const bar = node('div', '', 'flex flex-wrap gap-2');
    bar.append(button(d.atomic_context?.length ? 'Extract again' : 'Extract key facts', () => runPreview(d.id, d.atomic_context?.length ? 're-atomize' : 'atomize')));
    if (d.atomic_context?.length) bar.append(button('Edit key facts', () => factsEditor(d, d.atomic_context, false)), button('Delete key facts', () => mutate(d.id, 'delete_atoms')));
    bar.append(button(d.raw_evicted ? 'Restore full evidence' : 'Exclude full evidence', () => mutate(d.id, d.raw_evicted ? 'restore' : 'evict_raw'))); root.append(bar);
}
let initialized = false;
export function initContextDataPanel() {
    if (initialized || !panel()) return; initialized = true;
    window.canLeaveContext = canLeaveContext; window.resetContextDetail = resetContextDetail;
    document.getElementById('context-toggle')?.addEventListener('click', () => panel().hidden ? openContextPanel() : closeContext());
    document.getElementById('context-close')?.addEventListener('click', closeContext);
    document.getElementById('context-expand')?.addEventListener('click', e => { const expanded = panel().classList.toggle('is-expanded'); e.currentTarget.textContent = expanded ? 'Compact view' : 'Expand'; e.currentTarget.setAttribute('aria-pressed', String(expanded)); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !panel().hidden && !document.querySelector('dialog[open]')) closeContext(); });
    document.addEventListener('click', e => { const btn = e.target.closest('#context-data-items [data-action]'); if (btn) withPending(btn, () => viewContextItem(Number(btn.dataset.id)), { target: panel() }); });
}
