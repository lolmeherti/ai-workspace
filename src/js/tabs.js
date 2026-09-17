/**
 * @file js/tabs.js
 * @description Tab Manager. Handles panel navigation, tracks the active tab state in local storage, and safeguards active runs.
 */

import { state } from './state.js';

export function initTabs() {
    window.addEventListener('beforeunload', function (e) {
        if (state.isGenerating || state.jobRun || state.memoryConsolidating || window.hasUnsavedEditor?.() || document.getElementById('q')?.value || document.querySelector('form[data-dirty="true"]')) {
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

    const workspaceForTab = { chats: 'chat-workspace', uploads: 'gallery-workspace', emails: 'email-workspace', jobs: 'job-workspace', memories: 'chat-workspace' };
    state.activeTab = workspaceForTab[tabId] ? tabId : 'chats';
    const activeWorkspace = workspaceForTab[state.activeTab];
    Object.values(workspaceForTab).forEach(id => document.getElementById(id)?.classList.toggle('hidden', id !== activeWorkspace));
    document.querySelectorAll('.workspace-nav-button').forEach(button => {
        if (button.id === 'tab-btn-' + state.activeTab) button.setAttribute('aria-current', 'page');
        else button.removeAttribute('aria-current');
    });
    if (state.activeTab === 'uploads') document.dispatchEvent(new CustomEvent('gallery-opened'));
    if (state.activeTab === 'jobs') document.dispatchEvent(new CustomEvent('jobs-opened'));
    if (state.activeTab === 'memories') {
        document.body.classList.remove('sidebar-collapsed');
        document.getElementById('sidebar-toggle')?.setAttribute('aria-expanded', 'true');
        document.getElementById('sidebar-toggle')?.setAttribute('aria-label', 'Collapse sidebar');
    } else if (matchMedia('(max-width: 760px)').matches) {
        document.body.classList.add('sidebar-collapsed');
        document.getElementById('sidebar-toggle')?.setAttribute('aria-expanded', 'false');
        document.getElementById('sidebar-toggle')?.setAttribute('aria-label', 'Expand sidebar');
    }
}
