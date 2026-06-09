<!-- src/views/chat-file-editor-drawer.php -->

<div id="chat-file-editor-drawer" class="w-0 border-l border-slate-800 bg-[#070c19] h-full flex flex-col transition-all duration-300 ease-out overflow-hidden shrink-0 relative shadow-[0_0_30px_rgba(0,0,0,0.5)]">
    
    <!-- 1. Drawer Header -->
    <div class="px-5 py-4 border-b border-slate-800/60 flex items-center justify-between select-none shrink-0 bg-[#0a1124] h-16 shadow-[0_4px_12px_rgba(0,0,0,0.15)]">
        <div class="flex items-center gap-2.5 truncate max-w-[50%]">
            <div class="w-8 h-8 bg-cyan-950/40 border border-cyan-500/30 rounded flex items-center justify-center shrink-0">
                <uk-icon icon="file-text" class="w-3.5 h-3.5 text-cyan-400"></uk-icon>
            </div>
            <div class="flex flex-col min-w-0">
                <span id="editor-file-title" class="text-[11px] font-bold tracking-wider text-slate-100 truncate uppercase">No File Selected</span>
                <span class="text-[9px] font-bold text-slate-500 tracking-wider uppercase flex items-center gap-1.5 mt-0.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500/80 animate-pulse"></span> Draft Workspace
                </span>
            </div>
        </div>
        
        <div class="flex items-center gap-2">
            <!-- Edit Selection Button (Hidden by default, visible when 2+ adjacent blocks selected) -->
            <button id="editor-edit-selection-btn" class="hidden flex items-center justify-center gap-1.5 px-3 py-1.5 text-[10px] font-extrabold tracking-wider uppercase bg-blue-950/30 hover:bg-blue-900/50 text-blue-400 border border-blue-500/30 rounded-lg transition-all cursor-pointer outline-none shadow-md">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-blue-400"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                Edit Selection
            </button>

             <!-- Delete Selection Button (Hidden by default, visible when 1+ blocks selected) -->
            <button id="editor-delete-selection-btn" class="hidden flex items-center justify-center gap-1.5 px-3 py-1.5 text-[10px] font-extrabold tracking-wider uppercase bg-rose-950/20 hover:bg-rose-950/50 text-rose-400 border border-rose-500/30 rounded-lg transition-all cursor-pointer outline-none shadow-md">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-rose-400"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                Delete Selection
            </button>

            <!-- Save/Commit Button -->
            <button id="editor-save-btn" class="flex items-center justify-center gap-1.5 px-3 py-1.5 text-[10px] font-extrabold tracking-wider uppercase bg-cyan-950/40 hover:bg-cyan-900/50 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 rounded-lg transition-all cursor-pointer outline-none shadow-[0_0_12px_rgba(6,182,212,0.15)]">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                Save Changes
            </button>
            
            <!-- Close / Discard Button -->
            <button id="editor-close-btn" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-500 hover:text-slate-300 hover:bg-slate-800/40 transition-colors cursor-pointer text-lg font-bold outline-none leading-none">&times;</button>
        </div>
    </div>

    <!-- 2. Drawer Body (Scrollable Block Container) -->
    <div class="flex-1 overflow-y-auto p-4 space-y-1.5 select-text bg-[#070c19] scrollbar-thin scrollbar-thumb-slate-800 scrollbar-track-transparent" id="editor-blocks-container">
        <!-- Javascript will inject the interactive blocks here dynamically -->
    </div>

    <!-- 3. Blocking Overlay (Active when AI is writing) -->
    <div id="editor-lock-overlay" class="absolute inset-0 bg-[#050811]/90 backdrop-blur-[2px] z-30 flex flex-col items-center justify-center gap-4 transition-opacity duration-300 opacity-0 pointer-events-none select-none">
        <div class="flex flex-col items-center gap-3">
            <div class="relative flex items-center justify-center">
                <div class="absolute w-12 h-12 rounded-full border border-cyan-500/20 animate-ping"></div>
                <svg class="animate-spin h-8 w-8 text-cyan-400 relative z-10" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            </div>
            <div class="text-center">
                <span class="text-[11px] text-cyan-400 font-extrabold tracking-widest uppercase animate-pulse block">Work in progress.</span>
                <span class="text-[9px] text-slate-500 uppercase tracking-widest block mt-1">Please hold on while the application is running...</span>
            </div>
        </div>
    </div>
</div>