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
use App\Search;
use App\Scraper;
use App\Cache;

class ChatManager
{
    private Database $db;
    private AgentManager $agent;
    private ?MemoryExtractor $memoryExtractor;
    private ?MemorySelector $memorySelector;
    private ?SearchDecider $searchDecider;
    private ?SemanticCacheEvaluator $cacheEvaluator;
    private ?ContextCondenser $contextCondenser;
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
        $this->memoryExtractor = $memoryExtractor;
        $this->memorySelector = $memorySelector;
        $this->searchDecider = $searchDecider;
        $this->cacheEvaluator = $cacheEvaluator;
        $this->contextCondenser = $contextCondenser;
        $this->uploadDir = __DIR__ . '/../uploads/';
    }

    public function process(int $sessionId, string $query, ?array $imageFile, ?string $cacheAction = null, ?string $cacheKeyToUse = null): array
    {
        $imagePath = null;
        $updatedTitle = null;
        
        if ($sessionId <= 0 || empty($this->db->selectSafe('chat_sessions', ['id' => $sessionId]))) {
            $this->db->insert('chat_sessions', [
                'title' => 'New Chat'
            ]);

            $sessionId = (int)$this->db->getConnection()->lastInsertId();
        }

        if (empty($cacheAction)) {
            if ($imageFile && $imageFile['error'] === UPLOAD_ERR_OK) {
                if (!is_dir($this->uploadDir)) {
                    mkdir($this->uploadDir, 0755, true);
                }
                $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9.\-_]/', '', basename($imageFile['name']));
                $dest = $this->uploadDir . $filename;
                if (move_uploaded_file($imageFile['tmp_name'], $dest)) {
                    $imagePath = 'uploads/' . $filename;
                }
            }

            if ($this->memoryExtractor) {
                $this->memoryExtractor->runIfThresholdMet($sessionId);
            }

            $this->db->insert('chat_history', [
                'session_id' => $sessionId,
                'role' => 'user',
                'message' => $query,
                'image_path' => $imagePath,
                'token_estimate' => (int)(mb_strlen($query) / 4)
            ]);
        }

        $history = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);

        if (count($history) === 1 && empty($cacheAction)) {
            $titlePrompt = [
                ['role' => 'system', 'content' => 'You are a title generator. Generate an extremely short 2-3 word title representing the user query. Do not use quotes, markdown, punctuation, or explanations. Keep it under 20 characters.'],
                ['role' => 'user', 'content' => $query]
            ];
            $temperature = (float) Config::get('AGENT_TITLE_TEMP', 0.4);
            $newTitle = trim($this->agent->chat($titlePrompt, false, null, $temperature));
            if (!empty($newTitle) && strlen($newTitle) < 50) {
                $this->db->update('chat_sessions', ['title' => $newTitle], ['id' => $sessionId]);
                $updatedTitle = $newTitle;
            }
        }

        $searchQuery = null;
        $condensedContext = '';
        $usedCache = false;
        
        if ($this->searchDecider && $this->cacheEvaluator && $this->contextCondenser) {
            $searchQuery = $this->searchDecider->requiresSearch($query);
            
            if ($searchQuery) {
                if ($cacheAction === 'use_cache' && !empty($cacheKeyToUse)) {
                    $condensedContext = Cache::get($cacheKeyToUse) ?? '';
                    $usedCache = !empty($condensedContext);
                } elseif ($cacheAction !== 'force_live') {
                    $ledger = Cache::getSearchLedger();
                    $evaluation = $this->cacheEvaluator->evaluate($searchQuery, $ledger);
                    
                    if ($evaluation) {
                        if ($evaluation['decision'] === 'ASK_USER') {
                            return [
                                'status' => 'ask_user',
                                'cache_key' => $evaluation['cache_key'],
                                'query_text' => $evaluation['query'],
                                'session_id' => $sessionId
                            ];
                        }
                        
                        if ($evaluation['decision'] === 'AUTO_USE') {
                            $condensedContext = Cache::get($evaluation['cache_key']) ?? '';
                            $usedCache = !empty($condensedContext);
                        }
                    }
                }
                
                if (!$usedCache && empty($condensedContext)) {
                    $limit = (int) Config::get('MAX_SEARCH_RESULTS_TO_SCRAPE', 3);
                    $results = Search::query($searchQuery, $limit);
                    
                    $scrapedPages = [];
                    foreach ($results as $url) {
                        $scrapedPages[] = Scraper::fetchAndClean($url);
                    }
                    
                    if (!empty($scrapedPages)) {
                        $condensedContext = $this->contextCondenser->condense($scrapedPages, $query);
                        
                        $newCacheKey = 'ctx_' . md5($searchQuery . time());
                        Cache::set($newCacheKey, $condensedContext);
                        Cache::addToLedger($searchQuery, $newCacheKey);
                    }
                }
            }
        }

        $currentDate = date('F j, Y');
        $cutoffDate = 'early 2024';
        
        $systemPrompt = "You are a helpful, friendly, and highly intelligent AI conversational assistant. " .
                        "Today's date is {$currentDate}. " .
                        "Your internal knowledge cutoff is {$cutoffDate}. " .
                        "Please be aware of this gap when answering questions about current events.";

        if (!empty($condensedContext)) {
            $systemPrompt .= "\n\nLIVE WEB SEARCH CONTEXT:\n" . $condensedContext;
            if ($usedCache) {
                $systemPrompt .= "\n(Note: This context was retrieved from your recent semantic memory cache).";
            }
        }

        if ($this->memorySelector) {
            $relevantMemories = $this->memorySelector->selectRelevantMemory($query);
            if (!empty($relevantMemories)) {
                $systemPrompt .= "\n\nRelevant details you recall about the user:\n" . $relevantMemories;
            }
        }

        $messages = [];
        $messages[] = [
            'role' => 'system',
            'content' => $systemPrompt
        ];

        $rollingLimit = (int) Config::get('CHAT_ROLLING_WINDOW_LIMIT', 15);
        $recentHistory = array_slice($history, -$rollingLimit);

        foreach ($recentHistory as $row) {
            if (!empty($row['image_path']) && file_exists(__DIR__ . '/../' . $row['image_path'])) {
                $mimeType = mime_content_type(__DIR__ . '/../' . $row['image_path']);
                $base64   = base64_encode(file_get_contents(__DIR__ . '/../' . $row['image_path']));
                $messages[] = [
                    'role' => $row['role'],
                    'content' => [
                        ['type' => 'text', 'text' => $row['message']],
                        ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"]]
                    ]
                ];
            } else {
                $messages[] = [
                    'role' => $row['role'],
                    'content' => $row['message']
                ];
            }
        }

        $aiResponse = $this->agent->chat($messages, false);

        $this->db->insert('chat_history', [
            'session_id' => $sessionId,
            'role' => 'assistant',
            'message' => $aiResponse,
            'image_path' => null,
            'token_estimate' => (int)(mb_strlen($aiResponse) / 4)
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
}