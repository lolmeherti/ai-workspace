/**
 * @file js/fileHandler.js
 * @description Attachment & Paste Handler. Monitors clipboard paste triggers, handles file input selections, and manages UI preview elements.
 */

import { state } from './state.js';

export function initFilePaste() {
    document.addEventListener("paste", function(e) {
        const items = (e.clipboardData || window.clipboardData).items;
        for (let item of items) {
            if (item.kind === "file" && item.type.startsWith("image/")) {
                const file = item.getAsFile();
                
                state.selectedFile = null;
                state.pastedImageFile = file;

                const previewContainer = document.getElementById("image-preview-container");
                const imgPreview = document.getElementById("image-preview");
                const iconPreview = document.getElementById("file-icon-preview");
                const previewName = document.getElementById("file-preview-name");
                const previewType = document.getElementById("file-preview-type");

                if (previewName) previewName.textContent = "Pasted Image";
                if (previewType) previewType.textContent = "IMAGE";

                if (previewContainer) {
                    previewContainer.style.setProperty("display", "flex", "important");
                    previewContainer.classList.remove("hidden");
                }
                
                if (iconPreview) iconPreview.classList.add("hidden");
                if (imgPreview) {
                    imgPreview.classList.remove("hidden");
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        imgPreview.src = event.target.result;
                    };
                    reader.readAsDataURL(file);
                }
            }
        }
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