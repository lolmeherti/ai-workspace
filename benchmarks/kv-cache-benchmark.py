#!/usr/bin/env python3
"""
Standalone llama.cpp KV prompt-shape benchmark.

Talks directly to a running llama-server (default http://127.0.0.1:1234).
No Localsy code imports; Python stdlib only. Re-runnable unchanged against any
loaded model (auto-detected from /v1/models).

Purpose: measure whether prompt KV cache survives across the prompt shapes
Localsy uses and proposes, focusing on the "D" atomization strategy
(answer request populates the KV -> atomizer request should reuse the prefix).

Methodology note (important):
  llama.cpp's prompt cache (this build) serves the LONGEST matching prefix from
  ANY recently-cached prompt, not only the immediately-preceding request. A
  boundary-ladder or back-to-back test that shares a [system..U4] prefix will
  therefore pollute the measurement: the atomizer can "hit" an earlier ladder's
  cached prefix instead of its own answer. Two consequences:

  1. Boundaries are computed by RECONSTRUCTING the serialized prompt from the
     known template rules and /tokenize-ing it -- never by sending chat
     requests that would populate the cache.
  2. Every test uses a unique nonce in the system prompt, so no two tests ever
     share a [system..U4] prefix. The atomizer's only possible cache hit is its
     own immediately-preceding answer.

Run:
  python kv-cache-benchmark.py --probe    # verify API fields + reconstruction
  python kv-cache-benchmark.py --smoke    # small matrix, one content size
  python kv-cache-benchmark.py --full     # full size matrix (slow)
  python kv-cache-benchmark.py --history 2000,8000 --evidence 1000,4000 --atoms 250,500
"""

import argparse
import json
import os
import random
import sys
import time
import urllib.request
import urllib.error

BASE = "http://127.0.0.1:1234"
MODEL_NAME = None

MODE_EFFORT = {"instruct": "none", "medium": "medium", "low": "low", "xhigh": "xhigh"}

ATOMIZE_INSTRUCTION = "Extract the durable factual claims from the provided context as [S#] claim lines."
U4_PROMPT = "Analyze the provided context and summarize the key insight in a few sentences."

# Banner strings injected by the Qwen template into the system message for the
# graduated efforts (chat_template.jinja lines 84, 86). medium/none have none.
LOW_BANNER = ("Reasoning effort is set to low. Keep your thinking brief and focused, "
              "moving directly to the conclusion without unnecessary elaboration.")
XHIGH_BANNER = ("Reasoning effort is set to xhigh. Please think carefully through the task, "
                "validate key assumptions, consider plausible alternatives, and prioritize "
                "correctness, consistency, and clarity in the final answer.")

_noncelock = [0]


# --------------------------------------------------------------------------- #
# HTTP + server access
# --------------------------------------------------------------------------- #

def _request(path, obj=None, method="POST"):
    url = BASE + path
    data = json.dumps(obj).encode("utf-8") if obj is not None else None
    req = urllib.request.Request(url, data=data,
                                 headers={"Content-Type": "application/json"}, method=method)
    with urllib.request.urlopen(req, timeout=1200) as r:
        return json.loads(r.read().decode("utf-8"))


def get(path):
    return _request(path, None, "GET")


def post(path, obj):
    return _request(path, obj, "POST")


def tokenize(text):
    return post("/tokenize", {"content": text})


def ntokens(text):
    return len(tokenize(text)["tokens"])


def detect_model():
    global MODEL_NAME
    if MODEL_NAME:
        return MODEL_NAME
    try:
        data = get("/v1/models").get("data") or []
        if data:
            MODEL_NAME = data[0].get("id") or data[0].get("name")
    except Exception:
        pass
    MODEL_NAME = MODEL_NAME or "local-model"
    return MODEL_NAME


