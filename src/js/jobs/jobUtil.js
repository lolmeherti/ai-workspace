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
    const existing = document.getElementById('job-flash');
    if (existing) existing.remove();

    const el = document.createElement('div');
    el.id = 'job-flash';
    el.className = `fixed bottom-4 right-4 px-4 py-2.5 rounded-lg border text-[10px] font-bold uppercase tracking-widest shadow-lg z-50 ${ok ? 'bg-emerald-950/80 border-emerald-500/40 text-emerald-400' : 'bg-rose-950/80 border-rose-500/40 text-rose-400'}`;
    el.textContent = message;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 3000);
}

export function spinner() {
    return `<div class="text-center py-10 text-cyan-400 flex items-center justify-center gap-2 select-none"><svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg><span class="text-[10px] font-bold tracking-widest uppercase animate-pulse">Loading...</span></div>`;
}

export async function postJson(apiAction, body) {
    const res = await fetch(`index.php?api_action=${apiAction}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(body).toString(),
    });
    return res.json();
}

export async function getJson(apiAction, params = {}) {
    const qs = new URLSearchParams({ api_action: apiAction, ...params });
    const res = await fetch(`index.php?${qs}`);
    return res.json();
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
