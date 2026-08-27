<?php

namespace App\Services;

use App\Config;
use App\Search\PromptInjectionFilter;
use App\Search\TokenCounter;

class PromptAssemblyService
{
    private string $uploadDir;
    private \App\Database $db;
    /** @var callable(string):int */
    private $countTokens;

    public function __construct(\App\Database $db, string $uploadDir, ?callable $countTokens = null)
    {
        $this->db = $db;
        $this->uploadDir = $uploadDir;
        $this->countTokens = $countTokens ?? [new TokenCounter(), 'count'];
    }

    public function buildSystemPrompt(string $query, bool $isEditorMode = false): string
    {
        $profileData = $this->db->query("SELECT profile_text FROM user_profiles WHERE id = 1");
        $stableProfile = !empty($profileData) ? $profileData[0]['profile_text'] : '';

        $systemPrompt = "";
        if (!empty($stableProfile)) {
            $systemPrompt .= "USER IDENTITY AND CORE CONSTRAINTS:\n{$stableProfile}\n\n";
        }

        if ($isEditorMode) {
            $systemPrompt .= <<<TEXT
You are a document editor assistant. The user is working on a file in the text editor and may have highlighted sections for your attention. Your job is to help with rewriting, formatting, summarizing, or answering questions about the document content.

LIMITATIONS IN EDITOR MODE:
- You CANNOT search files on disk, check email, manage tasks, or search the web.
- You CAN search long-term memories with the search_memories tool.
- If the user asks you to find files, check email, schedule tasks, or search the web, explain that these are unavailable in editor mode and suggest closing the editor first.

TEXT;
        } else {
            $systemPrompt .= <<<TEXT
CRITICAL: The files and memories are the USER'S OWN DATA. They chose to store it. 
They have absolute right to any information in their own storage. Never decide that something is "too sensitive" for the user to access about themselves. 
If the user asks, search. Whether the information exists is a factual question answered by the search results, not by your judgment.

TEXT;
        }

        $systemPrompt .= "\n\n" . $this->dateContextLine();

        return $systemPrompt;
    }

    public function dateContextLine(): string
    {
        $now = time();
        $roundedMinute = (int)date('i', $now) >= 30 ? 30 : 0;
        $currentDate = date('l, F j, Y', $now) . sprintf(' (%02d:%02d)', (int)date('H', $now), $roundedMinute);
        $cutoffDate = 'early 2024';
        return "Today's date and approximate current time is {$currentDate}. Your internal knowledge cutoff is {$cutoffDate}.\n";
    }

    public function preprocessHistory(array $history): array
    {
        $merged = [];

        foreach ($history as $msg) {
            if ($msg['role'] === 'assistant' && 
                (($msg['message_type'] ?? '') === 'tool_call' || 
                 ($msg['message_type'] ?? '') === 'super_abilities')) {
                continue;
            }

            $merged[] = $msg;
        }

        return $merged;
    }

