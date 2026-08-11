<?php

declare(strict_types=1);

namespace App\Adapters;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use InvalidArgumentException;
use RuntimeException;

final class Reddit
{
    private const REDDIT_ORIGIN = 'https://www.reddit.com';

    public function supports(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === 'reddit.com'
            || $host === 'www.reddit.com'
            || $host === 'old.reddit.com';
    }

    /**
     * Parses rendered Reddit HTML.
     *
     * Discovery deliberately avoids Reddit frontend component/tag names and CSS
     * classes. Posts/comments are identified from Reddit's semantic entity data:
     * t3_* / t1_* IDs, permalink, author, depth, timestamps, etc.
     *
     * Subreddit pages return the posts currently present in the DOM.
     * Post pages return the post plus the first N top-level comments and only
     * their immediate replies (depth=1). Replies-to-replies are ignored.
     */
    public function parse(
        string $url,
        string $html,
        int $maxSubredditPosts = 25,
        int $maxTopLevelComments = 5,
    ): array {
        if (!$this->supports($url)) {
            throw new InvalidArgumentException('URL is not a Reddit URL.');
        }

        if ($html === '') {
            throw new InvalidArgumentException('HTML cannot be empty.');
        }

        $document = $this->loadHtml($html);
        $xpath = new DOMXPath($document);

        if ($this->isPostUrl($url)) {
            return $this->parsePostPage($url, $xpath, $maxTopLevelComments);
        }

        return $this->parseSubredditPage($url, $xpath, $maxSubredditPosts);
    }

    private function parseSubredditPage(string $url, DOMXPath $xpath, int $maxPosts): array
    {
        $posts = [];
        $seen = [];

        foreach ($this->findPostEntities($xpath) as $post) {
            if (count($posts) >= $maxPosts) {
                break;
            }

            if (!$this->looksLikePostEntity($post) || $this->isPromotedPost($post)) {
                continue;
            }

            $parsed = $this->extractPost($xpath, $post);
            $key = $parsed['id'] ?: $parsed['url'];

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $posts[] = $parsed;
        }

        return [
            'type' => 'subreddit',
            'url' => $url,
            'subreddit' => $this->subredditFromUrl($url),
            'posts' => $posts,
        ];
    }

    private function parsePostPage(string $url, DOMXPath $xpath, int $maxTopLevelComments): array
    {
        $postNode = $this->findMainPost($xpath, $url);

        $post = $postNode instanceof DOMElement
            ? $this->extractPost($xpath, $postNode)
            : [
                'id' => $this->postThingIdFromUrl($url) ?? '',
                'title' => '',
                'url' => $url,
                'author' => '',
                'subreddit' => $this->subredditFromUrl($url),
                'created_at' => null,
                'score' => null,
                'comment_count' => null,
                'post_type' => null,
                'flair' => null,
                'body' => '',
            ];

        return [
            'type' => 'post',
            'url' => $url,
            'subreddit' => $post['subreddit'] ?: $this->subredditFromUrl($url),
            'post' => $post,
            'comments' => $this->extractCommentTree($xpath, $maxTopLevelComments),
        ];
    }

    /** @return list<DOMElement> */
    private function findPostEntities(DOMXPath $xpath): array
    {
        $query = '//*['
            . 'starts-with(@id, "t3_")'
            . ' or starts-with(@thingid, "t3_")'
            . ' or (@post-title and @permalink)'
            . ']';

        return $this->uniqueElements($xpath->query($query));
    }

    private function findMainPost(DOMXPath $xpath, string $url): ?DOMElement
    {
        $thingId = $this->postThingIdFromUrl($url);

        if ($thingId !== null) {
            $literal = $this->xpathLiteral($thingId);
            $node = $xpath->query('//*[@id=' . $literal . ' or @thingid=' . $literal . ']')->item(0);
            if ($node instanceof DOMElement && $this->looksLikePostEntity($node)) {
                return $node;
            }
        }

        $targetPath = $this->normalizeRedditPath((string) parse_url($url, PHP_URL_PATH));

        foreach ($this->findPostEntities($xpath) as $node) {
            if (!$this->looksLikePostEntity($node)) {
                continue;
            }

            $permalink = $this->normalizeRedditPath($node->getAttribute('permalink'));
            if ($permalink !== '' && $permalink === $targetPath) {
                return $node;
            }
        }

        foreach ($this->findPostEntities($xpath) as $node) {
            if ($this->looksLikePostEntity($node)) {
                return $node;
            }
        }

        return null;
    }

    private function looksLikePostEntity(DOMElement $node): bool
    {
        $score = 0;

        $id = $this->entityId($node);
        if (str_starts_with($id, 't3_')) {
            $score += 5;
        }

        if ($node->hasAttribute('post-title')) {
            $score += 3;
        }

        $permalink = $node->getAttribute('permalink');
        if ($permalink !== '' && preg_match('~^/r/[^/]+/comments/[^/]+(?:/|$)~i', $permalink) === 1) {
            $score += 3;
        }

        if ($node->hasAttribute('author')) {
            $score += 1;
        }
        if ($node->hasAttribute('created-timestamp')) {
            $score += 1;
        }
        if ($node->hasAttribute('subreddit-prefixed-name')) {
            $score += 1;
        }
        if ($node->hasAttribute('comment-count')) {
            $score += 1;
        }

        return $score >= 5;
    }

