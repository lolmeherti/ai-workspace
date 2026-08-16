/**
 * @file js/jobs/jobActions.js
 * @description Individual job actions: transitions, restore, delete, block, apply.
 */

import { flash, postJson, getJson, esc } from './jobUtil.js';
import { refreshDetails, clearDetails } from './jobDetails.js';
import { refreshInbox } from './jobInbox.js';

window.jobAction = jobAction;
window.submitApply = submitApply;
window.cancelApply = cancelApply;

async function jobAction(key, uuid) {
    switch (key) {
        case 'interested': return transition(uuid, 'interested');
        case 'not_interested': return transition(uuid, 'history', { history_reason: 'not_interested' });
        case 'move_to_interview': return transition(uuid, 'interview');
        case 'rejected_by_company': return transition(uuid, 'history', { history_reason: 'rejected_by_company' });
        case 'move_to_offer': return transition(uuid, 'offer');
        case 'offer_accepted': return transition(uuid, 'history', { history_reason: 'offer_accepted' });
        case 'offer_rejected': return transition(uuid, 'history', { history_reason: 'offer_rejected' });
        case 'restore': return restore(uuid);
        case 'apply': return openApplyForm(uuid);
        case 'block_company': return blockCompany(uuid);
        case 'block_domain': return blockDomain(uuid);
        case 'delete': return deleteJob(uuid);
    }
}

async function transition(uuid, to, extra = {}) {
    const data = await postJson('transition_job', { uuid, to, ...extra });
    await handleMutation(data);
}

async function restore(uuid) {
    const data = await postJson('restore_job', { uuid });
    await handleMutation(data);
}

async function deleteJob(uuid) {
    if (!confirm('Delete this job permanently?')) return;
    const data = await postJson('batch_action', { uuids: JSON.stringify([uuid]), action: 'delete' });
    if (data.status === 'success') {
        flash('Job deleted.');
        clearDetails();
        await refreshInbox();
    } else {
        flash(data.message || 'Delete failed.', false);
    }
}

async function blockCompany(uuid) {
    if (!confirm('Block this company for 7 days? Its unread jobs will be removed.')) return;
    await handleMutation(await postJson('block_company', { uuid }));
}

async function blockDomain(uuid) {
    if (!confirm('Block this source domain for 7 days? Its unread jobs will be removed.')) return;
    await handleMutation(await postJson('block_domain', { uuid }));
}

async function handleMutation(data) {
    if (data.status === 'success') {
        flash('Updated.');
        await refreshInbox();
        await refreshDetails();
    } else {
        flash(data.message || 'Action failed.', false);
    }
}

async function openApplyForm(uuid) {
    document.getElementById('job-apply-form')?.remove();
    const container = document.getElementById('job-details-container');
    if (!container) return;

    const cvs = await getJson('list_cvs');
    const cvOptions = (cvs.status === 'success' && Array.isArray(cvs.cvs))
        ? cvs.cvs.map(cv => `<option value="${esc(cv.uuid)}">${esc(cv.designation)}</option>`).join('')
        : '';

    const form = document.createElement('form');
    form.id = 'job-apply-form';
    form.className = 'mb-5 p-4 rounded-xl border border-cyan-500/20 bg-[#0a0f1d]/80 space-y-3';
    form.innerHTML = `
        <div class="text-[10px] font-bold uppercase tracking-widest text-cyan-400">Apply to this job</div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Date & time</label>
                <input type="datetime-local" name="applied_at" class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 outline-none focus:border-cyan-500/40 transition-colors">
            </div>
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">CV</label>
                <select name="applied_cv_uuid" class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 outline-none focus:border-cyan-500/40 transition-colors">${cvOptions}</select>
            </div>
        </div>
        <div class="flex gap-2 justify-end">
            <button type="button" class="job-apply-cancel px-3 py-2 rounded-lg text-[10px] font-bold uppercase tracking-wider border border-slate-700 text-slate-400 hover:text-slate-200 transition-all cursor-pointer outline-none">Cancel</button>
            <button type="submit" class="px-3 py-2 rounded-lg text-[10px] font-bold uppercase tracking-wider bg-transparent hover:bg-cyan-900/40 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 transition-all cursor-pointer outline-none">Confirm</button>
        </div>`;
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        window.submitApply(uuid, form);
    });
    container.prepend(form);
    form.querySelector('input[name="applied_at"]')?.focus();
}

async function submitApply(uuid, form) {
    const fd = new FormData(form);
    const appliedAt = fd.get('applied_at');
    const cvUuid = fd.get('applied_cv_uuid');
    if (!appliedAt || !cvUuid) {
        flash('Choose a date/time and a CV.', false);
        return;
    }
    const data = await postJson('transition_job', { uuid, to: 'applied', applied_at: appliedAt, applied_cv_uuid: cvUuid });
    if (data.status === 'success') {
        cancelApply();
    }
    await handleMutation(data);
}

function cancelApply() {
    document.getElementById('job-apply-form')?.remove();
}
