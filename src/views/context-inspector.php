<aside id="context-data-panel" class="context-inspector" data-purpose="context-drawer" aria-label="Context Data" hidden>
    <header class="inspector-header">
        <div><h3>Context Data</h3><p class="ui-muted">Original evidence and extracted facts</p></div>
        <button type="button" id="context-expand" class="ui-icon-button" aria-label="Expand reading area" title="Expand reading area" aria-pressed="false"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5M3 3l6 6m12-6-6 6M3 21l6-6m12 6-6-6"/></svg></button>
        <button type="button" id="context-close" class="ui-icon-button" aria-label="Close Context Data"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
    </header>
    <div id="context-data-items" class="context-list">
        <?php include __DIR__ . '/context-items.php'; ?>
    </div>
    <div id="context-detail-host" class="context-detail-host" hidden></div>
</aside>
