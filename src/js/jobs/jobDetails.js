import { confirmAction, withPending } from '../workspace/feedback.js';
/**
 * @file js/jobs/jobDetails.js
 * @description Right-pane job details: editable record, AI comment, description, actions.
 */

import { esc, getJson, postJson, flash, spinner, STATE_LABELS, fmtDate, toLocalInput } from './jobUtil.js';
import { switchJobView } from './jobViews.js';
import { addCodeCopyButtons } from '../markdown.js';

let currentJobUuid = null;
let currentJob = null;
let requestSequence = 0;
export const selectedJobId = () => currentJobUuid;
export function paintJobSelection() {
    document.querySelectorAll('.job-card').forEach(card => { const active = card.dataset.uuid === currentJobUuid; card.classList.toggle('is-current', active); card.setAttribute('aria-pressed', String(active)); });
    document.querySelector('.jobs-results')?.classList.toggle('has-selection', !!currentJobUuid);
}
async function canLeaveJob() {
    const container = document.getElementById('job-details-container');
    if (container.querySelector('[aria-busy="true"]')) return false;
    return !container.querySelector('form[data-dirty="true"]') || await confirmAction('Discard unsaved changes to this job?', { confirmLabel: 'Discard changes', destructive: true });
}

const inputCls = 'w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 outline-none focus:border-cyan-500/40 transition-colors';
const textareaCls = 'w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2.5 text-slate-200 outline-none focus:border-cyan-500/40 transition-colors leading-relaxed';

export function initDetails() {
    const container = document.getElementById('job-details-container');
    container.addEventListener('click', onDetailsClick);
    container.addEventListener('submit', onDetailsSubmit);
    container.addEventListener('input', e => { const form = e.target.closest('form'); if (form) form.dataset.dirty = 'true'; });
    document.getElementById('job-details-back')?.addEventListener('click', async () => { if (await canLeaveJob()) clearDetails(); });
    window.paintJobSelection = paintJobSelection;
    window.selectJob = selectJob;
    window.cancelApply = cancelApply;
}

export async function selectJob(uuid) {
    if (uuid === currentJobUuid || !await canLeaveJob()) return;
    switchJobView('details');
    currentJobUuid = uuid;
    paintJobSelection();
    await renderJob();
}

export function clearDetails() {
    currentJobUuid = null; currentJob = null; requestSequence++; paintJobSelection();
    document.querySelector('.jobs-results')?.classList.remove('has-activity', 'has-selection');
    switchJobView('details');
    const container = document.getElementById('job-details-container');
    if (container) container.innerHTML = placeholder('Select a job to view its details.');
}

export async function refreshDetails() {
    if (currentJobUuid && !document.querySelector('#job-edit-form[data-dirty="true"]')) await renderJob();
}

async function renderJob() {
    const container = document.getElementById('job-details-container');
    if (!container || !currentJobUuid) return;
    const request = ++requestSequence;
    const id = currentJobUuid;
    container.innerHTML = spinner();

    const data = await getJson('get_job', { uuid: currentJobUuid });
    if (request !== requestSequence || id !== currentJobUuid) return;
    if (data.status !== 'success') {
        container.innerHTML = placeholder(data.message || 'Unable to load this job.') + '<button type="button" class="ui-button" id="job-retry">Retry</button>';
        container.querySelector('#job-retry').addEventListener('click', renderJob);
        return;
    }
    currentJob = data.job;
    showReadView();
}

function placeholder(msg) {
    return `<div class="h-full flex flex-col items-center justify-center gap-3 text-slate-600 select-none">
        <uk-icon icon="briefcase" class="w-10 h-12 text-slate-700 opacity-30"></uk-icon>
        <p class="text-xs tracking-normal normal-case font-bold">${esc(msg)}</p>
    </div>`;
}

function field(label, inner) {
    return `<div><label class="block text-xs font-bold normal-case tracking-normal text-slate-400 mb-1.5">${esc(label)}</label>${inner}</div>`;
}

function stateBadgeClass(state) {
    return {
        unread: 'border-slate-600 text-slate-300',
        interested: 'border-cyan-500/40 text-cyan-400',
        applied: 'border-blue-500/40 text-blue-400',
        interview: 'border-amber-500/40 text-amber-400',
        offer: 'border-emerald-500/40 text-emerald-400',
        history: 'border-slate-700 text-slate-500',
    }[state] ?? 'border-slate-700 text-slate-400';
}

