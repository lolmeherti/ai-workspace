/**
 * @file js/jobs/jobInbox.js
 * @description Sidebar category cards -> workspace job list. Pagination + blocks readout.
 */

import { esc, getJson, STATE_LABELS, STATE_ORDER, dateOnly, spinner } from './jobUtil.js';

const PER_PAGE = 10;
let activeCategory = 'unread';
let requestSequence = 0;
let currentPage = 1;
let categoryCounts = {};

const CATEGORY_META = {
    unread:     { icon: 'mail',    iconColor: 'text-slate-400' },
    interested: { icon: 'star',    iconColor: 'text-cyan-400' },
    applied:    { icon: 'send',    iconColor: 'text-blue-400' },
    interview:  { icon: 'user',    iconColor: 'text-amber-400' },
    offer:      { icon: 'check',   iconColor: 'text-emerald-400' },
    history:    { icon: 'history', iconColor: 'text-slate-600' },
};

export function initInbox() {
    document.getElementById('job-stage-select')?.addEventListener('change', e => openCategory(e.target.value));
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
    const cards = document.getElementById('job-cards');
    cards?.setAttribute('aria-busy', 'true');
    if (cards) cards.innerHTML = spinner();
    const title = document.getElementById('job-category-title');
    if (title) title.textContent = STATE_LABELS[state] ?? state;
    let data;
    try {
        data = await getJson('list_jobs', { state, page, per_page: PER_PAGE });
    } catch (e) {
        if (request !== requestSequence) return;
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
    const picker = document.getElementById('job-stage-select');
    if (picker) {
        picker.replaceChildren(...STATE_ORDER.map(state => new Option(`${STATE_LABELS[state]} (${categoryCounts[state] ?? 0})`, state)));
        picker.value = activeCategory;
    }
}

function renderCategoriesError() {
    const el = document.getElementById('job-categories');
    if (!el) return;
    el.innerHTML = '<p class="jobs-empty">Application stages could not be loaded.</p><button type="button" class="ui-button">Retry</button>';
    el.querySelector('button')?.addEventListener('click', refreshCounts);
}

function renderJobsError() {
    const cardsEl = document.getElementById('job-cards');
    if (cardsEl) {
        cardsEl.removeAttribute('aria-busy');
        cardsEl.innerHTML = '<p class="jobs-empty">Jobs could not be loaded. Your saved jobs are kept.</p><button type="button" class="ui-button">Retry</button>';
        cardsEl.querySelector('button')?.addEventListener('click', () => loadCategory(activeCategory, currentPage));
    }
}

function categoryCardHtml(state, count) {
    const meta = CATEGORY_META[state] ?? CATEGORY_META.history;
    const active = state === activeCategory;
    return `
        <button class="job-category-card${active ? ' is-active' : ''}" data-state="${state}" aria-pressed="${active}">
            <span class="job-category-main">
                <uk-icon icon="${meta.icon}" class="job-category-icon ${active ? 'text-cyan-400' : meta.iconColor}"></uk-icon>
                <span class="job-category-label">${esc(STATE_LABELS[state] ?? state)}</span>
            </span>
            <span class="job-category-count${count > 0 ? ' has-count' : ''}">${count}</span>
        </button>`;
}

function cardHtml(job) {
    const meta = job.location ? `${esc(job.company)} · ${esc(job.location)}` : esc(job.company);
    return `
        <div role="button" tabindex="0" aria-label="${esc(job.title)} at ${esc(job.company)}" class="job-card" data-uuid="${esc(job.uuid)}">
            <label class="job-select-label"><input type="checkbox" aria-label="Select ${esc(job.title)}" class="job-select" data-uuid="${esc(job.uuid)}" data-state="${esc(job.state)}"></label>
            <div class="job-card-body">
                <div class="job-card-head"><h3 class="job-card-title">${esc(job.title)}</h3><span class="job-card-chevron" aria-hidden="true">›</span></div>
                <p class="job-card-meta">${meta}</p>
                <div class="job-card-footer">${job.salary ? `<span class="job-card-salary">${esc(job.salary)}</span>` : ''}<span class="job-card-date">${dateOnly(job.posted_at)}</span></div>
            </div>
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
