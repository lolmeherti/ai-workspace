/**
 * @file js/streamer/streamTextCleaner.js
 * @description Clean assistant stream HTML by removing empty paragraph artifacts.
 */

export function cleanAssistantStreamText(html) {
    if (!html) return '';
    let clean = html.replace(/<p>\s*<\/p>/gi, '');
    return clean;
}
