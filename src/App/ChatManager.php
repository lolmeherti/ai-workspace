<?php

namespace App;

use App\Database;
use App\AgentManager;
use App\Agents\MemoryExtractor;
use App\Agents\MemorySelector;
use App\Agents\SearchDecider;
use App\Agents\SemanticCacheEvaluator;
use App\Agents\ContextCondenser;
use App\Config;
use App\Services\FileAttachmentService;
use App\Services\WebSearchService;
use App\Services\PromptAssemblyService;
use App\Services\ToolExecutionService;

class ChatManager
{
    private Database $db;
    private AgentManager $agent;
    private ?SearchDecider $searchDecider;
    private FileAttachmentService $fileAttachmentService;
    private WebSearchService $webSearchService;
    private PromptAssemblyService $promptAssemblyService;
    private ToolExecutionService $toolExecutionService;
    private string $uploadDir;

    public function __construct(
        Database $db, 
        AgentManager $agent, 
        ?MemoryExtractor $memoryExtractor = null,
        ?MemorySelector $memorySelector = null,
        ?SearchDecider $searchDecider = null,
        ?SemanticCacheEvaluator $cacheEvaluator = null,
        ?ContextCondenser $contextCondenser = null
    ) {
        $this->db = $db;
        $this->agent = $agent;
        $this->searchDecider = $searchDecider;
        $this->uploadDir = __DIR__ . '/../uploads/';

        $this->fileAttachmentService = new FileAttachmentService($db, $agent, $this->uploadDir);
        $this->webSearchService = new WebSearchService($searchDecider, $cacheEvaluator, $contextCondenser);
        $this->promptAssemblyService = new PromptAssemblyService($this->db, $memorySelector, $this->uploadDir);
        $this->toolExecutionService = new ToolExecutionService($db, $agent, $this->uploadDir);
    }

    /**
     * Sanitizes the messages array for strict templates (like Qwen).
     * Any system message found after index 0 is mapped to a user role 
     * to prevent template compilation exceptions.
     */
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

    private function stripThinkingTags(string $text): string
    {
        // Gemma format: <|channel>thought ... <channel|>
        $text = preg_replace('/<\|channel>thought.*?<channel\|>/s', '', $text);
        // DeepSeek format: <think> ... </think>
        $text = preg_replace('/<think>.*?<\/think>/s', '', $text);
        return trim($text);
    }

