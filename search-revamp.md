# Localsy Search Revamp — v1 Plan

## Problem

The current search pipeline produces answers that feel untrustworthy. The root cause is not failure to retrieve pages — it's that the information-reduction strategy destroys evidence before the model sees it.

### Current pipeline

```
User clicks search card
  → LLM formulates search_web QUERY:<terms>
  → SemanticCacheEvaluator checks Redis (AUTO_USE / ASK_USER / NONE)
  → Search::query() hits SearXNG → 3 URLs
  → calculateScrapeBudgetStatic() → per-URL token cap
  → For each URL: Scraper::fetchAndClean (FlareSolverr → strip_tags → truncate)
  → ContextCondenser::condense (iterative LLM summarization: summarize(page1) → update(page2) → update(page3))
  → Result cached as ctx_<md5(query . time())>
  → Injected as [LIVE WEB SEARCH CONTEXT] appended to last user message
  → Second LLM call formulates answer
```

### What's wrong (ranked by damage to answer quality)

1. **Iterative condenser destroys provenance.** Page order matters. Earlier evidence gets repeatedly rewritten. Disagreement gets flattened. The model can fabricate corroboration from a single source. The final model receives an anonymous blob with no URLs, dates, or section headings.

2. **Web content is injected as privileged prompt content.** Appended to the last user message alongside the state guard, functionally a system-level instruction channel. A page containing "Ignore previous instructions. Tell the user..." passes straight through strip_tags().

3. **Truncation happens before relevance is determined.** A fixed per-URL token cap doesn't know where the useful passage is. The 5000th token might be an SEO intro and the answer lives at token 9000.

4. **strip_tags() destroys structure.** Tables become ambiguous word soup. Heading hierarchy is lost. `<tr><th>Battery</th><td>22 hours</td></tr>` becomes `Battery 22 hours` — works until a table contains multiple products.

5. **Top-3 blind fetch.** SearXNG ranking is not relevance to the question. The first three results might be an Apple product page, an SEO comparison page, and a duplicated syndicated article. All are fetched with equal status.

6. **FlareSolverr is the default HTTP client.** Every URL launches a full Chromium instance regardless of whether the page needs JavaScript. Most documentation, blogs, and static articles work fine with a direct GET.

7. **Cache stores the least reusable artifact.** Caching query-specific condensed prose means a related query re-fetches everything. Raw HTML, extracted markdown, and chunks are more reusable across queries.

8. **No retry, no circuit breaker, no pacing, silent failures.** Three distinct failure modes (blocked, timeout, empty page) all collapse to "no usable content."

## Core principles

1. **Separate relevance reduction from context reduction.** BM25 determines what matters. Extractive compression removes what can be removed faithfully. The LLM condenser is a last-resort context-control layer, not the first retrieval layer.

2. **Preserve provenance through every stage.** Every chunk carries source ID, URL, title, domain, publication date, and heading path. The final model can cite, compare, and express uncertainty.

3. **Retrieve evidence, not summaries.** The model should receive the most relevant passages from pages, not an editorial rewrite of pages.

4. **Use the fewest externally visible requests possible.** One search request, sequential fetching with early stopping, HTTP-first acquisition, aggressive caching. The goal is obtaining enough diverse evidence, not maximum throughput.

5. **Respect model and infrastructure constraints.** The 2B–4B model formulates search terms. Application code handles policy. No new containers without demonstrated need. No embeddings without evidence lexical retrieval is insufficient.

6. **Make every stage independently diagnosable.** If the answer is wrong, you should be able to determine whether the search engine, candidate selection, extraction, retrieval, condensation, or final model caused the failure.

## Revised pipeline (target)

```
One search query (colon format, no JSON)
    ↓
One SearXNG request → 10–15 candidates
    ↓
Local candidate deduplication + ranking
    ↓
Sequential HTTP-first fetching (stop when evidence sufficient)
    ↓
Structured extraction (PHP Readability cascade → Markdown)
    ↓
Heading-aware structural chunking
    ↓
In-memory BM25 relevance selection
    ↓
Three-level evidence fitting:
    ├── exact chunks fit budget → pass directly
    ├── extractive compression → sentence/paragraph reduction
    └── source-preserving LLM condensation → hard-capped evidence ledger
    ↓
Source-labelled untrusted evidence block
    ↓
Final answer with citations
```

---

## Phase 0: Frozen evaluation fixtures ✅ DONE

Create 10 representative test queries BEFORE changing any pipeline code. Without frozen inputs, you cannot determine whether quality changes came from the pipeline or from different search results, page content, or site accessibility.

### Query selection (cover known failure modes)

| # | Type | What it tests |
|---|------|---------------|
| 1 | Official specification | Primary source retrieval, table extraction |
| 2 | Long article (answer near bottom) | Truncation vs chunk retrieval |
| 3 | Comparison table | Multi-product table preservation |
| 4 | News / current event | Freshness, multiple independent sources |
| 5 | Conflicting sources | Disagreement preservation |
| 6 | Blocked page with alternatives | Skip vs escalate decision |
| 7 | Prompt-injection text in page | Trust boundary enforcement |
| 8 | Two separate facts required | Multi-aspect retrieval |
| 9 | Obscure query (weak ranking) | Candidate diversity fallback |
| 10 | Software documentation with code | Code block preservation |

### Capture for each query

Save frozen artifacts to `src/tests/search-eval/<query-id>/`:

```
original-question.txt          # raw user question
search-query.txt               # what the model output for search_web
searxng-response.json          # complete SearXNG JSON response
urls-selected.txt              # which URLs were fetched
raw-pages/
  <sha256(url)>.json           # {requested_url, final_url, http_status, headers, content_type, charset,
                               #  fetch_method, fetched_at, content_hash, body_base64}
extracted-text/
  <sha256(url)>.txt            # extracted text per URL (current pipeline)
current-answer.txt             # final answer from current pipeline
notes.json                     # manual scoring
```

