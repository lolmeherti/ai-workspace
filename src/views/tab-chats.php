<?php
if (!isset($sessions) && isset($db)) {
    try {
        $sessions = $db->query("SELECT * FROM chat_sessions ORDER BY created_at DESC");
    } catch (\Exception $e) {
        $sessions = [];
    }
}
$sessions = $sessions ?? [];

$currentSessionId = (int)($sessionId ?? ($_GET['session_id'] ?? 0));
$chatsActive = ($activeTab ?? 'chats') === 'chats';
?>

<style>
/* Futuristic session items */
.chat-session-item {
    position: relative;
    border-left: 2px solid transparent !important;
}

/* Subtle cyan HUD glowing bar on the left for starred chats */
.chat-session-item[data-starred="1"] {
    border-left: 2px solid rgba(6, 182, 212, 0.75) !important;
    box-shadow: inset 4px 0 10px -4px rgba(6, 182, 212, 0.15);
}

/* Star Glow & Hover States */
.star-glow-active {
    color: #fbbf24 !important; /* Premium Amber */
    filter: drop-shadow(0 0 6px rgba(251, 191, 36, 0.6));
}

.star-glow-inactive {
    color: #475569 !important; /* Dark Slate default */
}

.star-glow-inactive:hover {
    color: #22d3ee !important; /* Cyan hover */
    filter: drop-shadow(0 0 4px rgba(34, 211, 238, 0.4));
}

/* Smooth border color transitions */
.in-edit-mode .chat-session-item {
    transition: border-color 0.2s, background-color 0.2s, box-shadow 0.2s;
}

/* Red alert hover states when hovering in edit mode */
.in-edit-mode .chat-session-item:hover {
    border-color: rgba(244, 63, 94, 0.2) !important;
    background-color: rgba(244, 63, 94, 0.05) !important;
}

/* Prevent inner text links from blocking container click bubbling */
.in-edit-mode .chat-session-item a {
    pointer-events: none !important;
    user-select: none;
}

/* Hide star action during multi-select operations */
.in-edit-mode .btn-star-session {
    display: none !important;
}
</style>

