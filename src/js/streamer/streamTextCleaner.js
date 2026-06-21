/**
 * @file js/streamer/streamTextCleaner.js
 * @description Clean assistant stream HTML by removing empty paragraph artifacts and tool-call residue.
 */

const KNOWN_TOOL_NAMES = 'search_files|web_search|create_todoist_task|get_todoist_tasks|delete_todoist_task|update_todoist_task|get_email_briefing|search_memories';

const TOOL_CALL_PATTERNS = [
    // Gemma native wrapper variants: <|tool_call|>, <|tool_call>, <tool_call|>, <tool_call>
    /<\/??\|?tool_call\|?>\s*call:(?:[a-zA-Z0-9_-]+[:.])?[a-zA-Z0-9_-]+(?:\s*[\{\(][\s\S]*?[\}\)])?\s*<\/?\|?tool_call\|?>/gi,
    // Standalone call: prefix fallback with optional args in {} or ()
    new RegExp('\\bcall:(?:[a-zA-Z0-9_-]+[:.])?(' + KNOWN_TOOL_NAMES + ')(?:\\s*[\\{\\(][\\s\\S]*?[\\}\\)])?', 'gi'),
    // Namespaced call artifact with args: :namespace:tool{...} or :namespace.tool(...)
    new RegExp(':[a-zA-Z0-9_-]+(?::|\\.)(' + KNOWN_TOOL_NAMES + ')\\s*[\\{\\(][\\s\\S]*?[\\}\\)]', 'gi'),
    // Namespaced call artifact without args: :namespace:tool or :namespace.tool
    new RegExp(':[a-zA-Z0-9_-]+(?::|\\.)(' + KNOWN_TOOL_NAMES + ')', 'gi'),
    // Qwen-style XML wrapper: <tool_call>{...}</tool_call>
    /<tool_call>\s*\{[\s\S]*?\}\s*<\/tool_call>/gi,
    // Orphan wrapper tags that appear without a matched closing pair
    /<\/??\|?tool_call\|?>/gi,
];

export function stripToolCallArtifacts(text) {
    if (!text) return text;
    let cleaned = text;
    for (const pattern of TOOL_CALL_PATTERNS) {
        cleaned = cleaned.replace(pattern, '');
    }
    return cleaned.replace(/\n{3,}/g, '\n\n');
}

export function cleanAssistantStreamText(html) {
    if (!html) return '';
    let clean = html.replace(/<p>\s*<\/p>/gi, '');
    return clean;
}