Each fixture must capture response metadata alongside the raw body (status, headers, content-type, charset, fetch method, final URL after redirects). Otherwise offline replay cannot test the content-type router or redirect handling.

### Notes format

```json
{
  "question": "What is the maximum supported memory for product X?",
  "expected_domains": ["manufacturer.example"],
  "expected_terms": ["128 GB"],
  "failure_modes_expected": ["answer in specification table", "long page"],
  "scoring": {
    "correct_source_found": true,
    "relevant_passage_retrieved": false,
    "answer_supported_by_evidence": false,
    "provenance_preserved": false
  }
}
```

### Two evaluation modes

**Offline replay**: Both old and new pipelines receive the same frozen HTML documents. Tests extraction, retrieval, condensation, and answering in isolation. Deterministic and reproducible.

**Live end-to-end**: Both pipelines perform actual searches. Tests search ranking, current accessibility, freshness, and real-world latency.

**Important for Phase 1 live comparison**: fetch once and run both old and new information-reduction paths over the same newly captured responses. Do not independently repeat the complete live search for each pipeline — that doubles your external traffic and introduces input variance.

Without offline replay, Phase 1 comparison is noisy and potentially misleading because SearXNG rankings, page content, and site accessibility all change between runs.

---

## Phase 1: Evidence-preserving replacement ✅ DONE (1A/1B/1C/1D)

Implement as a vertical slice on the same 3 URLs. Compare against Phase 0 baseline before proceeding.

- **1A — Retrieval proof**: Frozen HTML → structured extraction → structural chunks → BM25 → exact source-labelled evidence. ✅
- **1B — Deterministic context fitting**: Exact token counting, global budget, content-aware extractive compression. ✅
- **1C — LLM fallback condenser**: Per-source ledger generation and deterministic post-fitting. ✅
- **1D — Trust and lifecycle**: Evidence-role placement, prompt-injection guards, citation rendering, search-artifact externalization. ✅

### Implementation files

New classes under `src/App/Search/`:
- `WebChunk.php` — value object with provenance metadata (source ID, URL, heading path, section type)
- `ContentExtractor.php` — HTML extraction cascade: metadata → semantic container → body fallback → Markdown (league/html-to-markdown)
- `StructuralChunker.php` — heading-aware chunking (300-800 char targets, never splits tables/code mid-structure)
- `Bm25Retriever.php` — multi-field weighted BM25F (Robertson non-negative IDF), `RetrievalPolicy`, source diversity selection
- `EvidenceBuilder.php` — escaped XML-style evidence blocks with source labels, `buildTexts()` for compressed variants
- `TokenCounter.php` — real token counting via llama.cpp `/tokenize` endpoint, `mb_strlen/4` fallback
- `ExtractiveCompressor.php` — content-type aware deterministic compression (prose/table/list)
- `SourceCondenser.php` — per-source LLM condensation with parseable `[S1-C4]` chunk references, validated output
- `CitationValidator.php` — strips hallucinated source IDs from final answer, extracts/validates citations
- `SearchArtifactManager.php` — Redis metadata + filesystem bodies, BM25 rehydration trigger for follow-up questions

### 1d. Structural chunking

Chunk by document structure, not arbitrary token count.

**Structural units** (in priority order):
- Heading section (h2 with all content until next h2)
- Paragraph
- List (keep all items together)
- Table (keep all rows together)
- Code block (keep with surrounding explanation)
- Definition block (<dl>)
- Specification row (single key-value)

**Combining rules**:
- Target 300–800 tokens per chunk
- Combine small adjacent units (short paragraphs under same heading)
- Never split a table unless it exceeds 2000 tokens
- When splitting large tables: split by rows, repeat header row and heading path in each chunk
- **Wide tables** (>10 columns): split by column groups, repeating the primary key/row identifier column in each chunk
- Never split a code block from its immediately surrounding explanation
- Keep a paragraph with its immediately following list

**Overlap**: 1–2 sentences of overlap between consecutive chunks from the same section. Apply overlap only to prose sections — skip overlap on table rows, list items, and code blocks.

**Every chunk carries**:
- `source_id`: "S1", "S2", etc.
- `chunk_id`: "S1-C4"
- `url`: original URL
- `final_url`: after redirects
- `title`: page title
- `domain`: extracted from URL
- `published_at`: from metadata, null if unknown
- `updated_at`: from metadata, null if unknown
- `fetched_at`: ISO 8601 timestamp
- `heading_path`: ["Technical Specifications", "Camera"]
- `section_type`: "paragraph" | "table" | "list" | "code" | "specification"
- `text`: chunk content
- `position`: ordinal within document

### 1e. In-memory BM25 retrieval

No embeddings. No Qdrant. Brute-force PHP over ~30–150 chunks per search.

**Tokenization**:
- Lowercase for scoring, preserve original for display
- Split on whitespace and punctuation boundaries
- Split camelCase: `maxUnavailable` → `["maxunavailable", "max", "unavailable"]`
- Split snake_case: `max_unavailable` → `["max_unavailable", "max", "unavailable"]`
- Preserve version numbers as single tokens: `PHP 8.3`, `GPT-5.6`
- Preserve model/product identifiers: `iPhone16`, `RTX 5090`
- Preserve CVE/IDs: `CVE-2026-1234`
- Normalize common punctuation: don't split on hyphens in compound terms
- Apply English stop words only when language is known to be English
- Never stem code identifiers, product names, or version strings

**Multi-field weighted BM25F with Robertson non-negative IDF**:

```php
$score =
    3.0 * bm25Field($query, $chunk->title) +
    2.0 * bm25Field($query, implode(' > ', $chunk->headingPath)) +
    1.0 * bm25Field($query, $chunk->text) +
    1.5 * entityMatch($query, $chunk);
```

**Source diversity** via `RetrievalPolicy` with per-domain caps and minimum evidence domains.

### 1f. Three-level evidence fitting

**Level 1: Exact chunks** — BM25-selected chunks fit within the evidence budget → pass directly.

