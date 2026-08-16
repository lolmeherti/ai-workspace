/**
 * @file js/jobs/registryManager.js
 * @description Template sources list, add, edit, and delete.
 */

import { esc, flash, spinner, postJson, getJson } from './jobUtil.js';

let editingUuid = null;
let entriesCache = [];

export function initRegistryManager() {
    const form = document.getElementById('registry-form');
    if (form) form.addEventListener('submit', saveRegistry);
    window.loadRegistry = loadRegistry;
    window.editRegistry = editRegistry;
    window.deleteRegistry = deleteRegistry;
}

export async function loadRegistry() {
    const container = document.getElementById('registry-list-container');
    if (!container) return;
    container.innerHTML = spinner();

    const data = await getJson('list_registry');
    if (data.status !== 'success') {
        container.innerHTML = '<p class="text-rose-400 text-[10px] uppercase font-bold">Failed to load sources.</p>';
        return;
    }

    entriesCache = data.entries || [];
    if (entriesCache.length === 0) {
        container.innerHTML = '<div class="text-center py-12 text-slate-600"><p class="text-[10px] tracking-widest uppercase font-bold">No sources yet</p><p class="text-[9px] text-slate-600 mt-1">Add a listing URL template above to get started.</p></div>';
    } else {
        container.innerHTML = '';
        entriesCache.forEach(entry => container.appendChild(renderEntry(entry)));
    }
}

function renderEntry(entry) {
    const placeholders = entry.placeholders || {};
    const ph = Object.entries(placeholders).map(([name, values]) =>
        `<div class="text-[9px] text-slate-500 font-mono mt-1"><span class="text-cyan-400/80">{${esc(name)}}</span> = ${esc((values || []).join(', '))}</div>`
    ).join('');

    const card = document.createElement('div');
    card.className = 'p-4 rounded-xl border border-slate-850 bg-[#091124]/60';
    card.innerHTML = `
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-[11px] font-bold text-slate-100">${esc(entry.domain || '')}</span>
                </div>
                <div class="text-[9px] text-slate-500 font-mono mt-1.5 break-all">${esc(entry.url)}</div>
                ${ph}
            </div>
            <div class="flex gap-1.5 shrink-0 items-start">
                <button onclick="window.editRegistry('${entry.uuid}')" class="px-2.5 py-1 rounded-md text-[9px] font-bold uppercase tracking-wider border border-slate-700 text-slate-300 hover:text-cyan-400 hover:border-cyan-500/40 transition-all cursor-pointer outline-none">Edit</button>
                <button onclick="window.deleteRegistry('${entry.uuid}')" class="px-2.5 py-1 rounded-md text-[9px] font-bold uppercase tracking-wider border border-slate-700 text-slate-400 hover:text-rose-400 hover:border-rose-500/40 transition-all cursor-pointer outline-none">Delete</button>
            </div>
        </div>
    `;
    return card;
}

async function saveRegistry(e) {
    e.preventDefault();
    const url = document.getElementById('reg-url').value.trim();
    const jobTitle = document.getElementById('reg-job-title').value.trim();
    const location = document.getElementById('reg-location').value.trim();

    if (!url) {
        flash('URL is required.', false);
        return;
    }

    const body = { url, job_title: jobTitle, location };
    const data = editingUuid
        ? await postJson('update_registry', { uuid: editingUuid, ...body })
        : await postJson('add_registry', body);

    if (data.status === 'success') {
        flash(editingUuid ? 'Source updated.' : 'Source added.');
        resetForm();
        loadRegistry();
    } else {
        flash(data.message || 'Save failed.', false);
    }
}

function editRegistry(uuid) {
    const entry = entriesCache.find(e => e.uuid === uuid);
    if (!entry) return;
    editingUuid = uuid;
    document.getElementById('reg-url').value = entry.url || '';
    document.getElementById('reg-job-title').value = ((entry.placeholders || {}).job_title || []).join(', ');
    document.getElementById('reg-location').value = ((entry.placeholders || {}).location || []).join(', ');
    document.getElementById('reg-save-label').textContent = 'Update';
}

function resetForm() {
    editingUuid = null;
    document.getElementById('reg-url').value = '';
    document.getElementById('reg-job-title').value = '';
    document.getElementById('reg-location').value = '';
    document.getElementById('reg-save-label').textContent = 'Add';
}

export async function deleteRegistry(uuid) {
    if (!confirm('Delete this source?')) return;
    const data = await postJson('delete_registry', { uuid });
    if (data.status === 'success') {
        flash('Source deleted.');
        loadRegistry();
    } else {
        flash(data.message || 'Delete failed.', false);
    }
}
