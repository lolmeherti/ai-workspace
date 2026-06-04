<aside class="w-[300px] glass-panel border-r flex flex-col h-full shrink-0 z-10 shadow-xl overflow-hidden bg-[#0a0f1d]">
    
    <!-- Static Header -->
    <div class="p-4 border-b border-slate-800/60 bg-[#0d1321]">
        <div class="mb-4">
            <a href="index.php?new_chat=1" class="btn-futuristic w-full flex items-center justify-center gap-2 py-2.5 rounded-lg font-medium text-sm">
                <uk-icon icon="plus" class="w-4 h-4"></uk-icon> New Chat
            </a>
        </div>

        <div class="flex bg-[#070b14] p-1 rounded-lg border border-slate-800 text-xs font-semibold relative">
            <button onclick="switchSidebarTab('chats')" id="tab-btn-chats" class="flex-1 py-2 rounded-md transition-all text-center flex items-center justify-center gap-1 cursor-pointer">
                <uk-icon icon="message-square" class="w-3.5 h-3.5"></uk-icon> Chats
            </button>
            <button onclick="switchSidebarTab('memories')" id="tab-btn-memories" class="flex-1 py-2 rounded-md transition-all text-center flex items-center justify-center gap-1 cursor-pointer">
                <uk-icon icon="brain" class="w-3.5 h-3.5"></uk-icon> Memories
            </button>
            <button onclick="switchSidebarTab('queries')" id="tab-btn-queries" class="flex-1 py-2 rounded-md transition-all text-center flex items-center justify-center gap-1 cursor-pointer">
                <uk-icon icon="search" class="w-3.5 h-3.5"></uk-icon> Queries
            </button>
        </div>
    </div>

    <!-- Active Scrollable Tab Panel Container -->
    <div class="flex-1 overflow-hidden relative">
        <!-- Tab Panels -->
        <?php include __DIR__ . '/tab-chats.php'; ?>
        <?php include __DIR__ . '/tab-memories.php'; ?>
        <?php include __DIR__ . '/tab-queries.php'; ?>
    </div>
    
    <!-- Static Footer -->
    <div class="p-4 border-t border-slate-800/80 space-y-4 bg-[#090d18] mt-auto">
        <p class="m-0">
            <a href="#settings-modal" uk-toggle class="text-sm font-medium text-slate-300 hover:text-cyan-400 transition-colors flex items-center gap-2 cursor-pointer outline-none">
                <uk-icon icon="settings" class="w-4 h-4"></uk-icon> Settings
            </a>
        </p>
        
        <details class="group bg-[#0f172a] border border-slate-800 rounded-lg overflow-hidden transition-all duration-300">
            <summary class="list-none flex justify-between items-center p-3 text-sm font-medium cursor-pointer select-none hover:bg-slate-800/50 text-slate-300">
                <div class="flex items-center gap-2">
                    <uk-icon icon="activity" class="w-4 h-4 text-cyan-400"></uk-icon> System Health
                </div>
                <uk-icon icon="chevron-down" class="w-4 h-4 transition-transform group-open:rotate-180"></uk-icon>
            </summary>
            <div class="p-4 text-xs leading-relaxed space-y-2 border-t border-slate-800 bg-[#0b1120]">
                <div class="flex justify-between items-center"><span>Database</span> <span class="<?php echo $status->database ? 'text-emerald-400 status-glow-online' : 'text-rose-400 status-glow-offline'; ?> font-bold"><?php echo $status->database ? 'Online' : 'Offline'; ?></span></div>
                <div class="flex justify-between items-center"><span>Cache (Redis)</span> <span class="<?php echo $status->redis ? 'text-emerald-400 status-glow-online' : 'text-rose-400 status-glow-offline'; ?> font-bold"><?php echo $status->redis ? 'Online' : 'Offline'; ?></span></div>
                <div class="flex justify-between items-center"><span>SearXNG</span> <span class="<?php echo $status->searxng ? 'text-emerald-400 status-glow-online' : 'text-rose-400 status-glow-offline'; ?> font-bold"><?php echo $status->searxng ? 'Online' : 'Offline'; ?></span></div>
                <div class="flex justify-between items-center"><span>Scraper</span> <span class="<?php echo $status->flaresolverr ? 'text-emerald-400 status-glow-online' : 'text-rose-400 status-glow-offline'; ?> font-bold"><?php echo $status->flaresolverr ? 'Online' : 'Offline'; ?></span></div>
                <div class="flex justify-between items-center"><span>AI Core</span> <span class="<?php echo $status->ai ? 'text-emerald-400 status-glow-online' : 'text-rose-400 status-glow-offline'; ?> font-bold"><?php echo $status->ai ? 'Online' : 'Offline'; ?></span></div>
            </div>
        </details>

        <form method="POST" action="index.php" onsubmit="return confirm('Wipe database? This will delete all sessions and messages.');">
            <input type="hidden" name="clear_all" value="1">
            <button type="submit" class="w-full bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/30 rounded-lg text-xs font-medium py-2 transition-colors">Clear All History</button>
        </form>
    </div>
</aside>