function detailsHtml(job) {
    const state = job.state;
    const reason = job.history_reason ? ` — ${esc(job.history_reason)}` : '';
    const workModeOptions = ['', 'remote', 'hybrid', 'on_site']
        .map(v => `<option value="${v}" ${(job.work_mode ?? '') === v ? 'selected' : ''}>${v === '' ? '(unknown)' : v}</option>`).join('');

    const showOffer = state === 'offer' || job.offer_compensation || job.offer_deadline || job.offer_notes;
    const hasInterview = Array.isArray(job.interview_timestamps) && job.interview_timestamps.length > 0;
    const showInterview = state === 'interview' || hasInterview;
    const interviewText = hasInterview ? job.interview_timestamps.join('\n') : '';

    return `
    <form id="job-edit-form" class="max-w-3xl">
        <div class="flex items-start justify-between gap-4 mb-5">
            <div class="flex-1 min-w-0 space-y-2">
                <input name="title" value="${esc(job.title)}" class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2 text-sm font-bold text-slate-100 outline-none focus:border-cyan-500/40 transition-colors">
                <input name="company" value="${esc(job.company)}" class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2 text-slate-300 outline-none focus:border-cyan-500/40 transition-colors">
            </div>
            <div class="shrink-0 text-right">
                <span class="inline-block px-2.5 py-1 rounded-md text-xs font-extrabold normal-case tracking-normal border ${stateBadgeClass(state)}">${esc(STATE_LABELS[state] ?? state)}${reason}</span>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 mb-5">
            ${field('Posted at', `<input name="posted_at" type="datetime-local" value="${esc(toLocalInput(job.posted_at))}" class="${inputCls}">`)}
            ${field('Source domain', `<input name="source_domain" value="${esc(job.source_domain)}" class="${inputCls}">`)}
            ${field('Work mode', `<select name="work_mode" class="${inputCls}">${workModeOptions}</select>`)}
            ${field('Employment type', `<input name="employment_type" value="${esc(job.employment_type)}" class="${inputCls}">`)}
            ${field('Salary', `<input name="salary" value="${esc(job.salary)}" class="${inputCls}">`)}
            ${field('Applicants', `<input name="applicant_count" value="${esc(job.applicant_count)}" class="${inputCls}">`)}
            ${field('Location', `<input name="location" value="${esc(job.location)}" class="${inputCls}">`)}
            ${field('City', `<input name="city" value="${esc(job.city)}" class="${inputCls}">`)}
            ${field('Country', `<input name="country" value="${esc(job.country)}" class="${inputCls}">`)}
        </div>

        <div class="mb-4">
            <label class="block text-xs font-bold normal-case tracking-normal text-slate-400 mb-1.5">Job URL</label>
            <div class="flex items-center gap-2">
                <input name="url" value="${esc(job.url)}" class="${inputCls}">
                ${job.url ? `<a href="${esc(job.url)}" target="_blank" rel="noopener noreferrer" class="shrink-0 px-3 py-2 rounded-lg text-xs font-bold normal-case tracking-normal border border-cyan-500/30 text-cyan-400 hover:bg-cyan-900/40 transition-all cursor-pointer whitespace-nowrap">Open ↗</a>` : ''}
            </div>
        </div>

        <div class="mb-4">
            <label class="block text-xs font-bold normal-case tracking-normal text-slate-400 mb-1.5">AI selection comment</label>
            <div class="job-md markdown-content bg-[#0a0f1d]/60 border border-slate-800 rounded-lg px-4 py-3" data-md="${esc(job.ai_selection_comment)}" data-empty="No comment."></div>
        </div>

        <div class="mb-4">
            <label class="block text-xs font-bold normal-case tracking-normal text-slate-400 mb-1.5">Description</label>
            <div class="job-md markdown-content max-h-[30rem] overflow-y-auto bg-[#0a0f1d]/60 border border-slate-800 rounded-lg px-4 py-3" data-md="${esc(job.description)}" data-empty="No description."></div>
        </div>

        ${showInterview ? `
        <div class="mb-4">
            <label class="block text-xs font-bold normal-case tracking-normal text-slate-400 mb-1.5">Interview timestamps (one per line)</label>
            <textarea name="interview_timestamps" rows="3" class="${textareaCls}">${esc(interviewText)}</textarea>
        </div>` : ''}

        ${showOffer ? `
        <div class="mb-4 p-4 rounded-xl border border-slate-850 bg-[#0a0f1d]/60 grid grid-cols-2 gap-3">
            ${field('Offer compensation', `<input name="offer_compensation" value="${esc(job.offer_compensation)}" class="${inputCls}">`)}
            ${field('Offer deadline', `<input name="offer_deadline" type="datetime-local" value="${esc(toLocalInput(job.offer_deadline))}" class="${inputCls}">`)}
            <div class="col-span-2">
                <label class="block text-xs font-bold normal-case tracking-normal text-slate-400 mb-1.5">Offer notes</label>
                <textarea name="offer_notes" rows="2" class="${textareaCls}">${esc(job.offer_notes)}</textarea>
            </div>
        </div>` : ''}

        ${stateHistoryHtml(job)}
        ${metadataHtml(job)}

        <div class="flex items-center gap-2 flex-wrap pt-4 border-t border-slate-850">
            <button type="submit" class="px-4 py-2 rounded-lg text-xs font-bold normal-case tracking-normal bg-transparent hover:bg-cyan-900/40 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 transition-all cursor-pointer outline-none">Save</button>
            <button type="button" class="ui-button" data-job-cancel>Cancel edit</button>
            <button type="button" class="hidden job-delete-btn px-4 py-2 rounded-lg text-xs font-bold normal-case tracking-normal bg-transparent hover:bg-rose-900/40 text-rose-400 border border-rose-500/30 hover:border-rose-400/50 transition-all cursor-pointer outline-none ml-auto" data-uuid="${esc(job.uuid)}">Delete</button>
        </div>
    </form>`;
}

