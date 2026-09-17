import { state } from './state.js';
import { appendDraftReference } from './chat/chatNavigation.js';
import { requestJson, notify, clearNotice } from './workspace/feedback.js';
import { paintAvailability } from './workspace/availability.js';
export function initFilePaste() {
    document.addEventListener('paste', async e => {
        if (state.activeTab !== 'chats' || !e.target.closest('#chatForm')) return;
        const files = [...(e.clipboardData?.items || [])].filter(item => item.kind === 'file').map(item => item.getAsFile()).filter(Boolean);
        if (!files.length) return;
        e.preventDefault();
        const sessionId = state.sessionId;
        const image = files.find(file => file.type.startsWith('image/'));
        if (image) previewFile({ files: [image] });
        const documents = files.filter(file => !file.type.startsWith('image/'));
        if (!documents.length) return;
        state.pendingUploads = (state.pendingUploads || 0) + documents.length; paintAvailability();
        notify(`Uploading ${documents.length} attachment(s)…`, { id: 'attachment-upload', target: document.getElementById('composer-notices'), kind: 'info' });
        await Promise.allSettled(documents.map(async file => {
            try {
                const body = new FormData(); body.append('file', file);
                const data = await requestJson('index.php?api_action=upload_file', { method: 'POST', body });
                if (!data.file) throw new Error('No file was returned.');
                appendDraftReference(sessionId, { ...data.file, preview: '' });
            } catch (error) { notify(`${file.name}: ${error.message} Paste this file again to retry.`, { id: 'attachment-error-' + file.name.replace(/[^a-z0-9]/gi, ''), target: state.sessionId === sessionId ? document.getElementById('composer-notices') : undefined }); }
            finally { state.pendingUploads--; paintAvailability(); }
        }));
        clearNotice('attachment-upload');
    });
}

export function previewFile(input) {
    const file = input.files[0];
    if (!file) return;

    state.selectedFile = file;
    state.pastedImageFile = null;

    const previewContainer = document.getElementById("image-preview-container");
    const imgPreview = document.getElementById("image-preview");
    const iconPreview = document.getElementById("file-icon-preview");
    const previewName = document.getElementById("file-preview-name");
    const previewType = document.getElementById("file-preview-type");

    if (previewName) previewName.textContent = file.name;
    if (previewType) previewType.textContent = file.type || "Document";

    if (previewContainer) {
        previewContainer.style.setProperty("display", "flex", "important");
        previewContainer.classList.remove("hidden");
    }

    if (file.type.startsWith("image/")) {
        const reader = new FileReader();
        reader.onload = function(e) {
            if (state.selectedFile !== file) return;
            if (imgPreview) {
                imgPreview.src = e.target.result;
                imgPreview.classList.remove("hidden");
            }
            if (iconPreview) iconPreview.classList.add("hidden");
        };
        reader.readAsDataURL(file);
    } else {
        if (imgPreview) imgPreview.classList.add("hidden");
        if (iconPreview) iconPreview.classList.remove("hidden");
    }
}

export function removeFile() {
    state.selectedFile = null;
    state.pastedImageFile = null;

    const fileInput = document.getElementById("fileInput");
    if (fileInput) {
        fileInput.value = "";
    }

    const previewContainer = document.getElementById("image-preview-container");
    if (previewContainer) {
        previewContainer.style.setProperty("display", "none", "important");
        previewContainer.classList.add("hidden");
    }

    const imgPreview = document.getElementById("image-preview");
    if (imgPreview) {
        imgPreview.src = "";
        imgPreview.classList.add("hidden");
    }

    const iconPreview = document.getElementById("file-icon-preview");
    if (iconPreview) {
        iconPreview.classList.add("hidden");
    }
}
