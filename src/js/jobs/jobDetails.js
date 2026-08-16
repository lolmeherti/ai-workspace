/**
 * @file js/jobs/jobDetails.js
 * @description Right-pane job details: editable record, AI comment, description, actions.
 */

import { esc, getJson, postJson, flash, spinner, STATE_LABELS, fmtDate, toLocalInput } from './jobUtil.js';
import { switchJobView } from './jobViews.js';

let currentJobUuid = null;

const inputCls = 'w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 outline-none focus:border-cyan-500/40 transition-colors';
const textareaCls = 'w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2.5 text-slate-200 outline-none focus:border-cyan-500/40 transition-colors leading-relaxed';

export function initDetails() {
    const container = document.getElementById('job-details-container');
    container.addEventListener('click', onDetailsClick);
    container.addEventListener('submit', onDetailsSubmit);
    window.selectJob = selectJob;
    window.cancelApply = cancelApply;
}

export async function selectJob(uuid) {
    switchJobView('details');
    currentJobUuid = uuid;
    await renderJob();
}

export function clearDetails() {
    currentJobUuid = null;
    const container = document.getElementById('job-details-container');
    if (container) container.innerHTML = placeholder('Select a job to view its details.');
}

export async function refreshDetails() {
    if (currentJobUuid) await renderJob();
}

async function renderJob() {
    const container = document.getElementById('job-details-container');
    if (!container || !currentJobUuid) return;
    container.innerHTML = spinner();

    const data = await getJson('get_job', { uuid: currentJobUuid });
    if (data.status !== 'success') {
        currentJobUuid = null;
        container.innerHTML = placeholder('This job no longer exists.');
        return;
    }
    container.innerHTML = detailsHtml(data.job);
    renderJobMarkdown(container);
}

function placeholder(msg) {
    return `<div class="h-full flex flex-col items-center justify-center gap-3 text-slate-600 select-none">
        <uk-icon icon="briefcase" class="w-10 h-12 text-slate-700 opacity-30"></uk-icon>
        <p class="text-[10px] tracking-widest uppercase font-bold">${esc(msg)}</p>
    </div>`;
}

function field(label, inner) {
    return `<div><label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">${esc(label)}</label>${inner}</div>`;
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
                <span class="inline-block px-2.5 py-1 rounded-md text-[9px] font-extrabold uppercase tracking-widest border ${stateBadgeClass(state)}">${esc(STATE_LABELS[state] ?? state)}${reason}</span>
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
            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Job URL</label>
            <div class="flex items-center gap-2">
                <input name="url" value="${esc(job.url)}" class="${inputCls}">
                ${job.url ? `<a href="${esc(job.url)}" target="_blank" rel="noopener noreferrer" class="shrink-0 px-3 py-2 rounded-lg text-[10px] font-bold uppercase tracking-wider border border-cyan-500/30 text-cyan-400 hover:bg-cyan-900/40 transition-all cursor-pointer whitespace-nowrap">Open ↗</a>` : ''}
            </div>
        </div>

        <div class="mb-4">
            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">AI selection comment</label>
            <div class="job-md markdown-content bg-[#0a0f1d]/60 border border-slate-800 rounded-lg px-4 py-3" data-md="${esc(job.ai_selection_comment)}" data-empty="No comment."></div>
        </div>

        <div class="mb-4">
            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Description</label>
            <div class="job-md markdown-content max-h-[30rem] overflow-y-auto bg-[#0a0f1d]/60 border border-slate-800 rounded-lg px-4 py-3" data-md="${esc(job.description)}" data-empty="No description."></div>
        </div>

        ${showInterview ? `
        <div class="mb-4">
            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Interview timestamps (one per line)</label>
            <textarea name="interview_timestamps" rows="3" class="${textareaCls}">${esc(interviewText)}</textarea>
        </div>` : ''}

        ${showOffer ? `
        <div class="mb-4 p-4 rounded-xl border border-slate-850 bg-[#0a0f1d]/60 grid grid-cols-2 gap-3">
            ${field('Offer compensation', `<input name="offer_compensation" value="${esc(job.offer_compensation)}" class="${inputCls}">`)}
            ${field('Offer deadline', `<input name="offer_deadline" type="datetime-local" value="${esc(toLocalInput(job.offer_deadline))}" class="${inputCls}">`)}
            <div class="col-span-2">
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Offer notes</label>
                <textarea name="offer_notes" rows="2" class="${textareaCls}">${esc(job.offer_notes)}</textarea>
            </div>
        </div>` : ''}

        ${stateHistoryHtml(job)}
        ${metadataHtml(job)}

        <div class="flex items-center gap-2 flex-wrap pt-4 border-t border-slate-850">
            <button type="submit" class="px-4 py-2 rounded-lg text-[10px] font-bold uppercase tracking-wider bg-transparent hover:bg-cyan-900/40 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 transition-all cursor-pointer outline-none">Save</button>
            ${actionButtonsHtml(job)}
            <button type="button" class="job-delete-btn px-4 py-2 rounded-lg text-[10px] font-bold uppercase tracking-wider bg-transparent hover:bg-rose-900/40 text-rose-400 border border-rose-500/30 hover:border-rose-400/50 transition-all cursor-pointer outline-none ml-auto" data-uuid="${esc(job.uuid)}">Delete</button>
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
            { key: 'apply', label: 'Apply' },
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

    return actions.map(a => `<button type="button" class="job-action-btn px-3 py-2 rounded-lg text-[10px] font-bold uppercase tracking-wider bg-transparent hover:bg-cyan-900/40 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 transition-all cursor-pointer outline-none" data-action="${a.key}" data-uuid="${esc(job.uuid)}">${esc(a.label)}</button>`).join('');
}

function stateHistoryHtml(job) {
    const log = Array.isArray(job.state_timestamps) ? job.state_timestamps : [];
    if (log.length === 0) return '';
    const rows = log.map(t => `${esc(t.from)} → ${esc(t.to)} · ${esc(fmtDate(t.at))}`).join('<br>');
    return `<div class="mb-4 text-[9px] text-slate-500"><span class="uppercase tracking-widest font-bold text-slate-600">State history</span><div class="mt-1 font-mono leading-relaxed">${rows}</div></div>`;
}

function metadataHtml(job) {
    const meta = job.metadata;
    if (meta === null || meta === undefined) return '';
    if (Array.isArray(meta) && meta.length === 0) return '';
    if (typeof meta === 'object' && !Array.isArray(meta) && Object.keys(meta).length === 0) return '';
    const json = typeof meta === 'string' ? meta : JSON.stringify(meta, null, 2);
    return `<div class="mb-4 text-[9px] text-slate-500"><span class="uppercase tracking-widest font-bold text-slate-600">Metadata</span><pre class="mt-1 bg-[#060b13] border border-slate-900 rounded-lg p-3 text-slate-400 whitespace-pre-wrap">${esc(json)}</pre></div>`;
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
        } else {
            el.textContent = raw;
        }
    });
}

function onDetailsClick(e) {
    const actionBtn = e.target.closest('.job-action-btn');
    if (actionBtn) {
        window.jobAction(actionBtn.dataset.action, actionBtn.dataset.uuid);
        return;
    }
    const deleteBtn = e.target.closest('.job-delete-btn');
    if (deleteBtn) {
        window.jobAction('delete', deleteBtn.dataset.uuid);
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
    const data = await postJson('edit_job', body);
    if (data.status === 'success') {
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
