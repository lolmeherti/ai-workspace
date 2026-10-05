<?php

/**
 * Separate date retrieval over the material the content extractor throws away.
 *
 * The extractor discards HEADER / FOOTER / ASIDE / NAV and everything outside the
 * article root. Publication and update information usually lives exactly there:
 * Wikipedia states "This page was last edited on ..." in its footer, Reuters puts
 * its dateline in the byline block above the body. This pass reads only that
 * discarded material, keeps the sentence that carries the date (so the kind can be
 * told apart), resolves what it can, attaches the result to the source, and throws
 * the rest away. It is deterministic: no model call, no index, no query.
 *
 * Metadata (<time datetime>, meta tags, JSON-LD) is used when present because it is
 * the most explicit signal, but it is never required — Wikipedia's line is plain
 * prose and must still resolve. No rule here makes a wrong association impossible:
 * a page that states several dates in its chrome can still be misread. Every result
 * therefore carries the evidence text it came from.
 *
 * Usage: php date-probe.php --snapshot=<id> [--json]
 */

$snapshot = null;
$asJson = false;
$noMetadata = false;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--snapshot=')) $snapshot = substr($arg, 11);
    if ($arg === '--json') $asJson = true;
    if ($arg === '--no-metadata') $noMetadata = true;   // prove the prose path alone
}
if (!$snapshot) {
    fwrite(STDERR, "FAIL: --snapshot=<id> is required.\n");
    exit(1);
}

$runDir = __DIR__ . '/runs/' . $snapshot;
$htmlPath = $runDir . '/page.html';
if (!is_file($htmlPath)) {
    fwrite(STDERR, "FAIL: {$htmlPath} not found.\n");
    exit(1);
}
$html = (string) file_get_contents($htmlPath);

// ── date shapes and labels ───────────────────────────────────────────────────
$MONTH = '(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*';
$DATE = '(?:\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?)?'
      . '|\d{1,2}\.\d{1,2}\.\d{4}'
      . '|\d{1,2}\s+' . $MONTH . '\s+\d{4}'
      . '|' . $MONTH . '\s+\d{1,2},\s+\d{4}'
      . '|\d{1,2}\s+de\s+[a-z]+\s+de\s+\d{4}'
      . '|\d+\s+(?:minute|hour|day|week|month|year)s?\s+ago'
      . '|vor\s+\d+\s+(?:Minuten?|Stunden?|Tagen?|Wochen?|Monaten?|Jahren?)'
      . '|\d{1,2}:\d{2}\s*(?:AM|PM)?\s*(?:GMT|UTC|CET|CEST|[+-]\d{1,2})?)';
$LABEL_PUB = '(?:published|posted|publish(?:ed)? on|edited by|ver(?:ö|o)ffentlicht|erschienen|stand)';
$LABEL_UPD = '(?:last edited|last modified|last updated|updated|aktualisiert|zuletzt (?:ge(?:ä|a)ndert|bearbeitet))';

/** Render an element's text with a separator between adjacent nodes.
 *  Raw textContent fuses inline elements: "September 30, 20269:01 PM".
 */
function spacedText(DOMNode $el): string
{
    $out = '';
    foreach ($el->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
            $out .= $child->nodeValue . ' ';
        } elseif ($child->nodeType === XML_ELEMENT_NODE) {
            $out .= spacedText($child) . ' ';
        }
    }
    return preg_replace('/\s+/u', ' ', $out) ?? $out;
}

libxml_use_internal_errors(true);
$doc = new DOMDocument();
$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
libxml_clear_errors();
$xp = new DOMXPath($doc);

$candidates = [];
$consideredChars = 0;

/** Record every date-shaped match in a region, with the sentence around it. */
function scanRegion(string $region, string $text, string $labelPub, string $labelUpd, array &$candidates, int &$considered): void
{
    $text = trim($text);
    $considered += mb_strlen($text);
    if ($text === '') return;
    global $DATE;
    // Sentence-ish windows so the date keeps its context but nothing else.
    $pattern = '/([^.!?|]{0,110}?(' . $DATE . ')[^.!?|]{0,50})/u';
    if (!preg_match_all($pattern, $text, $mm, PREG_SET_ORDER)) return;
    foreach ($mm as $m) {
        $sentence = trim(preg_replace('/\s+/u', ' ', $m[1]) ?? $m[1]);
        $kind = 'unknown';
        if (preg_match('/' . $labelUpd . '/iu', $sentence)) $kind = 'update';
        elseif (preg_match('/' . $labelPub . '/iu', $sentence)) $kind = 'publication';
        $candidates[] = [
            'region'   => $region,
            'kind'     => $kind,
            'text'     => $sentence,
            'date'     => trim($m[2] ?? $m[0]),
        ];
    }
}

