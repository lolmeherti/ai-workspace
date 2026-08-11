<?php

namespace App;

use App\Database;
use App\AgentManager;
use App\Agents\MemoryExtractor;
use App\Agents\SemanticCacheEvaluator;
use App\Agents\ContextCondenser;
use App\Cache;
use App\Config;
use App\Scraper;
use App\Search;
use App\Search\CitationValidator;
use App\Services\FileAttachmentService;
use App\Services\PromptAssemblyService;
use App\Services\ToolExecutionService;
use App\Services\Tools\SearchWebTool;

class ChatManager
{
    private Database $db;
    private AgentManager $agent;
    private FileAttachmentService $fileAttachmentService;
    private PromptAssemblyService $promptAssemblyService;
    private ToolExecutionService $toolExecutionService;
    private string $uploadDir;

    private ?SemanticCacheEvaluator $cacheEvaluator;
    private ?ContextCondenser $contextCondenserService;

    public function __construct(
        Database $db, 
        AgentManager $agent, 
        ?MemoryExtractor $memoryExtractor = null,
        ?SemanticCacheEvaluator $cacheEvaluator = null,
        ?ContextCondenser $contextCondenser = null
    ) {
        $this->db = $db;
        $this->agent = $agent;
        $this->uploadDir = __DIR__ . '/../uploads/';
        $this->cacheEvaluator = $cacheEvaluator;
        $this->contextCondenserService = $contextCondenser;

        $this->fileAttachmentService = new FileAttachmentService($db, $agent, $this->uploadDir);
        $this->promptAssemblyService = new PromptAssemblyService($this->db, $this->uploadDir);
        $this->toolExecutionService = new ToolExecutionService($db, $agent, $this->uploadDir);
    }

    private function cleanMessagesArray(array $messages): array
    {
        foreach ($messages as $idx => $msg) {
            $role = $msg['role'] ?? '';
            $content = $msg['content'] ?? '';
            
            if ($idx > 0 && $role === 'system') {
                $messages[$idx]['role'] = 'user';
                $messages[$idx]['content'] = "[System Context / Tool Output]:\n" . $content;
            }
        }
        return $messages;
    }

