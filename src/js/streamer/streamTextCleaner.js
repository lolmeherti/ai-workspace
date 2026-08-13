/**
 * @file js/streamer/streamTextCleaner.js
 * @description Clean assistant stream HTML by removing empty paragraph artifacts.
 */

export function cleanAssistantStreamText(html) {
    if (!html) return '';
    return html.replace(/<p>\s*<\/p>/gi, '');
}