// ── 1. explicit metadata (supplement, never a dependency) ────────────────────
$meta = [];
foreach ($xp->query('//meta[@property or @name or @itemprop]') as $node) {
    $key = strtolower($node->getAttribute('property') ?: ($node->getAttribute('name') ?: $node->getAttribute('itemprop')));
    $val = trim($node->getAttribute('content'));
    if ($val === '') continue;
    if (!$noMetadata && preg_match('/^(article:published_time|og:published_time|datepublished|date|pubdate|dc\.date|article:modified_time|og:updated_time|datemodified|last-modified)$/', $key)) {
        $kind = preg_match('/modif|updated|last-modif/', $key) ? 'update' : 'publication';
        $meta[$kind][] = ['key' => $key, 'val' => $val];
    }
}
foreach ($noMetadata ? [] : $xp->query('//script[@type="application/ld+json"]') as $node) {
    $json = json_decode(trim($node->textContent), true);
    if (!is_array($json)) continue;
    $walk = static function ($n) use (&$walk, &$meta): void {
        if (!is_array($n)) return;
        foreach ($n as $k => $v) {
            if (is_array($v)) { $walk($v); continue; }
            $k = strtolower((string) $k);
            if ($k === 'datepublished' && $v) $meta['publication'][] = ['key' => 'json-ld datePublished', 'val' => (string) $v];
            if ($k === 'datemodified' && $v) $meta['update'][] = ['key' => 'json-ld dateModified', 'val' => (string) $v];
            // Job postings (JobPosting JSON-LD) state datePosted, not datePublished —
            // searching only for the latter reported "no date" on pages that have one.
            if ($k === 'dateposted' && $v) $meta['publication'][] = ['key' => 'json-ld datePosted', 'val' => (string) $v];
        }
    };
    $walk($json);
}
// <time datetime> anywhere — the most explicit visible signal.
$timeNodes = [];
if ($noMetadata) $xpTime = new DOMXPath($doc);
foreach ($noMetadata ? [] : $xp->query('//time[@datetime]') as $node) {
    $region = 'body';
    for ($p = $node; $p instanceof DOMElement; $p = $p->parentNode) {
        $tag = strtoupper($p->tagName);
        if (in_array($tag, ['HEADER', 'FOOTER', 'ASIDE', 'NAV', 'BODY'], true)) { $region = strtolower($tag); break; }
    }
    $timeNodes[] = [
        'region' => $region,
        'val' => trim($node->getAttribute('datetime')),
        'text' => trim(preg_replace('/\s+/u', ' ', spacedText($node)) ?? ''),
    ];
}

// ── 2. the discarded chrome: HEADER / FOOTER / ASIDE / NAV ───────────────────
foreach (['header', 'footer', 'aside', 'nav'] as $tag) {
    foreach ($xp->query('//' . $tag) as $el) {
        scanRegion($tag, spacedText($el), $LABEL_PUB, $LABEL_UPD, $candidates, $consideredChars);
    }
}

// ── 3. the byline region: text in the title's ancestor before the body starts ─
$h1 = $xp->query('//h1')->item(0);
if ($h1 instanceof DOMElement && $h1->parentNode instanceof DOMElement) {
    // Only the headline's own siblings: a byline or dateline sits next to the
    // headline. Climbing to an ancestor drags the article body (and its reference
    // list) into a region that is supposed to be chrome.
    $anchor = $h1->parentNode;
    $bylineText = '';
    foreach ($anchor->childNodes as $child) {
        if ($child->isSameNode($h1)) break;
        if ($child instanceof DOMElement) $bylineText .= spacedText($child) . ' ';
        elseif ($child->nodeType === XML_TEXT_NODE) $bylineText .= $child->nodeValue . ' ';
    }
    if (trim($bylineText) !== '') {
        scanRegion('byline-before-headline', mb_substr($bylineText, -1200), $LABEL_PUB, $LABEL_UPD, $candidates, $consideredChars);
    }
    $after = '';
    $seenH1 = false;
    foreach ($anchor->childNodes as $child) {
        if ($child->isSameNode($h1)) { $seenH1 = true; continue; }
        if (!$seenH1) continue;
        if ($child instanceof DOMElement) $after .= spacedText($child) . ' ';
        elseif ($child->nodeType === XML_TEXT_NODE) $after .= $child->nodeValue . ' ';
        if (mb_strlen($after) > 700) break;
    }
    if (trim($after) !== '') {
        scanRegion('byline-after-headline', $after, $LABEL_PUB, $LABEL_UPD, $candidates, $consideredChars);
    }
}

// ── rank: explicit metadata > labelled chrome > unlabelled chrome ────────────
$rank = static function (array $c): int {
    $base = ['header' => 1, 'footer' => 2, 'byline-before-headline' => 2, 'byline-after-headline' => 2, 'aside' => 4, 'nav' => 5][$c['region']] ?? 3;
    if ($c['kind'] === 'publication') $base -= 1;
    return $base;
};
usort($candidates, static fn(array $a, array $b): int => $rank($a) <=> $rank($b));

