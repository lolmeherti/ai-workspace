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

                if (empty($matchingFiles)) {
                    $resultsTxt .= "- No matching files found.\n";
                } else {
                    if (count($matchingFiles) >= 1) {
                        $emit('file_choices', [
                            'query' => $toolQuery,
                            'files' => $matchingFiles
                        ]);
                    }

                    foreach ($matchingFiles as &$f) {
                        $f['preview'] = '';
                        $resultsTxt .= "- File: \"{$f['generated_title']}\" (Original Name: {$f['original_name']}, Type: {$f['file_type']})\n";
                    }
                    unset($f);
                }

                $instructions = <<<TEXT
Below are the results of the file search you just requested.

{$resultsTxt}
TEXT;

                $instructions .= <<<TEXT


INSTRUCTIONS:
1. Tell the user which files you found by their title.
2. If the user asked a specific question that the file could answer, tell them to click "Append to Chat" on the relevant file so you can read its contents.
3. Keep your answer professional, direct, and concise.
TEXT;

                return $instructions;
    }
}
