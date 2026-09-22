#!/usr/bin/env python3
"""
Localsy lifecycle cache benchmark.

Reproduces the real multi-request sequences (answer -> D atomizer -> next
turn; rolling tail atoms; restore raw; front atoms) against the live
llama-server and measures cache_n on the final request of each, against
expected_lcp computed from the fully-rendered + /tokenize'd prompts of every
preceding request in the sequence.

All requests use instruct mode (reasoning_effort=none): no banner, no
reasoning, so the assistant turn is reconstructed from the visible answer only
(D1-style) and the measurement isolates the cache heuristic from mode effects.

expected_lcp = max over every preceding request of LCP(render(next), render(prev)).
cache_n      = server-reported matching-prefix length on the final request.

A unique nonce stamps the system prompt of each lifecycle so no cross-lifecycle
prefix match pollutes the measurement.
"""

import json
import os
import random
import time
import urllib.request

BASE = "http://127.0.0.1:1234"
MODEL = "Qwen3.8 27B UD_Q5_K_XL (unsloth)"
MODE = "instruct"
GENPROMPT = "<|im_start|>assistant\n<think>\n\n</think>\n\n"

WORDS = ("alpha beta gamma delta epsilon zeta eta theta iota kappa lambda mu nu xi "
         "omicron pi rho sigma tau upsilon phi chi psi omega analysis synthesis "
         "context retrieval semantic embedding ranking relevance evidence source "
         "claim document paragraph sentence token vector metric graph node edge").split()

U4 = "Based on the provided context, what is the main finding?"
U5 = "What are the implications of that finding?"
U6 = "How should I act on it?"
U7 = "Summarize everything so far."
ATOMIZE = "Extract the durable factual claims from the context as [S#] claim lines."

_nonce = [0]
_RUN_ID = "%x%x" % (os.getpid(), time.time_ns())


def post(path, obj):
    req = urllib.request.Request(BASE + path, data=json.dumps(obj).encode(),
                                 headers={"Content-Type": "application/json"}, method="POST")
    return json.loads(urllib.request.urlopen(req, timeout=600).read())


def tokenize(text):
    return post("/tokenize", {"content": text})["tokens"]


def ntokens(text):
    return len(tokenize(text))


def chat(msgs, max_tokens=1, seed=None):
    payload = {"model": MODEL, "messages": msgs, "stream": False, "max_tokens": max_tokens,
               "chat_template_kwargs": {"reasoning_effort": "none"}}
    if seed is not None:
        payload["seed"] = seed
    return post("/v1/chat/completions", payload)


def extract(resp):
    msg = resp.get("choices", [{}])[0].get("message", {})
    return msg.get("content") or "", msg.get("reasoning_content") or ""


def gen_text(seed, approx_tokens):
    rng = random.Random(seed)
    return " ".join(rng.choice(WORDS) for _ in range(max(4, int(approx_tokens * 1.4))))


def render_prompt(messages):
    head = 0
    while head < len(messages) and messages[head]["role"] in ("system", "developer"):
        head += 1
    sys_parts = [m["content"].strip() for m in messages[:head] if (m.get("content") or "").strip()]
    out = []
    if sys_parts:
        out.append("<|im_start|>system\n" + "\n\n".join(sys_parts) + "<|im_end|>\n")
    for m in messages[head:]:
        content = (m.get("content") or "").strip()
        if m["role"] == "user":
            out.append("<|im_start|>user\n" + content + "<|im_end|>\n")
        elif m["role"] == "assistant":
            rc = (m.get("reasoning_content") or "").strip()
            if rc:
                out.append("<|im_start|>assistant\n<think>\n" + rc + "\n</think>\n\n"
                           + content + "<|im_end|>\n")
            else:
                out.append("<|im_start|>assistant\n<think>\n\n</think>\n\n"
                           + content + "<|im_end|>\n")
    return "".join(out) + GENPROMPT


def lcp(a, b):
    n = 0
    for x, y in zip(a, b):
        if x != y:
            break
        n += 1
    return n


def next_nonce():
    _nonce[0] += 1
    return f"NONCE-{_RUN_ID}-{_nonce[0]:04d}"


def system(nonce):
    return {"role": "system", "content": "You are a helpful assistant. Answer using only the provided context. " + nonce}


def history():
    return [
        {"role": "user", "content": "What is this project about?"},
        {"role": "assistant", "content": "A local AI chat application with persistent memory and file search."},
        {"role": "user", "content": "How does the memory work?"},
        {"role": "assistant", "content": "It extracts durable facts and re-injects them as context."},
    ]


