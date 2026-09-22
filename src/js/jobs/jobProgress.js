import { flash, esc, getJson, postJson, fmtDate } from './jobUtil.js';
import { switchJobView } from './jobViews.js';
import { refreshInbox } from './jobInbox.js';
import { clearDetails } from './jobDetails.js';
import { state } from '../state.js';
import { ensureAIAvailable, paintAvailability, refreshAvailability } from '../workspace/availability.js';
import { confirmAction, notify, withPending } from '../workspace/feedback.js';
let activeRunUuid = null;
let streaming = false;
let reconciling = false;
let terminal = false;
let statusTimer;
function setRun(run) {
    activeRunUuid = run?.uuid || null;
    state.jobRun = run;
    document.getElementById('job-find-btn').disabled = !!run || streaming;
    const cancel = document.getElementById('job-run-cancel');
    cancel.disabled = !activeRunUuid;
    if (!run) cancel.textContent = 'Cancel search';
    paintAvailability();
}
export function initProgress() {
    window.jobFindJobs = runJobSearch; window.openRunLogs = openRunLogs; window.pruneJobs = pruneJobs;
    document.getElementById('job-run-cancel')?.addEventListener('click', e => withPending(e.currentTarget, cancelRun));
    document.addEventListener('jobs-opened', () => { if (!streaming) reconcileRun(); });
    document.addEventListener('visibilitychange', () => { clearTimeout(statusTimer); if (!document.hidden && !streaming && state.jobRun) reconcileRun(); });
}
export function showProgress() { switchJobView('progress'); updateProgress(); }
export function updateProgress(progress = {}) {
    const el = document.getElementById('job-progress-body'); if (!el) return;
    document.getElementById('job-run-status').textContent = 'Search running';
    el.innerHTML = `<div class="job-progress-status" role="status"><span class="ui-spinner"></span></div>
        <p class="ui-muted">You can keep browsing saved jobs. Results are kept as they are found.</p>
        ${progress.listing ? `<p class="break-all">${esc(progress.listing)}</p>` : ''}
        <p>${Number(progress.jobs_scraped) || 0} found · ${Number(progress.jobs_selected) || 0} selected${progress.sources_total ? ` · ${Number(progress.sources_done) || 0} of ${Number(progress.sources_total)} sources checked` : ''}${progress.sources_failed ? ` · ${Number(progress.sources_failed)} sources failed` : ''}</p>`;
}
export function hideProgress() { document.getElementById('job-view-progress').classList.add('hidden'); }
export async function reconcileRun() {
    if (streaming || reconciling) return; reconciling = true; clearTimeout(statusTimer);
    try {
        const data = await getJson('get_run_status');
        if (data.status !== 'success') {
            setRun({ uuid: activeRunUuid, unknown: true });
            notify('Search status is unavailable. Check again before starting another search.', { target: document.getElementById('job-run-summary'), action: 'Check status', onAction: reconcileRun });
            return;
        }
        const wasRunning = !!state.jobRun;
        setRun(data.run);
        if (data.run) {
            showProgress(); updateProgress(data.run);
            document.getElementById('job-run-status').textContent = 'Search still running';
            if (!document.hidden) statusTimer = setTimeout(reconcileRun, 5000);
        } else {
            hideProgress(); document.getElementById('job-run-status').textContent = 'No active search';
            if (wasRunning) { await loadRunLogs(); await refreshInbox(); }
        }
    } finally { reconciling = false; }
}
export async function runJobSearch() {
    if (streaming || state.jobRun || !ensureAIAvailable(document.getElementById('job-notices'))) return;
    const cv = document.getElementById('job-cv-select').value;
    if (!cv) { flash('Select a CV in Search setup first.', false); switchJobView('cvs'); return; }
    if (document.querySelector('#job-setup form[data-dirty="true"]')) { flash('Save or discard your Search setup edits before finding jobs.', false); switchJobView('profile'); return; }
    streaming = true; terminal = false; setRun({ uuid: null }); showProgress();
    document.getElementById('job-run-summary').replaceChildren();
    try {
        const res = await fetch('index.php?api_action=run_job_search', { method: 'POST', headers: { Accept: 'text/event-stream', 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ cv_uuid: cv }) });
        if (!res.ok) { const data = await res.json(); throw new Error(data.message || 'Search could not start.'); }
        const reader = res.body.getReader(), decoder = new TextDecoder(); let buffer = '';
        while (true) {
            const { done, value } = await reader.read(); if (done) break;
            buffer += decoder.decode(value, { stream: true }).replaceAll('\r\n', '\n');
            const parts = buffer.split('\n\n'); buffer = parts.pop();
            for (const part of parts) {
                const text = part.trim(); if (!text.startsWith('data:')) continue;
                const event = JSON.parse(text.slice(5).trim());
                if (event.event === 'run_start') { activeRunUuid = event.data.run_uuid; setRun({ uuid: activeRunUuid }); initLiveLogFeed(activeRunUuid); }
                else if (event.event === 'progress') updateProgress(event.data);
                else if (event.event === 'run_log') appendLogLine(event.data);
                else if (event.event === 'fetching') appendLogLine({ run_uuid: activeRunUuid, level: 'info', message: `Fetching candidate ${Number(event.data.done) + 1}/${event.data.total}`, created_at: nowStamp() });
                else if (event.event === 'error') throw new Error(event.data.message || 'Search failed.');
                else if (event.event === 'run_complete') {
                    terminal = true; const summary = event.data.summary || {};
                    document.getElementById('job-run-status').textContent = summary.cancelled ? 'Cancelled' : 'Complete';
                    showSummaryBanner(`${summary.cancelled ? 'Search cancelled' : 'Search complete'} · ${summary.jobs_selected || 0} selected of ${summary.jobs_scraped || 0} found · ${summary.sources_failed || 0} sources failed.`);
                    await loadRunLogs();
                }
            }
        }
        if (!terminal) throw new Error('The connection ended before the search result was confirmed.');
    } catch (e) {
        hideProgress(); document.getElementById('job-run-status').textContent = 'Checking search status';
        notify(e.message + ' Saved results are kept. No new search has been started.', { target: document.getElementById('job-run-summary'), action: 'Check status', onAction: reconcileRun });
    } finally {
        streaming = false;
        if (terminal) { setRun(null); hideProgress(); } else await reconcileRun();
        await refreshInbox(); refreshAvailability();
    }
}
async function cancelRun() {
    if (!activeRunUuid) return;
    const data = await postJson('cancel_job_search', { run_uuid: activeRunUuid });
    if (data.status === 'success') { document.getElementById('job-run-cancel').textContent = 'Cancellation requested'; document.getElementById('job-run-status').textContent = 'Stopping after the current step'; }
}
async function openRunLogs() { switchJobView('logs'); await loadRunLogs(); }
async function pruneJobs() {
    if (state.jobRun) { flash('Wait for the active search to finish before clearing jobs.', false); return; }
    if (!await confirmAction('Delete all saved jobs, job searches, and run logs? CVs, preferences, sources and blocks remain saved. This cannot be undone.', { title: 'Clear all saved jobs?', confirmLabel: 'Clear saved jobs', destructive: true })) return;
    await withPending(document.getElementById('job-prune-btn'), async () => {
        const data = await postJson('prune_jobs', {});
        if (data.status === 'success') { flash(`Cleared ${data.jobs_deleted || 0} jobs and ${data.runs_deleted || 0} searches.`); await refreshInbox(); clearDetails(); await loadRunLogs(); }
    });
}
function showSummaryBanner(message) {
    notify(message, { target: document.getElementById('job-run-summary'), kind: 'success', action: 'View run history', onAction: openRunLogs });
}
async function loadRunLogs() {
    const container = document.getElementById('job-logs-container');
    if (!container) return;
    const data = await getJson('list_run_logs');
    if (data.status !== 'success') return;

    const meta = document.getElementById('job-run-meta');
    const statusEl = document.getElementById('job-run-status');

    if (!data.run) {
        delete container.dataset.runUuid;
        if (meta) meta.innerHTML = '';
        if (statusEl) statusEl.textContent = 'No active search';
        container.innerHTML = '<div class="run-log-empty"><uk-icon icon="activity"></uk-icon><p>No job runs yet</p></div>';
        return;
    }

    const run = data.run;
    container.dataset.runUuid = run.uuid;
    const status = run.status === 'completed' ? 'complete' : run.status;
    const rows = (data.logs || []).map(logRow).join('');
    if (statusEl) statusEl.textContent = status === 'complete' ? 'Complete' : status === 'cancelled' ? 'Cancelled' : 'Running';

    if (meta) {
        meta.innerHTML = `
            <div class="run-meta-grid">
                <div class="run-meta-tile"><span class="run-meta-label">Execution ID</span><span class="run-meta-value">#${esc(run.uuid.slice(0, 8))}</span><span class="run-meta-sub">Started: ${esc(fmtDate(run.started_at))}</span></div>
                <div class="run-meta-tile"><span class="run-meta-label">Yield Stats</span><span class="run-meta-value run-meta-value--emerald">Scraped ${run.jobs_scraped ?? 0} · Selected ${run.jobs_selected ?? 0}</span><span class="run-meta-sub">${run.sources_attempted ?? 0} listings, ${run.sources_failed ?? 0} failed</span></div>
                <div class="run-meta-tile"><span class="run-meta-label">Status</span><span class="run-meta-value ${status === 'complete' ? 'run-meta-value--emerald' : status === 'cancelled' ? 'run-meta-value--rose' : 'run-meta-value--amber'}">${esc(status)}</span>${run.completed_at ? `<span class="run-meta-sub">Finished: ${esc(fmtDate(run.completed_at))}</span>` : ''}</div>
            </div>`;
    }

    container.innerHTML = `<div class="run-log-feed"><div class="run-log-feed-head"><span>LIVE TELEMETRY STREAM</span><span>${run.jobs_scraped ?? 0} scraped · ${run.jobs_selected ?? 0} selected</span></div><div id="job-logs-rows" class="run-log-rows">${rows || '<div class="run-log-empty"><p>No log entries</p></div>'}</div></div>`;

    scrollLogsToBottom();
}

