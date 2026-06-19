# Fix Tool Call Pipeline Regressions

> **For Hermes:** Use subagent-driven-development skill to implement this plan task-by-task.

**Goal:** Fix three regressions introduced by `d3eccfc` ("tool call serialization") commit: tool results never reaching user/AI, file searches triggering unnecessary web searches, and duplicate tool badges.

**Architecture:** The core problem is an architectural mismatch. The old tool architecture (tools call LLM internally and stream responses directly) conflicts with the new while-loop design (which expects tools to return pure data fed back to the next LLM iteration). We fix this by removing the silent emitter, breaking after tool execution, and fixing the search fast-path regexes.

**Tech Stack:** PHP 8.3 (ChatManager, SearchDecider, WebSearchService), JavaScript (streamResponse.js)

---

## Root Cause Analysis

### Bug 1: Tool results never reach user or AI

`ChatManager.php` line 209-213 creates `$silentEmit` which suppresses ALL `token` events from tool execution. The tools (GetTodoistTasksTool, SearchFilesTool, etc.) internally call the LLM to generate user-facing responses and emit them as `token` events. These tokens are killed. The tool returns the AI commentary as a string, but it's stored as `[TOOL_RESULT]` metadata — never streamed.

The while-loop then does a SECOND `streamAgentResponse()` call. The `markdownBuffer` in `streamResponse.js` is never reset — new tokens append to the buffer that already contains the first call's output (tool JSON, thinking tags). `extractThinking()` re-parses the combined blob, producing garbled thinking content and a chat bubble mixing tool JSON with a redundant "Is there anything you'd like to do?" follow-up from the second AI call.

**Additional damage:** 
- Line 297 uses `$cleanResponse` before it's defined on line 316 → PHP notice, token estimate always 0
- Frontend tool JSON regex (line 409) only strips `{"tool":"X"}` objects — misses `{"tool":"search_files","query":"CV"}` with extra fields

### Bug 2: File search triggers web search

Two independent fast-path regexes match "search for CV":

1. `SearchDecider.php` line 32-34: `preg_match('/^search\s+for\s+(.+)$/i', ...)` → returns `search_query = "CV"`, `intents = "none"`
2. `WebSearchService.php` line 44-50: `preg_match('/search\s+for/i', ...)` → sets `$isForced = true` → `cacheAction = 'force_live'`

Both bypass the LLM classifier. `search_query` being non-null triggers SearXNG → scrape → condense → inject into system prompt. Useless web search runs alongside the correct file search.

### Bug 3: Duplicate search_files badge

The while-loop iterates twice when tools are detected (iteration 1: tool execution, iteration 2: second AI call). Each `tool_start` event appends a purple `<uk-icon icon="code">` badge. If the LLM emits tool JSON in both iterations, two badges appear. The hash dedup only works within a single AI response, not across iterations.

This is a symptom of Bug 1 — fixing the double-iteration loop eliminates the duplicate badge.

---

## Tasks

### Task 1: Fix `$cleanResponse` ordering (trivial PHP reorder)

**Objective:** Move variable definition before its first use to eliminate PHP notice

**Files:**
- Modify: `src/App/ChatManager.php:296-316`

**Step 1: Move `$cleanResponse` definition before token calculation**

Current code (lines 296-316):
```php
$usage = $this->agent->lastUsage;
$assistantTokens = (int)(mb_strlen($cleanResponse) / 4);  // UNDEFINED

if ($usage) {
    // ... usage handling ...
}

// Strip thinking tags from final response before storing
$cleanResponse = $this->stripThinkingTags($aiResponse);   // DEFINED HERE
```

Move the `$cleanResponse` line to before `$assistantTokens`:
```php
$cleanResponse = $this->stripThinkingTags($aiResponse);
$usage = $this->agent->lastUsage;
$assistantTokens = (int)(mb_strlen($cleanResponse) / 4);

if ($usage) {
    // ... usage handling ...
}
```

**Verification:** No more PHP notice in logs. Token estimate is non-zero for tool-call responses.

---

### Task 2: Remove `$silentEmit` and break after tool execution

**Objective:** Let tool-generated responses stream normally. Stop the while-loop after tool execution — don't make a redundant second AI call.

**Files:**
- Modify: `src/App/ChatManager.php:171-294`

**Step 1: Replace `$silentEmit` with `$emit`**

Remove lines 209-213:
```php
$silentEmit = function(string $event, array $data = []) use ($emit) {
    if ($event !== 'token') {
        $emit($event, $data);
    }
};
```

Change line 263 from:
```php
$toolOutput = $this->toolExecutionService->processToolCall($singleJson, $sessionId, $currentMessages, $silentEmit);
```
To:
```php
$toolOutput = $this->toolExecutionService->processToolCall($singleJson, $sessionId, $currentMessages, $emit);
```

**Step 2: Break after tool execution — don't loop**

After the tool results are stored (after line 289 `$executionCount++`), set `$aiResponse` from the tool output and break immediately:

```php
$combinedResultText = "[TOOL_RESULT]\n" . implode("\n\n", $combinedResults);

$this->db->insert('chat_history', [
    'session_id' => $sessionId,
    'role' => 'system',
    'message' => $combinedResultText,
    'token_estimate' => (int)(mb_strlen($combinedResultText) / 4)
]);

// Tool already generated the user-facing response internally — use it as final output
$aiResponse = implode("\n\n", $combinedResults);
break;
```