    public function process(int $sessionId, string $query, ?array $imageFile, ?string $cacheAction = null, ?string $cacheKeyToUse = null, ?callable $streamCallback = null): array
    {
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

        $imagePath = null;
        if (empty($cacheAction)) {
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
        if (empty($cacheAction)) {
            $emit('status', ['text' => 'Generating title...']);
            $updatedTitle = $this->autoGenerateTitle($sessionId, $query, $history, $emit);
        }

        $usedCache = false;
        $scrapedUrls = [];
        $searchQuery = null;
        $intent = 'none';

        $emit('status', ['text' => 'Analyzing intent...']);
        if ($this->searchDecider) {
            $decisionResult = $this->searchDecider->decideSearchAndIntent($query, $history);
            $searchQuery = $decisionResult['search_query'] ?? null;
            $intent = $decisionResult['intents'] ?? 'none';
        }

        $emit('intent_result', [
            'search_query' => $searchQuery,
            'intents' => $intent
        ]);

        $condensedContext = $this->webSearchService->executeDecision(
            $query,
            $history,
            $cacheAction,
            $cacheKeyToUse,
            $emit,
            $usedCache,
            $scrapedUrls,
            $searchQuery
        );

        if ($condensedContext === 'ASK_USER') {
            return [];
        }

        $emit('status', ['text' => 'Assembling context...']);
        $systemPrompt = $this->promptAssemblyService->buildSystemPrompt($condensedContext, $usedCache, $query);
        $currentMessages = $this->promptAssemblyService->buildMessagesArray($systemPrompt, $history, $intent);
        
        $currentMessages = $this->cleanMessagesArray($currentMessages);

        $emit('context_assembled', [
            'message_count' => count($history),
            'has_search_context' => !empty($condensedContext),
            'used_cache' => $usedCache
        ]);

        $emit('generating', []);

        $aiResponse = '';
        $executionCount = 0;
        $maxExecutions = 5;
        $calledTools = [];

        while ($executionCount < $maxExecutions) {
            $aiRawResponse = $this->streamAgentResponse($currentMessages, $emit);
            $rawTools = \App\JsonParser::extractAllAndDecode($aiRawResponse);
            
            $decodedTools = [];
            $seenHashes = [];
            foreach ($rawTools as $toolParams) {
                $hash = md5(json_encode($toolParams));
                if (!in_array($hash, $seenHashes)) {
                    $seenHashes[] = $hash;
                    $decodedTools[] = $toolParams;
                }
            }

            $isToolCall = !empty($decodedTools);

            if ($isToolCall) {
                // Filter out tools already called in this turn
                $newTools = [];
                foreach ($decodedTools as $toolParams) {
                    $toolName = $toolParams['tool'] ?? 'unknown_tool';
                    if (!in_array($toolName, $calledTools)) {
                        $newTools[] = $toolParams;
                        $calledTools[] = $toolName;
                    }
                }
                $decodedTools = $newTools;

                // If all tools were repeats, treat as text response
                if (empty($decodedTools)) {
                    $aiResponse = $this->stripThinkingTags($aiRawResponse);
                    // Strip tool JSON from the response
                    foreach ($rawTools as $toolParams) {
                        $toolJson = json_encode($toolParams);
                        $aiResponse = str_replace($toolJson, '', $aiResponse);
                    }
                    $aiResponse = trim($aiResponse);
                    break;
                }

                $preambleMessage = $this->stripThinkingTags($aiRawResponse);
                foreach ($decodedTools as $toolParams) {
                    $toolJson = json_encode($toolParams);
                    $preambleMessage = str_replace($toolJson, '', $preambleMessage);
                }
                $preambleMessage = trim($preambleMessage);

                // If there is a text preamble, save it. Otherwise, save the raw tool call as a tool_call type.
                if (!empty($preambleMessage)) {
                    $this->db->insert('chat_history', [
                        'session_id' => $sessionId,
                        'role' => 'assistant',
                        'message' => $preambleMessage,
                        'message_type' => 'text',
                        'token_estimate' => (int)(mb_strlen($preambleMessage) / 4)
                    ]);
                } else {
                    $this->db->insert('chat_history', [
                        'session_id' => $sessionId,
                        'role' => 'assistant',
                        'message' => $aiRawResponse,
                        'message_type' => 'tool_call', // Standardizing on 'tool_call' so it's ignored in plaintext UI views
                        'token_estimate' => (int)(mb_strlen($aiRawResponse) / 4)
                    ]);
                }

                $combinedResults = [];
                foreach ($decodedTools as $toolParams) {
                    $toolName = $toolParams['tool'] ?? 'unknown_tool';
                    
                    $runningPhrase = '';
                    $completedPhrase = '';
                    
                    switch ($toolName) {
                        case 'search_files':
                            $q = $toolParams['query'] ?? '';
                            $runningPhrase = "Looking for \"{$q}\"...";
                            $completedPhrase = "Files checked.";
                            break;
                        case 'search_memories':
                            $q = $toolParams['query'] ?? '';
                            $runningPhrase = "Recalling \"{$q}\"...";
                            $completedPhrase = "Recalled.";
                            break;
                        case 'get_todoist_tasks':
                            $runningPhrase = "Checking agenda...";
                            $completedPhrase = "Agenda loaded.";
                            break;
                        case 'create_todoist_task':
                            $c = $toolParams['content'] ?? '';
                            $runningPhrase = "Scheduling \"{$c}\"...";
                            $completedPhrase = "Scheduled.";
                            break;
                        case 'update_todoist_task':
                            $runningPhrase = "Updating task...";
                            $completedPhrase = "Updated.";
                            break;
                        case 'delete_todoist_task':
                            $runningPhrase = "Removing task...";
                            $completedPhrase = "Removed.";
                            break;
                        case 'get_email_briefing':
                            $runningPhrase = "Checking emails...";
                            $completedPhrase = "Briefing compiled.";
                            break;
                        default:
                            $runningPhrase = "Processing...";
                            $completedPhrase = "Done.";
                            break;
                    }

                    $emit('tool_start', ['tool' => $toolName, 'label' => $runningPhrase]);

                    $singleJson = json_encode($toolParams);
                    $toolOutput = $this->toolExecutionService->processToolCall($singleJson, $sessionId, $currentMessages, $emit);
                    
                    $emit('tool_done', ['tool' => $toolName, 'label' => $completedPhrase]);
                    
                    $sanitized = preg_replace('/"tool"\s*:\s*"[^"]*",?\s*/', '', $toolOutput);
                    $sanitized = preg_replace('/,\s*"tool"\s*:\s*"[^"]*"/', '', $sanitized);
                    $combinedResults[] = $sanitized;

                    $emit('data_fetching', [
                        'tool' => $toolName,
                        'status' => 'success',
                        'label' => $completedPhrase,
                        'payload' => $sanitized
                    ]);
                }

                $combinedResultText = implode("\n\n", $combinedResults);

                $this->db->insert('chat_history', [
                    'session_id' => $sessionId,
                    'role' => 'system',
                    'message' => $combinedResultText,
                    'message_type' => 'data_fetching',
                    'tool_name' => $toolName,
                    'token_estimate' => (int)(mb_strlen($combinedResultText) / 4)
                ]);

                $updatedHistory = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
                // Rebuild system prompt without tool instructions now that tool data is in context
                $systemPrompt = $this->promptAssemblyService->buildSystemPrompt($condensedContext, $usedCache, $query, true);
                $currentMessages = $this->promptAssemblyService->buildMessagesArray($systemPrompt, $updatedHistory, $intent);
                
                $currentMessages = $this->cleanMessagesArray($currentMessages);
                
                $executionCount++;
            } else {
                $aiResponse = $aiRawResponse;
                break;
            }
        }

        $cleanResponse = $this->stripThinkingTags($aiResponse);
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
            'search_query' => $searchQuery,
            'cache_used' => $usedCache ? 1 : 0,
            'scraped_urls' => !empty($scrapedUrls) ? json_encode($scrapedUrls) : null
        ]);