    private function extractPost(DOMXPath $xpath, DOMElement $post): array
    {
        $id = $this->entityId($post);
        $permalink = $post->getAttribute('permalink');

        $title = trim($post->getAttribute('post-title'));
        $body = $this->extractEntityBody($xpath, $post, $id, 'post');

        return [
            'id' => $id,
            'title' => $title,
            'url' => $this->absoluteRedditUrl($permalink),
            'author' => $post->getAttribute('author'),
            'subreddit' => $post->getAttribute('subreddit-prefixed-name'),
            'created_at' => $this->nullableString($post->getAttribute('created-timestamp')),
            'score' => $this->nullableInt($post->getAttribute('score')),
            'comment_count' => $this->nullableInt($post->getAttribute('comment-count')),
            'post_type' => $this->nullableString($post->getAttribute('post-type')),
            'flair' => $this->extractPostFlair($xpath, $post),
            'body' => $body,
        ];
    }

    private function extractPostFlair(DOMXPath $xpath, DOMElement $post): ?string
    {
        foreach (['post-flair', 'link-flair-text', 'flair'] as $attribute) {
            $value = trim($post->getAttribute($attribute));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return list<array{
     *   id:string,depth:int,author:string,url:string,created_at:?string,score:?int,body:string,replies:list<array>
     * }>
     */
    private function extractCommentTree(DOMXPath $xpath, int $maxTopLevelComments): array
    {
        $result = [];
        $currentTopLevelIndex = null;
        $topLevelCount = 0;

        foreach ($this->findCommentEntities($xpath) as $comment) {
            if (!$this->looksLikeCommentEntity($comment)) {
                continue;
            }

            $depth = $this->commentDepth($comment);
            if ($depth === null) {
                continue;
            }

            if ($depth === 0) {
                if ($topLevelCount >= $maxTopLevelComments) {
                    break;
                }

                $parsed = $this->extractComment($xpath, $comment, $depth);
                $parsed['replies'] = [];

                $result[] = $parsed;
                $currentTopLevelIndex = array_key_last($result);
                $topLevelCount++;
                continue;
            }

            if ($depth === 1 && $currentTopLevelIndex !== null) {
                $result[$currentTopLevelIndex]['replies'][] = $this->extractComment($xpath, $comment, $depth);
            }

            // depth >= 2 intentionally ignored.
        }

        return $result;
    }

    /** @return list<DOMElement> */
    private function findCommentEntities(DOMXPath $xpath): array
    {
        $query = '//*['
            . 'starts-with(@id, "t1_")'
            . ' or starts-with(@thingid, "t1_")'
            . ' or (@depth and @author and @permalink)'
            . ' or starts-with(@aria-label, "Comment from ")'
            . ']';

        return $this->uniqueElements($xpath->query($query));
    }

    private function looksLikeCommentEntity(DOMElement $node): bool
    {
        $score = 0;

        $id = $this->entityId($node);
        if (str_starts_with($id, 't1_')) {
            $score += 5;
        }

        if ($node->hasAttribute('depth') && filter_var($node->getAttribute('depth'), FILTER_VALIDATE_INT) !== false) {
            $score += 3;
        }

        if ($node->hasAttribute('author')) {
            $score += 1;
        }
        if ($node->hasAttribute('permalink')) {
            $score += 1;
        }
        if ($node->hasAttribute('created-timestamp')) {
            $score += 1;
        }
        if (str_starts_with($node->getAttribute('aria-label'), 'Comment from ')) {
            $score += 1;
        }

        return $score >= 5;
    }

    private function extractComment(DOMXPath $xpath, DOMElement $comment, int $depth): array
    {
        $id = $this->entityId($comment);
        $body = $this->extractEntityBody($xpath, $comment, $id, 'comment');

        if ($body === '') {
            $body = $this->commentTextFallback($comment);
        }

        $createdAt = $comment->getAttribute('created-timestamp');
        if ($createdAt === '') {
            $createdAt = $this->firstAttributeRelative($xpath, $comment, './/time[@datetime]', 'datetime');
        }

        return [
            'id' => $id,
            'depth' => $depth,
            'author' => $comment->getAttribute('author'),
            'url' => $this->absoluteRedditUrl($comment->getAttribute('permalink')),
            'created_at' => $this->nullableString($createdAt),
            'score' => $this->nullableInt($comment->getAttribute('score')),
            'body' => $body,
        ];
    }

    private function extractEntityBody(
        DOMXPath $xpath,
        DOMElement $entity,
        string $thingId,
        string $kind,
    ): string {
        if ($thingId !== '') {
            $suffix = $kind === 'comment' ? '-comment-rtjson-content' : '-post-rtjson-content';
            $exactId = $thingId . $suffix;
            $node = $xpath->query('.//*[@id=' . $this->xpathLiteral($exactId) . ']', $entity)->item(0);
            if ($node !== null) {
                $text = $this->normalizeText($node->textContent);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        $needles = $kind === 'comment'
            ? ['-comment-rtjson-content', '-post-rtjson-content']
            : ['-post-rtjson-content'];

        foreach ($needles as $needle) {
            $node = $xpath->query('.//*[contains(@id, ' . $this->xpathLiteral($needle) . ')]', $entity)->item(0);
            if ($node !== null) {
                $text = $this->normalizeText($node->textContent);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    private function commentTextFallback(DOMElement $comment): string
    {
        /** @var DOMElement $clone */
        $clone = $comment->cloneNode(true);

        // Remove descendant comment entities without depending on their element name.
        $nested = [];

        // DOMXPath cannot query a detached clone against its original owner reliably,
        // so walk descendants directly and identify comment entities semantically.
        $stack = [];
        foreach ($clone->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $stack[] = $child;
            }
        }

        while ($stack !== []) {
            /** @var DOMElement $node */
            $node = array_pop($stack);

            if ($this->looksLikeDetachedCommentEntity($node)) {
                $nested[] = $node;
                continue;
            }

            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $stack[] = $child;
                }
            }
        }

        foreach ($nested as $node) {
            $node->parentNode?->removeChild($node);
        }

        return $this->normalizeText($clone->textContent);
    }

    private function looksLikeDetachedCommentEntity(DOMElement $node): bool
    {
        $id = $this->entityId($node);

        if (str_starts_with($id, 't1_')) {
            return true;
        }

        if ($node->hasAttribute('depth') && $node->hasAttribute('author')) {
            return true;
        }

        return str_starts_with($node->getAttribute('aria-label'), 'Comment from ');
    }

    private function entityId(DOMElement $node): string
    {
        $id = trim($node->getAttribute('id'));
        $thingId = trim($node->getAttribute('thingid'));

        if (str_starts_with($id, 't1_') || str_starts_with($id, 't3_')) {
            return $id;
        }

        if (str_starts_with($thingId, 't1_') || str_starts_with($thingId, 't3_')) {
            return $thingId;
        }

        return $id !== '' ? $id : $thingId;
    }

    private function commentDepth(DOMElement $comment): ?int
    {
        $value = trim($comment->getAttribute('depth'));

        return $value !== '' && filter_var($value, FILTER_VALIDATE_INT) !== false
            ? (int) $value
            : null;
    }

    /** @return list<DOMElement> */
    private function uniqueElements(DOMNodeList|false $nodes): array
    {
        if ($nodes === false) {
            return [];
        }

        $result = [];
        $seen = [];

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $objectId = spl_object_id($node);
            if (isset($seen[$objectId])) {
                continue;
            }

            $seen[$objectId] = true;
            $result[] = $node;
        }

        return $result;
    }

    private function isPromotedPost(DOMElement $post): bool
    {
        // Semantic/product-state attributes only; no CSS-class inspection.
        return $post->hasAttribute('promoted')
            || strtolower($post->getAttribute('post-type')) === 'ad'
            || $post->hasAttribute('is-promoted');
    }

    private function isPostUrl(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('~^/r/[^/]+/comments/[^/]+(?:/|$)~i', $path) === 1;
    }

    private function postThingIdFromUrl(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('~^/r/[^/]+/comments/([^/]+)(?:/|$)~i', $path, $matches) !== 1) {
            return null;
        }

        return 't3_' . $matches[1];
    }

    private function subredditFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('~^/r/([^/]+)~i', $path, $matches) !== 1) {
            return '';
        }

        return 'r/' . $matches[1];
    }

