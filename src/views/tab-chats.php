<?php
// Fallback: Fetch sessions if not already loaded globally by index.php
if (!isset($sessions) && isset($db)) {
    try {
        $sessions = $db->query("SELECT * FROM chat_sessions ORDER BY created_at DESC");
    } catch (\Exception $e) {
        $sessions = [];
    }
}
$sessions = $sessions ?? [];

// Determine the active state
$currentSessionId = (int)($sessionId ?? ($_GET['session_id'] ?? 0));
$chatsActive = ($activeTab ?? 'chats') === 'chats';
?>

<!-- Embedded Custom Styling for Manage Mode (Keeps things clean) -->
<style>
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
/* Hide the individual trash icons during multi-select operations */
.in-edit-mode .btn-delete-single {
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
            ?>
                <div data-session-id="<?php echo $session['id']; ?>" 
                     class="chat-session-item group relative flex items-center justify-between rounded-lg p-2 transition-all duration-200 cursor-pointer border <?php echo $isActive ? 'bg-slate-800/80 text-white border-slate-700/50 shadow-sm' : 'border-transparent text-slate-400 hover:bg-slate-800/30 hover:text-slate-200'; ?>">
                    
                    <a href="index.php?session_id=<?php echo $session['id']; ?>&tab=chats" class="session-link flex-1 flex items-center gap-2 truncate pr-6 select-none">
                        <!-- Custom Selection Indicator (Visible only in Manage Mode) -->
                        <span class="select-check-icon hidden mr-1 shrink-0">
                            <uk-icon icon="check" class="w-3.5 h-3.5 text-rose-400"></uk-icon>
                        </span>
                        
                        <uk-icon icon="message-square" class="w-3.5 h-3.5 text-cyan-500/70 shrink-0"></uk-icon>
                        <span class="session-title truncate text-xs"><?php echo htmlspecialchars($session['title']); ?></span>
                    </a>
                    
                    <!-- Individual Trash Action (Hidden automatically in Manage Mode) -->
                    <a href="index.php?delete_session=<?php echo $session['id']; ?>" 
                       onclick="return confirm('Are you sure you want to delete this conversation?');" 
                       class="btn-delete-single absolute right-2 opacity-0 group-hover:opacity-100 transition-opacity text-slate-500 hover:text-rose-400 py-1 px-1.5 rounded hover:bg-slate-800/50">
                        <uk-icon icon="trash" class="w-3.5 h-3.5"></uk-icon>
                    </a>
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