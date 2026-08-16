/**
 * @file js/jobs/jobViews.js
 * @description View switching for the job tracker right pane + CV dropdown refresh.
 */

import { getJson } from './jobUtil.js';

export function switchJobView(viewName) {
    document.querySelectorAll('.job-view').forEach(el => el.classList.add('hidden'));
    const target = document.getElementById('job-view-' + viewName);
    if (target) target.classList.remove('hidden');

    document.querySelectorAll('.job-view-btn').forEach(btn => {
        const active = btn.dataset.view === viewName;
        btn.classList.toggle('text-cyan-400', active);
        btn.classList.toggle('bg-slate-900', active);
    });
}

export async function refreshJobCvSelect() {
    const select = document.getElementById('job-cv-select');
    if (!select) return;

    try {
        const data = await getJson('list_cvs');
        if (data.status !== 'success') return;

        const cvs = data.cvs || [];
        const current = select.value;
        select.innerHTML = '<option value="">[ No CV selected ]</option>';
        cvs.forEach(cv => {
            const opt = document.createElement('option');
            opt.value = cv.uuid;
            opt.textContent = (cv.active_flag == 1 ? '[ACTIVE] ' : '') + cv.designation;
            select.appendChild(opt);
        });

        const active = cvs.find(cv => cv.active_flag == 1);
        if (active) {
            select.value = active.uuid;
        } else if ([...select.options].some(o => o.value === current)) {
            select.value = current;
        }
    } catch (e) {}
}
