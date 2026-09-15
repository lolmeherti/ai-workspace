import { requestJson, notify, clearNotice } from '../workspace/feedback.js';
const REASONS = window.REPLY_DOWNVOTE_REASONS || {};
const TOOL_REASONS = window.REPLY_TOOL_TURN_REASONS || [];
const THUMB_UP =
    '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10v12M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/></svg>';
const THUMB_DOWN =
    '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 2v12M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 6.5 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2.76a2 2 0 0 0-1.79 1.11L12 22a3.13 3.13 0 0 1-3-3.88Z"/></svg>';

const RATE_BTN_CLASS =
    'rate-btn w-7 h-7 flex items-center justify-center rounded-lg border border-slate-700 text-slate-500 hover:text-slate-200 hover:border-slate-500 transition-colors cursor-pointer bg-transparent';

function setRatingState(container, rating, reason = '') {
    container.dataset.rating = rating === null ? '' : String(rating);
    container.dataset.reason = reason;
    container.querySelectorAll('[data-rate]').forEach(btn => {
        const selected = rating === Number(btn.dataset.rate);
        btn.classList.toggle(btn.dataset.rate === '1' ? 'rate-active-up' : 'rate-active-down', selected);
        btn.setAttribute('aria-pressed', String(selected));
        btn.setAttribute('aria-label', btn.dataset.rate === '1' ? 'Helpful reply' : 'Unhelpful reply');
    });
    let status = container.querySelector('.rating-status');
    if (!status) { status = document.createElement('span'); status.className = 'rating-status'; status.setAttribute('role', 'status'); container.append(status); }
    status.textContent = rating === 0 ? 'Saved: ' + (REASONS[reason] || 'Unhelpful') : rating === 1 ? 'Saved: helpful' : '';
}
function closeMenu(container, focus = false) {
    container.querySelector('.reason-menu')?.classList.add('hidden');
    const down = container.querySelector('[data-rate="0"]'); down?.setAttribute('aria-expanded', 'false');
    if (focus) down?.focus();
}
function showMenu(container) {
    document.querySelectorAll('.reply-rating').forEach(c => closeMenu(c));
    let menu = container.querySelector('.reason-menu');
    if (!menu) { menu = document.createElement('div'); menu.className = 'reason-menu'; container.append(menu); }
    menu.replaceChildren(); menu.setAttribute('role', 'group'); menu.setAttribute('aria-label', 'Why was this reply unhelpful?');
    const title = document.createElement('span'); title.textContent = 'What went wrong?'; menu.append(title);
    Object.entries(REASONS).filter(([key]) => !TOOL_REASONS.includes(key) || container.dataset.hadToolCalls === '1').forEach(([key, label]) => {
        const btn = document.createElement('button'); btn.type = 'button'; btn.dataset.reason = key; btn.textContent = label; btn.className = 'ui-button'; menu.append(btn);
    });
    const cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'ui-button'; cancel.textContent = 'Cancel'; cancel.addEventListener('click', () => closeMenu(container, true)); menu.append(cancel);
    menu.classList.remove('hidden'); container.querySelector('[data-rate="0"]').setAttribute('aria-expanded', 'true'); menu.querySelector('button')?.focus();
}
export function renderReplyRating(bubble, opts = {}) {
    const id = opts.message_id ?? opts.messageId; if (!id) return null;
    const c = document.createElement('div'); c.className = 'reply-rating'; c.dataset.messageId = String(id); c.dataset.hadToolCalls = opts.had_tool_calls ? '1' : '0';
    c.innerHTML = `<button type="button" data-rate="1" class="${RATE_BTN_CLASS}">${THUMB_UP}</button><button type="button" data-rate="0" class="${RATE_BTN_CLASS}">${THUMB_DOWN}</button>`;
    setRatingState(c, opts.rating ?? null, opts.reason || ''); bubble.append(c); return c;
}
export async function postRating(container, rating, reason) {
    if (container.dataset.pending === 'true') return;
    container.dataset.pending = 'true'; container.setAttribute('aria-busy', 'true');
    container.querySelectorAll('button').forEach(b => { b.disabled = true; });
    const id = Number(container.dataset.messageId);
    clearNotice('rating-error', container);
    try {
        if (container.dataset.uncertain === 'true') {
            const actual = await requestJson(`index.php?api_action=get_reply_rating&message_id=${id}`, { timeout: 7000 });
            setRatingState(container, actual.rating ?? null, actual.reason || '');
            delete container.dataset.uncertain;
            if (actual.rating === rating && (rating !== 0 || actual.reason === reason)) { closeMenu(container, true); return; }
        }
        const data = await requestJson('index.php?api_action=rate_reply', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ message_id: id, rating, ...(reason === undefined ? {} : { reason }) }) });
        setRatingState(container, data.rating ?? null, data.reason || ''); closeMenu(container, true);
    } catch (error) {
        // A lost response can still mean the server saved the vote. Read before offering a retry.
        let reconciled = false;
        try {
            const actual = await requestJson(`index.php?api_action=get_reply_rating&message_id=${id}`, { timeout: 7000 });
            setRatingState(container, actual.rating ?? null, actual.reason || '');
            reconciled = actual.rating === rating && (rating !== 0 || actual.reason === reason);
        } catch { container.dataset.uncertain = 'true'; }
        if (reconciled) closeMenu(container, true);
        else notify('Could not confirm that feedback was saved. Your selected reason is kept.', { target: container, id: 'rating-error', action: container.dataset.uncertain === 'true' ? 'Check saved feedback' : 'Retry', onAction: () => postRating(container, rating, reason) });
    } finally {
        delete container.dataset.pending; container.removeAttribute('aria-busy'); container.querySelectorAll('button').forEach(b => { b.disabled = false; });
    }
}
let initialized = false;
export function initReplyRating() {
    if (initialized) return; initialized = true;
    const hydrate = () => document.querySelectorAll('.reply-rating').forEach(c => setRatingState(c, c.dataset.rating === '' ? null : Number(c.dataset.rating), c.dataset.reason || ''));
    hydrate(); document.addEventListener('workspace-content-ready', hydrate);
    document.addEventListener('click', e => {
        const c = e.target.closest('.reply-rating');
        if (!c) { document.querySelectorAll('.reply-rating').forEach(c => closeMenu(c)); return; }
        if (c.dataset.pending === 'true') return;
        const reason = e.target.closest('button[data-reason]'); if (reason) { postRating(c, 0, reason.dataset.reason); return; }
        const btn = e.target.closest('[data-rate]'); if (!btn) return;
        const want = Number(btn.dataset.rate), current = c.dataset.rating === '' ? null : Number(c.dataset.rating);
        if (want === 0 && current !== 0) showMenu(c); else postRating(c, current === want ? null : want);
    });
    document.addEventListener('keydown', e => { const c = e.target.closest('.reply-rating'); if (c && e.key === 'Escape') closeMenu(c, true); });
}
