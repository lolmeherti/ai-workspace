<?php

namespace App\Actions\File;

use App\Actions\BaseAction;
use App\Search\FileRetriever;

class FileSearchAction extends BaseAction
{
    private ?FileRetriever $retriever = null;

    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        if (ob_get_length()) {
            ob_clean();
        }

        if (!$this->db) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No database connection available.'], 400);
            return;
        }

        $source = $_GET['source'] ?? '';
        $isGallery = ($source === 'gallery');
        $toolQuery = trim($_GET['query'] ?? '');

        try {
            if ($toolQuery === '') {
                $files = $this->browseAll();
            } elseif ($isGallery) {
                $files = $this->retriever()->rankAll($toolQuery);
            } else {
                $files = $this->retriever()->rank($toolQuery);
            }

            if ($isGallery) {
                $this->respondGallery($files, $toolQuery);
            } else {
                $this->jsonResponse([
                    'status' => 'success',
                    'query' => $toolQuery,
                    'files' => array_slice($files, 0, 5),
                ]);
            }
        } catch (\Exception $e) {
            $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    private function retriever(): FileRetriever
    {
        if ($this->retriever === null) {
            $this->retriever = new FileRetriever($this->db);
        }
        return $this->retriever;
    }

    private function browseAll(): array
    {
        return $this->db->query(
            "SELECT id, original_name, physical_name, generated_title, file_type, uploaded_at
             FROM uploaded_files
             ORDER BY uploaded_at DESC"
        );
    }

    private function respondGallery(array $files, string $query): void
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 12)));
        $category = (string)($_GET['category'] ?? 'all');
        $files = array_values(array_filter($files, static function (array $file) use ($category): bool {
            $type = strtolower($file['file_type'] ?? '');
            $ext = strtolower(pathinfo($file['original_name'] ?? '', PATHINFO_EXTENSION));
            $image = str_starts_with($type, 'image/') || $type === 'image'
                || in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
            return $category === 'images' ? $image : ($category === 'docs' ? !$image : true);
        }));
        $total = count($files);
        $pages = max(1, (int)ceil($total / $limit));
        $page = min($page, $pages);
        $offset = ($page - 1) * $limit;

        $pageFiles = array_slice($files, $offset, $limit);
        foreach ($pageFiles as &$file) {
            $file['snippet'] = $this->getFileSnippet($file['physical_name'], $file['file_type']);
        }
        unset($file);

        $this->jsonResponse([
            'status' => 'success',
            'query' => $query,
            'files' => $pageFiles,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => $pages,
            ],
        ]);
    }

    private function getFileSnippet(string $physicalName, string $fileType): string
    {
        $lowerType = strtolower($fileType);
        if (str_starts_with($lowerType, 'image/') || $lowerType === 'image') {
            return '';
        }

        $uploadDir = realpath(__DIR__ . '/../../../uploads/');
        $txtPath = $uploadDir . '/' . $physicalName . '.txt';
        $origPath = $uploadDir . '/' . $physicalName;

        $targetPath = null;
        if (file_exists($txtPath)) {
            $targetPath = $txtPath;
        } elseif (file_exists($origPath) && (str_ends_with(strtolower($physicalName), '.txt') || str_starts_with(strtolower($fileType), 'text/'))) {
            $targetPath = $origPath;
        }

        if ($targetPath) {
            $handle = @fopen($targetPath, 'r');
            if ($handle) {
                $snippet = fread($handle, 150);
                fclose($handle);

                $snippet = mb_convert_encoding($snippet, 'UTF-8', 'UTF-8');
                return preg_replace('/\s+/', ' ', trim($snippet));
            }
        }
        return '';
    }
}
