# Session Evidence Reuse — V1 Spec

Intercept `search_web` and, in a narrow, precision-biased case, satisfy the
query from already-retained `backing_chunks` instead of hitting the network.
The model never chooses reuse; the system does, deterministically.

## Problem

After atomization, the model sees only the compact atoms (e.g. "3-day battery
life") and not the full retained chunks (`backing_chunks` still holds "420 mAh").
On a follow-up that asks for the dropped detail, the model re-searches the web
every time. Reuse is a deterministic property of the data (is the value in the
chunk or not?), so it must be a system decision — not a model judgment, and not
an LLM coverage verifier.

## Design invariants (non-negotiable)

1. **2-call lock → precision-bias.** A tool turn is `firstPass` + one answer
   inference. A false re-read (reuse fires, but the value is wrong or absent)
   leaves the answer under-evidenced with no recovery — there is no third call
   to re-issue a search. A false search is the status quo. Therefore reuse only
   fires when the retained chunk *clearly* contains the requested detail;
   otherwise we search the web.
2. **BM25 = candidate retrieval only.** It narrows the set; it never decides
   sufficiency. "Related ≠ contains."
3. **No LLM verifier.** A coverage check would be a third inference against the
   1-or-2-call budget.
4. **Session evidence, not a cache.** No TTL, no freshness machinery. An
   explicit "re-search / refresh / latest" request bypasses reuse.
5. **Model behavior unchanged.** It keeps emitting `search_web`; the intercept
   is transparent to it.
6. **No executable path change until the shadow evaluation measures
   false-positive precision.**

## V1 scope

- **QUANTITY with an explicit unit, only.** No DATE / IDENTITY / BOOLEAN
  generalization yet.
- Out of scope for V1: other answer types, an LLM verifier, and atom-absence
  as a *gate* (see below — atom-absence is logged, not acted on).

## The gate (implementation-level pseudocode)

Runs on every `search_web` call, before any network access. Two stages.

### Stage 1 — candidate retrieval (necessary, never sufficient)

```
retained = active backing_chunks for session
           (data_fetching rows where raw_evicted = 0 OR atomic_context IS NOT NULL)
if retained empty:
    return NETWORK_SEARCH                      # nothing to check

candidates = bm25_rank(retained, query).top(K, above=FLOOR_SCORE)
if candidates empty:
    return NETWORK_SEARCH                      # unrelated topic — free kill
```

K and FLOOR_SCORE are tunables (see Open decisions). Stage 1 exists only to
avoid running Stage 2 over an unrelated corpus.

### Stage 2 — QUANTITY unit-value verification (the sufficiency test)

```
# 2a. The query must request an explicit unit.
unit = extract_unit(query)                     # from fixed UNIT_LEXICON
if unit is None:
    return NETWORK_SEARCH                      # no explicit unit -> ambiguous

# 2b. A numeric value must sit adjacent to that unit in the chunk,
#     tied locally to the query's property/subject.
for chunk in candidates:
    for match in find_value_unit_spans(chunk.text, unit):
        # match = (numeric value, unit) in a tight local span
        #          (same sentence / table row / N-token window)
        if not locally_tied(match, query_property_noun(chunk, query),
                                  query_subject_noun(chunk, query)):
            continue                           # value present but wrong subject
        return REUSE(chunk)                    # original S#-C# provenance

return NETWORK_SEARCH                          # value not clearly present
```

`locally_tied` requires the query's property noun ("battery") and/or subject
("Aura X9") to co-occur with the value+unit inside the same local span. This is
the guard against "a 420 mAh figure that belongs to a different device."

### Signals logged, not gated

- `atom_absent` — whether the matched value is present in the chunk but missing
  from that row's `atomic_context`. This is logged as context only; it does NOT
  participate in the reuse decision. (If the value were already in the atoms,
  the model would normally have answered directly rather than emit `search_web`.)

## Failure modes

| Case | Result | Acceptable? |
|------|--------|-------------|
| Reuse fires, value correct | token/latency saved | yes (the win) |
| Reuse fires, value wrong/absent | under-evidenced answer, no recovery | **never** (must be ~0) |
| Web search when value was present | status quo | yes |
| Ambiguity anywhere | web search | yes (the default) |

## Shadow / log-only evaluation (before any path change)

Instrument `search_web` execution to compute the gate verdict and log it, while
behavior remains byte-for-byte unchanged (no short-circuit). Per call, log:

```
event: search_evidence_shadow
session_id, query, unit (or null), matched_value (or null),
candidate_chunk_ids, verdict (would_reuse | would_search), reason,
atom_absent (bool)
```

Offline measurement on real sessions:

- **False-positive precision** = `# would_reuse where an offline judge confirms
  the matched value actually answers the query` / `# would_reuse`.
  Success threshold: precision ≥ 0.99 (target 1.0) before wiring the
  short-circuit.
- **Recall** = `# would_reuse` / `# queries whose answer was in retained chunks`.
  Informational only — low recall is expected and acceptable under
  precision-bias.
- Tune K and FLOOR_SCORE only against the logged data, not by intuition.

## Open decisions (settle before shadow instrumentation)

1. `UNIT_LEXICON` — exact list (mAh, GB, MB, TB, mm, cm, g, kg, hours, days, W,
   Hz, nit, inches, mph, km/h, …). Minimal first.
2. "Tight local span" — sentence boundary vs N-token window vs table row. The
   structural chunker already preserves `<table>` rows / headings; prefer the
   narrowest span that still captures `value + unit + subject`.
3. K and FLOOR_SCORE for candidate retrieval.
4. Intercept hook location — `SearchWebTool::execute` vs `ToolExecutionService`
   (the intercept is read-only in shadow mode; it will short-circuit later).

## Deferred / non-goals

- DATE / IDENTITY / BOOLEAN answer types.
- LLM coverage verifier.
- Cross-session / global cache with TTL.
- Removing `search_session_evidence` (its rehydrate + BM25 code is exactly what
  the intercept reuses).
