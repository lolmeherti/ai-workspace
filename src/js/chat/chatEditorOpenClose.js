import { renderEditorBlocks } from './chatEditorRenderBlocks.js';
import { updateActiveTargetPill } from './chatEditorBlockSelection.js';
import { flushDraftChanges } from './draftSync.js';
import { requestJson, notify, confirmAction, withPending } from '../workspace/feedback.js';
import { state } from '../state.js';
let epoch = 0;
let baseline = '';
export function initEditorState() {
    window.activeEditFile ??= null; window.activeBlocks ??= []; window.activeToggledBlocks ??= new Set();
}
function showDrawer() {
    state.editorVisible = true;
    document.getElementById('chat-file-editor-drawer').dataset.open = 'true';
    const drawer = document.getElementById('chat-file-editor-drawer');
    drawer.classList.remove('w-0', 'border-transparent', 'inspector-hidden'); drawer.classList.add('w-[60%]', 'border-slate-800/80');
    document.getElementById('context-data-panel').hidden = true;
    document.getElementById('context-toggle')?.setAttribute('aria-expanded', 'false');
    document.getElementById('editor-file-title').textContent = window.activeEditFile;
    const resume = document.getElementById('editor-resume'); if (resume) resume.hidden = true;
    updateActiveTargetPill();
}
export async function openEditorDrawer(filename, button) {
    if (state.editorSaving || !filename || !await (window.canLeaveContext?.() ?? true)) return;
    if (filename === window.activeEditFile) { showDrawer(); return; }
    if (state.generation) { notify('Wait for the current AI task to finish before changing documents.'); return; }
    if (window.activeEditFile && !await discardEditorDraft()) return;
    const request = ++epoch;
    await withPending(button, async () => {
        const data = await requestJson(`index.php?api_action=open_draft&file=${encodeURIComponent(filename)}`);
        if (request !== epoch) return;
        window.activeEditFile = filename; window.activeBlocks = data.blocks; window.activeToggledBlocks.clear(); baseline = JSON.stringify(data.blocks);
        sessionStorage.setItem('activeEditFile', filename); sessionStorage.removeItem('activeToggledBlocks');
        showDrawer(); renderEditorBlocks(); document.dispatchEvent(new Event('workspace-content-ready'));
    });
}
export function closeEditorDrawer() {
    state.editorVisible = false;
    document.getElementById('chat-file-editor-drawer').dataset.open = 'false';
    const drawer = document.getElementById('chat-file-editor-drawer');
    drawer.classList.remove('w-[60%]', 'border-slate-800/80'); drawer.classList.add('w-0', 'border-transparent');
    let resume = document.getElementById('editor-resume');
    if (!resume && window.activeEditFile) {
        resume = document.createElement('button'); resume.type = 'button'; resume.id = 'editor-resume'; resume.className = 'ui-button'; resume.addEventListener('click', () => openEditorDrawer(window.activeEditFile));
        document.getElementById('context-toggle').parentElement.append(resume);
    }
    if (resume) { resume.textContent = 'Resume document'; resume.hidden = !window.activeEditFile; }
    updateActiveTargetPill();
}
export async function discardEditorDraft() {
    if (state.editorSaving) return false;
    if (!window.activeEditFile) return true;
    if (state.generation) { notify('Wait for the AI to finish before discarding this document draft.'); return false; }
    if (!await confirmAction('Discard this document draft and its edits? The saved file is unchanged.', { title: 'Discard document draft?', confirmLabel: 'Discard draft', destructive: true })) return false;
    try {
        await flushDraftChanges();
        await requestJson('index.php?api_action=discard_draft', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ file: window.activeEditFile }) });
        resetEditor(); return true;
    } catch (e) { notify(e.message, { target: document.getElementById('editor-notices') }); return false; }
}
function resetEditor() {
    window.activeEditFile = null; window.activeBlocks = []; window.activeToggledBlocks.clear(); baseline = '';
    sessionStorage.removeItem('activeEditFile'); sessionStorage.removeItem('activeToggledBlocks');
    document.getElementById('editor-blocks-container').replaceChildren(); closeEditorDrawer();
}
export async function saveEditorDraft() {
    if (!window.activeEditFile || state.generation || state.editorSaving) return;
    state.editorSaving = true;
    await withPending(document.getElementById('editor-save-btn'), async () => {
        document.activeElement?.blur(); await flushDraftChanges();
        await requestJson('index.php?api_action=save_draft', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ file: window.activeEditFile }) });
        resetEditor(); notify('Document saved.', { kind: 'success' });
    }, { target: document.getElementById('editor-notices') });
    state.editorSaving = false;
}
window.hasUnsavedEditor = () => !!window.activeEditFile && (JSON.stringify(window.activeBlocks) !== baseline || !!document.querySelector('#editor-blocks-container textarea'));
window.discardEditorDraft = discardEditorDraft;
initEditorState();
