# Localsy Search Bridge

> **Superseded — Aug 2026.** SearXNG and snippet-only mode were removed. `search_web`
> is bridge-only now; a bridge outage surfaces as an explicit "web search unavailable
> / no results" message instead of degrading to snippets. See
> `.hermes/plans/searxng-removal-handoff.md`.

Browser-based search and retrieval. The user's Edge browser handles SERP extraction,
page rendering, and site-specific structured extraction. SearXNG provides SERP when
the bridge is unavailable. FlareSolverr is removed entirely — it never worked
reliably and has no place in a single-user local agent.

## Architecture

```
User asks question
  → PHP ChatManager → SearchPipeline::run()
      │
      ├── Check bridge presence (Go relay)
      │     │
      │     ├── BRIDGE ALIVE:
      │     │     ├── SERP via bridge (Google search, extension extracts)
      │     │     ├── For each candidate URL (sequential, blocking):
      │     │     │     ├── Extension opens inactive tab
      │     │     │     ├── Site-specific JS extracts structured content
      │     │     │     ├── Returns {type, title, body, sections[]}
      │     │     │     └── PHP feeds directly to StructuralChunker
      │     │     └── BM25 → evidence fitting → coverage check
      │     │           → EvidenceBuilder → answer with citations
      │     │
      │     └── BRIDGE NOT ALIVE:
      │           ├── SERP via SearXNG queryCandidates()
      │           ├── NO crawling — URLs are not fetched
      │           ├── Evidence = SERP snippets only
      │           └── Answer cites snippets as untrusted evidence
      │
      └── EvidenceBuilder → answer
```

### Two modes

| Mode | Condition | SERP | Crawling | Evidence quality |
|------|-----------|------|----------|-----------------|
| Full | Bridge connected | Browser Google search | Extension opens tabs, site-specific JS extraction | Full page content, structured |
| Snippet-only | Bridge not connected | SearXNG | None — snippets only | Title + URL + snippet per result |

Snippet-only is the cost of not having the bridge. The model still gets ranked
candidates with titles and snippets. It can't verify claims against full page
content, but it has more than nothing. This is intentional — don't build
infrastructure that doesn't work (FlareSolverr) just to say you have it.

## Component roles

| Component | Role |
|-----------|------|
| Edge extension | SERP extraction, page rendering, site-specific JS extraction, generic fallback extraction |
| Go binary | Persistent WebSocket relay bridge: PHP HTTP → Go → extension WS → response. Bridge presence tracking. |
| PHP pipeline | Sequential orchestration: candidate → fetch → chunk → BM25 → condense → next candidate |
| SearXNG | SERP source when bridge is unavailable. No page fetching. |

## Go relay bridge

The Go binary runs persistently alongside the systray. Adds two HTTP endpoints
and one WebSocket relay:

```
PHP ──GET  /bridge/status──→ Go  → {connected: true/false}
PHP ──POST /bridge/fetch───→ Go ──WebSocket──→ Extension
  {url, request_id}              {action:"fetch", url, request_id}
                                   │
PHP ←──HTTP response────── Go ←──fetch_result────────┘
  {status, content}            {type:"fetch_result", request_id, status, content}
```

### /bridge/status

Called once at the start of each search. Returns `{connected: true}` if an
extension is connected and has heartbeated within the last 60s. PHP uses this
to decide: full mode or snippet-only mode. No per-request probe needed.

### /bridge/fetch

Called for each candidate URL in full mode. PHP blocks on the HTTP call with
a configurable timeout (default 15s per URL). Go blocks on the WebSocket response.
No polling, no Redis, no state machine.

### Presence tracking

Extension sends `{type:"ping", ts:...}` every 20s. Go tracks last ping timestamp.
If no ping within 60s, bridge is considered disconnected. The Go binary detects
TCP close on the WebSocket and marks disconnected immediately.

## Extension capabilities

### SERP extraction (already built)

Google search → `{position, title, url, snippet}` per result. Content script
scoped to `google.com/search*`. Proven working via PowerShell test harness.

### Page extraction (to build)

Per-domain content scripts that parse rendered DOM into normalized structured content.
The extension runs them in inactive tabs. If the page is a CAPTCHA/humanity challenge,
the extension reports `challenge_required`, activates the tab, and waits for retry.

**Site-specific extractors:**

| Site | Extractors |
|------|-----------|
| Reddit (`reddit.com`, `old.reddit.com`) | t3_/t1_ entity discovery, post body via rtjson-content, comment tree depth=0+1 |
| GitHub (`github.com`) | Issue/PR body, top comments, code blocks, status labels |
| Stack Overflow (`stackoverflow.com`) | Question + accepted answer + top voted, code with language hints |
| Wikipedia (`wikipedia.org`) | Infobox key-values, body text with heading path, citation footnotes |

**Generic extractor** for all other domains: `document.body.innerText` with heading
hierarchy preserved. Less structured than site-specific but better than nothing.

### Content schema

All extractors return the same normalized shape:

```json
{
  "type": "article",
  "url": "https://example.com/page",
  "title": "Page Title",
  "fetched_at": "2026-08-11T19:00:00Z",
  "body": "Full extracted text with preserved structure",
  "metadata": {
    "author": "username",
    "published": "2026-01-15T00:00:00Z",
    "site": "reddit",
    "score": 142,
    "comment_count": 28
  },
  "sections": [
    {
      "heading": "Section Name",
      "heading_level": 2,
      "body": "Section text content"
    }
  ]
}
```

PHP feeds this directly to `StructuralChunker` — no `ContentExtractor` step.
Metadata keys vary by site but `type`, `url`, `title`, `body` are always present.