def evidence(ntok=300):
    return "[S1] " + gen_text(4000, ntok) + "\n\n[S2] " + gen_text(4001, ntok)


def atoms_synthetic(ntok=60, seed_base=5000):
    return "[S1] " + gen_text(seed_base, ntok) + "\n[S2] " + gen_text(seed_base + 1, ntok)


def generate(msgs, max_tokens=96, seed=42):
    resp = chat(msgs, max_tokens, seed)
    content, reasoning = extract(resp)
    if reasoning:
        return content
    if "</think>" in content:
        return content.split("</think>", 1)[1].lstrip("\n")
    return content


def measure(msgs):
    resp = chat(msgs, 1)
    t = resp["timings"]
    cache = t.get("cache_n") or 0
    total = (t.get("prompt_n") or 0) + cache
    return cache, total


def boundaries(groups):
    """groups: list of (label, [messages]). Returns token offset (excl genprompt)
    of the end of each group in the fully-rendered prompt."""
    gp = ntokens(GENPROMPT)
    offs, prefix = {}, []
    for label, msgs in groups:
        prefix += msgs
        offs[label] = len(tokenize(render_prompt(prefix))) - gp
    return offs


def report(name, expected, cache, total, bounds):
    print(f"=== {name} ===")
    print(f"  expected_lcp={expected}  cache_n={cache}  delta={expected - cache}  (total={total})")
    for label in ["end SYSTEM", "end H", "end U4", "end A4", "end ATOMS", "end U5", "end A5", "end U6"]:
        if label in bounds:
            print(f"    {label:<12} {bounds[label]:>6}")
    print()


def lifecycle_A():
    """tool turn -> D atomizer -> next user turn (raw replaced by atoms)."""
    S = system(next_nonce())
    H = history()
    RAW = evidence(300)
    prev_tokens = []

    # REQUEST 1: answer
    m1 = [S] + H + [{"role": "user", "content": RAW}, {"role": "user", "content": U4}]
    A4 = generate(m1)
    prev_tokens.append(tokenize(render_prompt(m1)))

    # REQUEST 2: D atomizer
    m2 = ([S] + H + [{"role": "user", "content": RAW}, {"role": "user", "content": U4},
          {"role": "assistant", "content": A4}, {"role": "user", "content": ATOMIZE}])
    ATOMS = generate(m2, 128)
    prev_tokens.append(tokenize(render_prompt(m2)))

    # REQUEST 3: real next-turn candidate (measure)
    m3 = ([S] + H + [{"role": "user", "content": U4}, {"role": "assistant", "content": A4},
          {"role": "user", "content": ATOMS}, {"role": "user", "content": U5}])
    cache, total = measure(m3)
    t3 = tokenize(render_prompt(m3))
    expected = max(lcp(t3, p) for p in prev_tokens)
    bounds = boundaries([
        ("end SYSTEM", [S]),
        ("end H", H),
        ("end U4", [{"role": "user", "content": U4}]),
        ("end A4", [{"role": "assistant", "content": A4}]),
        ("end ATOMS", [{"role": "user", "content": ATOMS}]),
    ])
    report("Lifecycle A: D atomizer -> next turn (atoms replace raw)", expected, cache, total, bounds)


def lifecycle_B():
    """ordinary next turn with unchanged tail atoms (rolling position)."""
    S = system(next_nonce())
    H = history()
    ATOMS = atoms_synthetic(60)
    prev_tokens = []

    # turn 1
    m1 = [S] + H + [{"role": "user", "content": ATOMS}, {"role": "user", "content": U5}]
    A5 = generate(m1)
    prev_tokens.append(tokenize(render_prompt(m1)))

    # turn 2 (measure)
    m2 = ([S] + H + [{"role": "user", "content": U5}, {"role": "assistant", "content": A5},
          {"role": "user", "content": ATOMS}, {"role": "user", "content": U6}])
    cache, total = measure(m2)
    t2 = tokenize(render_prompt(m2))
    expected = max(lcp(t2, p) for p in prev_tokens)
    bounds = boundaries([
        ("end SYSTEM", [S]),
        ("end H", H),
        ("end U4", [{"role": "user", "content": U5}]),
        ("end A4", [{"role": "assistant", "content": A5}]),
        ("end ATOMS", [{"role": "user", "content": ATOMS}]),
    ])
    report("Lifecycle B: next turn, unchanged tail atoms", expected, cache, total, bounds)


