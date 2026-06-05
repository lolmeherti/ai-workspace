import { state } from './state.js';
import { streamResponse } from './streamer.js';
import { removeFile } from './fileHandler.js';

export async function handleChatSubmit(e) {
    e.preventDefault();
    
    const form = document.getElementById("chatForm");
    const inputField = document.getElementById("q");
    const fileInput = document.getElementById("fileInput");
    const chatWindow = document.getElementById("chatWindow");
    
    const message = inputField.value.trim();
    const file = state.selectedFile || (fileInput ? fileInput.files[0] : null) || state.pastedImageFile;
    const hasReferences = window.selectedFileReferences && window.selectedFileReferences.length > 0;
    
    if (!message && !file && !state.pastedImageFile && !hasReferences) return;

    const emptyState = document.getElementById("empty-state");
    if (emptyState) emptyState.remove();

    const isImage = state.pastedImageFile || (file && file.type.startsWith("image/"));
    
    let fileDataUrl = null;
    if (isImage) {
        const previewEl = document.getElementById("image-preview");
        if (previewEl) fileDataUrl = previewEl.src;
    }

    const formData = new FormData(form);
    if (state.pastedImageFile) {
        formData.append("file", state.pastedImageFile, "pasted_image.png");
    }

    let finalQueryText = message;
    if (hasReferences) {
        window.selectedFileReferences.forEach(ref => {
            finalQueryText += ` [File: ${ref.physical_name}]`;
        });
    }
    formData.set("q", finalQueryText);

    inputField.value = "";
    inputField.style.height = "";
    removeFile();

    const tplUser = document.getElementById("tpl-user-message");
    const userNode = tplUser.content.cloneNode(true);
    
    const userBubble = userNode.querySelector(".bubble-content");
    const msgText = userNode.querySelector(".msg-text");
    const imgElement = userNode.querySelector(".upload-img");
    
    userBubble.setAttribute("data-raw", message);
    
    let renderedUserMsg = message
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/\n/g, "<br>");

    if (hasReferences) {
        window.selectedFileReferences.forEach(ref => {
            renderedUserMsg += ` [File: ${ref.physical_name}]`;
        });
    }

    if (window.parseInlineFiles) {
        renderedUserMsg = window.parseInlineFiles(renderedUserMsg);
    }
    
    msgText.innerHTML = renderedUserMsg;
    
    if (isImage && fileDataUrl) {
        imgElement.src = fileDataUrl;
        imgElement.classList.remove("hidden");
    } else if (file && !isImage) {
        imgElement.classList.add("hidden");
        const docWrapper = document.createElement("div");
        docWrapper.className = "flex items-center gap-2 bg-slate-900/60 border border-slate-800 p-3 rounded-lg max-w-xs mb-3";
        docWrapper.innerHTML = `
            <uk-icon icon="file-text" class="w-6 h-6 text-cyan-400"></uk-icon>
            <span class="text-xs text-slate-300 font-medium truncate">${file.name}</span>
        `;
        userBubble.prepend(docWrapper);
    }

    chatWindow.appendChild(userNode);
    chatWindow.scrollTop = chatWindow.scrollHeight;

    window.selectedFileReferences = [];
    window.updateFileReferencesUI();

    await streamResponse(formData, message);
}

export function toggleChatEditMode() {
    state.isChatEditMode = !state.isChatEditMode;
    const chatsList = document.getElementById('chats-list-container');
    const manageBtn = document.getElementById('btn-manage-chats');
    const deleteBar = document.getElementById('multi-delete-bar');
    
    if (!chatsList || !manageBtn || !deleteBar) return;

    if (state.isChatEditMode) {
        chatsList.classList.add('in-edit-mode');
        manageBtn.innerHTML = '<uk-icon icon="close" class="w-3.5 h-3.5"></uk-icon> Cancel';
        manageBtn.className = "text-xs text-rose-400 hover:text-rose-300 font-medium transition-colors cursor-pointer flex items-center gap-1";
        deleteBar.classList.remove('translate-y-full');
        state.selectedChatIds = [];
        updateSelectedChatsUI();
    } else {
        chatsList.classList.remove('in-edit-mode');
        manageBtn.innerHTML = '<uk-icon icon="file-edit" class="w-3.5 h-3.5"></uk-icon> Manage';
        manageBtn.className = "text-xs text-slate-400 hover:text-cyan-400 font-medium transition-colors cursor-pointer flex items-center gap-1";
        deleteBar.classList.add('translate-y-full');
        
        document.querySelectorAll('.chat-session-item').forEach(item => {
            item.classList.remove('border-rose-500/40', 'bg-rose-950/20', 'shadow-[0_0_10px_rgba(244,63,94,0.1)]');
            const checkIcon = item.querySelector('.select-check-icon');
            if (checkIcon) checkIcon.classList.add('hidden');
        });
        state.selectedChatIds = [];
    }
}

export function updateSelectedChatsUI() {
    const counter = document.getElementById('selected-chats-count');
    if (counter) {
        counter.textContent = state.selectedChatIds.length;
    }
    
    const deleteBtn = document.getElementById('btn-submit-multi-delete');
    if (deleteBtn) {
        if (state.selectedChatIds.length === 0) {
            deleteBtn.disabled = true;
            deleteBtn.className = "px-3 py-1 text-xs font-semibold bg-rose-500/10 text-rose-400/50 border border-rose-500/10 rounded transition-colors flex items-center gap-1 opacity-50 cursor-not-allowed";
        } else {
            deleteBtn.disabled = false;
            deleteBtn.className = "px-3 py-1 text-xs font-semibold bg-rose-500/20 hover:bg-rose-500/30 text-rose-400 border border-rose-500/40 rounded transition-colors flex items-center gap-1 cursor-pointer";
        }
    }
}

export function handleChatSelection(e) {
    if (!state.isChatEditMode) return;
    
    const item = e.target.closest('.chat-session-item');
    if (!item) return;

    e.preventDefault();
    e.stopPropagation();

    const id = parseInt(item.getAttribute('data-session-id'), 10);
    if (isNaN(id)) return;

    const index = state.selectedChatIds.indexOf(id);
    const checkIcon = item.querySelector('.select-check-icon');
    
    if (index > -1) {
        state.selectedChatIds.splice(index, 1);
        item.classList.remove('border-rose-500/40', 'bg-rose-950/20', 'shadow-[0_0_10px_rgba(244,63,94,0.1)]');
        if (checkIcon) checkIcon.classList.add('hidden');
    } else {
        state.selectedChatIds.push(id);
        item.classList.add('border-rose-500/40', 'bg-rose-950/20', 'shadow-[0_0_10px_rgba(244,63,94,0.1)]');
        if (checkIcon) checkIcon.classList.remove('hidden');
    }

    updateSelectedChatsUI();
}

export function submitMultiDelete() {
    if (state.selectedChatIds.length === 0) return;
    
    const confirmMsg = `Are you sure you want to permanently delete these ${state.selectedChatIds.length} conversations?`;
    if (!confirm(confirmMsg)) return;

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'index.php';

    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'delete_multiple_sessions';
    actionInput.value = '1';
    form.appendChild(actionInput);

    state.selectedChatIds.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_sessions[]';
        input.value = id;
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
}

window.handleChatSubmit = handleChatSubmit;
window.toggleChatEditMode = toggleChatEditMode;
window.submitMultiDelete = submitMultiDelete;
window.handleChatSelection = handleChatSelection;