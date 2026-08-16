/**
 * @file js/jobs/cvManager.js
 * @description CV list, upload, extract, set-active, and delete.
 */

import { esc, flash, spinner, postJson, getJson } from './jobUtil.js';
import { refreshJobCvSelect } from './jobViews.js';

export function initCvManager() {
    const form = document.getElementById('cv-upload-form');
    if (form) form.addEventListener('submit', uploadCv);

    const fileInput = document.getElementById('cv-file-input');
    if (fileInput) fileInput.addEventListener('change', () => syncFileState());

    const removeBtn = document.getElementById('cv-file-remove');
    if (removeBtn) removeBtn.addEventListener('click', clearFile);

    const dropzone = document.getElementById('cv-dropzone');
    if (dropzone) {
        ['dragenter', 'dragover'].forEach(ev => dropzone.addEventListener(ev, e => {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.add('border-cyan-400', 'bg-cyan-500/10');
        }));
        ['dragleave', 'drop'].forEach(ev => dropzone.addEventListener(ev, e => {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.remove('border-cyan-400', 'bg-cyan-500/10');
        }));
        dropzone.addEventListener('drop', e => {
            const file = e.dataTransfer.files && e.dataTransfer.files[0];
            if (file) setFile(file);
        });
    }

    window.loadCvs = loadCvs;
    window.extractCv = extractCv;
    window.setActiveCv = setActiveCv;
    window.deleteCv = deleteCv;
}

function setFile(file) {
    const input = document.getElementById('cv-file-input');
    if (!input) return;
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    syncFileState();
}

function clearFile() {
    const input = document.getElementById('cv-file-input');
    if (input) input.value = '';
    syncFileState();
}

function syncFileState() {
    const input = document.getElementById('cv-file-input');
    const file = input && input.files && input.files[0];
    const chip = document.getElementById('cv-file-chip');
    const nameEl = document.getElementById('cv-file-name');
    const sizeEl = document.getElementById('cv-file-size');
    const submitBtn = document.getElementById('cv-upload-submit');

    if (!file) {
        if (chip) chip.classList.add('hidden');
        if (submitBtn) submitBtn.disabled = true;
        return;
    }

    if (nameEl) nameEl.textContent = file.name;
    if (sizeEl) sizeEl.textContent = fmtSize(file.size);
    if (chip) chip.classList.remove('hidden');
    if (submitBtn) submitBtn.disabled = false;
    clearUploadError();
}

function fmtSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

function showUploadError(msg) {
    const el = document.getElementById('cv-upload-error');
    const text = document.getElementById('cv-upload-error-text');
    if (el) el.classList.remove('hidden');
    if (text) text.textContent = msg;
}

function clearUploadError() {
    const el = document.getElementById('cv-upload-error');
    if (el) el.classList.add('hidden');
}

export async function loadCvs() {
    const container = document.getElementById('cv-list-container');
    if (!container) return;
    container.innerHTML = spinner();

    let data;
    try {
        data = await getJson('list_cvs');
    } catch (e) {
        container.innerHTML = '<p class="text-rose-400 text-[10px] uppercase font-bold text-center py-10">Failed to load CVs.</p>';
        return;
    }

    if (data.status !== 'success') {
        container.innerHTML = '<p class="text-rose-400 text-[10px] uppercase font-bold text-center py-10">Failed to load CVs.</p>';
        return;
    }

    if (!data.cvs || data.cvs.length === 0) {
        container.innerHTML = `
            <div class="text-center py-14 flex flex-col items-center justify-center gap-3 select-none rounded-xl border border-dashed border-slate-800 bg-[#0a0f1d]/40">
                <uk-icon icon="file-text" class="w-10 h-12 text-slate-700 opacity-40"></uk-icon>
                <p class="text-[10px] tracking-widest uppercase font-bold text-slate-500">No CVs yet</p>
                <p class="text-[9px] text-slate-600">Drop your resume in the panel above to add your first CV.</p>
            </div>`;
    } else {
        container.innerHTML = '';
        data.cvs.forEach(cv => container.appendChild(renderCvCard(cv)));
    }

    refreshJobCvSelect();
}

