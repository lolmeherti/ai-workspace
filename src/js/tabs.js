/**
 * @file js/tabs.js
 * @description Tab Manager. Handles panel navigation, tracks the active tab state in URL search queries, and safeguards active runs.
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

export function switchSidebarTab(tabName) {
    if (state.isGenerating) {
        const proceed = confirm("A prompt is currently in progress. Switching tabs now will void the computation. Do you want to proceed?");
        if (!proceed) {
            return;
        }
    }

    state.activeTab = tabName;
    
    ['chats', 'memories', 'queries'].forEach(t => {
        const panel = document.getElementById(`panel-${t}`);
        if (panel) {
            panel.classList.toggle('hidden', t !== tabName);
        }
    });

    ['chats', 'memories', 'queries'].forEach(t => {
        const btn = document.getElementById(`tab-btn-${t}`);
        if (btn) {
            if (t === tabName) {
                btn.className = "flex-1 py-2 rounded-md transition-all text-center flex items-center justify-center gap-1 bg-slate-800 text-white shadow-md font-semibold border border-slate-700/50 cursor-pointer";
            } else {
                btn.className = "flex-1 py-2 rounded-md transition-all text-center flex items-center justify-center gap-1 text-slate-400 hover:text-slate-200 cursor-pointer";
            }
        }
    });

    const url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    window.history.replaceState({}, '', url);

    const settingsForm = document.querySelector('#settings-modal form');
    if (settingsForm) {
        const actionUrl = new URL(settingsForm.action, window.location.origin);
        actionUrl.searchParams.set('tab', tabName);
        settingsForm.action = actionUrl.pathname + actionUrl.search;
    }
}