<?php

namespace App\Agents;

use App\AgentManager;
use App\Config;

class SearchDecider
{
    private AgentManager $agent;

    public function __construct(AgentManager $agent)
    {
        $this->agent = $agent;
    }

    public function requiresSearch(string $userPrompt, array $history = []): ?string
    {
        $result = $this->decideSearchAndIntent($userPrompt, $history);
        return $result['search_query'] ?? null;
    }

    public function decideSearchAndIntent(string $userPrompt, array $history = []): array
    {
        $cleanPrompt = trim(strtolower($userPrompt));

        // Fast-path regex for explicit search commands
        if (preg_match('/^(?:force\s+)?(?:the\s+)?(?:web\s+)?search\s+(.+)$/i', $userPrompt, $matches)) {
            return ['search_query' => trim($matches[1]), 'intents' => 'none'];
        }

        if (preg_match('/^force\s+(?:the\s+)?(?:web\s+)?search$/i', $userPrompt)) {
            $lastUserQuery = null;
            foreach (array_reverse($history) as $msg) {
                if ($msg['role'] === 'user' && $msg['message'] !== $userPrompt) {
                    $lastUserQuery = $msg['message'];
                    break;
                }
            }
            if ($lastUserQuery) {
                return ['search_query' => $lastUserQuery, 'intents' => 'none'];
            }
        }

        $currentDate = date('l, F j, Y g:i A');
        
        $historyText = "";
        
        // Filter history to ONLY include concise, conversational user and assistant turns.
        // We strictly exclude massive system payloads (like database results or extracted file contents)
        // to prevent saturating the decider model's attention window.
        $cleanHistory = [];
        foreach ($history as $msg) {
            $role = $msg['role'] ?? '';
            $type = $msg['message_type'] ?? 'text';
            
            if ($role === 'system' || $type === 'data_fetching' || $type === 'tool_call') {
                continue; // Skip non-conversational context completely
            }
            
            $cleanHistory[] = $msg;
        }

        $slicedHistory = array_slice($cleanHistory, -6); // Take the last 6 clean conversational turns
        foreach ($slicedHistory as $msg) {
            if ($msg['message'] !== $userPrompt) {
                $roleLabel = ucfirst($msg['role']);
                $content = $msg['message'];
                
                // Truncate past long messages (like summaries) so they don't distract the decider
                if (mb_strlen($content) > 200) {
                    $content = mb_substr($content, 0, 200) . '... [truncated]';
                }
                
                $historyText .= "{$roleLabel}: {$content}\n";
            }
        }

        $systemPrompt = <<<TEXT
Today is {$currentDate}.

You are an Intent Router agent. Analyze the user's request and output a single JSON object.

SEARCH DECISION RULES:
- Set "requires_search" to true ONLY if the request asks for real-time information (e.g., current news, weather, stock prices, or general web facts).
- Set "requires_search" to false if the user is asking about their personal calendar, their personal files (like their resume, documents, or photos), their personal emails, or general conversation.

PERSONAL OWNERSHIP OVERRIDE:
The words "my", "mine", "I have a", or "I booked" indicate PERSONAL data — NOT a web search. For ALL queries containing "my" + a noun (e.g., "my IBAN", "my flight", "my password"), ALWAYS include both "search_files" and "search_memories" in intents. These are your cheapest, fastest tools and should always be checked first for personal information. Examples:
- "my flight details" / "my booking" / "my ticket" / "my reservation" → intents MUST include search_files,search_memories,email_briefing
- "my IBAN" / "my bank account" / "my password" / "my phone number" / "my address" → intents MUST include search_files,search_memories
- "my invoice" / "my receipt" / "my bill" / "my statement" → intents MUST include search_files,search_memories,email_briefing

Only trigger web search for queries about PUBLIC facts — where the answer is the same for everyone. If the answer depends on who the user is, the information is in their email, files, or memories.

INTENT ROUTING RULES:
Identify ALL tool categories the user wants to utilize:
- "search_files": User wants to find, view, or check files/docs/images on disk.
- "search_memories": User asks to recall, remember, or retrieve saved information about themselves or past conversations. Trigger words: "recall", "remember", "what do you know about", "do you remember", "what did I say about", "my" + personal info (IBAN, address, phone, account number, password).
- "todoist_create": User wants to create, add, plan, or schedule a task/reminder/appointment.
- "todoist_get": User wants to see, read, fetch, or list their tasks/calendar/schedule.
- "todoist_update": User wants to edit, update, reschedule, or change an existing task.
- "todoist_delete": User wants to delete, remove, or clear a task/reminder.
- "email_briefing": User wants to check, read, or get a briefing of their emails.
- "none": Standard conversation or general question with no tool intent.

Format your output STRICTLY as:
{
  "requires_search": boolean,
  "search_query": string or null,
  "intents": "comma,separated,list" or "none"
}

If requires_search is true, "search_query" MUST be a standalone search query. For intents, output ALL categories that apply.

CONVERSATION HISTORY:
{$historyText}
TEXT;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ];

        $temperature = (float) Config::get('AGENT_DECIDER_TEMP', 0.1);
        $response = trim($this->agent->chat($messages, false, null, $temperature));

        $data = \App\JsonParser::extractAndDecode($response);

        $result = ['search_query' => null, 'intents' => 'none'];

        if (is_array($data)) {
            if (isset($data['requires_search']) && $data['requires_search'] === true) {
                $result['search_query'] = !empty($data['search_query']) ? trim($data['search_query']) : null;
            }
            if (isset($data['intents'])) {
                $result['intents'] = trim($data['intents']);
            }
        }

        return $result;
    }
}