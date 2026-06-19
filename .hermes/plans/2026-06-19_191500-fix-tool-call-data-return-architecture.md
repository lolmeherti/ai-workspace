# Fix Tool Call Pipeline — Data-Return Architecture

> **For Hermes:** Use `subagent-driven-development` skill to implement this plan task-by-task.  
> **Self-contained:** This document assumes zero conversation context. Read it top-to-bottom.

**Goal:** Fix three regressions from `d3eccfc` ("tool call serialization"):
1. Tool results never reach the user or the main LLM
2. File searches ("find my CV") trigger unnecessary web searches
3. `search_files` tool badge appears twice in the UI

**Root Architecture Change:** Tools become data-only — they fetch, format, and return raw results. The main LLM (via the while-loop) receives the data in its context and generates the natural-language response. No tool ever calls the LLM internally.

**Tech Stack:** PHP 8.3, JavaScript (ES modules), MySQL 8.0

---

## Architecture Overview

### Before (broken)
```
AI call → tool JSON captured
  → tool executes API call
  → tool calls LLM internally (generates commentary)  
  → $silentEmit kills those tokens
  → while-loop does second AI call
  → LLM sees own commentary in context → redundant output
  → frontend buffer accumulates across iterations → garbled
```

### After (fixed)
```
AI call → tool JSON captured
  → tool executes API call, returns raw data string
  → ChatManager emits structured data_fetching SSE event (amber accordion in UI)
  → data stored in DB with message_type='data_fetching'
  → PromptAssemblyService.preprocessHistory() runs 3 passes:
      Pass 1: strips data_fetching messages, collects payloads with [SYSTEM DATA FETCHED] metadata
      Pass 2: appends all collected data to the last user message
      Pass 3: collapses consecutive assistant messages (strict template alternation)
  → while-loop does next AI call with clean alternating [user, assistant] messages
  → LLM generates natural response from fresh data in context
```

### Message Alternation (Template Safety)

Before preprocessing, the history could look like this:
```
[user, "whats coming up"]
[assistant, "Let me check..."]           ← preamble
[data_fetching]                           ← stripped in pass 1
[assistant, "Here's your agenda"]         ← final response
```

After `preprocessHistory()`:
```
[user, "whats coming up\n\n[SYSTEM DATA FETCHED]\nTool Executed: get_todoist_tasks()\n..."]
[assistant, "Let me check...\n\nHere's your agenda"]    ← collapsed, single message
```

Strict alternation preserved. No GGUF/Jinja template rejection. No Anthropic/OpenAI API errors.

### KV Cache Preservation
The data is appended to the LAST user message (index N), not inserted as a new message. The anchor prefix and all preceding messages stay byte-identical — fully cached. Only the final user message content grows.

```
Before merge:
  [0: system, anchor profile]     ← KV cached
  [1: user, "whats coming up"]    ← changed

After merge:
  [0: system, anchor profile]     ← KV cached (unchanged)
  [1: user, "whats coming up\n\n[Retrieved data:\n...]"]  ← changed (same position)
```

---

## DB Schema Change

### `message_type` column on `chat_history`

```sql
ALTER TABLE chat_history ADD COLUMN message_type VARCHAR(50) DEFAULT 'text';
```

Values:
- `'text'` — normal chat message (default for all existing rows)
- `'data_fetching'` — tool execution result accordion

Added via `Schema.php` using the existing try-catch migration pattern (see lines 106-120 for reference). This column is queried by PromptAssemblyService (to merge into user messages) and chat-window.php (to render accordions on page reload).

---

## SSE Event: `data_fetching`

New event emitted by ChatManager after tool execution completes. Carries the tool results for frontend rendering.

**Payload:**
```json
{
  "event": "data_fetching",
  "data": {
    "tool": "get_todoist_tasks",
    "status": "success",
    "label": "Checking agenda...",
    "payload": "System successfully fetched upcoming tasks from Todoist:\n- [ID: 123] ..."
  }
}
```

`status` is `"success"` for completed fetches, `"error"` for tool failures (renders red accent instead of amber).

**Frontend behavior:**
1. Creates a `data-fetching-accordion` inside the AI wrapper
2. Places it AFTER the thinking accordion, BEFORE the file-choices placeholder
3. Renders `content` as markdown
4. Uses an amber/gold color scheme (distinct from cyan=thinking, emerald=trace, purple=tool badge)
5. Auto-collapses when the first `token` event fires (same pattern as trace accordion)

