<?php

namespace App\Search;

class StructuralChunker
{
    private const TARGET_CHARS_MIN = 1200;
    private const TARGET_CHARS_MAX = 3200;
    private const TABLE_MAX = 8000;
    private const OVERLAP_SENTENCES = 2;
    private const MAX_UNIT_CHARS = 50000;

    /**
     * @return WebChunk[]
     */
    public function chunk(ExtractedDocument $doc, string $sourceId): array
    {
        if (empty(trim($doc->markdown))) {
            return [];
        }

        $units = $this->buildChunkUnits($doc->markdown);

        $chunks = [];
        $chunkNum = 0;
        foreach ($units as $unit) {
            $chunkNum++;
            $chunks[] = new WebChunk(
                sourceId: $sourceId,
                chunkId: "{$sourceId}-C{$chunkNum}",
                url: $doc->url,
                finalUrl: $doc->finalUrl,
                title: $doc->title,
                domain: $doc->domain,
                publishedAt: $doc->publishedAt,
                updatedAt: $doc->updatedAt,
                fetchedAt: $doc->fetchedAt,
                headingPath: $unit['headingTitles'],
                sectionType: $unit['type'],
                text: $unit['text'],
                position: $chunkNum,
            );
        }

        return $chunks;
    }

    public function chunkText(string $text): array
    {
        if (empty(trim($text))) {
            return [];
        }

        return array_map(fn($u) => $u['text'], $this->buildChunkUnits($text));
    }

    private function buildChunkUnits(string $md): array
    {
        $units = $this->parseStructuralUnits($md);
        $merged = $this->mergeSmallUnits($units);

        $result = [];

        foreach ($merged as $unit) {
            $text = $unit['text'];
            $type = $unit['type'];
            $headingTitles = array_map(fn($h) => $h['title'], $unit['heading']);

            if ($type === 'table' && strlen($text) > self::TABLE_MAX) {
                $rows = explode("\n", $text);
                $header = $rows[0];
                $sep = $rows[1] ?? '';
                $dataRows = array_slice($rows, 2);
                $batch = [$header, $sep];
                $batchSize = 0;

                foreach ($dataRows as $row) {
                    if ($batchSize > 1 && strlen(implode("\n", $batch)) + strlen($row) > self::TARGET_CHARS_MAX) {
                        $result[] = ['text' => implode("\n", $batch), 'type' => 'table', 'headingTitles' => $headingTitles];
                        $batch = [$header, $sep];
                        $batchSize = 0;
                    }
                    $batch[] = $row;
                    $batchSize++;
                }

                if (count($batch) > 2) {
                    $result[] = ['text' => implode("\n", $batch), 'type' => 'table', 'headingTitles' => $headingTitles];
                }
                continue;
            }

            if ($type !== 'code' && strlen($text) > self::TARGET_CHARS_MAX * 2) {
                $paragraphs = preg_split('/\n\n+/', $text);
                $batch = [];
                $batchLen = 0;
                $overlap = '';

                foreach ($paragraphs as $p) {
                    $p = trim($p);
                    if (empty($p)) continue;

                    if ($batchLen > 0 && $batchLen + strlen($p) > self::TARGET_CHARS_MAX) {
                        $result[] = ['text' => implode("\n\n", $batch), 'type' => 'paragraph', 'headingTitles' => $headingTitles];

                        if ($type === 'paragraph' && self::OVERLAP_SENTENCES > 0) {
                            $sentences = preg_split('/(?<=[.!?])\s+/', end($batch));
                            $overlap = implode(' ', array_slice($sentences, -self::OVERLAP_SENTENCES));
                        }

                        $batch = $overlap ? [$overlap] : [];
                        $batchLen = strlen($overlap);
                    }

                    $batch[] = $p;
                    $batchLen += strlen($p);
                }

                if (!empty($batch)) {
                    $result[] = ['text' => implode("\n\n", $batch), 'type' => $type, 'headingTitles' => $headingTitles];
                }
            } elseif (strlen($text) > self::MAX_UNIT_CHARS) {
                $subChunks = mb_str_split($text, self::TARGET_CHARS_MAX * 4);
                foreach ($subChunks as $sub) {
                    $result[] = ['text' => $sub, 'type' => $type, 'headingTitles' => $headingTitles];
                }
            } else {
                $result[] = ['text' => $text, 'type' => $type, 'headingTitles' => $headingTitles];
            }
        }

        return $result;
    }

