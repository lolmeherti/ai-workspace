/**
 * @file js/chat/chatContextData.js
 * @description Evict/restore, inspect, and live-add retained Context Data items.
 */

export function toggleContextItem(historyId) {
    const item = document.querySelector(`.context-item[data-id="${historyId}"]`);
    if (!item) return;

    fetch(`index.php?toggle_context=${historyId}&ajax=1`)
        .then(response => response.json())
        .then(data => {
            if (data.status !== 'success') return;
            const active = !!data.active_context;
            item.setAttribute('data-active', active ? '1' : '0');

            const badge = item.querySelector('.context-badge');
            if (badge) {
                badge.textContent = active ? 'Active' : 'Evicted';
                badge.className = 'context-badge text-[9px] px-1.5 py-0.5 rounded-full border ' +
                    (active
                        ? 'bg-cyan-500/10 border-cyan-500/20 text-cyan-400'
                        : 'bg-rose-500/10 border-rose-500/20 text-rose-400');
            }

            const btn = item.querySelector('.context-toggle-btn');
            if (btn) btn.textContent = active ? 'Evict' : 'Restore';
        })
        .catch(err => console.error('Failed to toggle context item:', err));
}

export function addContextItem(item) {
    const container = document.getElementById('context-data-items');
    if (!container) return;

    const empty = document.getElementById('context-data-empty');
    if (empty) empty.remove();

    if (container.querySelector(`.context-item[data-id="${item.id}"]`)) return;

    const active = !!item.active;
    const sourceCount = Number(item.source_count) || 0;
    const metaParts = [];
    const toolName = (item.tool_name && item.tool_name.trim() !== '') ? item.tool_name : '';
    if (toolName) {
        metaParts.push(toolName);
    }
    if (sourceCount > 0) {
        metaParts.push(`${sourceCount} source${sourceCount === 1 ? '' : 's'}`);
    }
    metaParts.push(`~${Number(item.token_estimate) || 0} tokens`);
    const label = (item.label && item.label.trim() !== '') ? item.label : 'Context Data';

    const div = document.createElement('div');
    div.className = 'context-item flex items-center gap-3 px-3 py-2 rounded-lg border border-slate-700/40 bg-slate-900/30';
    div.setAttribute('data-id', item.id);
    div.setAttribute('data-active', active ? '1' : '0');

    const textWrap = document.createElement('div');
    textWrap.className = 'flex flex-col min-w-0 flex-1';
    const labelEl = document.createElement('span');
    labelEl.className = 'text-xs text-slate-300 truncate';
    labelEl.textContent = label;
    const metaEl = document.createElement('span');
    metaEl.className = 'text-[10px] text-slate-500 font-mono';
    metaEl.textContent = metaParts.join(' · ');
    textWrap.appendChild(labelEl);
    textWrap.appendChild(metaEl);
    div.appendChild(textWrap);

    const badge = document.createElement('span');
    badge.className = 'context-badge text-[9px] px-1.5 py-0.5 rounded-full border ' +
        (active
            ? 'bg-cyan-500/10 border-cyan-500/20 text-cyan-400'
            : 'bg-rose-500/10 border-rose-500/20 text-rose-400');
    badge.textContent = active ? 'Active' : 'Evicted';
    div.appendChild(badge);

    const viewBtn = document.createElement('button');
    viewBtn.type = 'button';
    viewBtn.className = 'context-view-btn shrink-0 text-[10px] px-2 py-1 rounded border border-slate-700/50 text-slate-400 hover:border-cyan-500/40 hover:text-cyan-400 transition-colors cursor-pointer';
    viewBtn.textContent = 'View';
    viewBtn.addEventListener('click', () => viewContextItem(item.id));
    div.appendChild(viewBtn);

    const toggleBtn = document.createElement('button');
    toggleBtn.type = 'button';
    toggleBtn.className = 'context-toggle-btn shrink-0 text-[10px] px-2 py-1 rounded border border-slate-700/50 text-slate-400 hover:border-rose-500/40 hover:text-rose-400 transition-colors cursor-pointer';
    toggleBtn.textContent = active ? 'Evict' : 'Restore';
    toggleBtn.addEventListener('click', () => toggleContextItem(item.id));
    div.appendChild(toggleBtn);

    container.appendChild(div);
    updateContextCount();
}

export function viewContextItem(historyId) {
    fetch(`index.php?view_context=${historyId}&ajax=1`)
        .then(response => response.json())
        .then(data => {
            if (data.status !== 'success') return;
            showContextModal(data);
        })
        .catch(err => console.error('Failed to view context item:', err));
}

function updateContextCount() {
    const container = document.getElementById('context-data-items');
    const countEl = document.getElementById('context-data-count');
    if (container && countEl) {
        countEl.textContent = String(container.querySelectorAll('.context-item').length);
    }
}

function showContextModal(data) {
    const existing = document.getElementById('context-view-modal');
    if (existing) existing.remove();

    const overlay = document.createElement('div');
    overlay.id = 'context-view-modal';
    overlay.className = 'fixed inset-0 z-[120] flex items-center justify-center bg-[#070b14]/90 backdrop-blur-sm';
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

    const card = document.createElement('div');
    card.className = 'bg-[#0f172a] border border-cyan-500/30 rounded-2xl max-w-3xl w-full max-h-[85vh] flex flex-col overflow-hidden shadow-[0_0_50px_rgba(6,182,212,0.2)]';

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
    body.className = 'flex-1 overflow-y-auto px-5 py-4 space-y-4';

    if (data.sources && typeof data.sources === 'object' && Object.keys(data.sources).length) {
        const srcHeading = document.createElement('div');
        srcHeading.className = 'text-[10px] font-semibold uppercase tracking-wider text-cyan-400';
        srcHeading.textContent = 'Sources';
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

    const contentHeading = document.createElement('div');
    contentHeading.className = 'text-[10px] font-semibold uppercase tracking-wider text-cyan-400';
    contentHeading.textContent = 'Retained Evidence';
    body.appendChild(contentHeading);

    if (data.parsed && Array.isArray(data.parsed) && data.parsed.length) {
        for (const src of data.parsed) {
            const srcWrap = document.createElement('div');
            srcWrap.className = 'space-y-2 border border-slate-800 rounded-lg p-3 bg-slate-900/50';

            const srcTitle = document.createElement('div');
            srcTitle.className = 'text-sm font-semibold text-slate-100';
            srcTitle.textContent = src.title || src.id || 'Source';
            srcWrap.appendChild(srcTitle);

            if (src.domain) {
                const srcDomain = document.createElement('div');
                srcDomain.className = 'text-[10px] text-slate-500 font-mono';
                srcDomain.textContent = src.domain;
                srcWrap.appendChild(srcDomain);
            }

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

            body.appendChild(srcWrap);
        }
    } else {
        const pre = document.createElement('pre');
        pre.className = 'whitespace-pre-wrap break-words text-xs text-slate-300 bg-slate-900/50 border border-slate-800 rounded-lg p-3 font-mono';
        pre.textContent = data.message || '(empty)';
        body.appendChild(pre);
    }

    card.appendChild(body);
    overlay.appendChild(card);
    document.body.appendChild(overlay);
}
