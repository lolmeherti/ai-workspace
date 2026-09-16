<aside id="workspace-sidebar" class="workspace-sidebar">
    <div class="sidebar-brand"><strong>Localsy</strong><span>AI workspace</span></div>
    <a href="index.php?new_chat=1" id="new-chat-link" class="ui-button ui-button--primary new-chat-link">
        <uk-icon icon="plus" class="w-4 h-4" aria-hidden="true"></uk-icon><span>New chat</span>
    </a>
    <nav class="workspace-nav" aria-label="Workspaces">
        <?php foreach ([
            'chats' => ['message-square', 'Chats'],
            'uploads' => ['folder', 'Files'],
            'memories' => ['brain', 'Memories'],
            'emails' => ['mail', 'Email'],
            'jobs' => ['briefcase', 'Jobs'],
        ] as $tab => [$icon, $label]): ?>
        <button type="button" onclick="switchSidebarTab('<?php echo $tab; ?>')" id="tab-btn-<?php echo $tab; ?>" aria-label="<?php echo $label; ?>" class="workspace-nav-button">
            <uk-icon icon="<?php echo $icon; ?>" class="w-5 h-5" aria-hidden="true"></uk-icon><span><?php echo $label; ?></span>
        </button>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-content">
        <?php include __DIR__ . '/tab-chats.php'; ?>
        <?php include __DIR__ . '/tab-memories.php'; ?>
        <?php include __DIR__ . '/tab-jobs.php'; ?>
        <?php include __DIR__ . '/tab-uploads.php'; ?>
        <?php include __DIR__ . '/tab-emails.php'; ?>
    </div>
    <footer class="sidebar-footer">
        <div class="ai-status" data-ai-status data-state="checking" role="status" aria-live="polite">
            <div class="ai-status-heading"><span class="status-dot" aria-hidden="true"></span><span data-ai-label>Checking AI…</span></div>
            <p data-ai-detail>Checking AI availability…</p>
            <button type="button" class="ui-button" data-return-to-task hidden>View task</button>
        </div>
        <a href="#settings-modal" uk-toggle class="sidebar-settings" aria-label="Settings">
            <uk-icon icon="settings" class="w-4 h-4" aria-hidden="true"></uk-icon><span>Settings</span>
        </a>
        <details class="system-health">
            <summary><uk-icon icon="activity" class="w-4 h-4" aria-hidden="true"></uk-icon><span>System health</span></summary>
            <dl>
                <div><dt>Database</dt><dd><?php echo $status->database ? 'Online' : 'Offline'; ?></dd></div>
                <div><dt>Redis</dt><dd><?php echo $status->redis ? 'Online' : 'Offline'; ?></dd></div>
                <div><dt><?php echo htmlspecialchars($status->model_name ?: 'AI service'); ?></dt><dd><?php echo $status->ai ? 'Online' : 'Offline'; ?></dd></div>
            </dl>
            <form method="POST" action="index.php" data-confirm="Delete all conversations and their messages? This cannot be undone.">
                <input type="hidden" name="clear_all" value="1">
                <button type="submit" class="ui-button ui-button--danger">Clear all history</button>
            </form>
        </details>
    </footer>
</aside>