        $finalHistory = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
        $totalSessionTokens = 0;
        foreach ($finalHistory as $row) {
            $totalSessionTokens += (int)($row['token_estimate'] ?? 0);
        }

        $emit('done', [
            'message' => $cleanResponse,
            'title' => $updatedTitle,
            'total_session_tokens' => $totalSessionTokens,
            'session_id' => $sessionId
        ]);

        return [
            'status' => 'success',
            'message' => $aiResponse,
            'title' => $updatedTitle,
            'meta' => [
                'search_triggered' => $searchQuery !== null,
                'search_query' => $searchQuery,
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
        if (count($history) > ($keepLimit * 2)) {
            $totalTokens = 0;
            foreach ($history as $row) {
                $totalTokens += (int)($row['token_estimate'] ?? 0);
            }

            $threshold = (int) Config::get('MEMORY_EXTRACTION_THRESHOLD_TOKENS', 15000);
            $triggerThreshold = $threshold * 0.8;

            if ($totalTokens >= $triggerThreshold) {
                $emit('limit_warning', [
                    'session_id' => $sessionId,
                    'total_tokens' => $totalTokens,
                    'threshold' => $threshold
                ]);
                return false;
            }
        }

        return true;
    }

    private function ensureSessionExists(int &$sessionId): void
    {
        if ($sessionId <= 0 || empty($this->db->selectSafe('chat_sessions', ['id' => $sessionId]))) {
            $this->db->insert('chat_sessions', [
                'title' => 'New Chat'
            ]);
            $sessionId = (int)$this->db->getConnection()->lastInsertId();
        }
    }

    private function autoGenerateTitle(int $sessionId, string $query, array $history, callable $emit): ?string
    {
        if (count($history) !== 1) {
            return null;
        }

        // Daily briefing gets a templated title
        if (preg_match('/\b(?:daily\s+)?brief(?:ing)?\b/i', $query) || stripos($query, 'email') !== false) {
            $newTitle = 'Daily Briefing - ' . date('l d/m/Y');
        } else {
            $query = trim(preg_replace('/^(force\s+(the\s+)?(web\s+)?search|search\s+for)\s+/i', '', $query));
            $newTitle = mb_substr($query, 0, 30);
            if (mb_strlen($query) > 30) $newTitle .= '...';
        }

        if (!empty($newTitle)) {
            $this->db->update('chat_sessions', ['title' => $newTitle], ['id' => $sessionId]);
            $emit('title_updated', ['title' => $newTitle]);
            return $newTitle;
        }

        return null;
    }

    private function streamAgentResponse(array $messages, callable $emit): string
    {
        $aiResponse = '';
        $utf8_buffer = '';
        $inJsonTool = false;
        $jsonBraceDepth = 0;
        $inThoughtTag = false;

        $this->agent->chat($messages, true, function($chunk, $type = 'content') use ($emit, &$aiResponse, &$utf8_buffer, &$inJsonTool, &$jsonBraceDepth, &$inThoughtTag) {
            if ($type === 'reasoning') {
                $emit('reasoning', ['chunk' => $chunk]);
                return;
            }

            $aiResponse .= $chunk;

            // Suppress Gemma thought tags and DeepSeek think tags
            if ($inThoughtTag) {
                if (str_contains($chunk, '<channel|>') || str_contains($chunk, '</think>')) {
                    $inThoughtTag = false;
                }
                return;
            }
            if (str_contains($chunk, '<|channel>thought') || str_contains($chunk, '<think>')) {
                $inThoughtTag = true;
                // Check if the tag closes in the same chunk
                if (str_contains($chunk, '<channel|>') || str_contains($chunk, '</think>')) {
                    $inThoughtTag = false;
                }
                return;
            }

            if ($inJsonTool) {
                // Track brace depth to detect end of JSON
                $jsonBraceDepth += substr_count($chunk, '{') - substr_count($chunk, '}');
                if ($jsonBraceDepth <= 0) {
                    $inJsonTool = false;
                    $jsonBraceDepth = 0;
                }
                return;
            }

            $utf8_buffer .= $chunk;

            if (mb_check_encoding($utf8_buffer, 'UTF-8')) {
                // Strip [System Routing Hint: ...] from output
                $clean = preg_replace('/\[System Routing Hint:[^\]]*\]/s', '', $utf8_buffer);
                if (empty(trim($clean))) {
                    $utf8_buffer = '';
                    return;
                }

                // If the clean buffer contains an opening brace '{', we might be starting a tool call.
                // We should defer emitting this buffer until we see either '"tool":' or confirm it's not a tool.
                if (str_contains($clean, '{')) {
                    if (preg_match('/"tool"\s*:/', $clean)) {
                        $inJsonTool = true;
                        // Find the opening { for the JSON object
                        $jsonStart = strrpos($clean, '{');
                        if ($jsonStart !== false) {
                            // Emit text before the JSON
                            $beforeJson = trim(substr($clean, 0, $jsonStart));
                            if (!empty($beforeJson)) {
                                $emit('token', ['chunk' => $beforeJson]);
                            }
                            $jsonPart = substr($clean, $jsonStart);
                            $jsonBraceDepth = substr_count($jsonPart, '{') - substr_count($jsonPart, '}');
                            // If JSON is fully contained, emit any text after the closing brace
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

                    // If the buffer grows large and hasn't matched '"tool":', it is standard text.
                    if (mb_strlen($clean) > 150) {
                        $emit('token', ['chunk' => $clean]);
                        $utf8_buffer = '';
                    }

                    // Wait for more chunks to verify tool presence
                    return;
                }

                $emit('token', ['chunk' => $clean]);
                $utf8_buffer = '';
            }
        });

        if (!empty($utf8_buffer) && !$inJsonTool) {
            $emit('token', ['chunk' => mb_convert_encoding($utf8_buffer, 'UTF-8', 'UTF-8')]);
        }

        return $aiResponse;
    }
}