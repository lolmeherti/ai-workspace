import { initAvailability } from './workspace/availability.js';
import { initWorkspaceShell } from './workspace/shell.js';
import { initChatNavigation } from './chat/chatNavigation.js';
/**
 * @file js/app.js
 * @description Main application bootstrap. Initializes modular components, binds functions to global window scope, and coordinates event listeners.
 */

import { state } from './state.js';
import { initTabs, switchSidebarTab } from './tabs.js';
import { initFilePaste, previewFile, removeFile } from './fileHandler.js';
import { parseMarkdownElements, copyToClipboard } from './markdown.js';
import { handleChatSubmit, toggleChatEditMode, handleChatSelection, submitMultiDelete } from './chatManager.js';
import { initReplyRating } from './chat/replyRating.js';
import { initReasoningEffort } from './chat/reasoningEffort.js';
import { enableMemoryEdit, disableMemoryEdit, updateTokenCounter, bypassCondensation, confirmCondensation, applyCondensation, triggerManualCondensation, lockChatContext } from './ui.js';
import './gallery/galleryBootstrap.js';
import './tabs/tabsBootstrap.js';

window.switchSidebarTab = window.switchSidebarTab || switchSidebarTab;
window.enableMemoryEdit = enableMemoryEdit;
window.disableMemoryEdit = disableMemoryEdit;
window.copyToClipboard = copyToClipboard;
window.previewFile = previewFile;
window.removeFile = removeFile;
window.removeImage = removeFile;
window.bypassCondensation = bypassCondensation;
window.confirmCondensation = confirmCondensation;
window.applyCondensation = applyCondensation;
window.triggerManualCondensation = triggerManualCondensation;
window.toggleChatEditMode = toggleChatEditMode;
window.submitMultiDelete = submitMultiDelete;

document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const forcedUrlTab = urlParams.get('tab');
    const savedLocalTab = localStorage.getItem('activeTab');

    if (forcedUrlTab) {
        state.activeTab = forcedUrlTab;
        localStorage.setItem('activeTab', forcedUrlTab);
    } else if (savedLocalTab) {
        state.activeTab = savedLocalTab;
    } else if (typeof currentActiveTab !== 'undefined') {
        state.activeTab = currentActiveTab;
    } else {
        state.activeTab = 'chats';
    }
    
    initWorkspaceShell();
    initChatNavigation();
    initAvailability();
    initTabs();
    initFilePaste();
    parseMarkdownElements();
    initReplyRating();
    initReasoningEffort();
    
    switchSidebarTab(state.activeTab);

    const chatWindow = document.getElementById('chatWindow');
    if (chatWindow) {
        chatWindow.scrollTop = chatWindow.scrollHeight;
    }

    if (typeof initialSessionTokens !== 'undefined' && typeof maxTokensLimit !== 'undefined') {
        updateTokenCounter(initialSessionTokens, maxTokensLimit);
        if (initialSessionTokens >= (maxTokensLimit - 4096)) {
            lockChatContext();
        }
    }

    const chatForm = document.getElementById('chatForm');
    if (chatForm) {
        chatForm.addEventListener('submit', handleChatSubmit);
    }

    const textareaInput = document.getElementById('q');
    if (textareaInput) {
        textareaInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
                e.preventDefault();
                chatForm.dispatchEvent(new Event('submit'));
            }
        });
    }

    const chatsContainer = document.getElementById('chats-list-container');
    if (chatsContainer) {
        chatsContainer.addEventListener('click', handleChatSelection);
    }

    const pendingPrompt = sessionStorage.getItem('pending_chat_prompt');
    if (pendingPrompt) {
        sessionStorage.removeItem('pending_chat_prompt');
        const textarea = document.getElementById('q');
        if (textarea) {
            textarea.value = pendingPrompt;
            textarea.style.height = '';
            textarea.style.height = textarea.scrollHeight + 'px';
            // Restore the draft for review; sending remains an explicit action.
        }
    }

    // Auto-start the daily briefing after landing on a fresh conversation. The
    // briefing trigger navigates here via new_chat=1 and stashes this flag.
    const briefingAutoStart = sessionStorage.getItem('briefing_autostart');
    if (briefingAutoStart !== null) {
        sessionStorage.removeItem('briefing_autostart');
        window.startBriefing?.(briefingAutoStart === '1');
    }
});