**SSE event sequence for a tool-call turn:**
```
tool_start     → trace row + purple badge
data_fetching  → amber accordion with fetched data
tool_done      → trace row (completed)
token          → AI's natural language response streams
done           → finalize
```

---

## PromptAssemblyService: mergeDataFetching

Before `buildMessagesArray()` iterates the history, a preprocessing pass finds all `data_fetching` rows and appends their content to the immediately preceding user message. The `data_fetching` rows are excluded from the final messages array.

**Algorithm:**
```
For each message in history:
  If message_type == 'data_fetching':
    Find the most recent user message in the output so far
    Append: "\n\n[Retrieved data:\n{content}\n]\n\nRespond to the user's query using the above information."
    Skip this message (don't add to output)
  Else:
    Add to output as normal
```

**Why this works with multi-tool batches:**
Both `data_fetching` messages append to the same user message. The LLM sees all fetched data at once.

---

## Files Changed (15 files)

| # | File | Change |
|---|------|--------|
| 1 | `src/App/Database/Schema.php` | Add `message_type` column migration |
| 2 | `src/App/ChatManager.php` | Fix $cleanResponse ordering, emit `data_fetching` event, store with message_type, remove $silentEmit, remove tool JSON stripping |
| 3 | `src/App/Services/PromptAssemblyService.php` | Add `mergeDataFetching()` preprocessing in `buildMessagesArray()` |
| 4 | `src/App/Services/Tools/GetTodoistTasksTool.php` | Remove internal LLM call, return data only |
| 5 | `src/App/Services/Tools/SearchFilesTool.php` | Remove internal LLM call, return data only |
| 6 | `src/App/Services/Tools/CreateTodoistTaskTool.php` | Remove internal LLM call, return data only |
| 7 | `src/App/Services/Tools/DeleteTodoistTaskTool.php` | Remove token emission, return data only |
| 8 | `src/App/Services/Tools/UpdateTodoistTaskTool.php` | Remove internal LLM call, return data only |
| 9 | `src/App/Services/Tools/GetEmailBriefingTool.php` | Remove internal LLM call, return data only |
| 10 | `src/App/Agents/SearchDecider.php` | Remove `search for` fast-path regex (line 32-34) |
| 11 | `src/App/Services/WebSearchService.php` | Remove `search for` force-live regex (line 44) |
| 12 | `src/js/streamer/streamResponse.js` | Handle `data_fetching` SSE event, remove tool JSON strip regex |
| 13 | `src/js/streamer/streamTextCleaner.js` | Remove tool JSON regexes (no longer needed) |
| 14 | `src/views/chat-window.php` | Render `data_fetching` accordions, replace `[TOOL_RESULT]` skip |
| 15 | `src/css/styles.css` | Data-fetching accordion styles |

---

## Tasks

---

### Task 1: DB Schema — Add `message_type` column

**Objective:** Add nullable `message_type` column to `chat_history` with migration pattern

**Files:**
- Modify: `src/App/Database/Schema.php`

**Step 1: Add migration block**

After the existing `email_cache` migrations (line 120), add TWO migration blocks:
```php
try {
    $columns = $this->db->query("SHOW COLUMNS FROM chat_history LIKE 'message_type'");
    if (empty($columns)) {
        $this->db->executeStatement("ALTER TABLE chat_history ADD COLUMN message_type VARCHAR(50) DEFAULT 'text' AFTER message");
    }
} catch (PDOException $e) {
}

try {
    $columns = $this->db->query("SHOW COLUMNS FROM chat_history LIKE 'tool_name'");
    if (empty($columns)) {
        $this->db->executeStatement("ALTER TABLE chat_history ADD COLUMN tool_name VARCHAR(100) NULL AFTER message_type");
    }
} catch (PDOException $e) {
}
```

This follows the exact same pattern as the `is_seen` and `fetched_at` migrations. Column goes AFTER `message` to keep it logically grouped with the content it classifies.

**Verification:** Run the app. No errors on boot. `SHOW COLUMNS FROM chat_history` shows `message_type` with default `'text'`.

---

### Task 2: Fix `$cleanResponse` ordering in ChatManager

**Objective:** Move variable definition before first use to eliminate PHP notice

**Files:**
- Modify: `src/App/ChatManager.php` lines 296-316

