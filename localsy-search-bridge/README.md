# Localsy Search Bridge MVP

> **Superseded — Aug 2026.** SearXNG and snippet-only mode were removed. `search_web`
> is bridge-only now; a bridge outage surfaces as an explicit "web search unavailable
> / no results" message instead of degrading to snippets. See
> `.hermes/plans/searxng-removal-handoff.md`.

## Load into Edge

1. Unzip this folder.
2. Open `edge://extensions`.
3. Enable **Developer mode**.
4. Click **Load unpacked** and select this folder.

## One-shot PowerShell test

From this folder:

```powershell
powershell -ExecutionPolicy Bypass -File .\test-search-bridge.ps1 -Query "Gemma 4 E4B mmproj llama.cpp"
```

The script listens on `ws://127.0.0.1:8765/` and waits for the extension.
If it does not connect immediately, click **Reload** on the extension card in
`edge://extensions` (the extension also retries periodically).

Expected flow:

1. PowerShell prints `Bridge connected`.
2. Edge opens an inactive Google search tab.
3. The extension extracts up to 10 organic `{position,title,url,snippet}` results.
4. The tab closes.
5. PowerShell prints the result table and raw JSON.

If Google presents consent or CAPTCHA, the extension reports that status and
activates the tab instead of silently returning an empty result set.

## Known issues

### Google DOM classes are unstable

`VwiC3b`, `IsZvec`, `MjjYud`, `[data-snhf]` are minified class names that Google
changes periodically. The `findUsefulAncestor()` fallback in `google-serp.js`
provides some resilience, but when the DOM structure shifts, snippet extraction
can degrade or fail. No fix — the content script is only 136 lines and painless
to patch when breakage is observed.

### Consent wall on /search is misreported

Google sometimes puts the "Before you continue" wall directly on the `/search`
page as an overlay rather than redirecting to consent.google.com. The content
script's `location.pathname !== "/search"` guard does not catch this case —
it enters the deadline loop, never finds results, and incorrectly reports
`no_results` instead of `consent_required`. The user never sees the tab because
`no_results` triggers tab-close, not tab-activate.

### First-run always hits consent

A fresh browser profile hitting Google for the first time always gets the
cookie consent interstitial. The bridge's first query after extension install
will likely trigger this. After the user clicks through once, subsequent
searches proceed normally. This is expected behaviour but worth knowing so the
first run is not mistaken for a broken bridge.

## How the pipeline works (current state)

### Full flow

```
User clicks search card
  → LLM formulates search_web QUERY:<terms>
  → Cache evaluation (AskUserPolicy → SemanticCacheEvaluator two-shot)
  → SearchPipeline::run()
      │
      ├─ Bridge connected? ──→ runBridgeMode()
      │    1. BridgeFetcher::searchSERP() → Go relay → extension → Google SERP
      │       Returns Candidate[]{position, title, url, snippet}
      │    2. CandidateDeduplicator (URL canonicalization, per-domain cap: 2)
      │    3. MAX_SEARCH_RESULTS_TO_SCRAPE cap (default: 3)
      │    4. FOR each candidate (sequential, blocking):
      │         a. BridgeFetcher::fetch(url) → Go relay → extension → open tab → extract
      │         b. generic-extractor.js: TreeWalker over visible DOM, 500ms poll
      │            until >200 chars, max 10s wait. Returns {entities: [{body, sections}]}
      │         c. chunksFromBridgeContent() → WebChunk[] with source metadata
      │         d. Accumulate into allChunks[]
      │         e. Bm25Retriever::rank(allChunks, query) → selected chunks
      │         f. CoverageTracker::updateFromChunks(selected)
      │         g. BREAK if allRequiredTargetsCovered() AND fetched_urls >= MIN_EVIDENCE_SOURCES (default: 2)
      │    5. Final BM25 over all accumulated chunks
      │    6. Three-level evidence fitting:
      │         Level 1: exact chunks fit budget → pass directly
      │         Level 2: ExtractiveCompressor → sentence/paragraph reduction
      │         Level 3: SourceCondenser → per-source LLM condensation → hard-capped evidence ledger
      │    7. Return {evidence: XML block with <source>/<claim> citations, sourceIds, sourceUrls}
      │
      └─ Bridge disconnected? ──→ runSnippetMode()
            SearXNG → 12 candidates → dedup → EvidenceBuilder::fromSnippets()
            Returns {evidence: XML <source>/<snippet> block, sourceIds: [], sourceUrls: []}
```

### Early stopping (CoverageTracker)

`CoverageTracker` runs incrementally after each URL to avoid fetching more than needed.

**How it works**: `extractTargets()` splits the query on "and"/"or"/commas, strips filler
words, produces a list of noun phrases. After each URL is fetched, chunked, and BM25-ranked,
`updateFromChunks()` checks each target against the selected chunks using term overlap
(threshold: 0.6). When ALL targets have at least one supporting source, the tracker reports
coverage complete.

**Known failure modes**:

| Failure | What happens | Why |
|---|---|---|
| Page has heading but no data | "F1 Standings 2026" title, body is JS-lazy-loaded. Extractor gets "Loading standings..." → score passes 0.6, tracker says done, actual data never fetched. | Term matching doesn't understand content semantics — a heading match passes the threshold regardless of whether actual data is present. |
| Wrong entity | "reset router password" extracts `["reset","router","password"]`. A Windows password-reset page scores 0.6 on "reset"+"password." Tracker says done, answer is about Windows, not the router. | Term overlap is context-free. The query terms appear in unrelated pages with high BM25 scores. |
| Synonym gap | "how long macbook lasts unplugged" extracts "unplugged." First page says "18 hour battery life" but never says "unplugged." Score fails. Fetches 9 more URLs that use "battery life" — answer was in URL #1 all along. | No semantic expansion. The tracker misses coverage that a human would recognize immediately. |
| Duplicate sources | Page 1 covers all targets, page 2 is a syndicated copy of page 1. `MIN_EVIDENCE_SOURCES=2` satisfied. Both say the same thing — zero independent corroboration. | Source-level dedup is URL-based only. Content dedup would require semantic comparison. |

**What would be better**: an LLM-based verification after each fetch that asks "Does this
page actually contain the data the user asked for?" and decides whether to stop. But that's
a per-URL LLM call (expensive) and the current design defers verification to the final
answer step — the model either has enough evidence or says what's missing.

### Config variables

| Variable | Default | Effect |
|---|---|---|
| `MAX_SEARCH_RESULTS_TO_SCRAPE` | 3 | Max URLs to fetch in bridge or snippet mode |
| `MIN_EVIDENCE_SOURCES` | 2 | Min distinct sources before early-stop kicks in |
| `MAX_WEB_CONTEXT_TOKENS` | 8192 | Evidence budget for three-level fitting |
| `BRIDGE_HOST` | `host.docker.internal` | IP of the Go HTTP server (set automatically by Go binary via `util.GetWindowsHostIP()`) |
| `BRIDGE_HTTP_PORT` | 9876 | Port the Go HTTP server listens on |

### Diagnostic events (visible at /logs)

| Event | Source | What it shows |
|---|---|---|
| `bridge_check_failed` | BridgeFetcher | Curl error or disconnected status |
| `search_mode` | SearchPipeline | Bridge vs snippet mode selection |
| `bridge_serp` | SearchPipeline | Raw candidate count from Google SERP |
| `bridge_fallback` | SearchPipeline | Why bridge mode fell back to snippets |
| `bridge_fetch` | BridgeFetchLogger | Per-URL fetch result (status, body_len, entity_count) |
| `bridge_evidence` | SearchPipeline | Total chunks, selected chunks, evidence length |
| `tool_executed` | ToolExecutionService | Tool result length + first/last 200 chars |
| `tool_turn_second_pass` | ChatManager | Messages sent to the second LLM call (roles, lengths, previews) |
| `search_pipeline_ok` / `search_pipeline_failed` | SearchWebTool | Pipeline success or fallback to legacy |

## Session checkpoint (August 11, 2026)

Architecture is locked. The browser is the primary crawler — SearXNG stays as
SERP fallback in snippet-only mode. FlareSolverr is removed entirely.

### What we decided

- **Go relay** on :8765 (WS for extension) and :9876 (HTTP endpoints for PHP)
- **Extension** handles SERP extraction (already built) + page extraction (Reddit first, then generic)
- **PHP** checks bridge presence once per search, then sequential blocking per URL
- **Entity-based schema**: one page → multiple entities (post, comments, replies), each with own canonical URL
- **Entity-level citations**: S1 = post, S2 = comment, S3 = reply — each maps to its own permalink
- **Single blocking CAPTCHA**: extension polls internally, no PHP retry loop
- **No bridge = snippet-only**: SearXNG SERP, no crawling, snippets as evidence

### Files to know

- Full plan: `C:\Users\Admin-PC\Desktop\ai-workspace\.hermes\plans\localsy-search-bridge.md`
- Architecture doc: `C:\Users\Admin-PC\Desktop\ai-workspace\localsy-search-bridge.md`
- Extension source: `C:\Users\Admin-PC\Desktop\ai-workspace\localsy-search-bridge\`
- PHP adapter: `C:\Users\Admin-PC\Desktop\ai-workspace\src\App\Adapters\Reddit.php` (to port to extension)
- PHP pipeline: `C:\Users\Admin-PC\Desktop\ai-workspace\src\App\Search\SearchPipeline.php`
- Go HTTP server: `C:\Users\Admin-PC\Desktop\ai-workspace\internal\launcher\httpserver.go`

### Implementation order

1. Go relay bridge (`internal/bridge/relay.go`) — testable standalone
2. Extension page extraction — Reddit extractor, then generic
3. PHP BridgeFetcher + SearchPipeline mode switch
4. Snippet-only fallback + entity-aware citations
5. FlareSolverr cleanup (last)

### Handoff prompt for next session

```
Continue the Localsy Search Bridge implementation. The full plan is at
.hermes/plans/localsy-search-bridge.md. The architecture is locked —
browser as primary crawler, Go relay on :8765/:9876, entity-based citations,
single blocking CAPTCHA handling, no FlareSolverr.

Start with Phase 1: Go relay bridge. Create internal/bridge/relay.go with
WebSocket server on :8765 and HTTP endpoints /bridge/status + /bridge/fetch
on :9876 (added to existing modelsHandler in httpserver.go).

Load the localsy-development skill first. Project at C:\Users\Admin-PC\Desktop\ai-workspace.
Extension lives at localsy-search-bridge/. Never run live tests.
```