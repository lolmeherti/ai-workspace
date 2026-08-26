/**
 * @file js/chat/chatContextData.js
 * @description Context Data panel + viewer: per-source Raw / Atomized / Evicted
 * state machine with manual atomize, re-atomize, edit/delete atoms, evict raw,
 * and restore. Drives the raw -> atomized arrow in the viewer modal.
 */

const BADGES = {
    raw:       { text: 'Raw',        cls: 'bg-cyan-500/10 border-cyan-500/20 text-cyan-400' },
    raw_atoms: { text: 'Raw + atoms', cls: 'bg-sky-500/10 border-sky-500/20 text-sky-400' },
    atomized:  { text: 'Atomized',   cls: 'bg-violet-500/10 border-violet-500/20 text-violet-400' },
    evicted:   { text: 'Evicted',    cls: 'bg-rose-500/10 border-rose-500/20 text-rose-400' },
};

function hasAtoms(data) {
    return Array.isArray(data.atomic_context) && data.atomic_context.length > 0;
}

function stateOf(data) {
    const evicted = !!data.raw_evicted;
    const atoms = hasAtoms(data);
    if (evicted) return atoms ? 'atomized' : 'evicted';
    return atoms ? 'raw_atoms' : 'raw';
}

function badgeCls(state) {
    return 'context-badge text-[9px] px-1.5 py-0.5 rounded-full border ' + BADGES[state].cls;
}

function metaFor(data) {
    const parts = [];
    const tool = (data.tool_name && data.tool_name.trim() !== '') ? data.tool_name : '';
    if (tool) parts.push(tool);
    const srcCount = data.sources
        ? Object.keys(data.sources).length
        : (Number(data.source_count) || 0);
    if (srcCount > 0) parts.push(`${srcCount} source${srcCount === 1 ? '' : 's'}`);
    parts.push(`raw ~${Number(data.token_estimate) || 0}`);
    if (hasAtoms(data)) parts.push(`atoms ~${Number(data.atomic_tokens) || 0}`);
    return parts.join(' · ');
}

/** POST helper for the atomize_context action. */
async function postAtomize(op, id, claims) {
    const body = new URLSearchParams({ action: 'atomize_context', op, id: String(id) });
    if (claims !== undefined) body.set('claims', JSON.stringify(claims));
    const resp = await fetch('index.php', { method: 'POST', body });
    return resp.json();
}

async function fetchView(id) {
    const resp = await fetch(`index.php?view_context=${id}&ajax=1`);
    return resp.json();
}

/** Re-render a panel row from fresh view data. */
function renderRow(id, data) {
    const item = document.querySelector(`.context-item[data-id="${id}"]`);
    if (!item) return;

    const state = stateOf(data);
    item.setAttribute('data-state', state);

    const badge = item.querySelector('.context-badge');
    if (badge) {
        badge.textContent = BADGES[state].text;
        badge.className = badgeCls(state);
        if (state === 'evicted') badge.title = 'This raw data is not part of the chat anymore. Restore loads the full data back in.';
    }

    const meta = item.querySelector('.context-meta');
    if (meta) meta.textContent = metaFor(data);

    const btnWrap = item.querySelector('.context-btns');
    if (btnWrap) {
        btnWrap.innerHTML = '';
        btnWrap.appendChild(actionButton('view', 'View', id, state));
        if (state === 'raw') {
            btnWrap.appendChild(actionButton('atomize', 'Atomize', id, state));
        } else if (state === 'raw_atoms' || state === 'atomized') {
            btnWrap.appendChild(actionButton('reatomize', 'Re-atomize', id, state));
            btnWrap.appendChild(actionButton('delete_atoms', 'Delete atoms', id, state));
        }
        btnWrap.appendChild(actionButton(
            state === 'raw' || state === 'raw_atoms' ? 'evict_raw' : 'restore',
            state === 'raw' || state === 'raw_atoms' ? 'Evict raw' : 'Restore',
            id, state
        ));
    }
}

