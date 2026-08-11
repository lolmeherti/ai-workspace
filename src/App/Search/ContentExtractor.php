<?php

namespace App\Search;

use DOMDocument;
use DOMXPath;
use League\HTMLToMarkdown\HtmlConverter;

final readonly class ExtractedDocument
{
    public function __construct(
        public string $url,
        public string $finalUrl,
        public string $title,
        public string $domain,
        public ?string $publishedAt,
        public ?string $updatedAt,
        public string $fetchedAt,
        public string $markdown,
        public string $extractionMethod,
        public int $contentLength,
    ) {}
}

class ContentExtractor
{
    private HtmlConverter $converter;

    public function __construct()
    {
        $this->converter = new HtmlConverter([
            'strip_tags' => true,
            'remove_nodes' => 'script style nav footer header aside noscript form iframe',
            'hard_break' => true,
            'preserve_comments' => false,
            'header_style' => 'atx',
            'table_pipe_escape' => '\\|',
            'table_caption_side' => 'top',
        ]);
    }

    public function extract(string $html, string $url, string $finalUrl, string $fetchedAt): ExtractedDocument
    {
        if (empty(trim($html))) {
            return new ExtractedDocument($url, $finalUrl, '', self::domain($url),
                null, null, $fetchedAt, '', 'empty_body', 0);
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html,
            LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $meta = $this->extractMetadata($dom, $xpath);
        $domain = self::domain($finalUrl ?: $url);

        $contentDom = $this->selectSubtree($dom, $xpath);
        $method = $contentDom === $dom ? 'body_fallback' : 'semantic_container';

        if ($contentDom === $dom) {
            $this->stripLayoutTags($dom);
        }

        $markdown = $this->convertToMarkdown($contentDom, $dom);

        $specDetected = $this->hasSpecificationContent($dom, $xpath);
        if ($specDetected && $contentDom !== $dom) {
            $bodyMarkdown = $this->convertToMarkdown($dom, $dom);
            if (strlen($bodyMarkdown) > strlen($markdown) * 1.3) {
                $markdown = $bodyMarkdown;
                $method = 'spec_page_body';
            }
        }

        $markdown = $this->cleanMarkdown($markdown);

        if (strlen($markdown) < 200) {
            $text = trim(strip_tags($html));
            $text = preg_replace('/\s+/', ' ', $text);
            if (strlen($text) > strlen($markdown)) {
                $markdown = $text;
                $method = 'raw_strip_tags';
            }
        }

        return new ExtractedDocument(
            url: $url,
            finalUrl: $finalUrl,
            title: $meta['title'],
            domain: $domain,
            publishedAt: $meta['published'],
            updatedAt: $meta['modified'],
            fetchedAt: $fetchedAt,
            markdown: $markdown,
            extractionMethod: $method,
            contentLength: strlen($markdown),
        );
    }

    private function extractMetadata(DOMDocument $dom, DOMXPath $xpath): array
    {
        $meta = ['title' => '', 'published' => null, 'modified' => null];

        $titleNode = $dom->getElementsByTagName('title')->item(0);
        if ($titleNode) {
            $meta['title'] = trim($titleNode->textContent);
        }

        $metaNodes = $dom->getElementsByTagName('meta');
        foreach ($metaNodes as $node) {
            $name = strtolower($node->getAttribute('name') ?: $node->getAttribute('property'));
            $content = $node->getAttribute('content');

            if ($name === 'description' && empty($meta['description'])) {
                $meta['description'] = $content;
            }
            if (in_array($name, ['article:published_time', 'date'])) {
                $meta['published'] = $meta['published'] ?: $content;
            }
            if ($name === 'article:modified_time') {
                $meta['modified'] = $meta['modified'] ?: $content;
            }
        }

        // JSON-LD
        foreach ($dom->getElementsByTagName('script') as $script) {
            if (strtolower($script->getAttribute('type')) === 'application/ld+json') {
                $json = json_decode(trim($script->textContent), true);
                if ($json) {
                    $ld = is_array($json) && isset($json['@graph']) ? $json['@graph'] : [$json];
                    foreach ($ld as $item) {
                        if (isset($item['datePublished'])) {
                            $meta['published'] = $meta['published'] ?: $item['datePublished'];
                        }
                        if (isset($item['dateModified'])) {
                            $meta['modified'] = $meta['modified'] ?: $item['dateModified'];
                        }
                    }
                }
            }
        }

        return $meta;
    }

    private function selectSubtree(DOMDocument $dom, DOMXPath $xpath): DOMDocument
    {
        $selectors = [
            '//article',
            '//main',
            '//*[@role="main"]',
            '//div[@id="content"]',
            '//div[@class="content"]',
            '//div[@id="main"]',
        ];

        foreach ($selectors as $sel) {
            $nodes = $xpath->query($sel);
            if ($nodes && $nodes->length > 0) {
                $node = $nodes->item(0);
                $text = trim($node->textContent);
                if (strlen($text) > 500 && $this->contentDensity($node) > 0.3) {
                    $sub = new DOMDocument();
                    $sub->loadHTML('<?xml encoding="UTF-8">' .
                        $dom->saveHTML($node), LIBXML_NOBLANKS | LIBXML_NOERROR);
                    return $sub;
                }
            }
        }

        return $dom;
    }

    private function contentDensity(\DOMNode $node): float
    {
        $text = trim($node->textContent);
        if (strlen($text) === 0) return 0.0;

        $tagCount = 0;
        $this->countTags($node, $tagCount);
        $tagChars = $tagCount * 5;

        return max(0, 1.0 - ($tagChars / strlen($text)));
    }

    private function countTags(\DOMNode $node, int &$count): void
    {
        if ($node->nodeType === XML_ELEMENT_NODE) {
            $count++;
            foreach ($node->childNodes as $child) {
                $this->countTags($child, $count);
            }
        }
    }

    private function stripLayoutTags(DOMDocument $dom): void
    {
        $remove = ['script', 'style', 'nav', 'header', 'footer', 'aside',
                    'noscript', 'form', 'img', 'video', 'svg', 'canvas',
                    'iframe', 'audio', 'picture', 'source', 'embed', 'object', 'track'];

        foreach ($remove as $tag) {
            $elements = $dom->getElementsByTagName($tag);
            for ($i = $elements->length - 1; $i >= 0; $i--) {
                $node = $elements->item($i);
                if ($node && $node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
    }

    private function hasSpecificationContent(DOMDocument $dom, DOMXPath $xpath): bool
    {
        $indicators = ['//table', '//dl', '//ul[contains(@class,"spec")]',
                        '//div[contains(@class,"spec")]', '//section[contains(@class,"spec")]'];
        foreach ($indicators as $sel) {
            $nodes = $xpath->query($sel);
            if ($nodes && $nodes->length > 0) return true;
        }
        return false;
    }

    private function convertToMarkdown(DOMDocument $contentDom, DOMDocument $fullDom): string
    {
        $html = $contentDom->saveHTML();

        try {
            return trim($this->converter->convert($html));
        } catch (\Throwable $e) {
            return trim(strip_tags($fullDom->saveHTML()));
        }
    }

    private function cleanMarkdown(string $md): string
    {
        $md = preg_replace("/\n{3,}/", "\n\n", $md);
        $md = preg_replace('/ {2,}/', ' ', $md);
        $md = preg_replace('/^\s+\n/m', "\n", $md);
        return trim($md);
    }

    private static function domain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return $host ?: '';
    }
}
