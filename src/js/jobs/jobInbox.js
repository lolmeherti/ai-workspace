/**
 * @file js/jobs/jobInbox.js
 * @description Sidebar category cards -> workspace job list. Pagination + blocks readout.
 */

import { esc, getJson, STATE_LABELS, STATE_ORDER, dateOnly } from './jobUtil.js';

const PER_PAGE = 10;
let activeCategory = 'unread';
let requestSequence = 0;
let currentPage = 1;
let categoryCounts = {};

const CATEGORY_META = {
    unread:     { icon: 'mail',    text: 'text-slate-300',   border: 'border-slate-700',      hover: 'hover:border-slate-500',      iconColor: 'text-slate-400' },
    interested: { icon: 'star',    text: 'text-cyan-400',    border: 'border-cyan-500/30',    hover: 'hover:border-cyan-500/60',    iconColor: 'text-cyan-400' },
    applied:    { icon: 'send',    text: 'text-blue-400',    border: 'border-blue-500/30',    hover: 'hover:border-blue-500/60',    iconColor: 'text-blue-400' },
    interview:  { icon: 'user',    text: 'text-amber-400',   border: 'border-amber-500/30',   hover: 'hover:border-amber-500/60',   iconColor: 'text-amber-400' },
    offer:      { icon: 'check',   text: 'text-emerald-400', border: 'border-emerald-500/30', hover: 'hover:border-emerald-500/60', iconColor: 'text-emerald-400' },
    history:    { icon: 'history', text: 'text-slate-500',   border: 'border-slate-800',      hover: 'hover:border-slate-600',      iconColor: 'text-slate-600' },
};

export function initInbox() {
    const categoriesContainer = document.getElementById('job-categories');
    if (categoriesContainer) {
        categoriesContainer.addEventListener('click', (e) => {
            const catCard = e.target.closest('.job-category-card');
            if (catCard) openCategory(catCard.dataset.state);
        });
    }

    const listContainer = document.getElementById('job-list-view');
    if (listContainer) {
        listContainer.addEventListener('keydown', e => { if (e.target.matches('.job-card') && ['Enter', ' '].includes(e.key)) { e.preventDefault(); window.selectJob(e.target.dataset.uuid); } });
        listContainer.addEventListener('click', (e) => {
            const pageBtn = e.target.closest('.job-page-btn');
            if (pageBtn) {
                loadCategory(activeCategory, parseInt(pageBtn.dataset.page, 10));
                return;
            }
            if (e.target.closest('.job-select-label')) return;
            const card = e.target.closest('.job-card');
            if (card) window.selectJob(card.dataset.uuid);
        });
        listContainer.addEventListener('change', (e) => {
            if (e.target.classList.contains('job-select')) {
                window.onJobSelectChange(e.target);
            }
        });
    }
}

export async function loadInbox() {
    await refreshCounts();
    loadBlocks();
}

export async function refreshInbox() {
    await refreshCounts();
}

async function refreshCounts() {
    let data;
    try {
        data = await getJson('list_jobs');
    } catch (e) {
        renderCategoriesError();
        return;
    }

    if (data.status !== 'success') {
        renderCategoriesError();
        return;
    }

    categoryCounts = data.counts ?? {};
    renderCategories();

    if (activeCategory) {
        await loadCategory(activeCategory, currentPage);
    }

    window.syncSelection?.();
}

function openCategory(state) {
    activeCategory = state;
    currentPage = 1;
    renderCategories();
    loadCategory(state, 1);
}

async function loadCategory(state, page) {
    if (!state) return;

    const request = ++requestSequence;
    document.getElementById('job-cards')?.setAttribute('aria-busy', 'true');
    let data;
    try {
        data = await getJson('list_jobs', { state, page, per_page: PER_PAGE });
    } catch (e) {
        renderJobsError();
        return;
    }

    if (request !== requestSequence) return;
    document.getElementById('job-cards')?.removeAttribute('aria-busy');
    if (data.status !== 'success') {
        renderJobsError();
        return;
    }

    const lastPage = Math.max(1, Math.ceil(data.total / PER_PAGE));
    if (page > lastPage) return loadCategory(state, lastPage);
    currentPage = page;

    const titleEl = document.getElementById('job-category-title');
    if (titleEl) titleEl.textContent = STATE_LABELS[state] ?? state;

    const countEl = document.getElementById('job-list-count');
    if (countEl) countEl.textContent = `${data.total} job${data.total === 1 ? '' : 's'}`;

    const cardsEl = document.getElementById('job-cards');
    if (cardsEl) {
        cardsEl.innerHTML = data.jobs.length === 0
            ? '<div class="text-center py-10 text-slate-600 text-xs normal-case tracking-normal font-bold select-none">No jobs in this category</div>'
            : data.jobs.map(cardHtml).join('');
    }

    renderPagination(page, data.total);
    window.syncSelection?.();
    window.paintJobSelection?.();
    document.dispatchEvent(new Event('workspace-content-ready'));
}

function renderCategories() {
    const el = document.getElementById('job-categories');
    if (!el) return;
    el.innerHTML = STATE_ORDER.map(state => categoryCardHtml(state, categoryCounts[state] ?? 0)).join('');
}

function renderCategoriesError() {
    const el = document.getElementById('job-categories');
    if (!el) return;
    el.innerHTML = '<p class="text-rose-400 text-xs normal-case font-bold text-center py-8">Failed to load categories.</p>';
}

function renderJobsError() {
    const cardsEl = document.getElementById('job-cards');
    if (cardsEl) cardsEl.innerHTML = '<div class="text-center py-10 text-rose-400 text-xs normal-case tracking-normal font-bold select-none">Failed to load jobs.</div>';
}