function actionButtonsHtml(job) {
    const actions = {
        unread: [
            { key: 'interested', label: 'Interested' },
            { key: 'not_interested', label: 'Not Interested' },
            { key: 'block_company', label: 'Block Company' },
            { key: 'block_domain', label: 'Block Source' },
        ],
        interested: [
            { key: 'apply', label: 'Record application' },
            { key: 'not_interested', label: 'Not Interested' },
        ],
        applied: [
            { key: 'move_to_interview', label: 'Move to Interview' },
            { key: 'rejected_by_company', label: 'Rejected by Company' },
        ],
        interview: [
            { key: 'move_to_offer', label: 'Move to Offer' },
            { key: 'rejected_by_company', label: 'Rejected by Company' },
        ],
        offer: [
            { key: 'offer_accepted', label: 'Offer Accepted' },
            { key: 'offer_rejected', label: 'Offer Rejected' },
        ],
        history: [
            { key: 'restore', label: 'Restore' },
        ],
    }[job.state] ?? [];

    return actions.map(a => `<button type="button" class="job-action-btn" data-action="${a.key}" data-uuid="${esc(job.uuid)}">${esc(a.label)}</button>`).join('');
}

function stateHistoryHtml(job) {
    const log = Array.isArray(job.state_timestamps) ? job.state_timestamps : [];
    if (log.length === 0) return '';
    const rows = log.map(t => `${esc(t.from)} → ${esc(t.to)} · ${esc(fmtDate(t.at))}`).join('<br>');
    return `<div class="mb-4 text-xs text-slate-500"><span class="normal-case tracking-normal font-bold text-slate-600">State history</span><div class="mt-1 font-mono leading-relaxed">${rows}</div></div>`;
}

function metadataHtml(job) {
    const meta = job.metadata;
    if (meta === null || meta === undefined) return '';
    if (Array.isArray(meta) && meta.length === 0) return '';
    if (typeof meta === 'object' && !Array.isArray(meta) && Object.keys(meta).length === 0) return '';
    const json = typeof meta === 'string' ? meta : JSON.stringify(meta, null, 2);
    return `<div class="mb-4 text-xs text-slate-500"><span class="normal-case tracking-normal font-bold text-slate-600">Metadata</span><pre class="mt-1 bg-[#060b13] border border-slate-900 rounded-lg p-3 text-slate-400 whitespace-pre-wrap">${esc(json)}</pre></div>`;
}

function renderJobMarkdown(container) {
    container.querySelectorAll('.job-md').forEach((el) => {
        const raw = el.getAttribute('data-md') || '';
        if (!raw.trim()) {
            el.innerHTML = `<p class="text-slate-600 italic">${el.getAttribute('data-empty') || 'None.'}</p>`;
            return;
        }
        if (typeof marked !== 'undefined') {
            el.innerHTML = marked.parse(raw, { breaks: true });
            addCodeCopyButtons(el);
        } else {
            el.textContent = raw;
        }
    });
}

