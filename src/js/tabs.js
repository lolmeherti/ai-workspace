/**
 * @file js/tabs.js
 * @description Tab Manager. Handles panel navigation, tracks the active tab state in local storage, and safeguards active runs.
 */

import { state } from './state.js';

export function initTabs() {
    window.addEventListener('beforeunload', function (e) {
        if (state.isGenerating) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
}

export function switchSidebarTab(tabId) {
    localStorage.setItem('activeTab', tabId);

    try {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tabId);
        window.history.replaceState(null, '', url);
    } catch (e) {
    }

    const panels = ['panel-chats', 'panel-memories', 'panel-queries', 'panel-uploads', 'panel-emails', 'panel-jobs'];
    const buttons = ['tab-btn-chats', 'tab-btn-memories', 'tab-btn-queries', 'tab-btn-uploads', 'tab-btn-emails', 'tab-btn-jobs'];

    panels.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            if (id === 'panel-' + tabId) {
                el.classList.remove('hidden');
            } else {
                el.classList.add('hidden');
            }
        }
    });

    buttons.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            if (id === 'tab-btn-' + tabId) {
                el.classList.add('bg-slate-900', 'text-cyan-400');
            } else {
                el.classList.remove('bg-slate-900', 'text-cyan-400');
            }
        }
    });

    const chatWorkspace = document.getElementById('chat-workspace');
    const galleryWorkspace = document.getElementById('gallery-workspace');
    const emailWorkspace = document.getElementById('email-workspace');
    const jobWorkspace = document.getElementById('job-workspace');

    if (tabId === 'uploads') {
        if (chatWorkspace) chatWorkspace.classList.add('hidden');
        if (emailWorkspace) emailWorkspace.classList.add('hidden');
        if (jobWorkspace) jobWorkspace.classList.add('hidden');
        if (galleryWorkspace) galleryWorkspace.classList.remove('hidden');
        document.dispatchEvent(new CustomEvent('gallery-opened'));
    } else if (tabId === 'emails') {
        if (chatWorkspace) chatWorkspace.classList.add('hidden');
        if (galleryWorkspace) galleryWorkspace.classList.add('hidden');
        if (jobWorkspace) jobWorkspace.classList.add('hidden');
        if (emailWorkspace) emailWorkspace.classList.remove('hidden');
    } else if (tabId === 'jobs') {
        if (chatWorkspace) chatWorkspace.classList.add('hidden');
        if (galleryWorkspace) galleryWorkspace.classList.add('hidden');
        if (emailWorkspace) emailWorkspace.classList.add('hidden');
        if (jobWorkspace) jobWorkspace.classList.remove('hidden');
        document.dispatchEvent(new CustomEvent('jobs-opened'));
    } else {
        if (galleryWorkspace) galleryWorkspace.classList.add('hidden');
        if (emailWorkspace) emailWorkspace.classList.add('hidden');
        if (jobWorkspace) jobWorkspace.classList.add('hidden');
        if (chatWorkspace) chatWorkspace.classList.remove('hidden');
    }
}