function logRow(l) {
    const time = String(l.created_at ?? '').slice(11, 19);
    return `
        <div class="run-log-row">
            <span class="run-log-time">${esc(time)}</span>
            <span class="run-log-level ${levelColor(l.level)}">[${esc(l.level)}]</span>
            <span class="run-log-msg">${esc(l.message)}</span>
        </div>`;
}

function appendLogLine(log) {
    const view = document.getElementById('job-view-logs');
    const container = document.getElementById('job-logs-container');
    const rows = document.getElementById('job-logs-rows');
    if (!view || !container || !rows) return;
    if (view.classList.contains('hidden')) return;
    if (container.dataset.runUuid !== log.run_uuid) return;
    const empty = rows.querySelector('.run-log-empty');
    if (empty) empty.remove();
    rows.insertAdjacentHTML('beforeend', logRow(log));
    scrollLogsToBottom();
}

function initLiveLogFeed(runUuid) {
    const container = document.getElementById('job-logs-container');
    if (!container) return;
    container.dataset.runUuid = runUuid;
    container.innerHTML = `<div class="run-log-feed"><div class="run-log-feed-head"><span>LIVE TELEMETRY STREAM</span><span>Streaming…</span></div><div id="job-logs-rows" class="run-log-rows"></div></div>`;
}

function nowStamp() {
    const d = new Date();
    const p = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
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
