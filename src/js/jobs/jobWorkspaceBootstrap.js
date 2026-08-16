/**
 * @file js/jobs/jobWorkspaceBootstrap.js
 * @description Bootstrap the job tracker workspace modules and expose handlers on window.
 */

import { switchJobView, refreshJobCvSelect } from './jobViews.js';
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

document.addEventListener('jobs-opened', () => {
    loadCvs();
    loadProfile();
    loadRegistry();
    refreshJobCvSelect();
    loadInbox();
});
