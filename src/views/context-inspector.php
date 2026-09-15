<aside id="context-data-panel" class="context-inspector" aria-label="Context Data" hidden>
    <header class="inspector-header">
        <div><h3>Context Data</h3><p class="ui-muted">Original evidence and extracted facts</p></div>
        <button type="button" id="context-expand" class="ui-icon-button" aria-label="Expand reading area" title="Expand reading area">↔</button>
        <button type="button" id="context-close" class="ui-icon-button" aria-label="Close Context Data">×</button>
    </header>
    <div id="context-data-items" class="context-list">
        <?php include __DIR__ . '/context-items.php'; ?>
    </div>
    <div id="context-detail-host" class="context-detail-host" hidden></div>
</aside>
