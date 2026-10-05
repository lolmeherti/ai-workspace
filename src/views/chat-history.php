                <?php if (empty($history)): ?>
                    <div class="flex flex-col items-center justify-center text-center h-full py-20 opacity-80" id="empty-state">
                        <div class="w-20 h-20 mb-6 rounded-full bg-gradient-to-tr from-cyan-500/20 to-blue-500/20 flex items-center justify-center border border-cyan-500/30 shadow-[0_0_30px_rgba(6,182,212,0.15)]">
                            <uk-icon icon="bot" class="w-10 h-10 text-cyan-400"></uk-icon>
                        </div>
                        <h3 class="text-2xl font-bold tracking-tight text-white mb-2">How can I assist you today?</h3>
                        <p class="text-sm text-slate-400 max-w-sm">Enter a prompt, ask a question, or attach a document/image to start the conversation.</p>
                    </div>
                <?php else: ?>
                    <?php
                    // Evidence per assistant turn: the contiguous run of data_fetching rows written just before
                    // it. Rows saved before failed_sources was stored have no list of their own, and the
                    // assistant row carries only the answer — never the evidence — so deriving failures from
                    // that text alone reports every fetched source as unreadable.
                    $turnEvidenceByRow = [];
                    $evidenceBuf = '';
                    foreach ($history as $hRow) {
                        if (($hRow['message_type'] ?? '') === 'data_fetching') {
                            $evidenceBuf .= (string) $hRow['message'] . "\n";
                            continue;
                        }
                        if (($hRow['role'] ?? '') === 'assistant') {
                            $turnEvidenceByRow[(int) $hRow['id']] = $evidenceBuf;
                        }
                        $evidenceBuf = '';
                    }

                    // Source numbers for citation badges, keyed by the id's own number (S7 -> 7) so a restored
                    // answer badges exactly what the live stream did. Collected across the session's rows: an
                    // answer may cite a source that was read several turns ago.
                    $refMap = [];
                    foreach ($history as $hRow) {
                        if (($hRow['message_type'] ?? '') !== 'data_fetching' || empty($hRow['source_map'])) {
                            continue;
                        }
                        $rowMap = json_decode((string) $hRow['source_map'], true);
                        if (!is_array($rowMap)) {
                            continue;
                        }
                        foreach ($rowMap as $sid => $meta) {
                            if (preg_match('/^S(\d+)$/', (string) $sid, $sm)) {
                                $refMap[(int) $sm[1]] = [
                                    'url'    => (string) ($meta['url'] ?? ''),
                                    'title'  => (string) ($meta['title'] ?? ''),
                                    'domain' => (string) ($meta['domain'] ?? ''),
                                ];
                            }
                        }
                    }
                    ?>
                    <?php if (!empty($refMap)): ?>
                        <?php // JSON_HEX_TAG keeps a page title from closing this script tag. ?>
                        <script type="application/json" id="ref-map"><?php echo json_encode($refMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
                    <?php endif; ?>
                    <?php foreach ($history as $msg): ?>
                        <?php
                        $msgType = $msg['message_type'] ?? 'text';
                        if ($msgType === 'data_fetching'):
                            // Data fetching results are internal tool output — the model
                            // already summarized them in its response. Skip rendering.
                            continue;
                        endif;
                        if (($msg['role'] ?? '') === 'system') continue;
                        ?>
                        <div class="flex flex-col w-full max-w-[92%] mx-auto space-y-1 chat-message-container <?php echo $msg['role'] === 'user' ? 'items-end' : 'items-start'; ?>">

                            <div class="flex items-center gap-2 <?php echo $msg['role'] === 'user' ? 'flex-row-reverse mr-1' : 'ml-1'; ?>">
                                <span class="text-xs text-slate-500 font-semibold normal-case tracking-normal flex items-center gap-2">
                                    <?php echo $msg['role'] === 'user' ? 'You' : htmlspecialchars($msg['model'] ?? $msg['model_name'] ?? \App\Config::get('LLM_MODEL_NAME', 'Assistant')); ?>
                                    <?php if ($msg['role'] !== 'user'): ?>
                                        <?php if (!empty($msg['search_query'])): ?>
                                            <span class="text-[0.65rem] px-2 py-0.5 rounded-full bg-blue-500/20 text-blue-400 border border-blue-500/30 flex items-center gap-1 normal-case tracking-normal shadow-sm">
                                                <uk-icon icon="globe" class="w-3.5 h-3.5"></uk-icon> Web Search
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </span>
                                <button class="copy-affordance animate-fade-in"
                                        onclick="copyToClipboard(this)"
                                        title="Copy message">
                                    <uk-icon icon="copy" class="w-3.5 h-3.5"></uk-icon>
                                </button>
                            </div>

                            <div class="<?php echo $msg['role'] === 'user' ? 'chat-user rounded-2xl rounded-tr-sm' : 'chat-assistant rounded-2xl rounded-tl-sm markdown-content flex flex-col items-stretch'; ?> px-5 py-4 text-[0.95rem] leading-relaxed max-w-[85%]"
                                 data-raw="<?php echo htmlspecialchars($msg['message']); ?>">
                                <?php if (!empty($msg['image_path'])): ?>
                                    <?php
                                    $ext = strtolower(pathinfo($msg['image_path'], PATHINFO_EXTENSION));
                                    if (in_array($ext, ["png", "jpg", "jpeg", "gif", "webp"])):
                                    ?>
                                        <img src="<?php echo htmlspecialchars($msg['image_path']); ?>" class="max-w-xs rounded-lg mb-3 border border-white/20 shadow-md block" alt="Uploaded image">
                                    <?php else: ?>
                                        <div class="flex items-center gap-2 bg-slate-900/60 border border-slate-800 p-3 rounded-lg max-w-xs mb-3">
                                            <uk-icon icon="file-text" class="w-6 h-6 text-cyan-400"></uk-icon>
                                            <span class="text-xs text-slate-300 font-medium truncate"><?php echo htmlspecialchars(basename($msg['image_path'])); ?></span>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if ($msg['role'] === 'assistant'): ?>
                                    <?php $briefingCards = !empty($msg['briefing_cards']) ? $msg['briefing_cards'] : null; ?>
                                    <div class="markdown-rendered" data-markdown="<?php echo htmlspecialchars($msg['message']); ?>"<?php if ($briefingCards !== null): ?> data-briefing-cards="<?php echo htmlspecialchars($briefingCards, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>></div>
                                    <?php $sources = !empty($msg['source_map']) ? json_decode($msg['source_map'], true) : null; ?>
                                    <?php
                                    // Sources this turn could not use: whatever was recorded at the time, plus —
                                    // for rows written before the column existed — the ones that carry no evidence
                                    // block (fetched, but nothing usable reached the answer).
                                    $failedSources = !empty($msg['failed_sources']) ? json_decode($msg['failed_sources'], true) : [];
                                    if (empty($failedSources) && !empty($sources)) {
                                        $failedSources = \App\Search\SourceFailures::unused($sources, $turnEvidenceByRow[(int) $msg['id']] ?? '');
                                    }
                                    // The map is everything the turn fetched, so a page that was read and
                                    // contributed nothing would otherwise be listed as successful and failed.
                                    // It stays one row, under Failed sources.
                                    $usedSources = !empty($sources) ? \App\Search\SourceFailures::used($sources, (array) $failedSources) : [];
                                    // Rows written before the failure list carried a title still get one: the page's
                                    // title is on this row's own source map, and without it several pages on one
                                    // domain render as several identical lines.
                                    if (!empty($failedSources) && !empty($sources)) {
                                        $titleByUrl = [];
                                        foreach ($sources as $s) {
                                            $sUrl = (string) ($s['url'] ?? '');
                                            if ($sUrl !== '') { $titleByUrl[$sUrl] = (string) ($s['title'] ?? ''); }
                                        }
                                        foreach ($failedSources as &$failedRow) {
                                            $fUrl = (string) ($failedRow['url'] ?? '');
                                            if ((string) ($failedRow['title'] ?? '') === '' && isset($titleByUrl[$fUrl])) {
                                                $failedRow['title'] = $titleByUrl[$fUrl];
                                            }
                                        }
                                        unset($failedRow);
                                    }
                                    // Several pages on one site are one category, not several sources: group the
                                    // pages by site so the panel shows each site once with its endpoints inside.
                                    $usedSourceGroups = \App\Search\SourceFailures::groupBySite($usedSources);
                                    ?>
                                    <?php if (!empty($sources) || !empty($failedSources)): ?>
                                        <div class="sources-panel relative w-full mt-4 overflow-hidden rounded-xl border border-cyan-500/20 bg-gradient-to-b from-[#0d1321]/90 to-[#0d1321]/70 backdrop-blur-sm shadow-[0_0_25px_rgba(6,182,212,0.08),inset_0_1px_0_rgba(6,182,212,0.06)]">
                                            <span class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-cyan-400/60 to-transparent"></span>
                                            <div class="flex items-center gap-2 px-4 pt-3 pb-2">
                                                <span class="relative flex items-center justify-center w-6 h-6 rounded-md bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 shadow-[0_0_10px_rgba(6,182,212,0.12)]">
                                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                                                </span>
                                                <span class="text-xs font-semibold tracking-normal normal-case bg-gradient-to-r from-cyan-300 via-blue-400 to-emerald-400 bg-clip-text text-transparent">Sources</span>
                                                <span class="relative flex h-1.5 w-1.5">
                                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-cyan-400 opacity-75"></span>
                                                    <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-cyan-400"></span>
                                                </span>
                                                <span class="ml-auto text-xs px-1.5 py-0.5 rounded-full bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 font-mono"><?php echo count($usedSources); ?></span>
                                                <?php if (!empty($failedSources)): ?>
                                                    <span class="source-unread-count text-xs px-1.5 py-0.5 rounded-full border font-mono" title="<?php echo count($failedSources); ?> source(s) could not be used"><?php echo count($failedSources); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="px-3 pb-3 flex flex-col gap-1.5">
                                                <?php if (!empty($failedSources) && !empty($usedSources)): ?>
                                                    <div class="px-1 pt-1 pb-0.5 text-[10px] uppercase tracking-wide text-slate-500">Successful sources</div>
                                                <?php endif; ?>
                                                <?php foreach ($usedSourceGroups as $siteGroup): ?>
                                                    <?php
                                                    // Every site heads its block, even when it answered once: a header on some
                                                    // groups and not others made the list read as two things stacked together.
                                                    // The count only earns its place above one page. A source with no site
                                                    // (a memory, an attached file) has nothing to head with and stays bare.
                                                    $siteName = (string) ($siteGroup['site'] ?? '');
                                                    $grouped = $siteName !== '';
                                                    ?>
                                                    <?php if ($grouped): ?>
                                                        <div class="source-site-head">
                                                            <span><?php echo htmlspecialchars($siteName); ?></span>
                                                            <?php if (count($siteGroup['pages']) > 1): ?><span class="source-site-count"><?php echo count($siteGroup['pages']); ?></span><?php endif; ?>
                                                        </div>
                                                        <div class="source-site-pages">
                                                    <?php endif; ?>
                                                    <?php foreach ($siteGroup['pages'] as $s): ?>
                                                        <?php
                                                        $srcUrl = $s['url'] ?? '';
                                                        $srcDomain = $s['domain'] ?? '';
                                                        $srcTitle = $s['title'] ?? '';
                                                        if ($srcTitle === '') {
                                                            $srcTitle = $srcDomain !== '' ? $srcDomain : $srcUrl;
                                                        }
                                                        // Inside a site group the endpoint tells the pages apart — the site is the heading.
                                                        $srcEndpoint = $grouped ? \App\Search\UrlIndex::endpoint($srcUrl) : null;
                                                        ?>
                                                        <a href="<?php echo htmlspecialchars($srcUrl); ?>" target="_blank" rel="noopener noreferrer" class="group relative flex items-center gap-3 px-3 py-2 rounded-lg border border-slate-700/40 bg-slate-900/30 hover:border-cyan-500/30 hover:bg-cyan-500/5 hover:shadow-[0_0_16px_rgba(6,182,212,0.10)] transition-all duration-200">
                                                            <span class="flex items-center justify-center w-7 h-7 shrink-0 rounded-md bg-slate-800/60 border border-slate-700/50 text-cyan-400 group-hover:border-cyan-500/40 group-hover:text-cyan-300 group-hover:shadow-[0_0_12px_rgba(6,182,212,0.25)] transition-all">
                                                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                                                            </span>
                                                            <span class="flex flex-col min-w-0 flex-1">
                                                                <span class="text-xs text-slate-300 truncate group-hover:text-cyan-200 transition-colors"><?php echo htmlspecialchars($srcTitle); ?></span>
                                                                <?php if ($srcEndpoint): ?>
                                                                    <span class="source-endpoint text-xs truncate"><?php echo htmlspecialchars($srcEndpoint); ?></span>
                                                                <?php elseif (!$grouped && $srcDomain !== '' && $srcDomain !== $srcTitle): ?>
                                                                    <span class="text-xs text-slate-500 truncate font-mono group-hover:text-slate-400 transition-colors"><?php echo htmlspecialchars($srcDomain); ?></span>
                                                                <?php endif; ?>
                                                            </span>
                                                            <span class="shrink-0 text-slate-600 group-hover:text-cyan-400 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 transition-all">
                                                                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                                            </span>
                                                        </a>
                                                    <?php endforeach; ?>
                                                    <?php if ($grouped): ?></div><?php endif; ?>
                                                <?php endforeach; ?>
                                                <?php if (!empty($failedSources)): ?>
                                                    <div class="px-1 pt-1 pb-0.5 text-[10px] uppercase tracking-wide text-slate-500">Failed sources</div>
                                                    <?php foreach ($failedSources as $f): ?>
                                                        <?php
                                                        $fUrl = (string) ($f['url'] ?? '');
                                                        $fFix = !empty($f['solvable']);
                                                        // Two weights of one notice (see .source-unread in styles.css): a
                                                        // page the browser can clear for the user takes the app's warm
                                                        // accent, everything else stays recessed — a red block would read as
                                                        // an error, and for most of these there is nothing to act on.
                                                        ?>
                                                        <<?php echo $fUrl !== '' ? 'a href="' . htmlspecialchars($fUrl) . '" target="_blank" rel="noopener noreferrer"' : 'div'; ?> title="<?php echo htmlspecialchars(trim((string) ($f['tooltip'] ?? '') . ($fUrl !== '' ? ' — ' . $fUrl : ''))); ?>" class="source-unread<?php echo $fFix ? ' source-unread--fixable' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg border transition-all duration-200">
                                                            <span class="source-unread-icon flex items-center justify-center w-7 h-7 shrink-0 rounded-md border">
                                                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                                            </span>
                                                            <span class="flex flex-col min-w-0 flex-1">
                                                                <span class="source-unread-label text-xs truncate transition-colors"><?php echo htmlspecialchars((string) ($f['label'] ?? 'could not be read')); ?></span>
                                                                <?php // Which page this was. Several pages on one domain is normal, so the domain alone would make them indistinguishable rows. ?>
                                                                <span class="flex items-center gap-1.5 min-w-0">
                                                                    <?php if ((string) ($f['title'] ?? '') !== ''): ?>
                                                                        <span class="source-unread-page text-xs truncate transition-colors"><?php echo htmlspecialchars((string) $f['title']); ?></span>
                                                                    <?php endif; ?>
                                                                    <span class="source-unread-host text-xs shrink-0 font-mono transition-colors"><?php echo htmlspecialchars((string) (($f['domain'] ?? '') !== '' ? $f['domain'] : $fUrl)); ?></span>
                                                                </span>
                                                            </span>
                                                            <?php if ($fFix): ?>
                                                                <span class="source-unread-hint shrink-0 text-[10px] font-mono">you can fix this</span>
                                                            <?php endif; ?>
                                                        </<?php echo $fUrl !== '' ? 'a' : 'div'; ?>>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php $perf = !empty($msg['perf_metrics']) ? json_decode($msg['perf_metrics'], true) : null; ?>
                                <?php if (!empty($perf['calls'])): ?>
                                    <?php
                                    $pmCalls = $perf['calls'];
                                    $pmLabels = ['firstpass' => 'first pass', 'answer' => 'answer', 'condenser' => 'condenser', 'tools' => 'tools'];
                                    $pmAc = null;
                                    foreach ($pmCalls as $pmC) {
                                        if (($pmC['purpose'] ?? '') === 'answer') { $pmAc = $pmC; break; }
                                    }
                                    if ($pmAc === null) {
                                        foreach ($pmCalls as $pmC) {
                                            if (($pmC['purpose'] ?? '') === 'firstpass') { $pmAc = $pmC; break; }
                                        }
                                    }
                                    if ($pmAc === null) { $pmAc = $pmCalls[count($pmCalls) - 1]; }
                                    // Reasoning as the backend applied it for a call: mode/effort plus the
                                    // field/value that went on the wire. Mirrors reasoningLabel() in
                                    // streamResponse.js so a reloaded turn reads exactly like a live one.
                                    $pmReasonOf = static function (array $perf, array $call): ?string {
                                        $r = $perf['reasoning'] ?? null;
                                        $hasCallInfo = isset($call['reasoning_mode']) || isset($call['reasoning_effort']) || isset($call['reasoning_field']);
                                        if ($r === null && !$hasCallInfo) { return null; }
                                        $mode = $call['reasoning_mode'] ?? ($r['mode'] ?? null);
                                        $effort = $call['reasoning_effort'] ?? ($r['effort'] ?? null);
                                        $label = $mode === 'instruct' ? 'off' : (($effort !== null && $effort !== '') ? $effort : 'default');
                                        if (!isset($call['reasoning_field'])) { return $label . ' → template default'; }
                                        $value = $call['reasoning_value'] ?? null;
                                        return $label . ' → ' . (($value !== null && $value !== '') ? $value : 'template default');
                                    };
                                    $pmParts = [count($pmCalls) . ' call' . (count($pmCalls) === 1 ? '' : 's')];
                                    if (isset($perf['total_ms'])) { $pmParts[] = number_format($perf['total_ms'] / 1000, 1) . 's'; }
                                    if (!empty($perf['ttft_ms'])) { $pmParts[] = 'TTFT ' . number_format($perf['ttft_ms'] / 1000, 1) . 's'; }
                                    if ($pmAc && ($pmAc['reasoning_ms'] ?? 0) > 0) { $pmParts[] = 'think ' . number_format($pmAc['reasoning_ms'] / 1000, 1) . 's'; }
                                    if ($pmAc) {
                                        $pmTps = 0;
                                        if (($pmAc['content_ms'] ?? 0) > 0 && ($pmAc['content_tok'] ?? 0) > 0) { $pmTps = ($pmAc['content_tok'] ?? 0) / (($pmAc['content_ms'] ?? 1) / 1000); }
                                        elseif (($pmAc['pred_tps'] ?? 0) > 0) { $pmTps = $pmAc['pred_tps']; }
                                        if ($pmTps > 0) { $pmParts[] = (int)round($pmTps) . ' tok/s'; }
                                        if (($pmAc['prompt_tokens'] ?? 0) > 0) { $pmParts[] = (int)round(($pmAc['cache_n'] ?? 0) / $pmAc['prompt_tokens'] * 100) . '% cached'; }
                                    }
                                    $pmReason = $pmAc ? $pmReasonOf($perf, $pmAc) : null;
                                    if ($pmReason !== null) { $pmParts[] = 'reason ' . $pmReason; }
                                    $pmSummary = implode(' · ', $pmParts);
                                    $pmCallReasons = array_map(fn($c) => $pmReasonOf($perf, $c), $pmCalls);
                                    $pmDistinct = array_values(array_unique(array_filter($pmCallReasons, fn($v) => $v !== null)));
                                    // Only spend a column on reasoning when the calls disagree: the summary
                                    // already states the turn's setting, so one value would just repeat.
                                    $pmShowReason = count($pmDistinct) > 1
                                        || (count($pmDistinct) === 1 && $pmDistinct[0] !== $pmReason);
                                    ?>
                                    <details class="metrics-section w-full mt-3 overflow-hidden rounded-lg border border-slate-700/40 bg-slate-900/40">
                                        <summary class="flex items-center justify-between gap-3 px-3 py-2 cursor-pointer select-none text-slate-300">
                                            <span class="flex items-center gap-2 text-xs font-semibold normal-case tracking-normal text-slate-400">
                                                <svg class="w-3.5 h-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                                                metrics
                                            </span>
                                            <span class="text-xs font-mono text-slate-400 truncate"><?php echo htmlspecialchars($pmSummary); ?></span>
                                        </summary>
                                        <div class="px-3 pb-3 border-t border-slate-800/60">
                                            <table class="metrics-table w-full text-xs font-mono text-slate-400">
                                                <thead><tr class="text-slate-500 text-left">
                                                    <th class="py-1 pr-2 font-normal">call</th><th class="py-1 pr-2 font-normal">time</th><th class="py-1 pr-2 font-normal">prefill</th><th class="py-1 pr-2 font-normal">think</th><th class="<?php echo $pmShowReason ? 'py-1 pr-2' : 'py-1'; ?> font-normal">text</th><?php if ($pmShowReason): ?><th class="py-1 font-normal">reason</th><?php endif; ?>
                                                </tr></thead>
                                                <tbody>
                                                <?php foreach ($pmCalls as $pmI => $pmC): ?>
                                                    <?php
                                                    $pmLabel = $pmLabels[$pmC['purpose'] ?? ''] ?? ($pmC['purpose'] ?? '?');
                                                    $pmPrefill = ($pmC['prompt_ms'] ?? 0) > 0 ? (int)round($pmC['prompt_ms']) . 'ms · ' . ($pmC['prompt_n'] ?? 0) . ' new' . (($pmC['cache_n'] ?? 0) > 0 ? ' · ' . $pmC['cache_n'] . ' cached' : '') : '—';
                                                    $pmThink = ($pmC['reasoning_ms'] ?? 0) > 0 ? (int)round($pmC['reasoning_ms']) . 'ms · ' . ($pmC['reasoning_tok'] ?? 0) . ' tok' : '—';
                                                    $pmText = ($pmC['content_ms'] ?? 0) > 0 ? (int)round($pmC['content_ms']) . 'ms · ' . ($pmC['content_tok'] ?? 0) . ' tok' : '—';
                                                    ?>
                                                    <tr class="border-t border-slate-800/40">
                                                        <td class="py-1 pr-2 whitespace-nowrap"><?php echo htmlspecialchars($pmLabel); ?></td>
                                                        <td class="py-1 pr-2 whitespace-nowrap"><?php echo (int)round($pmC['elapsed_ms'] ?? 0); ?>ms</td>
                                                        <td class="py-1 pr-2"><?php echo htmlspecialchars($pmPrefill); ?></td>
                                                        <td class="py-1 pr-2"><?php echo htmlspecialchars($pmThink); ?></td>
                                                        <td class="<?php echo $pmShowReason ? 'py-1 pr-2' : 'py-1'; ?>"><?php echo htmlspecialchars($pmText); ?></td>
                                                        <?php if ($pmShowReason): ?><td class="py-1"><?php echo htmlspecialchars($pmCallReasons[$pmI] ?? '—'); ?></td><?php endif; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </details>
                                <?php endif; ?>
                                <?php else: ?>
                                    <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                <?php endif; ?>

                                <?php if (strlen($msg['message']) > 300): ?>
                                    <div class="flex justify-end mt-4 pt-2 border-t border-slate-800/20 bottom-copy-container mt-auto">
                                        <button type="button" class="copy-affordance animate-fade-in"
                                                onclick="copyToClipboard(this)"
                                                title="Copy message">
                                            <uk-icon icon="copy" class="w-3 h-3"></uk-icon>
                                        </button>
                                    </div>
                                <?php endif; ?>

                                <?php if ($msg['role'] === 'assistant'): ?>
                                <?php
                                    $rating = $msg['rating'] ?? null;
                                    $ratingReason = (string)($msg['rating_reason'] ?? '');
                                    $hadTool = !empty($msg['had_tool_calls']);
                                ?>
                                <div class="reply-rating flex items-center gap-1 mt-2" data-message-id="<?php echo (int)$msg['id']; ?>" data-had-tool-calls="<?php echo $hadTool ? '1' : '0'; ?>" data-rating="<?php echo $rating === null ? '' : (int)$rating; ?>" data-reason="<?php echo htmlspecialchars($ratingReason); ?>">
                                    <button type="button" data-rate="1" title="Good reply" class="rate-btn w-7 h-7 flex items-center justify-center rounded-lg border border-slate-700 text-slate-500 hover:text-slate-200 hover:border-slate-500 transition-colors cursor-pointer bg-transparent <?php echo ($rating !== null && (int)$rating === 1) ? 'rate-active-up' : ''; ?>">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10v12M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/></svg>
                                    </button>
                                    <button type="button" data-rate="0" title="Bad reply" class="rate-btn w-7 h-7 flex items-center justify-center rounded-lg border border-slate-700 text-slate-500 hover:text-slate-200 hover:border-slate-500 transition-colors cursor-pointer bg-transparent <?php echo ($rating !== null && (int)$rating === 0) ? 'rate-active-down' : ''; ?>">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 2v12M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 6.5 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2.76a2 2 0 0 0-1.79 1.11L12 22a3.13 3.13 0 0 1-3-3.88Z"/></svg>
                                    </button>
                                    <div class="reason-menu hidden flex flex-wrap gap-1 mt-1.5">
                                        <?php foreach (\App\Actions\RateReplyAction::DOWNVOTE_REASONS as $rk => $rl): ?>
                                            <?php if (in_array($rk, \App\Actions\RateReplyAction::TOOL_TURN_REASONS, true) && !$hadTool) continue; ?>
                                            <button type="button" data-reason="<?php echo htmlspecialchars($rk); ?>" class="text-xs px-2 py-1 rounded-full border border-slate-700 bg-slate-800/60 text-slate-300 hover:border-slate-500 hover:text-slate-100 transition-colors cursor-pointer"><?php echo htmlspecialchars($rl); ?></button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