## Citations

The bridge produces entity-aware content, which means citations can be more
precise than the current source-level model.

### Current citation model (pipeline without bridge)

```
[S1] Source 1 — reddit.com/r/LocalLLaMA — "Best Gemma4 llama.cpp command"
  [S1-C4] chunk from that source
```

One level: source → chunks. No distinction between post body and comments.

### Bridge citation model

The extension knows the entity type and authorship of every piece of content:

```json
{
  "source_id": "S1",
  "url": "https://reddit.com/r/LocalLLaMA/comments/1sbpf86/",
  "title": "Best Gemma4 llama.cpp command switches",
  "domain": "reddit.com",
  "content": [
    {
      "entity_type": "post",
      "author": "u/llama_fan",
      "score": 142,
      "published": "2026-07-15T12:00:00Z",
      "sections": [...]
    },
    {
      "entity_type": "comment",
      "depth": 0,
      "author": "u/cpp_expert",
      "score": 89,
      "published": "2026-07-15T14:30:00Z",
      "body": "..."
    },
    {
      "entity_type": "reply",
      "depth": 1,
      "author": "u/llama_fan",
      "score": 34,
      "body": "..."
    }
  ]
}
```

The evidence block can now cite with entity context:

```
[S1] reddit.com/r/LocalLLaMA — "Best Gemma4 llama.cpp command"
  [S1-post]     OP by u/llama_fan, score 142
  [S1-comment]  u/cpp_expert, score 89
  [S1-reply]    u/llama_fan, score 34
```

The model can now say: "According to u/cpp_expert (score 89) on Reddit [S1], the
recommended flags are..." — distinguishing between the OP's claim and a
highly-upvoted community response. This matters because a post title might ask
a question, but the answer is in the comments.

### Generic extractor citations

For domains without site-specific extractors, the generic extractor can still
provide basic structure:

```
[S2] example.com/blog/post
  [S2-body]   Main article text
```

Less granular but still source-labelled.

### EvidenceBuilder integration

`EvidenceBuilder` already supports `buildTexts()` for source-labelled evidence
blocks. The chunk model (`WebChunk`) carries `source_id`, `chunk_id`, `heading_path`,
and `section_type`. Adding `entity_type`, `author`, and `score` to the chunk
metadata allows the evidence block formatter to include entity context without
changing the chunking or BM25 pipeline at all.

## Status values

| Status | Meaning | Tab behavior |
|--------|---------|--------------|
| `success` | Structured content extracted | Close tab |
| `challenge_required` | CAPTCHA / "prove your humanity" | Activate tab, wait for retry |
| `no_bridge` | Extension not connected | N/A (Go responds immediately) |
| `timeout` | Extension didn't respond within deadline | Close tab, skip URL |
| `parse_failed` | Page loaded but extraction found nothing | Close tab, skip URL |

### Retry flow for challenge_required

1. Extension opens tab, detects CAPTCHA, activates tab, reports `challenge_required`.
2. PHP receives status, waits (user solves CAPTCHA in browser).
3. PHP retries the same URL via bridge.
4. Extension detects the tab is already open at the target URL, extracts content,
   reports `success`.

PHP caps retries at 3 per URL with 30s total timeout to prevent indefinite blocking.

## PHP pipeline integration

`SearchPipeline::run()` checks bridge status once at the start:

```php
$bridgeAvailable = BridgeFetcher::isConnected();

if ($bridgeAvailable) {
    // Full mode: bridge SERP + crawl
    $candidates = BridgeFetcher::searchSERP($query);
    foreach ($candidates as $candidate) {
        $content = BridgeFetcher::fetch($candidate->url);
        if ($content === null) continue;  // timeout, skip
        // Feed structured content directly to chunker
        $chunks = $this->chunker->chunk($content);
        // ... BM25, evidence fitting, coverage check
    }
} else {
    // Snippet-only mode: SearXNG SERP, no crawling
    $candidates = Search::queryCandidates($query, 12, $intent);
    // Build evidence from snippets directly
    $evidence = EvidenceBuilder::fromSnippets($candidates);
    return ['evidence' => $evidence, 'sourceIds' => [...]];
}
```

`EvidenceBuilder::fromSnippets()` is new — takes `Candidate[]` and builds an
evidence block from snippets alone, with source URLs and titles. The model
gets ranked results with extracted snippets but no verified page content.
This is the dumbed-down fallback.

## What's built

- Extension: manifest v3, service worker (WebSocket client, search dispatch, tab
  management), Google SERP content script — all working
- Test harness: PowerShell WebSocket server, verified with live Google searches
- PHP: `Reddit` adapter (entity-based semantic extraction) — ready to port to extension

## What needs building

1. **Go relay bridge** — `/bridge/status`, `/bridge/fetch`, WebSocket relay, presence tracking. First priority.
2. **Extension page extraction** — per-domain content scripts (Reddit first) + generic fallback
3. **PHP BridgeFetcher** — wraps Go relay HTTP calls, `isConnected()` + `fetch()` + `searchSERP()`
4. **SearchPipeline mode switch** — bridge-alive full mode vs snippet-only fallback
5. **EvidenceBuilder::fromSnippets()** — builds evidence block from Candidate[] snippets
6. **challenge_required retry** — PHP retry loop with activation handshake

## Not in scope (v1)

- FlareSolverr — removed
- Firefox support (Chromium MV3 first)
- Multiple simultaneous browser bridges
- Browser launch / CDP
- Moving pipeline orchestration to Go (blocking PHP model is intentional)
- Scraper / FetchSafety / OutboundScheduler — kept for historical reference, not used in active paths
