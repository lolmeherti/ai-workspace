<!-- Main Workspace Container -->
<div class="flex-1 flex flex-col h-full min-w-0 bg-[#070b13] relative overflow-hidden">

    <!-- 1. Header Area: Title, Synchronization & Filtering -->
    <header class="p-6 border-b border-slate-800/60 bg-[#0d1321]/60 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 select-none shrink-0">
        <div>
            <h1 class="text-lg font-bold text-slate-100 flex items-center gap-2 tracking-wide">
                <uk-icon icon="folder" class="w-5 h-5 text-cyan-400"></uk-icon>
                File Management Hub
            </h1>
            <p class="text-xs text-slate-400">Search, preview, and batch operations on raw disk uploads.</p>
        </div>

        <div class="flex flex-col sm:flex-row gap-3 items-stretch sm:items-center">
           <!-- Sync Disk Folder Button -->
            <button type="button" id="gallery-sync-btn" class="group flex items-center justify-center gap-1.5 bg-transparent border border-slate-800/80 hover:border-cyan-500/40 text-slate-400 hover:text-cyan-400 px-2.5 py-0.5 rounded-full text-[10px] tracking-wider transition-all duration-300 font-bold cursor-pointer outline-none" title="Sync Uploads Directory with Database">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="w-3 h-3 transform group-hover:rotate-180 transition-transform duration-500 ease-out">
                    <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                    <path d="M3 3v5h5"/>
                    <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/>
                    <path d="M16 16h5v5"/>
                </svg>
                <span>SYNC DISK</span>
            </button>

            <!-- Glass Search Bar -->
            <div class="relative min-w-[240px]">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-500">
                    <uk-icon icon="search" class="w-4 h-4"></uk-icon>
                </span>
                <input type="text" id="gallery-search-input" 
                    placeholder="Search titles or filenames..." 
                    class="w-full pl-9 pr-4 py-2 text-xs font-medium text-slate-200 bg-[#091124]/90 border border-cyan-500/20 hover:border-cyan-500/40 focus:border-cyan-500/60 rounded-lg shadow-inner outline-none transition-all placeholder:text-slate-500" />
            </div>

            <!-- Category Pills -->
            <div class="flex bg-[#05070f] p-1 rounded-lg border border-slate-800 text-xs font-semibold">
                <button id="filter-btn-all" class="px-3 py-1.5 rounded-md text-[11px] uppercase tracking-wider text-cyan-400 bg-slate-900 cursor-pointer transition-all">All</button>
                <button id="filter-btn-images" class="px-3 py-1.5 rounded-md text-[11px] uppercase tracking-wider text-slate-400 hover:text-cyan-400 cursor-pointer transition-all">Images</button>
                <button id="filter-btn-docs" class="px-3 py-1.5 rounded-md text-[11px] uppercase tracking-wider text-slate-400 hover:text-cyan-400 cursor-pointer transition-all">Documents</button>
            </div>
        </div>
    </header>

    <!-- 2. Grid & Drawer Split-Viewport -->
    <div class="flex-1 flex relative overflow-hidden">
        
        <!-- Main Scrollable Grid Area (Dropzone target) -->
        <div class="flex-1 h-full overflow-y-auto p-6 relative" id="gallery-grid-scroll-container">
            
            <!-- Drag and Drop Overlay visual indicator -->
            <div id="gallery-drop-overlay" class="absolute inset-4 bg-[#070b13]/95 border-2 border-dashed border-cyan-500/50 rounded-xl z-40 flex flex-col items-center justify-center gap-3 transition-opacity duration-200 opacity-0 pointer-events-none select-none">
                <div class="flex flex-col items-center gap-3 pointer-events-none">
                    <uk-icon icon="cloud-upload" class="w-12 h-12 text-cyan-400 animate-pulse"></uk-icon>
                    <span class="text-sm font-bold text-cyan-400 tracking-widest uppercase">Drop files to upload & AI index</span>
                    <span class="text-[10px] text-slate-500">Supports images, PDFs, word documents, and text files</span>
                </div>
            </div>

            <!-- Active Filter Sub-Indicator -->
            <div class="flex items-center justify-between text-slate-400 text-[11px] font-semibold uppercase tracking-wider mb-4 border-b border-slate-900 pb-2 select-none animate-fade-in">
                <span id="gallery-count-label">Loading your files...</span>
                <span class="text-cyan-500/70 cursor-pointer hover:underline hidden" id="gallery-clear-filters">Clear Filters</span>
            </div>

            <!-- Dynamic File Cards Grid -->
            <div id="gallery-grid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 pb-24">
                <!-- Javascript will render cards here -->
            </div>

            <!-- Loading Spinner Template -->
            <div id="gallery-loader" class="absolute inset-0 flex items-center justify-center bg-[#070b13]/80 z-10 transition-opacity duration-300">
                <div class="flex flex-col items-center gap-3 select-none">
                    <svg class="animate-spin h-8 w-8 text-cyan-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span class="text-xs text-cyan-400 font-bold tracking-widest uppercase">Indexing Disk Files...</span>
                </div>
            </div>

            <!-- Pagination Controls -->
            <div id="gallery-pagination" class="flex justify-center items-center gap-3 pt-6 pb-12 select-none border-t border-slate-900/60 hidden">
                <button id="pager-prev" class="px-3 py-1.5 text-[10px] font-extrabold uppercase tracking-wider bg-slate-900 hover:bg-slate-850 text-slate-300 hover:text-cyan-400 border border-slate-800 rounded-lg cursor-pointer disabled:opacity-40 disabled:pointer-events-none transition-all">Prev</button>
                <span class="text-xs text-slate-400 font-semibold" id="pager-info">Page 1 of 1</span>
                <button id="pager-next" class="px-3 py-1.5 text-[10px] font-extrabold uppercase tracking-wider bg-slate-900 hover:bg-slate-850 text-slate-300 hover:text-cyan-400 border border-slate-800 rounded-lg cursor-pointer disabled:opacity-40 disabled:pointer-events-none transition-all">Next</button>
            </div>
        </div>

        <!-- Right-Side Slide-Over Preview Drawer -->
        <div id="gallery-preview-drawer" 
            class="w-[420px] max-w-full border-l border-slate-800/80 bg-[#091124] h-full flex flex-col transition-all duration-300 transform translate-x-full absolute md:relative right-0 top-0 z-20 shadow-2xl shrink-0">
            
            <!-- Drawer Header -->
            <div class="p-4 border-b border-slate-800 flex items-center justify-between select-none shrink-0 bg-[#0d1321]">
                <div class="flex items-center gap-2">
                    <uk-icon icon="info" class="w-4 h-4 text-cyan-400"></uk-icon>
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-200">File Preview</span>
                </div>
                <button id="close-preview-btn" class="text-slate-500 hover:text-slate-200 transition-colors cursor-pointer text-lg font-bold outline-none">&times;</button>
            </div>

            <!-- Drawer Body -->
            <div class="flex-1 overflow-y-auto p-5 space-y-5" id="drawer-body">
                <!-- Javascript will inject preview content dynamically -->
            </div>

            <!-- Drawer Footer Action Bar -->
            <div class="p-4 border-t border-slate-800 flex items-center justify-between select-none bg-[#090e1b] shrink-0" id="drawer-footer">
                <div class="flex items-center gap-2">
                    <button id="drawer-action-explorer" class="flex items-center justify-center gap-1.5 px-3 py-2 text-[10px] font-extrabold tracking-wider uppercase bg-slate-800 hover:bg-slate-750 text-slate-300 hover:text-cyan-400 border border-slate-700 hover:border-cyan-500/30 rounded-lg transition-all cursor-pointer">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
                        Show Local
                    </button>
                    <button id="drawer-action-append" class="flex items-center justify-center gap-1.5 px-3 py-2 text-[10px] font-extrabold tracking-wider uppercase bg-cyan-950/40 hover:bg-cyan-900/60 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 rounded-lg transition-all cursor-pointer">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>
                        Append
                    </button>
                </div>
                <button id="drawer-action-delete" class="flex items-center justify-center gap-1.5 px-3 py-2 text-[10px] font-extrabold tracking-wider uppercase bg-rose-950/25 hover:bg-rose-950/55 text-rose-400 border border-rose-500/20 hover:border-rose-400/50 rounded-lg transition-all cursor-pointer">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-rose-400"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                    Delete
                </button>
            </div>
        </div>

    </div>

    <!-- 3. Floating Batch Action Bar -->
    <div id="gallery-batch-bar" 
        class="fixed bottom-6 left-1/2 -translate-x-1/2 px-5 py-3.5 bg-[#091124]/95 border border-cyan-500/30 rounded-xl shadow-[0_0_20px_rgba(6,182,212,0.25)] flex items-center gap-6 z-35 select-none transition-all duration-300 transform translate-y-24 opacity-0 invisible">
        <span class="text-xs font-bold text-cyan-400 tracking-wider">
            <span id="batch-selection-count">0</span> Selected
        </span>
        <div class="h-5 w-[1px] bg-slate-800"></div>
        <div class="flex items-center gap-3">
            <button id="batch-action-append" class="flex items-center justify-center gap-1.5 px-4 py-2 text-[10px] font-extrabold tracking-wider uppercase bg-cyan-950/60 hover:bg-cyan-900 text-cyan-400 border border-cyan-500/40 hover:border-cyan-400 rounded-lg transition-all cursor-pointer">
                Append Selected
            </button>
            <button id="batch-action-delete" class="flex items-center justify-center gap-1.5 px-4 py-2 text-[10px] font-extrabold tracking-wider uppercase bg-rose-950/30 hover:bg-rose-950/65 text-rose-400 border border-rose-500/30 hover:border-rose-400 rounded-lg transition-all cursor-pointer">
                Delete Selected
            </button>
        </div>
    </div>

