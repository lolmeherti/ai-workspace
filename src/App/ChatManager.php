<?php

namespace App;

use App\Database;
use App\AgentManager;
use App\Agents\AtomizationPolicy;
use App\Config;
use App\Search\CitationValidator;
use App\Search\SourceCondenser;
use App\Search\TokenCounter;
use App\Search\WebChunk;
use App\Services\AtomizationStats;
use App\Services\FileAttachmentService;
use App\Services\ModelLock;
use App\Services\PromptAssemblyService;
use App\Services\ToolExecutionService;

class ChatManager
{
    private const OUTPUT_RESERVE_TOKENS = 4096;
    private const SAFETY_MARGIN_TOKENS = 256;
    private const ROUTER_MAX_TOKENS = 1024;

    private Database $db;
    private AgentManager $agent;
    private FileAttachmentService $fileAttachmentService;
    private PromptAssemblyService $promptAssemblyService;
    private ToolExecutionService $toolExecutionService;
    private AtomizationStats $atomizationStats;
    private string $uploadDir;
    /** @var callable(string):int */
    private $countTokens;

    public function __construct(
        Database $db,
        AgentManager $agent,
        ?callable $countTokens = null
    ) {
        $this->db = $db;
        $this->agent = $agent;
        $this->countTokens = $countTokens ?? [new TokenCounter(), 'count'];
        $this->uploadDir = __DIR__ . '/../uploads/';

        $this->fileAttachmentService = new FileAttachmentService($db, $agent, $this->uploadDir);
        $this->promptAssemblyService = new PromptAssemblyService($this->db, $this->uploadDir);
        $this->toolExecutionService = new ToolExecutionService($db, $agent, $this->uploadDir);
        $this->atomizationStats = new AtomizationStats($db);
    }

    public function process(int $sessionId, string $query, ?array $imageFile, ?string $activeEditFile = null, ?callable $streamCallback = null): array
    {
        $emit = function(string $event, array $data = []) use ($streamCallback) {
            if ($streamCallback !== null) {
                $streamCallback($event, $data);
            }
        };

        $emit('status', ['text' => 'Initializing...']);

        $overflow = $this->preflightContext($sessionId, $query, !empty($activeEditFile));
        if ($overflow !== null) {
            $emit('context_overflow', $overflow);
            return [
                'status' => 'context_overflow',
                'message' => $overflow['message'],
            ];
        }

        $lockToken = ModelLock::acquireOrBusy(ModelLock::PROCESS_TTL_MS);
        try {
            return $this->processLocked($sessionId, $query, $imageFile, $activeEditFile, $emit);
        } finally {
            ModelLock::release($lockToken);
        }
    }

