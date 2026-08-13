<?php

namespace App;

use App\Database;
use App\AgentManager;
use App\Agents\MemoryExtractor;
use App\Agents\ContextCondenser;
use App\Config;
use App\Scraper;
use App\Search;
use App\Search\CitationValidator;
use App\Services\FileAttachmentService;
use App\Services\PromptAssemblyService;
use App\Services\ToolExecutionService;

class ChatManager
{
    private Database $db;
    private AgentManager $agent;
    private FileAttachmentService $fileAttachmentService;
    private PromptAssemblyService $promptAssemblyService;
    private ToolExecutionService $toolExecutionService;
    private string $uploadDir;

    private ?ContextCondenser $contextCondenserService;

    public function __construct(
        Database $db, 
        AgentManager $agent, 
        ?MemoryExtractor $memoryExtractor = null,
        ?ContextCondenser $contextCondenser = null
    ) {
        $this->db = $db;
        $this->agent = $agent;
        $this->uploadDir = __DIR__ . '/../uploads/';
        $this->contextCondenserService = $contextCondenser;

        $this->fileAttachmentService = new FileAttachmentService($db, $agent, $this->uploadDir);
        $this->promptAssemblyService = new PromptAssemblyService($this->db, $this->uploadDir);
        $this->toolExecutionService = new ToolExecutionService($db, $agent, $this->uploadDir);
    }

    public function cleanMessagesArray(array $messages): array
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

    public function process(int $sessionId, string $query, ?array $imageFile, ?string $activeEditFile = null, ?callable $streamCallback = null): array
    {
        $emit = function(string $event, array $data = []) use ($streamCallback) {
            if ($streamCallback !== null) {
                $streamCallback($event, $data);
            }
        };

        $emit('status', ['text' => 'Initializing...']);

        $bypassWarning = (int)($_POST['bypass_warning'] ?? 0);
        if (!$this->checkTokenThreshold($sessionId, (bool)$bypassWarning, $emit)) {
            return [
                'status' => 'warning',
                'message' => 'Token limit warning triggered'
            ];
        }

        $this->ensureSessionExists($sessionId);

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
        $currentMessages = $this->promptAssemblyService->buildMessagesArray($systemPrompt, $history, [], '', false, $query);
        $currentMessages = $this->cleanMessagesArray($currentMessages);

        $contextMessageCount = count($currentMessages) - 1;

        $emit('context_assembled', [
            'message_count' => $contextMessageCount,
            'has_search_context' => false,
        ]);

        $sourceMap = [];
        $this->toolExecutionService->resetSourceMap();

        // Tool call pass — native function calling via llama.cpp API.
        // Model receives JSON-schema tool definitions; API enforces format.
        $tools = $this->buildToolSchemas();
        $emit('status', ['text' => 'Analyzing request...']);

        $toolResult = $this->agent->chatWithTools($currentMessages, $tools, 'auto');

        if ($toolResult['finish_reason'] === 'tool_calls' && !empty($toolResult['tool_calls'])) {
            \App\ProgressWriter::init('/tmp');
            $emit('status', ['text' => 'Executing tools...']);

            foreach ($toolResult['tool_calls'] as $toolCall) {
                $fn = $toolCall['function'] ?? [];
                $toolName = $fn['name'] ?? '';
                $argsJson = $fn['arguments'] ?? '{}';
                $args = json_decode($argsJson, true) ?: [];
                $queries = $args['queries'] ?? [];

                if (empty($toolName) || empty($queries)) continue;

                $queryList = implode(', ', $queries);
                $emit('tool_start', ['tool' => $toolName, 'label' => "{$toolName}: {$queryList}"]);

                $result = $this->toolExecutionService->executeToolByName(
                    $toolName, $queries, $sessionId, $emit
                );

                $sourceMap = array_merge($sourceMap, $this->toolExecutionService->getLastSourceMap());

                $emit('tool_done', ['tool' => $toolName, 'label' => "{$toolName} completed."]);

                $this->db->insert('chat_history', [
                    'session_id' => $sessionId,
                    'role' => 'system',
                    'message' => $result,
                    'message_type' => 'data_fetching',
                    'token_estimate' => (int)(mb_strlen($result) / 4),
                ]);
            }

            \App\ProgressWriter::done($sessionId);

            $history = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
            $currentMessages = $this->promptAssemblyService->buildMessagesArray(
                $systemPrompt, $history, [], '', false, $query
            );
            $currentMessages = $this->cleanMessagesArray($currentMessages);
        }

        if (!empty($sourceMap)) {
            $emit('sources', ['sources' => $sourceMap]);
        }

        $emit('generating', []);

        $aiRawResponse = $this->streamAgentResponse($currentMessages, $emit);
        $finalResponse = $aiRawResponse;

        $cleanResponse = (new CitationValidator())
            ->sanitizeCitations($finalResponse, array_keys($sourceMap));

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
            'session_id' => $sessionId,
            'sources' => $sourceMap,
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

    private function checkTokenThreshold(int $sessionId, bool $bypassWarning, callable $emit): bool
    {
        if ($bypassWarning) {
            return true;
        }

        $history = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
        $keepLimit = (int) Config::get('CONDENSATION_KEEP_LIMIT', 6);

        $filteredHistory = array_filter($history, function($row) {
            $type = $row['message_type'] ?? '';
            return $type !== 'tool_call';
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
        ];
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

}