function categoryCardHtml(state, count) {
    const meta = CATEGORY_META[state] ?? CATEGORY_META.history;
    const active = state === activeCategory;
    const border = active ? 'border-cyan-400/70 bg-cyan-500/[0.07]' : `${meta.border} bg-[#0a0f1d]/60`;
    return `
        <button class="job-category-card w-full flex items-center justify-between gap-2 px-3 py-2.5 rounded-lg border ${border} ${meta.hover} transition-colors cursor-pointer outline-none text-left" data-state="${state}" aria-pressed="${active}">
            <span class="flex items-center gap-2.5 min-w-0">
                <uk-icon icon="${meta.icon}" class="w-4 h-4 shrink-0 ${active ? 'text-cyan-400' : meta.iconColor}"></uk-icon>
                <span class="text-xs font-bold normal-case tracking-normal ${meta.text} truncate">${esc(STATE_LABELS[state] ?? state)}</span>
            </span>
            <span class="px-2 py-0.5 rounded-full text-xs font-extrabold tabular-nums border shrink-0 ${count > 0 ? 'bg-cyan-950/60 text-cyan-400 border-cyan-500/30' : 'bg-slate-900 text-slate-600 border-slate-800'}">${count}</span>
        </button>`;
}

const STATE_ACCENT = {
    unread: 'bg-slate-500/60',
    interested: 'bg-cyan-500/70',
    applied: 'bg-blue-500/70',
    interview: 'bg-amber-500/70',
    offer: 'bg-emerald-500/70',
    history: 'bg-slate-600/60',
};

function cardHtml(job) {
    const accent = STATE_ACCENT[job.state] ?? 'bg-cyan-500/60';
    const meta = job.location ? `${esc(job.company)} · ${esc(job.location)}` : esc(job.company);
    return `
        <div role="button" tabindex="0" aria-label="${esc(job.title)} at ${esc(job.company)}" class="job-card group relative flex items-center gap-2.5 pl-4 pr-2.5 py-2.5 rounded-xl border border-slate-800/70 bg-gradient-to-r from-[#0d1424]/80 to-[#0a0f1d]/80 hover:border-cyan-500/30 hover:bg-[#0e1728]/60 cursor-pointer transition-all overflow-hidden" data-uuid="${esc(job.uuid)}">
            <span class="absolute left-0 top-0 h-full w-[3px] ${accent}"></span>
            <label class="job-select-label relative inline-flex items-center justify-center w-5 h-5 rounded-full cursor-pointer shrink-0 select-none">
                <input type="checkbox" aria-label="Select ${esc(job.title)}" class="job-select peer sr-only" data-uuid="${esc(job.uuid)}" data-state="${esc(job.state)}">
                <span class="absolute inset-0 rounded-full border border-slate-700 bg-slate-900/40 group-hover:border-cyan-500/40 transition-all duration-200 peer-checked:border-cyan-400 peer-checked:bg-cyan-500/15 peer-checked:shadow-[0_0_12px_rgba(6,182,212,0.45)]"></span>
                <svg class="relative w-3 h-3 text-cyan-300 opacity-0 scale-50 transition-all duration-200 peer-checked:opacity-100 peer-checked:scale-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </label>
            <div class="min-w-0 flex-1">
                <div class="text-xs font-semibold text-slate-100 leading-snug truncate">${esc(job.title)}</div>
                <div class="text-xs text-slate-400 mt-0.5 truncate">${meta}</div>
                <div class="flex items-center gap-1.5 mt-1">
                    <span class="text-xs text-slate-500 font-mono shrink-0">${dateOnly(job.posted_at)}</span>
                    ${job.salary ? `<span class="text-slate-600 shrink-0">·</span><span class="text-xs font-medium text-emerald-400/80 min-w-0 truncate">${esc(job.salary)}</span>` : ''}
                </div>
            </div>
            <span class="text-slate-600 group-hover:text-cyan-400 transition-colors shrink-0">›</span>
        </div>`;
}

function renderPagination(page, total) {
    const el = document.getElementById('job-pagination');
    if (!el) return;
    const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));
    if (totalPages <= 1) {
        el.innerHTML = '';
        return;
    }
    el.innerHTML = `
        <div class="flex items-center justify-between px-1 py-1 text-xs text-slate-500">
            <button class="job-page-btn hover:text-cyan-400 cursor-pointer outline-none ${page <= 1 ? 'opacity-30 pointer-events-none' : ''}" ${page <= 1 ? 'disabled' : ''} data-page="${page - 1}">‹ Prev</button>
            <span>${page} / ${totalPages}</span>
            <button class="job-page-btn hover:text-cyan-400 cursor-pointer outline-none ${page >= totalPages ? 'opacity-30 pointer-events-none' : ''}" ${page >= totalPages ? 'disabled' : ''} data-page="${page + 1}">Next ›</button>
        </div>`;
}

async function loadBlocks() {
    const el = document.getElementById('job-blocks-readout');
    if (!el) return;
    const data = await getJson('get_blocks');
    if (data.status !== 'success' || !data.blocks || data.blocks.length === 0) {
        el.classList.add('hidden');
        return;
    }
    const text = data.blocks.map(b => `${b.kind === 'domain' ? 'Domain' : 'Company'}: ${b.value}`).join(' · ');
    el.innerHTML = `<span class="normal-case tracking-normal font-bold text-slate-600">Blocked</span> <span class="text-slate-500">${esc(text)}</span>`;
    el.classList.remove('hidden');
}
