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

    public function process(int $sessionId, string $query, ?array $imageFile, ?string $cacheAction = null, ?string $cacheKeyToUse = null, ?callable $streamCallback = null): array
    {
        $emit = function(string $event, array $data = []) use ($streamCallback) {
            if ($streamCallback !== null) {
                $streamCallback($event, $data);
            }
        };

        $bypassWarning = (int)($_POST['bypass_warning'] ?? 0);
        if (empty($cacheAction) && !$bypassWarning) {
            $history = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
            
            if (count($history) > 6) {
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
                    return [
                        'status' => 'warning',
                        'message' => 'Token limit warning triggered'
                    ];
                }
            }
        }

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
                    $mimeType = @mime_content_type($dest) ?: 'application/octet-stream';
                    
                    if (!str_starts_with($mimeType, "image/")) {
                        $extractedText = null;
                        
                        try {
                            if (!class_exists('\App\FileExtractor') && file_exists(__DIR__ . '/FileExtractor.php')) {
                                require_once __DIR__ . '/FileExtractor.php';
                            }
                            
                            if (class_exists('\App\FileExtractor')) {
                                $extractedText = \App\FileExtractor::extractText($dest, $imageFile['name']);
                            } else {
                                $extractedText = "[System Error: FileExtractor missing]";
                            }
                        } catch (\Throwable $e) {
                            $extractedText = "[System Error parsing document: " . $e->getMessage() . "]";
                        }

                        if ($extractedText !== null && trim($extractedText) !== "") {
                            if (mb_strlen($extractedText) > 40000) {
                                $extractedText = mb_substr($extractedText, 0, 40000) . "\n\n... [Content truncated due to length limits]";
                            }
                            file_put_contents($dest . '.txt', $extractedText);
                        } else {
                            file_put_contents($dest . '.txt', "[Could not extract text content]");
                        }
                    }
                }
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
                $updatedTitle = $newTitle;
                $emit('title_updated', ['title' => $newTitle]);
            }
        }

        $searchQuery = null;
        $condensedContext = '';
        $usedCache = false;
        $scrapedUrls = [];
        
        if ($this->searchDecider && $this->cacheEvaluator && $this->contextCondenser) {
            $searchQuery = $this->searchDecider->requiresSearch($query, $history);
            
            if ($searchQuery) {
                $emit('search_decided', ['query' => $searchQuery]);

                if ($cacheAction === 'use_cache' && !empty($cacheKeyToUse)) {
                    $condensedContext = Cache::get($cacheKeyToUse) ?? '';
                    $usedCache = !empty($condensedContext);
                    if ($usedCache) {
                        $emit('cache_used', []);
                    }
                } elseif ($cacheAction !== 'force_live') {
                    $ledger = Cache::getSearchLedger();
                    $evaluation = $this->cacheEvaluator->evaluate($searchQuery, $ledger);
                    
                    if ($evaluation) {
                        if ($evaluation['decision'] === 'ASK_USER') {
                            $emit('ask_user', [
                                'cache_key' => $evaluation['cache_key'],
                                'query_text' => $evaluation['query'],
                                'session_id' => $sessionId
                            ]);
                            return [];
                        }
                        
                        if ($evaluation['decision'] === 'AUTO_USE') {
                            $condensedContext = Cache::get($evaluation['cache_key']) ?? '';
                            $usedCache = !empty($condensedContext);
                            if ($usedCache) {
                                $emit('cache_used', []);
                            }
                        }
                    }
                }
                
                if (!$usedCache && empty($condensedContext)) {
                    $limit = (int) Config::get('MAX_SEARCH_RESULTS_TO_SCRAPE', 3);
                    $results = Search::query($searchQuery, $limit);
                    $scrapedUrls = $results;
                    
                    $scrapedPages = [];
                    foreach ($results as $url) {
                        $emit('scraping_start', ['url' => $url]);
                        $scrapedPages[] = Scraper::fetchAndClean($url);
                        $emit('scraping_done', ['url' => $url]);
                    }
                    
                    if (!empty($scrapedPages)) {
                        $emit('condensing', []);
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
        
        $systemPrompt = <<<TEXT
You are a helpful, friendly, and highly intelligent AI conversational assistant. Today's date is {$currentDate}. Your internal knowledge cutoff is {$cutoffDate}. Please be aware of this gap when answering questions about current events.
TEXT;

        if (!empty($condensedContext)) {
            $systemPrompt .= <<<TEXT


LIVE WEB SEARCH CONTEXT:
{$condensedContext}
TEXT;
            if ($usedCache) {
                $systemPrompt .= <<<TEXT

(Note: This context was retrieved from your recent semantic memory cache).
TEXT;
            }
        }

        if ($this->memorySelector) {
            $relevantMemories = $this->memorySelector->selectRelevantMemory($query);
            if (!empty($relevantMemories)) {
                $systemPrompt .= <<<TEXT


Relevant details you recall about the user:
{$relevantMemories}
TEXT;
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
            $hasImage = false;
            $messageContent = $row['message'];

            if (!empty($row['image_path']) && file_exists(__DIR__ . '/../' . $row['image_path'])) {
                $fullFilePath = __DIR__ . '/../' . $row['image_path'];
                $mimeType = @mime_content_type($fullFilePath) ?: 'application/octet-stream';
                
                if (str_starts_with($mimeType, "image/")) {
                    $hasImage = true;
                    $base64   = base64_encode(file_get_contents($fullFilePath));
                    $messages[] = [
                        'role' => $row['role'],
                        'content' => [
                            ['type' => 'text', 'text' => $messageContent],
                            ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"]]
                        ]
                    ];
                } else {
                    $txtPath = $fullFilePath . '.txt';
                    if (file_exists($txtPath)) {
                        $docText = file_get_contents($txtPath);
                        $cleanFileName = preg_replace('/^[a-z0-9]+_/', '', basename($row['image_path']));

                        $messageContent = <<<TEXT
[Attached Document: {$cleanFileName}] {$docText} {$messageContent}
TEXT;
                    }
                }
            }

            if (!$hasImage) {
                $messages[] = [
                    'role' => $row['role'],
                    'content' => $messageContent
                ];
            }
        }

        $emit('generating', []);

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

        $usage = $this->agent->lastUsage;
        $userTokens = (int)(mb_strlen($query) / 4);
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
            'total_session_tokens' => $totalSessionTokens
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