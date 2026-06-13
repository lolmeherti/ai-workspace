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
        $this->uploadDir = __DIR__ . '/../uploads/';

        $this->fileAttachmentService = new FileAttachmentService($db, $agent, $this->uploadDir);
        $this->webSearchService = new WebSearchService($searchDecider, $cacheEvaluator, $contextCondenser);
        $this->promptAssemblyService = new PromptAssemblyService($memorySelector, $this->uploadDir);
        $this->toolExecutionService = new ToolExecutionService($db, $agent, $this->uploadDir);
    }

    public function process(int $sessionId, string $query, ?array $imageFile, ?string $cacheAction = null, ?string $cacheKeyToUse = null, ?callable $streamCallback = null): array
    {
        $emit = function(string $event, array $data = []) use ($streamCallback) {
            if ($streamCallback !== null) {
                $streamCallback($event, $data);
            }
        };

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
            $updatedTitle = $this->autoGenerateTitle($sessionId, $query, $history, $emit);
        }

        $usedCache = false;
        $scrapedUrls = [];
        $searchQuery = null;

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

        $intent = 'none';
        if (empty($cacheAction)) {
            $routerPrompt = $this->promptAssemblyService->buildRouterPrompt($query);
            $routerMessages = [
                ['role' => 'system', 'content' => $routerPrompt]
            ];
            $intentRaw = $this->agent->chat($routerMessages, false, null, 0.1);
            
            $validIntents = [
                'search_files',
                'todoist_create',
                'todoist_get',
                'todoist_update',
                'todoist_delete',
                'email_briefing'
            ];
            
            $foundIntents = [];
            foreach ($validIntents as $vi) {
                if (stripos($intentRaw, $vi) !== false) {
                    $foundIntents[] = $vi;
                }
            }
            
            $intent = empty($foundIntents) ? 'none' : implode(',', $foundIntents);
        }

        $systemPrompt = $this->promptAssemblyService->buildSystemPrompt($condensedContext, $usedCache, $query);
        $messages = $this->promptAssemblyService->buildMessagesArray($systemPrompt, $history, $intent);

        $emit('generating', []);

        $aiResponse = $this->streamAgentResponse($messages, $emit);
        $aiResponse = $this->toolExecutionService->processToolCall($aiResponse, $sessionId, $messages, $emit);

        $usage = $this->agent->lastUsage;
        $assistantTokens = (int)(mb_strlen($aiResponse) / 4);

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
            'message' => $aiResponse,
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
            'message' => $aiResponse,
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

        $titlePromptText = <<<TEXT
You are a title generator. Generate an extremely short 2-3 word title representing the user query. Do not use quotes, markdown, punctuation, or explanations. Keep it under 20 characters.
TEXT;

        $titlePrompt = [
            ['role' => 'system', 'content' => $titlePromptText],
            ['role' => 'user', 'content' => $query]
        ];

        $temperature = (float) Config::get('AGENT_TITLE_TEMP', 0.4);
        $newTitle = trim($this->agent->chat($titlePrompt, false, null, $temperature));

        if (!empty($newTitle) && strlen($newTitle) < 50) {
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

        $this->agent->chat($messages, true, function($chunk) use ($emit, &$aiResponse, &$utf8_buffer) {
            $aiResponse .= $chunk;
            $utf8_buffer .= $chunk;

            if (mb_check_encoding($utf8_buffer, 'UTF-8')) {
                $emit('token', ['chunk' => $utf8_buffer]);
                $utf8_buffer = '';
            }
        });

        if (!empty($utf8_buffer)) {
            $emit('token', ['chunk' => mb_convert_encoding($utf8_buffer, 'UTF-8', 'UTF-8')]);
        }

        return $aiResponse;
    }
}