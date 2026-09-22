#!/usr/bin/env python3
"""
Brutally minimal XOR/append-vs-replace isolation.

For each shape, sends P1 then P2 (both instruct mode, max_tokens=1, no
reasoning change) and compares the server's cache_n against expected_lcp,
which is computed directly from the two fully-rendered + /tokenize'd prompts.

expected_lcp = longest common token prefix of render(P1) and render(P2).
cache_n      = number of prompt tokens the server reports as cached on P2.

If cache_n == expected_lcp  -> clean prefix reuse (append behavior).
If cache_n << expected_lcp  -> real llama.cpp cache limitation/heuristic.

Each test uses a unique nonce so P2 can only match P1, never an earlier test.
"""

import json
import random
import urllib.request

BASE = "http://127.0.0.1:1234"
MODEL = "Qwen3.8 27B UD_Q5_K_XL (unsloth)"

WORDS = ("alpha beta gamma delta epsilon zeta eta theta iota kappa lambda mu nu xi "
         "omicron pi rho sigma tau upsilon phi chi psi omega").split()

_nonce = [0]


def post(path, obj):
    req = urllib.request.Request(BASE + path, data=json.dumps(obj).encode(),
                                 headers={"Content-Type": "application/json"}, method="POST")
    return json.loads(urllib.request.urlopen(req, timeout=300).read())


def tokenize(text):
    return post("/tokenize", {"content": text})["tokens"]


def chat(msgs, max_tokens=1):
    return post("/v1/chat/completions", {
        "model": MODEL, "messages": msgs, "stream": False, "max_tokens": max_tokens,
        "chat_template_kwargs": {"reasoning_effort": "none"},
    })


def gen_text(seed, approx_tokens):
    rng = random.Random(seed)
    return " ".join(rng.choice(WORDS) for _ in range(max(4, int(approx_tokens * 1.4))))


def render_prompt(messages):
    """Exact reconstruction of the loaded Qwen template (instruct mode)."""
    banner = ""
    head = 0
    while head < len(messages) and messages[head]["role"] in ("system", "developer"):
        head += 1
    sys_parts = [m["content"].strip() for m in messages[:head] if (m.get("content") or "").strip()]
    sys_content = "\n\n".join(sys_parts)
    out = []
    if sys_content:
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
    return "".join(out) + "<|im_start|>assistant\n<think>\n\n</think>\n\n"


def lcp(a, b):
    n = 0
    for x, y in zip(a, b):
        if x != y:
            break
        n += 1
    return n


def run(label, msgs1, msgs2):
    _nonce[0] += 1
    # stamp a unique nonce into both systems so nothing cross-matches
    for m in msgs1 + msgs2:
        if m["role"] == "system":
            m["content"] = m["content"] + " NONCE-%06d" % _nonce[0]
    r1, r2 = render_prompt(msgs1), render_prompt(msgs2)
    expected = lcp(tokenize(r1), tokenize(r2))
    rec_total = len(tokenize(r2))
    _ = chat(msgs1, 1)
    resp = chat(msgs2, 1)
    cn = resp["timings"].get("cache_n") or 0
    total = (resp["timings"].get("prompt_n") or 0) + cn
    match = "MATCH" if rec_total == total else f"DIFF({total - rec_total:+d})"
    print(f"{label:28s} expected_lcp={expected:6d}  cache_n={cn:6d}  delta={expected - cn:6d}  "
          f"rec_total={rec_total:5d} server_total={total:5d} {match}")


def history():
    return [
        {"role": "user", "content": gen_text(2000, 60)},
        {"role": "assistant", "content": gen_text(3000, 60)},
        {"role": "user", "content": gen_text(2001, 60)},
        {"role": "assistant", "content": gen_text(3001, 60)},
    ]


def system():
    return {"role": "system", "content": "You are concise."}


print("=== Phase 1: P = SYSTEM + HISTORY + X/Y (no trailing user) ===")
for size in (16, 64, 250, 1000):
    X, Y = gen_text(5000, size), gen_text(7000, size)
    run(f"X/Y={size}",
        [system()] + history() + [{"role": "user", "content": X}],
        [system()] + history() + [{"role": "user", "content": Y}])

print()
print("=== Phase 2: P = SYSTEM + HISTORY + X/Y + SAME_USER ===")
SAME = "What is the key point?"
for size in (16, 64, 250, 1000):
    X, Y = gen_text(5000, size), gen_text(7000, size)
    run(f"X/Y={size} + SAME_USER",
        [system()] + history() + [{"role": "user", "content": X}, {"role": "user", "content": SAME}],
        [system()] + history() + [{"role": "user", "content": Y}, {"role": "user", "content": SAME}])

print()
print("=== Phase 3: actual Localsy tail shape (evidence/atoms replaced, then U4) ===")
U4 = "Analyze the provided context and summarize the key insight."
for ev_size, at_size in ((1000, 250), (250, 250), (250, 1000), (1000, 1000)):
    evidence = "[S1] " + gen_text(4000, ev_size)
    atoms = "[S1] " + gen_text(5000, at_size)
    run(f"evidence({ev_size}) <-> atoms({at_size})",
        [system()] + history() + [{"role": "user", "content": evidence}, {"role": "user", "content": U4}],
        [system()] + history() + [{"role": "user", "content": atoms}, {"role": "user", "content": U4}])
