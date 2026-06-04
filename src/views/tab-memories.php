<div id="panel-memories" class="h-full overflow-y-auto p-4 space-y-3 hidden">
    <div class="flex justify-between items-center bg-[#070b14] p-2.5 border border-slate-800 rounded-lg">
        <span class="text-xs font-semibold text-slate-400">Memory Count</span>
        <span class="text-xs font-bold text-cyan-400 px-2 py-0.5 rounded bg-cyan-500/15 border border-cyan-500/30">
            <?php echo $memoryCount; ?> / 500
        </span>
    </div>

    <!-- Add Custom Memory Constraint Form -->
    <form method="POST" action="index.php?session_id=<?php echo $sessionId; ?>&tab=memories" class="space-y-2">
        <input type="hidden" name="add_memory" value="1">
        <div class="flex gap-1.5">
            <input type="text" name="memory_text" placeholder="Add custom memory constraint..." required 
                   class="input-futuristic flex-1 text-xs px-2.5 py-2 rounded-lg" 
                   <?php echo $memoryCount >= 500 ? 'disabled' : ''; ?>>
            <button type="submit" class="btn-futuristic px-3 rounded-lg flex items-center justify-center font-bold"
                    <?php echo $memoryCount >= 500 ? 'disabled' : ''; ?> title="Add Memory">
                <uk-icon icon="plus" class="w-4 h-4"></uk-icon>
            </button>
        </div>
        <?php if ($memoryCount >= 500): ?>
            <p class="text-[10px] text-rose-400 font-semibold tracking-wide">Memory bank capacity (500) reached.</p>
        <?php endif; ?>
    </form>

    <!-- Memory Rows -->
    <div class="space-y-2.5 pt-2">
        <?php if (empty($memories)): ?>
            <p class="text-xs text-slate-500 text-center py-4">No active memories stored.</p>
        <?php else: ?>
            <?php foreach ($memories as $m): ?>
                <div class="bg-slate-900/40 border border-slate-800/80 rounded-lg p-3 text-xs relative group transition-all duration-200 hover:border-slate-700/60 shadow-sm">
                    
                    <!-- Static Display -->
                    <div id="memory-view-<?php echo $m['id']; ?>" class="space-y-2.5">
                        <p class="m-0 text-slate-200 leading-relaxed break-words whitespace-pre-line"><?php echo htmlspecialchars($m['memory_text']); ?></p>
                        <div class="flex justify-between items-center text-[10px] text-slate-500 pt-1 border-t border-slate-800/40">
                            <span><?php echo date('M d, Y', strtotime($m['created_at'])); ?></span>
                            <div class="flex gap-2.5 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
                                <button onclick="enableMemoryEdit(<?php echo $m['id']; ?>)" class="text-cyan-400 hover:text-cyan-300 font-semibold transition-colors">Edit</button>
                                <form method="POST" action="index.php?session_id=<?php echo $sessionId; ?>&tab=memories" class="inline" onsubmit="return confirm('Nuke this memory permanently?');">
                                    <input type="hidden" name="delete_memory" value="1">
                                    <input type="hidden" name="memory_id" value="<?php echo $m['id']; ?>">
                                    <button type="submit" class="text-rose-400 hover:text-rose-300 font-semibold transition-colors">Delete</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Inline Form editor -->
                    <form id="memory-edit-<?php echo $m['id']; ?>" method="POST" action="index.php?session_id=<?php echo $sessionId; ?>&tab=memories" class="hidden space-y-2">
                        <input type="hidden" name="update_memory" value="1">
                        <input type="hidden" name="memory_id" value="<?php echo $m['id']; ?>">
                        <textarea name="memory_text" class="input-futuristic w-full rounded-lg p-2 text-xs h-20 leading-relaxed focus:ring-1 focus:ring-cyan-500 focus:border-cyan-500" required><?php echo htmlspecialchars($m['memory_text']); ?></textarea>
                        <div class="flex justify-end gap-1.5 text-[10px]">
                            <button type="button" onclick="disableMemoryEdit(<?php echo $m['id']; ?>)" class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 text-slate-300 transition-colors">Cancel</button>
                            <button type="submit" class="px-3 py-1 rounded btn-futuristic font-semibold">Save</button>
                        </div>
                    </form>
                    
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>