function renderCvCard(cv) {
    const card = document.createElement('div');
    const active = cv.active_flag == 1;
    card.className = 'p-4 rounded-xl border bg-[#0a0f1d]/70 transition-colors ' + (active ? 'border-cyan-500/30 hover:border-cyan-500/50' : 'border-slate-850 hover:border-slate-700');
    const hasMarkdown = cv.extracted_markdown && String(cv.extracted_markdown).trim() !== '';

    card.innerHTML = `
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 flex-wrap">
                    <uk-icon icon="file-text" class="w-4 h-4 text-cyan-400/70 shrink-0"></uk-icon>
                    <span class="text-[12px] font-bold text-slate-100 truncate">${esc(cv.designation)}</span>
                    ${active ? '<span class="px-1.5 py-0.5 text-[8px] font-extrabold tracking-widest uppercase bg-cyan-950/50 border border-cyan-500/30 text-cyan-400 rounded-md shrink-0">Active</span>' : ''}
                </div>
                <div class="text-[9px] text-slate-500 font-mono mt-1 truncate">${esc(cv.file_hash || '')}</div>
            </div>
            <div class="flex gap-1.5 shrink-0 flex-wrap justify-end">
                <button onclick="window.extractCv('${cv.uuid}', this)" class="px-2.5 py-1 rounded-md text-[9px] font-bold uppercase tracking-wider border border-cyan-500/30 text-cyan-400 hover:bg-cyan-900/40 hover:border-cyan-400/50 transition-all cursor-pointer outline-none">Extract Details</button>
                ${active ? '' : `<button onclick="window.setActiveCv('${cv.uuid}')" class="px-2.5 py-1 rounded-md text-[9px] font-bold uppercase tracking-wider border border-slate-700 text-slate-300 hover:text-cyan-400 hover:border-cyan-500/40 transition-all cursor-pointer outline-none">Set Active</button>`}
                <button onclick="window.deleteCv('${cv.uuid}')" class="px-2.5 py-1 rounded-md text-[9px] font-bold uppercase tracking-wider border border-slate-700 text-slate-400 hover:text-rose-400 hover:border-rose-500/40 hover:bg-rose-900/20 transition-all cursor-pointer outline-none">Delete</button>
            </div>
        </div>
        ${hasMarkdown ? `<pre class="mt-3 text-[10px] text-slate-300 whitespace-pre-wrap bg-[#060b13] border border-slate-900 p-3 rounded-lg max-h-48 overflow-y-auto leading-relaxed">${esc(cv.extracted_markdown)}</pre>` : '<p class="text-[9px] text-slate-500 mt-3 italic">Not extracted yet &mdash; click <span class="text-cyan-400 not-italic font-bold">Extract Details</span> to build the profile.</p>'}
    `;
    return card;
}

async function uploadCv(e) {
    e.preventDefault();
    const fileInput = document.getElementById('cv-file-input');
    const designationInput = document.getElementById('cv-designation');
    const file = fileInput && fileInput.files && fileInput.files[0];

    if (!file) {
        showUploadError('Choose a CV file first — drop it above or click the panel to browse.');
        flash('Choose a CV file first.', false);
        return;
    }

    const formData = new FormData();
    formData.append('cv', file);
    formData.append('designation', designationInput ? designationInput.value : '');

    setUploading(true);
    try {
        const res = await fetch('index.php?api_action=upload_cv', { method: 'POST', body: formData });
        const data = await res.json().catch(() => ({ status: 'error', message: 'Server returned an invalid response (HTTP ' + res.status + ').' }));

        if (data.status === 'success') {
            flash('CV uploaded.');
            if (designationInput) designationInput.value = '';
            clearFile();
            loadCvs();
        } else {
            showUploadError(data.message || 'Upload failed.');
            flash(data.message || 'Upload failed.', false);
        }
    } catch (err) {
        showUploadError('Upload failed — ' + (err && err.message ? err.message : 'network error'));
        flash('Upload failed.', false);
    } finally {
        setUploading(false);
    }
}

function setUploading(on) {
    const submitBtn = document.getElementById('cv-upload-submit');
    const label = document.getElementById('cv-upload-label');
    if (!submitBtn) return;
    if (on) {
        submitBtn.disabled = true;
        if (label) label.textContent = 'Uploading…';
    } else {
        const input = document.getElementById('cv-file-input');
        submitBtn.disabled = !(input && input.files && input.files.length);
        if (label) label.textContent = 'Upload CV';
    }
}

export async function extractCv(uuid, button) {
    const originalLabel = button ? button.textContent : '';
    if (button) {
        button.disabled = true;
        button.textContent = 'Extracting…';
    }

    const data = await postJson('extract_cv', { cv_uuid: uuid });

    if (data.status === 'success') {
        flash('CV details extracted.');
        loadCvs();
    } else {
        flash(data.message || 'Extraction failed.', false);
        if (button) {
            button.disabled = false;
            button.textContent = originalLabel || 'Extract Details';
        }
    }
}

export async function setActiveCv(uuid) {
    const data = await postJson('set_active_cv', { cv_uuid: uuid });
    if (data.status === 'success') {
        flash('CV marked active.');
        loadCvs();
    } else {
        flash(data.message || 'Failed to set active.', false);
    }
}

export async function deleteCv(uuid) {
    if (!confirm('Delete this CV? Existing applications keep their stored snapshot.')) return;
    const data = await postJson('delete_cv', { cv_uuid: uuid });
    if (data.status === 'success') {
        flash('CV deleted.');
        loadCvs();
    } else {
        flash(data.message || 'Delete failed.', false);
    }
}
