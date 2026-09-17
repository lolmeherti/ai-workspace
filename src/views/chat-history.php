                <?php if (empty($history)): ?>
                    <div class="flex flex-col items-center justify-center text-center h-full py-20 opacity-80" id="empty-state">
                        <div class="w-20 h-20 mb-6 rounded-full bg-gradient-to-tr from-cyan-500/20 to-blue-500/20 flex items-center justify-center border border-cyan-500/30 shadow-[0_0_30px_rgba(6,182,212,0.15)]">
                            <uk-icon icon="bot" class="w-10 h-10 text-cyan-400"></uk-icon>
                        </div>
                        <h3 class="text-2xl font-bold tracking-tight text-white mb-2">How can I assist you today?</h3>
                        <p class="text-sm text-slate-400 max-w-sm">Enter a prompt, ask a question, or attach a document/image to start the conversation.</p>
                    </div>
                <?php else: ?>
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
                                <button class="text-slate-500 hover:text-cyan-400 p-0.5 rounded transition-colors duration-150 cursor-pointer flex items-center justify-center animate-fade-in"
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
                                    <?php if (!empty($sources)): ?>
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
                                                <span class="ml-auto text-xs px-1.5 py-0.5 rounded-full bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 font-mono"><?php echo count($sources); ?></span>
                                            </div>
                                            <div class="px-3 pb-3 flex flex-col gap-1.5">
                                                <?php foreach ($sources as $s): ?>
                                                    <?php
                                                    $srcUrl = $s['url'] ?? '';
                                                    $srcDomain = $s['domain'] ?? '';
                                                    $srcTitle = $s['title'] ?? '';
                                                    if ($srcTitle === '') {
                                                        $srcTitle = $srcDomain !== '' ? $srcDomain : $srcUrl;
                                                    }
                                                    ?>
                                                    <a href="<?php echo htmlspecialchars($srcUrl); ?>" target="_blank" rel="noopener noreferrer" class="group relative flex items-center gap-3 px-3 py-2 rounded-lg border border-slate-700/40 bg-slate-900/30 hover:border-cyan-500/30 hover:bg-cyan-500/5 hover:shadow-[0_0_16px_rgba(6,182,212,0.10)] transition-all duration-200">
                                                        <span class="flex items-center justify-center w-7 h-7 shrink-0 rounded-md bg-slate-800/60 border border-slate-700/50 text-cyan-400 group-hover:border-cyan-500/40 group-hover:text-cyan-300 group-hover:shadow-[0_0_12px_rgba(6,182,212,0.25)] transition-all">
                                                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                                                        </span>
                                                        <span class="flex flex-col min-w-0 flex-1">
                                                            <span class="text-xs text-slate-300 truncate group-hover:text-cyan-200 transition-colors"><?php echo htmlspecialchars($srcTitle); ?></span>
                                                            <?php if ($srcDomain !== '' && $srcDomain !== $srcTitle): ?>
                                                                <span class="text-xs text-slate-500 truncate font-mono group-hover:text-slate-400 transition-colors"><?php echo htmlspecialchars($srcDomain); ?></span>
                                                            <?php endif; ?>
                                                        </span>
                                                        <span class="shrink-0 text-slate-600 group-hover:text-cyan-400 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 transition-all">
                                                            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                                        </span>
                                                    </a>
                                                <?php endforeach; ?>
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
                                    $pmSummary = implode(' · ', $pmParts);
                                    $pmChain = implode(' → ', array_map(fn($c) => $pmLabels[$c['purpose'] ?? ''] ?? ($c['purpose'] ?? '?'), $pmCalls));
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
                                            <div class="text-xs text-slate-500 font-mono py-1.5"><?php echo htmlspecialchars($pmChain); ?></div>
                                            <table class="w-full text-xs font-mono text-slate-400">
                                                <thead><tr class="text-slate-500 text-left">
                                                    <th class="py-1 pr-2 font-normal">call</th><th class="py-1 pr-2 font-normal">time</th><th class="py-1 pr-2 font-normal">prefill</th><th class="py-1 pr-2 font-normal">think</th><th class="py-1 font-normal">text</th>
                                                </tr></thead>
                                                <tbody>
                                                <?php foreach ($pmCalls as $pmC): ?>
                                                    <?php
                                                    $pmLabel = $pmLabels[$pmC['purpose'] ?? ''] ?? ($pmC['purpose'] ?? '?');
                                                    $pmPrefill = ($pmC['prompt_ms'] ?? 0) > 0 ? (int)round($pmC['prompt_ms']) . 'ms · ' . ($pmC['prompt_n'] ?? 0) . ' tok' . (($pmC['cache_n'] ?? 0) > 0 ? ' · ' . $pmC['cache_n'] . ' cached' : '') : '—';
                                                    $pmThink = ($pmC['reasoning_ms'] ?? 0) > 0 ? (int)round($pmC['reasoning_ms']) . 'ms · ' . ($pmC['reasoning_tok'] ?? 0) . ' tok' : '—';
                                                    $pmText = ($pmC['content_ms'] ?? 0) > 0 ? (int)round($pmC['content_ms']) . 'ms · ' . ($pmC['content_tok'] ?? 0) . ' tok' : '—';
                                                    ?>
                                                    <tr class="border-t border-slate-800/40">
                                                        <td class="py-1 pr-2"><?php echo htmlspecialchars($pmLabel); ?></td>
                                                        <td class="py-1 pr-2"><?php echo (int)round($pmC['elapsed_ms'] ?? 0); ?>ms</td>
                                                        <td class="py-1 pr-2"><?php echo htmlspecialchars($pmPrefill); ?></td>
                                                        <td class="py-1 pr-2"><?php echo htmlspecialchars($pmThink); ?></td>
                                                        <td class="py-1"><?php echo htmlspecialchars($pmText); ?></td>
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
                                        <button type="button" class="text-xs text-slate-500 hover:text-cyan-400 flex items-center gap-1 transition-colors duration-150 cursor-pointer bg-transparent border-none p-0.5 animate-fade-in flex items-center gap-1"
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
