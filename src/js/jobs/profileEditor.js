/**
 * @file js/jobs/profileEditor.js
 * @description Global job profile load and save.
 */

import { flash, postJson, getJson } from './jobUtil.js';

export function initProfileEditor() {
    const form = document.getElementById('profile-form');
    if (form) form.addEventListener('submit', saveProfile);
    window.loadProfile = loadProfile;
}

export async function loadProfile() {
    const data = await getJson('get_profile');
    if (data.status !== 'success') return;

    const p = data.profile || {};
    document.getElementById('profile-locations').value = (p.locations || []).join(', ');
    setChecks('profile-work-mode', p.work_modes || []);
    setChecks('profile-employment', p.employment_types || []);
    document.getElementById('profile-salary-min').value = p.salary_min || '';
    document.getElementById('profile-salary-currency').value = p.salary_currency || '';
    document.getElementById('profile-free-text').value = p.free_text || '';
    updateCompleteBadge(data.complete);
}

function setChecks(containerId, values) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.querySelectorAll('input[type=checkbox]').forEach(cb => {
        cb.checked = values.includes(cb.value);
    });
}

function getChecks(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return [];
    return [...container.querySelectorAll('input[type=checkbox]:checked')].map(cb => cb.value);
}

function updateCompleteBadge(complete) {
    const badge = document.getElementById('profile-complete-badge');
    if (!badge) return;
    badge.textContent = complete ? 'Profile complete' : 'Incomplete — add 1+ location and 1+ work mode';
    badge.className = `text-[9px] font-bold uppercase tracking-widest ${complete ? 'text-emerald-400' : 'text-amber-400'}`;
}

async function saveProfile(e) {
    e.preventDefault();

    const locations = document.getElementById('profile-locations').value.split(',').map(s => s.trim()).filter(Boolean);

    const data = await postJson('save_profile', {
        locations: JSON.stringify(locations),
        work_modes: JSON.stringify(getChecks('profile-work-mode')),
        employment_types: JSON.stringify(getChecks('profile-employment')),
        salary_min: document.getElementById('profile-salary-min').value,
        salary_currency: document.getElementById('profile-salary-currency').value,
        free_text: document.getElementById('profile-free-text').value,
    });

    if (data.status === 'success') {
        flash('Profile saved.');
        updateCompleteBadge(data.complete);
    } else {
        flash(data.message || 'Save failed.', false);
    }
}
