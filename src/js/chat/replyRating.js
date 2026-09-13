/**
 * @file js/chat/replyRating.js
 * @description Per-reply thumbs up/down with a structured downvote-reason menu.
 * One delegated click listener serves BOTH server-rendered bubbles
 * (chat-window.php) and client-rendered bubbles (streamResponse.js done).
 */

const REASONS = window.REPLY_DOWNVOTE_REASONS || {};
const TOOL_REASONS = window.REPLY_TOOL_TURN_REASONS || [];

const THUMB_UP =
    '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10v12M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/></svg>';
const THUMB_DOWN =
    '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 2v12M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 6.5 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2.76a2 2 0 0 0-1.79 1.11L12 22a3.13 3.13 0 0 1-3-3.88Z"/></svg>';

const RATE_BTN_CLASS =
    'rate-btn w-7 h-7 flex items-center justify-center rounded-lg border border-slate-700 text-slate-500 hover:text-slate-200 hover:border-slate-500 transition-colors cursor-pointer bg-transparent';

function buildReasonMenu(hadToolCalls) {
    const keys = Object.keys(REASONS).filter((k) => {
        if (TOOL_REASONS.includes(k)) return !!hadToolCalls;
        return true;
    });
    return (
        `<div class="reason-menu hidden flex flex-wrap gap-1 mt-1.5">` +
        keys
            .map(
                (k) =>
                    `<button type="button" data-reason="${k}" class="text-[10px] px-2 py-1 rounded-full border border-slate-700 bg-slate-800/60 text-slate-300 hover:border-slate-500 hover:text-slate-100 transition-colors cursor-pointer">${REASONS[k]}</button>`
            )
            .join('') +
        `</div>`
    );
}

/**
 * Inject a rating control into a freshly-streamed assistant bubble.
 * Server-rendered bubbles already carry the markup; this only runs for the
 * live stream path.
 */
export function renderReplyRating(bubble, opts = {}) {
    const messageId = opts.message_id ?? opts.messageId;
    if (!messageId) return null;

    const hadToolCalls = opts.had_tool_calls ? 1 : 0;
    const rating = opts.rating ?? null;
    const reason = opts.reason ?? '';

    const container = document.createElement('div');
    container.className = 'reply-rating flex items-center gap-1 mt-2';
    container.dataset.messageId = String(messageId);
    container.dataset.hadToolCalls = String(hadToolCalls);
    container.dataset.rating = rating === null ? '' : String(rating);
    container.dataset.reason = reason || '';

    container.innerHTML =
        `<button type="button" data-rate="1" title="Good reply" class="${RATE_BTN_CLASS} ${rating === 1 ? 'rate-active-up' : ''}">${THUMB_UP}</button>` +
        `<button type="button" data-rate="0" title="Bad reply" class="${RATE_BTN_CLASS} ${rating === 0 ? 'rate-active-down' : ''}">${THUMB_DOWN}</button>` +
        buildReasonMenu(hadToolCalls);

    bubble.appendChild(container);
    return container;
}

function setRatingState(container, rating, reason) {
    container.dataset.rating = rating === null ? '' : String(rating);
    container.dataset.reason = reason || '';
    const up = container.querySelector('[data-rate="1"]');
    const down = container.querySelector('[data-rate="0"]');
    if (up) up.classList.toggle('rate-active-up', rating === 1);
    if (down) down.classList.toggle('rate-active-down', rating === 0);
}

async function postRating(container, rating, reason) {
    const messageId = Number(container.dataset.messageId);
    if (!Number.isFinite(messageId) || messageId <= 0) {
        console.warn('rate_reply: invalid message_id, skipping:', container.dataset.messageId);
        return;
    }
    const body = { message_id: messageId, rating };
    if (reason !== undefined && reason !== null) body.reason = reason;
    try {
        const res = await fetch('index.php?api_action=rate_reply', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            console.error('rate_reply failed:', res.status, data.message || '');
            return;
        }
        if (data.status === 'ok') {
            setRatingState(container, data.rating ?? null, data.reason ?? '');
        }
    } catch (e) {
        console.error('rate_reply failed:', e);
    }
}

function closeAllMenus() {
    document.querySelectorAll('.reply-rating .reason-menu:not(.hidden)').forEach((m) => m.classList.add('hidden'));
}

function onDocumentClick(e) {
    const reasonBtn = e.target.closest('button[data-reason]');
    if (reasonBtn) {
        const container = reasonBtn.closest('.reply-rating');
        const reason = reasonBtn.dataset.reason;
        const menu = container.querySelector('.reason-menu');
        if (menu) menu.classList.add('hidden');
        postRating(container, 0, reason);
        return;
    }

    const rateBtn = e.target.closest('[data-rate]');
    if (rateBtn) {
        const container = rateBtn.closest('.reply-rating');
        const want = Number(rateBtn.dataset.rate);
        const current = container.dataset.rating === '' ? null : Number(container.dataset.rating);

        if (want === 0) {
            if (current === 0) {
                // already downvoted -> second click clears it
                postRating(container, null);
            } else {
                // open the reason menu
                const menu = container.querySelector('.reason-menu');
                if (menu) menu.classList.remove('hidden');
            }
            return;
        }

        // upvote: toggle (click again clears)
        postRating(container, current === 1 ? null : 1);
        return;
    }

    closeAllMenus();
}

/** Set up the delegated listener + inject the button styles once. */
export function initReplyRating() {
    const styleId = 'reply-rating-style';
    if (!document.getElementById(styleId)) {
        const style = document.createElement('style');
        style.id = styleId;
        style.textContent = `
            .rate-btn.rate-active-up { color: #34d399; border-color: rgba(52, 211, 153, 0.5); background: rgba(52, 211, 153, 0.1); }
            .rate-btn.rate-active-down { color: #fb7185; border-color: rgba(251, 113, 133, 0.5); background: rgba(251, 113, 133, 0.1); }
        `;
        document.head.appendChild(style);
    }
    document.addEventListener('click', onDocumentClick);
}