**Step 1: Move the line**

Current (broken):
```php
$usage = $this->agent->lastUsage;
$assistantTokens = (int)(mb_strlen($cleanResponse) / 4);  // UNDEFINED at this point

if ($usage) {
    // ... usage block ...
}

$cleanResponse = $this->stripThinkingTags($aiResponse);
```

Fixed:
```php
$cleanResponse = $this->stripThinkingTags($aiResponse);
$usage = $this->agent->lastUsage;
$assistantTokens = (int)(mb_strlen($cleanResponse) / 4);

if ($usage) {
    // ... usage block ...
}
```

**Verification:** No PHP notice in error log. Token estimate is non-zero for tool-call responses.

---

### Task 3: ChatManager — Remove `$silentEmit` and tool JSON stripping

**Objective:** Let tools use `$emit` directly. Remove dead code that strips tool JSON from token stream (tools no longer emit JSON as tokens).

**Files:**
- Modify: `src/App/ChatManager.php` lines 191-270

**Step 1: Remove `$silentEmit` closure**

Delete lines 209-213:
```php
$silentEmit = function(string $event, array $data = []) use ($emit) {
    if ($event !== 'token') {
        $emit($event, $data);
    }
};
```

**Step 2: Pass `$emit` directly to processToolCall**

Change line 263 from:
```php
$toolOutput = $this->toolExecutionService->processToolCall($singleJson, $sessionId, $currentMessages, $silentEmit);
```
To:
```php
$toolOutput = $this->toolExecutionService->processToolCall($singleJson, $sessionId, $currentMessages, $emit);
```

**Step 3: Remove `$cleanMessage` JSON stripping block**

Delete lines 192-198 (the `foreach ($decodedTools as $toolParams)` loop that does `str_replace($toolJson, '', $cleanMessage)`). 

Remove line 193 (`$cleanMessage = $this->stripThinkingTags($aiRawResponse);`) — the preamble text after tool JSON will be stored as-is.

Replace the assistant message insert block (lines 200-205) with a conditional that only stores non-empty preamble:
```php
// Store any preamble the AI emitted before the tool JSON
$preambleMessage = $this->stripThinkingTags($aiRawResponse);
foreach ($decodedTools as $toolParams) {
    $toolJson = json_encode($toolParams);
    $preambleMessage = str_replace($toolJson, '', $preambleMessage);
}
$preambleMessage = trim($preambleMessage);
if (!empty($preambleMessage)) {
    $this->db->insert('chat_history', [
        'session_id' => $sessionId,
        'role' => 'assistant',
        'message' => $preambleMessage,
        'message_type' => 'text',
        'token_estimate' => (int)(mb_strlen($preambleMessage) / 4)
    ]);
}
```

**Step 4: Add `data_fetching` emission after tool execution**

After the tool execution loop (after line 270 `$combinedResults[] = $sanitized;`), add:
```php
// Emit data_fetching event for frontend accordion (structured payload)
$emit('data_fetching', [
    'tool' => $toolName,
    'status' => 'success',
    'label' => $completedPhrase,
    'payload' => $sanitized
]);
```

**Step 5: Store tool result with `message_type = 'data_fetching'`**

Change the `[TOOL_RESULT]` insert (lines 275-282) to use `message_type` and include the tool name:
```php
$combinedResultText = implode("\n\n", $combinedResults);

$this->db->insert('chat_history', [
    'session_id' => $sessionId,
    'role' => 'system',
    'message' => $combinedResultText,
    'message_type' => 'data_fetching',
    'tool_name' => $toolName,
    'token_estimate' => (int)(mb_strlen($combinedResultText) / 4)
]);
```

Remove the `[TOOL_RESULT]\n` prefix. Remove `$emit('token', ['chunk' => "\n\n"])` on line 207 and `$emit('token', ['chunk' => "\n"])` on line 273 — these were formatting hacks for the old architecture.

**Verification:** No `$silentEmit` in code. No `[TOOL_RESULT]` string in inserts. No `str_replace` of tool JSON. `data_fetching` SSE event fires after each tool execution.

---

### Task 4: PromptAssemblyService — 3-Step Preprocessing Pass

**Objective:** Before `buildMessagesArray()` iterates the history, run a single preprocessing pass that:
1. Strips `data_fetching` messages and collects their content
2. Appends collected data (with structured metadata) to the last user message
3. Collapses consecutive assistant messages to satisfy strict API template alternation

