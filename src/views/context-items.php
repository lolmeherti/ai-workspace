    <?php
    $contextItems = [];
    $totalSaved = 0;
    foreach (($history ?? []) as $msg) {
        if (($msg['message_type'] ?? '') !== 'data_fetching') {
            continue;
        }
        $sourceCount = 0;
        if (!empty($msg['source_map'])) {
            $decoded = json_decode($msg['source_map'], true);
            if (is_array($decoded)) {
                $sourceCount = count($decoded);
            }
        }
        $rawEvicted = (int)($msg['raw_evicted'] ?? 0) === 1;
        $hasAtoms = !empty($msg['atomic_context']);
        $toolName = trim($msg['tool_name'] ?? '');
        $queryText = trim($msg['search_query'] ?? '');
        $label = $queryText !== '' ? $queryText : ($toolName !== '' ? $toolName : 'Context Data');
        $tokens = (int)($msg['token_estimate'] ?? 0);
        $atomTokens = (int)($msg['atomic_tokens'] ?? 0);

        if ($rawEvicted) {
            $state = $hasAtoms ? 'atomized' : 'evicted';
        } else {
            $state = $hasAtoms ? 'raw_atoms' : 'raw';
        }
        $badgeMap = [
            'raw' => ['Full evidence', 'bg-cyan-500/10 border-cyan-500/20 text-cyan-400'],
            'raw_atoms' => ['Evidence + key facts', 'bg-sky-500/10 border-sky-500/20 text-sky-400'],
            'atomized' => ['Key facts only', 'bg-violet-500/10 border-violet-500/20 text-violet-400'],
            'evicted' => ['Excluded', 'bg-rose-500/10 border-rose-500/20 text-rose-400'],
        ];
        $badgeText = $badgeMap[$state][0];
        $badgeCls = $badgeMap[$state][1];

        $saved = ($state === 'atomized') ? max(0, $tokens - $atomTokens) : 0;
        $totalSaved += $saved;

        $metaParts = [];
        if ($toolName !== '') {
            $metaParts[] = $toolName;
        }
        if ($sourceCount > 0) {
            $metaParts[] = $sourceCount . ' source' . ($sourceCount === 1 ? '' : 's');
        }

        $contextItems[] = [
            'id' => (int)$msg['id'],
            'state' => $state,
            'label' => $label,
            'meta' => implode(' · ', $metaParts),
            'tokens' => $tokens,
            'atomTokens' => $atomTokens,
            'saved' => $saved,
            'badgeText' => $badgeText,
            'badgeCls' => $badgeCls,
        ];
    }

    $fmt = static fn(int $n): string => number_format($n);
    $savedIcon = static fn(string $cls): string => '<svg class="' . $cls . ' text-emerald-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14m0 0l-6-6m6 6l6-6"/></svg>';
    ?>
            <?php if ($totalSaved > 0): ?>
                <div id="context-savings-summary" class="flex items-center gap-3 mb-3 px-3 py-2.5 rounded-lg bg-emerald-500/10 border border-emerald-500/25">
                    <?php echo $savedIcon('w-4 h-4'); ?>
                    <div class="min-w-0">
                        <div class="text-emerald-300 font-bold text-xl leading-tight"><span class="tabular-nums"><?php echo $fmt($totalSaved); ?></span> tokens saved</div>
                        <div class="text-emerald-200/60 text-xs">Key facts keep only the essentials in context</div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (empty($contextItems)): ?>
                <p id="context-data-empty" class="text-xs text-slate-500 text-center py-4">No retained context data yet.</p>
            <?php else: ?>
                <?php foreach ($contextItems as $item): ?>
                    <div class="context-item flex items-center gap-3 px-3 py-2 rounded-lg border border-slate-700/40 bg-slate-900/30" data-id="<?php echo $item['id']; ?>" data-state="<?php echo $item['state']; ?>" data-saved="<?php echo $item['saved']; ?>">
                        <div class="flex flex-col min-w-0 flex-1">
                            <span class="text-xs text-slate-300 truncate"><?php echo htmlspecialchars($item['label']); ?></span>
                            <span class="context-meta text-xs text-slate-500 font-mono"><?php echo htmlspecialchars($item['meta']); ?></span>
                            <?php if ($item['state'] === 'atomized'): ?>
                                <div class="context-tokens mt-2 flex items-center gap-2 rounded-md bg-emerald-500/10 border border-emerald-500/20 px-2.5 py-1.5">
                                    <?php echo $savedIcon('w-3.5 h-3.5'); ?>
                                    <span class="text-emerald-300 text-xl font-bold tabular-nums leading-none"><?php echo $fmt($item['saved']); ?></span>
                                    <span class="text-emerald-200/90 text-xs font-medium">tokens saved</span>
                                    <span class="ml-auto text-emerald-400/70 text-xs font-mono tabular-nums"><?php echo $fmt($item['tokens']); ?> &rarr; <?php echo $fmt($item['atomTokens']); ?></span>
                                </div>
                            <?php elseif ($item['state'] === 'raw_atoms'): ?>
                                <div class="context-tokens mt-2 flex items-center gap-2 text-xs">
                                    <span class="text-slate-300 font-semibold tabular-nums">~<?php echo $fmt($item['tokens'] + $item['atomTokens']); ?></span>
                                    <span class="text-slate-500">tokens (evidence + facts)</span>
                                </div>
                            <?php elseif ($item['state'] === 'evicted'): ?>
                                <div class="context-tokens mt-2 flex items-center gap-2 text-xs">
                                    <span class="text-rose-400/80 font-semibold tabular-nums">0</span>
                                    <span class="text-slate-500">tokens &mdash; excluded</span>
                                </div>
                            <?php else: ?>
                                <div class="context-tokens mt-2 flex items-center gap-2 text-xs">
                                    <span class="text-slate-300 font-semibold tabular-nums">~<?php echo $fmt($item['tokens']); ?></span>
                                    <span class="text-slate-500">tokens in context</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <span class="context-badge text-xs px-1.5 py-0.5 rounded-full border <?php echo $item['badgeCls']; ?>"<?php if ($item['state'] === 'evicted'): ?> title="This raw data is not part of the chat anymore. Restore loads the full data back in."<?php endif; ?>><?php echo $item['badgeText']; ?></span>
                        <div class="context-btns">
                            <button type="button" data-action="view" data-id="<?php echo $item['id']; ?>" class="ui-button">View</button>
                            <button type="button" data-action="edit_raw" data-id="<?php echo $item['id']; ?>" class="ui-button ui-button--secondary">Edit evidence</button>
                            <button type="button" data-action="<?php echo in_array($item['state'], ['raw_atoms', 'atomized']) ? 'reatomize' : 'atomize'; ?>" data-id="<?php echo $item['id']; ?>" class="ui-button ui-button--primary"><?php echo in_array($item['state'], ['raw_atoms', 'atomized']) ? 'Extract again' : 'Extract key facts'; ?></button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
