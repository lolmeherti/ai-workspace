# Localsy Search Bridge MVP

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
