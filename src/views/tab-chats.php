<div id="panel-chats" class="h-full overflow-y-auto p-4 space-y-1 hidden">
    <?php if (empty($sessions)): ?>
        <p class="text-xs text-slate-500 text-center py-4">No conversations found.</p>
    <?php else: ?>
        <?php foreach ($sessions as $s): ?>
            <div class="group flex justify-between items-center px-3 py-2.5 rounded-lg transition-all duration-200 text-sm <?php echo (int)$s['id'] === (int)$sessionId ? 'bg-slate-800/80 border-l-2 border-cyan-400 text-white shadow-md' : 'hover:bg-slate-800/40 text-slate-400 hover:text-slate-200'; ?>">
                <a href="index.php?session_id=<?php echo $s['id']; ?>&tab=chats" class="truncate block flex-1 text-left select-none outline-none">
                    <span class="session-title font-medium"><?php echo htmlspecialchars($s['title']); ?></span>
                </a>
                <a href="index.php?delete_session=<?php echo $s['id']; ?>" class="opacity-0 group-hover:opacity-100 text-slate-500 hover:text-rose-400 transition-colors p-1" onclick="return confirm('Delete this conversation?');">
                    <uk-icon icon="trash" class="w-3.5 h-3.5"></uk-icon>
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>