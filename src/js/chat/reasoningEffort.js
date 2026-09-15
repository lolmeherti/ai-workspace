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

    // 1. Control type comes from the runtime policy's effort_map (graduated iff non-empty).
    let graduated = false;
    try {
        const r = await fetch('index.php?api_action=get_switch_status', { headers: { 'Accept': 'application/json' } });
        const st = await r.json();
        const policy = typeof st.runtime_policy === 'string' ? JSON.parse(st.runtime_policy) : (st.runtime_policy || {});
        const em = (policy.reasoning && policy.reasoning.effort_map) || {};
        graduated = Object.keys(em).length > 0;
    } catch (e) {
        graduated = false;
    }

    // 2. Load the saved choice (DB-backed, survives refresh).
    let effort = 'medium';
    try {
        const r = await fetch('index.php?api_action=get_reasoning_effort', { headers: { 'Accept': 'application/json' } });
        const j = await r.json();
        if (j.status === 'ok' && VALID.includes(j.effort)) effort = j.effort;
    } catch (e) {
        /* keep default */
    }

    // 3. Normalize to a value the visible control can express (model may have changed).
    if (graduated) {
        if (!['low', 'medium', 'high'].includes(effort)) effort = 'medium';
    } else {
        effort = effort === 'off' ? 'off' : 'medium';
    }

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