    public function process(int $sessionId, string $query, ?array $imageFile, ?string $cacheAction = null, ?string $cacheKeyToUse = null, ?string $activeEditFile = null, ?callable $streamCallback = null): array
    {
        \App\ProgressWriter::init('/tmp');

        // Sentinel — confirms progress directory is writable
        if (\App\ProgressWriter::isReady()) {
            @unlink('/tmp/progress/' . $sessionId . '.jsonl');
            \App\ProgressWriter::write($sessionId, 'ready', 'Progress pipeline active', 'emerald');
        } else {
            error_log('ProgressWriter: directory not writable — /tmp/progress');
        }

        $emit = function(string $event, array $data = []) use ($streamCallback) {
            if ($streamCallback !== null) {
                $streamCallback($event, $data);
            }
        };

        $emit('status', ['text' => 'Initializing...']);

        $bypassWarning = (int)($_POST['bypass_warning'] ?? 0);
        if (empty($cacheAction)) {
            if (!$this->checkTokenThreshold($sessionId, (bool)$bypassWarning, $emit)) {
                return [
                    'status' => 'warning',
                    'message' => 'Token limit warning triggered'
                ];
            }
        }

        $this->ensureSessionExists($sessionId);

        // Parse active_tools from POST (set by frontend card)
        $activeToolsRaw = $_POST['active_tools'] ?? '';
        $activeTools = [];
        if (!empty($activeToolsRaw)) {
            $activeTools = array_filter(array_map('trim', explode(',', $activeToolsRaw)));
        }
        $isToolTurn = !empty($activeTools);
        $pendingTools = $activeTools; // multi-tool: queue of tools left to execute

        if ($isToolTurn) {
            \App\Logger::logEvent('tool_turn_start', 'Tool turn initiated', [
                'session_id' => $sessionId,
                'active_tools' => $activeTools,
                'query' => $query,
            ], 'info', 'ChatManager::process');
        }

        \App\Logger::info("isToolTurn check", [
            'raw' => $activeToolsRaw ?: 'EMPTY',
            'parsed' => $activeTools,
            'isToolTurn' => $isToolTurn,
            'post_keys' => array_keys($_POST),
        ]);

        // Silent emit for tool turns — suppress trace noise, only pass core stream events
        $silentEmit = function(string $event, array $data = []) use ($emit, $isToolTurn) {
            if ($isToolTurn) {
                if (!in_array($event, ['token', 'reasoning', 'thought_complete', 'generating', 'done', 'super_abilities_requested', 'file_choices', 'status', 'ask_user'])) {
                    return;
                }
            }
            $emit($event, $data);
        };
        $emit = $silentEmit;

        $imagePath = null;
        if (!$isToolTurn && empty($cacheAction)) {
            if ($imageFile && $imageFile['error'] !== UPLOAD_ERR_NO_FILE) {
                $emit('status', ['text' => 'Processing attachment...']);
            }
            $imagePath = $this->fileAttachmentService->handleUpload($sessionId, $imageFile);
            $this->db->insert('chat_history', [
                'session_id' => $sessionId,
                'role' => 'user',
                'message' => $query,
                'image_path' => $imagePath,
                'token_estimate' => (int)(mb_strlen($query) / 4)
            ]);
        }

        $history = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
        $updatedTitle = null;
        if (empty($cacheAction) && count($history) === 1) {
            $updatedTitle = $this->autoGenerateTitle($sessionId, $query, $history, $emit);
        }

        $usedCache = false;

        $systemPrompt = $this->promptAssemblyService->buildSystemPrompt($query, !empty($activeEditFile));
        $firstTool = $isToolTurn ? $activeTools : [];
        $currentMessages = $this->promptAssemblyService->buildMessagesArray($systemPrompt, $history, $firstTool, '', false, $query);

        $currentMessages = $this->cleanMessagesArray($currentMessages);

        $contextMessageCount = count($currentMessages) - 1; 

        $emit('context_assembled', [
            'message_count' => $contextMessageCount,
            'has_search_context' => false,
            'used_cache' => false
        ]);

        // Handle cache_action resubmits from ask_user card
        // Evidence injected as separate untrusted-data message — never system role.
        $validSourceIds = []; // populated by structured evidence pipeline in later phase
        $evidenceBlock = '';
        if ($cacheAction === 'use_cache' && !empty($cacheKeyToUse)) {
            $cachedContent = Cache::get($cacheKeyToUse) ?? '';
            if (!empty($cachedContent)) {
                $evidenceBlock = $cachedContent;
                $usedCache = true;
            }
        } elseif ($cacheAction === 'force_live') {
            if ($this->contextCondenserService !== null) {
                $result = SearchWebTool::liveSearch($query, $currentMessages, $emit, $this->contextCondenserService, $sessionId);
                if (!empty($result['evidence']) && !str_starts_with($result['evidence'], 'Web search for')) {
                    $evidenceBlock = $result['evidence'];
                    $validSourceIds = $result['sourceIds'] ?? [];
                }
            }
        }

        if (!empty($evidenceBlock)) {
            // Rebuild messages with evidence injected as proper untrusted block
            $currentMessages = $this->promptAssemblyService->buildMessagesArray(
                $systemPrompt, $history, $firstTool, '', $usedCache, $query,
                $evidenceBlock, $validSourceIds
            );
            $currentMessages = $this->cleanMessagesArray($currentMessages);
        }

        $emit('generating', []);

        $executionCount = 0;
        $maxExecutions = 5;
        $finalResponse = '';

        while ($executionCount < $maxExecutions) {
            if ($isToolTurn && !empty($pendingTools)) {
                $emit('status', ['text' => 'Analyzing request...']);

                $aiRawResponse = $this->agent->chat($currentMessages, false);

                $responseLen = strlen($aiRawResponse);
                \App\Logger::logEvent('tool_turn_first_pass', "Tool turn first pass complete: {$responseLen} chars", [
                    'session_id' => $sessionId,
                    'response_length' => $responseLen,
                    'response_preview' => mb_substr($aiRawResponse, 0, 300),
                ], 'info', 'ChatManager::process');

                \App\Logger::info('tool-turn first pass response', [
                    'len' => strlen($aiRawResponse),
                    'preview' => substr($aiRawResponse, 0, 300),
                ]);
            } else {
                $aiRawResponse = $this->streamAgentResponse($currentMessages, $emit);
            }

            if ($executionCount === 0 && empty($activeTools)) {
                // Normal turn: check if the model requested super_abilities.
                // Only check the response portion — NOT the CoT/thinking. The model
                // often mentions "super_abilities" in its internal reasoning (both
                // positively and negatively), but the card should only appear when
                // the model explicitly outputs it in the visible response.
                $extracted = \App\ThoughtExtractor::extract($aiRawResponse);
                $userVisible = $extracted['content'];
                if (stripos($userVisible, 'super_abilities') !== false) {
                    $finalResponse = $aiRawResponse;
                    // Save the assistant response
                    $this->db->insert('chat_history', [
                        'session_id' => $sessionId,
                        'role' => 'assistant',
                        'message' => $aiRawResponse,
                        'message_type' => 'super_abilities',
                        'token_estimate' => (int)(mb_strlen($aiRawResponse) / 4),
                        'search_query' => null,
                        'cache_used' => 0,
                        'scraped_urls' => null
                    ]);
                    $emit('super_abilities_requested', [
                        'session_id' => $sessionId,
                        'query' => $query
                    ]);
                    $emit('done', [
                        'message' => $aiRawResponse,
                        'title' => $updatedTitle,
                        'session_id' => $sessionId
                    ]);
                    return [
                        'status' => 'success',
                        'message' => $aiRawResponse,
                        'title' => $updatedTitle,
                        'meta' => ['super_abilities_requested' => true]
                    ];
                }
            }

            // Parse tool calls from response. On a tool turn, only the first
            // (non-streaming) response contains tool calls. Subsequent streaming
            // responses are the model's natural-language answer — don't re-scan.
            $shouldParse = $isToolTurn && !empty($pendingTools);
            $toolResults = $shouldParse
                ? $this->toolExecutionService->parseAndExecuteToolLines($aiRawResponse, $sessionId, $currentMessages, $emit)
                : [];

            // Guarantee: every user-selected tool MUST execute. If the model
            // skipped any, force-execute them with the full user query.
            // Only on the tool-parsing pass (shouldParse), not the streaming pass.
            if ($shouldParse && !empty($activeTools)) {
                $matchedNames = array_column($toolResults, 'tool');
                $calendarTools = ['get_todoist_tasks', 'create_todoist_task', 'update_todoist_task', 'delete_todoist_task'];
                $calendarMatched = !empty(array_intersect($matchedNames, $calendarTools));

                foreach ($activeTools as $tool) {
                    if ($tool === 'calendar') {
                        if (!$calendarMatched) {
                            $emit('status', ['text' => 'Fetching calendar...']);
                            \App\ProgressWriter::write($sessionId, 'tool_start', 'Executing get_todoist_tasks (forced)', 'amber');
                            $forced = $this->toolExecutionService->executeGuaranteed('get_todoist_tasks', $query, $sessionId, $currentMessages, $emit);
                            if (!empty($forced)) {
                                $toolResults[] = ['tool' => 'get_todoist_tasks', 'result' => $forced];
                            }
                            \App\ProgressWriter::write($sessionId, 'tool_done', 'get_todoist_tasks completed (forced).', 'amber');
                            $emit('trace', ['label' => 'get_todoist_tasks (forced) completed.', 'color' => 'emerald']);
                        }
                    } elseif (!in_array($tool, $matchedNames)) {
                        $emit('status', ['text' => "Executing {$tool}..."]);
                        \App\ProgressWriter::write($sessionId, 'tool_start', "Executing {$tool} (forced)", 'amber');
                        $forced = $this->toolExecutionService->executeGuaranteed($tool, $query, $sessionId, $currentMessages, $emit);
                        if (!empty($forced)) {
                            $toolResults[] = ['tool' => $tool, 'result' => $forced];
                        }
                        \App\ProgressWriter::write($sessionId, 'tool_done', "{$tool} completed (forced).", 'amber');
                        $emit('trace', ['label' => "{$tool} (forced) completed.", 'color' => 'emerald']);
                    }
                }
            }

            if ($isToolTurn && $executionCount === 0 && empty($toolResults)) {
                \App\Logger::logEvent('tool_name_missed', 'Tool turn: model response contained no parseable tool names', [
                    'session_id' => $sessionId,
                    'response_length' => strlen($aiRawResponse),
                    'response_preview' => mb_substr($aiRawResponse, 0, 500),
                    'response_first_50_chars' => mb_substr($aiRawResponse, 0, 50),
                ], 'warn', 'ChatManager::process');
            }

            \App\Logger::info('tool-turn parse result', [
                'shouldParse' => $shouldParse,
                'toolCount' => count($toolResults),
                'tools' => array_column($toolResults, 'tool'),
                'responseLen' => strlen($aiRawResponse),
                'responsePreview' => substr($aiRawResponse, 0, 200),
            ]);

            // Check for ASK_USER sentinel from cache evaluator
            $askUser = false;
            foreach ($toolResults as $item) {
                if (str_starts_with($item['result'], '__ASK_USER__')) {
                    $askUser = true;
                    break;
                }
            }
            if ($askUser) {
                // Frontend card is already shown via ask_user SSE event.
                // Don't save tool results — let the user decide cache vs live.
                $emit('done', [
                    'message' => '',
                    'title' => $updatedTitle,
                    'session_id' => $sessionId
                ]);
                return [
                    'status' => 'success',
                    'message' => '',
                    'meta' => ['ask_user' => true]
                ];
            }

            if (!empty($toolResults)) {
                // Save the tool-call response
                $this->db->insert('chat_history', [
                    'session_id' => $sessionId,
                    'role' => 'assistant',
                    'message' => $aiRawResponse,
                    'message_type' => 'tool_call',
                    'token_estimate' => (int)(mb_strlen($aiRawResponse) / 4)
                ]);

                // tool_done + save each tool result
                foreach ($toolResults as $item) {
                    $tn = $item['tool'];

                    \App\ProgressWriter::write($sessionId, 'tool_done', "{$tn} completed.", 'emerald');

                    $this->db->insert('chat_history', [
                        'session_id' => $sessionId,
                        'role' => 'system',
                        'message' => $item['result'],
                        'message_type' => 'data_fetching',
                        'tool_name' => $tn,
                        'token_estimate' => (int)(mb_strlen($item['result']) / 4)
                    ]);
                }

                // All tools executed — rebuild for natural streaming response
                $pendingTools = [];
                $updatedHistory = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);

                // Extract source IDs from data_fetching messages so CitationValidator can fire
                if (empty($validSourceIds)) {
                    foreach ($updatedHistory as $row) {
                        if (($row['message_type'] ?? '') === 'data_fetching') {
                            $msg = $row['message'] ?? '';
                            if (preg_match_all('/<source\s+id="([^"]+)"/', $msg, $m)) {
                                $validSourceIds = array_values(array_unique($m[1]));
                            }
                        }
                    }
                }

                $currentMessages = $this->promptAssemblyService->buildMessagesArray($systemPrompt, $updatedHistory, [], '', false, $query);
                $currentMessages = $this->cleanMessagesArray($currentMessages);

                $executionCount++;
            } elseif ($isToolTurn && !empty($pendingTools)) {
                // Tool call not matched. Clear queue, go to streaming.
                $pendingTools = [];

                \App\Logger::logEvent('tool_turn_no_match', 'Tool turn produced no parseable tool call', [
                    'session_id' => $sessionId,
                    'response_preview' => mb_substr($aiRawResponse, 0, 500),
                    'response_length' => strlen($aiRawResponse),
                    'pending_tools' => $pendingTools,
                ], 'warn', 'ChatManager::process');

                $this->db->insert('chat_history', [
                    'session_id' => $sessionId,
                    'role' => 'assistant',
                    'message' => $aiRawResponse,
                    'message_type' => 'tool_call',
                    'token_estimate' => (int)(mb_strlen($aiRawResponse) / 4)
                ]);

                $updatedHistory = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
                $currentMessages = $this->promptAssemblyService->buildMessagesArray($systemPrompt, $updatedHistory, [], '', false, $query);
                $currentMessages = $this->cleanMessagesArray($currentMessages);

                $executionCount++;
            } else {
                // No tool calls — this is the final response
                $finalResponse = $aiRawResponse;
                break;
            }
        }

