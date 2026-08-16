# Localsy Project Context

## What this is
A hybrid desktop application called **Localsy** that bundles a local AI chat experience with persistent memory, email integration, file management, and web search — all running locally on the user's machine. It consists of two parts:
1. A Go binary launcher (tray icon) that bootstraps llama.cpp inference and Docker containers
2. A PHP/JS web app served by Apache in Docker for the chat UI

## Tech Stack

### Backend infrastructure (Go - `main.go`)
- **Package**: `localsy`
- **Runtime**: Standalone binary with systray integration (`github.com/getlantern/systray`)
- **Key subpackages** under `internal/`:
  - `launcher/` — bootstrap flow: detect GPU, download models, start Docker + llama server
  - `docker/` — WSL2 Docker management and compose file generation
  - `llama/` — llama.cpp server orchestration (start, stop, health-check)
  - `models/` — tiered model resolution based on available VRAM
  - `gpu/detect.go` — GPU vendor detection (NVIDIA/AMD/intel)
  - `env/merge.go` — merge user config into generated .env for Docker containers
  - `download/file.go` — progress-tracked HTTP download with systray tooltip updates
- **Embedded assets** via `//go:embed`: docker-compose.prod.yml, models.json, searxng/settings.yml, icon.ico

### Models configuration (`models.json`)
Five tiers mapped to VRAM brackets (Tier1 = >=32GB / 5090 up to Tier5 = <=11.9GB). Each tier specifies a GGUF model file + optional multimodal projector (.mmproj), both downloaded from HuggingFace at first run.

### Web application layer (PHP - `src/`)
- **Runtime**: PHP 8.3 on Apache (Docker, `./Dockerfile`)
- **Framework**: Bare PHP — no framework, hand-rolled MVC-ish structure under `src/App/`
- **Dependencies** (`composer.json`):
  - `vlucas/phpdotenv` for .env parsing
  - `predis/predis` (Redis client)
  - `smalot/pdfparser` for PDF content extraction
  - `webklex/php-imap` / `phpmailer/phpmailer` for email reading and sending

### Web app structure (`src/App/`)
- **Core**: `AgentManager.php` — wraps llama.cpp API calls (OpenAI-compatible chat endpoint at `http://host.docker.internal:1234/v1`). Supports streaming + non-streaming responses.
- **Agents** (`src/App/Agents/`): specialized reasoning modules:
  - `MemoryExtractor.php`, `MemorySelector.php` — persistent memory management
  - `SearchDecider.php`, `TaskMatcher.php` — routing/intent logic
  - `SchedulingAgent.php`, `ContextCondenser.php`
- **Controllers** (`src/App/Controllers/`): Chat, Email, File, Cache, AI Settings
- **Actions** (`src/App/Actions/`): HTTP action handlers (chat stream, email list/send/reply, file search/upload/edit, todoist integration)
- **Services**: `ToolExecutionService`, `PromptAssemblyService`, `EmailService`
- **Tools**: Todoist create/delete/update tasks, search files, get email briefings
- **Database**: MySQL 8.0 via PDO; schema in `src/App/Database/Schema.php`; repositories for chat sessions and memory

### Frontend (JavaScript - `src/js/`)
- Modular ES modules with bare PHP templating (`views/*.php` includes)
- Chat: block-based editor, streaming response rendering, file references, clipboard, Todoist UI
- Email: inbox loading, reply form, AI-assisted replies
- Tabs system: chats / emails / memories / queries / uploads workspaces
- Libraries loaded from CDN: Tailwind CSS, franken-ui, marked.js (markdown), KaTeX (math), highlight.js

### Supporting services (Docker Compose)
| Service | Purpose | Ports |
|---------|---------|-------|
| `web` | PHP/Apache app server | 8080:80 |
| `mysql` | MySQL 8.0 persistence | 3306:3306 |
| `redis` | Caching layer (7-alpine) | 6379:6379 |
| `searxng` | Private web search engine | 8888:8080 |
| `flaresolverr` | Cloudflare/bot bypass for search | 8191:8191 |

## Key files to know

### Entry points
- **`main.go`** — systray binary entry, embeds compose/models/searxng config
- **`internal/launcher/bootstrap.go`** — the full startup sequence (GPU detect -> model select -> download if needed -> Docker up -> llama server -> open browser)
- **`src/index.php`** — PHP app bootstrap, pulls health check status and page data

### Configuration
- **`models.json`** — VRAM-tiered models with HuggingFace URLs
- **`docker-compose.yml`** — development compose (bind-mounts `./src`)
- **`docker-compose.prod.yml`** (embedded in binary) — production compose with built assets
- **`searxng/settings.yml`** — search engine configuration
- `.env` at root and `src/.env` — environment variables

### Build & scripts
- **`setup.bat`**, **`run-dev.bat`** — Windows setup/launch scripts
- **`build-and-push.bat`** — Docker build + registry push
- **`clean-localsy.bat`** — cleanup script
- **`get-diagnostics.bat`** — diagnostic collection

## Architecture patterns

### Boot flow (Go binary)
```
main.go
  -> launcher.Bootstrap()
     1. Create ~/.localy/{bin, models, searxng} dirs
     2. GPU detection
     3. Model tier selection from models.json by VRAM
     4. Download .gguf model + optional .mmproj if missing
     5. Ensure Docker WSL backend ready
     6. Write docker-compose.yml and searxng/settings.yml
     7. Merge user config into generated .env
     8. chmod -R 777 in WSL for file sharing
     9. Start Docker daemon + compose up
     10. Start llama.cpp server (local inference)
     11. Open browser to http://localhost:8080
```

