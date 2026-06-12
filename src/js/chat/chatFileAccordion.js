/**
 * @file js/chat/chatFileAccordion.js
 * @description File accordion expand/collapse, explorer open, and append-to-chat actions.
 */

export function toggleFileAccordion(headerElement) {
    const container = headerElement.closest('.file-accordion-item');
    if (!container) return;

    const contentPanel = container.querySelector('.file-accordion-content');
    const arrowIcon = container.querySelector('.accordion-arrow');

    if (!contentPanel) return;

    const isExpanded = contentPanel.classList.contains('expanded');

    const list = container.closest('.file-accordion-list');
    if (list) {
        list.querySelectorAll('.file-accordion-item').forEach(item => {
            if (item !== container) {
                const siblingContent = item.querySelector('.file-accordion-content');
                const siblingArrow = item.querySelector('.accordion-arrow');
                if (siblingContent) siblingContent.classList.remove('expanded');
                if (siblingArrow) siblingArrow.classList.remove('rotated');
            }
        });
    }

    if (isExpanded) {
        contentPanel.classList.remove('expanded');
        if (arrowIcon) arrowIcon.classList.remove('rotated');
    } else {
        contentPanel.classList.add('expanded');
        if (arrowIcon) arrowIcon.classList.add('rotated');

        const docPre = contentPanel.querySelector('.document-lazy-load');
        if (docPre && docPre.getAttribute('data-loaded') === 'false') {
            const filename = docPre.getAttribute('data-file');
            const loadingText = contentPanel.querySelector('.lazy-loading-indicator');

            fetch(`index.php?api_action=get_file_content&file=${encodeURIComponent(filename)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        docPre.textContent = data.content;
                        docPre.setAttribute('data-loaded', 'true');
                        if (loadingText) loadingText.classList.add('hidden');
                        docPre.classList.remove('hidden');
                    } else {
                        if (loadingText) loadingText.textContent = `Error: ${data.message}`;
                    }
                })
                .catch(err => {
                    if (loadingText) loadingText.textContent = `Error connecting to API: ${err.message}`;
                });
        }
    }
}

export function showFileInExplorer(filename, button) {
    if (!filename) return;
    const originalHTML = button.innerHTML;
    button.disabled = true;
    button.innerHTML = `
        <svg class="animate-spin h-3.5 w-3.5 text-cyan-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        Locating...
    `;

    fetch(`index.php?api_action=show_in_explorer&file=${encodeURIComponent(filename)}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                button.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-emerald-400"><polyline points="20 6 9 17 4 12"/></svg>
                    Opened!
                `;
            } else if (data.status === 'fallback') {
                window.open(data.url, '_blank');
                navigator.clipboard.writeText(data.physical_path).catch(() => {});
                button.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-amber-400"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    Opened (Tab)!
                `;
            } else {
                alert(`Could not open path: ${data.message}`);
                button.innerHTML = originalHTML;
            }
        })
        .catch(err => {
            alert(`Error communicating with system: ${err.message}`);
            button.innerHTML = originalHTML;
        })
        .finally(() => {
            setTimeout(() => {
                button.innerHTML = originalHTML;
                button.disabled = false;
            }, 2000);
        });
}

export function appendFileFromAccordion(button, file) {
    if (typeof window.addFileReference === 'function') {
        window.addFileReference(file);
        const originalHTML = button.innerHTML;
        button.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-emerald-400"><polyline points="20 6 9 17 4 12"/></svg>
            Appended!
        `;
        button.classList.add('border-emerald-500/40', 'text-emerald-400');
        setTimeout(() => {
            button.innerHTML = originalHTML;
            button.classList.remove('border-emerald-500/40', 'text-emerald-400');
        }, 1500);
    }
}