<div id="panel-chats" class="h-full flex flex-col <?php echo $chatsActive ? '' : 'hidden'; ?> overflow-hidden relative">
    
    <!-- Tab Sub-Header -->
    <div class="flex justify-between items-center px-4 py-3 border-b border-slate-800/40 bg-[#0b101f]">
        <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider select-none">Conversations</span>
        <button id="btn-manage-chats" onclick="toggleChatEditMode()" class="text-xs text-slate-400 hover:text-cyan-400 font-medium transition-colors cursor-pointer flex items-center gap-1">
            <uk-icon icon="file-edit" class="w-3.5 h-3.5"></uk-icon> Manage
        </button>
    </div>

    <!-- High-Tech Filter Segment Control -->
    <div class="px-4 py-2.5 border-b border-slate-800/40 bg-[#080d1a] flex gap-2">
        <button onclick="setChatFilter('all')" id="btn-filter-all" 
                class="flex-1 py-1.5 px-3 rounded-md border text-[11px] font-medium transition-all duration-200 text-center cursor-pointer">
            All
        </button>
        <button onclick="setChatFilter('starred')" id="btn-filter-starred" 
                class="flex-1 py-1.5 px-3 rounded-md border text-[11px] font-medium transition-all duration-200 text-center flex items-center justify-center gap-1.5 cursor-pointer">
            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="currentColor" class="text-amber-400/90 drop-shadow-[0_0_3px_rgba(245,158,11,0.5)]"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            Starred
        </button>
    </div>

    <!-- Scrollable Conversations List -->
    <div id="chats-list-container" class="flex-1 overflow-y-auto p-2 space-y-1 pb-16">
        <?php if (empty($sessions)): ?>
            <div class="text-center text-slate-500 text-xs py-8">
                <uk-icon icon="comments" class="w-8 h-8 text-slate-600/50 mb-2"></uk-icon>
                <p>No conversations yet.</p>
            </div>
        <?php else: ?>
            <?php foreach ($sessions as $session): 
                $isActive = ($currentSessionId === (int)$session['id']);
                $isStarred = !empty($session['is_starred']);
            ?>
                <div data-session-id="<?php echo $session['id']; ?>" 
                     data-starred="<?php echo $isStarred ? '1' : '0'; ?>"
                     class="chat-session-item group relative flex items-center justify-between rounded-lg p-2 transition-all duration-200 cursor-pointer border <?php echo $isActive ? 'bg-slate-800/80 text-white border-slate-700/50 shadow-sm' : 'border-transparent text-slate-400 hover:bg-slate-800/30 hover:text-slate-200'; ?>">
                    
                    <a href="index.php?session_id=<?php echo $session['id']; ?>&tab=chats" class="session-link flex-1 flex items-center gap-2 truncate pr-8 select-none">
                        <!-- Custom Selection Indicator (Visible only in Manage Mode) -->
                        <span class="select-check-icon hidden mr-1 shrink-0">
                            <uk-icon icon="check" class="w-3.5 h-3.5 text-rose-400"></uk-icon>
                        </span>
                        
                        <uk-icon icon="message-square" class="w-3.5 h-3.5 text-cyan-500/70 shrink-0"></uk-icon>
                        <span class="session-title truncate text-xs"><?php echo htmlspecialchars($session['title']); ?></span>
                    </a>

                    <!-- Star Button Toggle -->
                    <button onclick="toggleStarSession(event, <?php echo $session['id']; ?>)" 
                            class="btn-star-session absolute right-2 <?php echo $isStarred ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'; ?> transition-all duration-300 py-1 px-1.5 rounded hover:bg-slate-800/40 z-10">
                        <svg xmlns="http://www.w3.org/2000/svg" 
                             width="13" 
                             height="13" 
                             viewBox="0 0 24 24" 
                             fill="<?php echo $isStarred ? 'currentColor' : 'none'; ?>" 
                             stroke="currentColor" 
                             stroke-width="2" 
                             stroke-linecap="round" 
                             stroke-linejoin="round" 
                             class="star-icon <?php echo $isStarred ? 'star-glow-active' : 'star-glow-inactive'; ?> transition-all duration-300">
                            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                        </svg>
                    </button>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Floating Action Slide-up Panel -->
    <div id="multi-delete-bar" class="absolute bottom-0 left-0 right-0 p-3 bg-[#0d1321]/95 border-t border-slate-800/80 backdrop-blur-md translate-y-full transition-all duration-300 flex justify-between items-center z-20 shadow-[0_-5px_15px_rgba(0,0,0,0.4)]">
        <span class="text-xs text-slate-400">
            <span id="selected-chats-count" class="font-bold text-cyan-400">0</span> selected
        </span>
        <div class="flex gap-2">
            <button onclick="toggleChatEditMode()" class="px-2.5 py-1 text-xs text-slate-400 hover:text-slate-200 transition-colors cursor-pointer">
                Cancel
            </button>
            <button onclick="submitMultiDelete()" id="btn-submit-multi-delete" disabled class="px-3 py-1 text-xs font-semibold bg-rose-500/10 text-rose-400/50 border border-rose-500/10 rounded transition-colors flex items-center gap-1 opacity-50 cursor-not-allowed">
                <uk-icon icon="trash" class="w-3.5 h-3.5"></uk-icon> Delete
            </button>
        </div>
    </div>
</div>

<script>
let currentChatFilter = localStorage.getItem('chat_list_filter') || 'all';