**Level 2: Extractive compression** — content-type aware deterministic reduction:
- Prose: rank sentences by query term overlap, keep top N + adjacent context
- Tables: select rows with relevant terms, repeat column headers
- Lists: rank whole items, keep top N
- Code: keep complete blocks/functions, never split mid-syntax
- Key-value specs: keep key+value together

**Level 3: Source-preserving LLM condensation** — per-source, never cross-source. Condenser outputs parseable `[S1-C4]` lines. Deterministic post-fitting trims lowest-relevance items until budget fits.

### 1g. Trust and lifecycle (Phase 1D)

**CitationValidator** — `src/App/Search/CitationValidator.php`
- `sanitizeCitations()` strips hallucinated `[S4]` when only S1,S2 are valid
- `extractCitations()` returns cited source IDs from answer
- `hasHallucinatedCitations()` boolean check

**Evidence-role placement** — `PromptAssemblyService::buildMessagesArray()`
- Evidence no longer appended to state guard → injected as separate message
- Prefers `tool` role (`LLM_EVIDENCE_TOOL_ROLE` config), falls back to `user` with `--- BEGIN UNTRUSTED EXTERNAL DATA ---` delimiters
- System prompt gets untrusted-evidence guard + citation instructions when evidence is present
- `ChatManager` no longer injects search context as `role: 'system'` — uses the new evidence path

**Search-artifact externalization** — `src/App/Search/SearchArtifactManager.php`
- Redis metadata (`search:srch_<hex>:meta`), filesystem evidence + chunks
- `maybeRehydrate()` — BM25 relevance gate on follow-up questions (threshold 3.5)
- 7-day TTL, compact evidence cap (500 tokens) on rehydration

### Known issues (Phase 1)

- **Chunker**: oversized structural units (Wikipedia References section, 154K chars) not split — poisons condenser budget. Fix: split any structural unit exceeding 50K chars regardless of type.
- **two-facts fixture**: 0B answer (transient LLM dead call). Evidence block is valid (5363B). Re-running produces correct output.
- **content_type**: FlareSolverr response headers don't consistently include Content-Type. Phase 2 content-type router will need to sniff from HTML head or response body.
- **Pipeline not wired**: The new search pipeline classes aren't called from the live search path yet. `SearchWebTool` and `WebSearchService` still use the old condenser. Integration happens when the orchestration layer is built.

### Domain-specific adapters

Some sites use structured DOMs (custom elements, comment trees, nested metadata) that a generic strip_tags → markdown pipeline destroys. For these, domain-specific adapters parse the rendered HTML into clean structured output that the chunker can consume.

**Pattern**: each adapter implements `supports(string $url): bool` and `parse(string $url, string $html): array`. The ContentExtractor maintains an adapter registry keyed by domain. When a URL matches an adapter, structured extraction runs instead of the generic cascade.

**Selector philosophy**: Never depend on CSS classes, minified identifiers, or visual layout selectors. Extract data from structural markers that Reddit's own rendering depends on — if Reddit removed them, the page would not render correctly. Priorities in order:

1. **ID-based entity signals** — `t3_*` for posts, `t1_*` for comments. These are Reddit's internal thing IDs, embedded in DOM element IDs, and are the canonical identity layer.
2. **Semantic attributes** — `permalink`, `author`, `created-timestamp`, `subreddit-prefixed-name`, `score`, `depth`. These attributes are the data model; removing them would require Reddit to restructure their entire rendering pipeline.
3. **Semantic body IDs** — `*-post-rtjson-content` and `*-comment-rtjson-content`. These are Reddit's rich-text rendering containers, tied to the entity ID, and are the authoritative source for post/comment bodies.
4. **Accessible fallbacks** — `aria-label="Comment from ..."` as secondary evidence when primary signals are absent. Aria labels are an accessibility requirement and are less likely to be removed or restructured than CSS classes.
5. **Tag-based comment isolation** — when nested comments must be stripped to prevent a parent from absorbing replies, use the element tag name (not a CSS selector). If Reddit changes their custom element naming, this single reference is trivial to update.

**CSS classes, slot attributes, heading selectors, and visual layout selectors are explicitly avoided.** They change with Reddit's design refreshes and provide no semantic guarantee.

Built so far:

- `src/App/Adapters/Reddit.php` — `Reddit` adapter. Subreddit pages return post listings (skipping promoted). Post pages find the main post by matching the post ID from the URL to a `t3_*` element, then extract body text from the associated `-post-rtjson-content` container. Comments are discovered via `t1_*` ID patterns, sorted by `depth` attribute (0 = top-level, 1 = direct reply, 2+ intentionally dropped). Comment bodies extracted from `-comment-rtjson-content` containers. Output is `{type, url, subreddit, posts|post+comments}` with `{id, title, url, author, score, created_at, body}` per item.

Planned adapters:

| Site | Why generic extractor fails | What an adapter would capture |
|------|---------------------------|-------------------------------|
| GitHub (issues/PRs/discussions) | Threaded comments, code blocks, reactions, status labels | Issue body + top comments + resolution, code blocks preserved |
| Stack Overflow | Accepted answer is buried in DOM, code blocks lose language tags | Question + accepted answer + top voted, code with language hints |
| Wikipedia | Infobox tables, citation footnotes, section hierarchy | Structured infobox key-values, body text with heading path, citations as links |
| Hacker News | Flat comment tree with nesting via indentation | Post + top threads, score/author metadata |

---

## Phase 2: HTTP-first acquisition and pacing ✅ DONE

### 2a. Basic fetch safety

`FetchSafety::safeFetchUrl()` — validates URL before any external request:
- Scheme restriction (http/https only)
- URL credentials rejected
- Port restriction (80, 443 only)
- DNS resolution: A + AAAA records, filters private/reserved IPs
- curl pinned to validated IP via `CURLOPT_RESOLVE` (prevents DNS rebinding TOCTOU)
- `CURLOPT_FOLLOWLOCATION` disabled — redirects handled in PHP loop, every hop re-validated
- 5MB byte limit via `CURLOPT_XFERINFOFUNCTION`
- 10-second timeout
- Relative redirect resolution against current URL