Remove the message-rebuild-and-reload block (lines 284-289):
```php
$updatedHistory = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
$currentMessages = $this->promptAssemblyService->buildMessagesArray($systemPrompt, $updatedHistory, $intent);
$currentMessages = $this->cleanMessagesArray($currentMessages);
```

**Verification:** 
- Send "whats coming up on my schedule" → agenda appears in chat bubble
- Trace shows: intent → tool_start → tool_done → response streams normally
- No duplicate AI call
- No garbled combined-buffer output

---

### Task 3: Fix `search for` fast-path regexes (two files)

**Objective:** Stop "search for CV" from triggering web searches. Let the LLM classifier decide.

**Files:**
- Modify: `src/App/Agents/SearchDecider.php:32-34`
- Modify: `src/App/Services/WebSearchService.php:44-50`

**Step 1: Remove `search for` fast-path from SearchDecider**

In `SearchDecider.php`, remove lines 32-34:
```php
if (preg_match('/^search\s+for\s+(.+)$/i', $userPrompt, $matches)) {
    return ['search_query' => trim($matches[1]), 'intents' => 'none'];
}
```

The LLM classifier in `decideSearchAndIntent()` correctly distinguishes file searches from web searches. The `force search` and `force web search` regexes on lines 28-30 and 36-47 stay — those are explicit user overrides.

**Step 2: Remove `search for` force from WebSearchService**

In `WebSearchService.php`, change line 44 from:
```php
if (preg_match('/force\s+(?:the\s+)?(?:web\s+)?search/i', $query) || preg_match('/search\s+for/i', $query)) {
```
To:
```php
if (preg_match('/force\s+(?:the\s+)?(?:web\s+)?search/i', $query)) {
```

Only explicit "force search" commands should bypass the LLM classifier and force live search.

**Verification:**
- Send "search for CV" or "find my CV" → no web search triggered → `search_decided` event does NOT fire → search_files tool is used correctly
- Send "force web search latest news" → web search still triggers
- Send "force search bitcoin price" → web search still triggers

---

### Task 4: Widen frontend tool JSON strip regex

**Objective:** Strip all tool JSON objects (including those with extra fields like `query`) from the streaming buffer before markdown rendering.

**Files:**
- Modify: `src/js/streamer/streamResponse.js:409`

**Step 1: Replace the narrow regex**

Change line 409 from:
```js
parseBuffer = parseBuffer.replace(/\{\s*"tool"\s*:\s*"[^"]*"\s*\}/gi, '');
```
To:
```js
parseBuffer = parseBuffer.replace(/\{\s*"tool"\s*:\s*"[^"]*"[^}]*\}/gi, '');
```

The key change: `[^}]*\}` instead of `\s*\}` — matches any characters (including commas, other keys) between the tool value and the closing brace. This catches `{"tool":"search_files","query":"CV"}`.

**Verification:**
- During streaming, tool JSON with extra fields does not appear in the chat bubble
- The regex doesn't break normal JSON-like text in responses (the `"tool"` key is specific to Localsy's tool call format)

---

### Task 5: Test both rendering paths

**Objective:** Verify both streaming (SSE) and historic (page reload) rendering paths work correctly after fixes.

**Step 1: Test streaming path**
1. Start a new chat
2. Send "whats coming up on my schedule" → agenda streams normally
3. Send "find my CV" → file results appear, no web search
4. Check trace accordion: all steps appear in chronological order, no duplicate badges
5. Check thinking accordion: if model emits CoT, it renders without duplication or garbled text

**Step 2: Test historic path**
1. Reload the page (navigate away and back)
2. Verify previous tool-call responses render correctly from chat_history
3. Verify thinking accordions are pre-collapsed, not duplicated
4. Verify tool badges are preserved on reload

**Step 3: Test email briefing tool**
1. Send "check my emails" → email briefing streams normally
2. Verify `get_email_briefing` tool badge appears once, not twice

---

## Files Changed Summary

| File | Change |
|------|--------|
| `src/App/ChatManager.php` | Tasks 1+2: reorder `$cleanResponse`, remove `$silentEmit`, break after tool execution |
| `src/App/Agents/SearchDecider.php` | Task 3: remove `search for` fast-path regex |
| `src/App/Services/WebSearchService.php` | Task 3: remove `search for` force-live regex |
| `src/js/streamer/streamResponse.js` | Task 4: widen tool JSON strip regex |

## Risks and Tradeoffs

- **Multi-tool batch support is deferred.** The while-loop was designed for multiple tool calls in one turn (e.g., search_files + get_todoist_tasks). By breaking immediately, we lose that capability. The tradeoff: single-tool calls work correctly now. Multi-tool can be re-added later with a proper data-only tool architecture.

- **Tool commentary quality depends on internal LLM call.** Tools will continue using their internal LLM calls to generate responses. This is the existing behavior that was working before `d3eccfc`. No regression in response quality.

- **The `search for` regex was a user-facing feature.** Users who typed "search for bitcoin price" got an automatic web search. Removing this fast-path means the LLM classifier handles it — which may add ~1-2s latency. The classifier is already called for every request (via `decideSearchAndIntent`), so there's no additional LLM call — just a slightly different code path for that call's output.

- **The widened JS regex could theoretically match non-tool JSON.** If an AI response contains `{"tool": "something"}` in natural conversation, it would be stripped. This is extremely unlikely in practice and would only affect a fragment of the output.
