<?php

namespace App;

class FileExtractor
{
    public static function extractText(string $filePath, string $originalName): ?string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (in_array($extension, ["txt", "py", "php", "js", "json", "css", "html", "md", "yml", "yaml", "xml", "csv"])) {
            $content = @file_get_contents($filePath);
            return self::sanitizeUtf8($content);
        }

        if ($extension === "pdf") {
            try {
                if (class_exists('\Smalot\PdfParser\Parser')) {
                    $parser = new \Smalot\PdfParser\Parser();
                    $pdf = $parser->parseFile($filePath);
                    $text = $pdf->getText();
                    return self::sanitizeUtf8($text);
                }
                return "[System Error: smalot/pdfparser is missing. Run composer require smalot/pdfparser]";
            } catch (\Throwable $e) {
                return "[System Error parsing PDF: " . $e->getMessage() . "]";
            }
        }

        if ($extension === "docx") {
            return self::extractDocxText($filePath);
        }

        return null;
    }

    private static function extractDocxText(string $filePath): ?string
    {
        try {
            if (class_exists('\ZipArchive')) {
                $zip = new \ZipArchive();
                if ($zip->open($filePath) === true) {
                    $index = $zip->locateName("word/document.xml");
                    if ($index !== false) {
                        $data = $zip->getFromIndex($index);
                        $zip->close();
                        $content = str_replace("</w:r></w:p></w:tc><w:tc>", " ", $data);
                        $content = str_replace("</w:r></w:p>", "\r\n", $content);
                        $cleanText = strip_tags($content);
                        return self::sanitizeUtf8($cleanText);
                    }
                    $zip->close();
                }
            }
        } catch (\Throwable $e) {}
        
        return null;
    }

    private static function sanitizeUtf8(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
}