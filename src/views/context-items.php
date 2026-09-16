    <?php
    $contextItems = [];
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

        $metaParts = [];
        if ($toolName !== '') {
            $metaParts[] = $toolName;
        }
        if ($sourceCount > 0) {
            $metaParts[] = $sourceCount . ' source' . ($sourceCount === 1 ? '' : 's');
        }
        $metaParts[] = 'raw ~' . $tokens;
        if ($hasAtoms) {
            $metaParts[] = 'atoms ~' . $atomTokens;
        }
        $contextItems[] = [
            'id' => (int)$msg['id'],
            'state' => $state,
            'label' => $label,
            'meta' => implode(' · ', $metaParts),
            'badgeText' => $badgeText,
            'badgeCls' => $badgeCls,
        ];
    }
    ?>
            <?php if (empty($contextItems)): ?>
                <p id="context-data-empty" class="text-xs text-slate-500 text-center py-4">No retained context data yet.</p>
            <?php else: ?>
                <?php foreach ($contextItems as $item): ?>
                    <div class="context-item flex items-center gap-3 px-3 py-2 rounded-lg border border-slate-700/40 bg-slate-900/30" data-id="<?php echo $item['id']; ?>" data-state="<?php echo $item['state']; ?>">
                        <div class="flex flex-col min-w-0 flex-1">
                            <span class="text-xs text-slate-300 truncate"><?php echo htmlspecialchars($item['label']); ?></span>
                            <span class="context-meta text-xs text-slate-500 font-mono"><?php echo htmlspecialchars($item['meta']); ?></span>
                        </div>
                        <span class="context-badge text-xs px-1.5 py-0.5 rounded-full border <?php echo $item['badgeCls']; ?>"<?php if ($item['state'] === 'evicted'): ?> title="This raw data is not part of the chat anymore. Restore loads the full data back in."<?php endif; ?>><?php echo $item['badgeText']; ?></span>
                        <div class="context-btns">
                            <button type="button" data-action="view" data-id="<?php echo $item['id']; ?>" class="ui-button">View</button>
                            <button type="button" data-action="edit_raw" data-id="<?php echo $item['id']; ?>" class="ui-button">Edit evidence</button>
                            <button type="button" data-action="<?php echo in_array($item['state'], ['raw_atoms', 'atomized']) ? 'reatomize' : 'atomize'; ?>" data-id="<?php echo $item['id']; ?>" class="ui-button"><?php echo in_array($item['state'], ['raw_atoms', 'atomized']) ? 'Extract again' : 'Extract key facts'; ?></button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
