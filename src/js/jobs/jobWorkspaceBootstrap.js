/**
 * @file js/jobs/jobWorkspaceBootstrap.js
 * @description Bootstrap the job tracker workspace modules and expose handlers on window.
 */

import { switchJobView, refreshJobCvSelect, closeJobSetup } from './jobViews.js';
import { initInbox, loadInbox } from './jobInbox.js';
import { initDetails, clearDetails } from './jobDetails.js';
import './jobActions.js';
import { initBatchSelect } from './jobBatchSelect.js';
import { initProgress } from './jobProgress.js';
import { initCvManager, loadCvs } from './cvManager.js';
import { initProfileEditor, loadProfile } from './profileEditor.js';
import { initRegistryManager, loadRegistry } from './registryManager.js';

window.switchJobView = switchJobView;
window.refreshJobCvSelect = refreshJobCvSelect;

initCvManager();
initProfileEditor();
initRegistryManager();
initInbox();
initDetails();
initBatchSelect();
initProgress();

switchJobView('details');
clearDetails();

let loaded = false;
document.addEventListener('jobs-opened', () => {
    if (!loaded) { loaded = true; loadCvs(); loadProfile(); loadRegistry(); refreshJobCvSelect(); loadInbox(); }
});
document.getElementById('job-setup-open')?.addEventListener('click', () => switchJobView('cvs'));
document.getElementById('job-setup-close')?.addEventListener('click', closeJobSetup);
document.getElementById('job-setup')?.addEventListener('cancel', e => { e.preventDefault(); closeJobSetup(); });
document.getElementById('job-setup')?.addEventListener('input', e => { const form = e.target.closest('form'); if (form) form.dataset.dirty = 'true'; });
document.getElementById('job-activity-back')?.addEventListener('click', clearDetails);
