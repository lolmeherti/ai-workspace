<?php
// Retrieve real-time metrics safely from the database wrapper
$totalFilesCount = 0;
$latestFileName = 'None';

if ($db) {
    try {
        $fileStats = $db->query("SELECT COUNT(*) as total FROM uploaded_files");
        $totalFilesCount = (int)($fileStats[0]['total'] ?? 0);
        
        $latestFile = $db->query("SELECT original_name FROM uploaded_files ORDER BY uploaded_at DESC LIMIT 1");
        if (!empty($latestFile)) {
            $latestFileName = $latestFile[0]['original_name'];
        }
    } catch (\Exception $e) {
        // Fallback gracefully in case of any database read issues
    }
}
?>

<div id="panel-uploads" class="hidden flex flex-col h-full overflow-y-auto p-4 space-y-4 select-none animate-fade-in">
    
    <!-- Active Status Card -->
    <div class="bg-[#091124]/90 border border-cyan-500/20 rounded-xl p-4 shadow-[0_0_12px_rgba(6,182,212,0.15)] space-y-3">
        <div class="flex items-center gap-3">
            <span class="flex items-center justify-center shrink-0 w-8 h-8 bg-slate-950/80 rounded border border-cyan-500/20 text-cyan-400">
                <uk-icon icon="folder" class="w-4 h-4"></uk-icon>
            </span>
            <div>
                <h3 class="text-xs font-bold text-slate-200 tracking-wide uppercase">Uploads Gallery</h3>
                <p class="text-[10px] text-cyan-500/70 italic uppercase tracking-wider">Active Workspace View</p>
            </div>
        </div>
        
        <p class="text-xs text-slate-300 leading-relaxed font-normal">
            You are currently browsing your files on disk. The central control pane has switched to display your **Uploads Grid**.
        </p>
    </div>

    <!-- Live Statistics Container -->
    <div class="bg-[#0f172a] border border-slate-800 rounded-lg overflow-hidden text-xs">
        <div class="flex items-center justify-between p-3 border-b border-slate-800/80 bg-[#0d1321]/80 text-slate-300 font-semibold">
            <div class="flex items-center gap-2">
                <uk-icon icon="activity" class="w-3.5 h-3.5 text-cyan-400"></uk-icon>
                Storage Statistics
            </div>
        </div>
        <div class="p-3 space-y-2.5 leading-relaxed text-slate-300 bg-[#0b1120]">
            <div class="flex justify-between items-center">
                <span>Total Files</span>
                <span class="text-cyan-400 font-bold" id="sidebar-total-files"><?php echo $totalFilesCount; ?></span>
            </div>
            <div class="flex justify-between items-center">
                <span>Latest File</span>
                <span class="text-slate-400 font-medium truncate max-w-[130px]" title="<?php echo htmlspecialchars($latestFileName); ?>" id="sidebar-latest-file">
                    <?php echo htmlspecialchars($latestFileName); ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Guidance Notes -->
    <div class="bg-slate-900/40 border border-slate-800/60 rounded-lg p-3 space-y-2">
        <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Gallery Actions</h4>
        <ul class="text-[11px] text-slate-400 leading-relaxed list-disc list-inside space-y-1">
            <li>Click a card to toggle file selection.</li>
            <li>Hover on cards for fast file actions.</li>
            <li>Use the floating bottom bar to batch append or delete files.</li>
        </ul>
    </div>

</div>