</div>

<!-- 4. Deletion Confirmation Modal Overlay -->
<div id="gallery-delete-modal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center hidden select-none animate-fade-in">
    <div class="w-full max-w-md bg-[#091124]/95 border border-rose-500/30 rounded-xl p-6 shadow-2xl space-y-4">
        <!-- Header -->
        <div class="flex items-center gap-3 text-rose-400">
            <span class="flex items-center justify-center shrink-0 w-8 h-8 bg-rose-500/10 rounded border border-rose-500/20">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </span>
            <h3 class="text-sm font-bold tracking-wider uppercase">Permanent Deletion Alert</h3>
        </div>

        <!-- Body -->
        <p class="text-xs text-slate-300 leading-relaxed" id="delete-modal-text">
            Are you sure you want to permanently delete these items from disk? This will delete both the primary file and any raw text extractions. This action cannot be undone.
        </p>

        <!-- Footer Buttons -->
        <div class="flex items-center justify-end gap-3 pt-2">
            <button id="delete-modal-cancel" class="px-4 py-2 text-[10px] font-extrabold uppercase tracking-wider bg-slate-800 hover:bg-slate-750 text-slate-300 border border-slate-700 rounded-lg transition-all cursor-pointer">
                Cancel
            </button>
            <button id="delete-modal-confirm" class="px-4 py-2 text-[10px] font-extrabold uppercase tracking-wider bg-rose-600 hover:bg-rose-500 text-white rounded-lg transition-all cursor-pointer">
                Confirm Delete
            </button>
        </div>
    </div>
</div>