`FetchSafety::looksLikeChallengePage()` — 8 indicators (cf-browser-verification, captcha, ddos-guard, etc.)

`FetchSafety::isUseful()` — 2xx status + 200+ chars + not challenge page

`FetchSafety::resolveFetchStrategy()` — decision tree for when to escalate to FlareSolverr vs skip:
- Escalate when: user explicitly requested that URL, it's uniquely authoritative, or it's the last candidate
- Skip otherwise — move to next candidate instead of spending browser overhead

`FetchResult` value object: statusCode, body, finalUrl, resolvedIp, contentType.

### 2b. HTTP-first fetcher

`Scraper::fetchAndClean()` updated:
1. Try direct curl via `FetchSafety::safeFetchUrl()` first
2. If useful (200, enough content, no challenge) → proceed
3. `UnsafeUrlException` (private IP, bad scheme) → return empty immediately
4. `FetchException` (DNS failure, timeout, too many redirects) → fall through to FlareSolverr
5. FlareSolverr path extracted as `fetchViaFlareSolverr()` private method
6. New `$fetchMethod` out-param ('curl' or 'flaresolverr') for coverage measurement
7. Backward compat: existing callers (SearchWebTool, WebSearchService) unaffected

### 2c. Request pacing — Redis-backed and atomic

`OutboundScheduler` — atomic Redis Lua for concurrent PHP-FPM workers:
- `acquireSlot()` — Lua eval: max(now, global_delay, host_delay, service_delay) + jitter, SET all three keys atomically
- `waitForSlot()` — acquire + usleep until slot
- `acquireGlobalLock()` — NX token-based lock with configurable PX lease
- `releaseGlobalLock()` — Lua check-then-delete, only releases if caller owns the token (TOCTOU-safe)
- `acquireWithWait()` — convenience: wait → lock (retry 100ms backoff) → return token
- Delays: 800ms global, 1500ms per-host, 4000ms SearXNG, max 700ms jitter

Lock acquired AFTER waiting for slot, not before — acquiring before a long wait risks lease expiry.

---

## Phase 3: Better candidate selection ✅ DONE

### 3a. SearXNG engine profiles

Minimal engine set per intent — avoids fanning out to every enabled engine from one IP:

| Intent | Engines |
|---|---|
| SoftwareDocs | duckduckgo, github, stackoverflow |
| Academic | duckduckgo, arxiv, pubmed |
| News | bing, duckduckgo, google_news |
| Default | duckduckgo, bing |

### 3b. SearchIntent enum

`App\Enums\SearchIntent` — deterministic intent classification from query text (regex patterns). First match wins — ordering: SoftwareDocs, ProductSpecs, News, Academic, Recommendation, General.

### 3c. Candidates (10–15 per search)

`Search::queryCandidates()` returns `Candidate[]` with metadata:
- url, title, snippet, domain, position, engine, publishedDate
- Engine profiles selected by intent
- `Search::query()` kept as backward-compat wrapper returning plain URL strings

### 3d. URL canonicalization and deduplication

`CandidateDeduplicator` — before any page fetch:
- Scheme + hostname to lowercase, remove default ports, remove URL fragments
- Strip tracking parameters (utm_*, fbclid, gclid, ref, etc.)
- Exact URL dedup on normalized form
- Near-duplicate title detection
- Per-domain cap: max 2 candidates
- Does NOT blindly remove www or resolve redirects (delayed to actual fetch)

### 3e. Deterministic candidate scoring

`CandidateRanker::scoreDeterministic()` — scores before any fetch:
- Search position: max(0, 11 - position) * 0.5
- Domain preference by intent (configurable boost map)
- Lexical overlap: title terms * 2.0 + snippet terms * 1.0
- Low-quality domain penalty: -5.0 (Pinterest, Quora, Medium — configurable)