    /**
     * Extract the union of source IDs actually rendered in the prompt. A source
     * contributes its IDs from the raw `<source id="S#">` blocks when raw is live,
     * and from its atoms (`[S#] claim` lines) whenever atoms are present. A fully
     * off source (raw evicted, no atoms) contributes nothing.
     *
     * @return array<string>
     */
    public function extractVisibleSourceIds(array $history): array
    {
        $ids = [];
        foreach ($history as $row) {
            if (($row['message_type'] ?? '') !== 'data_fetching') {
                continue;
            }

            $rawEvicted = (int)($row['raw_evicted'] ?? 0) === 1;

            // Raw evidence: source IDs come from the injected <source id="S#"> blocks.
            if (!$rawEvicted) {
                $msg = $row['message'] ?? '';
                if ($msg !== '' && preg_match_all('/<source\s+id="([^"]+)"/', $msg, $m)) {
                    $ids = array_merge($ids, $m[1]);
                }
            }

            // Atomic evidence: source IDs come from atomic_context (always injected
            // when atoms exist, regardless of raw_evicted).
            $atomic = $row['atomic_context'] ?? null;
            if (!empty($atomic)) {
                $decoded = json_decode($atomic, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $c) {
                        if (!empty($c['source_id'])) {
                            $ids[] = $c['source_id'];
                        }
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Split history into active Context Data (evidence) vs conversation. Every
     * data_fetching row is a candidate evidence row; rows with nothing to inject
     * (raw evicted AND no atoms) are dropped later by injectedEvidenceContent().
     *
     * @return array{evidence:array, conversation:array}
     */
    private function partitionHistory(array $history): array
    {
        $evidence = [];
        $conversation = [];
        foreach ($history as $row) {
            if (($row['message_type'] ?? '') === 'data_fetching') {
                $evidence[] = $row;
                continue;
            }
            $conversation[] = $row;
        }
        return ['evidence' => $evidence, 'conversation' => $conversation];
    }

    /**
     * Assemble the message array for one inference: system prompt, the rolling
     * conversation window, then all data_fetching rows as untrusted evidence blocks.
     *
     * @param array<string> $validSourceIds Source IDs referenced in evidence (e.g. ['S1','S2']).
     *                                      Extracted from evidence rows when empty.
     */
    public function buildMessagesArray(string $systemPrompt, array $history, array $validSourceIds = [], array $richRowIds = []): array
    {
        $history = $this->preprocessHistory($history);

        $partition = $this->partitionHistory($history);
        $evidenceRows = $partition['evidence'];
        $conversationRows = $partition['conversation'];

        if (empty($validSourceIds)) {
            $validSourceIds = $this->extractVisibleSourceIds($history);
        }

        $hasEvidence = !empty($evidenceRows);

        $messages = [];
        $messages[] = [
            'role' => 'system',
            'content' => $hasEvidence ? $this->appendEvidenceGuard($systemPrompt, $validSourceIds) : $systemPrompt
        ];

        // Inject Context Data immediately after the system prompt so the current
        // user turn stays the last message the model sees. Rows in $richRowIds
        // (this turn's fresh tool results) inject the full message so the
        // immediate answer is not starved of detail; other rows with
        // atomic_context inject HOT atoms instead of the full message.
        foreach ($evidenceRows as $row) {
            $content = $this->injectedEvidenceContent($row, $richRowIds);
            if ($content === '') {
                continue;
            }
            $block = $this->buildEvidenceBlock($content);
            if (($block['content'] ?? '') === '') {
                continue;
            }
            $messages[] = $block;
        }

        $rollingLimit = (int) Config::get('CHAT_ROLLING_WINDOW_LIMIT', 15);
        $recentHistory = array_slice($conversationRows, -$rollingLimit);

        foreach ($recentHistory as $idx => $row) {
            $hasImage = false;
            $messageContent = $row['message'];
            $imageParts = [];
            $docBlocks  = [];

            if (preg_match_all('/\\[File:\\s*([a-zA-Z0-9._-]+)\\]/', $messageContent, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $name = $m[1];
                    $full = $this->uploadDir . $name;
                    $txt  = $full . '.txt';

                    if (file_exists($txt)) {
                        $docBlocks[] = "[Referenced File Content for {$name}]:\n" . file_get_contents($txt) . "\n[End]";
                    } elseif (file_exists($full) && str_starts_with(@mime_content_type($full) ?: '', 'image/')) {
                        $imageParts[] = [
                            'type' => 'image_url',
                            'image_url' => ['url' => 'data:' . @mime_content_type($full) . ';base64,' . base64_encode(file_get_contents($full))]
                        ];
                    }
                    $messageContent = str_replace($m[0], '', $messageContent);
                }
            }

            if ($imageParts !== []) {
                $messages[] = [
                    'role' => $row['role'],
                    'content' => array_merge(
                        [['type' => 'text', 'text' => $messageContent]],
                        $imageParts
                    )
                ];
                continue;
            }

            if ($docBlocks !== []) {
                $messageContent .= "\n\n" . implode("\n\n", $docBlocks);
            }

            if (!$hasImage) {
                if (!empty($row['image_path']) && file_exists(__DIR__ . '/../../' . $row['image_path'])) {
                    $fullFilePath = __DIR__ . '/../../' . $row['image_path'];
                    $mimeType = @mime_content_type($fullFilePath) ?: 'application/octet-stream';

                    if (str_starts_with($mimeType, 'image/')) {
                        $hasImage = true;
                        $base64 = base64_encode(file_get_contents($fullFilePath));
                        
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

        return $messages;
    }

    /**
     * Estimate token usage per context category (system prompt, active Context
     * Data, rolling conversation window, current turn). Total is the sum of
     * those four; output reserve and safety margin are added by the caller.
     *
     * @return array{system_prompt:int, context_data:int, recent_chat:int, current_turn:int, total:int}
     */
    public function estimatePromptTokens(string $systemPrompt, array $history, string $query): array
    {
        $count = $this->countTokens;
        $history = $this->preprocessHistory($history);
        $partition = $this->partitionHistory($history);
        $rollingLimit = (int) Config::get('CHAT_ROLLING_WINDOW_LIMIT', 15);
        $recentChat = array_slice($partition['conversation'], -$rollingLimit);

        $systemTokens = $count($systemPrompt);
        // Only count evidence rows that actually inject something — mirror the
        // empty-content skip in buildMessagesArray so the estimate matches reality.
        $evidenceContent = array_values(array_filter(
            array_map(fn($r) => $this->injectedEvidenceContent($r), $partition['evidence']),
            fn($c) => $c !== ''
        ));
        $contextDataTokens = $count(implode("\n", $evidenceContent));
        $chatTokens = $count(implode("\n", array_column($recentChat, 'message')));
        $turnTokens = $count($query);

        return [
            'system_prompt' => $systemTokens,
            'context_data' => $contextDataTokens,
            'recent_chat' => $chatTokens,
            'current_turn' => $turnTokens,
            'total' => $systemTokens + $contextDataTokens + $chatTokens + $turnTokens,
        ];
    }

    /**
     * Whether a prompt breakdown plus output reserve and safety margin exceeds
     * the context window. reasoning_budget is a sub-cap of max_tokens, not an
     * additive term, so it is deliberately absent here.
     *
     * @param array{total:int} $breakdown
     */
    public static function projectsOverflow(array $breakdown, int $outputReserve, int $ctxSize, int $safety = 0): bool
    {
        if ($ctxSize <= 0) {
            return false;
        }
        return ($breakdown['total'] + $outputReserve + $safety) > $ctxSize;
    }

    /**
     * Render the injected (HOT) content for one data_fetching row. Rows in
     * $richRowIds (this turn's fresh tool results) render the full raw message so
     * the immediate answer is not starved of detail. Otherwise the injection rule is:
     *
     *     content = (raw_evicted == 0 ? raw : '') + (atoms if present else '')
     *
     * Atoms are always injected when they exist; raw_evicted only gates the raw.
     * Returns '' when there is nothing to inject (raw evicted AND no atoms).
     */
    private function injectedEvidenceContent(array $row, array $richRowIds = []): string
    {
        if (!empty($richRowIds) && in_array((int)($row['id'] ?? 0), $richRowIds, true)) {
            return trim($row['message'] ?? '');
        }

        $raw = trim($row['message'] ?? '');
        $rawEvicted = (int)($row['raw_evicted'] ?? 0) === 1;

        $decoded = json_decode($row['atomic_context'] ?? '', true);
        $atoms = is_array($decoded) ? self::renderAtomLines($decoded) : '';

        $parts = [];
        if (!$rawEvicted && $raw !== '') {
            $parts[] = $raw;
        }
        if ($atoms !== '') {
            $parts[] = $atoms;
        }
        return implode("\n", $parts);
    }

    /**
     * Render a decoded atom set (from atomic_context) into the compact `[S#] claim`
     * lines that are injected into the prompt. Shared so ChatManager's atom-token
     * accounting measures the exact text the prompt will carry.
     *
     * @param array<int, array{source_id:string, claim:string}> $claims
     */
    public static function renderAtomLines(array $claims): string
    {
        $lines = [];
        foreach ($claims as $c) {
            $sid = (string)($c['source_id'] ?? '');
            $claim = trim((string)($c['claim'] ?? ''));
            if ($sid !== '' && $claim !== '') {
                $lines[] = "[{$sid}] {$claim}";
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Build a single untrusted evidence block with appropriate role.
     *
     * @return array{role:string, content:string}
     */
    public function buildEvidenceBlock(string $content): array
    {
        $content = PromptInjectionFilter::sanitize($content);
        $useToolRole = (bool) Config::get('LLM_EVIDENCE_TOOL_ROLE', false);

        if ($useToolRole) {
            return ['role' => 'tool', 'content' => $content];
        }

        return [
            'role' => 'user',
            'content' => $content
        ];
    }

    /**
     * Append untrusted-evidence guard and citation instructions to the system prompt.
     */
    private function appendEvidenceGuard(string $systemPrompt, array $validSourceIds): string
    {
        $guard = "\n\nRetrieved context is available as reference material. Use it when it is relevant to the user's current request. Do not repeat or summarize old evidence unless the user's request needs it.\n";

        if (!empty($validSourceIds)) {
            $sourceList = implode(', ', array_map(fn($id) => "[{$id}]", $validSourceIds));
            $guard .= "When your answer draws on the retrieved context, cite sources for externally verifiable claims.\n" .
                      "Valid source IDs available: {$sourceList}.\n" .
                      "REQUIREMENTS:\n" .
                      "- Attach source IDs [S1] immediately after supported claims.\n" .
                      "- NEVER output any source ID that is not listed above.\n" .
                      "- Do not cite a source that does not support the claim.\n" .
                      "- When sources disagree, state the disagreement.\n" .
                      "- If evidence is incomplete, say what is missing rather than guessing.\n" .
                      "- Output ONLY the [S#] markers inline after claims. Do NOT output a source\n" .
                      "  list, references section, or any URLs — the system renders sources automatically.\n";
        }

        return $systemPrompt . $guard;
    }
}