function actionButton(action, label, id, state) {
    const btn = document.createElement('button');
    btn.type = 'button';
    const isEvict = action === 'evict_raw';
    const isRestore = action === 'restore';
    btn.className = 'context-action-btn shrink-0 text-[10px] px-2 py-1 rounded border border-slate-700/50 text-slate-400 transition-colors cursor-pointer ' +
        (isEvict ? 'hover:border-rose-500/40 hover:text-rose-400' :
         isRestore ? 'hover:border-cyan-500/40 hover:text-cyan-400' :
         'hover:border-cyan-500/40 hover:text-cyan-400');
    btn.textContent = label;
    btn.setAttribute('data-action', action);
    btn.setAttribute('data-id', String(id));
    return btn;
}

export async function refreshContextItem(id) {
    const data = await fetchView(id);
    if (data.status !== 'success') return;
    renderRow(id, data);
    // If the viewer modal for this id is open, re-render it in place.
    const modal = document.getElementById('context-view-modal');
    if (modal && modal.getAttribute('data-id') === String(id)) {
        fillModalBody(modal, data);
    }
    return data;
}

/** Live-add a freshly retrieved Context Data row (SSE context_data_added). */
export function addContextItem(item) {
    const container = document.getElementById('context-data-items');
    if (!container) return;

    const empty = document.getElementById('context-data-empty');
    if (empty) empty.remove();

    if (container.querySelector(`.context-item[data-id="${item.id}"]`)) return;

    const data = {
        id: item.id,
        raw_evicted: false,
        atomic_context: null,
        atomic_tokens: 0,
        token_estimate: Number(item.token_estimate) || 0,
        tool_name: item.tool_name || '',
        search_query: item.query || '',
        sources: null,
        source_count: Number(item.source_count) || 0,
    };

    const div = document.createElement('div');
    div.className = 'context-item flex items-center gap-3 px-3 py-2 rounded-lg border border-slate-700/40 bg-slate-900/30';
    div.setAttribute('data-id', item.id);
    div.appendChild(buildRowInner(data));
    container.appendChild(div);
    updateContextCount();
}

/** Build the inner markup of a row (shared by server render + JS render). */
function buildRowInner(data) {
    const state = stateOf(data);
    const label = (data.search_query && data.search_query.trim() !== '')
        ? data.search_query : ((data.tool_name && data.tool_name.trim() !== '') ? data.tool_name : 'Context Data');

    const wrap = document.createElement('div');
    wrap.className = 'flex flex-col min-w-0 flex-1';

    const labelEl = document.createElement('span');
    labelEl.className = 'text-xs text-slate-300 truncate';
    labelEl.textContent = label;
    wrap.appendChild(labelEl);

    const metaEl = document.createElement('span');
    metaEl.className = 'context-meta text-[10px] text-slate-500 font-mono';
    metaEl.textContent = metaFor(data);
    wrap.appendChild(metaEl);

    const badge = document.createElement('span');
    badge.className = badgeCls(state);
    badge.textContent = BADGES[state].text;
    if (state === 'evicted') badge.title = 'This raw data is not part of the chat anymore. Restore loads the full data back in.';

    const btnWrap = document.createElement('div');
    btnWrap.className = 'context-btns flex items-center gap-1.5';
    btnWrap.appendChild(actionButton('view', 'View', data.id, state));
    if (state === 'raw') {
        btnWrap.appendChild(actionButton('atomize', 'Atomize', data.id, state));
    } else if (state === 'raw_atoms' || state === 'atomized') {
        btnWrap.appendChild(actionButton('reatomize', 'Re-atomize', data.id, state));
        btnWrap.appendChild(actionButton('delete_atoms', 'Delete atoms', data.id, state));
    }
    btnWrap.appendChild(actionButton(
        state === 'raw' || state === 'raw_atoms' ? 'evict_raw' : 'restore',
        state === 'raw' || state === 'raw_atoms' ? 'Evict raw' : 'Restore',
        data.id, state
    ));

    const frag = document.createDocumentFragment();
    frag.appendChild(wrap);
    frag.appendChild(badge);
    frag.appendChild(btnWrap);
    return frag;
}

