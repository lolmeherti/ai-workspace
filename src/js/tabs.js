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

export function switchSidebarTab(tabId) {
    const panels = ['panel-chats', 'panel-memories', 'panel-queries', 'panel-uploads'];
    const buttons = ['tab-btn-chats', 'tab-btn-memories', 'tab-btn-queries', 'tab-btn-uploads'];
    
    panels.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.toggle('hidden', id !== 'panel-' + tabId);
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
    
    if (tabId === 'uploads') {
        if (chatWorkspace) chatWorkspace.classList.add('hidden');
        if (galleryWorkspace) galleryWorkspace.classList.remove('hidden');
        document.dispatchEvent(new CustomEvent('gallery-opened'));
    } else {
        if (chatWorkspace) chatWorkspace.classList.remove('hidden');
        if (galleryWorkspace) galleryWorkspace.classList.add('hidden');
    }
}