def genprompt(mode):
    # chat_template.jinja lines 424-431.
    if mode == "instruct":
        return "<|im_start|>assistant\n<think>\n\n</think>\n\n"
    return "<|im_start|>assistant\n<think>\n"


def banner_for(mode):
    return {"low": LOW_BANNER, "xhigh": XHIGH_BANNER}.get(mode, "")


# --------------------------------------------------------------------------- #
# Prompt reconstruction (boundary offsets, no cache pollution)
# --------------------------------------------------------------------------- #

def render_prompt(messages, mode, bos=False):
    """Reconstruct the exact serialized prompt for the loaded Qwen template
    (chat_template.jinja) for our controlled message shapes: no tools, no
    tool_calls, string content only. Returns the raw text passed to the
    tokenizer. `bos` prepends the BOS token when the server does."""
    banner = banner_for(mode)
    head = 0
    while head < len(messages) and messages[head]["role"] in ("system", "developer"):
        head += 1
    sys_parts = []
    for m in messages[:head]:
        p = (m.get("content") or "").strip()
        if p:
            sys_parts.append(p)
    sys_content = "\n\n".join(sys_parts)

    out = []
    if sys_content or banner:
        if banner:
            out.append("<|im_start|>system\n" + banner + "\n\n" + sys_content + "<|im_end|>\n")
        else:
            out.append("<|im_start|>system\n" + sys_content + "<|im_end|>\n")

    for m in messages[head:]:
        role = m["role"]
        content = (m.get("content") or "").strip()
        if role == "user":
            out.append("<|im_start|>user\n" + content + "<|im_end|>\n")
        elif role == "assistant":
            rc = (m.get("reasoning_content") or "").strip()
            if rc:
                out.append("<|im_start|>assistant\n<think>\n" + rc + "\n</think>\n\n"
                           + content + "<|im_end|>\n")
            else:
                out.append("<|im_start|>assistant\n<think>\n\n</think>\n\n"
                           + content + "<|im_end|>\n")

    prompt = "".join(out) + genprompt(mode)
    return ("<|endoftext|>" + prompt) if bos else prompt


def render_boundaries(system, conv, evidence, u4, assistant_msg, mode, bos):
    """Absolute token offsets (excluding the generation prompt) of each boundary
    in a prompt serialized in `mode`. Computed purely from reconstruction."""
    gp = ntokens(genprompt(mode))
    base = ([{"role": "system", "content": system}] + conv
            + [{"role": "user", "content": evidence}] + [{"role": "user", "content": u4}])
    offs = {}
    offs["end system"] = ntokens(render_prompt([{"role": "system", "content": system}], mode, bos)) - gp
    offs["end conversation"] = ntokens(render_prompt([{"role": "system", "content": system}] + conv, mode, bos)) - gp
    offs["end raw evidence"] = ntokens(render_prompt([{"role": "system", "content": system}] + conv
                                                     + [{"role": "user", "content": evidence}], mode, bos)) - gp
    offs["end U4"] = ntokens(render_prompt(base, mode, bos)) - gp
    if assistant_msg is not None:
        offs["end assistant"] = ntokens(render_prompt(base + [assistant_msg], mode, bos)) - gp
    return offs


# --------------------------------------------------------------------------- #
# Chat + response parsing
# --------------------------------------------------------------------------- #

def chat(messages, mode="instruct", max_tokens=1, tools=None, seed=None, temperature=None):
    payload = {
        "model": detect_model(),
        "messages": messages,
        "stream": False,
        "max_tokens": max_tokens,
        "chat_template_kwargs": {"reasoning_effort": MODE_EFFORT[mode]},
    }
    if tools is not None:
        payload["tools"] = tools
    if seed is not None:
        payload["seed"] = seed
    if temperature is not None:
        payload["temperature"] = temperature
    return post("/v1/chat/completions", payload)


def extract(resp):
    msg = resp.get("choices", [{}])[0].get("message", {})
    content = msg.get("content") or ""
    reasoning = msg.get("reasoning_content") or ""
    usage = resp.get("usage") or {}
    timings = resp.get("timings") or {}
    return content, reasoning, usage, timings