function updateContextCount() {
    const container = document.getElementById('context-data-items');
    const countEl = document.getElementById('context-data-count');
    if (container && countEl) {
        countEl.textContent = String(container.querySelectorAll('.context-item').length);
    }
}

// ---------------------------------------------------------------------------
// Row + viewer actions
// ---------------------------------------------------------------------------

export async function viewContextItem(id) {
    const data = await fetchView(id);
    if (data.status !== 'success') return;
    showContextModal(data);
}

export async function atomizeContextItem(id) {
    // Atomize produces a preview in the modal (no commit until Done).
    await viewContextItem(id);
    runPreview(id, 'atomize');
}

export async function reAtomizeContextItem(id) {
    runPreview(id, 're-atomize');
}

export async function evictRawContextItem(id) {
    await postAtomize('evict_raw', id);
    await refreshContextItem(id);
}

export async function restoreContextItem(id) {
    await postAtomize('restore', id);
    await refreshContextItem(id);
}

export async function deleteAtomsContextItem(id) {
    await postAtomize('delete_atoms', id);
    await refreshContextItem(id);
}

export async function editAtomsContextItem(id, claims) {
    await postAtomize('edit_atoms', id, claims);
    await refreshContextItem(id);
}

/** Kick off the atomize/re-atomize preview inside an open modal. */
async function runPreview(id, op) {
    const modal = document.getElementById('context-view-modal');
    if (!modal) {
        const data = await fetchView(id);
        if (data.status !== 'success') return;
        showContextModal(data);
    }
    const m = document.getElementById('context-view-modal');
    const atoms = m.querySelector('.context-atoms');
    if (!atoms) return;

    atoms.innerHTML = '';
    const spinner = document.createElement('div');
    spinner.className = 'flex items-center gap-2 text-xs text-violet-300';
    spinner.innerHTML = '<span class="uk-spinner uk-spinner-xs animate-spin" uk-spinner="ratio: 0.5"></span> Condensing raw evidence...';
    atoms.appendChild(spinner);

    const res = await postAtomize(op, id);
    if (res.status === 'preview') {
        renderPreview(atoms, id, res.claims, res.atom_tokens);
    } else if (res.status === 'empty') {
        atoms.innerHTML = '';
        const note = document.createElement('p');
        note.className = 'text-xs text-slate-500 italic';
        note.textContent = res.message || 'No durable facts could be extracted.';
        atoms.appendChild(note);
    } else {
        atoms.innerHTML = '';
        const err = document.createElement('p');
        err.className = 'text-xs text-rose-400';
        err.textContent = (res && res.message) ? res.message : 'Atomization failed.';
        atoms.appendChild(err);
    }
}

