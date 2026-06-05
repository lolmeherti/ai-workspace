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

    /**
     * Decides if search is needed, using recent chat history to contextualize the query.
     */
    public function requiresSearch(string $userPrompt, array $history = []): ?string
    {
        $currentDate = date('l, F j, Y g:i A');
        
        $historyText = "";
        $slicedHistory = array_slice($history, -8); 
        foreach ($slicedHistory as $msg) {
            if ($msg['message'] !== $userPrompt) {
                $historyText .= ucfirst($msg['role']) . ": " . $msg['message'] . "\n";
            }
        }

        $systemPrompt = <<<TEXT
Today is {$currentDate}.

You are a Search Decision Agent. Your job is to analyze the recent conversation history and the user's latest message to determine if a live web search is necessary to answer the user's request.

A live web search is necessary if the user's request requires up-to-date facts, news, weather, or real-time information. 

Be aware that there is a local file system. If the user is asking something that is likely from the local file system such as their personal files, then it is not an online web search.

Format your output STRICTLY as a JSON object matching this schema:
{
  "requires_search": boolean,
  "search_query": string or null
}

If requires_search is true, the "search_query" MUST be a standalone, fully rephrased search query. 

CONVERSATION HISTORY:
{$historyText}
TEXT;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ];

        $temperature = (float) Config::get('AGENT_DECIDER_TEMP', 0.1);
        $response = trim($this->agent->chat($messages, false, null, $temperature));

        if (strpos($response, '```') !== false) {
            $response = preg_replace('/```(?:json)?\s*(.*?)\s*```/s', '$1', $response);
            $response = trim($response);
        }

        $data = json_decode($response, true);

        if (is_array($data) && isset($data['requires_search']) && $data['requires_search'] === true) {
            return !empty($data['search_query']) ? trim($data['search_query']) : null;
        }

        return null;
    }
}