    private function processLocked(int $sessionId, string $query, ?array $imageFile, ?string $activeEditFile, callable $emit): array
    {
        $this->ensureSessionExists($sessionId);

        // Per-turn performance capture: reset the agent's call log and wrap the
        // emit callback to record time-to-first-token (first 'token' event).
        $this->agent->resetCallLog();
        $turnStart = microtime(true);
        $firstTokenTs = null;
        $origEmit = $emit;
        $emit = function (string $event, array $data = []) use ($origEmit, &$firstTokenTs) {
            if ($event === 'token' && $firstTokenTs === null) {
                $firstTokenTs = microtime(true);
            }
            $origEmit($event, $data);
        };

        // Deferred evidence hygiene: if un-atomized raw evidence has piled up
        // past the backlog threshold (or context headroom is tight), atomize it
        // now — before this turn's messages are built — so the atoms are
        // injected instead of raw. Runs per tool result, never as one giant
        // mixed batch (keeps the condenser's job narrow for small models).
        $this->atomizeBacklogIfNeeded($sessionId, $emit);

        // Always insert user message — tools are always available, no per-turn activation
        $imagePath = null;
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

        $history = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
        $updatedTitle = null;
        if (count($history) === 1) {
            $updatedTitle = $this->autoGenerateTitle($sessionId, $query, $history, $emit);
        }

        $systemPrompt = $this->promptAssemblyService->buildSystemPrompt($query, !empty($activeEditFile));
        // Date + knowledge cutoff keeps tool selection accurate (Phase 2 finding),
        // folded into the integrated first pass so the model knows when to search.
        $systemPrompt .= $this->promptAssemblyService->dateContextLine();
        $currentMessages = $this->promptAssemblyService->buildMessagesArray($systemPrompt, $history);

        $contextMessageCount = count($currentMessages) - 1;

        $emit('context_assembled', [
            'message_count' => $contextMessageCount,
            'has_search_context' => false,
        ]);

        $sourceMap = [];
        $this->toolExecutionService->resetSourceMap();
        $this->toolExecutionService->resetBackingChunks();
        $this->toolExecutionService->resetSelectedChunks();
        $this->toolExecutionService->resetRetrievedSourceIds();

        // Transient session-evidence retrieval is injected for this turn only.
        $transientEvidence = [];
        $transientSourceIds = [];

        // Integrated first pass: the normal assistant with tools attached. On a
        // no-tool turn the answer streams live (pre-decision reasoning buffered
        // then released); on a tool turn it assembles tool_calls, we execute
        // them, then run a single second inference over the acquired evidence.
        $emit('status', ['text' => 'Analyzing request...']);
        $first = $this->firstPass($currentMessages, $emit);

        $freshRowIds = [];
        if (!empty($first['tool_calls'])) {
            \App\ProgressWriter::init('/tmp');
            $emit('status', ['text' => 'Executing tools...']);

            foreach ($first['tool_calls'] as $toolCall) {
                $fn = $toolCall['function'] ?? [];
                $toolName = $fn['name'] ?? '';
                $argsJson = $fn['arguments'] ?? '{}';
                $args = json_decode($argsJson, true) ?: [];
                $queries = $args['queries'] ?? [];

                if (empty($toolName)) continue;
                if (empty($queries) && $toolName !== 'create_todoist_task') continue;

                $queryList = $toolName === 'create_todoist_task'
                    ? ($args['content'] ?? '')
                    : implode(', ', $queries);
                $emit('tool_start', ['tool' => $toolName, 'label' => "{$toolName}: {$queryList}"]);

                $result = $this->toolExecutionService->executeToolByName(
                    $toolName, $args, $sessionId, $emit
                );

                // search_session_evidence is TRANSIENT: its rehydrated chunks are
                // injected for this turn only. Do NOT persist a durable Context Data
                // row, do NOT promote retrieved chunks to atomic_context, and do NOT
                // allocate new source IDs (chunks keep their original S#-C# refs).
                if ($toolName === 'search_session_evidence') {
                    $transientEvidence[] = $result;
                    $transientSourceIds = array_merge(
                        $transientSourceIds,
                        $this->toolExecutionService->getLastRetrievedSourceIds()
                    );
                    $emit('tool_done', ['tool' => $toolName, 'label' => "{$toolName} completed."]);
                    continue;
                }

                $lastSourceMap = $this->toolExecutionService->getLastSourceMap();
                $lastBackingChunks = $this->toolExecutionService->getLastBackingChunks();
                $lastSelectedChunks = $this->toolExecutionService->getLastSelectedChunks();
                $sourceMap = array_merge($sourceMap, $lastSourceMap);

                $emit('tool_done', ['tool' => $toolName, 'label' => "{$toolName} completed."]);

                // Raw evidence is stored un-atomized (atomic_context null) with
                // the exact selected chunks persisted so a later deferred
                // atomization pass can condense this row on its own. The next
                // turn injects raw until that happens.
                $this->db->insert('chat_history', [
                    'session_id' => $sessionId,
                    'role' => 'system',
                    'message' => $result,
                    'message_type' => 'data_fetching',
                    'tool_name' => $toolName,
                    'search_query' => $queryList,
                    'source_map' => !empty($lastSourceMap) ? json_encode($lastSourceMap) : null,
                    'backing_chunks' => !empty($lastBackingChunks) ? json_encode($lastBackingChunks, JSON_UNESCAPED_UNICODE) : null,
                    'selected_chunks' => ($toolName === 'search_web' && !empty($lastSelectedChunks)) ? json_encode($lastSelectedChunks, JSON_UNESCAPED_UNICODE) : null,
                    'atomic_context' => null,
                    'token_estimate' => ($this->countTokens)($result),
                ]);

                $historyId = (int) $this->db->getConnection()->lastInsertId();
                $freshRowIds[] = $historyId;

                $emit('context_data_added', [
                    'id' => $historyId,
                    'label' => $queryList,
                    'tool_name' => $toolName,
                    'query' => $queryList,
                    'source_count' => count($lastSourceMap),
                    'token_estimate' => ($this->countTokens)($result),
                    'active' => true,
                ]);
            }

            \App\ProgressWriter::done($sessionId);

            $history = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
            // Immediate answer uses rich evidence for this turn's fresh rows;
            // atoms (durable compact context) replace rich evidence on later turns.
            $currentMessages = $this->promptAssemblyService->buildMessagesArray($systemPrompt, $history, [], $freshRowIds);

            // Inject transient session-evidence retrieval for this turn only.
            foreach ($transientEvidence as $te) {
                $block = $this->promptAssemblyService->buildEvidenceBlock($te);
                if (($block['content'] ?? '') !== '') {
                    $currentMessages[] = $block;
                }
            }
        }

        if (!empty($sourceMap)) {
            $emit('sources', ['sources' => $sourceMap]);
        }

        $emit('generating', []);

        if (!empty($first['tool_calls'])) {
            // Tool turn: single second inference over the acquired evidence.
            $aiRawResponse = $this->streamAgentResponse($currentMessages, $emit);
        } else {
            // Normal turn: the first pass already streamed the answer live.
            $aiRawResponse = $first['content'] ?? '';
        }
        $finalResponse = $aiRawResponse;

        $visibleSourceIds = $this->promptAssemblyService->extractVisibleSourceIds($history);
        if (!empty($transientSourceIds)) {
            $visibleSourceIds = array_values(array_unique(array_merge($visibleSourceIds, $transientSourceIds)));
        }
        $cleanResponse = (new CitationValidator())
            ->sanitizeCitations($finalResponse, $visibleSourceIds);

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
            'source_map' => empty($sourceMap) ? null : json_encode($sourceMap),
        ]);

        $assistantRowId = (int) $this->db->getConnection()->lastInsertId();

        // Per-turn performance metrics: total wall-clock + TTFT + the agent's
        // per-call log (firstpass / answer / condenser / ...). Persisted on the
        // assistant row so every bubble carries its own metrics on reload.
        $perfMetrics = [
            'total_ms' => (int) round((microtime(true) - $turnStart) * 1000),
            'ttft_ms' => $firstTokenTs !== null ? (int) round(($firstTokenTs - $turnStart) * 1000) : null,
            'calls' => $this->agent->callLog,
        ];
        $this->db->update('chat_history', [
            'perf_metrics' => json_encode($perfMetrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], ['id' => $assistantRowId]);

        $finalHistory = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
        if ($usage && isset($usage['prompt_tokens'])) {
            $totalSessionTokens = (int)$usage['prompt_tokens'];
        } else {
            $totalSessionTokens = 0;
            foreach ($finalHistory as $row) {
                $totalSessionTokens += (int)($row['token_estimate'] ?? 0);
            }
        }

        $this->db->update('chat_sessions', [
            'context_tokens' => $totalSessionTokens
        ], ['id' => $sessionId]);

        // No post-answer consolidation here. Atomization is deferred: the raw
        // evidence stays active this turn, and a later turn's
        // atomizeBacklogIfNeeded() pass condenses it once the backlog (or
        // context pressure) justifies the cost. The answer is never blocked.

        $emit('done', [
            'message' => $cleanResponse,
            'title' => $updatedTitle,
            'total_session_tokens' => $totalSessionTokens,
            'session_id' => $sessionId,
            'sources' => $sourceMap,
            'perf_metrics' => $perfMetrics,
        ]);

        return [
            'status' => 'success',
            'message' => $finalResponse,
            'title' => $updatedTitle,
            'meta' => [
                'search_triggered' => false,
                'search_query' => null,
            ]
        ];
    }

    private function preflightContext(int $sessionId, string $query, bool $isEditorMode): ?array
    {
        $ctxSize = (int) Config::get('LLM_CTX_SIZE', 0);
        if ($ctxSize <= 0) {
            return null;
        }

        $history = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
        $systemPrompt = $this->promptAssemblyService->buildSystemPrompt($query, $isEditorMode);
        $breakdown = $this->promptAssemblyService->estimatePromptTokens($systemPrompt, $history, $query);

        if (!PromptAssemblyService::projectsOverflow($breakdown, self::OUTPUT_RESERVE_TOKENS, $ctxSize, self::SAFETY_MARGIN_TOKENS)) {
            return null;
        }

        $total = $breakdown['total'] + self::OUTPUT_RESERVE_TOKENS + self::SAFETY_MARGIN_TOKENS;
        return [
            'total' => $total,
            'max' => $ctxSize,
            'output_reserve' => self::OUTPUT_RESERVE_TOKENS,
            'breakdown' => $breakdown,
            'message' => sprintf(
                'Context limit reached (%d/%d tokens). Context Data ~%d · Chat ~%d · output reserve %d. Evict raw context or condense chat to continue.',
                $total,
                $ctxSize,
                $breakdown['context_data'],
                $breakdown['recent_chat'],
                self::OUTPUT_RESERVE_TOKENS
            ),
        ];
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

    private function buildToolSchemas(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_local',
                    'description' => 'Search the user\'s local files and long-term memories for information. Use for anything in the user\'s personal data.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'queries' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Search queries. Include synonyms and alternate phrasings.',
                            ],
                        ],
                        'required' => ['queries'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_web',
                    'description' => 'Search the web for current information, facts, news, or anything beyond the user\'s personal data. Be focused — use only the most relevant query terms.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'queries' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Focused web search queries. Keep it tight.',
                            ],
                        ],
                        'required' => ['queries'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_calendar',
                    'description' => 'Search the user\'s calendar for tasks, events, and todo items.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'queries' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Calendar search queries.',
                            ],
                        ],
                        'required' => ['queries'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_session_evidence',
                    'description' => 'Search detailed evidence already retrieved from the web earlier in this conversation. Use when the detail you need was likely fetched before, instead of searching the web again.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'queries' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Search queries for the retained evidence.',
                            ],
                            'source_ids' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Optional: restrict the search to specific source IDs (e.g. ["S1"]).',
                            ],
                        ],
                        'required' => ['queries'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'create_todoist_task',
                    'description' => 'Create a task in the user\'s Todoist list.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'content' => [
                                'type' => 'string',
                                'description' => 'Task content.',
                            ],
                            'due_string' => [
                                'type' => 'string',
                                'description' => 'Natural-language due date, e.g. "Sep 29 10:00".',
                            ],
                        ],
                        'required' => ['content'],
                    ],
                ],
            ],
        ];
    }

    private function noToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'no_tool',
                'description' => 'Choose this when the request can be answered directly from your own knowledge or the conversation, with no search or tool needed.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
        ];
    }

    private function buildRouterSchemas(): array
    {
        return array_merge($this->buildToolSchemas(), [$this->noToolSchema()]);
    }

    private function routeRequest(array $history, array $assistantMessages): array
    {
        $routerMessages = $this->promptAssemblyService->buildMessagesArray(
            $this->promptAssemblyService->buildRouterSystemPrompt(),
            $history
        );

        $result = $this->agent->chatWithTools(
            $routerMessages,
            $this->buildRouterSchemas(),
            'required',
            0.0,
            self::ROUTER_MAX_TOKENS
        );

        $leaked = $result['content'] ?? null;
        $leakedChars = $leaked !== null ? mb_strlen($leaked) : 0;

        $realCalls = [];
        $sawNoTool = false;

        foreach (($result['tool_calls'] ?? []) as $tc) {
            $fn = $tc['function'] ?? [];
            $name = $fn['name'] ?? '';
            $args = json_decode($fn['arguments'] ?? '{}', true) ?: [];
            $queries = $args['queries'] ?? [];

            if ($name === 'no_tool') {
                $sawNoTool = true;
                continue;
            }

            if ($name !== '' && !empty($queries)) {
                $realCalls[] = [
                    'function' => [
                        'name' => $name,
                        'arguments' => $fn['arguments'] ?? '{}',
                    ],
                ];
            }
        }

        if (!empty($realCalls)) {
            if ($leakedChars > 0) {
                \App\Logger::logEvent('router_leaked_content', 'Router emitted prose alongside a valid tool call; prose ignored', [
                    'tools' => array_column(array_column($realCalls, 'function'), 'name'),
                    'leaked_chars' => $leakedChars,
                ], 'warn', 'ChatManager::routeRequest');
            }
            \App\Logger::logEvent('router_tools', 'Router selected tool(s)', [
                'tools' => array_column(array_column($realCalls, 'function'), 'name'),
                'fallback' => false,
            ], 'info', 'ChatManager::routeRequest');
            return ['tool_calls' => $realCalls, 'fallback' => false];
        }

        if ($sawNoTool) {
            if ($leakedChars > 0) {
                \App\Logger::logEvent('router_leaked_content', 'Router emitted prose alongside no_tool; prose ignored', [
                    'leaked_chars' => $leakedChars,
                ], 'warn', 'ChatManager::routeRequest');
            }
            \App\Logger::logEvent('router_no_tool', 'Router selected no_tool', [], 'info', 'ChatManager::routeRequest');
            return ['tool_calls' => [], 'fallback' => false];
        }

        \App\Logger::logEvent('router_failure', 'Router produced no valid tool call; falling back to auto-tool path', [
            'finish_reason' => $result['finish_reason'] ?? null,
            'content_chars' => $leakedChars,
            'tool_call_count' => count($result['tool_calls'] ?? []),
        ], 'warn', 'ChatManager::routeRequest');

        $fallback = $this->agent->chatWithTools($assistantMessages, $this->buildToolSchemas(), 'auto');
        $fallbackCalls = [];
        foreach (($fallback['tool_calls'] ?? []) as $tc) {
            $fn = $tc['function'] ?? [];
            $name = $fn['name'] ?? '';
            $args = json_decode($fn['arguments'] ?? '{}', true) ?: [];
            $queries = $args['queries'] ?? [];
            if ($name !== '' && !empty($queries)) {
                $fallbackCalls[] = [
                    'function' => [
                        'name' => $name,
                        'arguments' => $fn['arguments'] ?? '{}',
                    ],
                ];
            }
        }

        return ['tool_calls' => $fallbackCalls, 'fallback' => true];
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

    /**
     * Integrated first pass: one tool-capable streaming inference. Buffers
     * pre-decision reasoning, releases it (via the reasoning SSE) when normal
     * content begins, and discards it on a tool turn. Returns the structured
     * result from AgentManager::chatToolCapable.
     *
     * @return array{finish_reason:string, content:string, tool_calls:?array, usage:?array}
     */
    private function firstPass(array $messages, callable $emit): array
    {
        $reasoningBuffer = '';
        $utf8Buffer = '';
        $contentEmitted = false;
        $contentChars = 0;

        $result = $this->agent->chatToolCapable(
            $messages,
            $this->buildToolSchemas(),
            'auto',
            function ($chunk, $type) use ($emit, &$reasoningBuffer, &$utf8Buffer, &$contentEmitted, &$contentChars) {
                if ($type === 'reasoning') {
                    $reasoningBuffer .= $chunk;
                    return;
                }

                $utf8Buffer .= $chunk;
                if (!mb_check_encoding($utf8Buffer, 'UTF-8')) {
                    return;
                }

                if (!$contentEmitted && $reasoningBuffer !== '') {
                    $emit('reasoning', ['chunk' => $reasoningBuffer]);
                    $emit('thought_complete', []);
                    $reasoningBuffer = '';
                }
                $contentEmitted = true;
                $contentChars += mb_strlen($utf8Buffer);
                $emit('token', ['chunk' => $utf8Buffer]);
                $utf8Buffer = '';
            }
        );

        if ($utf8Buffer !== '') {
            if (!$contentEmitted && $reasoningBuffer !== '') {
                $emit('reasoning', ['chunk' => $reasoningBuffer]);
                $emit('thought_complete', []);
                $reasoningBuffer = '';
            }
            $contentEmitted = true;
            $emit('token', ['chunk' => mb_convert_encoding($utf8Buffer, 'UTF-8', 'UTF-8')]);
            $utf8Buffer = '';
        }

        if ($contentEmitted && !empty($result['tool_calls'])) {
            \App\Logger::logEvent('content_before_tool', 'Content emitted before tool_calls in the integrated first pass', [
                'content_before_tool_chars' => $contentChars,
                'tool' => $result['tool_calls'][0]['function']['name'] ?? null,
            ], 'warn', 'ChatManager::firstPass');
        }

        return $result;
    }

    public function streamAgentResponse(array $messages, callable $emit): string
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

    /**
     * Deferred evidence atomization. Runs at the start of a turn, before this
     * turn's messages are built, when the session's accumulated un-atomized raw
     * evidence crosses the backlog threshold — or when context headroom is tight
     * (safety override).
     *
     * PARTIAL reclamation: the threshold means "cleanup is required", not "clean
     * everything". Rows are sorted largest-first and atomized ONE AT A TIME
     * (each is a single narrow condenseBatched call, so a small model is never
     * handed a mixed evidence landfill); after each row the remaining backlog
     * and headroom are recalculated and the loop stops as soon as the backlog is
     * back below target AND safety headroom is restored. A giant maintenance
     * stall (e.g. three ~4s condenser calls back-to-back) is avoided; later
     * evidence accumulation triggers the next row's condensation.
     */
    private function atomizeBacklogIfNeeded(int $sessionId, callable $emit, ?SourceCondenser $condenser = null, ?int $ctxSize = null): void
    {
        $rows = $this->db->query(
            "SELECT id, token_estimate, search_query, selected_chunks, backing_chunks
             FROM chat_history
             WHERE session_id = ? AND message_type = 'data_fetching'
               AND raw_evicted = 0 AND atomic_context IS NULL",
            [$sessionId]
        );
        if (empty($rows)) {
            return;
        }

        $backlog = 0;
        foreach ($rows as $r) {
            $backlog += (int)($r['token_estimate'] ?? 0);
        }
        if ($backlog <= 0) {
            return;
        }

        $ctxSize = $ctxSize ?? (int) Config::get('LLM_CTX_SIZE', 0);
        $session = $this->db->query('SELECT context_tokens FROM chat_sessions WHERE id = ?', [$sessionId]);
        $sessionTokens = (int)($session[0]['context_tokens'] ?? 0);
        $headroom = $ctxSize - $sessionTokens - self::OUTPUT_RESERVE_TOKENS - self::SAFETY_MARGIN_TOKENS;

        if (!AtomizationPolicy::shouldAtomizeBacklog($backlog, $headroom, $ctxSize)) {
            return;
        }

        // Largest-first ordering so the highest-value rows (biggest raw evidence)
        // are reclaimed first, minimizing the number of condenser calls.
        usort($rows, fn($a, $b) => (int)($b['token_estimate'] ?? 0) <=> (int)($a['token_estimate'] ?? 0));

        // Rebuild the pending list (largest-first): one entry per un-atomized
        // row, from its persisted selected chunks (fallback to backing_chunks for
        // rows that predate selected_chunks persistence).
        $pending = [];
        foreach ($rows as $r) {
            $chunks = $this->decodeChunks((string)($r['selected_chunks'] ?? ''));
            if (empty($chunks)) {
                $chunks = $this->decodeChunks((string)($r['backing_chunks'] ?? ''));
            }
            if (empty($chunks)) {
                continue;
            }
            $pending[] = [
                'id' => (int)$r['id'],
                'token_estimate' => (int)($r['token_estimate'] ?? 0),
                'chunks' => $chunks,
                'query' => (string)($r['search_query'] ?? ''),
            ];
        }

        if (empty($pending)) {
            return;
        }

        \App\Logger::logEvent(
            'atomization_backlog',
            "Compacting evidence backlog of {$backlog} tokens across " . count($pending) . ' rows (headroom ' . $headroom . ')',
            [
                'backlog_tokens' => $backlog,
                'rows' => count($pending),
                'headroom' => $headroom,
                'consolidation_ms_ema' => round($this->atomizationStats->consolidationMsEma(), 1),
            ],
            'info',
            'ChatManager::atomizeBacklogIfNeeded'
        );

        $emit('status', ['text' => 'Compacting evidence...']);
        $emit('consolidation_start', ['rows' => count($pending)]);

        $condenser = $condenser ?? new SourceCondenser($this->agent);
        $remainingBacklog = $backlog;
        $remainingHeadroom = $headroom;
        $persisted = 0;
        $failed = 0;

        foreach ($pending as $item) {
            // Re-evaluate after every row: stop as soon as the backlog is below
            // target AND safety headroom is restored. Continue only while more
            // reclaim is actually required.
            if (!AtomizationPolicy::shouldAtomizeBacklog($remainingBacklog, $remainingHeadroom, $ctxSize)) {
                break;
            }

            // atomizeRow returns the atom token count on success (0 = empty,
            // -1 = threw). Reclaimed = raw − atoms, NOT the full raw row.
            $atomTokens = $this->atomizeRow($item['id'], $item['chunks'], $item['query'], $condenser);
            if ($atomTokens > 0) {
                $persisted++;
                $reclaimed = max(0, $item['token_estimate'] - $atomTokens);
                $emit('context_data_atomized', [
                    'id' => $item['id'],
                    'raw_tokens' => $item['token_estimate'],
                    'atom_tokens' => $atomTokens,
                    'reclaimed' => $reclaimed,
                ]);
                $remainingBacklog = max(0, $remainingBacklog - $reclaimed);
                $remainingHeadroom += $reclaimed; // atoms still inject; only the shrink is reclaimed
            } elseif ($atomTokens === -1) {
                $failed++;
            }
            // atomTokens 0 (empty extraction): row stays raw, not a failure.
        }

        if ($failed > 0) {
            $emit('consolidation_error', ['failed' => $failed, 'persisted' => $persisted]);
        } else {
            $emit('consolidation_done', ['persisted' => $persisted]);
        }
        $this->recordConsolidationTiming();
    }

    /** Decode a persisted WebChunk[] JSON column back into WebChunk objects. */
    public static function decodeChunks(string $json): array
    {
        $raw = json_decode($json, true);
        if (!is_array($raw)) {
            return [];
        }
        $chunks = [];
        foreach ($raw as $c) {
            if (is_array($c)) {
                $chunks[] = WebChunk::fromArray($c);
            }
        }
        return $chunks;
    }

    /** Feed the just-run consolidation's server-measured latency into the EMA. */
    private function recordConsolidationTiming(): void
    {
        $t = $this->agent->lastTimings ?? [];
        $total = (float)($t['prompt_ms'] ?? 0.0) + (float)($t['predicted_ms'] ?? 0.0);
        if ($total > 0.0) {
            $this->atomizationStats->recordConsolidation($total);
        }
    }

    /**
     * Condense ONE evidence row (one tool result) into atomic claims and persist
     * them on that row's atomic_context. No SSE emission here — the caller owns
     * the consolidation_start/done/error envelope.
     *
     * @return int the atom token count when atoms were persisted (> 0); 0 when
     *             extraction was empty (raw stays active, not a hard failure);
     *             -1 when condensation threw.
     */
    private function atomizeRow(int $rowId, array $chunks, string $query, SourceCondenser $condenser): int
    {
        $filtered = array_values(array_filter($chunks, fn($c) => strlen($c->text) < 50000));
        if (empty($filtered)) {
            $filtered = $chunks;
        }

        try {
            $claims = $condenser->condenseBatched($filtered, $query);
        } catch (\Throwable $e) {
            \App\Logger::logEvent('consolidation_failed', 'Evidence atomization failed: ' . $e->getMessage(), [
                'row_id' => $rowId,
                'error' => $e->getMessage(),
            ], 'error', 'ChatManager::atomizeRow');
            return -1;
        }

        if (empty($claims)) {
            \App\Logger::logEvent('consolidation_empty', 'Evidence atomization produced no atoms; raw evidence stays active', [
                'row_id' => $rowId,
            ], 'info', 'ChatManager::atomizeRow');
            return 0;
        }

        $atomTokens = $this->atomTokenCount($claims);

        $this->db->update('chat_history', [
            'atomic_context' => json_encode($claims, JSON_UNESCAPED_UNICODE),
            'atomic_tokens' => $atomTokens,
            'raw_evicted' => 1,
        ], ['id' => $rowId]);

        \App\Logger::logEvent('consolidation_ok', 'Evidence atomization persisted atoms', [
            'row_id' => $rowId,
            'claims' => count($claims),
            'atom_tokens' => $atomTokens,
        ], 'info', 'ChatManager::atomizeRow');
        return $atomTokens;
    }

    /**
     * Real token count of the atom set as it will be injected — the `[S#] claim`
     * lines rendered by PromptAssemblyService::renderAtomLines. Uses the injectable
     * TokenCounter (llama /tokenize, md5-cached, chars/4 fallback) so the viewer's
     * arrow shows real reclaimed tokens, not a chars/4 guess.
     */
    private function atomTokenCount(array $claims): int
    {
        $lines = PromptAssemblyService::renderAtomLines($claims);
        return ($this->countTokens)($lines);
    }

    /**
     * Evidence consolidation over a pre-built pending map. Runs SourceCondenser
     * over the exact selected chunks of each row and writes atomic_context on
     * that same row (no new row). On failure or empty extraction the row's
     * atomic_context stays null so the raw message remains active. Emits
     * consolidation_start, then consolidation_done (or consolidation_error) so
     * the frontend can show a "Consolidating evidence..." state.
     *
     * @param array<int, array{chunks: \App\Search\WebChunk[], query: string}> $pending
     */
    private function consolidateFreshEvidence(array $pending, callable $emit, ?SourceCondenser $condenser = null): void
    {
        if (empty($pending)) {
            return;
        }

        $emit('consolidation_start', ['rows' => count($pending)]);
        $condenser = $condenser ?? new SourceCondenser($this->agent);
        $persisted = 0;
        $failed = 0;

        foreach ($pending as $rowId => $item) {
            $atomTokens = $this->atomizeRow((int)$rowId, $item['chunks'], $item['query'], $condenser);
            if ($atomTokens > 0) {
                $persisted++;
            } elseif ($atomTokens === -1) {
                $failed++;
            }
        }

        if ($failed > 0) {
            $emit('consolidation_error', ['failed' => $failed, 'persisted' => $persisted]);
        } else {
            $emit('consolidation_done', ['persisted' => $persisted]);
        }
    }

}