**Why step 3 matters:** If the AI emitted a preamble ("Let me check your schedule...") before the tool JSON, the history contains `[assistant, preamble]` followed by `[assistant, final response]`. Many GGUF/Jinja templates and cloud APIs (Anthropic, OpenAI) reject consecutive same-role messages. The collapse merges them into one message.

**Files:**
- Modify: `src/App/Services/PromptAssemblyService.php`

**Step 1: Add `preprocessHistory()` method**

```php
public function preprocessHistory(array $history): array
{
    $merged = [];
    $pendingData = '';

    // Pass 1: Collect and strip data_fetching messages
    foreach ($history as $msg) {
        if (($msg['message_type'] ?? 'text') === 'data_fetching') {
            $pendingData .= "\n\n[SYSTEM DATA FETCHED]\n"
                          . "Tool Executed: " . ($msg['tool_name'] ?? 'unknown_tool') . "()\n"
                          . "Status: Success\n"
                          . "Result Payload:\n"
                          . "-----------------\n"
                          . $msg['message'] . "\n"
                          . "-----------------";
            continue;
        }
        $merged[] = $msg;
    }

    // Pass 2: Append collected data to the LAST user message
    if (!empty($pendingData)) {
        for ($i = count($merged) - 1; $i >= 0; $i--) {
            if ($merged[$i]['role'] === 'user') {
                $merged[$i]['content'] .= "\n\n" . $pendingData
                    . "\n\nRespond to the user's query using the above retrieved information.";
                break;
            }
        }
    }

    // Pass 3: Collapse consecutive assistant messages (strict template alternation)
    $collapsed = [];
    foreach ($merged as $msg) {
        $lastIdx = count($collapsed) - 1;
        if ($lastIdx >= 0
            && $collapsed[$lastIdx]['role'] === 'assistant'
            && $msg['role'] === 'assistant') {
            // Merge into previous assistant message
            $collapsed[$lastIdx]['content'] .= "\n\n" . $msg['content'];
        } else {
            $collapsed[] = $msg;
        }
    }

    return $collapsed;
}
```

**Step 2: Call `preprocessHistory` at the top of `buildMessagesArray()`**

Add as the first line of `buildMessagesArray()`:
```php
$history = $this->preprocessHistory($history);
```

**Step 3: Store tool name alongside data_fetching messages**

In `ChatManager.php` (Task 3 Step 5), add `tool_name` to the DB insert so PromptAssemblyService has it for structured metadata:
```php
$this->db->insert('chat_history', [
    'session_id' => $sessionId,
    'role' => 'system',
    'message' => $combinedResultText,
    'message_type' => 'data_fetching',
    'token_estimate' => (int)(mb_strlen($combinedResultText) / 4)
]);
```

**Verification:** 
- Dump `$messages` array before LLM call:
  - No `data_fetching` rows present
  - No consecutive same-role messages
  - Last user message contains structured `[SYSTEM DATA FETCHED]` block with tool name and payload
- Template errors don't fire on Qwen GGUF (strictest template), DeepSeek, or Gemma models

---

### Task 5: Refactor tools — Remove internal LLM calls (6 tools)

**Objective:** Each tool returns raw data string. No `$this->agent->chat()` calls. No `$emit('token', ...)` calls. The `$cleanJson` return value pattern becomes `return $instructions;`.

**Pattern to remove (present in GetTodoistTasksTool, SearchFilesTool, CreateTodoistTaskTool, UpdateTodoistTaskTool, GetEmailBriefingTool):**
```php
$messages[] = ['role' => 'assistant', 'content' => $cleanJson];
$messages[] = ['role' => 'system', 'content' => $instructions];

$aiCommentary = '';
$commentaryBuffer = '';
$this->agent->chat($messages, true, function($chunk) use ($emit, &$aiCommentary, &$commentaryBuffer) {
    $aiCommentary .= $chunk;
    $commentaryBuffer .= $chunk;
    if (mb_check_encoding($commentaryBuffer, 'UTF-8')) {
        $emit('token', ['chunk' => $commentaryBuffer]);
        $commentaryBuffer = '';
    }
});
if (!empty($commentaryBuffer)) {
    $emit('token', ['chunk' => mb_convert_encoding($commentaryBuffer, 'UTF-8', 'UTF-8')]);
}

return $cleanJson . "\n\n" . $aiCommentary;
```

