/**
 * @file js/markdown.js
 * @description Markdown rendering helper. Parses markdown content, highlights pre/code blocks,
 *              extracts thinking/reasoning tags into collapsible accordions, and executes copy-to-clipboard.
 */

const THINKING_FORMATS = [
    { open: '<|channel|>thought', openLen: 18, close: '<channel|>', closeLen: 10 },
    { open: '<|channel>thought', openLen: 17, close: '<channel|>', closeLen: 10 },
    { open: '<think>', openLen: 7, close: '</think>', closeLen: 8 }
];

const REF_BADGE_ICON = '<svg class="ref-badge-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>';
const REF_LABEL_MAX = 40;

function escapeHtml(text) {
    return String(text).replace(/[<>&]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]));
}

function escapeAttr(text) {
    return String(text).replace(/[<>&"]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]));
}

/**
 * The source map the reloaded page was rendered with: {7: {url, title, domain}, ...}
 * Emitted by the chat history view so a reloaded answer can badge its citations exactly as the live one did.
 */
export function refMapFromDocument() {
    const host = document.getElementById('ref-map');
    if (!host) return {};
    try {
        const map = JSON.parse(host.textContent || '{}');
        return map && typeof map === 'object' ? map : {};
    } catch {
        return {};
    }
}

/**
 * The same map built from the live stream's source payload (the `sources` SSE event): [{id: 'S7', url, ...}]
 * becomes {7: {url, title, domain}}.
 */
export function refMapFromSources(sources) {
    const map = {};
    for (const source of Array.isArray(sources) ? sources : []) {
        const number = Number(String(source && source.id || '').replace(/^S/, ''));
        if (!number) continue;
        map[number] = {
            url: String(source.url || ''),
            title: String(source.title || ''),
            domain: String(source.domain || '')
        };
    }
    return map;
}

/**
 * The refs the live stream stored on the bubble it was writing into: a just-streamed answer is not in the
 * page-load map, so binding the clipboard needs the turn's own source numbering.
 */
export function refsOnBubble(bubble) {
    if (!bubble || !bubble.dataset) return {};
    try {
        const map = JSON.parse(bubble.dataset.refs || '{}');
        return map && typeof map === 'object' ? map : {};
    } catch {
        return {};
    }
}

/** The domain as it should read: no "www.", which is noise in a chip or a link. */
function hostLabel(ref) {
    return String(ref.domain || '').replace(/^www\./, '');
}

/**
 * The page a chip names, for collapsing: one turn can register the same page under two source numbers (two
 * searches, one page), and the reader should not be told about it twice. Mirrors UrlIndex::key() — host
 * lowercased, "www." dropped, default port and fragment dropped, trailing slash trimmed — without folding
 * tracking parameters, so two different videos (same path, different query) stay two pages.
 */
function pageKey(ref) {
    const raw = String(ref.url || '').trim();
    if (raw === '') return '';
    let url;
    try { url = new URL(raw); } catch { return raw.replace(/\/+$/, ''); }
    const host = url.hostname.toLowerCase().replace(/^www\./, '');
    const defaultPort = (url.protocol === 'https:' && url.port === '443') || (url.protocol === 'http:' && url.port === '80');
    const port = url.port && !defaultPort ? ':' + url.port : '';
    const path = (url.pathname || '/').replace(/\/+$/, '');
    return host + port + path + (url.search || '');
}

/** The page's identity as a reader sees it: its site first, then its title, so two pages of one site are told
 *  apart — "hak5.org · WiFi Pineapple Enterprise" against "hak5.org · WiFi Pineapple - Hak5" — and a page with
 *  no title still says where it came from. */
function refLabel(ref) {
    const site = hostLabel(ref);
    const title = String(ref.title || '').trim();
    if (site && title) return site + ' · ' + title;
    return site || title || String(ref.url || '');
}

function refBadge(ref, number, page = '') {
    const url = String(ref.url || '');
    const title = String(ref.title || '');
    let label = refLabel(ref);
    if (label.length > REF_LABEL_MAX) label = label.slice(0, REF_LABEL_MAX - 1).trimEnd() + '…';
    const tip = (title ? title + ' — ' : '') + (url || hostLabel(ref));
    // The number comes first: it is what the bare numbers further down the answer refer to, so the tie between a
    // chip and a "3" two paragraphs later is visible, not only in the hover.
    return `<a class="ref-badge" data-ref="${number}" data-page="${page}" href="${escapeAttr(url)}" target="_blank" rel="noopener noreferrer" title="${escapeAttr(tip)}">`
        + `<span class="ref-badge-num">${number}</span>` + REF_BADGE_ICON
        + `<span class="ref-badge-label">${escapeHtml(label)}</span></a>`;
}

/** A source this answer has already named: the bare number stands in for the chip and links the same page, so a
 *  paragraph citing one page throughout does not read as a wall of identical chips. */
function refSup(ref, number) {
    const url = String(ref.url || '');
    const title = String(ref.title || '');
    const tip = (title ? title + ' — ' : '') + (url || hostLabel(ref));
    return `<a class="ref-badge-sup" data-ref="${number}" href="${escapeAttr(url)}" target="_blank" rel="noopener noreferrer" title="${escapeAttr(tip)}">${number}</a>`;
}

/** Older citation shapes have no badge and no link: they are removed wherever the answer is rendered or copied. */
function stripLegacyCitations(out) {
    out = out.replace(/\[S\d+(?:-C\d+)?(?:\s*,\s*S\d+(?:-C\d+)?)*\]/g, '');
    out = out.replace(/\(\s*S\d+\s*(?:[\/,&+]|and|or|to)\s*S\d+\s*\)/g, '');
    out = out.replace(/\bS\d+\b(?:\s*(?:\/|,|&|\+|and|or|to)\s*\bS\d+\b)+/g, '');
    return out.replace(/\bS\d+-C\d+\b/g, '');
}

/**
 * Turn the model's citation tokens into source badges.
 *
 * `refs` maps the token's number to {url, title, domain} — the number is the source's own sequence number, so
 * "#REFID_7" always means source 7 however many turns ago it was read. A token with no source behind it is
 * dropped rather than rendered as a dead chip, and the older citation shapes ([S1], "S2/S3", "(S2)") are
 * removed because nothing can render them. With `holdPartial`, a token still arriving is withheld instead of
 * flashing as text while the stream is mid-word.
 */
export function substituteRefBadges(text, refs = {}, { holdPartial = false } = {}) {
    let out = String(text);

    // A source number or chunk suffix may still be arriving. Hold the whole trailing
    // token until a delimiter or final render, before replacing any part with a badge.
    if (holdPartial) out = out.replace(/(?:[\[(]#?|#)R(?:E(?:F(?:I(?:D(?:_\d*(?:-C?\d*)?)?)?)?)?)?$|(?:[\[(]?#)$/, '');

    // Which sources this text has already named in full, and under which number each page was named. Markers
    // already in the text are counted first: the streaming path rewrites its buffer with this function's own
    // output on every frame, and re-scanning it must not hand the same source a second full chip.
    const named = new Set();
    const namedPages = new Map();
    for (const marker of out.matchAll(/data-ref="(\d+)" data-page="([^"]*)"/g)) {
        named.add(Number(marker[1]));
        if (marker[2] !== '') namedPages.set(marker[2], Number(marker[1]));
    }
    for (const marker of out.matchAll(/data-ref="(\d+)"/g)) named.add(Number(marker[1]));

    // The model is asked for "#REFID_7" but writes what it likes: "[REFID_1-C2]" in brackets, without the hash,
    // with a chunk suffix. Anything but the one shape used to survive every renderer as literal text — measured:
    // 76 of them on one chat page. The brackets and the hash are therefore part of the marker.
    out = out.replace(/[\[(]?#?REFID_(\d+)(?:-C\d+)?[)\]]?/g, (match, digits) => {
        const number = Number(digits);
        const ref = refs[number];
        if (!ref) return '';
        if (named.has(number)) return refSup(ref, number);
        const page = pageKey(ref) === '' ? '' : encodeURIComponent(pageKey(ref));
        // A second registration of a page this answer already named is a repeat, and it repeats the number the
        // page was named under — not its own, which would point at a chip that does not exist.
        const namedAs = page === '' ? 0 : (namedPages.get(page) || 0);
        if (namedAs) return refSup(ref, namedAs);
        named.add(number);
        if (page !== '') namedPages.set(page, number);
        return refBadge(ref, number, page);
    });

    out = stripLegacyCitations(out);

    return out;
}

/**
 * The same tokens as markdown links, for text that leaves the app — the clipboard, an export. References a
 * reader can follow, instead of a token nothing outside the chat window can resolve.
 */
export function refsToLinks(text, refs = {}) {
    const out = String(text).replace(/[\[(]?#?REFID_(\d+)(?:-C\d+)?[)\]]?/g, (match, digits) => {
        const ref = refs[Number(digits)];
        if (!ref || !ref.url) return '';
        // Same label as the chip, without the truncation: a link someone pastes elsewhere can carry the
        // page's full title.
        return `[${refLabel(ref).replace(/[[\]]/g, '')}](${ref.url})`;
    });

    return stripLegacyCitations(out);
}

export function extractThinking(text) {
    for (const fmt of THINKING_FORMATS) {
        const start = text.indexOf(fmt.open);
        if (start === -1) continue;
        const end = text.indexOf(fmt.close, start + fmt.openLen);
        if (end === -1) {
            return { thinking: text.substring(start + fmt.openLen), response: '', format: fmt };
        }
        return {
            thinking: text.substring(start + fmt.openLen, end),
            response: text.substring(end + fmt.closeLen),
            format: fmt
        };
    }
    return { thinking: '', response: text, format: null };
}

export function createThinkingAccordion(thinkingText, refs = {}) {
    const details = document.createElement('details');
    details.className = 'w-full mb-4 overflow-hidden group thinking-accordion rounded-xl border border-cyan-500/20 bg-gradient-to-b from-[#0d1321]/90 to-[#0d1321]/70 backdrop-blur-sm shadow-[0_0_25px_rgba(6,182,212,0.06),inset_0_1px_0_rgba(6,182,212,0.05)] transition-all duration-300';
    details.open = false;

    details.innerHTML = `
        <summary class="flex items-center justify-between px-5 py-3 cursor-pointer select-none bg-gradient-to-r from-cyan-500/5 via-cyan-500/3 to-transparent hover:from-cyan-500/10 hover:via-cyan-500/5 transition-all duration-200">
            <span class="flex items-center gap-3">
                <span class="relative flex items-center justify-center w-8 h-8 rounded-lg bg-cyan-500/10 border border-cyan-500/20 shadow-[0_0_10px_rgba(6,182,212,0.15)]">
                    <svg class="w-4 h-4 text-cyan-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9.5 2A2.5 2.5 0 0 1 12 4.5v15a2.5 2.5 0 0 1-4.96.44 2.5 2.5 0 0 1-2.96-3.08 3 3 0 0 1-.34-5.58 2.5 2.5 0 0 1 1.32-4.24 2.5 2.5 0 0 1 4.44-2.04Z"/>
                        <path d="M14.5 2A2.5 2.5 0 0 0 12 4.5v15a2.5 2.5 0 0 0 4.96.44 2.5 2.5 0 0 0 2.96-3.08 3 3 0 0 0 .34-5.58 2.5 2.5 0 0 0-1.32-4.24 2.5 2.5 0 0 0-4.44-2.04Z"/>
                    </svg>
                </span>
                <span class="text-sm font-semibold tracking-normal bg-gradient-to-r from-cyan-300 via-cyan-400 to-blue-400 bg-clip-text text-transparent">Thinking Process</span>
            </span>
            <span class="flex items-center gap-2 text-[0.65rem] text-slate-500 font-medium tracking-normal normal-case">
                <span>Complete</span>
                <svg class="w-3.5 h-3.5 transition-transform duration-300 group-open:rotate-180 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
            </span>
        </summary>
        <div class="px-5 pb-4 pt-3 border-t border-cyan-500/10 bg-[#070b14]/40">
            <div class="thinking-content text-sm text-slate-300 leading-relaxed markdown-content space-y-3"></div>
        </div>
    `;

    const content = details.querySelector('.thinking-content');
    // The reasoning plans the answer, so it carries the same citation tokens: they are replaced here exactly as
    // they are in the answer, or a token the model was thinking about shows up as text while it streams.
    const html = marked.parse(substituteRefBadges(thinkingText, refs));
    content.innerHTML = html;
    if (typeof hljs !== 'undefined') {
        content.querySelectorAll('pre code').forEach(block => hljs.highlightElement(block));
    }
    addCodeCopyButtons(content);

    return details;
}

export function parseMarkdownElements() {
    // Read once per pass: every restored answer badges its citations from the same session source map.
    const refs = refMapFromDocument();
    document.querySelectorAll('.markdown-rendered:not(.parsed)').forEach(function(el) {
        const rawMarkdown = el.getAttribute('data-markdown') || '';
        const { thinking, response } = extractThinking(rawMarkdown);

        const displayText = thinking ? response : rawMarkdown;

        if (typeof marked !== 'undefined') {
            el.innerHTML = marked.parse(substituteRefBadges(displayText, refs));
        }

        if (typeof window.parseInlineFiles === 'function') {
            el.innerHTML = window.parseInlineFiles(el.innerHTML);
        }

        el.classList.add('parsed', 'markdown-content');

        if (thinking && !el.parentNode.querySelector('.thinking-accordion')) {
            const accordion = createThinkingAccordion(thinking, refs);
            el.parentNode.insertBefore(accordion, el);
        }

        if (typeof hljs !== 'undefined') {
            el.querySelectorAll('pre code').forEach((block) => {
                hljs.highlightElement(block);
            });
        }
        addCodeCopyButtons(el);
    });
}

export function addCodeCopyButtons(container) {
    if (!container) return;
    const COPY_SVG = '<svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>';
    const CHECK_SVG = '<svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';

    container.querySelectorAll('pre').forEach((pre) => {
        if (pre.dataset.codeCopy) return;
        pre.dataset.codeCopy = '1';
        pre.style.position = 'relative';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'code-copy-btn';
        btn.title = 'Copy code';
        btn.innerHTML = COPY_SVG;
        btn.addEventListener('click', () => {
            const code = pre.querySelector('code') ? pre.querySelector('code').textContent : pre.textContent;
            navigator.clipboard.writeText(code).then(() => {
                btn.innerHTML = CHECK_SVG;
                btn.classList.add('code-copy-btn--ok');
                setTimeout(() => {
                    btn.innerHTML = COPY_SVG;
                    btn.classList.remove('code-copy-btn--ok');
                }, 1500);
            }).catch(() => {});
        });
        pre.appendChild(btn);
    });
}

export function copyToClipboard(button) {
    const container = button.closest('.chat-message-container');
    if (!container) return;

    const bubble = container.querySelector('[data-raw]');
    if (!bubble) return;

    // The stored answer keeps its #REFID_n tokens so the badges can be rebuilt; what lands on the clipboard
    // is the same answer with each token as a markdown link, which is what survives being pasted elsewhere.
    const refs = { ...refMapFromDocument(), ...refsOnBubble(bubble) };
    const textToCopy = refsToLinks(bubble.getAttribute('data-raw'), refs);

    navigator.clipboard.writeText(textToCopy).then(() => {
        const icon = button.querySelector('uk-icon');
        if (icon) {
            icon.setAttribute('icon', 'check');
            button.classList.add('text-emerald-400');
            button.classList.remove('text-slate-500', 'hover:text-cyan-400');

            setTimeout(() => {
                icon.setAttribute('icon', 'copy');
                button.classList.remove('text-emerald-400');
                button.classList.add('text-slate-500', 'hover:text-cyan-400');
            }, 1500);
        }
    }).catch(() => {});
}
