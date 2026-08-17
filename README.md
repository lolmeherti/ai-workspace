# Localsy

Local-first AI workspace: a Go launcher plus a PHP/JS web app (Apache in Docker)
bundling local LLM chat, persistent memory, email, file management, and web search.
See `AGENTS.md` for the detailed technical context.

## Development conventions

**Scan for existing usage before writing new code.** Before adding a helper, query,
service, or utility, check whether one already exists and reuse it — do not
reimplement it. The codebase has centralized paths that should always be taken:

- **Database** — all access goes through `App\Database` (raw PDO wrappers) and the
  domain repositories (`App\Repositories\`, `App\Jobs\*Repository`). Do not open a
  second connection or hand-roll a parallel query layer.
- **LLM** — `App\AgentManager` is the single inference endpoint. No other provider.
- **Fetching / parsing** — `App\Search\BridgeFetcher` and `App\JsonParser` are
  the shared substrates; reuse them rather than writing a new fetch or parse path.

The goal is to prevent accidental rewrites and code duplication: when in doubt,
trace the existing symbol to its definition and usages first.