function onDetailsClick(e) {
    if (e.target.closest('[data-job-back]')) { (async () => { if (await canLeaveJob()) clearDetails(); })(); return; }
    if (e.target.closest('[data-job-edit]')) {
        const container = document.getElementById('job-details-container'); container.innerHTML = detailsHtml(currentJob);
        renderJobMarkdown(container); document.dispatchEvent(new Event('workspace-content-ready')); container.querySelector('input')?.focus(); return;
    }
    if (e.target.closest('[data-job-cancel]')) { (async () => { if (await canLeaveJob()) showReadView(); })(); return; }
    const actionBtn = e.target.closest('.job-action-btn');
    if (actionBtn) {
        withPending(actionBtn, () => window.jobAction(actionBtn.dataset.action, actionBtn.dataset.uuid));
        return;
    }
    const deleteBtn = e.target.closest('.job-delete-btn');
    if (deleteBtn) {
        withPending(deleteBtn, () => window.jobAction('delete', deleteBtn.dataset.uuid));
        return;
    }
    if (e.target.closest('.job-apply-cancel')) {
        cancelApply();
    }
}

async function onDetailsSubmit(e) {
    e.preventDefault();
    if (e.target.id !== 'job-edit-form' || !currentJobUuid) return;
    const body = { uuid: currentJobUuid };
    new FormData(e.target).forEach((value, key) => { body[key] = value; });
    const data = await withPending(e.submitter, () => postJson('edit_job', body), { lockForm: true });
    if (!data) return;
    if (data.status === 'success') {
        e.target.dataset.dirty = 'false';
        flash('Job saved.');
        await refreshDetails();
    } else {
        flash(data.message || 'Save failed.', false);
    }
}

function cancelApply() {
    const form = document.getElementById('job-apply-form');
    if (form) form.remove();
}

function showReadView() {
    const j = currentJob; if (!j) return;
    const container = document.getElementById('job-details-container');
    const stateHistory = stateHistoryHtml(j);
    const metadata = metadataHtml(j);
    const listing = /^https?:\/\//i.test(j.url || '') ? `<a class="job-action-open" href="${esc(j.url)}" target="_blank" rel="noopener noreferrer">Open listing ↗</a>` : '';
    const facts = [
        ['Location', j.city || j.location, ''],
        ['Employment', j.employment_type, ''],
        ['Salary', j.salary, ' job-meta-tile--salary'],
        ['Posted', String(j.posted_at ?? '').slice(0, 10), ''],
        ['Applicants', j.applicant_count, ''],
        ['Source', j.source_domain, ''],
    ].filter(([, v]) => v !== null && v !== undefined && v !== '');
    container.innerHTML = `<article class="job-read-view">
        <div class="job-main-header">
            <div class="job-main-header-top">
                <div class="job-main-header-meta">
                    <span class="job-state-badge">${esc(STATE_LABELS[j.state] || j.state)}${j.history_reason ? ' · ' + esc(j.history_reason.replaceAll('_', ' ')) : ''}</span>
                    ${j.source_domain ? `<span class="job-source-note">Scraped from ${esc(j.source_domain)}</span>` : ''}
                </div>
                <button type="button" class="job-btn job-btn--ghost" data-job-edit>Edit details</button>
            </div>
            <h1 class="job-main-title">${esc(j.title)}</h1>
            <p class="job-main-company">${esc(j.company)}</p>
            <div class="job-main-actions">${listing}${actionButtonsHtml(j)}</div>
        </div>
        ${facts.length ? `<div class="job-meta-grid">${facts.map(([k, v, cls]) => `<div class="job-meta-tile${cls}"><span class="job-meta-label">${esc(k)}</span><span class="job-meta-value">${esc(v)}</span></div>`).join('')}</div>` : ''}
        ${j.ai_selection_comment ? `<div class="job-ai-callout"><div class="job-ai-callout-head"><span class="job-ai-callout-icon"><uk-icon icon="sparkles"></uk-icon></span><h3>Why the AI selected this job</h3></div><div class="job-md markdown-content" data-md="${esc(j.ai_selection_comment)}"></div></div>` : ''}
        <section class="job-description"><div class="job-description-head"><h2>Job description</h2></div><div class="job-md markdown-content" data-md="${esc(j.description)}" data-empty="No description available."></div></section>
        ${stateHistory || metadata ? `<details class="job-activity-data"><summary>Activity and source data</summary>${stateHistory}${metadata}</details>` : ''}
        <button type="button" class="job-delete-btn ui-button ui-button--danger" data-uuid="${esc(j.uuid)}">Delete job</button>
    </article>`;
    renderJobMarkdown(container); document.dispatchEvent(new Event('workspace-content-ready'));
}
