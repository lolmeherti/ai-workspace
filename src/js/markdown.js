/**
 * @file js/markdown.js
 * @description Markdown rendering helper. Parses markdown content, highlights pre/code blocks,
 *              extracts thinking/reasoning tags into collapsible accordions, and executes copy-to-clipboard.
 */

const THINKING_FORMATS = [
    { open: '<|channel>thought', openLen: 17, close: '<channel|>', closeLen: 10 },
    { open: '<think>', openLen: 7, close: '</think>', closeLen: 8 }
];

export function extractThinking(text) {
    let bestThinking = '';
    let bestResponse = text;
    let bestFormat = null;

    for (const fmt of THINKING_FORMATS) {
        const chunks = [];
        let response = '';
        let lastEnd = 0;
        let pos = 0;
        let found = false;

        while (true) {
            const start = text.indexOf(fmt.open, pos);
            if (start === -1) break;
            found = true;

            response += text.substring(lastEnd, start);
            const contentStart = start + fmt.openLen;
            const end = text.indexOf(fmt.close, contentStart);

            if (end === -1) {
                chunks.push(text.substring(contentStart));
                lastEnd = text.length;
                break;
            }

            chunks.push(text.substring(contentStart, end));
            pos = end + fmt.closeLen;
            lastEnd = pos;
        }

        if (!found) continue;

        response += text.substring(lastEnd);
        const thinking = chunks.join('\n\n');

        if (thinking.length > bestThinking.length) {
            bestThinking = thinking;
            bestResponse = response;
            bestFormat = fmt;
        }
    }

    return { thinking: bestThinking, response: bestResponse, format: bestFormat };
}

export function createThinkingAccordion(thinkingText) {
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
                <span class="text-sm font-semibold tracking-wide bg-gradient-to-r from-cyan-300 via-cyan-400 to-blue-400 bg-clip-text text-transparent">Thinking Process</span>
            </span>
            <span class="flex items-center gap-2 text-[0.65rem] text-slate-500 font-medium tracking-wide uppercase">
                <span>Complete</span>
                <svg class="w-3.5 h-3.5 transition-transform duration-300 group-open:rotate-180 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
            </span>
        </summary>
        <div class="px-5 pb-4 pt-3 border-t border-cyan-500/10 bg-[#070b14]/40">
            <div class="thinking-content text-sm text-slate-300 leading-relaxed markdown-content space-y-3"></div>
        </div>
    `;

    const content = details.querySelector('.thinking-content');
    const html = marked.parse(thinkingText);
    content.innerHTML = html;
    if (typeof hljs !== 'undefined') {
        content.querySelectorAll('pre code').forEach(block => hljs.highlightElement(block));
    }

    return details;
}

export function parseMarkdownElements() {
    document.querySelectorAll('.markdown-rendered:not(.parsed)').forEach(function(el) {
        const rawMarkdown = el.getAttribute('data-markdown') || '';
        const { thinking, response } = extractThinking(rawMarkdown);

        const displayText = thinking ? response : rawMarkdown;

        if (typeof marked !== 'undefined') {
            el.innerHTML = marked.parse(displayText);
        }

        if (typeof window.parseInlineFiles === 'function') {
            el.innerHTML = window.parseInlineFiles(el.innerHTML);
        }

        el.classList.add('parsed', 'markdown-content');

        if (thinking && !el.parentNode.querySelector('.thinking-accordion')) {
            const accordion = createThinkingAccordion(thinking);
            el.parentNode.insertBefore(accordion, el);
        }

        if (typeof hljs !== 'undefined') {
            el.querySelectorAll('pre code').forEach((block) => {
                hljs.highlightElement(block);
            });
        }
    });
}

export function copyToClipboard(button) {
    const container = button.closest('.chat-message-container');
    if (!container) return;

    const bubble = container.querySelector('[data-raw]');
    if (!bubble) return;

    const textToCopy = bubble.getAttribute('data-raw');

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
