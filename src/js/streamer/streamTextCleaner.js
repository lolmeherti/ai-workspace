/**
 * @file js/streamer/streamTextCleaner.js
 * @description Clean assistant stream HTML by removing tool JSON artifacts.
 */

export function cleanAssistantStreamText(html) {
    if (!html) return '';

    let clean = html.replace(/<pre><code class="language-json">[\s\S]*?"tool"\s*:\s*"search_files"[\s\S]*?<\/code><\/pre>/gi, '');
    clean = clean.replace(/<pre><code>[\s\S]*?"tool"\s*:\s*"search_files"[\s\S]*?<\/code><\/pre>/gi, '');
    clean = clean.replace(/\{[\s\S]*?"tool"\s*:\s*"search_files"[\s\S]*?\}/gi, '');
    clean = clean.replace(/Checking files\.\.\./gi, '');
    clean = clean.replace(/<p>\s*<\/p>/gi, '');

    return clean;
}
