<?php

namespace App\Services\Tools;

use App\AgentManager;

class SearchFilesTool
{

    public function __construct(
        private \App\Database $db,
        private \App\AgentManager $agent,
        private string $uploadDir,
        private TodoistApiClient $todoist
    ) {
    }

    public function execute(array $toolData, int $sessionId, array $messages, callable $emit, string $cleanJson): string
    {
                $toolQuery = $toolData['query'] ?? '';
                $cleanQuery = str_replace([',', ';', '/', '-', '_'], ' ', $toolQuery);
                $cleanQuery = preg_replace('/[^\p{L}\p{N}\s]/u', '', $cleanQuery);
                $keywords = array_filter(explode(' ', $cleanQuery));
                
                $sql = "SELECT id, original_name, physical_name, generated_title, file_type, uploaded_at FROM uploaded_files";
                $conditions = [];
                $params = [];
                
                if (!empty($keywords)) {
                    $idx = 0;
                    foreach ($keywords as $word) {
                        if (mb_strlen($word) < 2) continue;
                        $conditions[] = "(generated_title LIKE :w{$idx} OR original_name LIKE :o{$idx})";
                        $params[":w{$idx}"] = "%{$word}%";
                        $params[":o{$idx}"] = "%{$word}%";
                        $idx++;
                    }
                }

                if (!empty($conditions)) {
                    $sql .= " WHERE " . implode(" OR ", $conditions);
                }
                
                $sql .= " ORDER BY uploaded_at DESC LIMIT 5";
                $matchingFiles = $this->db->query($sql, $params);

                $resultsTxt = "System search completed for '{$toolQuery}'. Matches found on disk:\n";
                $injectedContexts = [];

                if (empty($matchingFiles)) {
                    $resultsTxt .= "- No matching files found.\n";
                } else {
                    if (count($matchingFiles) > 1) {
                        $emit('file_choices', [
                            'query' => $toolQuery,
                            'files' => $matchingFiles
                        ]);
                    }

                    foreach ($matchingFiles as &$f) {
                        $f['preview'] = '';
                        $resultsTxt .= "- File: \"{$f['generated_title']}\" (Original Name: {$f['original_name']}, Physical Path: uploads/{$f['physical_name']}, Uploaded at: {$f['uploaded_at']}, Type: {$f['file_type']})\n";

                        if ($f['file_type'] !== 'image') {
                            $txtPath = $this->uploadDir . $f['physical_name'] . '.txt';
                            if (file_exists($txtPath)) {
                                $docText = file_get_contents($txtPath);
                                $f['preview'] = mb_substr($docText, 0, 300, 'UTF-8');
                                $injectedContexts[] = "[Content of Found Document \"{$f['generated_title']}\" (uploads/{$f['physical_name']})]:\n{$docText}\n[End Document Content]\n";
                            }
                        }
                    }
                    unset($f);
                }

                $instructions = <<<TEXT
Below are the results of the file search you just requested.

{$resultsTxt}
TEXT;

                if (!empty($injectedContexts)) {
                    $injectedContextsStr = implode("\n\n", $injectedContexts);
                    $instructions .= <<<TEXT


[SYSTEM NOTE]: The content of matching text documents has been extracted and supplied below:
{$injectedContextsStr}
[End Document Content]
TEXT;
                }

                $instructions .= <<<TEXT


CRITICAL RESPONSE INSTRUCTIONS:
1. For text documents: their full content has been injected above. Read it and answer the user's query directly.
2. For image files: you can see only the file title and filename — you CANNOT see image content. Tell the user which relevant images you found by their title. The user can click "Append to Chat" to send an image to you for analysis.
3. Keep your answer professional, direct, and concise.
TEXT;

                return $instructions;
    }
}
