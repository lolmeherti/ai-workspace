<dialog id="memory-consolidation-dialog" class="ui-dialog memory-consolidation-dialog" aria-labelledby="memory-consolidation-title">
    <header class="workspace-dialog-header">
        <div><h2 id="memory-consolidation-title">Consolidate memories</h2><p>Clean up your saved facts and preferences.</p></div>
        <button type="button" class="ui-icon-button" data-memory-consolidation-close aria-label="Close memory consolidation">×</button>
    </header>
    <div class="workspace-dialog-body">
        <p>The AI will review up to 200 recent memories, combine overlapping entries, and use the current conversation for context.</p>
        <p>Changes are saved directly to your memories. You can review and edit the resulting list afterwards.</p>
        <div id="memory-consolidation-status" aria-live="polite"></div>
    </div>
    <footer class="workspace-dialog-footer">
        <button type="button" class="ui-button" data-memory-consolidation-close>Close</button>
        <button type="button" class="ui-button hidden" id="memory-consolidation-reload">Reload memories</button>
        <button type="button" class="ui-button ui-button--primary" id="memory-consolidation-start" data-ai-action>Consolidate memories</button>
    </footer>
</dialog>