    private function normalizeRedditPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        return '/' . trim($path, '/') . '/';
    }

    private function absoluteRedditUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return self::REDDIT_ORIGIN . '/' . ltrim($url, '/');
    }

    private function loadHtml(string $html): DOMDocument
    {
        if (!class_exists(DOMDocument::class)) {
            throw new RuntimeException('RedditAdapter requires PHP ext-dom.');
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML(
                '<?xml encoding="utf-8" ?>' . $html,
                LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_COMPACT,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    private function firstAttributeRelative(
        DOMXPath $xpath,
        DOMNode $context,
        string $query,
        string $attribute,
    ): string {
        $node = $xpath->query($query, $context)->item(0);

        return $node instanceof DOMElement ? $node->getAttribute($attribute) : '';
    }

    private function xpathLiteral(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }

        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }

        $parts = explode("'", $value);
        $encoded = [];
        foreach ($parts as $index => $part) {
            if ($part !== '') {
                $encoded[] = "'" . $part . "'";
            }
            if ($index < count($parts) - 1) {
                $encoded[] = '"\'"';
            }
        }

        return 'concat(' . implode(',', $encoded) . ')';
    }

    private function normalizeText(?string $text): string
    {
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\R\s*/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function nullableInt(string $value): ?int
    {
        $value = trim($value);

        return $value !== '' && is_numeric($value) ? (int) $value : null;
    }

    private function nullableString(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}