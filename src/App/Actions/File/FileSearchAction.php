<?php

namespace App\Actions\File;

use App\Actions\BaseAction;

class FileSearchAction extends BaseAction
{
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

        $toolQuery = $_GET['query'] ?? '';
        $keywords = array_filter(explode(' ', preg_replace('/[^\p{L}\p{N}\s]/u', '', $toolQuery)));

        $sql = "SELECT id, original_name, physical_name, generated_title, file_type, uploaded_at FROM uploaded_files";
        $conditions = [];
        $params = [];

        if (!empty($keywords)) {
            $idx = 0;
            foreach ($keywords as $word) {
                if (mb_strlen($word) < 2) {
                    continue;
                }
                $conditions[] = "(generated_title LIKE :w{$idx} OR original_name LIKE :o{$idx})";
                $params[":w{$idx}"] = "%{$word}%";
                $params[":o{$idx}"] = "%{$word}%";
                $idx++;
            }
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" OR ", $conditions);
        }

        $sql .= " ORDER BY uploaded_at DESC";

        if ($isGallery) {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = max(1, min(100, (int)($_GET['limit'] ?? 12)));
            $offset = ($page - 1) * $limit;

            $countSql = "SELECT COUNT(*) as total FROM uploaded_files";
            if (!empty($conditions)) {
                $countSql .= " WHERE " . implode(" OR ", $conditions);
            }

            try {
                $countResult = $this->db->query($countSql, $params);
                $total = (int)($countResult[0]['total'] ?? 0);

                $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
                $matchingFiles = $this->db->query($sql, $params);

                foreach ($matchingFiles as &$file) {
                    $file['snippet'] = $this->getFileSnippet($file['physical_name'], $file['file_type']);
                }

                $this->jsonResponse([
                    'status' => 'success',
                    'query' => $toolQuery,
                    'files' => $matchingFiles,
                    'pagination' => [
                        'total' => $total,
                        'page' => $page,
                        'limit' => $limit,
                        'pages' => ceil($total / $limit)
                    ]
                ]);
            } catch (\Exception $e) {
                $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
            }
        } else {
            $sql .= " LIMIT 5";
            try {
                $matchingFiles = $this->db->query($sql, $params);
                $this->jsonResponse([
                    'status' => 'success',
                    'query' => $toolQuery,
                    'files' => $matchingFiles
                ]);
            } catch (\Exception $e) {
                $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
            }
        }
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
