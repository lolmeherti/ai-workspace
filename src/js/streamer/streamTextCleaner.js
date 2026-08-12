/**
 * @file js/streamer/streamTextCleaner.js
 * @description Clean assistant stream HTML by removing empty paragraph artifacts.
 */

export function cleanAssistantStreamText(html) {
    if (!html) return '';
    let clean = html.replace(/<p>\s*<\/p>/gi, '');
    // Strip internal source citations [S1], [S1,S2], [S1, S2], [S1][S2] etc.
    clean = clean.replace(/\[S\d+(?:\s*,\s*S\d+)*\]/g, '');
    return clean;
}
