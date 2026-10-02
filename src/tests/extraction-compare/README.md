# Localsy — extraction comparison (Pipeline A vs Pipeline B)

Standalone experiment. **It does not modify or call the production browsing
pipeline.** Nothing in `localsy-search-bridge/` or `src/App/Search/` is touched;
the production extension can stay loaded and idle while this runs.

## What it compares

* **Pipeline A** — the existing deterministic extractor, unchanged. The harness
  loads `harness/deterministic-extractor.js`, which is a **byte-identical copy**
  of `localsy-search-bridge/generic-extractor.js` (sha256 is compared to the
  production file on every server start and recorded per snapshot). Its raw
  output is saved *before* any downstream condensation — PHP chunking, BM25
  selection, extractive compression and the per-source LLM condenser are
  deliberately excluded.
* **Pipeline B** — a model picks snapshot-local element IDs (`e1…eN`) from a
  compact DOM map, and the selected blocks' full content is then extracted
  **deterministically** from the content store. The model never rewrites,
  summarizes or reorders content; it only returns IDs plus one-clause reasons.

Both pipelines read the **same captured snapshot**: the page is loaded once, the
deterministic extractor settles as it does in production, and the DOM map, the
content store and the serialized DOM are all taken from that same page instance.

## Prerequisites

1. **Host:** Node (>=18) — runs the capture server and owns the browser.
2. **The harness extension loaded once into the browser the user already has open**
   (real session, logins, consent state):
   `edge://extensions` → Developer mode → **Load unpacked** → `src/tests/extraction-compare/harness`.
   The harness opens one **unfocused tab** per capture and closes it when done —
   the same technique the production bridge uses. No window, no new browser, no
   separate profile is ever launched by this server.
3. **Docker stack up** (`ai_php_web`) and **the model reachable** at the app's
   configured `LLM_API_URL` — needed only for the model pass. Capture-only runs
   work without them (`--no-model`).

## Run

```bash
# terminal 1 (host) — capture server (launches no browser on its own)
node src/tests/extraction-compare/server.js

# terminal 2 (container) — capture + one model selection pass
wsl -d localsy-docker-backend docker exec ai_php_web \
  php /var/www/html/tests/extraction-compare/compare.php \
  --url="https://example.com/article" \
  --question="What is the battery life and under what conditions?"

# replay the SAME snapshot with a different question (no browser, no capture)
wsl -d localsy-docker-backend docker exec ai_php_web \
  php /var/www/html/tests/extraction-compare/compare.php \
  --snapshot=20261002-153000-1a2b3c4d \
  --question="Does it mention charging speed?"

# capture both pipelines without calling a model
wsl -d localsy-docker-backend docker exec ai_php_web \
  php /var/www/html/tests/extraction-compare/compare.php \
  --url="https://example.com/article" --question="..." --no-model
```

Useful flags: `--budget-chars=60000` (DOM-map input budget),
`--temperature=0.0`, `--max-tokens=2048`,
`--mode=instruct` (turns reasoning OFF via the runtime policy's off_value; note that
`--effort=none` does NOT — the policy's effort map has no `none`, so it falls back to the
default effort), `--effort=low|medium|high`,
`--capture-timeout=180`, `--runs-dir=<path>`, `--port=8790`,
`--bind=127.0.0.1`, `--dom-max-bytes=8000000`.
Per-capture map knobs (recorded in `dom-map.json` → `options`): `--map-floor=N`
(text-block character floor, default 6) and `--preview-chars=N` (preview cap, default 260).

**What the floor may and may not remove.** The floor governs *selectability*, not content:
`content-store.json` renders each included block's whole subtree with no floor, so text
under a skipped block is still reachable by selecting its nearest included ancestor.
Anything the floor still refuses is listed in `dom-map.json` → `dropped_text_blocks`
(count, chars, up to 200 text samples) — drops are auditable, never invisible. Blocks that
carry a value (a digit, `€`, `$` or `%`) are kept regardless of length (`keepValueShaped`,
default on): a 15-char `"mind. 60.000EUR"` was once dropped by a 25-char floor. Known
precision cost: `"<3"` and table-of-contents numbers like `"2.2Llama 2"` are also kept.
Counters are split — `skipped.small_text_blocks` vs `skipped.small_regions` — because the
region count (containers rejected for being small) is not content loss and used to be
reported as if it were.

The harness loads **two** copies of production extractors, both byte-identical and hash-checked
per capture: `generic-extractor.js` (all URLs) and `reddit-extractor.js` (reddit.com, with the
generic one excluded there so the adapter wins deterministically — production itself registers
both on reddit and takes whichever reports first).

## Artifacts

`src/tests/extraction-compare/runs/<snapshot-id>/` (written by the server + the script):

| file | what |
|---|---|
| `request.json` / `claimed.json` | queue handshake (PHP → server via the shared dir) |
| `page.html` | rendered DOM at capture time (byte-capped, `capture-meta.json` flags truncation) |
| `pipeline-a.json` | **Pipeline A raw output**, verbatim extractor result |
| `dom-map.json` | compact map: per-block id, tag, role, depth, own/total chars, preview, table info, link cues, parent_id |
| `content-store.json` | full content per id (markdown) — preview truncation never destroys it |
| `capture-limitations.json` | frames, shadow DOM, hidden text, caps, images pending |
| `capture-timings.json` / `capture-meta.json` | capture timings, extractor hash check |
| `progress.log` | harness stage log for this snapshot |
| `q01/question.json` | the per-question run record |
| `q01/pipeline-a.md` | Pipeline A, rendered for reading |
| `q01/model-input.txt` | **exact** system+user prompt sent to the model |
| `q01/model-input-meta.json` | budget accounting: blocks shown/omitted, chars omitted |
| `q01/model-request.json` | endpoint, model name, sampling/reasoning settings, messages |
| `q01/model-response.json` | raw response, usage, latency, errors |
| `q01/selection.json` | parsed + validated selection: accepted, unknown ids, duplicates, collapsed overlaps |
| `q01/pipeline-b.md` | Pipeline B content, extracted deterministically from selected ids |
| `q01/stats.json` | all measured sizes/costs/failures |
| `q01/report.md` | side-by-side report + capture limitations + blank human-review checklist |

Each question gets its own `qNN/` directory, so one snapshot can be replayed with
as many questions as needed; only the model pass re-runs.

## Design notes / boundaries

* One capture = one page load = both pipelines. The snapshot is taken at the
  moment the production extractor considers the page settled, so a page that
  keeps mutating afterwards is captured as it was.
* One model selection pass only. No autonomous search, navigation, sufficiency
  evaluator or repeated inspection loop — this experiment tests DOM-guided
  selection alone.
* The map removes scripts/styles/noscript/svg/iframe subtrees, includes only
  text-bearing blocks above a size floor plus *significant* regions (≥2 child
  blocks or ≥100 chars of own text), and never repeats a descendant's text under
  an ancestor (previews use own text only).
* Budget: `--budget-chars`. If the map exceeds it, the remaining blocks are
  dropped and an **OMISSIONS section is added to the prompt** naming the id
  range, block count and characters omitted. The prompt is never silently
  truncated (`model-input.txt` is exactly what was sent).
* A failed model call is reported as a failure. There is no silent fallback to
  Pipeline A.
* No winner is computed. `report.md` ends with an empty human-review checklist.