        if ($executionCount >= $maxExecutions) {
            \App\Logger::logEvent('tool_loop_exhausted', "Tool execution loop hit max iterations ({$maxExecutions})", [
                'session_id' => $sessionId,
                'max_executions' => $maxExecutions,
                'final_response_len' => strlen($finalResponse),
            ], 'warn', 'ChatManager::process');
        }

        $cleanResponse = $finalResponse;

        // Strip hallucinated source IDs from final answer
        if (!empty($validSourceIds)) {
            $citationValidator = new CitationValidator();
            $cleanResponse = $citationValidator->sanitizeCitations($cleanResponse, $validSourceIds);
        }

        $usage = $this->agent->lastUsage;
        $assistantTokens = (int)(mb_strlen($cleanResponse) / 4);

        if ($usage) {
            if (isset($usage['prompt_tokens'])) {
                $userTokens = (int)$usage['prompt_tokens'];
                $userHistory = $this->db->selectSafe('chat_history', ['session_id' => $sessionId, 'role' => 'user']);
                if (!empty($userHistory)) {
                    usort($userHistory, fn($a, $b) => $b['id'] - $a['id']);
                    $this->db->update('chat_history', [
                        'token_estimate' => $userTokens
                    ], ['id' => $userHistory[0]['id']]);
                }
            }
            if (isset($usage['completion_tokens'])) {
                $assistantTokens = (int)$usage['completion_tokens'];
            }
        }