def split_think(content):
    if "</think>" in content:
        head, _sep, rest = content.partition("</think>")
        reasoning = head[len("<think>"):] if head.startswith("<think>") else head
        return reasoning.strip("\n").strip(), rest.lstrip("\n")
    return "", content


def capture_answer(resp):
    content, reasoning, usage, timings = extract(resp)
    if reasoning:
        return reasoning, content
    return split_think(content)


def total_prompt(t):
    """Total prompt tokens = uncached (prompt_n) + cached (cache_n). In this
    build timings.prompt_n is the NEWLY-EVALUATED (uncached) count."""
    return (t.get("prompt_n") or 0) + (t.get("cache_n") or 0)


def timings_of(t):
    uncached = t.get("prompt_n") or 0
    cached = t.get("cache_n") or 0
    return {
        "prompt_n": uncached + cached,
        "cache_n": cached,
        "uncached_n": uncached,
        "prompt_ms": t.get("prompt_ms") or 0,
        "prompt_tps": t.get("prompt_per_second") or 0,
        "pred_n": t.get("predicted_n") or 0,
        "pred_ms": t.get("predicted_ms") or 0,
    }


# --------------------------------------------------------------------------- #
# Synthetic content (deterministic, approximately sized, unique nonce)
# --------------------------------------------------------------------------- #

WORDS = (
    "alpha beta gamma delta epsilon zeta eta theta iota kappa lambda mu nu xi "
    "omicron pi rho sigma tau upsilon phi chi psi omega analysis synthesis "
    "context retrieval semantic embedding ranking relevance evidence source "
    "claim document paragraph sentence token vector metric graph node edge "
    "cluster centroid distance similarity query result search index catalog "
    "archive record field value key structure filter aggregation".split()
)


def gen_text(seed, approx_tokens):
    rng = random.Random(seed)
    n_words = max(8, int(approx_tokens * 1.4))
    return " ".join(rng.choice(WORDS) for _ in range(n_words))


def next_nonce():
    _noncelock[0] += 1
    return f"NONCE-{_noncelock[0]:06d}"


