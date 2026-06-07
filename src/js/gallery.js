/**
 * Uploads Gallery & File Management Controller
 */
document.addEventListener('DOMContentLoaded', () => {
    let allFiles = []; 
    let selectedFileIds = new Set(); 
    let activePreviewFile = null;
    
    let currentQuery = '';
    let currentFilter = 'all'; // 'all', 'images', 'docs'
    let currentPage = 1;
    let totalPages = 1;
    let limitPerPage = 12;

    let searchTimeout = null;
    let idToDelete = []; 

    const isImageFile = (file) => {
        const type = (file.file_type || '').toLowerCase();
        const ext = (file.original_name || '').split('.').pop().toLowerCase();
        return type.startsWith('image/') || type === 'image' || ['png', 'jpg', 'jpeg', 'gif', 'webp'].includes(ext);
    };

    // DOM Selectors
    const searchInput = document.getElementById('gallery-search-input');
    const galleryGrid = document.getElementById('gallery-grid');
    const galleryLoader = document.getElementById('gallery-loader');
    const countLabel = document.getElementById('gallery-count-label');
    const clearFiltersBtn = document.getElementById('gallery-clear-filters');
    
    // Category Tabs
    const filterBtnAll = document.getElementById('filter-btn-all');
    const filterBtnImages = document.getElementById('filter-btn-images');
    const filterBtnDocs = document.getElementById('filter-btn-docs');

    // Pagination
    const paginationContainer = document.getElementById('gallery-pagination');
    const pagerPrev = document.getElementById('pager-prev');
    const pagerNext = document.getElementById('pager-next');
    const pagerInfo = document.getElementById('pager-info');

    // Floating Batch Bar
    const batchBar = document.getElementById('gallery-batch-bar');
    const batchSelectionCount = document.getElementById('batch-selection-count');
    const batchActionAppend = document.getElementById('batch-action-append');
    const batchActionDelete = document.getElementById('batch-action-delete');

    // Preview Drawer
    const previewDrawer = document.getElementById('gallery-preview-drawer');
    const drawerBody = document.getElementById('drawer-body');
    const closePreviewBtn = document.getElementById('close-preview-btn');
    const drawerActionExplorer = document.getElementById('drawer-action-explorer');
    const drawerActionAppend = document.getElementById('drawer-action-append');
    const drawerActionDelete = document.getElementById('drawer-action-delete');

    // Deletion Modal
    const deleteModal = document.getElementById('gallery-delete-modal');
    const deleteModalText = document.getElementById('delete-modal-text');
    const deleteModalCancel = document.getElementById('delete-modal-cancel');
    const deleteModalConfirm = document.getElementById('delete-modal-confirm');

    /**
     * Fetch paginated files matching query from database
     */
    function fetchGalleryFiles() {
        if (!galleryLoader || !galleryGrid) return;
        
        galleryLoader.classList.remove('opacity-0', 'pointer-events-none');
        galleryLoader.style.display = 'flex';

        const url = `index.php?api_action=search_files&source=gallery&query=${encodeURIComponent(currentQuery)}&page=${currentPage}&limit=${limitPerPage}`;

        fetch(url)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    allFiles = data.files || [];
                    totalPages = data.pagination?.pages || 1;
                    currentPage = data.pagination?.page || 1;
                    
                    const totalFiles = data.pagination?.total || 0;
                    
                    // Update stats indicator labels
                    countLabel.textContent = currentQuery 
                        ? `Found ${totalFiles} matching files for "${currentQuery}"` 
                        : `Displaying ${allFiles.length} of ${totalFiles} uploaded files`;

                    if (currentQuery) {
                        clearFiltersBtn.classList.remove('hidden');
                    } else {
                        clearFiltersBtn.classList.add('hidden');
                    }

                    // Update sidebar statistics globally
                    const sidebarCount = document.getElementById('sidebar-total-files');
                    if (sidebarCount) sidebarCount.textContent = totalFiles;

                    renderGrid();
                    updatePaginationUI();
                } else {
                    galleryGrid.innerHTML = `
                        <div class="col-span-full py-12 text-center text-rose-400 font-semibold text-xs">
                            Failed to index files: ${data.message}
                        </div>
                    `;
                }
            })
            .catch(err => {
                console.error(err);
                if (galleryGrid) {
                    galleryGrid.innerHTML = `
                        <div class="col-span-full py-12 text-center text-rose-400 font-semibold text-xs">
                            Error communicating with files system: ${err.message}
                        </div>
                    `;
                }
            })
            .finally(() => {
                // Fade loader out
                galleryLoader.classList.add('opacity-0', 'pointer-events-none');
                setTimeout(() => { galleryLoader.style.display = 'none'; }, 300);
            });
    }

    /**
     * Filter current result array and draw HTML cards
     */
    function renderGrid() {
        galleryGrid.innerHTML = '';
        
        // Robust helper to check if a file is an image by type or extension
        const isImageFile = (file) => {
            const type = (file.file_type || '').toLowerCase();
            const ext = (file.original_name || '').split('.').pop().toLowerCase();
            return type.startsWith('image/') || type === 'image' || ['png', 'jpg', 'jpeg', 'gif', 'webp'].includes(ext);
        };
        
        // Filter elements locally based on selected tab button
        const filtered = allFiles.filter(file => {
            const isImg = isImageFile(file);
            if (currentFilter === 'images') return isImg;
            if (currentFilter === 'docs') return !isImg;
            return true;
        });

        if (filtered.length === 0) {
            galleryGrid.innerHTML = `
                <div class="col-span-full py-16 flex flex-col items-center justify-center gap-2 select-none">
                    <uk-icon icon="info" class="w-8 h-8 text-slate-600"></uk-icon>
                    <span class="text-xs font-semibold text-slate-500">No matching files found on disk.</span>
                </div>
            `;
            return;
        }

        filtered.forEach(file => {
            const isImage = isImageFile(file);
            const isSelected = selectedFileIds.has(file.id);
            const cardId = `gallery-card-${file.id}`;

            const card = document.createElement('div');
            card.id = cardId;
            card.className = `group bg-[#091124]/90 border ${isSelected ? 'border-cyan-500 shadow-[0_0_12px_rgba(6,182,212,0.15)] ring-1 ring-cyan-500' : 'border-slate-800/80'} rounded-xl overflow-hidden shadow-md cursor-pointer transition-all duration-200 hover:border-cyan-500/40 relative flex flex-col h-[280px] select-none`;
            
            // Build card inner HTML (Hover footers removed entirely!)
            card.innerHTML = `
                <!-- Checkbox Button overlay -->
                <div class="gallery-checkbox-btn absolute top-3 left-3 z-10 w-5 h-5 rounded-full border border-cyan-500/40 bg-slate-950/80 flex items-center justify-center shadow-md hover:border-cyan-500/70 transition-all cursor-pointer">
                    <span class="gallery-checkbox-dot w-2.5 h-2.5 rounded-full bg-cyan-400 transition-transform duration-150 ${isSelected ? 'scale-100' : 'scale-0'}"></span>
                </div>

                <!-- Main Card Body (triggers preview drawer on click) -->
                <div class="flex-1 flex flex-col overflow-hidden card-click-target">
                    ${isImage 
                        ? `<div class="h-44 w-full overflow-hidden border-b border-slate-900 bg-slate-950/20 flex items-center justify-center">
                             <img src="uploads/${file.physical_name}" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105" loading="lazy" />
                           </div>`
                        : `<div class="h-44 w-full p-4 border-b border-slate-900 bg-slate-950/40 overflow-hidden font-mono text-[9px] leading-relaxed text-slate-400/90 italic tracking-wide select-none">
                             <div class="flex items-center gap-1.5 mb-2 text-[8px] font-bold text-cyan-400 tracking-wider uppercase select-none shrink-0">
                                 <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>
                                 ${file.file_type.split('/').pop().toUpperCase()} EXTRACT
                             </div>
                             <div class="line-clamp-6 select-none">${file.snippet ? file.snippet : 'No text extraction preview available.'}</div>
                           </div>`
                    }
                    
                    <!-- Meta Info Area -->
                    <div class="p-4 flex flex-col justify-between flex-1 min-w-0">
                        <div class="truncate text-xs font-bold text-slate-100 truncate tracking-wide" title="${file.generated_title}">${file.generated_title}</div>
                        <div class="text-[10px] text-slate-400/70 truncate font-mono mt-1">${file.original_name}</div>
                    </div>
                </div>
            `;

            // Bind checkbox button target to toggle selections only
            card.querySelector('.gallery-checkbox-btn').addEventListener('click', (e) => {
                e.stopPropagation(); // Stop click from propagating to the card body preview trigger!
                toggleFileSelection(file.id);
            });

            // Bind main body clicks to open the preview drawer directly [3]
            card.querySelector('.card-click-target').addEventListener('click', (e) => {
                e.stopPropagation();
                openPreviewDrawer(file);
            });

            galleryGrid.appendChild(card);
        });
    }

    /**
     * Toggles a single file inside the selection state
     */
    function toggleFileSelection(id) {
        if (selectedFileIds.has(id)) {
            selectedFileIds.delete(id);
        } else {
            selectedFileIds.add(id);
        }
        
        // Re-render only selection styling on target card
        const card = document.getElementById(`gallery-card-${id}`);
        if (card) {
            const isSelected = selectedFileIds.has(id);
            const overlayDot = card.querySelector('.gallery-checkbox-dot');
            
            if (isSelected) {
                card.classList.add('border-cyan-500', 'shadow-[0_0_12px_rgba(6,182,212,0.15)]', 'ring-1', 'ring-cyan-500');
                card.classList.remove('border-slate-800/80');
                if (overlayDot) overlayDot.classList.replace('scale-0', 'scale-100');
            } else {
                card.classList.remove('border-cyan-500', 'shadow-[0_0_12px_rgba(6,182,212,0.15)]', 'ring-1', 'ring-cyan-500');
                card.classList.add('border-slate-800/80');
                if (overlayDot) overlayDot.classList.replace('scale-100', 'scale-0');
            }
        }

        updateBatchBar();
    }

    /**
     * Handle sliding Bottom bar positioning and count display
     */
    function updateBatchBar() {
        const selectedCount = selectedFileIds.size;
        if (selectedCount > 0) {
            batchSelectionCount.textContent = selectedCount;
            batchBar.classList.remove('translate-y-24', 'opacity-0', 'invisible');
        } else {
            batchBar.classList.add('translate-y-24', 'opacity-0', 'invisible');
        }
    }

    /**
     * Updates previous/next button disabled state and textual info
     */
    function updatePaginationUI() {
        if (totalPages <= 1) {
            paginationContainer.classList.add('hidden');
            return;
        }

        paginationContainer.classList.remove('hidden');
        pagerInfo.textContent = `Page ${currentPage} of ${totalPages}`;
        
        pagerPrev.disabled = (currentPage === 1);
        pagerNext.disabled = (currentPage === totalPages);
    }

    /**
     * Slide open detailed info drawer on the right
     */
    function openPreviewDrawer(file) {
        activePreviewFile = file;
        const isImage = isImageFile(file);

        // Set up active drawer content template
        drawerBody.innerHTML = `
            <div>
                <h4 class="text-[10px] font-bold text-cyan-500 uppercase tracking-wider mb-1">File Title</h4>
                <p class="text-sm font-semibold text-slate-100 break-words leading-relaxed">${file.generated_title}</p>
            </div>
            <div class="h-[1px] bg-slate-800/80"></div>
            <div>
                <h4 class="text-[10px] font-bold text-cyan-500 uppercase tracking-wider mb-1">Physical Details</h4>
                <div class="text-[11px] font-mono text-slate-400 space-y-1.5 leading-normal bg-slate-950/30 p-3 rounded-lg border border-slate-900">
                    <div class="flex"><span class="w-16 text-slate-500 font-sans">Name:</span> <span class="break-all text-slate-300">${file.original_name}</span></div>
                    <div class="flex"><span class="w-16 text-slate-500 font-sans">Type:</span> <span class="text-slate-300">${file.file_type}</span></div>
                    <div class="flex"><span class="w-16 text-slate-500 font-sans">Disk:</span> <span class="break-all text-slate-300">${file.physical_name}</span></div>
                    <div class="flex"><span class="w-16 text-slate-500 font-sans">Date:</span> <span class="text-slate-300">${new Date(file.uploaded_at).toLocaleString()}</span></div>
                </div>
            </div>
            <div class="h-[1px] bg-slate-800/80"></div>
            <div>
                <h4 class="text-[10px] font-bold text-cyan-500 uppercase tracking-wider mb-2">Content Preview</h4>
                ${isImage 
                    ? `<div class="bg-slate-950/50 p-1.5 border border-slate-900 rounded-xl overflow-hidden shadow-inner">
                         <img src="uploads/${file.physical_name}" class="w-full h-auto max-h-[300px] object-contain rounded-lg block" alt="Preview"/>
                       </div>`
                    : `<div class="bg-slate-950/90 border border-slate-800/80 rounded-xl p-4 text-xs font-mono text-slate-300 max-h-[320px] overflow-y-auto whitespace-pre-wrap text-left relative min-h-[100px] leading-relaxed shadow-inner">
                         <span class="drawer-loading-indicator text-cyan-400 flex items-center gap-2 font-sans font-medium">
                             <svg class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                             Streaming file from local disk...
                         </span>
                         <pre class="drawer-lazy-load hidden text-[11px]" data-loaded="false"></pre>
                       </div>`
                }
            </div>
        `;

        previewDrawer.classList.remove('translate-x-full');

        // Lazy load extracted text for documents
        if (!isImage) {
            const preEl = drawerBody.querySelector('.drawer-lazy-load');
            const loadingIndicator = drawerBody.querySelector('.drawer-loading-indicator');
            
            fetch(`index.php?api_action=get_file_content&file=${encodeURIComponent(file.physical_name)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        preEl.textContent = data.content;
                        preEl.classList.remove('hidden');
                        if (loadingIndicator) loadingIndicator.classList.add('hidden');
                    } else {
                        if (loadingIndicator) loadingIndicator.textContent = `Error: ${data.message}`;
                    }
                })
                .catch(err => {
                    if (loadingIndicator) loadingIndicator.textContent = `Network Error: ${err.message}`;
                });
        }
    }

    function closePreviewDrawer() {
        previewDrawer.classList.add('translate-x-full');
        activePreviewFile = null;
    }

    /**
     * Setup Single item Delete flows
     */
    function triggerSingleDelete(id) {
        idToDelete = [id];
        deleteModalText.textContent = "Are you sure you want to permanently delete this file from disk? This will delete both the primary file and any raw text extractions. This action cannot be undone.";
        deleteModal.classList.remove('hidden');
    }

    /**
     * Setup multi-selection Delete flows
     */
    function triggerBatchDelete() {
        if (selectedFileIds.size === 0) return;
        idToDelete = Array.from(selectedFileIds);
        deleteModalText.textContent = `Are you sure you want to permanently delete these ${selectedFileIds.size} files from disk? This will delete both the primary files and any associated raw text extractions. This action cannot be undone.`;
        deleteModal.classList.remove('hidden');
    }

    /**
     * Execute secure deletion payload to backend
     */
    function confirmAndExecuteDeletion() {
        if (idToDelete.length === 0) return;

        const originalConfirmHTML = deleteModalConfirm.innerHTML;
        deleteModalConfirm.disabled = true;
        deleteModalConfirm.textContent = "Deleting...";

        const formData = new FormData();
        formData.append('action', 'delete_files');
        idToDelete.forEach(id => formData.append('ids[]', id));

        fetch('index.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success' || data.status === 'partial_success') {
                // Remove deleted items from our active selection set
                idToDelete.forEach(id => selectedFileIds.delete(id));
                updateBatchBar();
                closePreviewDrawer();
                deleteModal.classList.add('hidden');

                // If we deleted everything on the current page, slide back a page if possible
                if (allFiles.length === idToDelete.length && currentPage > 1) {
                    currentPage--;
                }
                
                fetchGalleryFiles(); // Reload refreshed dataset
            } else {
                alert(`Deletion failed: ${data.message}`);
            }
        })
        .catch(err => {
            alert(`Error connecting to server: ${err.message}`);
        })
        .finally(() => {
            deleteModalConfirm.disabled = false;
            deleteModalConfirm.innerHTML = originalConfirmHTML;
            idToDelete = [];
        });
    }


    /**
     * Batch Append Selection to active chat session references
     */
    function batchAppendSelected() {
        if (selectedFileIds.size === 0) return;
        if (typeof window.addFileReference !== 'function') {
            alert("Chat system reference utility missing.");
            return;
        }

        selectedFileIds.forEach(id => {
            const file = allFiles.find(f => f.id === id);
            if (file) {
                const formattedFile = {
                    physical_name: file.physical_name,
                    file_type: isImageFile(file) ? 'image' : 'document',
                    generated_title: file.generated_title,
                    original_name: file.original_name,
                    preview: file.snippet || ''
                };
                window.addFileReference(formattedFile);
            }
        });

        selectedFileIds.clear();
        updateBatchBar();
        renderGrid();
        closePreviewDrawer();

        if (typeof window.switchSidebarTab === 'function') {
            window.switchSidebarTab('chats');
        }
    }

    // --- EVENT BINDINGS & LISTENERS ---

    // Listen to custom custom event dispatched from sidebar tab switching
    document.addEventListener('gallery-opened', () => {
        currentPage = 1;
        selectedFileIds.clear();
        updateBatchBar();
        closePreviewDrawer();
        fetchGalleryFiles();
    });

    // Real-time search keyup debouncer
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                currentQuery = searchInput.value;
                currentPage = 1;
                fetchGalleryFiles();
            }, 300);
        });
    }

    if (clearFiltersBtn) {
        clearFiltersBtn.addEventListener('click', () => {
            searchInput.value = '';
            currentQuery = '';
            currentPage = 1;
            fetchGalleryFiles();
        });
    }

    // Category Tabs click handlers
    const setupCategoryFilter = (btn, filterName) => {
        if (!btn) return;
        btn.addEventListener('click', () => {
            // Update active states
            [filterBtnAll, filterBtnImages, filterBtnDocs].forEach(b => {
                if (b) {
                    b.classList.remove('bg-slate-900', 'text-cyan-400');
                    b.classList.add('text-slate-400');
                }
            });
            btn.classList.add('bg-slate-900', 'text-cyan-400');
            btn.classList.remove('text-slate-400');

            currentFilter = filterName;
            renderGrid();
        });
    };

    setupCategoryFilter(filterBtnAll, 'all');
    setupCategoryFilter(filterBtnImages, 'images');
    setupCategoryFilter(filterBtnDocs, 'docs');

    // Pagination Listeners
    if (pagerPrev) {
        pagerPrev.addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                fetchGalleryFiles();
                document.getElementById('gallery-grid-scroll-container').scrollTop = 0;
            }
        });
    }

    if (pagerNext) {
        pagerNext.addEventListener('click', () => {
            if (currentPage < totalPages) {
                currentPage++;
                fetchGalleryFiles();
                document.getElementById('gallery-grid-scroll-container').scrollTop = 0;
            }
        });
    }

    // Preview Drawer Drawer Action Bindings
    if (closePreviewBtn) closePreviewBtn.addEventListener('click', closePreviewDrawer);

    if (drawerActionExplorer) {
        drawerActionExplorer.addEventListener('click', (e) => {
            if (activePreviewFile && typeof window.showFileInExplorer === 'function') {
                window.showFileInExplorer(activePreviewFile.physical_name, e.currentTarget);
            }
        });
    }

    if (drawerActionAppend) {
        drawerActionAppend.addEventListener('click', (e) => {
            if (activePreviewFile && typeof window.appendFileFromAccordion === 'function') {
                window.appendFileFromAccordion(e.currentTarget, activePreviewFile);
                
                closePreviewDrawer();

                if (typeof window.switchSidebarTab === 'function') {
                    window.switchSidebarTab('chats');
                }
            }
        });
    }

    if (drawerActionDelete) {
        drawerActionDelete.addEventListener('click', () => {
            if (activePreviewFile) {
                triggerSingleDelete(activePreviewFile.id);
            }
        });
    }

    // Deletion Modal Button Bindings
    if (deleteModalCancel) {
        deleteModalCancel.addEventListener('click', () => {
            deleteModal.classList.add('hidden');
            idToDelete = [];
        });
    }

    if (deleteModalConfirm) {
        deleteModalConfirm.addEventListener('click', confirmAndExecuteDeletion);
    }

    // Floating batch bar actions binding
    if (batchActionAppend) batchActionAppend.addEventListener('click', batchAppendSelected);
    if (batchActionDelete) batchActionDelete.addEventListener('click', triggerBatchDelete);
});

// --- DIRECT DROP, COPY-PASTE, & DISK SYNCHRONIZATION INTEGRATIONS ---

    const galleryDropContainer = document.getElementById('gallery-grid-scroll-container');
    const galleryDropOverlay = document.getElementById('gallery-drop-overlay');
    const gallerySyncBtn = document.getElementById('gallery-sync-btn');
    const galleryWorkspace = document.getElementById('gallery-workspace');

    if (galleryDropContainer && galleryDropOverlay) {
        let dragCounter = 0;

        galleryDropContainer.addEventListener('dragenter', (e) => {
            e.preventDefault();
            dragCounter++;
            galleryDropOverlay.classList.remove('opacity-0', 'pointer-events-none');
            galleryDropOverlay.classList.add('opacity-100');
        });

        galleryDropContainer.addEventListener('dragover', (e) => {
            e.preventDefault();
        });

        galleryDropContainer.addEventListener('dragleave', (e) => {
            e.preventDefault();
            dragCounter--;
            if (dragCounter === 0) {
                galleryDropOverlay.classList.add('opacity-0', 'pointer-events-none');
                galleryDropOverlay.classList.remove('opacity-100');
            }
        });

        galleryDropContainer.addEventListener('drop', (e) => {
            e.preventDefault();
            dragCounter = 0;
            galleryDropOverlay.classList.add('opacity-0', 'pointer-events-none');
            galleryDropOverlay.classList.remove('opacity-100');

            const droppedFiles = e.dataTransfer.files;
            if (droppedFiles.length > 0) {
                uploadDroppedFilesToGallery(droppedFiles);
            }
        });
    }

    document.addEventListener('paste', (e) => {
        if (galleryWorkspace && !galleryWorkspace.classList.contains('hidden')) {
            const items = (e.clipboardData || window.clipboardData).items;
            const filesToUpload = [];

            for (let item of items) {
                if (item.kind === 'file') {
                    const file = item.getAsFile();
                    if (file) {
                        filesToUpload.push(file);
                    }
                }
            }

            if (filesToUpload.length > 0) {
                e.preventDefault(); 
                uploadDroppedFilesToGallery(filesToUpload);
            }
        }
    });

    /**
     * Iterates over files and uploads them in parallel to the Direct Upload API action
     */
    function uploadDroppedFilesToGallery(files) {
        const loader = document.getElementById('gallery-loader');
        const grid = document.getElementById('gallery-grid');
        if (!loader || !grid) return;

        loader.classList.remove('opacity-0', 'pointer-events-none');
        loader.style.display = 'flex';
        
        const loaderText = loader.querySelector('span');
        const originalLoaderText = loaderText ? loaderText.textContent : 'Indexing Disk Files...';
        
        if (loaderText) {
            loaderText.textContent = `Uploading and AI indexing ${files.length} file(s)...`;
        }

        const uploadPromises = Array.from(files).map(file => {
            const formData = new FormData();
            formData.append('file', file);
            
            return fetch('index.php?api_action=upload_file', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status !== 'success') {
                    console.error(`Upload error for file ${file.name}: ${data.message}`);
                }
            })
            .catch(err => {
                console.error(`Network communication error for file ${file.name}:`, err);
            });
        });

        Promise.all(uploadPromises)
            .finally(() => {
                if (loaderText) {
                    loaderText.textContent = originalLoaderText;
                }
                
                if (typeof fetchGalleryFiles === 'function') {
                    fetchGalleryFiles();
                } else {
                    location.reload();
                }
            });
    }

    // 3. Disk Synchronization click handler
    if (gallerySyncBtn) {
        gallerySyncBtn.addEventListener('click', () => {
            const loader = document.getElementById('gallery-loader');
            const originalBtnHTML = gallerySyncBtn.innerHTML;
            gallerySyncBtn.disabled = true;
            gallerySyncBtn.innerHTML = `
                <svg class="animate-spin h-3 w-3 text-cyan-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                Syncing...
            `;

            if (loader) {
                loader.classList.remove('opacity-0', 'pointer-events-none');
                loader.style.display = 'flex';
                const loaderText = loader.querySelector('span');
                if (loaderText) {
                    loaderText.textContent = 'Scanning local uploads folder for new files...';
                }
            }

            fetch('index.php?api_action=sync_files')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        const count = data.synced_count || 0;
                        alert(`Sync complete. Identified and AI-indexed ${count} new file(s).`);
                    } else {
                        alert(`Sync failed: ${data.message}`);
                    }
                })
                .catch(err => {
                    alert(`Network error during sync: ${err.message}`);
                })
                .finally(() => {
                    gallerySyncBtn.disabled = false;
                    gallerySyncBtn.innerHTML = originalBtnHTML;
                    
                    if (loader) {
                        const loaderText = loader.querySelector('span');
                        if (loaderText) {
                            loaderText.textContent = 'Indexing Disk Files...';
                        }
                    }
                    
                    if (typeof fetchGalleryFiles === 'function') {
                        fetchGalleryFiles();
                    } else {
                        location.reload();
                    }
                });
        });
    }