function toggleStarSession(event, sessionId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    
    const btn = event.currentTarget;
    const item = btn.closest('.chat-session-item');
    const svg = btn.querySelector('.star-icon');
    if (!item || !svg) return;

    const isStarredNow = item.getAttribute('data-starred') === '1';
    const nextStarredState = !isStarredNow;
    
    item.setAttribute('data-starred', nextStarredState ? '1' : '0');
    
    if (nextStarredState) {
        btn.classList.remove('opacity-0');
        btn.classList.add('opacity-100');
        svg.setAttribute('fill', 'currentColor');
        svg.className.baseVal = "star-icon star-glow-active transition-all duration-300";
    } else {
        btn.classList.add('opacity-0');
        btn.classList.remove('opacity-100');
        svg.setAttribute('fill', 'none');
        svg.className.baseVal = "star-icon star-glow-inactive transition-all duration-300";
    }

    fetch(`index.php?toggle_star=${sessionId}&ajax=1`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                const confirmed = !!data.is_starred;
                item.setAttribute('data-starred', confirmed ? '1' : '0');
                if (confirmed) {
                    btn.classList.remove('opacity-0');
                    btn.classList.add('opacity-100');
                    svg.setAttribute('fill', 'currentColor');
                    svg.className.baseVal = "star-icon star-glow-active transition-all duration-300";
                } else {
                    btn.classList.add('opacity-0');
                    btn.classList.remove('opacity-100');
                    svg.setAttribute('fill', 'none');
                    svg.className.baseVal = "star-icon star-glow-inactive transition-all duration-300";
                }
                
                if (currentChatFilter === 'starred' && !confirmed) {
                    item.classList.add('hidden');
                }
            }
        })
        .catch(err => {
            console.error('Failed to star session:', err);

            item.setAttribute('data-starred', isStarredNow ? '1' : '0');
            if (isStarredNow) {
                btn.classList.add('opacity-100');
                btn.classList.remove('opacity-0');
                svg.setAttribute('fill', 'currentColor');
                svg.className.baseVal = "star-icon star-glow-active transition-all duration-300";
            } else {
                btn.classList.add('opacity-0');
                btn.classList.remove('opacity-100');
                svg.setAttribute('fill', 'none');
                svg.className.baseVal = "star-icon star-glow-inactive transition-all duration-300";
            }
        });
}

function setChatFilter(filter) {
    currentChatFilter = filter;
    localStorage.setItem('chat_list_filter', filter);

    const btnAll = document.getElementById('btn-filter-all');
    const btnStarred = document.getElementById('btn-filter-starred');
    const items = document.querySelectorAll('.chat-session-item');

    const activeFilterClass = "bg-[#0b1324] border-cyan-500/30 text-cyan-400 shadow-[0_0_8px_rgba(6,182,212,0.15)] font-semibold";
    const inactiveFilterClass = "bg-transparent border-transparent text-slate-400 hover:text-slate-200 hover:bg-slate-800/20";

    if (filter === 'all') {
        if (btnAll) btnAll.className = `flex-1 py-1.5 px-3 rounded-md border text-[11px] transition-all duration-200 text-center cursor-pointer ${activeFilterClass}`;
        if (btnStarred) btnStarred.className = `flex-1 py-1.5 px-3 rounded-md border text-[11px] transition-all duration-200 text-center flex items-center justify-center gap-1.5 cursor-pointer ${inactiveFilterClass}`;
        
        items.forEach(item => {
            item.classList.remove('hidden');
        });
    } else if (filter === 'starred') {
        if (btnAll) btnAll.className = `flex-1 py-1.5 px-3 rounded-md border text-[11px] transition-all duration-200 text-center cursor-pointer ${inactiveFilterClass}`;
        if (btnStarred) btnStarred.className = `flex-1 py-1.5 px-3 rounded-md border text-[11px] transition-all duration-200 text-center flex items-center justify-center gap-1.5 cursor-pointer ${activeFilterClass}`;
        
        items.forEach(item => {
            const isStarred = item.getAttribute('data-starred') === '1';
            if (isStarred) {
                item.classList.remove('hidden');
            } else {
                item.classList.add('hidden');
            }
        });
    }
}

document.addEventListener('DOMContentLoaded', () => {
    setChatFilter(currentChatFilter);
});
</script>