$pick = static function (string $kind, array $cands, array $meta, array $timeNodes) use ($rank): ?array {
    foreach ((array) ($meta[$kind] ?? []) as $m) {
        return ['text' => $m['key'] . ' = ' . $m['val'], 'date' => $m['val'], 'kind' => $kind, 'region' => 'metadata', 'signal' => 'metadata'];
    }
    foreach ($timeNodes as $t) {
        if ($kind === 'update' && !preg_match('/modifi|updated/i', $t['region'] . $t['text'])) continue;
        return ['text' => $t['text'] !== '' ? $t['text'] : '<time datetime>', 'date' => $t['val'], 'kind' => $kind, 'region' => 'time[@datetime]', 'signal' => 'time-element'];
    }
    foreach ($cands as $c) {
        if ($c['kind'] === $kind) return $c + ['signal' => 'labelled-text'];
    }
    return null;
};

// Dateline pattern: "September 30, 2026 9:01 PM GMT+2 Updated September 30, 2026" —
// when a sentence carries an explicit update label, the date standing before it is
// the publication date. Heuristic, and it keeps the sentence as evidence.
$datelinePublished = null;
foreach ($candidates as $c) {
    if (!preg_match('/(.{6,60}?(' . $DATE . '))[^.!?]{0,60}?(?:updated|last modified|last updated|aktualisiert)/iu', $c['text'], $m)) continue;
    $datelinePublished = ['text' => trim($m[1]), 'date' => trim($m[2]), 'kind' => 'publication',
                          'region' => $c['region'], 'signal' => 'dateline-pattern'];
    break;
}
if ($datelinePublished) $datelinePublishedOnly = $datelinePublished;   // used below, not via $meta

$published = $pick('publication', $candidates, $meta, $timeNodes);
if ($published === null && isset($datelinePublishedOnly)) $published = $datelinePublishedOnly;
$updated = $pick('update', $candidates, $meta, $timeNodes);
// A lone unlabelled/ambiguous date is reported as such rather than promoted.
$unknown = null;
if (!$published && !$updated && $candidates) $unknown = $candidates[0]['text'] . ' [' . $candidates[0]['date'] . ']';

$result = [
    'snapshot'          => $snapshot,
    'url'               => '', // filled from capture meta when present
    'publishedAt'       => $published['date'] ?? null,
    'published_evidence' => $published['text'] ?? null,
    'published_signal'  => $published['signal'] ?? null,
    'published_evidence_kept' => $published['text'] ?? null,
    'updatedAt'         => $updated['date'] ?? null,
    'updated_evidence'  => $updated['text'] ?? null,
    'updated_signal'    => $updated['signal'] ?? null,
    'ambiguous_date'    => $unknown,
    'metadata_disabled' => $noMetadata,
    'metadata_seen'     => $meta,
    'time_elements'     => $timeNodes,
    'chrome_considered_chars' => $consideredChars,
    'chrome_kept_chars' => mb_strlen(($published['text'] ?? '') . ($updated['text'] ?? '')),
    'discarded_chars'   => max(0, $consideredChars - mb_strlen(($published['text'] ?? '') . ($updated['text'] ?? ''))),
    'candidates'        => $candidates,
    'note'              => 'Chrome-only deterministic scan. Evidence text is kept so the kind can be judged; '
                         . 'nothing here guarantees a correct association on a page that states several dates.',
];
$metaFile = $runDir . '/capture-meta.json';
if (is_file($metaFile)) {
    $cm = json_decode((string) file_get_contents($metaFile), true);
    $result['url'] = $cm['url'] ?? '';
    $result['title'] = $cm['title'] ?? '';
}
file_put_contents($runDir . '/date-probe.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

if ($asJson) { echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n"; exit(0); }

printf("SNAPSHOT %s\n  %s\n", $snapshot, $result['url'] ?: ($result['title'] ?? ''));
printf("  published : %s\n", $result['publishedAt'] ?? '(none resolved)');
if ($result['published_evidence']) printf("    evidence: %s\n", $result['published_evidence']);
if ($result['published_signal']) printf("    signal  : %s\n", $result['published_signal']);
printf("  updated   : %s\n", $result['updatedAt'] ?? '(none resolved)');
if ($result['updated_evidence']) printf("    evidence: %s\n", $result['updated_evidence']);
if ($result['updated_signal']) printf("    signal  : %s\n", $result['updated_signal']);
if ($result['ambiguous_date']) printf("  ambiguous : %s\n", $result['ambiguous_date']);
printf("  chrome: %d chars considered, %d kept as evidence, %d discarded\n",
    $result['chrome_considered_chars'], $result['chrome_kept_chars'], $result['discarded_chars']);
printf("  candidates from the discarded chrome (%d):\n", count($result['candidates']));
foreach (array_slice($result['candidates'], 0, 8) as $c) {
    printf("    [%-22s %-11s] %s\n", $c['region'], $c['kind'], mb_substr($c['text'], 0, 132));
}
