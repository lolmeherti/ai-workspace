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

                $threshold = (int) Config::get('MEMORY_EXTRA_THRESHOLD_TOKENS', 15000);
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
                
                $timestamp = time();
                $filename = $timestamp . '_' . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9.\-_]/', '', basename($imageFile['name']));
                $dest = $this->uploadDir . $filename;
                
                if (move_uploaded_file($imageFile['tmp_name'], $dest)) {
                    $imagePath = 'uploads/' . $filename;
                    $mimeType = @mime_content_type($dest) ?: 'application/octet-stream';
                    $fileType = str_starts_with($mimeType, "image/") ? 'image' : 'document';
                    $extractedText = null;
                    
                    if ($fileType !== 'image') {
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

                    $generatedTitle = 'Untitled File';
                    if ($fileType === 'image') {
                        $base64 = base64_encode(file_get_contents($dest));
                        $imageMessages = [
                            [
                                'role' => 'system',
                                'content' => "Generate a natural, clear title for this image file that would make it easy to find later if searched for. Do not include markdown, explanations, or introductory text."
                            ],
                            [
                                'role' => 'user',
                                'content' => [
                                    ['type' => 'text', 'text' => "What is in this image? Provide a clear title/description."],
                                    ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"]]
                                ]
                            ]
                        ];
                        try {
                            $generatedTitle = trim($this->agent->chat($imageMessages, false, null, 0.3));
                        } catch (\Throwable $e) {
                            $generatedTitle = "Image: " . basename($imageFile['name']);
                        }
                    } else {
                        if ($extractedText !== null && trim($extractedText) !== "") {
                            $snippet = mb_substr($extractedText, 0, 1000);
                            $docMessages = [
                                [
                                    'role' => 'system',
                                    'content' => "If you were to name this file based on this snippet, what would the most natural, clear title be? Generate a single descriptive sentence summarizing exactly what this document is (e.g., 'this document is a resume of x profession with x years of experience'). Do not include markdown, explanations, or introductory text."
                                ],
                                [
                                    'role' => 'user',
                                    'content' => $snippet
                                ]
                            ];
                            try {
                                $generatedTitle = trim($this->agent->chat($docMessages, false, null, 0.3));
                            } catch (\Throwable $e) {
                                $generatedTitle = "Document: " . basename($imageFile['name']);
                            }
                        } else {
                            $generatedTitle = "Document: " . basename($imageFile['name']);
                        }
                    }

                    $this->db->insert('uploaded_files', [
                        'session_id' => $sessionId,
                        'original_name' => basename($imageFile['name']),
                        'physical_name' => $filename,
                        'generated_title' => $generatedTitle,
                        'file_type' => $fileType
                    ]);
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
You are a helpful, friendly, and highly intelligent AI conversational assistant. Today's date is {$currentDate}. Your internal knowledge cutoff is {$cutoffDate}. You are being supplemented with up to date data from a third party source via content injection. 
This is what keeps you up to date. If you see data from what you perceive to be the future, don't worry about it and understand that its relatively reliable data.

If the user asks for a file, asks to recall a document/image, or asks if you have a file on disk, do not make assumptions. You can search the database of uploaded files by outputting a JSON block with the following exact format as your ONLY output:
{"tool": "search_files", "query": "<search keyword>"}
Keep your query brief and natural (e.g., 'resume' or 'invoice').
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

            if (preg_match_all('/\[File:\s*([a-zA-Z0-9._\-]+)\]/', $messageContent, $matches)) {
                foreach ($matches[1] as $idx => $matchedFilename) {
                    $fullFilePath = $this->uploadDir . $matchedFilename;
                    $txtPath = $fullFilePath . '.txt';
                    
                    if (file_exists($txtPath)) {
                        $docText = file_get_contents($txtPath);
                        $messageContent .= "\n\n[Referenced File Content for {$matchedFilename}]:\n{$docText}\n[End of Referenced File Content]";
                    } elseif (file_exists($fullFilePath)) {
                        $mimeType = @mime_content_type($fullFilePath) ?: 'application/octet-stream';
                        if (str_starts_with($mimeType, "image/")) {
                            $hasImage = true;
                            $base64 = base64_encode(file_get_contents($fullFilePath));
                            $messages[] = [
                                'role' => $row['role'],
                                'content' => [
                                    ['type' => 'text', 'text' => str_replace($matches[0][$idx], "", $messageContent)],
                                    ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"]]
                                ]
                            ];
                        }
                    }
                }
            }

            if (!$hasImage) {
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

        $isToolCall = false;
        $toolQuery = '';
        
        if (preg_match('/\{\s*"[^"]+"\s*:/', $aiResponse, $matches, PREG_OFFSET_CAPTURE)) {
            $startIndex = $matches[0][1];
            $endIndex = strrpos($aiResponse, '}');
            
            if ($endIndex !== false && $endIndex > $startIndex) {
                $jsonStr = substr($aiResponse, $startIndex, $endIndex - $startIndex + 1);
                $decoded = @json_decode($jsonStr, true);
                
                if (is_array($decoded) && isset($decoded['tool']) && $decoded['tool'] === 'search_files') {
                    $isToolCall = true;
                    $toolQuery = $decoded['query'] ?? '';
                }
            }
        }

        if ($isToolCall) {
            try {
                $searchPattern = '%' . $toolQuery . '%';
                $matchingFiles = $this->db->query(
                    "SELECT id, original_name, physical_name, generated_title, file_type, uploaded_at 
                     FROM uploaded_files 
                     WHERE generated_title LIKE :q1 OR original_name LIKE :q2 
                     ORDER BY uploaded_at DESC 
                     LIMIT 5",
                    [
                        ':q1' => $searchPattern,
                        ':q2' => $searchPattern
                    ]
                );

                $resultsTxt = "System search completed for '{$toolQuery}'. Matches found on disk:\n";
                $injectedContexts = [];
                $imageAttachments = [];

                if (empty($matchingFiles)) {
                    $resultsTxt .= "- No matching files found.\n";
                } else {
                    foreach ($matchingFiles as &$f) {
                        $f['preview'] = '';
                        $resultsTxt .= "- File: \"{$f['generated_title']}\" (Original Name: {$f['original_name']}, Physical Path: uploads/{$f['physical_name']}, Uploaded at: {$f['uploaded_at']}, Type: {$f['file_type']})\n";

                        if ($f['file_type'] !== 'image') {
                            $txtPath = $this->uploadDir . $f['physical_name'] . '.txt';
                            if (file_exists($txtPath)) {
                                $docText = file_get_contents($txtPath);
                                $f['preview'] = mb_substr($docText, 0, 300, 'UTF-8');
                                $injectedContexts[] = "[Content of Found Document \"{$f['generated_title']}\" (uploads/{$f['physical_name']})]:\n{$docText}\n[End Document Content]";
                            }
                        } else {
                            $imgPath = $this->uploadDir . $f['physical_name'];
                            if (file_exists($imgPath)) {
                                $mimeType = @mime_content_type($imgPath) ?: 'image/jpeg';
                                $base64 = base64_encode(file_get_contents($imgPath));
                                $imageAttachments[] = [
                                    'mime_type' => $mimeType,
                                    'base64' => $base64,
                                    'physical_name' => $f['physical_name'],
                                    'title' => $f['generated_title']
                                ];
                            }
                        }
                    }
                    unset($f);
                }

                $instructions = "Below are the results of the file search you just requested.\n\n{$resultsTxt}\n";

                if (!empty($injectedContexts)) {
                    $instructions .= "\n[SYSTEM NOTE]: The content of matching text documents has been extracted and supplied below:\n" . implode("\n\n", $injectedContexts) . "\n";
                }

                if (!empty($imageAttachments)) {
                    $instructions .= "\n[SYSTEM NOTE]: The matching image files have been projected directly to your vision context. You can see them fully.\n";
                }

                $instructions .= "\nCRITICAL RESPONSE INSTRUCTIONS:\n" .
                                 "1. Do not ask the user to upload or reference/attach the files. You already have full access to their contents/images right now in this context.\n" .
                                 "2. Analyze the supplied files and answer the user's original query fully and directly.\n" .
                                 "3. You MUST refer to the file(s) in your response using the exact bracketed tag format `[File: physical_name]` (e.g., '[File: 1780675239_image.jpeg]'). This tag is caught by the frontend parser to automatically display the actual image or file card inline to the user.\n" .
                                 "4. Keep your answer professional, direct, and concise.";

                $messages[] = ['role' => 'assistant', 'content' => $aiResponse];

                if (!empty($imageAttachments)) {
                    $contentItems = [
                        ['type' => 'text', 'text' => $instructions]
                    ];
                    foreach ($imageAttachments as $img) {
                        $contentItems[] = [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$img['mime_type']};base64,{$img['base64']}"
                            ]
                        ];
                    }
                    $messages[] = [
                        'role' => 'system',
                        'content' => $contentItems
                    ];
                } else {
                    $messages[] = [
                        'role' => 'system',
                        'content' => $instructions
                    ];
                }

                $emit('token', ['chunk' => "\n\nChecking files..."]);

                $aiCommentary = '';
                $commentaryBuffer = '';

                $this->agent->chat($messages, true, function($chunk) use ($emit, &$aiCommentary, &$commentaryBuffer) {
                    $aiCommentary .= $chunk;
                    $commentaryBuffer .= $chunk;

                    if (mb_check_encoding($commentaryBuffer, 'UTF-8')) {
                        $emit('token', ['chunk' => $commentaryBuffer]);
                        $commentaryBuffer = '';
                    }
                });

                if (!empty($commentaryBuffer)) {
                    $emit('token', ['chunk' => mb_convert_encoding($commentaryBuffer, 'UTF-8', 'UTF-8')]);
                }

                $aiResponse .= "\n\n" . $aiCommentary;

                $emit('file_choices', [
                    'files' => $matchingFiles,
                    'query' => $toolQuery
                ]);
            } catch (\Throwable $e) {
                $emit('token', ['chunk' => "\n\n**System Error retrieving files:** " . $e->getMessage()]);
            }
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