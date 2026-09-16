import { confirmAction } from '../workspace/feedback.js';
let pending = false;
/**
 * @file js/jobs/jobBatchSelect.js
 * @description Card checkbox selection and per-category / universal batch actions.
 */

import { flash, postJson, esc } from './jobUtil.js';
import { refreshInbox } from './jobInbox.js';
import { refreshDetails } from './jobDetails.js';

const selected = new Map();

export function initBatchSelect() {
    window.onJobSelectChange = onJobSelectChange;
    window.performBatchAction = performBatchAction;
    window.syncSelection = syncSelection;
    const bar = document.getElementById('job-batch-bar');
    if (bar) bar.addEventListener('click', onBatchBarClick);
}

function syncSelection() {
    document.querySelectorAll('.job-select').forEach(cb => {
        cb.checked = selected.has(cb.dataset.uuid);
    });
    renderBatchBar();
}

function onJobSelectChange(checkbox) {
    const uuid = checkbox.dataset.uuid;
    const state = checkbox.dataset.state;
    if (checkbox.checked) selected.set(uuid, state);
    else selected.delete(uuid);
    renderBatchBar();
}

function renderBatchBar() {
    const bar = document.getElementById('job-batch-bar');
    if (!bar) return;
    if (selected.size === 0) {
        bar.classList.add('hidden');
        bar.innerHTML = '';
        return;
    }

    const states = new Set(selected.values());
    const uniform = states.size === 1;
    const actions = uniform ? batchActionsFor([...states][0]) : [{ key: 'delete', label: 'Delete' }];

    bar.innerHTML = `
        <div class="px-3 py-2 border-t border-slate-850 bg-[#0a0f1d] flex items-center gap-1.5 flex-wrap">
            <span class="text-xs font-bold normal-case tracking-normal text-slate-400 mr-1">${selected.size} selected</span>
            ${actions.map(a => `<button class="job-batch-btn px-2.5 py-1 rounded-md text-xs font-bold normal-case tracking-normal border ${a.key === 'delete' ? 'border-slate-700 text-slate-400 hover:text-rose-400 hover:border-rose-500/40' : 'border-slate-700 text-slate-300 hover:text-cyan-400 hover:border-cyan-500/40'} transition-all cursor-pointer outline-none" data-action="${a.key}">${esc(a.label)}</button>`).join('')}
            <button class="job-batch-clear px-2 py-1 text-xs normal-case tracking-normal text-slate-500 hover:text-slate-300 cursor-pointer outline-none ml-auto">Clear</button>
        </div>`;
    bar.classList.remove('hidden');
}

function batchActionsFor(state) {
    switch (state) {
        case 'unread':
            return [
                { key: 'interested', label: 'Interested' },
                { key: 'not_interested', label: 'Not Interested' },
                { key: 'delete', label: 'Delete' },
            ];
        case 'interested':
            return [
                { key: 'not_interested', label: 'Not Interested' },
                { key: 'delete', label: 'Delete' },
            ];
        case 'applied':
            return [
                { key: 'move_to_interview', label: 'Move to Interview' },
                { key: 'rejected_by_company', label: 'Rejected by Company' },
                { key: 'delete', label: 'Delete' },
            ];
        case 'interview':
            return [
                { key: 'move_to_offer', label: 'Move to Offer' },
                { key: 'rejected_by_company', label: 'Rejected by Company' },
                { key: 'delete', label: 'Delete' },
            ];
        case 'offer':
            return [
                { key: 'offer_accepted', label: 'Offer Accepted' },
                { key: 'offer_rejected', label: 'Offer Rejected' },
                { key: 'delete', label: 'Delete' },
            ];
        case 'history':
            return [
                { key: 'restore', label: 'Restore' },
                { key: 'delete', label: 'Delete' },
            ];
        default:
            return [{ key: 'delete', label: 'Delete' }];
    }
}

function onBatchBarClick(e) {
    if (e.target.closest('.job-batch-clear')) {
        clearSelection();
        return;
    }
    const btn = e.target.closest('.job-batch-btn');
    if (btn) performBatchAction(btn.dataset.action);
}

export async function performBatchAction(action) {
    if (pending || selected.size === 0) return;
    if (action === 'delete' && !await confirmAction(`Delete ${selected.size} selected job(s)? This is permanent.`, { confirmLabel: 'Delete jobs', destructive: true })) return;

    pending = true;
    const uuids = [...selected.keys()];
    document.getElementById('job-batch-bar').setAttribute('aria-busy', 'true');
    const data = await postJson('batch_action', { uuids: JSON.stringify(uuids), action });

    pending = false;
    document.getElementById('job-batch-bar').removeAttribute('aria-busy');
    if (data.status === 'success') {
        flash(`${data.updated ?? 0} job(s) updated.`);
        clearSelection();
        await refreshInbox();
        await refreshDetails();
    } else {
        flash(data.message || 'Batch action failed.', false);
    }
}

export function clearSelection() {
    selected.clear();
    document.querySelectorAll('.job-select').forEach(cb => { cb.checked = false; });
    renderBatchBar();
}
