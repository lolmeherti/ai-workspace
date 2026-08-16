<?php

namespace App\Services\Tools;

use App\Search\FileRetriever;

class SearchFilesTool
{
    private FileRetriever $retriever;

    public function __construct(
        private \App\Database $db,
        private \App\AgentManager $agent,
        private string $uploadDir,
        private TodoistApiClient $todoist
    ) {
        $this->retriever = new FileRetriever($db);
    }

    public function execute(array $toolData, int $sessionId, array $messages, callable $emit, string $cleanJson): string
    {
        // Accept queries array (merge into one keyword set) or single query string
        $queries = $toolData['queries'] ?? null;
        $toolQuery = is_array($queries) ? implode(' ', $queries) : ($toolData['query'] ?? '');
        \App\ProgressWriter::write($sessionId, 'search_querying', "Searching files for: {$toolQuery}", 'slate');

        $matchingFiles = $this->retriever->rank($toolQuery);

        $resultsTxt = "System search completed for '{$toolQuery}'. Matches found on disk:\n";

        if (empty($matchingFiles)) {
            \App\ProgressWriter::write($sessionId, 'search_no_results', "No files found matching: {$toolQuery}", 'rose');
            $resultsTxt .= "- No matching files found.\n";
        } else {
            \App\ProgressWriter::write($sessionId, 'search_done', count($matchingFiles) . ' files found.', 'emerald');
            $emit('file_choices', [
                'query' => $toolQuery,
                'files' => $matchingFiles
            ]);

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
