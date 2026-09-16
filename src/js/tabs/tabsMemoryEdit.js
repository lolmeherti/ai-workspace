import { ensureAIAvailable, paintAvailability, refreshAvailability, reportBusy } from '../workspace/availability.js';
import { requestJson, notify } from '../workspace/feedback.js';
import { state } from '../state.js';

let initialized = false;
let pending = false;
let needsReload = false;
let reloading = false;
const panel = () => document.getElementById('panel-memories');
const dialog = () => document.getElementById('memory-consolidation-dialog');
const status = () => document.getElementById('memory-consolidation-status');

function updateSelection() {
    const root = panel();
    if (!root) return;
    const checkboxes = [...root.querySelectorAll('.memory-checkbox')];
    const selected = checkboxes.filter(input => input.checked).length;
    const all = document.getElementById('select-all-memories');
    if (all) { all.checked = selected > 0 && selected === checkboxes.length; all.indeterminate = selected > 0 && selected < checkboxes.length; }
    document.getElementById('bulk-delete-form')?.classList.toggle('hidden', selected === 0);
    const count = document.getElementById('selected-count');
    if (count) count.textContent = selected;
}

function paintPending() {
    state.memoryConsolidating = pending;
    const root = panel();
    if (!root) return;
    root.setAttribute('aria-busy', String(pending));
    root.querySelectorAll('input:not([type="hidden"]), textarea, button:not(#consolidate-btn)').forEach(control => {
        if (pending) { control.dataset.memoryWasDisabled = String(control.disabled); control.disabled = true; }
        else if (control.dataset.memoryWasDisabled !== undefined) { control.disabled = control.dataset.memoryWasDisabled === 'true'; delete control.dataset.memoryWasDisabled; }
    });
    document.getElementById('consolidate-text').textContent = pending ? 'View consolidation…' : 'Consolidate & clean';
    document.getElementById('memory-consolidation-start').disabled = pending || needsReload || reloading;
    paintAvailability();
}

export function openMemoryConsolidation() {
    const modal = dialog();
    if (modal && !modal.open) modal.showModal();
}

function replaceMemories(html) {
    const parsed = new DOMParser().parseFromString(html, 'text/html');
    const replacement = parsed.getElementById('panel-memories');
    if (!replacement) throw new Error('The memory list could not be refreshed. Reload the page before trying again.');
    replacement.classList.toggle('hidden', state.activeTab !== 'memories');
    replacement.querySelectorAll('form[action]').forEach(form => {
        const url = new URL(form.getAttribute('action'), location.href);
        url.searchParams.set('session_id', state.sessionId);
        form.setAttribute('action', url.href);
    });
    panel()?.replaceWith(replacement);
    updateSelection();
    document.dispatchEvent(new Event('workspace-content-ready'));
}

export async function runMemoryConsolidation() {
    if (pending || needsReload || reloading || !ensureAIAvailable(status())) return;
    if (panel()?.querySelector('form[data-dirty="true"]')) {
        notify('Save or cancel your memory edits before consolidating. Your draft is kept.', { target: status() });
        return;
    }
    const form = document.getElementById('consolidate-form');
    if (!form) return;
    const body = new FormData(form);
    body.set('session_id', state.sessionId);
    pending = true; paintPending();
    status().replaceChildren();
    const indicator = document.createElement('div');
    indicator.className = 'memory-consolidation-progress'; indicator.setAttribute('role', 'status');
    indicator.innerHTML = '<span class="ui-spinner" aria-hidden="true"></span><span>Consolidating memories… You can close this window and keep reading.</span>';
    status().append(indicator);
    document.getElementById('memory-consolidation-reload').classList.add('hidden');
    try {
        const result = await requestJson(form.action, { method: 'POST', headers: { Accept: 'application/json' }, body });
        replaceMemories(result.html);
        notify(result.message || 'Memory list refreshed. Review your saved memories.', { target: status(), kind: 'success' });
        document.getElementById('memory-consolidation-start').textContent = 'Consolidate again';
        if (!dialog().open) notify('Memory list refreshed. Review your saved memories.', { id: 'memory-consolidation-finished', kind: 'success', action: 'View memories', onAction: () => window.switchSidebarTab?.('memories') });
    } catch (error) {
        const busy = error.code === 'model_busy';
        needsReload = !busy;
        if (busy) reportBusy(error.message);
        notify(busy ? error.message : 'Could not confirm consolidation completed. Reload memories to check their current state before trying again.', { target: status() });
        document.getElementById('memory-consolidation-reload').classList.toggle('hidden', busy);
    } finally {
        pending = false; paintPending(); refreshAvailability();
        indicator.remove();
    }
}

async function reloadMemories() {
    if (pending || reloading) return;
    if (panel()?.querySelector('form[data-dirty="true"]')) {
        notify('Save or cancel your memory edits before reloading. Your draft is kept.', { target: status() });
        return;
    }
    reloading = true;
    document.getElementById('memory-consolidation-start').disabled = true;
    const button = document.getElementById('memory-consolidation-reload');
    button.disabled = true;
    try {
        const response = await fetch(`index.php?session_id=${state.sessionId}&tab=memories`, { headers: { Accept: 'text/html' } });
        if (!response.ok) throw new Error('Unable to reload memories.');
        replaceMemories(await response.text());
        needsReload = false;
        notify('Memory list refreshed. Review it before running consolidation again.', { target: status(), kind: 'info' });
        button.classList.add('hidden');
    } catch { notify('The memory list could not be reloaded. Check the connection and try again.', { target: status() }); }
    finally { reloading = false; button.disabled = false; document.getElementById('memory-consolidation-start').disabled = needsReload; }
}

export function initMemoryTab() {
    const init = () => {
        if (initialized || !panel() || !dialog()) return;
        initialized = true;
        window.openMemoryConsolidation = openMemoryConsolidation;
        document.addEventListener('submit', event => {
            if (!event.target.closest('#panel-memories')) return;
            if (event.target.id === 'consolidate-form') { event.preventDefault(); openMemoryConsolidation(); }
            else if (pending) event.preventDefault();
        });
        document.addEventListener('input', event => {
            const form = event.target.closest('#add-memory-form, .memory-edit-form');
            if (form) form.dataset.dirty = 'true';
        });
        document.addEventListener('change', event => {
            if (event.target.id === 'select-all-memories') panel()?.querySelectorAll('.memory-checkbox').forEach(input => { input.checked = event.target.checked; });
            if (event.target.id === 'select-all-memories' || event.target.matches('.memory-checkbox')) updateSelection();
        });
        dialog().querySelectorAll('[data-memory-consolidation-close]').forEach(button => button.addEventListener('click', () => dialog().close()));
        document.getElementById('memory-consolidation-start').addEventListener('click', runMemoryConsolidation);
        document.getElementById('memory-consolidation-reload').addEventListener('click', reloadMemories);
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
}

initMemoryTab();
