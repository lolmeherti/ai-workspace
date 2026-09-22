#!/usr/bin/env python3
"""Verify: does tool_choice ('auto' vs 'none') change the rendered prompt prefix?

Sends the SAME messages+tools three ways and measures cache_n:
  A: tools + tool_choice=auto
  B: tools + tool_choice=none   (should cache-hit A if serialization identical)
  C: no tools                    (current answer-pass shape; should cache-miss = 0)
"""
import json, os, time, urllib.request

BASE = "http://127.0.0.1:1234"
MODEL = "Qwen3.8 27B UD_Q5_K_XL (unsloth)"

def post(path, obj):
    req = urllib.request.Request(BASE + path, data=json.dumps(obj).encode(),
                                 headers={"Content-Type": "application/json"}, method="POST")
    return json.loads(urllib.request.urlopen(req, timeout=300).read())

TOOLS = [
    {"type": "function", "function": {"name": "search_web",
     "description": "Search the web.",
     "parameters": {"type": "object", "properties": {"query": {"type": "string"}}, "required": ["query"]}}},
    {"type": "function", "function": {"name": "get_todoist_tasks",
     "description": "List tasks.",
     "parameters": {"type": "object", "properties": {}, "required": []}}},
]

MSGS = [
    {"role": "system", "content": "You are a helpful assistant. " + "NONCE-%x" % time.time_ns()},
    {"role": "user", "content": "hello"},
    {"role": "assistant", "content": "Hi, how can I help?"},
    {"role": "user", "content": "what is the weather?"},
]

def chat(msgs, tools=None, tc=None, max_tokens=1):
    p = {"model": MODEL, "messages": msgs, "stream": False, "max_tokens": max_tokens,
         "chat_template_kwargs": {"reasoning_effort": "none"}}
    if tools is not None:
        p["tools"] = tools
    if tc is not None:
        p["tool_choice"] = tc
    return post("/v1/chat/completions", p)

def cache(msgs, tools=None, tc=None):
    r = chat(msgs, tools, tc)
    t = r["timings"]
    return (t.get("cache_n") or 0), (t.get("prompt_n") or 0), (t.get("prompt_ms") or 0)

cA, nA, pA = cache(MSGS, TOOLS, "auto")
cB, nB, pB = cache(MSGS, TOOLS, "none")
cC, nC, pC = cache(MSGS, None, None)

print(f"A  tools+auto : cache_n={cA}  prompt_n={nA}  total={cA+nA}  prefill={pA}ms")
print(f"B  tools+none : cache_n={cB}  prompt_n={nB}  total={cB+nB}  prefill={pB}ms   (vs A: {'IDENTICAL' if cB == cA+nA else 'DIFFERS'})")
print(f"C  no tools   : cache_n={cC}  prompt_n={nC}  total={cC+nC}  prefill={pC}ms   (vs A: {'IDENTICAL' if cC == cA+nA else 'DIFFERS'})")
