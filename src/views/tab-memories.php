<?php $memoryFormAction = 'index.php?session_id=' . (int)$sessionId . '&amp;tab=memories'; ?>
<section id="panel-memories" class="memory-panel hidden" aria-label="Saved memories">
    <header class="memory-heading">
        <div><h2>Memories</h2><span class="memory-count"><?php echo (int)$memoryCount; ?> / 500</span></div>
        <p>Saved facts and preferences used in your conversations.</p>
    </header>
    <div class="memory-tools">
        <form id="consolidate-form" method="POST" action="<?php echo $memoryFormAction; ?>">
            <input type="hidden" name="manual_consolidate" value="1">
            <button id="consolidate-btn" type="submit" class="ui-button memory-consolidate-button" data-ai-action>
                <uk-icon id="consolidate-icon" icon="brain" aria-hidden="true"></uk-icon>
                <span id="consolidate-text">Consolidate &amp; clean</span>
            </button>
        </form>
        <form id="add-memory-form" method="POST" action="<?php echo $memoryFormAction; ?>" class="memory-add-form">
            <input type="hidden" name="add_memory" value="1">
            <label for="new-memory-text">Add a memory</label>
            <div class="memory-add-field">
                <input id="new-memory-text" type="text" name="memory_text" placeholder="A fact or preference…" required <?php echo $memoryCount >= 500 ? 'disabled' : ''; ?>>
                <button type="submit" class="ui-button ui-button--primary" aria-label="Add memory" <?php echo $memoryCount >= 500 ? 'disabled' : ''; ?>><uk-icon icon="plus" aria-hidden="true"></uk-icon></button>
            </div>
            <?php if ($memoryCount >= 500): ?><p class="memory-limit" role="status">Memory capacity reached. Remove an entry before adding another.</p><?php endif; ?>
        </form>
    </div>
    <div id="memory-notices" aria-live="polite"></div>
    <?php if (!empty($memories)): ?>
    <div class="memory-selection">
        <label for="select-all-memories"><input type="checkbox" id="select-all-memories">Select all</label>
        <form id="bulk-delete-form" method="POST" action="<?php echo $memoryFormAction; ?>" data-confirm="Delete selected memories permanently?" class="hidden">
            <input type="hidden" name="delete_multiple_memories" value="1">
            <button type="submit" class="memory-delete">Delete <span id="selected-count">0</span> selected</button>
        </form>
    </div>
    <?php endif; ?>
    <div class="memory-list">
        <?php if (empty($memories)): ?>
        <div class="memory-empty"><uk-icon icon="brain" aria-hidden="true"></uk-icon><h3>No saved memories</h3><p>Add a fact or preference above to help Localsy remember it.</p></div>
        <?php else: foreach ($memories as $m): $memoryId = (int)$m['id']; ?>
        <article class="memory-card">
            <input type="checkbox" name="selected_memories[]" value="<?php echo $memoryId; ?>" form="bulk-delete-form" class="memory-checkbox" aria-label="Select memory: <?php echo htmlspecialchars(function_exists('mb_substr') ? mb_substr($m['memory_text'], 0, 100) : substr($m['memory_text'], 0, 100), ENT_QUOTES); ?>">
            <div class="memory-card-body">
                <div id="memory-view-<?php echo $memoryId; ?>">
                    <p class="memory-text"><?php echo htmlspecialchars($m['memory_text']); ?></p>
                    <div class="memory-card-footer">
                        <time datetime="<?php echo htmlspecialchars(date('Y-m-d', strtotime($m['created_at']))); ?>"><?php echo date('M j, Y', strtotime($m['created_at'])); ?></time>
                        <div class="memory-card-actions">
                            <button type="button" onclick="enableMemoryEdit(<?php echo $memoryId; ?>)">Edit</button>
                            <form method="POST" action="<?php echo $memoryFormAction; ?>" data-confirm="Delete this memory permanently?">
                                <input type="hidden" name="delete_memory" value="1"><input type="hidden" name="memory_id" value="<?php echo $memoryId; ?>">
                                <button type="submit" class="memory-delete">Delete</button>
                            </form>
                        </div>
                    </div>
                </div>
                <form id="memory-edit-<?php echo $memoryId; ?>" method="POST" action="<?php echo $memoryFormAction; ?>" class="memory-edit-form hidden">
                    <input type="hidden" name="update_memory" value="1"><input type="hidden" name="memory_id" value="<?php echo $memoryId; ?>">
                    <label for="memory-text-<?php echo $memoryId; ?>">Edit memory</label>
                    <textarea id="memory-text-<?php echo $memoryId; ?>" name="memory_text" rows="5" required><?php echo htmlspecialchars($m['memory_text']); ?></textarea>
                    <div class="memory-card-actions"><button type="button" class="ui-button" onclick="disableMemoryEdit(<?php echo $memoryId; ?>)">Cancel</button><button type="submit" class="ui-button ui-button--primary">Save</button></div>
                </form>
            </div>
        </article>
        <?php endforeach; endif; ?>
    </div>
</section>
