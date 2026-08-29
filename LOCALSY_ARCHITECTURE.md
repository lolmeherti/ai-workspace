# Localsy — Top-to-Bottom Architecture Reference

Objective reporting. Title + bullets, no prose. Covers what it is, the stack, every
feature, how deep each feature is, and whether the scaffolding helps or hurts the LLM.

---

## 1. What Localsy is

- A hybrid desktop app: a local AI chat assistant with persistent memory, email, files,
  web search, and a job tracker — all running on the user's machine, no cloud LLM.
- Two parts shipped as one experience:
  - A Go binary (systray tray icon) that bootstraps local inference + Docker.
  - A PHP/JS web app (served by Apache in Docker) that is the chat UI.
- Single-user, pre-launch, sandboxed. Reads external data + writes one chat bubble per
  turn only. No terminal access, no outbound requests the user didn't trigger, no other
  writes. No backward-compat concerns.

## 2. What it does

- Runs a local LLM (llama.cpp) behind an OpenAI-compatible endpoint; zero external LLM
  providers.
- Persistent chat sessions with streaming responses.
- Long-term memory (extract / consolidate / retrieve).
- File ingestion + BM25 search (documents, images via vision).
- Web search with citations, run through a real browser bridge.
- Email inbox (IMAP) + AI daily briefing + reply assist.
- Calendar/Todoist task read + create.
- A job-search pipeline (CV → discovery → parse → evaluate → track → apply).

## 3. Stack

Go launcher (main.go)
- Package `localsy`; systray via github.com/getlantern/systray.
- Subpackages: launcher (bootstrap), docker (WSL2 + compose gen), llama (server
  orchestration), models (VRAM-tiered resolution), gpu/detect, env/merge, download
  (progress-tracked HTTP).
- Embeds via go:embed: docker-compose.prod.yml, models.json, icon.ico.

Web app (src/, Docker ./Dockerfile)
- PHP 8.3 on Apache. Bare PHP, no framework, hand-rolled MVC-ish under src/App/.
- PSR-4 autoload (App\ -> src/App/).
- Deps (composer.json): vlucas/phpdotenv, predis/predis, smalot/pdfparser,
  webklex/php-imap, phpmailer/phpmailer.