function renderPreview(atoms, id, claims, atomTokens) {
    atoms.innerHTML = '';
    const ta = document.createElement('textarea');
    ta.className = 'w-full h-40 text-xs text-slate-200 bg-slate-950/60 border border-slate-700/50 rounded-lg p-2 font-mono leading-relaxed';
    ta.value = claims.map(c => `[${c.source_id}] ${c.claim}`).join('\n');
    atoms.appendChild(ta);

    const meta = document.createElement('div');
    meta.className = 'text-[10px] text-slate-500 font-mono mt-1';
    meta.textContent = `preview · ~${Number(atomTokens) || 0} tokens`;
    atoms.appendChild(meta);

    const bar = document.createElement('div');
    bar.className = 'flex items-center gap-2 mt-2';
    const done = document.createElement('button');
    done.type = 'button';
    done.className = 'text-[10px] px-2 py-1 rounded border border-emerald-500/40 text-emerald-400 hover:bg-emerald-500/10 cursor-pointer';
    done.textContent = 'Done (atomize + evict raw)';
    done.addEventListener('click', async () => {
        done.disabled = true;
        await postAtomize('commit', id, parseAtomLines(ta.value));
        done.disabled = false;
        await refreshContextItem(id);
    });
    bar.appendChild(done);

    const again = document.createElement('button');
    again.type = 'button';
    again.className = 'text-[10px] px-2 py-1 rounded border border-slate-700/50 text-slate-400 hover:border-cyan-500/40 hover:text-cyan-400 cursor-pointer';
    again.textContent = 'Re-atomize';
    again.addEventListener('click', () => runPreview(id, 're-atomize'));
    bar.appendChild(again);

    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'text-[10px] px-2 py-1 rounded border border-slate-700/50 text-slate-400 hover:border-rose-500/40 hover:text-rose-400 cursor-pointer';
    cancel.textContent = 'Cancel';
    cancel.addEventListener('click', () => refreshContextItem(id));
    bar.appendChild(cancel);

    atoms.appendChild(bar);
}

function parseAtomLines(text) {
    const claims = [];
    for (const line of (text || '').split('\n')) {
        const t = line.trim();
        if (!t) continue;
        const m = t.match(/^\[([^\]]+)\]\s*(.+)$/);
        if (m) claims.push({ source_id: m[1], claim: m[2] });
    }
    return claims;
}

// ---------------------------------------------------------------------------
// Viewer modal (raw -> atomized arrow)
// ---------------------------------------------------------------------------

function showContextModal(data) {
    const existing = document.getElementById('context-view-modal');
    if (existing) existing.remove();

    const overlay = document.createElement('div');
    overlay.id = 'context-view-modal';
    overlay.setAttribute('data-id', String(data.id));
    overlay.className = 'fixed inset-0 z-[120] flex items-center justify-center bg-[#070b14]/90 backdrop-blur-sm';
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

    const card = document.createElement('div');
    card.className = 'context-modal-card bg-[#0f172a] border border-cyan-500/30 rounded-2xl max-w-3xl w-full max-h-[85vh] flex flex-col overflow-hidden shadow-[0_0_50px_rgba(6,182,212,0.2)]';

    const header = document.createElement('div');
    header.className = 'flex items-center justify-between px-5 py-3 border-b border-cyan-500/20 shrink-0';
    const title = document.createElement('span');
    title.className = 'text-sm font-semibold text-slate-100 truncate';
    const modalTool = (data.tool_name && data.tool_name.trim() !== '') ? data.tool_name : 'Context Data';
    const modalQuery = (data.search_query && data.search_query.trim() !== '') ? data.search_query : '';
    title.textContent = modalQuery !== '' ? `${modalTool}: ${modalQuery}` : modalTool;
    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'text-slate-400 hover:text-rose-400 text-xl leading-none px-2 cursor-pointer bg-transparent border-none';
    closeBtn.textContent = '\u00d7';
    closeBtn.addEventListener('click', () => overlay.remove());
    header.appendChild(title);
    header.appendChild(closeBtn);
    card.appendChild(header);

    const body = document.createElement('div');
    body.className = 'context-modal-body flex-1 overflow-y-auto px-5 py-4 space-y-4';
    card.appendChild(body);

    overlay.appendChild(card);
    document.body.appendChild(overlay);

    fillModalBody(overlay, data);
}