        $this->db->insert('chat_history', [
            'session_id' => $sessionId,
            'role' => 'assistant',
            'message' => $cleanResponse,
            'image_path' => null,
            'token_estimate' => $assistantTokens,
            'search_query' => null,
            'cache_used' => $usedCache ? 1 : 0,
            'scraped_urls' => !empty($scrapedUrls) ? json_encode($scrapedUrls) : null
        ]);

        $finalHistory = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
        if ($usage && isset($usage['prompt_tokens'])) {
            $totalSessionTokens = (int)$usage['prompt_tokens'];
        } else {
            $totalSessionTokens = 0;
            foreach ($finalHistory as $row) {
                $totalSessionTokens += (int)($row['token_estimate'] ?? 0);
            }
        }

        $emit('done', [
            'message' => $cleanResponse,
            'title' => $updatedTitle,
            'total_session_tokens' => $totalSessionTokens,
            'session_id' => $sessionId
        ]);

        \App\ProgressWriter::done($sessionId);

        return [
            'status' => 'success',
            'message' => $finalResponse,
            'title' => $updatedTitle,
            'meta' => [
                'search_triggered' => false,
                'search_query' => null,
                'cache_used' => $usedCache
            ]
        ];
    }

    private function checkTokenThreshold(int $sessionId, bool $bypassWarning, callable $emit): bool
    {
        if ($bypassWarning) {
            return true;
        }

        $history = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
        $keepLimit = (int) Config::get('CONDENSATION_KEEP_LIMIT', 6);

        $filteredHistory = array_filter($history, function($row) {
            $type = $row['message_type'] ?? '';
            return $type !== 'tool_call' && $type !== 'super_abilities';
        });

        if (count($filteredHistory) > ($keepLimit * 2)) {
            $totalTokens = 0;
            foreach ($filteredHistory as $row) {
                $totalTokens += (int)($row['token_estimate'] ?? 0);
            }

            $maxTokens = (int) Config::get('MAX_HISTORY_TOKENS', 0);
            if ($maxTokens <= 0) {
                $ctxSize = (int) Config::get('LLM_CTX_SIZE', 0);
                $maxTokens = $ctxSize > 0
                    ? max($ctxSize - 8000, 32768)
                    : 32768;
            }
            if ($totalTokens > $maxTokens) {
                $emit('limit_warning', [
                    'total_tokens' => $totalTokens,
                    'max_tokens' => $maxTokens,
                    'message_count' => count($filteredHistory)
                ]);
                return false;
            }
        }
        return true;
    }

    private function ensureSessionExists(int $sessionId): void
    {
        $exists = $this->db->query("SELECT id FROM chat_sessions WHERE id = ?", [$sessionId]);
        if (!$exists) {
            $this->db->insert('chat_sessions', [
                'id' => $sessionId,
                'title' => 'New Conversation',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }
    }

    private function autoGenerateTitle(int $sessionId, string $query, array $history, callable $emit): ?string
    {
        if (count($history) !== 1) return null;

        $title = mb_strlen($query) > 60
            ? mb_substr($query, 0, 57) . '...'
            : $query;

        $this->db->update('chat_sessions', ['title' => $title], ['id' => $sessionId]);
        $emit('title_updated', ['title' => $title]);
        return $title;
    }

    private function streamAgentResponse(array $messages, callable $emit): string
    {
        $aiResponse = '';
        $utf8_buffer = '';
        $inJsonTool = false;
        $jsonBraceDepth = 0;
        $inThought = false;
        $thoughtBuffer = '';
        $preThoughtBuffer = '';
        $isStartOfResponse = true;
        $nativeReasoningSeen = false;

        $this->agent->chat($messages, true, function($chunk, $type = 'content') use ($emit, &$aiResponse, &$utf8_buffer, &$inJsonTool, &$jsonBraceDepth, &$inThought, &$thoughtBuffer, &$preThoughtBuffer, &$isStartOfResponse, &$nativeReasoningSeen) {
            if ($type === 'reasoning') {
                $nativeReasoningSeen = true;
                $emit('reasoning', ['chunk' => $chunk]);
                return;
            }

            if ($nativeReasoningSeen) {
                $nativeReasoningSeen = false;
                $emit('thought_complete', []);
            }

            $aiResponse .= $chunk;

            // In-thought: accumulate into buffer, check for close tag in full buffer.
            // Previous per-chunk closesThought() missed close tags split across chunks.
            if ($inThought) {
                $thoughtBuffer .= $chunk;

                if (\App\ThoughtExtractor::containsCloseTag($thoughtBuffer)) {
                    $extracted = \App\ThoughtExtractor::extract($thoughtBuffer);
                    $inThought = false;

                    if (!empty($extracted['thought'])) {
                        $emit('reasoning', ['chunk' => $extracted['thought']]);
                    }
                    $emit('thought_complete', []);
                    $thoughtBuffer = '';

                    if (!empty($extracted['content'])) {
                        $chunk = $extracted['content'];
                    } else {
                        return;
                    }
                } else {
                    return;
                }
            }

            // Pre-thought: accumulate chunks in a buffer so we can detect open tags
            // that span chunk boundaries (e.g. "<|" + "channel" + "|>" + "thought").
            // Do NOT check opensThought() per-chunk — it misses split tags.
            $preThoughtBuffer .= $chunk;

            $openPos = \App\ThoughtExtractor::openTagPosition($preThoughtBuffer);
            if ($openPos !== -1) {
                if ($openPos > 0) {
                    $beforeText = substr($preThoughtBuffer, 0, $openPos);
                    \App\Logger::info('Thought open tag: emitting text before tag', ['len' => strlen($beforeText)]);
                    $emit('token', ['chunk' => $beforeText]);
                }

                $inThought = true;
                $thoughtBuffer = substr($preThoughtBuffer, $openPos);
                $preThoughtBuffer = '';

                if (\App\ThoughtExtractor::containsCloseTag($thoughtBuffer)) {
                    $extracted = \App\ThoughtExtractor::extract($thoughtBuffer);
                    $inThought = false;

                    if (!empty($extracted['thought'])) {
                        $emit('reasoning', ['chunk' => $extracted['thought']]);
                    }
                    $emit('thought_complete', []);
                    $thoughtBuffer = '';

                    if (!empty($extracted['content'])) {
                        $chunk = $extracted['content'];
                    } else {
                        return;
                    }
                } else {
                    return;
                }
            }

            if (strlen($preThoughtBuffer) > \App\ThoughtExtractor::MAX_OPEN_TAG_LEN) {
                $chunk = $preThoughtBuffer;
                $preThoughtBuffer = '';
            } else {
                return;
            }

            // Suppress native tokenizer tool-call blocks (Gemma 4 / Qwen)
            if (str_contains($chunk, '<|tool_call|>') || str_contains($chunk, '<|tool_call>')) {
                $inJsonTool = true;
                return;
            }
            if ($inJsonTool && (str_contains($chunk, '<|tool_call|>') || str_contains($chunk, '</tool_call>') || str_contains($chunk, '<tool_call|>'))) {
                $inJsonTool = false;
                return;
            }

            if ($inJsonTool) {
                $jsonBraceDepth += substr_count($chunk, '{') - substr_count($chunk, '}');
                if ($jsonBraceDepth <= 0) {
                    $inJsonTool = false;
                    $jsonBraceDepth = 0;
                }
                return;
            }

            $utf8_buffer .= $chunk;

            if (mb_check_encoding($utf8_buffer, 'UTF-8')) {
                // Strip old [System Routing Hint: ...] from output
                $clean = preg_replace('/\[System Routing Hint:[^\]]*\]/s', '', $utf8_buffer);

                if ($clean === '') {
                    $utf8_buffer = '';
                    return;
                }

                // Detect JSON tool calls (legacy format)
                if (str_contains($clean, '{')) {
                    if (preg_match('/"tool"\s*:/', $clean)) {
                        $inJsonTool = true;
                        $jsonStart = strrpos($clean, '{');
                        if ($jsonStart !== false) {
                            $beforeJson = trim(substr($clean, 0, $jsonStart));
                            if (!empty($beforeJson)) {
                                $emit('token', ['chunk' => $beforeJson]);
                            }
                            $jsonPart = substr($clean, $jsonStart);
                            $jsonBraceDepth = substr_count($jsonPart, '{') - substr_count($jsonPart, '}');
                            if ($jsonBraceDepth <= 0) {
                                $lastBrace = strrpos($jsonPart, '}');
                                $afterJson = trim(substr($jsonPart, $lastBrace + 1));
                                if (!empty($afterJson)) {
                                    $emit('token', ['chunk' => $afterJson]);
                                }
                            }
                        } else {
                            $jsonBraceDepth = 1;
                        }
                        $utf8_buffer = '';
                        return;
                    }

                    if (mb_strlen($clean) > 150) {
                        $emit('token', ['chunk' => $clean]);
                        $utf8_buffer = '';
                    }
                    return;
                }

                if ($isStartOfResponse && !empty(trim($clean))) {
                    $trimmedClean = ltrim($clean);
                    if (str_starts_with($trimmedClean, '`')) {
                        $clean = preg_replace('/^`+/s', '', $trimmedClean);
                        $clean = preg_replace('/^(markdown|text|html|txt)?\s+/si', '', $clean);
                    }
                    if (trim($clean) !== '' && !str_starts_with(trim($clean), '`')) {
                        $isStartOfResponse = false;
                    }
                }

                $emit('token', ['chunk' => $clean]);
                $utf8_buffer = '';
            }
        });

        // Drain any leftover content from the pre-thought buffer.
        // If the stream ends while the buffer is still < MAX_OPEN_TAG_LEN
        // and no open tag was found, the accumulated text was never emitted.
        if (!empty($preThoughtBuffer)) {
            $emit('token', ['chunk' => $preThoughtBuffer]);
        }

        // If we're still in a thought block at stream end (no close tag found),
        // emit whatever was accumulated as reasoning and close the block.
        if ($inThought && !empty($thoughtBuffer)) {
            $extracted = \App\ThoughtExtractor::extract($thoughtBuffer);
            if (!empty($extracted['thought'])) {
                $emit('reasoning', ['chunk' => $extracted['thought']]);
            }
            $emit('thought_complete', []);
            if (!empty($extracted['content'])) {
                $emit('token', ['chunk' => $extracted['content']]);
            }
        }

        if (!empty($utf8_buffer) && !$inJsonTool) {
            $emit('token', ['chunk' => mb_convert_encoding($utf8_buffer, 'UTF-8', 'UTF-8')]);
        }

        return $aiResponse;
    }

}