    private function parseStructuralUnits(string $md): array
    {
        $units = [];
        $lines = explode("\n", $md);
        $currentHeading = [];
        $buffer = '';
        $bufferType = 'paragraph';
        $inCodeBlock = false;
        $inTable = false;
        $tableRows = [];
        $codeLang = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '```')) {
                if ($inCodeBlock) {
                    $buffer .= $line . "\n";
                    $units[] = ['text' => trim($buffer), 'type' => 'code',
                                  'heading' => $currentHeading, 'lang' => $codeLang];
                    $buffer = '';
                    $bufferType = 'paragraph';
                    $inCodeBlock = false;
                    $codeLang = '';
                } else {
                    if (!empty(trim($buffer))) {
                        $units[] = ['text' => trim($buffer), 'type' => $bufferType,
                                     'heading' => $currentHeading, 'lang' => ''];
                        $buffer = '';
                    }
                    $codeLang = substr($trimmed, 3);
                    $inCodeBlock = true;
                    $buffer = $line . "\n";
                    $bufferType = 'code';
                }
                continue;
            }

            if ($inCodeBlock) {
                $buffer .= $line . "\n";
                continue;
            }

            if ($inTable) {
                if (str_starts_with($trimmed, '|') && str_contains($trimmed, '|')) {
                    $tableRows[] = $line;
                    continue;
                } else {
                    if (!empty($tableRows)) {
                        if (!empty(trim($buffer))) {
                            $units[] = ['text' => trim($buffer), 'type' => $bufferType,
                                         'heading' => $currentHeading, 'lang' => ''];
                        }
                        $units[] = ['text' => implode("\n", $tableRows), 'type' => 'table',
                                     'heading' => $currentHeading, 'lang' => ''];
                        $buffer = '';
                        $bufferType = 'paragraph';
                        $tableRows = [];
                    }
                    $inTable = false;
                }
            }

            if (!$inTable && str_starts_with($trimmed, '|') && str_contains($trimmed, '|')) {
                if (substr_count($trimmed, '|') >= 2) {
                    if (!empty(trim($buffer))) {
                        $units[] = ['text' => trim($buffer), 'type' => $bufferType,
                                     'heading' => $currentHeading, 'lang' => ''];
                        $buffer = '';
                    }
                    $inTable = true;
                    $tableRows = [$line];
                    continue;
                }
            }

            if (preg_match('/^#{1,6}\s/', $trimmed)) {
                if (!empty(trim($buffer))) {
                    $units[] = ['text' => trim($buffer), 'type' => $bufferType,
                                 'heading' => $currentHeading, 'lang' => ''];
                }

                $level = strlen($line) - strlen(ltrim($line, '#'));
                $title = trim(ltrim($trimmed, '# '));

                while (!empty($currentHeading) && $currentHeading[count($currentHeading)-1]['level'] >= $level) {
                    array_pop($currentHeading);
                }
                $currentHeading[] = ['level' => $level, 'title' => $title];

                $buffer = '';
                $bufferType = 'paragraph';
                continue;
            }

            if (preg_match('/^[-*+]\s/', $trimmed) || preg_match('/^\d+\.\s/', $trimmed)) {
                if ($bufferType !== 'list' && !empty(trim($buffer))) {
                    $units[] = ['text' => trim($buffer), 'type' => $bufferType,
                                 'heading' => $currentHeading, 'lang' => ''];
                    $buffer = '';
                }
                $bufferType = 'list';
            }

            $buffer .= ($buffer !== '' ? "\n" : '') . $line;
        }

        if ($inTable && !empty($tableRows)) {
            if (!empty(trim($buffer))) {
                $units[] = ['text' => trim($buffer), 'type' => $bufferType,
                             'heading' => $currentHeading, 'lang' => ''];
            }
            $units[] = ['text' => implode("\n", $tableRows), 'type' => 'table',
                         'heading' => $currentHeading, 'lang' => ''];
        } elseif (!empty(trim($buffer))) {
            $units[] = ['text' => trim($buffer), 'type' => $bufferType,
                         'heading' => $currentHeading, 'lang' => ''];
        }

        return $units;
    }

    private function mergeSmallUnits(array $units): array
    {
        if (empty($units)) return [];

        $merged = [];
        $current = $units[0];

        for ($i = 1; $i < count($units); $i++) {
            $unit = $units[$i];
            $canMerge = $current['type'] === $unit['type']
                || ($current['type'] === 'paragraph' && $unit['type'] === 'list')
                || ($current['type'] === 'list' && $unit['type'] === 'paragraph');

            $sameHeading = $current['heading'] === $unit['heading'];

            if ($canMerge && $sameHeading && strlen($current['text']) < self::TARGET_CHARS_MIN) {
                $current['text'] .= "\n\n" . $unit['text'];
            } else {
                $merged[] = $current;
                $current = $unit;
            }
        }
        $merged[] = $current;

        return $merged;
    }
}