function fillModalBody(overlay, data) {
    const card = overlay.querySelector('.context-modal-card');
    const body = card.querySelector('.context-modal-body');
    body.innerHTML = '';

    if (data.sources && typeof data.sources === 'object' && Object.keys(data.sources).length) {
        const srcHeading = heading('Sources');
        body.appendChild(srcHeading);
        for (const [id, s] of Object.entries(data.sources)) {
            const a = document.createElement('a');
            a.href = s.url || '#';
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.className = 'flex flex-col py-1.5 border-b border-slate-800/60';
            const t = document.createElement('span');
            t.className = 'text-xs text-slate-300 truncate';
            t.textContent = s.title || s.domain || s.url || id;
            a.appendChild(t);
            if (s.domain && s.domain !== (s.title || '')) {
                const d = document.createElement('span');
                d.className = 'text-[10px] text-slate-500 font-mono truncate';
                d.textContent = s.domain;
                a.appendChild(d);
            }
            body.appendChild(a);
        }
    }

    // div1 = raw
    const state = stateOf(data);
    const rawHeading = document.createElement('div');
    rawHeading.className = 'flex items-center gap-2';
    const rawTitle = heading('Raw Evidence');
    rawHeading.appendChild(rawTitle);
    const rawTok = document.createElement('span');
    rawTok.className = 'text-[10px] text-slate-500 font-mono';
    rawTok.textContent = `~${Number(data.token_estimate) || 0} tokens`;
    rawHeading.appendChild(rawTok);
    if (state === 'atomized' || state === 'evicted') {
        const evBadge = document.createElement('span');
        evBadge.className = badgeCls('evicted');
        evBadge.textContent = 'Evicted';
        evBadge.title = 'This raw data is not part of the chat anymore. Restore loads the full data back in.';
        rawHeading.appendChild(evBadge);
    }
    body.appendChild(rawHeading);

    const rawBox = document.createElement('div');
    rawBox.className = 'space-y-2 border border-slate-800 rounded-lg p-3 bg-slate-900/50 max-h-48 overflow-y-auto';
    if (data.parsed && Array.isArray(data.parsed) && data.parsed.length) {
        for (const src of data.parsed) {
            const srcWrap = document.createElement('div');
            srcWrap.className = 'space-y-1';
            const srcTitle = document.createElement('div');
            srcTitle.className = 'text-sm font-semibold text-slate-100';
            srcTitle.textContent = src.title || src.id || 'Source';
            srcWrap.appendChild(srcTitle);
            for (const chunk of (src.chunks || [])) {
                const chunkEl = document.createElement('div');
                chunkEl.className = 'text-xs text-slate-300 leading-relaxed';
                if (typeof marked !== 'undefined') {
                    chunkEl.innerHTML = marked.parse(chunk);
                } else {
                    chunkEl.textContent = chunk;
                }
                srcWrap.appendChild(chunkEl);
            }
            rawBox.appendChild(srcWrap);
        }
    } else {
        const pre = document.createElement('pre');
        pre.className = 'whitespace-pre-wrap break-words text-xs text-slate-300 font-mono';
        pre.textContent = data.message || '(empty)';
        rawBox.appendChild(pre);
    }
    body.appendChild(rawBox);

    // arrow
    const arrow = document.createElement('div');
    arrow.className = 'flex items-center justify-center gap-2 text-slate-600 text-[10px] font-mono uppercase tracking-wider';
    arrow.textContent = 'raw \u2192 atomized';
    body.appendChild(arrow);

    // div2 = atoms
    const atoms = document.createElement('div');
    atoms.className = 'context-atoms space-y-2 border border-slate-800 rounded-lg p-3 bg-slate-900/50';
    const atomsHeading = document.createElement('div');
    atomsHeading.className = 'flex items-center gap-2';
    const atomsTitle = heading('Atomized');
    atomsHeading.appendChild(atomsTitle);
    if (hasAtoms(data)) {
        const atomTok = document.createElement('span');
        atomTok.className = 'text-[10px] text-slate-500 font-mono';
        atomTok.textContent = `~${Number(data.atomic_tokens) || 0} tokens`;
        atomsHeading.appendChild(atomTok);

        const editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'text-[10px] px-2 py-1 rounded border border-slate-700/50 text-slate-400 hover:border-cyan-500/40 hover:text-cyan-400 cursor-pointer';
        editBtn.textContent = 'Edit';
        let editing = false;
        editBtn.addEventListener('click', async () => {
            if (!editing) {
                editing = true;
                const pre = atoms.querySelector('pre');
                const ta = document.createElement('textarea');
                ta.className = 'w-full h-40 text-xs text-slate-200 bg-slate-950/60 border border-slate-700/50 rounded-lg p-2 font-mono leading-relaxed';
                ta.value = data.atomic_context.map(c => `[${c.source_id}] ${c.claim}`).join('\n');
                if (pre) pre.replaceWith(ta);
                editBtn.textContent = 'Done';
            } else {
                const ta = atoms.querySelector('textarea');
                await editAtomsContextItem(data.id, parseAtomLines(ta.value));
            }
        });
        atomsHeading.appendChild(editBtn);
    }
    atoms.appendChild(atomsHeading);

    if (hasAtoms(data)) {
        const pre = document.createElement('pre');
        pre.className = 'whitespace-pre-wrap break-words text-xs text-slate-300 font-mono leading-relaxed';
        pre.textContent = data.atomic_context.map(c => `[${c.source_id}] ${c.claim}`).join('\n');
        atoms.appendChild(pre);
    } else {
        const p = document.createElement('p');
        p.className = 'text-xs text-slate-600 italic';
        p.textContent = 'Not atomized yet.';
        atoms.appendChild(p);
    }
    body.appendChild(atoms);

    // control bar
    const bar = document.createElement('div');
    bar.className = 'flex items-center gap-2 pt-1 border-t border-slate-800';
    if (state === 'raw') {
        bar.appendChild(actionButton('atomize', 'Atomize', data.id, state));
    } else if (state === 'raw_atoms' || state === 'atomized') {
        bar.appendChild(actionButton('reatomize', 'Re-atomize', data.id, state));
        bar.appendChild(actionButton('delete_atoms', 'Delete atoms', data.id, state));
    }
    bar.appendChild(actionButton(
        state === 'raw' || state === 'raw_atoms' ? 'evict_raw' : 'restore',
        state === 'raw' || state === 'raw_atoms' ? 'Evict raw context' : 'Restore',
        data.id, state
    ));
    body.appendChild(bar);
}

