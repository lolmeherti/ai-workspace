/**
 * @file js/app.js
 * @description Main application bootstrap. Initializes modular components, binds functions to global window scope, and coordinates event listeners.
 */

import { state } from './state.js';
import { initTabs, switchSidebarTab } from './tabs.js';
import { initFilePaste, previewFile, removeFile } from './fileHandler.js';
import { parseMarkdownElements, copyToClipboard } from './markdown.js';
import { handleChatSubmit, toggleChatEditMode, handleChatSelection, submitMultiDelete } from './chatManager.js';
import { enableMemoryEdit, disableMemoryEdit, updateTokenCounter, bypassCondensation, confirmCondensation } from './ui.js';

window.switchSidebarTab = switchSidebarTab;
window.enableMemoryEdit = enableMemoryEdit;
window.disableMemoryEdit = disableMemoryEdit;
window.copyToClipboard = copyToClipboard;
window.previewFile = previewFile;
window.removeFile = removeFile;
window.removeImage = removeFile; // Alias
window.bypassCondensation = bypassCondensation;
window.confirmCondensation = confirmCondensation;
window.toggleChatEditMode = toggleChatEditMode;
window.submitMultiDelete = submitMultiDelete;

document.addEventListener('DOMContentLoaded', () => {
    if (typeof currentActiveTab !== 'undefined') {
        state.activeTab = currentActiveTab;
    }
    
    initTabs();
    initFilePaste();
    parseMarkdownElements();
    switchSidebarTab(state.activeTab);

    const chatWindow = document.getElementById('chatWindow');
    if (chatWindow) {
        chatWindow.scrollTop = chatWindow.scrollHeight;
    }

    if (typeof initialSessionTokens !== 'undefined' && typeof maxTokensLimit !== 'undefined') {
        updateTokenCounter(initialSessionTokens, maxTokensLimit);
    }

    const chatForm = document.getElementById('chatForm');
    if (chatForm) {
        chatForm.addEventListener('submit', handleChatSubmit);
    }

    const textareaInput = document.getElementById('q');
    if (textareaInput) {
        textareaInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
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
            if (chatForm) {
                chatForm.dispatchEvent(new Event('submit'));
            }
        }
    }
});