### Request flow (web request)
```
Apache -> index.php
  -> Config::load() + EnvEditor read .env
  -> HealthCheck check (cached for 10s)
  -> Database connect
  -> AgentManager, MemoryExtractor instantiated
  -> PageDataLoader loads initial data
  -> Views rendered via PHP includes
  -> Frontend JS modules handle interactivity
```

### LLM communication
The Go launcher starts llama.cpp server on port 1234. PHP's `AgentManager.php` communicates with it over HTTP using the OpenAI-compatible API format (POST /chat/completions). This is the single AI inference endpoint — no other LLM providers are used.

## Memory system architecture

Three mechanisms handle different aspects of persistent knowledge:

### MemorySelector (`src/App/Agents/MemorySelector.php`)
Called on every request via PromptAssemblyService.buildSystemPrompt(). Uses MySQL FULLTEXT search against cleaned user prompt + fallback to most recent memories, then sends all candidates (up to 500) through an LLM filter that returns relevant memory IDs. Note: this call happens per-request and may be redundant within a single conversation — consider caching results by recent-messages-hash when optimizing.

### MemoryExtractor (`src/App/Agents/MemoryExtractor.php`)
Runs automatically when session token count exceeds ~15K tokens (~60K chars). Extracts 3-5 keywords via LLM, uses those for FULLTEXT search to find candidate memories, then runs a consolidation agent that merges overlapping facts and adds new durable user state. The extraction happens mid-conversation — consider whether event-driven triggers (session end) might produce better context than token-count thresholds.

### ContextCondenser (`src/App/Agents/ContextCondenser.php`)
Triggers when session approaches ~12K tokens (80% of threshold). Slices off old messages, sends archive to LLM for summarization + fact extraction, replaces history with a single "SUMMARY OF PREVIOUS CONVERSATION" system message. Well-scoped and earns its call — keep this logic mostly as-is.

## Tool-turn architecture

The super abilities card system replaces SearchDecider. When the user clicks a card (web search, file search, calendar, etc.), the backend enters a tool turn — the LLM outputs a tool call, the PHP parser extracts it, executes the tool, and the model formulates a natural-language response from the results.

**Multi-tool turns**: When the user selects multiple cards simultaneously, a `$pendingTools` queue drives the execution loop. Each tool gets its own non-streaming LLM call with a focused state guard showing only that one tool's spec. After execution, the tool is popped from the queue and messages are rebuilt with the next pending tool (or empty for natural response). Key invariants: one tool per LLM call, `parseAndExecuteToolLines` unchanged, `buildStateGuard` unchanged (single-tool arrays work as-is), calendar expansion still works per-pass, and the `$maxExecutions = 5` cap prevents infinite loops. On no-match, the failed tool is shifted and execution continues to the next tool.

**Code dedup**: `splitQueries()` and `combineResults()` were duplicated across `SearchWebTool` and `SearchMemoriesTool`. Both now live as `public static` on `SearchWebTool`; `SearchMemoriesTool` delegates to `SearchWebTool::splitQueries()` and `SearchWebTool::combineResults()`.

Key files: see skill `localsy-development` → `references/super-abilities-architecture.md` for the full pipeline, state guard, thought extraction, multi-tool implementation, and known pitfalls.

Parser: `ToolExecutionService::matchToolName()` scans the ENTIRE thought-stripped response for Tool enum values (not just position 0). `extractColonParams()` processes KEY:VALUE pairs in occurrence order. 62 parser tests in `src/tests/ParserTest.php`.

## Diagnostic event logging

`app_events` MySQL table captures every pipeline decision point. `Logger::logEvent()` writes to both DB and file log. Events include LLM request/response timing, tool turn lifecycle, tool match/miss/failure, and loop exhaustion.

Viewer at `/logs` (not linked from UI): event type counts, expandable samples, recent stream, manual Refresh + Clear.

## Unit test suites

~249 deterministic tests in `src/tests/`, no LLM calls. Uses PHP reflection to test private methods. Run: `docker exec ai_php_web php /var/www/html/tests/run.php`.

| Suite | Tests | Coverage |
|---|---|---|
| ParserTest | 62 | matchToolName scan, extractParams colon + function-call formats |
| MessageAssemblyTest | 50 | buildStateGuard, preprocessHistory, cleanMessagesArray |
| ThoughtExtractionTest | 58 | strip(), extract(), tag detection methods |
| MultiQueryTest | 27 | splitQueries edge cases, combineResults with Search/Memory prefixes |
| SearchPipelineTest | ~52 | Phase A: scrape budget math. Phase B: scraper HTML cleaning + truncation. Phase C: cache evaluator routing (mock, sentinel, emit capture) |

## Coding conventions
- PHP uses PSR-4 autoloading (`App\` -> `src/App/`)
- JavaScript modules use `<script type="module">` with explicit src attributes
- No framework conventions to follow — bare procedural style in controllers/actions
- Chat actions stream responses via SSE (Server-Sent Events)
- The PHP app has no ORM — raw PDO queries throughout
- Reuse centralized paths and scan for existing usage before writing — DB access
  only via `App\Database` + repositories, LLM only via `AgentManager`, fetching/
  parsing via `Scraper`/`BridgeFetcher`/`JsonParser`. Do not reimplement or open a
  parallel path; this is the primary guard against accidental rewrites/duplication.

## Important constraints
- **Never read `.env`** — it may contain credentials; use `.env.example` if needed
- The binary is self-contained: compose, models config, and searxng settings are embedded in the Go binary via `//go:embed`
- User data lives under `%LOCALAPPDATA%\localsy\` (Windows) or equivalent on other platforms
- File uploads stored at `src/uploads/`, indexed by filename hash