**Replace with:**
```php
return $instructions;
```

**File-by-file changes:**

#### Task 5a: `src/App/Services/Tools/GetTodoistTasksTool.php`
- Remove lines 80-103 (message building, LLM call, return)
- Replace with `return $instructions;`
- Remove `$emit('token', ['chunk' => "\n\nRetrieving tasks from Todoist..."]);` on line 22

#### Task 5b: `src/App/Services/Tools/SearchFilesTool.php`
- Remove lines 124-167 (message building, LLM call, return)
- Replace with `return $instructions;`
- Remove `$emit('token', ['chunk' => "\n\nChecking files..."]);` on line 22

#### Task 5c: `src/App/Services/Tools/CreateTodoistTaskTool.php`
- Remove lines 64-87 (message building, LLM call, return)
- Replace with `return $instructions;`
- Remove `$emit('token', ['chunk' => "\n\nAnalyzing calendar schedule..."]);` on line 21
- For the scheduling conflict path (lines 36-44): change to return the analysis as instructions. Replace lines 37-41 with:
```php
$instructions = "System scheduling analysis:\n";
$instructions .= "Status: " . $analysis['status'] . "\n";
$instructions .= $analysis['analysis'] . "\n\n";
$instructions .= "[SYSTEM NOTE]: The scheduling agent detected a conflict. Present this analysis to the user and ask how they'd like to proceed (reschedule, skip, or create anyway).";
return $instructions;
```
Remove the `$emit('token', ['chunk' => $aiCommentary]);` and return on line 41-43.

#### Task 5d: `src/App/Services/Tools/DeleteTodoistTaskTool.php`
- Already has no internal LLM call
- Remove `$emit('token', ['chunk' => "\n\nSearching for matching tasks to delete..."]);` on line 21
- Remove `$emit('token', ['chunk' => $replyText]);` on line 59
- Change `return $cleanJson . $replyText;` on line 61 to `return $replyText;`

#### Task 5e: `src/App/Services/Tools/UpdateTodoistTaskTool.php`
- Remove lines 82-106 (message building, LLM call, return)
- Replace with `return $instructions;`
- Remove `$emit('token', ['chunk' => "\n\nSearching for the task to edit..."]);` on line 21
- Remove `$emit('token', ['chunk' => "\n\nUpdating task details..."]);` on line 58

#### Task 5f: `src/App/Services/Tools/GetEmailBriefingTool.php`
- Remove lines 101-125 (message building, LLM call, return)
- Replace with `return $instructions;`
- Remove `$emit('token', ['chunk' => "\n\nSyncing inboxes..."]);` on line 22
- Remove `$emit('token', ['chunk' => "\n\nExtracting scheduled appointments and resolving conflicts..."]);` on line 30

**Verification for ALL tools:**
- Grep for `$this->agent->chat(` in `src/App/Services/Tools/` — zero results
- Grep for `$emit('token'` in `src/App/Services/Tools/` — zero results (except maybe error handlers)
- Each tool's `execute()` method ends with `return $instructions;` or `return $replyText;`

---

### Task 6: Fix search fast-path regexes (2 files)

**Objective:** "search for CV" or "find my CV" should NOT trigger web search. Only explicit "force search" commands should bypass the LLM classifier.

#### Task 6a: `src/App/Agents/SearchDecider.php`

Remove lines 32-34:
```php
if (preg_match('/^search\s+for\s+(.+)$/i', $userPrompt, $matches)) {
    return ['search_query' => trim($matches[1]), 'intents' => 'none'];
}
```

The LLM classifier (`decideSearchAndIntent`) already distinguishes file searches from web searches correctly. Keep the other fast-paths (lines 28-30 for `force search X`, lines 36-47 for `force search` with last-query fallback).

#### Task 6b: `src/App/Services/WebSearchService.php`

Change line 44 from:
```php
if (preg_match('/force\s+(?:the\s+)?(?:web\s+)?search/i', $query) || preg_match('/search\s+for/i', $query)) {
```
To:
```php
if (preg_match('/force\s+(?:the\s+)?(?:web\s+)?search/i', $query)) {
```

**Verification:**
- "search for CV" → `$searchQuery` is null → no web search → `search_files` tool used
- "force search bitcoin price" → web search triggers
- "force web search latest news" → web search triggers

