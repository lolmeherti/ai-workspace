/**
 * @file js/tabs/tabsMemoryEdit.js
 * @description Memory list bulk select and consolidate form UI.
 */

export function initMemoryTab() {
    document.addEventListener('DOMContentLoaded', function() {
        const selectAllCheckbox = document.getElementById('select-all-memories');
        const memoryCheckboxes = document.querySelectorAll('.memory-checkbox');
        const bulkDeleteForm = document.getElementById('bulk-delete-form');
        const selectedCountSpan = document.getElementById('selected-count');

        const consolidateForm = document.getElementById('consolidate-form');
        const consolidateBtn = document.getElementById('consolidate-btn');
        const consolidateIcon = document.getElementById('consolidate-icon');
        const consolidateText = document.getElementById('consolidate-text');

        if (consolidateForm) {
            consolidateForm.addEventListener('submit', function() {
                consolidateBtn.disabled = true;
                consolidateBtn.classList.add('opacity-70', 'cursor-not-allowed');
                consolidateBtn.classList.remove('hover:bg-cyan-500/20');
                consolidateText.textContent = 'Consolidating...';
                if (consolidateIcon) {
                    consolidateIcon.setAttribute('icon', 'spinner');
                    consolidateIcon.classList.add('animate-spin');
                }
            });
        }

        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                memoryCheckboxes.forEach(cb => {
                    cb.checked = selectAllCheckbox.checked;
                });
                updateBulkDeleteUI();
            });
        }

        memoryCheckboxes.forEach(cb => {
            cb.addEventListener('change', function() {
                updateBulkDeleteUI();
            });
        });

        function updateBulkDeleteUI() {
            const checkedCount = Array.from(memoryCheckboxes).filter(cb => cb.checked).length;

            if (checkedCount > 0) {
                bulkDeleteForm.classList.remove('hidden');
                bulkDeleteForm.classList.add('flex');
            } else {
                bulkDeleteForm.classList.add('hidden');
                bulkDeleteForm.classList.remove('flex');
            }

            if (selectedCountSpan) {
                selectedCountSpan.textContent = checkedCount;
            }

            if (selectAllCheckbox) {
                selectAllCheckbox.checked = checkedCount === memoryCheckboxes.length && memoryCheckboxes.length > 0;
            }
        }
    });
}

initMemoryTab();