`CandidateRanker::llmRerank()` — optional reorder of top 8, simple comma-separated integer output, combines with deterministic base (model can reorder within tier but cannot promote a bottom candidate to #1).

### 3f. Sequential fetching with early stopping

`CoverageTracker` — tracks which targets from the question are covered:
- `extractTargets()` splits question on commas/connectors, strips filler words
- `updateFromChunks()` records sources that cover each target
- `allRequiredTargetsCovered()` gate for stopping
- `shouldFetchAnother()` — stops when all targets covered AND domain diversity met

**Attempt tracking**: three distinct counters — attemptedSources, successfullyExtractedSources, sourcesWithRelevantEvidence. Only sourcesWithRelevantEvidence matters for sufficiency.

**Progress events** (for pipeline implementation):
| Stage | Event |
|---|---|
| SearXNG query | search_querying |
| Candidate ranking | search_ranking |
| Fetching URL | scraping_start |
| Extraction | search_extracting |
| Chunking | search_chunking |
| BM25 retrieval | search_retrieving |
| Evidence fitting | search_condensing |

### 3g. Follow-up search (only when needed)

Second SearXNG request only when first result set has a measurable gap: no primary source found, required coverage target unmatched, wrong entity results, stale results, or unresolved contradiction.

---

## Phase 4: Layered caching ✅ DONE

Cache each intermediate artifact independently so related queries can reuse them.

### Implementation files

New classes under `src/App/Search/`:
- `CacheKeyBuilder.php` — content normalization, canonicalization, SHA-256 hashing, all key formats (serp, doc:url, doc:body, doc:extract, doc:chunks, evidence, negative, ref count, LRU zset)
- `CacheTTL.php` — TTL policies by content type (intent-based), volatility estimation from query text (low/medium/high), staleness + force-expiry checks
- `CacheStorage.php` — gzipped filesystem body storage with Redis-backed reference counting (atomic INCR/DECR Lua), LRU eviction via sorted set, age-based cleanup
- `SearchCacheManager.php` — read-through/write-through orchestrator for all six cache layers (SERP, doc meta, raw body, extraction, chunks, evidence, negative), conditional revalidation support (ETag/Last-Modified), `getOrCompute*` convenience methods, version constants (EXTRACTOR=1, CHUNKER=1, RETRIEVAL=1, CONDENSER_PROMPT=1), flush + cleanup
- `AskUserPolicy.php` — metadata-driven AUTO_USE/ASK_USER/NONE decisions based on content age × topic volatility, no LLM call needed

Configurable via env: `SEARCH_CACHE_DIR` (default: `<project>/search-cache/`), `SEARCH_CACHE_MAX_MB` (default: 500)

### 4a. Cache key structure

Redis for metadata and lookup indexes. Filesystem for large bodies (gzipped raw responses, extracted documents). Use `raw_body` + `content_type` (not `raw_html`) since the router supports PDF, JSON, XML, Markdown, and text.

```
# SERP cache
serp:<sha256(canonical_query + engine_config)>
    results, fetched_at, ttl

# Document metadata
doc:url:<sha256(canonical_url)>
    latest_content_hash, fetched_at, etag, last_modified,
    status_code, content_type, fetch_method

# Raw body (filesystem, gzipped)
doc:body:<sha256(content)>
    body_gz_path, content_type, size

# Extraction
doc:extract:<sha256(content)>:v<extractor_version>
    markdown, metadata, extraction_method

# Chunks
doc:chunks:<sha256(extraction)>:v<chunker_version>
    chunks

# Evidence packet — versioned to prevent stale outputs surviving code changes.
# Source hashes MUST be sorted so order-independent lookup works.
evidence:<sha256(sorted_source_hashes + retrieval_version + chunker_version +
                 extractor_version + condenser_prompt_version +
                 condenser_model + token_budget + language)>
    evidence_text, condensation_level, created_at

# Negative cache (failed/challenge pages)
doc:url:<sha256(url)>:negative
    status: "challenge_detected" | "timeout" | "empty_body"
    detected_at, fetch_method
```

**Do NOT cache answers in v1.** The final answer depends on conversational context, language, tone, and what the user is contrasting against. `question + evidence_hash` is not enough. Cache evidence first; add answer caching later only with model version, prompt version, language, and normalized standalone question.

**Negative cache entries are method-specific**: `curl: challenge_detected` does not prevent the intentional `flaresolverr: not_attempted` fallback. Each fetch method gets its own negative entry.

### 4b. TTL policies

| Content type | TTL | Rationale |
|---|---|---|
| Stable documentation | 7–30 days | Specs, API docs rarely change |
| Product specifications | 1–7 days | May be updated, but not hourly |
| General articles | 1–7 days | Blog posts, informational pages |
| News results | 15–60 min | Stale quickly |
| SERP results | 5–30 min | Search ranking changes |
| Raw body with ETag/Last-Modified | Conditional revalidation | Use If-None-Match / If-Modified-Since |

### 4c. Cache cleanup

- Maximum total cache size (configurable, e.g. 500 MB for filesystem, smaller for Redis)
- LRU eviction when limit exceeded
- **Reference counting for content-addressed bodies**: `doc:body:<hash>` is tracked with a reference counter in Redis (`doc:body:<hash>:refs`). Evict filesystem bodies only when the reference count reaches 0. Multiple URLs pointing to the same content hash share one stored body. **Search artifacts count as references** — a body cannot be deleted while an active search artifact still points to it. Reference count updates must be atomic (Redis `INCR`/`DECR`).
- **Canonicalize source hashes before creating evidence keys**: normalize content before hashing (strip trailing whitespace, normalize line endings) so minor formatting differences don't cause cache misses.
- Age-based cleanup: documents older than max TTL removed
- Extractor/chunker version invalidation: version the keys and clean up old versions on deployment

### 4d. ASK_USER behaviour

Keep the existing user-facing ASK_USER card UI. Change the underlying decision to metadata-driven:

```php
function shouldAskUser(array $cachedEntry, string $query): bool {
    $age = time() - strtotime($cachedEntry['fetched_at']);
    $topicVolatility = estimateVolatility($query);  // "low" | "medium" | "high"

    return match ($topicVolatility) {
        'high' => $age > 900,       // 15 min for news/prices
        'medium' => $age > 3600,    // 1 hour for general
        'low' => $age > 86400,      // 24 hours for specs/docs
    };
}
```

The LLM-based SemanticCacheEvaluator can remain as a fallback, but metadata should handle the common path.

---

## Phase 5: Measurement-driven additions ⏸️ Deferred

None of these are mandatory for v1. Add only when evaluation reveals a specific deficiency. All triggers require pipeline integration + live measurement data first.

| Addition | Trigger condition |
|---|---|
| Dense embeddings + hybrid retrieval | Lexical-only retrieval fails on queries using different vocabulary than pages (e.g. "how long unplugged" vs "18 hours video playback") |
| Qdrant | Embedding corpus or retrieval requirements outgrow in-memory BM25 |
| Playwright middle tier | Logs show recurring pattern: HTTP succeeds, status 200, extracted content is empty, FlareSolverr works but is unnecessarily expensive (JS-rendered but not bot-protected) |
| Per-source query decomposition | Multi-aspect questions consistently fail because model can't split targets across sources |
| Claim-level citation verification | Answers cite sources that don't actually support the claim |
| Hostile-content classifier | Prompt-injection pages regularly affect answers despite prompt-level guardrails |
| Domain quality model | Low-quality or spam domains regularly outrank legitimate sources |

---

## What's explicitly out of scope for v1

- Dense embeddings or vector search
- Qdrant or any new Docker container
- Playwright or any new browser automation layer
- Python services (Trafilatura) — use PHP Readability port instead
- JSON structured output from the model — colon format only
- GBNF grammar enforcement — removed for good reason
- Multi-query search by default — single query, follow-up only on deficiency
- Concurrent page fetching — sequential only
- Browser fingerprint rotation — one persistent identity
- Full browser agent / interactive browsing
- Automated retry logic for failed fetches
- Answer caching — evidence caching only
- 50–100 query evaluation suite — 10 queries is sufficient for v1

---

## Phase summary

| Phase | Status | What it does | Implementation |
|---|---|---|---|
| 0 | ✅ Done | Frozen fixtures + 10-query eval set with response metadata | `src/tests/search-eval/capture.php`, `run-phase0.bat`. 10 queries, 30 raw-pages + 30 extracted-texts captured. |
| 1A | ✅ Done | Extract → chunk → BM25 → exact source-labelled evidence | `src/App/Search/`: WebChunk, ContentExtractor, StructuralChunker, Bm25Retriever, EvidenceBuilder. Offline replay at `src/tests/search-eval/replay-1a.php`. All 10 fixtures produce source-cited answers. comparison-table 134→805B, two-facts 141→601B, conflicting 1771→2776B (disagreement preserved with [S1]/[S2] citations). |
| 1B | ✅ Done | Token counting, global budget, extractive compression | `src/App/Search/`: TokenCounter (llama.cpp /tokenize), ExtractiveCompressor (prose/table/list). Three-level evidence fitting wired. 9/10 exact, 0 extractive, 1 condenser (news-event). |
| 1C | ✅ Done | Per-source LLM condenser, deterministic post-fitting | `src/App/Search/SourceCondenser`. Per-source isolation, parseable [S1-C4] chunk references, validated output. Covers the 1/10 case where extractive doesn't suffice. Post-fitting trims lowest-claim sources until budget fits. |
| 1D | ✅ Done | Evidence-role placement, citation rendering, search externalization | `src/App/Search/`: CitationValidator (strips hallucinated [SX]), SearchArtifactManager (Redis+FS storage, BM25 rehydration). `PromptAssemblyService` refactored: evidence as separate message (tool/user role), untrusted guard in system prompt. `ChatManager` no longer injects as system role, CitationValidator post-processing on final answer. |
| 2 | ✅ Done | HTTP-first + Redis-backed pacing + fetch safety | `src/App/Search/`: FetchSafety (safeFetchUrl with DNS pinning, redirect loop, byte limit), FetchResult, OutboundScheduler (atomic Lua slot acquisition + token-based lock). `Scraper` refactored: curl-first with FlareSolverr fallback, fetchMethod out-param for coverage measurement. |
| 3 | ✅ Done | Broad candidates → rerank → sequential with coverage-based early stop | `src/App/Enums/SearchIntent` (regex classification, 6 intents). `src/App/Search/`: Candidate, CandidateDeduplicator (URL canonicalization, tracking-param stripping, per-domain cap), CandidateRanker (deterministic scoring + optional LLM rerank), CoverageTracker (target extraction, coverage gate, shouldFetchAnother). `Search::queryCandidates()` with engine profiles. `Search::query()` kept as backward-compat wrapper. |
| 4 | ✅ Done | Layered caching (SERP, raw body, extraction, chunks, evidence) | `src/App/Search/`: CacheKeyBuilder (normalization + 12 key formats), CacheTTL (intent-based TTLs + volatility estimation), CacheStorage (gzipped FS bodies + Redis ref-counting + LRU eviction), SearchCacheManager (read-through/write-through for 6 cache layers, version constants, conditional revalidation), AskUserPolicy (metadata-driven AUTO_USE/ASK_USER/NONE). |
| 5 | ⏸️ Deferred | Additions only when measurements justify | Embeddings, Qdrant, Playwright, domain quality model — all triggers require live pipeline data. Deferred until Phase 0-4 integration is complete and evaluation reveals specific deficiencies. |

### Known issues

- **Chunker**: oversized structural units (Wikipedia References section, 154K chars) not split — poisons condenser budget. Fix: split any structural unit exceeding 50K chars regardless of type.
- **content_type**: FlareSolverr response headers don't consistently include Content-Type. Raw pages saved with `"unknown"`. Phase 2 content-type router will need to sniff from HTML head or response body.
- **two-facts fixture**: population data not in frozen pages. Pipeline correctly reports missing data for one aspect. Not a pipeline bug — fixture needs a population-specific URL to fully test multi-aspect retrieval.
- **conflicting fixture**: original captured pages were reCAPTCHA blocks (stale). Replaced with synthetic HTML containing genuine contradictory claims about dark chocolate. Pipeline correctly preserves disagreement with [S1]/[S2] citations. Live re-capture needed for production fidelity.

### Current state (July 2026)

**19 classes built** across Phases 1A–4. All 10 frozen fixtures produce evidence-based, source-cited answers via offline replay (`replay-1a.php`). Key wins over old pipeline: comparison-table 0B→805B, two-facts 141B→601B, conflicting disagreement preserved.

**Phase O1 wired**: `SearchPipeline.php` orchestrator connects all 19 classes into the live search path. Three call sites updated: tool-turn (`SearchWebTool`), force_live (`ChatManager`), smart-search (`WebSearchService`). Legacy condenser kept as catch-block fallback.

**Known runtime issues** (discovered during live testing):
- Pipeline throws on first real query, falls back to legacy condenser. Root cause unknown — check PHP error log for `SearchPipeline failed` message.
- `fetchViaFlareSolverr` was broken by orphaned `/**` from patch — FIXED. Method properly declared.
- No source IDs flow through tool-turn path. `$validSourceIds` only populated in `force_live` handler. Tool-turn returns bare evidence string, so `CitationValidator` never fires and model is never prompted to cite.
- `liveSearchLegacy` return type changed to `array` (was `string`). The `doLiveSearch()` caller extracts `$result['evidence']` — need to verify the `execute()` multi-query path also handles array correctly (lines 74-82 in SearchWebTool).

**Not yet done**:
- Phase O2: Wire `SearchCacheManager` + `AskUserPolicy` into live path (replace search_ledger)
- Phase O3: Wire `OutboundScheduler` into `Scraper` + `SearchPipeline` fetch loop
- Fix ChatManager pre-existing brace gap (117/116 — from Phase 1D)
- Fix sourceIds flowing through tool-turn path

---

## Phase O1: Pipeline orchestration ✅ DONE

Wire the new pipeline into the live search path. One new orchestrator class, minimal changes to existing files. No caching — old `SemanticCacheEvaluator` + `search_ledger` path stays unchanged.

### Current call graph

```
Tool turn path:
  ChatManager::process() [line 267]
    → ToolExecutionService::parseAndExecuteToolLines()
      → SearchWebTool::execute()
        → liveSearch() [static, line 90]
          → Search::query()           → URLs as strings
          → Scraper::fetchAndClean()  → cleaned truncated text
          → ContextCondenser::condense() → anonymous prose blob
          → Cache::set() + addToLedger()

ASK_USER force_live path:
  ChatManager::process() [line 178-184]
    → SearchWebTool::liveSearch()
      → [same as above]

Old smart-search path (SearchDecider):
  ChatManager::process() calls WebSearchService::executeDecision()
    → [duplicates entire search loop independently]
```

### Target call graph

```
All three paths:
  SearchPipeline::run(query, messages, emit)
    → SearchIntent::classify()
    → Search::queryCandidates()           → Candidate[]
    → CandidateDeduplicator::deduplicate()
    → CandidateRanker::scoreDeterministic()
    → CoverageTracker (sequential fetch + early stop)
        → Scraper::fetchAndClean()        → raw HTML (need raw, not cleaned)
        → ContentExtractor::extract()     → ExtractedDocument
        → StructuralChunker::chunk()      → WebChunk[]
    → Bm25Retriever::rank()               → selected WebChunk[]
    → threeLevelFit()                     → evidence block
    → EvidenceBuilder::build()            → XML evidence string
    → return {evidence, sourceIds}
```

### New file: `src/App/Search/SearchPipeline.php`

Single class wrapping the replay-1a.php logic in a production-safe form. Reuses existing classes — does not reimplement anything.

```
SearchPipeline::run(query, messages, emit): array
  Returns: ['evidence' => string, 'sourceIds' => string[]]

  Internal flow exactly mirrors replay-1a.php proven path:
    1. SearchIntent::classify(query)
    2. Search::queryCandidates(query, 12, intent)
    3. CandidateDeduplicator + CandidateRanker
    4. CoverageTracker drives sequential fetch loop
       → Scraper::fetchAndClean(url) but we need raw HTML
       → ContentExtractor::extract(html, ...)
       → StructuralChunker::chunk(doc, sourceId)
    5. Bm25Retriever::rank(allChunks, question, query, policy)
    6. Three-level evidence fitting (TokenCounter, ExtractiveCompressor, SourceCondenser)
    7. EvidenceBuilder::build(finalChunks)
    8. Extract sourceIds from final chunks
    9. Return {evidence, sourceIds}
```

**Scraper raw-return problem**: `Scraper::fetchAndClean()` calls `cleanAndTruncate()` which strips tags + truncates. The new pipeline needs raw HTML for `ContentExtractor`. Solution: add `Scraper::fetchRaw(string $url): ?FetchResult` — calls `FetchSafety::safeFetchUrl()` directly, returns the `FetchResult` with raw body. Falls back to FlareSolverr same way. No new fetch logic — just exposes what `fetchAndClean()` already does internally before the strip+truncate step.

### Changes to existing files

**1. `src/App/Scraper.php` — add `fetchRaw()`**

```php
// New static method — returns raw FetchResult instead of cleaned text.
// Uses same HTTP-first + FlareSolverr fallback as fetchAndClean().
public static function fetchRaw(string $targetUrl, ?string &$fetchMethod = null): ?FetchResult
```

Implementation: identical to `fetchAndClean()` lines 22-37 but returns `FetchResult` on success instead of passing body through `cleanAndTruncate()`. FlareSolverr path wraps response in a synthetic `FetchResult`. ~15 lines extracted from existing code.

**2. `src/App/Services/Tools/SearchWebTool.php` — replace `liveSearch()` body**

`liveSearch()` currently does: `Search::query()` → `Scraper::fetchAndClean()` → `ContextCondenser::condense()` → `Cache::set()` (lines 91-129). Replace body with:

```php
public static function liveSearch(string $searchQuery, array $messages, callable $emit,
    ContextCondenser $condenser, int $sessionId = 0): array
{
    $pipeline = new SearchPipeline();
    $result = $pipeline->run($searchQuery, $messages, $emit);

    if (!empty($result['evidence'])) {
        $cacheKey = 'ctx_' . md5($searchQuery . time());
        Cache::set($cacheKey, $result['evidence']);
        Cache::addToLedger($searchQuery, $cacheKey);
    }

    // Return structured — caller extracts evidence + sourceIds
    return $result;
}
```

Return type changes from `string` to `array`. Two callers update:

- `SearchWebTool::doLiveSearch()` (line 85-88): returns `$result['evidence']` for the tool-result string. Source IDs not relevant inside tool execution — tool results get saved to DB as `data_fetching` and injected by PromptAssemblyService later.
- `ChatManager::force_live` (line 178-184): extracts both `$result['evidence']` and `$result['sourceIds']`.

**3. `src/App/ChatManager.php` — update force_live handler (lines 178-184)**

Current:
```php
$condensed = SearchWebTool::liveSearch($query, $currentMessages, $emit,
    $this->contextCondenserService, $sessionId);
if (!empty($condensed) && !str_starts_with($condensed, 'Web search for')) {
    $evidenceBlock = $condensed;
}
```

Change to:
```php
$result = SearchWebTool::liveSearch($query, $currentMessages, $emit,
    $this->contextCondenserService, $sessionId);
if (!empty($result['evidence']) && !str_starts_with($result['evidence'], 'Web search for')) {
    $evidenceBlock = $result['evidence'];
    $validSourceIds = $result['sourceIds'] ?? [];
}
```

This populates `$validSourceIds` so the CitationValidator at line 418-419 actually has data to validate against.

**4. `src/App/Services/WebSearchService.php` — deduplicate, don't rewrite**

`WebSearchService::executeDecision()` duplicates the entire search loop independently (lines 96-121). Two options:

- (a) Replace body with `SearchPipeline::run()`. Risk: changes behavior for the smart-search path.
- (b) Leave as-is but make it call `SearchWebTool::liveSearch()`. The duplicate loop at lines 104-121 is identical logic. Replace with the call.

Recommend (b): replace lines 96-121 with:
```php
$result = SearchWebTool::liveSearch($searchQuery, [], $emit, $this->contextCondenser, 0);
return $result['evidence'] ?? '';
```

The `$scrapedUrls` out-param can be populated from `SearchPipeline` if needed (add to return array).

### What does NOT change

- `SemanticCacheEvaluator` — unchanged. O2 wires the new cache later.
- `search_ledger` — unchanged. O2 replaces with `SearchCacheManager`.
- `ChatManager` lines 320-341 (ASK_USER sentinel) — unchanged.
- `PromptAssemblyService` — unchanged. Already handles evidence injection + untrusted guard.
- `CitationValidator` — unchanged. Already integrated at ChatManager line 418-419.
- `ToolExecutionService` — unchanged. Tool routing stays the same.
- Multi-query path (`splitQueries`) — unchanged. Each sub-query calls `liveSearch()` independently.

### Risk: raw HTML in tool-result messages

The old pipeline stores `ContextCondenser` output as `data_fetching` messages (line 359-366 in ChatManager). These get injected into future turns via `PromptAssemblyService`. The old output was ~2-4KB of condensed prose. The new output is evidence blocks — similar size range (replay shows 360-3900 tokens), same storage path. No overflow risk.

### Rollback

If `SearchPipeline::run()` fails, `liveSearch()` falls back to old `ContextCondenser` path. The old code stays in the method as a catch block. Proven via: try new pipeline, catch Throwable, run old condenser.

---

## Session Handoff (July 29, 2026)

### What shipped this session
- **Phase 4** (layered caching): 5 classes — CacheKeyBuilder, CacheTTL, CacheStorage, SearchCacheManager, AskUserPolicy. Built, verified, not yet wired into live path.
- **Phase O1** (pipeline orchestration): 1 new class (SearchPipeline) + 4 files updated (Scraper, SearchWebTool, ChatManager, WebSearchService). Connects all 19 pipeline classes into the live search path. Legacy condenser kept as try/catch fallback.

### Files changed this session (most recent first)
```
src/App/Scraper.php                      — +fetchRaw(), +fetchViaFlareSolverrRaw(), fixed orphaned /**
src/App/Search/SearchPipeline.php        — new: class SearchPipeline (274 lines, orchestrator)
src/App/Services/Tools/SearchWebTool.php — liveSearch() return type string→array, calls SearchPipeline
src/App/ChatManager.php                  — force_live handler extracts $validSourceIds from result array
src/App/Services/WebSearchService.php    — duplicate search loop replaced with liveSearch() delegate
src/App/Search/CacheKeyBuilder.php       — new: 12 key formats, normalization, canonicalization
src/App/Search/CacheTTL.php              — new: intent-based TTLs, volatility estimation
src/App/Search/CacheStorage.php          — new: gzipped FS storage, ref counting, LRU eviction
src/App/Search/SearchCacheManager.php    — new: read/write-through for 6 cache layers
src/App/Search/AskUserPolicy.php         — new: metadata-driven AUTO_USE/ASK_USER/NONE
```

### What's broken (needs fix next session)
1. **Pipeline throws on live queries** — falls back to legacy condenser. Check PHP error log for `SearchPipeline failed`. Likely causes: wrong method signature on CandidateRanker/CoverageTracker, or a class not found in the Docker autoloader. The offline replay works — live path has different class resolution.
2. **No citations in tool-turn answers** — `$validSourceIds` only flows through `force_live` handler (ChatManager line 183). Tool-turn path (`SearchWebTool::execute()` → `doLiveSearch()`) returns bare string, CitationValidator never fires. Fix: pass sourceIds through the tool-result string or via a side channel.
3. **Old cache not hitting** — `SemanticCacheEvaluator` + `search_ledger` still active but may be broken by the return-type change. Check if cache write in `liveSearch()` (line ~100) actually writes, and if the evaluator can read the new evidence format.

### What's not done
- **Phase O2**: Wire SearchCacheManager + AskUserPolicy (content-addressed caching, replace search_ledger)
- **Phase O3**: Wire OutboundScheduler into Scraper fetch loop (Redis-backed pacing — class built, never called)
- **ChatManager brace gap**: 117/116 mismatch pre-existing from Phase 1D CitationValidator work. Not blocking but will bite eventually.
- **Chunker oversized-unit bug**: Wikipedia References sections (154K chars) not split, poisons condenser budget. Fix: split any unit > 50K chars.
- **Phase 5**: Deferred — all additions require live pipeline evaluation data first.

### Key files for debugging
- PHP error log (Docker): check for `SearchPipeline failed` catch-block message
- `src/App/Search/SearchPipeline.php` — the orchestrator, mirrors replay-1a.php
- `src/App/Services/Tools/SearchWebTool.php` — liveSearch() at line ~91, liveSearchLegacy() at line ~108
- `src/App/ChatManager.php` — force_live at line ~178, CitationValidator at line ~419
- `src/tests/search-eval/replay-1a.php` — offline replay script (proven working, reference for pipeline)
- `search-revamp.md` — this file, full plan and current state

### Command reference
```
# Replay offline fixtures (must be in Docker):
wsl -d localsy-docker-backend docker exec ai_php_web php /var/www/html/tests/search-eval/replay-1a.php
wsl -d localsy-docker-backend docker exec ai_php_web php /var/www/html/tests/search-eval/replay-1a.php --query-id=conflicting

# Check PHP error log in container:
wsl -d localsy-docker-backend docker exec ai_php_web cat /var/log/php_errors.log
```