---

### Task 7: Frontend — Handle `data_fetching` SSE event

**Objective:** Render amber accordion for fetched data. Remove fragile tool JSON stripping regex.

**Files:**
- Modify: `src/js/streamer/streamResponse.js`
- Modify: `src/js/streamer/streamTextCleaner.js`

#### Task 7a: Add `data_fetching` event handler in streamResponse.js

Add a new event handler block (alongside existing `tool_start`, `tool_done`, etc. handlers):

```js
if (event === 'data_fetching') {
    // Create data-fetching accordion
    const accordion = document.createElement('details');
    accordion.className = 'w-full mb-4 overflow-hidden group data-fetching-accordion rounded-xl border border-amber-500/20 bg-gradient-to-b from-[#0d1321]/90 to-[#0d1321]/70 backdrop-blur-sm shadow-[0_0_25px_rgba(245,158,11,0.05),inset_0_1px_0_rgba(245,158,11,0.04)] transition-all duration-300';
    accordion.open = true;
    accordion.innerHTML = `
        <summary class="flex items-center justify-between px-5 py-3 cursor-pointer select-none bg-gradient-to-r from-amber-500/5 via-amber-500/3 to-transparent hover:from-amber-500/10 hover:via-amber-500/5 transition-all duration-200">
            <span class="flex items-center gap-3">
                <span class="relative flex items-center justify-center w-8 h-8 rounded-lg bg-amber-500/10 border border-amber-500/20 shadow-[0_0_10px_rgba(245,158,11,0.12)]">
                    <svg class="w-4 h-4 text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <ellipse cx="12" cy="5" rx="9" ry="3"/>
                        <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/>
                        <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
                    </svg>
                </span>
                <span class="text-sm font-semibold tracking-wide bg-gradient-to-r from-amber-300 via-amber-400 to-yellow-400 bg-clip-text text-transparent">Data Fetching</span>
                <span class="flex h-2 w-2 relative data-fetch-pulse-dot">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-400 shadow-[0_0_6px_rgba(245,158,11,0.8)]"></span>
                </span>
            </span>
            <span class="flex items-center gap-2 text-[0.65rem] text-slate-500 font-medium tracking-wide uppercase">
                <span class="data-fetch-status">Fetching</span>
                <svg class="w-3.5 h-3.5 transition-transform duration-300 group-open:rotate-180 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
            </span>
        </summary>
        <div class="px-5 pb-4 pt-3 border-t border-amber-500/10 bg-[#070b14]/40">
            <div class="data-fetch-content text-sm text-slate-300 leading-relaxed markdown-content space-y-3"></div>
        </div>
    `;

    // Insert after thinking accordion, before file-choices
    const thinkingEl = aiWrapper.querySelector('.thinking-accordion');
    if (thinkingEl && thinkingEl.nextSibling) {
        thinkingEl.parentNode.insertBefore(accordion, thinkingEl.nextSibling);
    } else {
        const placeholder = aiWrapper.querySelector('.file-choices-placeholder-container');
        if (placeholder) {
            placeholder.parentNode.insertBefore(accordion, placeholder);
        }
    }

    // Render content (check status for error styling)
    const contentDiv = accordion.querySelector('.data-fetch-content');
    contentDiv.innerHTML = marked.parse(data.payload || data.content);
    contentDiv.querySelectorAll('pre code').forEach(block => hljs.highlightElement(block));

    // If error status, swap amber to red
    if (data.status === 'error') {
        accordion.className = accordion.className.replace(/amber/g, 'red').replace(/yellow/g, 'rose');
        const statusLabel = accordion.querySelector('.data-fetch-status');
        if (statusLabel) statusLabel.textContent = 'Error';
    } else {
        const statusLabel = accordion.querySelector('.data-fetch-status');
        if (statusLabel) statusLabel.textContent = data.label || 'Complete';
    }
    const pulseDot = accordion.querySelector('.data-fetch-pulse-dot');
    if (pulseDot) pulseDot.classList.add('hidden');
}
```

Auto-collapse on first token: in the existing `if (event === 'token' && isFirstToken)` block, add:
```js
// Collapse data-fetching accordions
aiWrapper.querySelectorAll('.data-fetching-accordion').forEach(el => el.open = false);
```

#### Task 7b: Remove tool JSON strip regex in streamResponse.js

