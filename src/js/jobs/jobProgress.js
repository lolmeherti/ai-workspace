/**
 * @file js/jobs/jobProgress.js
 * @description Find Jobs run: SSE runner, progress view, cancel, summary, run logs.
 */

import { flash, esc, getJson, postJson, fmtDate } from './jobUtil.js';
import { switchJobView } from './jobViews.js';
import { refreshInbox } from './jobInbox.js';
import { clearDetails } from './jobDetails.js';

let activeRunUuid = null;

export function initProgress() {
    window.jobFindJobs = runJobSearch;
    window.openRunLogs = openRunLogs;
    window.pruneJobs = pruneJobs;
    const cancelBtn = document.getElementById('job-run-cancel');
    if (cancelBtn) cancelBtn.addEventListener('click', cancelRun);
}

export function showProgress() {
    switchJobView('progress');
    updateProgress({});
}

export function updateProgress(state = {}) {
    const el = document.getElementById('job-progress-body');
    if (!el) return;
    const listing = state.listing || '';
    const scraped = state.jobs_scraped ?? 0;
    const selected = state.jobs_selected ?? 0;
    const done = state.sources_done ?? 0;
    const total = state.sources_total ?? 0;
    const failed = state.sources_failed ?? 0;

    el.innerHTML = `
        <div class="text-center text-cyan-400 flex items-center justify-center gap-2 py-6 select-none">
            <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            <span class="text-[10px] font-bold tracking-widest uppercase animate-pulse">Finding jobs...</span>
        </div>
        <div class="text-[9px] text-slate-500 font-mono space-y-1">
            ${listing ? `<div class="break-all">Listing: ${esc(listing)}</div>` : ''}
            <div>Jobs scraped: ${scraped}</div>
            <div>Jobs selected: ${selected}</div>
            ${total ? `<div>Listings: ${done} / ${total}${failed ? ` (${failed} failed)` : ''}</div>` : ''}
        </div>`;
}

export function hideProgress() {
    switchJobView('details');
}

async function runJobSearch() {
    const select = document.getElementById('job-cv-select');
    const cvUuid = select ? select.value : '';
    if (!cvUuid) {
        flash('Select a CV first.', false);
        return;
    }

    showProgress();
    try {
        const res = await fetch('index.php?api_action=run_job_search', {
            method: 'POST',
            headers: { 'Accept': 'text/event-stream', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ cv_uuid: cvUuid }).toString(),
        });

        if (!res.ok) {
            const err = await res.json().catch(() => null);
            flash(err?.message || `Search failed (HTTP ${res.status}).`, false);
            hideProgress();
            return;
        }

        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            buffer += decoder.decode(value, { stream: true });
            const parts = buffer.split('\n\n');
            buffer = parts.pop();
            for (const part of parts) {
                const line = part.trim();
                if (!line.startsWith('data: ')) continue;
                try {
                    const payload = JSON.parse(line.substring(6));
                    handleEvent(payload.event, payload.data);
                } catch (e) {}
            }
        }
    } catch (e) {
        flash('Search interrupted.', false);
        hideProgress();
        await refreshInbox();
    }
}

function handleEvent(event, data) {
    switch (event) {
        case 'run_start':
            activeRunUuid = data.run_uuid;
            break;
        case 'progress':
            updateProgress(data);
            break;
        case 'run_log':
            appendLogLine(data);
            break;
        case 'error':
            activeRunUuid = null;
            flash(data.message || 'Search failed.', false);
            hideProgress();
            refreshInbox();
            break;
        case 'run_complete':
            finishRun(data.summary);
            break;
        case 'done':
            break;
    }
}

async function finishRun(summary) {
    activeRunUuid = null;
    hideProgress();
    await refreshInbox();
    clearDetails();
    const msg = summary.cancelled
        ? `Search cancelled — ${summary.jobs_selected} kept of ${summary.jobs_scraped} scraped.`
        : `Search complete — ${summary.jobs_scraped} scraped, ${summary.jobs_selected} selected, ${summary.sources_failed} listings failed.`;
    showSummaryBanner(msg);
}

async function cancelRun() {
    await postJson('cancel_job_search', { run_uuid: activeRunUuid || '' });
}

async function openRunLogs() {
    switchJobView('logs');
    await loadRunLogs();
}

