<?php

namespace App\Actions\File;

use App\Actions\BaseAction;

class FileDraftDeleteBlocksAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        if (ob_get_length()) {
            ob_clean();
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $file = $input['file'] ?? '';
        $blockIds = $input['block_ids'] ?? [];

        if (empty($file) || !preg_match('/^[a-zA-Z0-9._\-]+$/', $file) || empty($blockIds)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid parameters.'], 400);
            return;
        }

        $uploadDir = realpath(__DIR__ . '/../../../uploads/');
        $draftPath = realpath($uploadDir . '/' . $file . '.draft.txt');

        if (!$draftPath || !file_exists($draftPath)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No active draft found.'], 404);
            return;
        }

        $content = file_get_contents($draftPath);
        $content = str_replace("\r\n", "\n", $content);
        $lines = explode("\n", $content);

        $indicesToRemove = [];
        foreach ($blockIds as $id) {
            $indicesToRemove[] = (int)str_replace('b-', '', $id) - 1;
        }

        foreach ($indicesToRemove as $remIdx) {
            if (isset($lines[$remIdx])) {
                unset($lines[$remIdx]);
            }
        }

        $lines = array_values($lines);
        $updatedContent = implode("\n", $lines);
        file_put_contents($draftPath, $updatedContent);

        $blocks = [];
        foreach ($lines as $idx => $line) {
            $blocks[] = [
                'id' => 'b-' . ($idx + 1),
                'content' => rtrim($line)
            ];
        }

        $this->jsonResponse([
            'status' => 'success',
            'blocks' => $blocks
        ]);
    }
}
