/**
 * @file js/chat/chatExport.js
 * @description Conversation export popover. Every switch maps to one query flag on
 * the export endpoint; the copied text and the downloaded files are rendered from
 * the same stored records, so the toggles can never drift between formats.
 */
import { requestJson, withPending } from '../workspace/feedback.js';
import { state } from '../state.js';

const panel = () => document.getElementById('export-panel');
const trigger = () => document.getElementById('export-toggle');
const copyButton = () => document.getElementById('export-copy');
const copyIcon = () => document.getElementById('export-copy-icon');
const STORAGE_KEY = 'localsy.export.options';
const CHECK_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
let copyIconMarkup = '';

function sessionId() {
    const input = document.querySelector('#chatForm input[name="session_id"]');
    const fromForm = Number(input?.value || 0);
    if (fromForm > 0) return fromForm;
    return Number(new URLSearchParams(window.location.search).get('session_id') || state.sessionId || 0);
}

/** @returns {Record<string, boolean>} every switch's state, keyed by its export flag. */
function optionState() {
    const state = {};
    panel()?.querySelectorAll('[data-export-option]').forEach(row => {
        state[row.dataset.exportOption] = row.getAttribute('aria-checked') === 'true';
    });
    return state;
}

function options() {
    const flags = {};
    Object.entries(optionState()).forEach(([key, on]) => { flags[key] = on ? '1' : '0'; });
    return flags;
}

/**
 * A saved setup is replayed on load, so the switches come back the way they were
 * left. Storage is unavailable in some contexts (private mode, blocked site data)
 * — every access is guarded, and then the markup defaults stand instead.
 */
function readStoredOptions() {
    try {
        const parsed = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}');
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
        return {};
    }
}

function applyStoredOptions() {
    const stored = readStoredOptions();
    panel()?.querySelectorAll('[data-export-option]').forEach(row => {
        const saved = stored[row.dataset.exportOption];
        if (typeof saved === 'boolean') row.setAttribute('aria-checked', saved ? 'true' : 'false');
    });
}

function persistOptions() {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(optionState()));
    } catch {
        // Not remembered this session — the export itself is unaffected.
    }
}

function buildUrl(format, delivery) {
    const params = new URLSearchParams({
        api_action: 'export_conversation',
        session_id: String(sessionId()),
        format,
        delivery,
        ...options()
    });
    return 'index.php?' + params.toString();
}

function setOpen(open) {
    const box = panel();
    const btn = trigger();
    if (!box || !btn) return;
    box.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
        requestAnimationFrame(syncScrollCue);
        box.querySelector('[data-export-option]')?.focus();
    }
}

/**
 * A panel too tall for the viewport scrolls, which slices the row at the fold.
 * Flag which edges have hidden content so CSS can fade them — a faded row reads
 * as "there is more", a hard cut reads as a rendering bug.
 */
function syncScrollCue() {
    const area = panel()?.querySelector('.export-panel-scroll');
    if (!area) return;
    area.classList.toggle('is-more-below', area.scrollTop + area.clientHeight < area.scrollHeight - 1);
    area.classList.toggle('is-more-above', area.scrollTop > 1);
}

function toggleRow(row) {
    row.setAttribute('aria-checked', row.getAttribute('aria-checked') === 'true' ? 'false' : 'true');
    persistOptions();
}

function flashCopied() {
    const button = copyButton();
    const icon = copyIcon();
    if (!button || !icon) return;
    icon.innerHTML = CHECK_ICON;
    button.classList.add('export-btn--done');
    setTimeout(() => {
        icon.innerHTML = copyIconMarkup;
        button.classList.remove('export-btn--done');
    }, 1500);
}

async function copyConversation() {
    const button = copyButton();
    await withPending(button, async () => {
        const res = await requestJson(buildUrl('txt', 'inline'));
        if (!res.text) throw new Error('The conversation could not be exported.');
        if (!navigator.clipboard?.writeText) throw new Error('Clipboard access is unavailable in this browser.');
        await navigator.clipboard.writeText(res.text);
        flashCopied();
        return null;
    });
}

function download(format) {
    const anchor = document.createElement('a');
    anchor.href = buildUrl(format, 'download');
    anchor.rel = 'noopener';
    document.body.append(anchor);
    anchor.click();
    anchor.remove();
}

export function initChatExport() {
    const btn = trigger();
    const box = panel();
    if (!btn || !box) return;

    copyIconMarkup = copyIcon()?.innerHTML || '';
    applyStoredOptions();

    btn.addEventListener('click', () => setOpen(box.hidden));
    box.querySelector('.export-panel-scroll')?.addEventListener('scroll', syncScrollCue, { passive: true });
    window.addEventListener('resize', syncScrollCue);

    box.addEventListener('click', event => {
        const row = event.target.closest('[data-export-option]');
        if (row) { toggleRow(row); return; }

        const down = event.target.closest('[data-export-download]');
        if (down) { download(down.dataset.exportDownload === 'json' ? 'json' : 'txt'); return; }

        if (event.target.closest('#export-copy')) copyConversation();
    });

    box.addEventListener('keydown', event => {
        const row = event.target.closest('[data-export-option]');
        if (!row) return;
        if (event.key === ' ' || event.key === 'Enter') { event.preventDefault(); toggleRow(row); }
    });

    document.addEventListener('click', event => {
        if (box.hidden) return;
        if (event.target.closest('#export-panel') || event.target.closest('#export-toggle')) return;
        setOpen(false);
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !box.hidden) setOpen(false);
    });
}
