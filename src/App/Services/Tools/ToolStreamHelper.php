<?php

namespace App\Services\Tools;

use App\AgentManager;

trait ToolStreamHelper
{
    protected function streamAgentCommentary(AgentManager $agent, array $messages, callable $emit, string $cleanJson): string
    {
        $aiCommentary = '';
        $commentaryBuffer = '';

        $agent->chat($messages, true, function ($chunk) use ($emit, &$aiCommentary, &$commentaryBuffer) {
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

        return $cleanJson . "\n\n" . $aiCommentary;
    }
}
