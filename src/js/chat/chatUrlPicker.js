/**
 * @file js/chat/chatUrlPicker.js
 * @description "Parse links" control above the composer: one remembered on/off, submitted with the message.
 *
 * The control is a setting, not a URL picker. It does no work of its own: nothing is indexed, fetched or
 * enumerated here, and the box is never watched. It reads its state from localStorage, paints itself, and
 * sends that state with the prompt — the backend then decides what, if anything, to open (see
 * ChatManager::resolveDirectSources). That keeps one implementation of "what is a fetchable URL" and one
 * place where the decision is made.
 *
 * First run: ON, which is the app's own link rule (a message that is essentially just a link is read, a
 * pasted page among prose opens nothing on its own). Turning it OFF means nothing is opened and the prompt
 * is not even scanned.
 */

const STORAGE_KEY = 'parse_links';

let parseOn = true;
let toggleEl = null;

function paint() {
    if (!toggleEl) return;
    toggleEl.querySelectorAll('[data-parse-links]').forEach((btn) => {
        const active = (btn.dataset.parseLinks === 'on') === parseOn;
        btn.classList.toggle('effort-active', active);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
}

/** Stored as '1' / '0'; anything else (including nothing stored yet) means ON. */
function read() {
    try {
        return localStorage.getItem(STORAGE_KEY) !== '0';
    } catch {
        return true;                             // storage blocked: fall back to the app's link rule
    }
}

function persist() {
    try {
        localStorage.setItem(STORAGE_KEY, parseOn ? '1' : '0');
    } catch { /* a blocked store just means the choice isn't remembered */ }
}

/** The decision to submit with the message: '1' = let the backend apply its link rule, '0' = open nothing. */
export function getParseLinks() {
    return parseOn ? '1' : '0';
}

export function initUrlPicker() {
    toggleEl = document.getElementById('parse-links-toggle');
    if (!toggleEl) return;

    parseOn = read();
    paint();

    toggleEl.querySelectorAll('[data-parse-links]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const next = btn.dataset.parseLinks === 'on';
            if (next === parseOn) return;
            parseOn = next;
            paint();
            persist();
        });
    });
}
