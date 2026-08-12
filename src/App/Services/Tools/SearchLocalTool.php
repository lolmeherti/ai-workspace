<?php

namespace App\Services\Tools;

class SearchLocalTool
{
    private SearchFilesTool $filesTool;
    private SearchMemoriesTool $memoriesTool;

    public function __construct(
        private \App\Database $db,
        private \App\AgentManager $agent,
        private string $uploadDir,
    ) {
        $this->filesTool = new SearchFilesTool($db, $agent, $uploadDir, new TodoistApiClient());
        $this->memoriesTool = new SearchMemoriesTool($db, $agent);
    }

    public function execute(array $queries, int $sessionId, array $messages, callable $emit): string
    {
        // Files: merge all query terms into one keyword set.
        // File search is keyword-based LIKE matching — separate queries
        // don't improve recall, they just multiply SQL queries.
        $merged = implode(' ', $queries);
        $filesResult = $this->filesTool->execute(
            ['query' => $merged], $sessionId, $messages, $emit, ''
        );

        // Memories: comma-separated for splitQueries.
        // MySQL FULLTEXT NATURAL LANGUAGE MODE works best with coherent
        // phrases — separate searches per query produce better relevance.
        $memoriesResult = $this->memoriesTool->execute(
            ['query' => implode(', ', $queries)], $sessionId, $messages, $emit, ''
        );

        $output = '';

        $hasFiles = !empty($filesResult) && !str_contains($filesResult, 'No matching files');
        if ($hasFiles) {
            $output .= "[Files]\n" . $filesResult . "\n\n";
        }

        $hasMemories = !empty($memoriesResult)
            && !str_contains($memoriesResult, 'No specific relevant memories');
        if ($hasMemories) {
            $output .= "[Memories]\n" . $memoriesResult . "\n\n";
        }

        if ($output === '') {
            return "No local results found for: " . $merged;
        }

        $output .= "[SYSTEM NOTE]: Present results from both Files and Memories sections. "
            . "If the same information appears in both, note the overlap. "
            . "If only one section has results, focus on that section.";

        return trim($output);
    }
}
