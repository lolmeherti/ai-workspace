<div id="panel-memories" class="h-full overflow-y-auto p-4 space-y-3 hidden">
    <div class="flex justify-between items-center bg-[#070b14] p-2.5 border border-slate-800 rounded-lg">
        <span class="text-xs font-semibold text-slate-400">Memory Count</span>
        <span class="text-xs font-bold text-cyan-400 px-2 py-0.5 rounded bg-cyan-500/15 border border-cyan-500/30">
            <?php echo $memoryCount; ?> / 500
        </span>
    </div>

    <form id="consolidate-form" method="POST" action="index.php?session_id=<?php echo $sessionId; ?>&tab=memories">
        <input type="hidden" name="manual_consolidate" value="1">
        <button id="consolidate-btn" type="submit" class="w-full bg-cyan-500/10 hover:bg-cyan-500/20 text-cyan-400 border border-cyan-500/30 rounded-lg text-xs font-semibold py-2.5 transition-colors flex items-center justify-center gap-2 cursor-pointer">
            <uk-icon id="consolidate-icon" icon="brain" class="w-4 h-4"></uk-icon>
            <span id="consolidate-text">Consolidate & Clean Memories</span>
        </button>
    </form>

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

    <?php if (!empty($memories)): ?>
        <div class="flex justify-between items-center bg-[#070b14] px-3 py-2 rounded-lg border border-slate-800/80 text-xs">
            <div class="flex items-center gap-2">
                <input type="checkbox" id="select-all-memories" class="rounded border-slate-800 bg-slate-950 text-cyan-500 focus:ring-cyan-500/20 w-4 h-4 cursor-pointer">
                <label id="select-all-label" for="select-all-memories" class="text-slate-400 font-medium cursor-pointer select-none">Select All</label>
            </div>
            
            <form id="bulk-delete-form" method="POST" action="index.php?session_id=<?php echo $sessionId; ?>&tab=memories" onsubmit="return confirm('Nuke selected memories permanently?');" class="hidden items-center">
                <input type="hidden" name="delete_multiple_memories" value="1">
                <button type="submit" class="text-rose-400 hover:text-rose-300 font-semibold transition-colors flex items-center gap-1.5">
                    <uk-icon icon="trash" class="w-3.5 h-3.5"></uk-icon>
                    Delete Selected (<span id="selected-count">0</span>)
                </button>
            </form>
        </div>
    <?php endif; ?>

    <div class="space-y-2.5 pt-2">
        <?php if (empty($memories)): ?>
            <p class="text-xs text-slate-500 text-center py-4">No active memories stored.</p>
        <?php else: ?>
            <?php foreach ($memories as $m): ?>
                <div class="bg-slate-900/40 border border-slate-800/80 rounded-lg p-3 text-xs relative group transition-all duration-200 hover:border-slate-700/60 shadow-sm flex gap-3">
                    
                    <div class="pt-0.5">
                        <input type="checkbox" name="selected_memories[]" value="<?php echo $m['id']; ?>" form="bulk-delete-form" class="memory-checkbox rounded border-slate-800 bg-slate-950 text-cyan-500 focus:ring-cyan-500/20 w-4 h-4 cursor-pointer">
                    </div>

                    <div class="flex-1 min-w-0">
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
                    
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
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
</script>