Delete line 409:
```js
parseBuffer = parseBuffer.replace(/\{\s*"tool"\s*:\s*"[^"]*"\s*\}/gi, '');
```

This is no longer needed — tools don't emit tool JSON as tokens anymore.

#### Task 7c: Simplify streamTextCleaner.js

The `cleanAssistantStreamText()` function searches for HTML patterns like `<pre><code class="language-json">` wrapping tool JSON. Since tools no longer emit JSON in the token stream, these regexes should never match. Remove the tool-specific regexes (lines 9-18) and keep only the empty paragraph cleanup (line 19):
```js
export function cleanAssistantStreamText(html) {
    if (!html) return '';
    let clean = html.replace(/<p>\s*<\/p>/gi, '');
    return clean;
}
```

**Verification:**
- Send "whats coming up on my schedule" → amber accordion appears with task data
- Accordion collapses when AI response starts streaming
- No raw JSON appears in chat bubble
- `streamTextCleaner.js` still compiles without errors

---

### Task 8: Server-side rendering — chat-window.php

**Objective:** Render `data_fetching` accordions on page reload. Replace `[TOOL_RESULT]` skip with `message_type` check.

**Files:**
- Modify: `src/views/chat-window.php`

**Step 1: Replace the skip condition**

Change line 63 from:
```php
<?php if (($msg['role'] ?? '') === 'system' && str_starts_with($msg['message'] ?? '', '[TOOL_RESULT]')) continue; ?>
```
To:
```php
<?php 
$msgType = $msg['message_type'] ?? 'text';
if ($msgType === 'data_fetching') {
    // Render accordion instead of chat bubble
    ?>
    <details class="w-full max-w-[92%] mx-auto mb-4 overflow-hidden group data-fetching-accordion rounded-xl border border-amber-500/20 bg-gradient-to-b from-[#0d1321]/90 to-[#0d1321]/70 backdrop-blur-sm shadow-[0_0_25px_rgba(245,158,11,0.05),inset_0_1px_0_rgba(245,158,11,0.04)] transition-all duration-300">
        <summary class="flex items-center justify-between px-5 py-3 cursor-pointer select-none bg-gradient-to-r from-amber-500/5 via-amber-500/3 to-transparent hover:from-amber-500/10 hover:via-amber-500/5 transition-all duration-200">
            <span class="flex items-center gap-3">
                <span class="relative flex items-center justify-center w-8 h-8 rounded-lg bg-amber-500/10 border border-amber-500/20 shadow-[0_0_10px_rgba(245,158,11,0.12)]">
                    <svg class="w-4 h-4 text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <ellipse cx="12" cy="5" rx="9" ry="3"/>
                        <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/>
                        <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
                    </svg>
                </span>
                <span class="text-sm font-semibold tracking-wide bg-gradient-to-r from-amber-300 via-amber-400 to-yellow-400 bg-clip-text text-transparent">Data Fetching</span>
            </span>
            <span class="flex items-center gap-2 text-[0.65rem] text-slate-500 font-medium tracking-wide uppercase">
                <span>Complete</span>
                <svg class="w-3.5 h-3.5 transition-transform duration-300 group-open:rotate-180 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
            </span>
        </summary>
        <div class="px-5 pb-4 pt-3 border-t border-amber-500/10 bg-[#070b14]/40">
            <div class="data-fetch-content text-sm text-slate-300 leading-relaxed markdown-content space-y-3"><?php echo htmlspecialchars($msg['message']); ?></div>
        </div>
    </details>
    <?php
    continue;
}
if (($msg['role'] ?? '') === 'system') continue; // Skip other system messages (condensation summaries, etc.)
?>
```

**Step 2: Add markdown rendering for historic data_fetching content**

The `markdown-content` class is already processed by `markdown.js::parseMarkdownElements()` on page load. No additional JS needed — the same pipeline that renders thinking accordions handles data_fetching accordions.

**Verification:**
- Start a chat, send "whats coming up on my schedule"
- Reload the page
- Amber accordion renders with task data
- Content is markdown-formatted (not raw text)
- Accordion is pre-collapsed (historic content, no `open` attribute)

---

### Task 9: CSS — Data-fetching accordion styles

**Objective:** Add any missing styles for the accordion that aren't already covered by Tailwind utility classes.

**Files:**
- Modify: `src/css/styles.css`

