<?php

namespace App\Actions\File;

use App\Actions\BaseAction;

class FileDraftUpdateAction extends BaseAction
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
        $blockId = $input['block_id'] ?? '';
        $newContent = $input['content'] ?? '';
        $replaceRange = $input['replace_range'] ?? [];

        if (empty($file) || !preg_match('/^[a-zA-Z0-9._\-]+$/', $file) || empty($blockId)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid update parameters.'], 400);
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

        $index = (int)str_replace('b-', '', $blockId) - 1;

        if ($index >= 0 && $index < count($lines)) {
            $lines[$index] = $newContent;
        } else {
            $this->jsonResponse(['status' => 'error', 'message' => 'Target block index out of bounds.'], 400);
            return;
        }

        if (is_array($replaceRange) && !empty($replaceRange)) {
            foreach ($replaceRange as $idToRemove) {
                $remIdx = (int)str_replace('b-', '', $idToRemove) - 1;
                if ($remIdx >= 0 && $remIdx < count($lines)) {
                    unset($lines[$remIdx]);
                }
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