def build_content(history_tokens, evidence_tokens, atoms_tokens, nonce, turns=2):
    system = ("You are a helpful assistant. Answer using only the provided context "
              "and cite sources as [S#] when you use them. " + nonce + ". " + gen_text(1001, 40))
    conv = []
    for i in range(turns):
        conv.append({"role": "user", "content": gen_text(2000 + i, history_tokens // (turns * 2))})
        conv.append({"role": "assistant", "content": gen_text(3000 + i, history_tokens // (turns * 2))})
    evidence = "[S1] " + gen_text(4000, evidence_tokens // 2) + "\n\n[S2] " + gen_text(4001, evidence_tokens // 2)
    atoms = "[S1] " + gen_text(5000, atoms_tokens // 2) + "\n[S2] " + gen_text(5001, atoms_tokens // 2)
    return system, conv, evidence, atoms


# --------------------------------------------------------------------------- #
# Measurement
# --------------------------------------------------------------------------- #

def result_dict(name, mode_pair, m2, elapsed, boundaries, verdicts, extra):
    prompt_n = m2["prompt_n"]
    cache_n = m2["cache_n"]
    return {
        "scenario": name,
        "model": detect_model(),
        "mode_pair": mode_pair,
        "prompt_n": prompt_n,
        "cache_n": cache_n,
        "uncached_n": max(0, prompt_n - cache_n),
        "cache_hit_ratio": round(cache_n / prompt_n, 4) if prompt_n else 0.0,
        "prompt_ms": m2["prompt_ms"],
        "prompt_tps": round(m2["prompt_tps"], 1),
        "pred_n": m2["pred_n"],
        "pred_ms": m2["pred_ms"],
        "total_elapsed_s": round(elapsed, 2),
        "boundaries": boundaries,
        "verdicts": verdicts,
        "extra": extra,
    }


def d_test(content, answer_mode, atom_mode, variant, bos, seed=42):
    system, conv, evidence, atoms = content
    u4 = U4_PROMPT
    answer_msgs = ([{"role": "system", "content": system}] + conv
                   + [{"role": "user", "content": evidence}]
                   + [{"role": "user", "content": u4}])

    # answer pass: populate the KV, capture reasoning + visible answer
    resp = chat(answer_msgs, mode=answer_mode, max_tokens=256, seed=seed)
    reasoning, visible = capture_answer(resp)

    if variant == "D1":
        assistant_msg = {"role": "assistant", "content": visible}
    else:  # D2
        assistant_msg = {"role": "assistant", "content": visible,
                         "reasoning_content": reasoning}

    # atomizer pass: measure cache_n against the answer KV (immediately prior;
    # unique nonce ensures this is the only possible match)
    atom_msgs = (answer_msgs + [assistant_msg]
                 + [{"role": "user", "content": ATOMIZE_INSTRUCTION}])
    t0 = time.time()
    _c2, _r2, _u2, t2 = extract(chat(atom_msgs, mode=atom_mode, max_tokens=1))
    elapsed = time.time() - t0
    m2 = timings_of(t2)

    bounds = render_boundaries(system, conv, evidence, u4, assistant_msg, atom_mode, bos)
    verdicts = {label: m2["cache_n"] >= off for label, off in bounds.items()}

    return result_dict(f"D[{variant}] {answer_mode} answer -> {atom_mode} atomizer",
                       f"{answer_mode} -> {atom_mode}", m2, elapsed, bounds, verdicts,
                       {"reasoning_chars": len(reasoning),
                        "answer_chars": len(visible),
                        "reasoning_present": bool(reasoning)})


def baseline_test(content, bos):
    system, conv, evidence, atoms = content
    msgs = ([{"role": "system", "content": system}] + conv
            + [{"role": "user", "content": evidence}] + [{"role": "user", "content": U4_PROMPT}])
    _ = chat(msgs, mode="instruct", max_tokens=1)
    _c, _r, _u, t2 = extract(chat(msgs, mode="instruct", max_tokens=1))
    m2 = timings_of(t2)
    bounds = render_boundaries(system, conv, evidence, U4_PROMPT, None, "instruct", bos)
    verdicts = {label: m2["cache_n"] >= off for label, off in bounds.items()}
    return result_dict("baseline identical repeat", "instruct -> instruct", m2, 0.0, bounds, verdicts, {})


def front_mutation_test(content, bos):
    system, conv, evidence, atoms = content
    msgs1 = ([{"role": "system", "content": system}] + [{"role": "user", "content": evidence}]
             + conv + [{"role": "user", "content": U4_PROMPT}])
    new_evidence = evidence + "\n\n[S3] " + gen_text(9001, 200)
    msgs2 = ([{"role": "system", "content": system}] + [{"role": "user", "content": new_evidence}]
             + conv + [{"role": "user", "content": U4_PROMPT}])
    _ = chat(msgs1, mode="instruct", max_tokens=1)
    _c, _r, _u, t2 = extract(chat(msgs2, mode="instruct", max_tokens=1))
    m2 = timings_of(t2)
    bounds = render_boundaries(system, conv, new_evidence, U4_PROMPT, None, "instruct", bos)
    verdicts = {label: m2["cache_n"] >= off for label, off in bounds.items()}
    return result_dict("front mutation (current Localsy)", "instruct -> instruct", m2, 0.0, bounds, verdicts, {})


def evidence_tail_test(content, bos):
    system, conv, evidence, atoms = content
    msgs1 = ([{"role": "system", "content": system}] + conv + [{"role": "user", "content": U4_PROMPT}])
    msgs2 = ([{"role": "system", "content": system}] + conv
             + [{"role": "user", "content": evidence}] + [{"role": "user", "content": U4_PROMPT}])
    _ = chat(msgs1, mode="instruct", max_tokens=1)
    _c, _r, _u, t2 = extract(chat(msgs2, mode="instruct", max_tokens=1))
    m2 = timings_of(t2)
    bounds = render_boundaries(system, conv, evidence, U4_PROMPT, None, "instruct", bos)
    verdicts = {label: m2["cache_n"] >= off for label, off in bounds.items()}
    return result_dict("evidence at tail (added after conversation)", "instruct -> instruct", m2, 0.0, bounds, verdicts, {})


def atoms_front_changed_test(content, bos):
    system, conv, evidence, atoms = content
    atoms2 = "[S1] " + gen_text(7000, 150) + "\n[S2] " + gen_text(7001, 150)
    msgs1 = ([{"role": "system", "content": system}] + [{"role": "user", "content": atoms}]
             + conv + [{"role": "user", "content": U4_PROMPT}])
    msgs2 = ([{"role": "system", "content": system}] + [{"role": "user", "content": atoms2}]
             + conv + [{"role": "user", "content": U4_PROMPT}])
    _ = chat(msgs1, mode="instruct", max_tokens=1)
    _c, _r, _u, t2 = extract(chat(msgs2, mode="instruct", max_tokens=1))
    m2 = timings_of(t2)
    bounds = render_boundaries(system, conv, atoms2, U4_PROMPT, None, "instruct", bos)
    verdicts = {label: m2["cache_n"] >= off for label, off in bounds.items()}
    return result_dict("atoms at front -> changed atoms", "instruct -> instruct", m2, 0.0, bounds, verdicts, {})


def atoms_tail_changed_test(content, bos):
    system, conv, evidence, atoms = content
    atoms2 = "[S1] " + gen_text(7000, 150) + "\n[S2] " + gen_text(7001, 150)
    msgs1 = ([{"role": "system", "content": system}] + conv
             + [{"role": "user", "content": atoms}] + [{"role": "user", "content": U4_PROMPT}])
    msgs2 = ([{"role": "system", "content": system}] + conv
             + [{"role": "user", "content": atoms2}] + [{"role": "user", "content": U4_PROMPT}])
    _ = chat(msgs1, mode="instruct", max_tokens=1)
    _c, _r, _u, t2 = extract(chat(msgs2, mode="instruct", max_tokens=1))
    m2 = timings_of(t2)
    bounds = render_boundaries(system, conv, atoms2, U4_PROMPT, None, "instruct", bos)
    verdicts = {label: m2["cache_n"] >= off for label, off in bounds.items()}
    return result_dict("atoms at tail -> changed atoms", "instruct -> instruct", m2, 0.0, bounds, verdicts, {})


def xor_test(content, direction, bos):
    system, conv, evidence, atoms = content
    if direction == "raw_to_atoms":
        first, second = evidence, atoms
        label = "XOR raw -> atoms"
    else:
        first, second = atoms, evidence
        label = "XOR atoms -> restored raw"
    msgs1 = ([{"role": "system", "content": system}] + conv
             + [{"role": "user", "content": first}] + [{"role": "user", "content": U4_PROMPT}])
    msgs2 = ([{"role": "system", "content": system}] + conv
             + [{"role": "user", "content": second}] + [{"role": "user", "content": U4_PROMPT}])
    _ = chat(msgs1, mode="instruct", max_tokens=1)
    _c, _r, _u, t2 = extract(chat(msgs2, mode="instruct", max_tokens=1))
    m2 = timings_of(t2)
    bounds = render_boundaries(system, conv, second, U4_PROMPT, None, "instruct", bos)
    verdicts = {label: m2["cache_n"] >= off for label, off in bounds.items()}
    return result_dict(label, "instruct -> instruct", m2, 0.0, bounds, verdicts, {})


def tool_schema_test(content, identical, bos):
    system, conv, evidence, atoms = content
    tools = [{
        "type": "function",
        "function": {
            "name": "search_local",
            "description": "Search the local knowledge base.",
            "parameters": {
                "type": "object",
                "properties": {"query": {"type": "string"}},
                "required": ["query"],
            },
        },
    }]
    msgs = ([{"role": "system", "content": system}] + conv + [{"role": "user", "content": U4_PROMPT}])
    if identical:
        _ = chat(msgs, mode="instruct", max_tokens=1, tools=tools)
        _c, _r, _u, t2 = extract(chat(msgs, mode="instruct", max_tokens=1, tools=tools))
        label = "tool schemas present -> identical schemas"
    else:
        _ = chat(msgs, mode="instruct", max_tokens=1, tools=tools)
        _c, _r, _u, t2 = extract(chat(msgs, mode="instruct", max_tokens=1))
        label = "tool schemas present -> absent"
    m2 = timings_of(t2)
    r = result_dict(label, "instruct -> instruct", m2, 0.0, {}, {}, {"tools": label})
    r["verdicts"] = {"full reuse": m2["cache_n"] >= m2["prompt_n"]}
    return r


# --------------------------------------------------------------------------- #
# Probe (field names + reconstruction verification)
# --------------------------------------------------------------------------- #

def run_probe():
    props = get("/props")
    print(f"BASE = {BASE}")
    print(f"model_alias: {props.get('model_alias')}")
    print(f"n_ctx: {props.get('n_ctx')}   total_slots: {props.get('total_slots')}")
    gds = props.get("default_generation_settings", {}).get("params", {})
    print(f"chat_format: {gds.get('chat_format')}   reasoning_format: {gds.get('reasoning_format')}   "
          f"reasoning_in_content: {gds.get('reasoning_in_content')}")
    caps = props.get("chat_template_caps", {})
    print(f"caps: supports_reasoning_effort={caps.get('supports_reasoning_effort')} "
          f"supports_preserve_reasoning={caps.get('supports_preserve_reasoning')}")
    print()

    print("--- /tokenize special-token check ---")
    for s in ["<|im_start|>", "<|im_start|>assistant\n<think>\n",
              "<|im_start|>assistant\n<think>\n\n</think>\n\n", "<|im_end|>", "<|endoftext|>"]:
        print(f"  ntokens({s!r}) = {ntokens(s)}")
    print()

    print("--- reconstruction verification (render vs server total) ---")
    system = "You are concise."
    conv = [{"role": "user", "content": "What is 2+2?"}]
    for mode in ["instruct", "medium", "low", "xhigh"]:
        msgs = [{"role": "system", "content": system}] + conv
        r = chat(msgs, mode=mode, max_tokens=1)
        server_total = total_prompt(r["timings"])
        rec_no_bos = ntokens(render_prompt(msgs, mode, bos=False))
        rec_bos = ntokens(render_prompt(msgs, mode, bos=True))
        tag = ("BOS" if server_total == rec_bos
               else "NO-BOS" if server_total == rec_no_bos else "MISMATCH")
        print(f"  {mode:9s}: server={server_total}  render(no_bos)={rec_no_bos}  render(bos)={rec_bos}  -> {tag}")

    print()
    print("--- reasoning handling (thinking/medium) ---")
    msgs = [{"role": "system", "content": "You are concise."},
            {"role": "user", "content": "What is 2+2? Answer in one sentence."}]
    resp = chat(msgs, mode="medium", max_tokens=80, seed=1)
    content, reasoning, usage, timings = extract(resp)
    print(f"  content[:120] = {content[:120]!r}")
    print(f"  reasoning_content[:120] = {reasoning[:120]!r}")
    print(f"  timings keys = {sorted(timings.keys())}")
    print(f"  timings.cache_n={timings.get('cache_n')}  usage={json.dumps(usage)}")


# --------------------------------------------------------------------------- #
# Report
# --------------------------------------------------------------------------- #

def fmt_boundary_report(r):
    lines = []
    b = r["boundaries"]
    if b:
        for label in ["end system", "end conversation", "end raw evidence", "end U4", "end assistant"]:
            if label in b:
                lines.append(f"  {label:<22} {b[label]:>8}")
        ev = b.get("end raw evidence")
        if ev is not None:
            status = "fully reused" if r["cache_n"] >= ev else "NOT reused"
            lines.append("")
            lines.append(f"  raw evidence ended: token {ev}")
            lines.append(f"  cache_n:            token {r['cache_n']}")
            lines.append(f"  RESULT: raw evidence {status}")
    return "\n".join(lines)


def fmt_verdicts(r):
    v = r["verdicts"]
    if not v:
        return ""
    ordered = ["end system", "end conversation", "end raw evidence", "end U4", "end assistant", "full reuse"]
    return "\n".join(f"    survived {l:<22} {'YES' if v[l] else 'NO'}" for l in ordered if l in v)


def print_one(r):
    print(f"=== {r['scenario']} ===")
    print(f"  mode_pair={r['mode_pair']} prompt_n={r['prompt_n']} cache_n={r['cache_n']} "
          f"uncached={r['uncached_n']} ratio={r['cache_hit_ratio']}")
    print(f"  prompt_ms={r['prompt_ms']} prompt_tps={r['prompt_tps']} pred_n={r['pred_n']} pred_ms={r['pred_ms']}")
    if r.get("extra"):
        print(f"  extra={json.dumps(r['extra'])}")
    br = fmt_boundary_report(r)
    if br:
        print(br)
    fv = fmt_verdicts(r)
    if fv:
        print(fv)
    print()


def write_artifacts(results):
    here = os.path.dirname(os.path.abspath(__file__))
    ts = time.strftime("%Y%m%d_%H%M%S")
    jp = os.path.join(here, f"kv-cache-results-{ts}.json")
    mp = os.path.join(here, f"kv-cache-results-{ts}.md")
    with open(jp, "w", encoding="utf-8") as f:
        json.dump(results, f, indent=2)
    with open(mp, "w", encoding="utf-8") as f:
        f.write(f"# KV cache benchmark — {detect_model()}\n\n")
        f.write(f"generated {time.strftime('%Y-%m-%d %H:%M:%S')}\n\n")
        for r in results:
            f.write(f"## {r['scenario']}\n\n")
            f.write(f"- mode pair: `{r['mode_pair']}`, prompt_n: {r['prompt_n']}, cache_n: {r['cache_n']}, "
                    f"uncached_n: {r['uncached_n']}, ratio: {r['cache_hit_ratio']}\n")
            f.write(f"- prompt_ms: {r['prompt_ms']}, prompt_tps: {r['prompt_tps']}, "
                    f"pred_n: {r['pred_n']}, pred_ms: {r['pred_ms']}\n")
            if r.get("extra"):
                f.write(f"- extra: `{json.dumps(r['extra'])}`\n")
            br = fmt_boundary_report(r)
            if br:
                f.write("\n```\n" + br + "\n```\n")
            fv = fmt_verdicts(r)
            if fv:
                f.write("\n```\n" + fv + "\n```\n")
            f.write("\n")
    return jp, mp


# --------------------------------------------------------------------------- #
# Main
# --------------------------------------------------------------------------- #

SCENARIOS = ["d", "baseline", "front_mutation", "evidence_tail", "atoms_front_changed",
             "atoms_tail_changed", "xor_raw_to_atoms", "xor_atoms_to_raw",
             "tool_present_absent", "tool_identical"]

D_PAIRS = [
    ("medium", "instruct"),
    ("low", "instruct"),
    ("xhigh", "instruct"),
    ("medium", "medium"),
    ("low", "low"),
    ("xhigh", "xhigh"),
    ("instruct", "instruct"),
]


def run_scenario(kind, content, bos, *args):
    if kind == "d":
        am, bm, variant = args
        return d_test(content, am, bm, variant, bos)
    if kind == "baseline":
        return baseline_test(content, bos)
    if kind == "front_mutation":
        return front_mutation_test(content, bos)
    if kind == "evidence_tail":
        return evidence_tail_test(content, bos)
    if kind == "atoms_front_changed":
        return atoms_front_changed_test(content, bos)
    if kind == "atoms_tail_changed":
        return atoms_tail_changed_test(content, bos)
    if kind == "xor_raw_to_atoms":
        return xor_test(content, "raw_to_atoms", bos)
    if kind == "xor_atoms_to_raw":
        return xor_test(content, "atoms_to_raw", bos)
    if kind == "tool_present_absent":
        return tool_schema_test(content, False, bos)
    if kind == "tool_identical":
        return tool_schema_test(content, True, bos)
    raise ValueError(f"unknown scenario {kind}")


def main():
    ap = argparse.ArgumentParser(description="llama.cpp KV prompt-shape benchmark")
    ap.add_argument("--probe", action="store_true")
    ap.add_argument("--smoke", action="store_true")
    ap.add_argument("--full", action="store_true")
    ap.add_argument("--history", default=None)
    ap.add_argument("--evidence", default=None)
    ap.add_argument("--atoms", default=None)
    ap.add_argument("--url", default=None)
    ap.add_argument("--only", default=None, help="comma list of scenario kinds")
    ap.add_argument("--bos", action="store_true", help="force BOS in reconstruction")
    args = ap.parse_args()

    global BASE
    if args.url:
        BASE = args.url.rstrip("/")

    if args.probe:
        run_probe()
        return

    if args.full:
        history = [2000, 8000, 16000, 32000, 64000]
        evidence = [1000, 4000, 8000, 16000]
        atoms = [250, 500, 1000, 2000]
    elif args.history or args.evidence or args.atoms:
        def csv(s, d):
            return [int(x) for x in s.split(",")] if s else d
        history = csv(args.history, [2000])
        evidence = csv(args.evidence, [1000])
        atoms = csv(args.atoms, [250])
    else:
        history, evidence, atoms = [2000], [1000], [250]

    # BOS: trust the server's own flag when available
    bos = args.bos
    if not args.bos:
        try:
            bos = bool(get("/props").get("add_bos_token", False))
        except Exception:
            bos = False

    only = set(args.only.split(",")) if args.only else None

    all_results = []
    for h in history:
        for e in evidence:
            for a in atoms:
                print(f"\n########## history={h} evidence={e} atoms={a} ##########\n")
                kinds = [s for s in SCENARIOS if (not only or s in only)]
                for kind in kinds:
                    if kind == "d":
                        # each D variant gets its OWN nonce so no two tests share
                        # a [system..U4] prefix (cross-test pollution otherwise)
                        for am, bm in D_PAIRS:
                            for variant in ("D1", "D2"):
                                content = build_content(h, e, a, next_nonce())
                                try:
                                    r = run_scenario(kind, content, bos, am, bm, variant)
                                except Exception as ex:
                                    print(f"  [ERROR] d {am}->{bm} {variant}: {ex}")
                                    continue
                                all_results.append(r)
                                print_one(r)
                    else:
                        content = build_content(h, e, a, next_nonce())
                        try:
                            r = run_scenario(kind, content, bos)
                        except Exception as ex:
                            print(f"  [ERROR] {kind}: {ex}")
                            continue
                        all_results.append(r)
                        print_one(r)

    if all_results:
        jp, mp = write_artifacts(all_results)
        print(f"artifacts:\n  {jp}\n  {mp}")


if __name__ == "__main__":
    main()