**Step 1: Add accordion transition and markdown content styles**

Append to styles.css:
```css
/* Data Fetching Accordion */
.data-fetching-accordion[open] > summary svg:last-child {
    transform: rotate(180deg);
}
.data-fetch-content.markdown-content p {
    margin-bottom: 0.75rem;
}
.data-fetch-content.markdown-content pre {
    background: rgba(15, 23, 42, 0.6);
    border: 1px solid rgba(245, 158, 11, 0.15);
    border-radius: 0.5rem;
    padding: 0.75rem;
    overflow-x: auto;
}
.data-fetch-content.markdown-content code {
    font-size: 0.75rem;
    color: #e2e8f0;
}
```

**Verification:** Check the accordion renders with correct colors, transitions, and code block styling in both streaming and historic views.

---

### Task 10: Integration test — Full pipeline

**Objective:** Verify the complete flow works end-to-end for all tool types

**Test cases:**

**A. Todoist get (streaming + historic)**
1. Send "whats coming up on my schedule"
2. Trace shows: intent_result → context_assembled → tool_start "Checking agenda..." → data_fetching accordion → tool_done "Agenda loaded."
3. AI responds with actual agenda items
4. Reload page → accordion renders from DB, content preserved
5. No duplicate badges, no raw JSON in chat

**B. File search**
1. Send "find my CV"
2. Trace shows: intent = search_files, no web search triggered
3. `search_decided` event does NOT fire
4. data_fetching accordion shows file results
5. AI responds with file references
6. Verify no SearXNG request was made (check Docker logs)

**C. Force web search still works**
1. Send "force search bitcoin price"
2. Web search triggers normally
3. `search_decided` event fires
4. Scraping traces appear

**D. Email briefing**
1. Send "check my emails"
2. Data fetching accordion shows email data
3. AI responds with briefing
4. No duplicate thinking accordions

**E. Create todoist task**
1. Send "schedule dentist tomorrow at 3pm"
2. Data fetching accordion shows created task
3. AI confirms the task was created

---

## Dead Code Removed (summary)

| What | Where | Why |
|------|-------|-----|
| `$silentEmit` closure | ChatManager.php:209-213 | Tools no longer emit tokens |
| `str_replace($toolJson, ...)` loop | ChatManager.php:194-197 | Tool JSON never in token stream |
| `[TOOL_RESULT]\n` prefix | ChatManager.php:275 | Replaced by message_type column |
| `$emit('token', ['chunk' => "\n\n"])` formatting | ChatManager.php:207,273 | Old hack for tool output spacing |
| `$this->agent->chat(...)` in all tools | 6 tool files | Main LLM handles response generation |
| `$emit('token', ...)` in all tools | 6 tool files | ChatManager handles all SSE emission |
| `/\{\s*"tool"[\s\S]*?\}/gi` regex | streamResponse.js:409 | No tool JSON in token stream |
| `toolNames` regex array | streamTextCleaner.js:9-18 | No tool JSON in HTML output |
| `search\s+for` fast-path | SearchDecider.php:32-34 | Let LLM classifier decide |
| `search\s+for` force-live | WebSearchService.php:44 | Let LLM classifier decide |

---

## Risks and Tradeoffs

- **Multi-tool batches:** The while-loop stays intact. If the LLM emits two tool JSON objects in one response, both execute, both get `data_fetching` accordions, and the next AI call sees both data blocks merged into the user message. This is fully supported.

- **CreateTodoistTaskTool conflict detection:** The scheduling conflict path (SchedulingAgent analysis) now returns the conflict as raw instructions instead of immediately asking the user. The main LLM receives the conflict data and presents it naturally. This may produce slightly different phrasing but the information is the same.

- **SearchFilesTool image attachments:** The tool currently base64-encodes images and adds them as vision content in the LLM call. This is a special case — images can't be passed as text to the main LLM via the data_fetching merge. The tool should continue to handle image attachments, but return text-only instructions for the merge path. The image handling stays in the tool for now (the tool still builds a message with the image, but the ChatManager would need to handle this — defer to a follow-up task).

- **Email briefing suggestions tags:** `[TodoistSuggest: content | due_string]` tags are currently emitted as tokens. With the new architecture, these tags become part of the instructions string and are fed to the main LLM via the merge. The system prompt already instructs the LLM to "append these exact tags to the very end of your final response." This path is preserved.
