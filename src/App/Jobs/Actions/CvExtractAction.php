<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\CvRepository;

class CvExtractAction extends BaseAction
{
    public function __construct(private $db, private $agentManager)
    {
    }

    public function execute(): void
    {
        $uuid = $_POST['cv_uuid'] ?? '';
        if ($uuid === '') {
            $this->jsonResponse(['status' => 'error', 'message' => 'Missing cv_uuid.'], 400);
            return;
        }

        $repo = new CvRepository($this->db);
        $cv = $repo->getByUuid($uuid);
        if ($cv === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'CV not found.'], 404);
            return;
        }

        $path = $this->uploadsDir() . $cv['file_ref'];
        if (!is_file($path)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'CV file is missing on disk.'], 404);
            return;
        }

        $raw = \App\FileExtractor::extractText($path, $cv['file_ref']);
        if ($raw === null || trim($raw) === '') {
            $this->jsonResponse(['status' => 'error', 'message' => 'Could not extract text from this CV.'], 422);
            return;
        }

        $truncated = mb_substr($raw, 0, 12000);

        $system = <<<'PROMPT'
You are a CV parser. Convert the resume text into a compact Markdown profile of the candidate's professional background: current role and seniority, years of experience, key skills and technologies, notable employers, education, and languages. Be factual and concise. Do not invent information. Output only the Markdown.
PROMPT;

        $markdown = $this->agentManager->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $truncated],
        ], false, null, 0.3);

        $markdown = $this->stripFences(trim($markdown));
        $repo->updateMarkdown($uuid, $markdown);

        $this->jsonResponse(['status' => 'success', 'cv' => $repo->getByUuid($uuid)]);
    }

    private function stripFences(string $text): string
    {
        if (preg_match('/^```[a-zA-Z]*\s*\n(.*)\n```$/s', $text, $matches)) {
            return $matches[1];
        }
        return $text;
    }

    private function uploadsDir(): string
    {
        $dir = realpath(__DIR__ . '/../../../uploads/');
        if ($dir === false) {
            $dir = __DIR__ . '/../../../uploads';
        }
        return rtrim($dir, '/') . '/';
    }
}
