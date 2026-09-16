/**
 * @file js/chat/reasoningEffort.js
 * @description Reasoning-effort control. Slider (low/medium/high) on runtimes with
 * a graduated effort_map; on/off toggle on binary runtimes. Choice persists to the
 * DB (set_reasoning_effort) and is sent with each chat via the hidden #effort-input.
 */

import { requestJson, notify, clearNotice } from '../workspace/feedback.js';

const VALID = ['low', 'medium', 'high', 'off'];

function setActive(control, value) {
    control.querySelectorAll('.effort-btn').forEach((btn) => {
        const on = btn.dataset.effort === value;
        btn.classList.toggle('effort-active', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
}

async function persist(effort) {
    const fd = new FormData();
    fd.append('action', 'set_reasoning_effort');
    fd.append('effort', effort);
    const result = await requestJson('index.php', { method: 'POST', body: fd });
    if (result.status !== 'saved') throw new Error('The reasoning setting could not be saved.');
}

async function initReasoningEffort() {
    const hidden = document.getElementById('effort-input');
    const graduatedEl = document.getElementById('effort-graduated');
    const binaryEl = document.getElementById('effort-binary');
    if (!hidden || !graduatedEl || !binaryEl) return;

    // Control type and selected value are rendered server-side: the active
    // control is already visible and #effort-input already holds the saved
    // value. No async fetch — the control is correct on first paint.
    const graduated = typeof reasoningEffortGraduated !== 'undefined' ? reasoningEffortGraduated : false;
    let effort = VALID.includes(hidden.value) ? hidden.value : 'medium';

    const control = graduated ? graduatedEl : binaryEl;
    control.style.display = 'flex';
    hidden.value = effort;
    setActive(control, effort);

    control.querySelectorAll('.effort-btn').forEach((btn) => {
        btn.addEventListener('click', async () => {
            if (control.getAttribute('aria-busy') === 'true' || effort === btn.dataset.effort) return;
            control.setAttribute('aria-busy', 'true');
            control.querySelectorAll('button').forEach(button => { button.disabled = true; });
            try {
                await persist(btn.dataset.effort);
                effort = btn.dataset.effort;
                hidden.value = effort;
                setActive(control, effort);
                clearNotice('reasoning-setting');
            } catch {
                notify('The reasoning setting could not be saved. Your previous choice is still selected; try again.', { id: 'reasoning-setting', target: document.getElementById('composer-notices') });
            } finally {
                control.removeAttribute('aria-busy');
                control.querySelectorAll('button').forEach(button => { button.disabled = false; });
            }
        });
    });
}

export { initReasoningEffort };
