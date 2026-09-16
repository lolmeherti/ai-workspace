import { JSDOM } from 'jsdom';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
export const root = fileURLToPath(new URL('../../', import.meta.url));
export function fixture(query = 'session_id=3&tab=chats') {
    return execFileSync(process.env.LOCALSY_PHP || 'php', ['tests/ui/preview.php', query], { cwd: root, encoding: 'utf8', maxBuffer: 4000000 });
}
export function mount(html = '') {
    const dom = new JSDOM(html, { url: 'http://localsy.test/index.php?session_id=3&tab=chats' });
    for (const key of ['window', 'document', 'history', 'location', 'localStorage', 'sessionStorage', 'Event', 'CustomEvent', 'MouseEvent', 'KeyboardEvent', 'HTMLElement', 'MutationObserver', 'FileReader', 'DOMParser', 'Option']) globalThis[key] = key === 'window' ? dom.window : dom.window[key];
    globalThis.matchMedia = () => ({ matches: false });
    globalThis.requestAnimationFrame = callback => setTimeout(() => callback(performance.now()), 1);
    globalThis.cancelAnimationFrame = clearTimeout;
    dom.window.HTMLElement.prototype.scrollIntoView = function() {};
    dom.window.HTMLDialogElement.prototype.showModal = function() { this.open = true; };
    dom.window.HTMLDialogElement.prototype.close = function() { this.open = false; };
    globalThis.marked = { parse: text => String(text).replaceAll('<', '&lt;') };
    globalThis.hljs = { highlightElement() {} };
    window.selectedFileReferences = []; window.updateFileReferencesUI = () => {};
    window.activeToggledBlocks = new Set(); window.activeBlocks = [];
    window.REPLY_DOWNVOTE_REASONS = { incorrect: 'Incorrect', tool_failed: 'Tool failed' };
    window.REPLY_TOOL_TURN_REASONS = ['tool_failed'];
    return dom;
}
export function json(data, status = 200) { return new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } }); }
export const settle = () => new Promise(resolve => setTimeout(resolve, 5));
export function deferred() { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; }
