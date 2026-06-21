<?php

namespace App;

use App\Database;
use App\AgentManager;
use App\Agents\SemanticCacheEvaluator;
use App\Agents\ContextCondenser;
use App\Agents\SearchQueryRefiner;
use App\Config;
use App\Services\FileAttachmentService;
use App\Services\PromptAssemblyService;
use App\Services\ToolExecutionService;
use App\Services\TagParserService;
use App\Services\Tools\WebSearchTool;

class ChatManager
{
    private Database $db;
    private AgentManager $agent;
    private FileAttachmentService $fileAttachmentService;
    private WebSearchTool $webSearchTool;
    private PromptAssemblyService $promptAssemblyService;
    private ToolExecutionService $toolExecutionService;
    private string $uploadDir;

    public function __construct(
        Database $db,
        AgentManager $agent,
        ?SemanticCacheEvaluator $cacheEvaluator = null,
        ?ContextCondenser $contextCondenser = null
    ) {
        $this->db = $db;
        $this->agent = $agent;
        $this->uploadDir = Config::getProjectRoot() . '/uploads/';

        $this->fileAttachmentService = new FileAttachmentService($db, $agent, $this->uploadDir);
        $this->webSearchTool = new WebSearchTool($cacheEvaluator, $contextCondenser, new SearchQueryRefiner($agent));
        $this->promptAssemblyService = new PromptAssemblyService($this->db, $this->uploadDir);
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

    public function process(int $sessionId, string $query, ?array $imageFile, ?string $cacheAction = null, ?string $cacheKeyToUse = null, ?string $activeEditFile = null, ?callable $streamCallback = null): array
    {
        $executionTrace = [];
        $emit = function(string $event, array $data = []) use ($streamCallback, &$executionTrace) {
            if ($streamCallback !== null) {
                $streamCallback($event, $data);
            }
            if (in_array($event, ['title_updated', 'intent_result', 'tool_start', 'tool_done', 'search_decided', 'cache_used', 'search_no_results', 'context_assembled', 'scraping_start', 'scraping_done', 'condensing', 'trace'], true)) {
                $executionTrace[] = ['event' => $event, 'data' => $data];
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

        $tagParser = new TagParserService();
        $parsed = $tagParser->parse($query);
        $tags = $parsed['tags'];
        $query = $parsed['query']; // stripped of tags for storage and LLM context
        $displayTags = $parsed['displayTags'];

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
                'tags' => empty($displayTags) ? null : json_encode($displayTags),
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
        if (!empty($activeEditFile)) {
            $intent = 'none';
            $searchQuery = null;
        } else {
            $intents = [];
            foreach ($tags as $tag) {
                switch ($tag) {
                    case 'web_search':
                        $searchQuery = $query;
                        break;
                    case 'search_files':
                        $intents[] = 'search_files';
                        break;
                    case 'search_memories':
                        $intents[] = 'search_memories';
                        break;
                    case 'local_search':
                        $intents[] = 'search_files';
                        $intents[] = 'search_memories';
                        break;
                }
            }
            $intents = array_values(array_unique($intents));
            $intent = empty($intents) ? 'none' : implode(',', $intents);
        }

        $emit('intent_result', [
            'search_query' => $searchQuery,
            'intents' => $intent
        ]);

        // =========================================================================
        // TIERED TOOL PIPELINE (SHORT-CIRCUITING EXECUTOR)
        // =========================================================================
        $tier1Found = false;
        $tier2Found = false;

        $intentList = array_filter(array_map('trim', explode(',', $intent)));
        $searchTargetQuery = !empty($searchQuery) ? $searchQuery : $query;

        // ----------------- TIER 1: LOCAL DISK SEARCH (Lowest Risk) -----------------
        if (in_array('search_files', $intentList) || in_array('search_memories', $intentList)) {
            $emit('status', ['text' => 'Checking local storage...']);

            if (in_array('search_files', $intentList)) {
                $toolParams = ['tool' => 'search_files', 'query' => $searchTargetQuery];
                $emit('tool_start', ['tool' => 'search_files', 'label' => 'Searching local disk...']);
                
                $toolOutput = $this->toolExecutionService->processToolCall(json_encode($toolParams), $sessionId, $history, $emit);
                
                $emit('tool_done', ['tool' => 'search_files', 'label' => 'Files checked.']);

                // Always record the tool result so the AI/history knows the search happened
                $this->db->insert('chat_history', [
                    'session_id' => $sessionId,
                    'role' => 'system',
                    'message' => $toolOutput,
                    'message_type' => 'data_fetching',
                    'tool_name' => 'search_files',
                    'token_estimate' => (int)(mb_strlen($toolOutput) / 4)
                ]);

                if (stripos($toolOutput, 'No matching files') === false && stripos($toolOutput, 'error') === false && trim($toolOutput) !== '') {
                    $tier1Found = true;
                }
            }

            if (in_array('search_memories', $intentList)) {
                $toolParams = ['tool' => 'search_memories', 'query' => $searchTargetQuery];
                $emit('tool_start', ['tool' => 'search_memories', 'label' => 'Searching memories...']);
                
                $toolOutput = $this->toolExecutionService->processToolCall(json_encode($toolParams), $sessionId, $history, $emit);
                
                $emit('tool_done', ['tool' => 'search_memories', 'label' => 'Memories checked.']);

                // Always record the tool result so the AI/history knows the search happened
                $this->db->insert('chat_history', [
                    'session_id' => $sessionId,
                    'role' => 'system',
                    'message' => $toolOutput,
                    'message_type' => 'data_fetching',
                    'tool_name' => 'search_memories',
                    'token_estimate' => (int)(mb_strlen($toolOutput) / 4)
                ]);

                if (stripos($toolOutput, 'No specific relevant memories') === false && stripos($toolOutput, 'error') === false && trim($toolOutput) !== '') {
                    $tier1Found = true;
                }
            }
        }

        // ----------------- TIER 2: PERSONAL ACCOUNTS (Medium Risk) -----------------
        // We only proceed to Tier 2 if Tier 1 found nothing (or wasn't requested)
        if (!$tier1Found && (in_array('todoist_get', $intentList) || in_array('email_briefing', $intentList))) {
            $emit('status', ['text' => 'Checking personal agenda...']);

            if (in_array('todoist_get', $intentList)) {
                $toolParams = ['tool' => 'get_todoist_tasks'];
                $emit('tool_start', ['tool' => 'get_todoist_tasks', 'label' => 'Checking agenda...']);
                
                $toolOutput = $this->toolExecutionService->processToolCall(json_encode($toolParams), $sessionId, $history, $emit);
                
                $emit('tool_done', ['tool' => 'get_todoist_tasks', 'label' => 'Agenda loaded.']);

                if (stripos($toolOutput, 'No upcoming tasks') === false && stripos($toolOutput, 'error') === false && trim($toolOutput) !== '') {
                    $tier2Found = true;

                    $this->db->insert('chat_history', [
                        'session_id' => $sessionId,
                        'role' => 'system',
                        'message' => $toolOutput,
                        'message_type' => 'data_fetching',
                        'tool_name' => 'get_todoist_tasks',
                        'token_estimate' => (int)(mb_strlen($toolOutput) / 4)
                    ]);
                }
            }
        }

        // ----------------- TIER 3: LIVE WEB SEARCH (Highest Risk) -----------------
        // We ONLY execute the web search if neither Tier 1 nor Tier 2 yielded a positive local match
        $condensedContext = '';
        if (!$tier1Found && !$tier2Found && !empty($searchQuery)) {
            $emit('status', ['text' => 'Consulting external sources...']);
            $condensedContext = $this->webSearchTool->executeWebSearch(
                $query,
                $cacheAction,
                $cacheKeyToUse,
                $emit,
                $usedCache,
                $scrapedUrls,
                $sessionId,
                $history
            );

            if ($condensedContext === 'ASK_USER') {
                return [];
            }
        }

        // Re-read updated history (including any pre-emptively inserted tool outputs)
        $history = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);

        // Deterministic response for a pure @files tag: the tool already did the work,
        // so don't give the AI a chance to overthink or ignore the result.
        $isPureFileSearchTag = (count($tags) === 1 && $tags[0] === 'search_files');
        if ($isPureFileSearchTag) {
            $filesFound = false;
            foreach (array_reverse($history) as $msg) {
                if (($msg['message_type'] ?? '') === 'data_fetching' && ($msg['tool_name'] ?? '') === 'search_files') {
                    $filesFound = stripos($msg['message'], 'No matching files') === false;
                    break;
                }
            }

            $encodedQuery = json_encode($searchTargetQuery);
            $aiResponse = $filesFound
                ? "I found these files. Click **Append to Chat** on any file you want me to read or discuss.\n\n{\"tool\":\"search_files\",\"query\":{$encodedQuery}}"
                : "I didn't find any files matching \"{$searchTargetQuery}\".";

            $emit('status', ['text' => 'Done.']);
            $emit('token', ['chunk' => $aiResponse]);

            $this->db->insert('chat_history', [
                'session_id' => $sessionId,
                'role' => 'assistant',
                'message' => $aiResponse,
                'image_path' => null,
                'token_estimate' => (int)(mb_strlen($aiResponse) / 4),
                'search_query' => $searchQuery,
                'execution_trace' => empty($executionTrace) ? null : json_encode($executionTrace)
            ]);

            $finalHistory = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
            $totalSessionTokens = 0;
            foreach ($finalHistory as $row) {
                $totalSessionTokens += (int)($row['token_estimate'] ?? 0);
            }

            $emit('done', [
                'message' => $this->stripThinkingTags($aiResponse),
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

        $emit('status', ['text' => 'Assembling context...']);
        $systemPrompt = $this->promptAssemblyService->buildSystemPrompt($query, !empty($activeEditFile));
        $currentMessages = $this->promptAssemblyService->buildMessagesArray($systemPrompt, $history, $condensedContext, $usedCache);
        
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

        // If a tool has already been run pre-emptively on the backend, register it to prevent double calls
        if ($tier1Found) {
            if (in_array('search_files', $intentList)) {
                $calledTools[] = 'search_files';
            }
            if (in_array('search_memories', $intentList)) {
                $calledTools[] = 'search_memories';
            }
        }
        if ($tier2Found) {
            $calledTools[] = in_array('todoist_get', $intentList) ? 'get_todoist_tasks' : 'get_email_briefing';
        }

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
                    $aiResponse = \App\JsonParser::stripToolCallArtifacts($aiRawResponse);
                    foreach ($rawTools as $toolParams) {
                        $toolJson = json_encode($toolParams);
                        $aiResponse = str_replace($toolJson, '', $aiResponse);
                    }
                    $aiResponse = trim($aiResponse);
                    break;
                }

                $this->db->insert('chat_history', [
                        'session_id' => $sessionId,
                        'role' => 'assistant',
                        'message' => $aiRawResponse,
                        'message_type' => 'tool_call', // Standardizing on 'tool_call' so it's ignored in plaintext UI views
                        'token_estimate' => (int)(mb_strlen($aiRawResponse) / 4)
                    ]);

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
                $currentMessages = $this->promptAssemblyService->buildMessagesArray($systemPrompt, $updatedHistory, $condensedContext, $usedCache);
                
                $currentMessages = $this->cleanMessagesArray($currentMessages);
                
                $executionCount++;
            } else {
                $aiResponse = \App\JsonParser::stripToolCallArtifacts($aiRawResponse);
                break;
            }
        }

        // Keep the raw response (with thinking tags) in the database to prevent KV cache invalidation
        $cleanResponse = $aiResponse; 
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
            'scraped_urls' => !empty($scrapedUrls) ? json_encode($scrapedUrls) : null,
            'execution_trace' => empty($executionTrace) ? null : json_encode($executionTrace)
        ]);

        $finalHistory = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
        $totalSessionTokens = 0;
        foreach ($finalHistory as $row) {
            $totalSessionTokens += (int)($row['token_estimate'] ?? 0);
        }

        $emit('done', [
            'message' => $this->stripThinkingTags($cleanResponse), // Strips tags only for the final browser display
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
        $isStartOfResponse = true; // Tracks the absolute start of the textual response

        $this->agent->chat($messages, true, function($chunk, $type = 'content') use ($emit, &$aiResponse, &$utf8_buffer, &$inJsonTool, &$jsonBraceDepth, &$isStartOfResponse) {
            if ($type === 'reasoning') {
                $emit('reasoning', ['chunk' => $chunk]);
                return;
            }

            $aiResponse .= $chunk;

            // Suppress native tokenizer tool-call blocks (Gemma 4 / Qwen) from streaming to screen,
            // but preserve any text that appears before/after the wrapper so reasoning tags are not swallowed.
            $nativeOpenTags = ['<|tool_call|>', '<|tool_call>', '<tool_call|>', '<tool_call>'];
            $nativeCloseTags = ['<|tool_call|>', '<tool_call|>', '</tool_call>'];

            $openTagPos = false;
            $openTagLen = 0;
            foreach ($nativeOpenTags as $tag) {
                $pos = strpos($chunk, $tag);
                if ($pos !== false && ($openTagPos === false || $pos < $openTagPos)) {
                    $openTagPos = $pos;
                    $openTagLen = strlen($tag);
                }
            }

            $closeTagPos = false;
            $closeTagLen = 0;
            foreach ($nativeCloseTags as $tag) {
                $pos = strpos($chunk, $tag);
                if ($pos !== false && ($closeTagPos === false || $pos < $closeTagPos)) {
                    $closeTagPos = $pos;
                    $closeTagLen = strlen($tag);
                }
            }

            if ($openTagPos !== false && !$inJsonTool) {
                if ($openTagPos > 0) {
                    $utf8_buffer .= substr($chunk, 0, $openTagPos);
                }
                $inJsonTool = true;
                $afterOpen = substr($chunk, $openTagPos + $openTagLen);
                foreach ($nativeCloseTags as $tag) {
                    $innerClose = strpos($afterOpen, $tag);
                    if ($innerClose !== false) {
                        $inJsonTool = false;
                        $afterClose = substr($afterOpen, $innerClose + strlen($tag));
                        if (!empty($afterClose)) {
                            $utf8_buffer .= $afterClose;
                        }
                        break;
                    }
                }
                return;
            }

            if ($closeTagPos !== false && $inJsonTool) {
                $inJsonTool = false;
                $afterClose = substr($chunk, $closeTagPos + $closeTagLen);
                if (!empty($afterClose)) {
                    $utf8_buffer .= $afterClose;
                }
                return;
            }

            if ($inJsonTool) {
                // Track brace depth to detect end of JSON standard tool calls
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
                if ($clean === '') { 
                    $utf8_buffer = '';
                    return;
                }

                // If the clean buffer contains an opening brace '{', we might be starting a tool call.
                // We should defer emitting this buffer until we see either '"tool"\s*:' or confirm it's not a tool.
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

                // If this is the absolute start of the textual response, strip accidental backticks
                if ($isStartOfResponse && !empty(trim($clean))) {
                    $trimmedClean = ltrim($clean);
                    if (str_starts_with($trimmedClean, '`')) {
                        // Strip leading backticks (e.g. ``` or `)
                        $clean = preg_replace('/^`+/s', '', $trimmedClean);
                        // Strip optional language specifiers (e.g. 'markdown', 'text', 'html')
                        $clean = preg_replace('/^(markdown|text|html|txt)?\s+/si', '', $clean);
                    }
                    
                    // ONLY set isStartOfResponse to false if we have finally outputted actual text.
                    // If the chunk was just backticks or is empty, we keep isStartOfResponse as true.
                    if (trim($clean) !== '' && !str_starts_with(trim($clean), '`')) {
                        $isStartOfResponse = false;
                    }
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