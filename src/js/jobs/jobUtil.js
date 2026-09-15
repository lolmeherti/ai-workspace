import { requestJson, notify } from '../workspace/feedback.js';
/**
 * @file js/jobs/jobUtil.js
 * @description Shared helpers for the job tracker management views.
 */

export function esc(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

export function flash(message, ok = true) {
    notify(message, { target: document.getElementById('job-setup')?.open ? document.getElementById('job-setup-notices') : document.getElementById('job-notices'), id: 'job-flash', kind: ok ? 'success' : 'error' });
}

export function spinner() {
    return `<div class="text-center py-10 text-cyan-400 flex items-center justify-center gap-2 select-none"><svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg><span class="text-xs font-bold tracking-normal normal-case animate-pulse">Loading...</span></div>`;
}

export async function postJson(apiAction, body) {
    const res = await safeRequest(`index.php?api_action=${apiAction}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(body).toString(),
    });
    return res;
}

export async function getJson(apiAction, params = {}) {
    const qs = new URLSearchParams({ api_action: apiAction, ...params });
    const res = await safeRequest(`index.php?${qs}`);
    return res;
}

export const STATE_LABELS = {
    unread: 'Unread', interested: 'Interested', applied: 'Applied',
    interview: 'Interview', offer: 'Offer', history: 'History',
};

export const STATE_ORDER = ['unread', 'interested', 'applied', 'interview', 'offer', 'history'];

export function dateOnly(dt) {
    return String(dt ?? '').slice(0, 10);
}

export function toLocalInput(dt) {
    const s = String(dt ?? '');
    if (s === '') return '';
    return s.includes('T') ? s.slice(0, 16) : s.slice(0, 16).replace(' ', 'T');
}

export function fmtDate(dt) {
    const s = String(dt ?? '');
    return s === '' ? '' : s.slice(0, 16).replace('T', ' ');
}

async function safeRequest(url, options) {
    try { return await requestJson(url, options); }
    catch (e) { flash(e.message, false); return { status: 'error', message: e.message, code: e.code }; }
}