def lifecycle_C():
    """restore raw on a later turn (from atom-active conversation)."""
    S = system(next_nonce())
    H = history()
    ATOMS = atoms_synthetic(60)
    RAW = evidence(300)
    prev_tokens = []

    m1 = [S] + H + [{"role": "user", "content": ATOMS}, {"role": "user", "content": U5}]
    A5 = generate(m1)
    prev_tokens.append(tokenize(render_prompt(m1)))

    m2 = ([S] + H + [{"role": "user", "content": U5}, {"role": "assistant", "content": A5},
          {"role": "user", "content": ATOMS}, {"role": "user", "content": U6}])
    A6 = generate(m2)
    prev_tokens.append(tokenize(render_prompt(m2)))

    # restore raw: expanded conversation + RAW + U_next
    m3 = ([S] + H + [{"role": "user", "content": U5}, {"role": "assistant", "content": A5},
          {"role": "user", "content": U6}, {"role": "assistant", "content": A6},
          {"role": "user", "content": RAW}, {"role": "user", "content": U7}])
    cache, total = measure(m3)
    t3 = tokenize(render_prompt(m3))
    expected = max(lcp(t3, p) for p in prev_tokens)
    bounds = boundaries([
        ("end SYSTEM", [S]),
        ("end H", H),
        ("end U4", [{"role": "user", "content": U5}]),
        ("end A4", [{"role": "assistant", "content": A5}]),
        ("end U5", [{"role": "user", "content": U6}]),
        ("end A5", [{"role": "assistant", "content": A6}]),
        ("end ATOMS", [{"role": "user", "content": RAW}]),
    ])
    report("Lifecycle C: restore raw on a later turn", expected, cache, total, bounds)


def lifecycle_D():
    """front atoms: unchanged (reuse) then mutated (collapse)."""
    S = system(next_nonce())
    H = history()
    ATOMS = atoms_synthetic(60, 5000)
    ATOMS2 = atoms_synthetic(60, 6000)  # mutated (different seeds)
    prev_tokens = []

    m1 = [S] + [{"role": "user", "content": ATOMS}] + H + [{"role": "user", "content": U5}]
    A5 = generate(m1)
    prev_tokens.append(tokenize(render_prompt(m1)))

    # turn 2: atoms unchanged, conversation grows (measure)
    m2 = ([S] + [{"role": "user", "content": ATOMS}] + H + [{"role": "user", "content": U5},
          {"role": "assistant", "content": A5}, {"role": "user", "content": U6}])
    cache1, total1 = measure(m2)
    t2 = tokenize(render_prompt(m2))
    exp1 = max(lcp(t2, p) for p in prev_tokens)
    prev_tokens.append(t2)

    # turn 3: atoms mutated (measure)
    A6 = generate(m2)  # turn 2's answer, for the grown conversation
    m3 = ([S] + [{"role": "user", "content": ATOMS2}] + H + [{"role": "user", "content": U5},
          {"role": "assistant", "content": A5}, {"role": "user", "content": U6},
          {"role": "assistant", "content": A6}, {"role": "user", "content": U7}])
    cache2, total2 = measure(m3)
    t3 = tokenize(render_prompt(m3))
    exp2 = max(lcp(t3, p) for p in prev_tokens)

    b1 = boundaries([
        ("end SYSTEM", [S]),
        ("end ATOMS", [{"role": "user", "content": ATOMS}]),
        ("end H", H),
        ("end U4", [{"role": "user", "content": U5}]),
    ])
    print(f"=== Lifecycle D: front atoms, unchanged ===")
    print(f"  expected_lcp={exp1}  cache_n={cache1}  delta={exp1 - cache1}  (total={total1})")
    for label in ["end SYSTEM", "end ATOMS", "end H", "end U4"]:
        print(f"    {label:<12} {b1[label]:>6}")
    print()

    b2 = boundaries([
        ("end SYSTEM", [S]),
        ("end ATOMS", [{"role": "user", "content": ATOMS2}]),
        ("end H", H),
        ("end U4", [{"role": "user", "content": U5}]),
    ])
    print(f"=== Lifecycle D: front atoms, mutated ===")
    print(f"  expected_lcp={exp2}  cache_n={cache2}  delta={exp2 - cache2}  (total={total2})")
    for label in ["end SYSTEM", "end ATOMS", "end H", "end U4"]:
        print(f"    {label:<12} {b2[label]:>6}")
    print()


if __name__ == "__main__":
    lifecycle_A()
    lifecycle_B()
    lifecycle_C()
    lifecycle_D()
