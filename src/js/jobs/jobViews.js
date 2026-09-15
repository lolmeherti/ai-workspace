import { getJson, flash } from './jobUtil.js';
import { confirmAction } from '../workspace/feedback.js';
export function switchJobView(viewName) {
    if (['cvs', 'profile', 'registry'].includes(viewName)) {
        const dialog = document.getElementById('job-setup');
        if (!dialog.open) { dialog.showModal(); document.dispatchEvent(new Event('job-setup-opened')); }
        if (viewName === 'registry') document.getElementById('job-source-settings').open = true;
        document.getElementById('job-view-' + viewName)?.scrollIntoView({ block: 'nearest' });
    } else if (['progress', 'logs'].includes(viewName)) {
        document.getElementById('job-run-activity').open = true;
        document.getElementById('job-view-' + viewName)?.classList.remove('hidden');
    }
}
export async function closeJobSetup() {
    const dialog = document.getElementById('job-setup');
    if (dialog.querySelector('[aria-busy="true"]')) { flash('Wait for the current operation to finish.', false); return; }
    if (dialog.querySelector('form[data-dirty="true"]')) {
        if (!await confirmAction('Unsaved changes will stay here for this visit. Searches use your last saved preferences and sources.', { title: 'Keep changes for later?', confirmLabel: 'Keep draft and close' })) return;
    }
    dialog.close(); document.getElementById('job-setup-open').focus();
}
export async function refreshJobCvSelect(cvs) {
    const select = document.getElementById('job-cv-select'); if (!select) return;
    if (!Array.isArray(cvs)) { const data = await getJson('list_cvs'); if (data.status !== 'success') return; cvs = data.cvs || []; }
    const current = select.value;
    select.replaceChildren(new Option('Select a CV', ''));
    cvs.forEach(cv => select.add(new Option(cv.designation + (cv.active_flag == 1 ? ' · default' : ''), cv.uuid)));
    select.value = cvs.some(cv => cv.uuid === current) ? current : cvs.find(cv => cv.active_flag == 1)?.uuid || '';
}
