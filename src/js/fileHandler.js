/**
 * @file js/fileHandler.js
 * @description Attachment & Paste Handler. Monitors clipboard paste triggers, handles file input selections, and manages UI preview elements.
 */

import { state } from './state.js';

export function initFilePaste() {
    document.addEventListener("paste", function(e) {
        // Prevent clashing if looking at the gallery workspace
        const galleryWorkspace = document.getElementById("gallery-workspace");
        if (galleryWorkspace && !galleryWorkspace.classList.contains("hidden")) {
            return;
        }

        const items = (e.clipboardData || window.clipboardData).items;
        const filesToUpload = [];

        for (let item of items) {
            if (item.kind === "file") {
                const file = item.getAsFile();
                if (file) {
                    filesToUpload.push(file);
                }
            }
        }

        // If files are pasted, upload them in background and append as references
        if (filesToUpload.length > 0) {
            e.preventDefault(); // Stop standard text paste behavior

            const chatForm = document.getElementById("chatForm");
            const inputField = document.getElementById("q");
            const submitBtn = chatForm ? chatForm.querySelector("button[type='submit']") : null;

            // Visual loading states
            const originalPlaceholder = inputField ? inputField.placeholder : "Message AI Assistant...";
            if (inputField) {
                inputField.disabled = true;
                inputField.placeholder = `Uploading and AI indexing ${filesToUpload.length} file(s)...`;
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.classList.add("opacity-50", "cursor-not-allowed");
            }

            const uploadPromises = filesToUpload.map(file => {
                const formData = new FormData();
                formData.append('file', file);

                return fetch('index.php?api_action=upload_file', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success' && data.file) {
                        const formattedFile = {
                            physical_name: data.file.physical_name,
                            file_type: data.file.file_type,
                            generated_title: data.file.generated_title,
                            original_name: data.file.original_name,
                            preview: '' // Loaded on demand during preview drawer requests
                        };
                        
                        // Register file inside standard reference array
                        if (typeof window.addFileReference === 'function') {
                            window.addFileReference(formattedFile);
                        } else {
                            if (!window.selectedFileReferences) window.selectedFileReferences = [];
                            window.selectedFileReferences.push(formattedFile);
                            if (typeof window.updateFileReferencesUI === 'function') {
                                window.updateFileReferencesUI();
                            }
                        }
                    } else {
                        console.error(`Upload failed for ${file.name}: ${data.message}`);
                    }
                })
                .catch(err => {
                    console.error(`Network error uploading file ${file.name}:`, err);
                });
            });

            Promise.all(uploadPromises).finally(() => {
                // Restore input and button states
                if (inputField) {
                    inputField.disabled = false;
                    inputField.placeholder = originalPlaceholder;
                    inputField.focus();
                }
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.classList.remove("opacity-50", "cursor-not-allowed");
                }
            });
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