async function pruneJobs() {
    if (!confirm('Delete all jobs, job searches, and run logs? This cannot be undone.')) return;
    try {
        const data = await postJson('prune_jobs', {});
        if (data.status === 'success') {
            flash(`Pruned ${data.jobs_deleted ?? 0} jobs, ${data.runs_deleted ?? 0} searches, ${data.logs_deleted ?? 0} log entries.`);
            await refreshInbox();
            clearDetails();
            await loadRunLogs();
        } else {
            flash(data.message || 'Prune failed.', false);
        }
    } catch (e) {
        flash('Prune failed.', false);
    }
}

async function loadRunLogs() {
    const container = document.getElementById('job-logs-container');
    if (!container) return;
    const data = await getJson('list_run_logs');
    if (data.status !== 'success') return;

    if (!data.run) {
        delete container.dataset.runUuid;
        container.innerHTML = '<div class="text-center py-20 text-slate-600 flex flex-col items-center justify-center gap-3 select-none"><uk-icon icon="activity" class="w-10 h-12 text-slate-700 opacity-30"></uk-icon><p class="text-[10px] tracking-widest uppercase font-bold">No job runs yet</p></div>';
        return;
    }

    const run = data.run;
    container.dataset.runUuid = run.uuid;
    const status = run.status === 'completed' ? 'complete' : run.status;
    const logRows = (data.logs || []).map(logRow).join('');

    container.innerHTML = `
        <div class="mb-4 p-4 rounded-xl border border-slate-850 bg-[#0a0f1d]/60">
            <div class="flex items-center justify-between mb-2">
                <span class="text-[10px] font-bold uppercase tracking-widest text-slate-300">Latest run</span>
                <span class="text-[9px] font-bold uppercase ${status === 'complete' ? 'text-emerald-400' : 'text-amber-400'}">${esc(status)}</span>
            </div>
            <div class="text-[10px] text-slate-400 font-mono space-y-0.5">
                <div>Started: ${esc(fmtDate(run.started_at))}</div>
                <div>Scraped ${run.jobs_scraped ?? 0} · Selected ${run.jobs_selected ?? 0} · Listings ${run.sources_attempted ?? 0} (${run.sources_failed ?? 0} failed)</div>
            </div>
        </div>
        <div id="job-logs-rows" class="space-y-0.5">${logRows || '<div class="text-center py-8 text-slate-600 text-[9px] uppercase">No log entries</div>'}</div>`;

    scrollLogsToBottom();
}

function logRow(l) {
    return `
        <div class="flex gap-2 py-1 border-b border-slate-900">
            <span class="shrink-0 font-mono text-slate-600">${esc(fmtDate(l.created_at))}</span>
            <span class="shrink-0 w-12 font-bold uppercase ${levelColor(l.level)}">${esc(l.level)}</span>
            <span class="${levelColor(l.level)} break-all">${esc(l.message)}</span>
        </div>`;
}

function appendLogLine(log) {
    const view = document.getElementById('job-view-logs');
    const container = document.getElementById('job-logs-container');
    const rows = document.getElementById('job-logs-rows');
    if (!view || !container || !rows) return;
    if (view.classList.contains('hidden')) return;
    if (container.dataset.runUuid !== log.run_uuid) return;
    const empty = rows.querySelector('.text-center');
    if (empty) empty.remove();
    rows.insertAdjacentHTML('beforeend', logRow(log));
    scrollLogsToBottom();
}

function scrollLogsToBottom() {
    const view = document.getElementById('job-view-logs');
    if (view) view.scrollTop = view.scrollHeight;
}

function levelColor(level) {
    return {
        error: 'text-rose-400',
        warn: 'text-amber-400',
        keep: 'text-emerald-400',
        list: 'text-cyan-400',
        info: 'text-slate-500',
    }[level] || 'text-slate-500';
}

function showSummaryBanner(msg) {
    const existing = document.getElementById('job-summary');
    if (existing) existing.remove();
    const el = document.createElement('div');
    el.id = 'job-summary';
    el.className = 'fixed bottom-4 right-4 px-4 py-3 rounded-lg border border-emerald-500/40 bg-emerald-950/80 text-emerald-300 text-[10px] font-bold uppercase tracking-widest shadow-lg z-50 flex items-center gap-3';
    el.innerHTML = `
        <span>${esc(msg)}</span>
        <button onclick="window.openRunLogs()" class="px-2 py-1 rounded-md text-[9px] uppercase tracking-wider border border-emerald-500/40 hover:bg-emerald-900/40 cursor-pointer outline-none">View logs</button>
        <button onclick="document.getElementById('job-summary').remove()" class="px-1.5 text-slate-400 hover:text-slate-200 cursor-pointer outline-none">×</button>`;
    document.body.appendChild(el);
}