function heading(text) {
    const h = document.createElement('div');
    h.className = 'text-[10px] font-semibold uppercase tracking-wider text-cyan-400';
    h.textContent = text;
    return h;
}

// ---------------------------------------------------------------------------
// Delegated click handling (server-rendered + JS-rendered rows share it)
// ---------------------------------------------------------------------------

export function initContextDataPanel() {
    const container = document.getElementById('context-data-items');
    if (!container || container.dataset.contextBound) return;
    container.dataset.contextBound = '1';

    container.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const id = Number(btn.getAttribute('data-id'));
        const action = btn.getAttribute('data-action');
        switch (action) {
            case 'view':         viewContextItem(id); break;
            case 'atomize':      atomizeContextItem(id); break;
            case 'reatomize':    reAtomizeContextItem(id); break;
            case 'delete_atoms': deleteAtomsContextItem(id); break;
            case 'evict_raw':    evictRawContextItem(id); break;
            case 'restore':      restoreContextItem(id); break;
        }
    });

    // The viewer modal itself also hosts action buttons.
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('#context-view-modal [data-action]');
        if (!btn) return;
        const id = Number(btn.getAttribute('data-id'));
        const action = btn.getAttribute('data-action');
        switch (action) {
            case 'atomize':      runPreview(id, 'atomize'); break;
            case 'reatomize':    runPreview(id, 're-atomize'); break;
            case 'delete_atoms': deleteAtomsContextItem(id); break;
            case 'evict_raw':    evictRawContextItem(id); break;
            case 'restore':      restoreContextItem(id); break;
        }
    });
}