- DB: MySQL 8.0 via raw PDO (no ORM). Repositories + App\Database.
- Frontend: vanilla JS ES modules, bare PHP templating (views/*.php). Libraries from
  CDN: Tailwind, franken-ui, marked.js, KaTeX, highlight.js. Dark glass UI.

Supporting services (Docker Compose)
- web  : PHP/Apache app server, 8080:80.
- mysql: MySQL 8.0, 3306.
- redis: 7-alpine, 6379 (caching + outbound pacing + model lock).

Inference
- llama.cpp server on port 1234 (OpenAI-compatible /v1/chat/completions, /tokenize, /props).
- Go HTTP API on :9876 (/api/models, /api/model-switch, /api/switch-status).

## 4. Model loading & context pipeline

- Boot: GPU detect -> models.json tier (5 VRAM brackets: t1 >=30GB, t2 >=22GB, t3 >=14GB,
  t4 >=10GB, t5 >=6GB) -> resolve model id from .env -> download GGUF (+ optional .mmproj)
  -> write .env -> start llama-server with --ctx-size -> open browser.
- VRAM read as bytes/1024^3; fractional-GiB handling (5090 "32GB" = 31.84 GiB) fixed by
  lowering every vram_min ~2GB (32->30, 24->22, 16->14, 12->10, 8->6).
- .env LLM_CTX_SIZE always rewritten from the resolved tier (kills drift between .env and
  the running server).
- Token counts come from llama's real usage (stream_options.include_usage), NOT chars/4.
- LLM load: neutral. This is infrastructure; it removes the "which model / how many
  tokens" guesswork so the model always runs at a context size it can actually use.

## 5. Chat (conversation core)

- SSE streaming: AgentManager::chat() always sends stream:true and fires a callback per
  delta; $stream=false only buffers without firing.
- Message lifecycle: user msg inserted -> buildSystemPrompt + buildMessagesArray ->
  firstPass (tool-capable) -> tools or direct answer -> CitationValidator sanitize ->
  assistant row inserted -> context_tokens updated.
- Message assembly: preprocessHistory filters tool_call/super_abilities marker rows and
  slices to a rolling window (default 15). Model sees ~10-12 clean messages, never raw DB
  rows.
- Thought extraction: ThoughtExtractor::strip() removes reasoning/tag artifacts before
  anything is saved or shown.
- Sessions: auto-title (one LLM call on first message), star, delete, truncate-all.
- Block-based editor frontend: render/delete/selection/stream blocks, file references,
  clipboard, markdown/KaTeX/highlight rendering.
- Image attachments: FileAttachmentService handles inline upload; multi-marker loop emits
  one user message with N image_url parts (never N messages).
- LLM load: EASIER. The assembly layer gives the model a clean, deduped, correctly-typed
  message array with the system prompt + date + knowledge cutoff already folded in. It
  never has to parse raw history noise.

## 6. Tool turn / Super-abilities (native function calling)

- Replace text-based tool syntax with llama.cpp function calling (tools JSON schema,
  tool_choice auto).
- Model tool surface (buildToolSchemas, the ONLY thing a model can invoke):
  search_local, search_web, search_calendar, search_session_evidence, create_calendar_task.
  Editor mode narrows this to search_memories only.
- ToolExecutionService wires 10 tools (the Tool enum); the other 5 (search_files,
  search_memories, get_calendar_tasks, delete_calendar_task, update_calendar_task) are NOT
  in the model schema — they are unreachable via native function calling. Read-only/
  navigation surface only.
- Flow: integrated firstPass streams reasoning + content; on tool_calls it discards
  buffered reasoning, executes each tool, then runs ONE second inference over evidence.
- Hard budget: normal turn = 1 inference, tool turn = 2 (a web turn is 2 blocking
  inferences — tool decision + answer; evidence atomization is deferred to a later turn).
  No auto-chaining, no per-turn router.
- LLM load: EASIER. The API enforces format structurally — no regex parsing, no refusal,
  no format drift across models. The model picks tools via schema instead of a fragile
  text convention.

## 7. Web search (deepest feature)

- Single implementation: browser bridge (real browser Google SERP + full-page extraction).
  SearXNG, snippet mode, and FlareSolverr all fully removed from the stack — no fallback.
- Pipeline (SearchPipeline::run -> runBridgeMode): splitQueries -> bridge SERP -> fetch
  per URL -> chunk (WebChunk) -> Bm25Retriever::rankRaw incremental early-stop -> diversity
  rank -> three-level evidence fit.
- Early-stop: after each URL, rank accumulated chunks raw-BM25; if >= MIN_EVIDENCE_SOURCES
  (2) distinct sourceIds in top-5 AND queryIsSimple() (no vs/versus/compare/better/best),
  stop. Hard cap MAX_SEARCH_RESULTS_TO_SCRAPE (3).
- Ranking: BM25 lexical + diversity selection (Phase 1 best + first new-domain). raw vs
  ranked split (rankRaw for stop decisions, rank for evidence).
- Evidence: SourceCondenser batched atomization (one inference over all S#-C# chunks,
  ~48% latency / ~46% token cut vs per-source). Produces atomic_context (durable [S#]
  fact lines) + backing_chunks (lossless).
- Citations: model emits [S#] anchors only; system owns source->URL map. CitationValidator
  strips hallucinated [SX] from user-visible text at 3 boundaries (DB save, live stream,
  page render).
- Honest no-result: returns explicit non-empty message when bridge down or zero results;
  model relays it rather than answering from degraded snippets.
- Defenses: SSRF via 4 layers — BridgeFetcher::validateFetchUrl (scheme/port/credentials)
  + Go relay validateHost (DNS IsGlobalUnicast, rejects loopback/private/link-local/CGNAT)
  + extension declarativeNetRequest blocklist + extension isPrivateHost post-nav check
  (DNS-rebinding residual documented). HostCooldown circuit breaker (2h, never re-fetch a
  challenged host), OutboundScheduler Redis pacing (800ms global + 1500ms/host + jitter),
  PromptInjectionFilter, evidence injected as role:user with "--- BEGIN UNTRUSTED EXTERNAL
  DATA ---" delimiters, EvidenceBuilder XML-escapes all chunk text.
- Domain adapters: Reddit (semantic-marker extraction, no CSS classes); planned GitHub/SO/
  Wikipedia/HN.
- Caching (Phase 4) REMOVED — bridge made search cheap enough that cache evaluation cost
  more than it saved.
- LLM load: EASIER, markedly. The model receives pre-ranked, deduped, diversity-filtered,
  atomized evidence with stable source IDs and explicit "nothing found" signals. It never
  sees raw hostile HTML. The one cost: a web turn is 2 blocking inferences (tool decision
  + answer); atomization to durable facts is deferred to a later turn, so it never blocks
  the visible answer.

## 8. Local search (files + memories)

- SearchLocalTool::execute() merges file queries into one keyword set (keyword LIKE
  matching; separate queries don't help recall) and comma-joins memory queries (FULLTEXT
  NATURAL LANGUAGE likes coherent phrases).
- Output sections [Files] / [Memories] + a SYSTEM NOTE telling the model to present both
  and flag overlap.
- LLM load: EASIER. One tool returns both corpora pre-merged; the model only synthesizes.
  System prompt pushes synonyms/alternate phrasings because file search is exact-substring
  and "cv" won't match "resume" without expansion.

## 9. File search index

- Ingestion ONCE at upload into searchable_text + search_entities; every later query is
  deterministic in-memory BM25 — no query-time LLM, no LIKE, no stoplist.
- Documents: FileExtractor writes .txt sidecar (40K cap), then ONE English normalization
  call. Always English-normalized at ingestion; original text in sidecar only, never indexed.
- Images: one bounded structured vision call -> generated_title, visible_text_original/
  english, description_english, entities. searchable_text = joined; entities separate.
  Failure degrades to filename title, never throws.
- Retrieval (FileRetriever): chunk via StructuralChunker -> Bm25Retriever scoreFileChunks
  (title 3.0, entities-as-heading 2.0, body 1.0, entity bonus 1.5) -> group by file id
  (file score = max chunk) -> top 0-3 distinct files, never pad.
- FileAliasMap expands query-time synonyms (cv/resume/lebenslauf, röntgen/x-ray,
  befund/medical report, rechnung/invoice). Not a translator (English already normalized).
- LLM load: EASIER. Ranked files arrive pre-scored; no LLM cost at query time. The
  ingestion English-normalization means the model searches one language, not N.

## 10. Files (upload / gallery / edit)

- Upload paths (all through FileIngestor ingest()): RegistersUploadedFiles (gallery/paste/
  drag/sync), FileAttachmentService (inline chat). Both write the same 4 columns.
- Gallery workspace: upload, sync button (adds on-disk files + re-indexes stale rows by
  version), delete, explorer, content view.
- File editor drawer: block-based draft editor (open/save/update/discard/delete-blocks)
  over an uploaded file.
- Re-index: manual foreground blocking, model-locked, 12/30 progress, per-file version
  stamping, continues past failures.
- LLM load: EASIER (search side). The editor uses the LLM only for AI-assisted editing;
  search is fully deterministic.

## 11. Memories

- Storage: flat memories(id, memory_text, FULLTEXT). No unique constraint, no content
  hash, no insert-time dedup (intentional: one row per field).
- Write path — MemoryExtractor::extractAndSave() is the ONLY writer:
  1. LLM extracts 3-5 keywords from recent chat.
  2. FULLTEXT NATURAL LANGUAGE search (LIMIT 25) -> candidates (pad with recent if <15).
  3. Consolidation LLM returns {updates, deletions, additions}.
  4. Writes in a transaction, then distills user_profiles "Golden State".
  - Candidate window: manual run = latest 200 (ORDER BY id DESC LIMIT 200); auto run =
    ~15-25 FULLTEXT keyword-matched (padded to 15 with recent). Consolidation is
    MANUAL-ONLY now (MemoryController -> extractAndSave(..., true)); the token-threshold
    auto-trigger (MEMORY_EXTRACTION_THRESHOLD_TOKENS) was removed.
- Read path — MemorySelector: tokenize (strip non-alnum) -> FULLTEXT BOOLEAN on all words
  -> LIKE %word% OR fallback (>=2 chars), OR semantics on all words. Then LLM filter picks
  relevant ids from up to 500 candidates.
- Dedup/merge (REAL, not lazy): the consolidation agent merges overlapping/redundant
  memories — it UPDATEs one kept row and DELETEs the redundant ids in the same transaction.
  The prompt walks it through an explicit merge example (coding-knowledge / developer /
  software-engineer -> one consolidated sentence).
- Scope (the actual gap): dedup only sees the candidate window, never the whole table. No
  global whole-table dedup pass; no DB uniqueness constraint. A stray older than 200 rows
  (manual) or not sharing the extracted keywords (auto) is invisible to the consolidator and
  survives. The weakness is candidate SELECTION (recall), not the merge action: synonym gaps
  make an auto-run's keywords miss the near-duplicate -> it never enters the window -> re-added
  as new. Nothing catches it at insert.
- Retrieval is intentionally fuzzy/broad (OR, no generic-token stoplist) for recall value —
  accepted cost: noisy results when a generic token ("number") is the only shared term.
- LLM load: MIXED. Write side dedups/unifies within its window but has a recall gap
  (candidate selection misses synonym strays); no automated trigger. Read side helps (fuzzy
  recall surfaces user facts) but can flood context with noisy memories. The LLM does both
  extraction and filtering — memory is the most LLM-dependent, least deterministic subsystem.

## 12. Session evidence retrieval

- search_session_evidence: BM25 over the session's active backing_chunks of data_fetching
  rows. Never hits network, never persists; result is TRANSIENT (this turn only), keeps
  original S#-C# provenance, no new source IDs.
- LLM load: EASIER. Lets the model re-read full evidence from earlier searches without a
  new web call — cheap, lossless, correctly cited.

## 13. Email (fetch / cache / reply)

- EmailService: IMAP via webklex/php-imap per account, SSL, validate_cert, 30s timeout.
- connectWithRetry (3 attempts, 0.5s/1s backoff) gated by classifyImapError() which walks
  the full previous-exception chain to distinguish transient (timeout, "empty response")
  from AUTH_FAILED (never retry -> avoids Gmail lockout).
- Body extraction with multi-fallback (HTML -> text -> raw, header stripping). MIME header
  decode. 500-char snippet. Sets Seen flag after fetch.
- email_cache table (UNIQUE account_id+uid) upsert via ON DUPLICATE KEY UPDATE.
- Reply via PHPMailer (SMTP); AI reply assist generates the draft, user sends.
- Accounts: add/delete; per-account fetch progress events.
- LLM load: NEUTRAL (fetch is deterministic). The only LLM work is reply-assist + briefing.
  Heavy retry/classification logic exists precisely so the LLM never has to interpret IMAP
  failures.

## 14. Email briefing (daily)

- ChatBriefingStreamAction: triage -> extract -> action cards -> synthesis prose.
- BriefingTriage: recall-biased LLM triage over (id + sender + subject + preview) -> id
  list to read in full. "Never under-include": empty result with non-empty input returns
  ALL ids (parse/format safety net).
- BriefingExtractor: structured commitment extraction. Chunks emails by char budget
  (chunkByBudget, never a fixed "5 emails"), one LLM call per chunk, returns JSON
  {content, due_string, source_email_ref}. reasoning_effort='none' on mechanical calls
  (native-thinking models otherwise burn max_tokens on reasoning_content -> empty content).
  PHP dedups (normalized-token overlap) + validates source refs against known ids.
- Cards: Email cards ([E#] anchors -> [Email:account_id:uid] -> lazy body fetch) and
  Action cards ("Suggested Tasks" -> Accept & Create Task). Persisted as briefing_cards
  JSON on the assistant row so they survive refresh (hydration in chatDomInit.js).
- LLM load: EASIER. The model's three jobs (triage, extract, synthesize) are each tightly
  scoped with JSON-only output and PHP-owned validation; it never builds HTML/cards.

## 15. Calendar / Todoist

- Exposed to model: search_calendar (read tasks/events) + create_calendar_task (create).
- Unexposed (dead to the model): delete_calendar_task, update_calendar_task,
  get_calendar_tasks (plus search_files/search_memories, which only run as search_local
  internals). get_email_briefing is a button-driven action, not a tool.
- TodoistApiClient + CreateTodoistTaskTool handle the API; create emits calendar_task_created
  SSE + a calendar expansion per pass.
- Briefing action cards call createTodoistTaskDirectly (button-driven, not model-driven).
- LLM load: EASIER. Read + create only, no destructive surface. The model gets schedule
  context when it asks and can create a task, but cannot delete/overwrite silently.

## 16. Jobs module (App\Jobs)

- Direction: manual listing URLs (user pastes filtered portal URLs) — automated discovery
  removed. Host-resolved adapters: devjobs.at -> DevJobsAt, else GenericListing.
- Pipeline (JobOrchestrator::run): run row -> discover (listing links, paginated, MAX_PAGES
  10) -> per URL evaluateAndStage -> commit (bulk INSERT, state 'unread').
- Per URL: JobParser->parse (bridge fetch only, normalizeHtml to 8k, ONE LLM call -> JSON,
  validateRecord) -> filters (stale/domain-blocked/company-blocked/
  dedupe url+posted_at) -> JobEvaluator->evaluate (KEEP/DISCARD + comment).
- LLM is the structured extractor (JSON) + evaluator (KEEP/DISCARD); PHP validates and
  INSERTs. LLM never writes SQL. temperature 0.3 on query/parse/evaluate.
- Correctness owned by PHP: content-based is_listing rejection (LLM flags listing pages,
  they are rejected — keeps stored URL = true posting URL), posted_at required (date-only,
  LLM resolves relative dates in any language from "Today's date" anchor), city/country
  inference limited to those two fields, description + AI comment stored as READ-ONLY
  markdown.
- Job URL is system-owned end-to-end (validateRecord stores the passed candidate URL, LLM
  output url ignored) — model cannot corrupt it.
- Tracking: state machine (unread -> interested -> applied -> interview -> offer -> history),
  CV upload/extract/select, profile (locations/modes/employment/salary), registry (boards),
  blocks (domain/company), runs + per-job logs (SSE live).
- LLM load: EASIER for the model's two narrow jobs (extract JSON, judge relevance); HARDER
  overall in that the pipeline is strict — no date = reject, listing = reject — but those
  rejections are enforced by PHP so the model's mistakes are caught, not trusted.

## 17. Context Data & evidence atomization

- Evidence lifecycle: HOT atomic_context (durable [S#] fact lines) + backing_chunks
  (lossless searchable) + transient rehydrated chunks (session-evidence, never persisted).
- Raw evidence stored un-atomized (atomic_context null); condensed at the start of a later
  turn, one tool-result at a time (never one giant mixed batch).
- Trigger (automatic, two signals, both context-scaled via piecewise-linear interpolation):
  (1) backlog — accumulated raw evidence crosses a threshold (1.5k @ 8k ctx, 8k @ 25k, 30k
  @ 160k); (2) safety — remaining headroom drops below a floor (1k @ 8k, 2.5k @ 25k, 8k
  @ 160k). Largest-first, re-checks after each row, stops once the backlog is back under
  target. Measured condensation latency feeds a running average that is recorded but not
  yet used to gate the decision.
- Demotion (rich -> atoms) is temporal via $richRowIds; eviction = raw_evicted (excluded
  from injection, citations, session-evidence; backing retained for Restore).
- Correctness invariant: never drop rich evidence before durable atoms exist (if condenser
  fails, atomic_context null -> fall back to message).
- ContextData actions: view / toggle / atomize (manual).
- LLM load: EASIER. Context stays bounded with durable compact facts; the model reads atoms
  on later turns instead of re-reading raw evidence. The deferred atomization means the
  answer is never blocked by condensation latency.

## 18. Context management (hard block / condense)

- Manual-only: no automated extraction, no automated condensation (soft warning removed).
- Hard block: isContextFull() rejects a new message when context_tokens >= LLM_CTX_SIZE -
  4096 (4096 = max_tokens output reserve), emitting context_full SSE; frontend
  lockChatContext() disables input + swaps placeholder.
- Preflight: projectsOverflow() estimates system prompt + history + query and blocks before
  insert, with a per-section breakdown (context data vs recent chat vs reserve).
- Manual "CONDENSE CHAT" (ContextCondenser) slices old messages -> LLM summary + fact
  extraction -> replaces with one summary system message; commitCondensation resets
  context_tokens to 0.
- LLM load: EASIER. Hard block + reserve guarantee the model never receives an
  over-context prompt (which would truncate silently). Condensation keeps long threads usable.

## 19. Token counter

- Server renders real values inline (number_format(totalSessionTokens) / LLM_CTX_SIZE,
  default 32768); JS updateTokenCounter() drives the bar + color live.
- context_tokens = llama's real prompt_tokens from the last response (fallback: sum of
  token_estimate). NOT chars/4 summed (that double-counted when a prior row held the full
  context count).
- LLM load: EASIER. Honest token math (chars/4 overestimates CJK and misleads) lets the
  user and the hard-block make correct context decisions.

## 20. Settings modal

- Model switcher: dropdown from Go API :9876/api/models (grouped by VRAM tier), ctx_size
  field. Save -> switchModel() POSTs {model_id, ctx_size} to :9876/api/model-switch;
  202 -> switching (fire-and-forget restart). JS polls get_switch_status every 2s ->
  reload on loaded.
- .env editor: every other env var rendered as a text input; saveSettings writes .env and
  re-syncs LLM_MODEL_ID/NAME to the actually-loaded model (a switch that died mid-download
  leaves .env stale otherwise).
- Sync Limit (api_action=sync_lmstudio_limit): reads llama /props default_generation_settings
  n_ctx and writes LLM_CTX_SIZE to .env.
- Clear All History button (truncates sessions/messages).
- LLM load: NEUTRAL. Config plumbing; the one correctness nicety is re-syncing .env model
  identity so the model name/context never drift from what's actually running.

## 21. System health

- HealthCheck::check() returns {database, redis, ai, model_name, all_operational}. Cached in
  Redis under system_health_status, TTL 10s, run on every request unless fresh.
- checkAi(): tries llama /props but the URL keeps its /v1 suffix, so /v1/props 404s (dead
  branch); always falls through to /v1/models data[0].id (the loaded model alias). n_ctx is
  NOT read here — only the Sync Limit path reads it.
- Sidebar shows Database / Redis / AI Core (loaded model name) with online/offline glow.
- Cosmetic: the offline badge does NOT gate chat (ChatStreamAction has no all_operational
  gate; the input "disable" is a disabled attr on a div = no-op).
- Migrations: initTables() runs through HealthCheck on non-cached page loads (idempotent
  SHOW COLUMNS + ALTER TABLE).
- LLM load: NEUTRAL. Purely informational; a stale 10s "offline" cache is cosmetic only.

## 22. Observability (event logging / perf metrics)

- app_events table: every pipeline decision — llm_request_start/response_done,
  llm_tool_request_start/response_done (incl tool_calls array), bridge_stop_check/
  bridge_stop, job_evaluated, etc. Logger::logEvent writes DB + file.
- /logs viewer (not linked from UI): event-type counts, expandable samples, recent stream,
  manual Refresh/Clear.
- Perf metrics: per-turn perf_metrics JSON on each assistant row (total_ms, ttft_ms,
  per-call log: prompt/prefill/decode timings, reasoning vs content split).
- Job run logs (SSE live) + src/logs/app.log (file, survives DB cleanup) +
  src/db_errors.log (full trace for any Fatal Bootstrap Error).
- LLM load: NEUTRAL-to-EASIER. Diagnostics don't touch the model, but they make silent
  failures visible and self-healable instead of the model silently degrading.

## 23. Citations & prompt-injection defenses

- System owns source->URL mapping; model emits [S#] anchors only, never URLs/references.
- CitationValidator strips hallucinated [SX] against $validSourceIds at 3 boundaries.
- Evidence injected as role:user with untrusted-data delimiters; EvidenceBuilder XML-escapes
  chunk text; PromptInjectionFilter present.
- Search results cleaned of [S#] markers before DB save; historical messages rendered clean.
- LLM load: EASIER + SAFER. The model sees clearly-delimited, escaped evidence and its
  citations are sanitized, so a hostile page can't inject instructions or fabricate
  references that leak to the user.

## 24. Running Localsy

End-user prerequisites (Windows only)
- Windows 10/11 with CPU virtualization enabled in BIOS (Intel VT-x / AMD SVM) — required for WSL2.
- WSL2 (setup.bat installs it headless if missing).
- A Docker daemon reachable inside the WSL2 distro `localsy-docker-backend`. The launcher
  boots it itself (`docker.EnsureHeadlessReady` / `StartHeadlessDaemon`) — no Docker Desktop
  UI needed on the host.
- Nothing else on the host: no PHP, Python, Node, or MySQL. Go is only required to BUILD the
  binary, not to run it. Everything else ships in Docker or is downloaded by the launcher.
- The Localsy Search Bridge Edge extension — the ONE piece the launcher does NOT provision.
  Load it manually: `edge://extensions` → Developer mode → "Load unpacked" → the
  `localsy-search-bridge/` folder. It connects to the Go relay on `ws://localhost:8765`
  (ping every 20s). Required for web search only.

Browser bridge (what it actually gates)
- Web search (`search_web`) has NO fallback — the bridge is the single implementation
  (SearXNG/snippet mode removed). Bridge disconnected → explicit "web search unavailable —
  browser bridge not connected", not a degraded answer.
- Jobs module is ALSO bridge-only (no Scraper/FlareSolverr — both removed). Without the
  bridge, job detail fetch and listing fetch fail outright (parse fails / listing skipped).
- Everything else — chat, memory, files, email, briefing, settings, health — runs untouched.
- Net: the app boots and "runs" without the bridge, but web search is a no-op. First Google
  search after install hits a consent wall once (user clicks through), and the extension's
  Google SERP selectors are minified classes that break occasionally.

First-time provisioning (once, as Administrator)
- Run `setup.bat`:
  - verifies BIOS virtualization (with a BIOS-enable walkthrough if off)
  - installs WSL2 headless if absent → prompts a reboot
  - creates `%LOCALAPPDATA%\localsy\`
  - writes the `.excluded` safety marker + Windows Defender exclusions (so Defender doesn't
    quarantine `llama-server.exe`)
  - adds a firewall inbound rule for port 1234 + disables the Hyper-V VM firewall for WSL
- Reboot once if WSL2 was just installed, then re-run setup.bat.

Daily run (end user)
- Double-click `localsy.exe` (the Go tray binary). On launch it:
  1. kills duplicate launcher / orphaned processes
  2. creates `%LOCALAPPDATA%\localsy\{bin, models}`
  3. detects GPU (vendor + VRAM) → picks a model tier from embedded models.json
  4. resolves the model id from `.env` (or auto-selects by VRAM)
  5. downloads the GGUF (+ optional .mmproj) from HuggingFace if missing — first run is a
     multi-GB download, progress shown in the tray tooltip; also updates `llama-server.exe`
  6. ensures the WSL2 Docker backend, writes `docker-compose.yml`, merges `.env`
  7. `chmod -R 777` in WSL, starts the headless Docker daemon, `docker compose up`
  8. starts llama.cpp on `:1234` (local inference)
  9. opens the browser to `http://localhost:8080`
- Tray icon = process control. Model switching happens in the Settings modal (Go API
  `:9876/api/model-switch`, async restart + download, progress-polled).

Build (developer only)
- `go build -ldflags "-H=windowsgui" -o localsy.exe`
- Self-contained: embeds `docker-compose.prod.yml`, `models.json`, and `icon.ico` via go:embed.

Development run (hot reload)
- Prereqs: Go, the WSL2 backend distro, git.
- Build the binary (above), then run `run-dev.bat`:
  - starts `localsy.exe -debug` (verbose log + live log terminal, skips auto-open browser)
  - waits for llama-server to LISTEN on `:1234`
  - stops the prod web container, starts a dev web container with a hot volume mount
    `./src:/var/www/html`
  - reachable at `http://localhost:8080`; edits in `src/` are live, no rebuild/restart
- Tests: `docker exec ai_php_web php /var/www/html/tests/run.php` (~249 deterministic tests).
  Live probes: `src/tests/live/` (run manually, e.g. via `wsl -d localsy-docker-backend docker exec ...`).

Cleanup / reset
- `clean-localsy.bat`: kills llama-server + localsy, `wsl --shutdown`, clears AppData
  (compose, .env, log, bin/) but PRESERVES `models/`.
- "Clear All History" button in Settings wipes DB sessions/messages.

Ports
- 8080 web (Apache/PHP), 3306 MySQL, 6379 Redis, 1234 llama.cpp, 9876 Go API, 8765 bridge WS relay.

Data locations
- `%LOCALAPPDATA%\localsy\` — `.env`, `docker-compose.yml`, `models/`, `bin/`, `uploads/`, `localsy.log`.
- `src/uploads/` — file uploads (bind-mounted into the web container).

---

## Summary — does Localsy help or hurt the LLM?

- HELPS (scaffolding does the hard, deterministic work): web search (rank + atomize +
  cite), file search (BM25 + English normalization), message assembly, context hard-block,
  session evidence, citation sanitization, prompt-injection delimiters, email fetch error
  handling, job parse validation.
- MIXED / weak spot: memory write path — dedup/unify IS real (merge via update+delete) but
  scoped to the candidate window, so synonym-mismatched or older-than-200 strays escape; no
  insert-time guard; no automated extraction/consolidation trigger. Retrieval is fuzzy to a
  fault (broad OR matching floods context with noise).
- NEUTRAL: token counter, settings, health, observability — correctness plumbing that
  neither adds nor removes model capability, but removes guesswork.
- NET: Localsy moves every deterministic, verifiable, or risky task OUT of the model and
  into PHP/Go, leaving the model to do synthesis over pre-cleaned, pre-ranked, well-cited
  inputs. The single weak subsystem is long-term memory write dedup.
