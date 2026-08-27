/**
 * @file js/streamer/streamResponse.js
 * @description SSE chat response streaming handler with automatic HTML tag-level thought parsing.
 */

import { state } from '../state.js';
import { showCondensationModal, updateTokenCounter, lockChatContext } from '../ui.js';
import { cleanAssistantStreamText } from './streamTextCleaner.js';
import { renderFileChoices } from './streamFileChoices.js';
import { extractThinking } from '../markdown.js';
import { addContextItem, refreshContextItem } from '../chat/chatContextData.js';

function escapeRegex(s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function stripCitations(text, sourceIds) {
    let out = text;
    if (sourceIds.length) {
        const sorted = [...sourceIds].sort((a, b) => b.length - a.length);
        const alt = sorted.map(escapeRegex).join('|');
        const re = new RegExp(`\\[(?:${alt})(?:-C\\d+)?(?:\\s*,\\s*(?:${alt})(?:-C\\d+)?)*\\]`, 'g');
        out = out.replace(re, '');
    }
    // Fallback: strip any residual citation-shaped token (hallucinated or stale IDs).
    return out.replace(/\[S\d+(?:-C\d+)?(?:\s*,\s*S\d+(?:-C\d+)?)*\]/g, '');
}

const SOURCE_GLOBE_ICON = '<svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>';
const SOURCE_EXT_ICON = '<svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';

function renderSourcesList(bubble, sources) {
    const panel = document.createElement('div');
    panel.className = 'sources-panel relative w-full mt-4 overflow-hidden rounded-xl border border-cyan-500/20 bg-gradient-to-b from-[#0d1321]/90 to-[#0d1321]/70 backdrop-blur-sm shadow-[0_0_25px_rgba(6,182,212,0.08),inset_0_1px_0_rgba(6,182,212,0.06)]';

    const accent = document.createElement('span');
    accent.className = 'absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-cyan-400/60 to-transparent';
    panel.appendChild(accent);

    const header = document.createElement('div');
    header.className = 'flex items-center gap-2 px-4 pt-3 pb-2';
    header.innerHTML = `
        <span class="relative flex items-center justify-center w-6 h-6 rounded-md bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 shadow-[0_0_10px_rgba(6,182,212,0.12)]">${SOURCE_GLOBE_ICON}</span>
        <span class="text-[10px] font-semibold tracking-wider uppercase bg-gradient-to-r from-cyan-300 via-blue-400 to-emerald-400 bg-clip-text text-transparent">Sources</span>
        <span class="relative flex h-1.5 w-1.5">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-cyan-400 opacity-75"></span>
            <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-cyan-400"></span>
        </span>
        <span class="ml-auto text-[10px] px-1.5 py-0.5 rounded-full bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 font-mono">${sources.length}</span>
    `;
    panel.appendChild(header);

    const list = document.createElement('div');
    list.className = 'px-3 pb-3 flex flex-col gap-1.5';

    for (const s of sources) {
        const a = document.createElement('a');
        a.href = s.url || '';
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.className = 'group relative flex items-center gap-3 px-3 py-2 rounded-lg border border-slate-700/40 bg-slate-900/30 hover:border-cyan-500/30 hover:bg-cyan-500/5 hover:shadow-[0_0_16px_rgba(6,182,212,0.10)] transition-all duration-200';

        const iconBox = document.createElement('span');
        iconBox.className = 'flex items-center justify-center w-7 h-7 shrink-0 rounded-md bg-slate-800/60 border border-slate-700/50 text-cyan-400 group-hover:border-cyan-500/40 group-hover:text-cyan-300 group-hover:shadow-[0_0_12px_rgba(6,182,212,0.25)] transition-all';
        iconBox.innerHTML = SOURCE_GLOBE_ICON;
        a.appendChild(iconBox);

        const textWrap = document.createElement('span');
        textWrap.className = 'flex flex-col min-w-0 flex-1';

        const titleText = s.title || s.domain || s.url || '';
        const title = document.createElement('span');
        title.className = 'text-xs text-slate-300 truncate group-hover:text-cyan-200 transition-colors';
        title.textContent = titleText;
        textWrap.appendChild(title);

        if (s.domain && s.domain !== titleText) {
            const domain = document.createElement('span');
            domain.className = 'text-[10px] text-slate-500 truncate font-mono group-hover:text-slate-400 transition-colors';
            domain.textContent = s.domain;
            textWrap.appendChild(domain);
        }

        a.appendChild(textWrap);

        const ext = document.createElement('span');
        ext.className = 'shrink-0 text-slate-600 group-hover:text-cyan-400 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 transition-all';
        ext.innerHTML = SOURCE_EXT_ICON;
        a.appendChild(ext);

        list.appendChild(a);
    }

    panel.appendChild(list);
    bubble.appendChild(panel);
}

const PURPOSE_LABELS = {
    firstpass: 'first pass',
    answer: 'answer',
    condenser: 'condenser',
    tools: 'tools',
};

function fmtMs(ms) {
    return Math.round(ms) + 'ms';
}

function fmtS(ms) {
    return (ms / 1000).toFixed(1) + 's';
}

function pickAnswerCall(calls) {
    if (!calls || !calls.length) return null;
    return calls.find(c => c.purpose === 'answer') || calls.find(c => c.purpose === 'firstpass') || calls[calls.length - 1];
}

/**
 * Render a per-turn "metrics" section at the bottom of an assistant bubble.
 * Compact summary line (calls / total / TTFT / reasoning / tok/s / cache%)
 * with an expandable per-call breakdown, so a slow/fast turn is explainable.
 */
function renderMetricsBubble(bubble, metrics) {
    if (!metrics || !Array.isArray(metrics.calls) || !metrics.calls.length) return;
    const calls = metrics.calls;
    const ac = pickAnswerCall(calls);

    const parts = [calls.length + ' call' + (calls.length === 1 ? '' : 's')];
    if (metrics.total_ms != null) parts.push(fmtS(metrics.total_ms));
    if (metrics.ttft_ms != null) parts.push('TTFT ' + fmtS(metrics.ttft_ms));
    if (ac && ac.reasoning_ms > 0) parts.push('think ' + fmtS(ac.reasoning_ms));
    if (ac) {
        let tps = 0;
        if (ac.content_ms > 0 && ac.content_tok > 0) tps = ac.content_tok / (ac.content_ms / 1000);
        else if (ac.pred_tps) tps = ac.pred_tps;
        if (tps > 0) parts.push(Math.round(tps) + ' tok/s');
        if (ac.prompt_tokens > 0) parts.push(Math.round(ac.cache_n / ac.prompt_tokens * 100) + '% cached');
    }
    const summary = parts.join(' \u00b7 ');

    const chain = calls.map(c => PURPOSE_LABELS[c.purpose] || c.purpose).join(' \u2192 ');

    const details = document.createElement('details');
    details.className = 'metrics-section w-full mt-3 overflow-hidden rounded-lg border border-slate-700/40 bg-slate-900/40';
    details.innerHTML = `
        <summary class="flex items-center justify-between gap-3 px-3 py-2 cursor-pointer select-none text-slate-300">
            <span class="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-wider text-slate-400">
                <svg class="w-3.5 h-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                metrics
            </span>
            <span class="text-[11px] font-mono text-slate-400 truncate">${summary}</span>
        </summary>
        <div class="px-3 pb-3 border-t border-slate-800/60">
            <div class="text-[10px] text-slate-500 font-mono py-1.5">${chain}</div>
            <table class="w-full text-[10px] font-mono text-slate-400">
                <thead><tr class="text-slate-500 text-left">
                    <th class="py-1 pr-2 font-normal">call</th>
                    <th class="py-1 pr-2 font-normal">time</th>
                    <th class="py-1 pr-2 font-normal">prefill</th>
                    <th class="py-1 pr-2 font-normal">think</th>
                    <th class="py-1 font-normal">text</th>
                </tr></thead>
                <tbody>
                    ${calls.map(c => {
                        const label = PURPOSE_LABELS[c.purpose] || c.purpose;
                        const prefill = c.prompt_ms > 0 ? `${fmtMs(c.prompt_ms)} \u00b7 ${c.prompt_n} tok${c.cache_n > 0 ? ' \u00b7 ' + c.cache_n + ' cached' : ''}` : '\u2014';
                        const think = c.reasoning_ms > 0 ? `${fmtMs(c.reasoning_ms)} \u00b7 ${c.reasoning_tok} tok` : '\u2014';
                        const text = c.content_ms > 0 ? `${fmtMs(c.content_ms)} \u00b7 ${c.content_tok} tok` : '\u2014';
                        return `<tr class="border-t border-slate-800/40">
                            <td class="py-1 pr-2">${label}</td>
                            <td class="py-1 pr-2">${fmtMs(c.elapsed_ms)}</td>
                            <td class="py-1 pr-2">${prefill}</td>
                            <td class="py-1 pr-2">${think}</td>
                            <td class="py-1">${text}</td>
                        </tr>`;
                    }).join('')}
                </tbody>
            </table>
        </div>
    `;
    bubble.appendChild(details);
}

class TypewriterEffect {
    constructor(targetEl, peekEl) {
        this.targetEl = targetEl;
        this.peekEl = peekEl;
        this.buffer = '';
        this.displayed = '';
        this.running = false;
        this.rafId = null;
        this.textEl = null;
        this.onFinish = null;
        this._setup();
    }

    _setup() {
        this.targetEl.innerHTML = '';
        this.textEl = document.createElement('span');
        this.textEl.className = 'typewriter-text';
        this.targetEl.appendChild(this.textEl);
    }

    push(text) {
        this.buffer += text;
        if (!this.running) this.start();
    }

    start() {
        if (this.running) return;
        this.running = true;
        this.rafId = requestAnimationFrame(() => this.tick());
    }

    tick() {
        if (this.buffer.length === 0) {
            this.running = false;
            if (this.rafId) { cancelAnimationFrame(this.rafId); this.rafId = null; }
            if (this.onFinish) {
                const cb = this.onFinish;
                this.onFinish = null;
                cb();
            }
            return;
        }
        this.displayed += this.buffer;
        this.buffer = '';
        this.textEl.textContent = this.displayed;
        if (this.peekEl) {
            this.peekEl.scrollTop = this.peekEl.scrollHeight;
        }
        this.rafId = requestAnimationFrame(() => this.tick());
    }

    flush() {
        if (this.rafId) { cancelAnimationFrame(this.rafId); this.rafId = null; }
        this.displayed += this.buffer;
        this.buffer = '';
        this.textEl.textContent = this.displayed;
        this.running = false;
        if (this.onFinish) {
            const cb = this.onFinish;
            this.onFinish = null;
            cb();
        }
    }

    reset() {
        if (this.rafId) { cancelAnimationFrame(this.rafId); this.rafId = null; }
        this.buffer = '';
        this.displayed = '';
        this.running = false;
        this.textEl.textContent = '';
    }

    get displayedText() { return this.displayed; }
}

let stickToBottom = true;
let scrollTrackingAttached = false;

function ensureScrollTracking(chatWindow) {
    if (scrollTrackingAttached) return;
    scrollTrackingAttached = true;
    chatWindow.addEventListener('scroll', () => {
        stickToBottom = chatWindow.scrollTop + chatWindow.clientHeight >= chatWindow.scrollHeight - 80;
    });
}

function scrollIfStuck(chatWindow) {
    if (stickToBottom && chatWindow) {
        chatWindow.scrollTop = chatWindow.scrollHeight;
    }
}

export async function streamResponse(formData, originalMessage) {
    state.isGenerating = true;
    
    const lockOverlay = document.getElementById('editor-lock-overlay');
    if (lockOverlay) {
        lockOverlay.classList.remove('opacity-0', 'pointer-events-none');
        lockOverlay.classList.add('opacity-100', 'pointer-events-auto');
    }

    const chatWindow = document.getElementById('chatWindow');
    const tplAi = document.getElementById('tpl-ai-message');
    const aiNode = tplAi.content.cloneNode(true);
    const aiWrapper = aiNode.querySelector('.ai-wrapper');
    const aiBubble = aiNode.querySelector('.ai-bubble');
    const aiLabelContainer = aiNode.querySelector('.ai-label-container');
    
    aiBubble.innerHTML = `
        <div class="flex items-center gap-3 text-cyan-400 font-medium loading-indicator mb-3 w-full">
            <span class="uk-spinner uk-spinner-sm animate-spin shrink-0" uk-spinner="ratio: 0.8"></span>
            <span class="loading-text truncate">Initializing...</span>
        </div>

        <details class="w-full mb-4 overflow-hidden group trace-accordion rounded-xl border border-emerald-500/20 bg-gradient-to-b from-[#0d1321]/90 to-[#0d1321]/70 backdrop-blur-sm shadow-[0_0_25px_rgba(16,185,129,0.05),inset_0_1px_0_rgba(16,185,129,0.04)] transition-all duration-300" open>
            <style>
                @keyframes trace-scan { 0% { background-position: -200% 0; } 100% { background-position: 200% 0; } }
                @keyframes trace-blink { 0%, 100% { opacity: 1; } 50% { opacity: 0; } }
                @keyframes trace-fadein { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
                .trace-active-task { animation: trace-fadein 0.2s ease-out; }
                .trace-active-task .trace-scan-overlay { background: linear-gradient(90deg, transparent 0%, rgba(16,185,129,0.08) 45%, rgba(16,185,129,0.15) 50%, rgba(16,185,129,0.08) 55%, transparent 100%); background-size: 200% 100%; animation: trace-scan 2s linear infinite; }
                .trace-cursor { animation: trace-blink 1s step-end infinite; }
            </style>
            <summary class="flex items-center justify-between px-5 py-3 cursor-pointer select-none bg-gradient-to-r from-emerald-500/5 via-emerald-500/3 to-transparent hover:from-emerald-500/10 hover:via-emerald-500/5 transition-all duration-200">
                <span class="flex items-center gap-3">
                    <span class="relative flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-500/10 border border-emerald-500/20 shadow-[0_0_10px_rgba(16,185,129,0.12)]">
                        <svg class="w-4 h-4 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="4 17 10 11 4 5"/>
                            <line x1="12" y1="19" x2="20" y2="19"/>
                        </svg>
                    </span>
                    <span class="text-sm font-semibold tracking-wide bg-gradient-to-r from-emerald-300 via-cyan-400 to-blue-400 bg-clip-text text-transparent">Execution Trace</span>
                    <span class="flex h-2 w-2 relative trace-pulse-dot">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-400 shadow-[0_0_6px_rgba(16,185,129,0.8)]"></span>
                    </span>
                </span>
                <span class="flex items-center gap-2 text-[0.65rem] text-slate-500 font-medium tracking-wide uppercase">
                    <span class="trace-step-counter"></span>
                    <svg class="w-3.5 h-3.5 transition-transform duration-300 group-open:rotate-180 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                </span>
            </summary>
            <div class="px-3 pb-3 pt-2 border-t border-emerald-500/10 bg-[#070b14]/40">
                <div class="trace-active-task hidden mb-2 px-2 py-1.5 rounded-md bg-emerald-500/5 border border-emerald-500/20 font-mono text-[0.7rem] relative overflow-hidden flex items-center">
                    <div class="trace-scan-overlay absolute inset-0 pointer-events-none"></div>
                    <span class="uk-spinner uk-spinner-sm animate-spin text-emerald-400 shrink-0 mr-1.5 trace-spinner" uk-spinner="ratio:0.6"></span>
                    <span class="trace-active-check mr-1.5 text-emerald-400 hidden">\u2713</span>
                    <span class="trace-active-label text-emerald-300/90"></span>
                    <span class="trace-cursor text-emerald-400 ml-0.5">\u2588</span>
                </div>
                <div class="space-y-0 trace-content flex flex-col items-stretch w-full font-mono">
                    <div class="scraping-container flex flex-col gap-1 mt-1 hidden w-full"></div>
                </div>
            </div>
        </details>

        <!-- Collapsible Thinking Process Box -->
        <div class="w-full mb-4 overflow-hidden thinking-accordion hidden rounded-xl border border-cyan-500/20 bg-gradient-to-b from-[#0d1321]/90 to-[#0d1321]/70 backdrop-blur-sm shadow-[0_0_25px_rgba(6,182,212,0.06),inset_0_1px_0_rgba(6,182,212,0.05)] transition-all duration-300">
            <div class="thinking-summary flex items-center justify-between px-5 py-3 cursor-pointer select-none bg-gradient-to-r from-cyan-500/5 via-cyan-500/3 to-transparent hover:from-cyan-500/10 hover:via-cyan-500/5 transition-all duration-200">
                <span class="flex items-center gap-3">
                    <span class="relative flex items-center justify-center w-8 h-8 rounded-lg bg-cyan-500/10 border border-cyan-500/20 shadow-[0_0_10px_rgba(6,182,212,0.15)]">
                        <svg class="w-4 h-4 text-cyan-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9.5 2A2.5 2.5 0 0 1 12 4.5v15a2.5 2.5 0 0 1-4.96.44 2.5 2.5 0 0 1-2.96-3.08 3 3 0 0 1-.34-5.58 2.5 2.5 0 0 1 1.32-4.24 2.5 2.5 0 0 1 4.44-2.04Z"/>
                            <path d="M14.5 2A2.5 2.5 0 0 0 12 4.5v15a2.5 2.5 0 0 0 4.96.44 2.5 2.5 0 0 0 2.96-3.08 3 3 0 0 0 .34-5.58 2.5 2.5 0 0 0-1.32-4.24 2.5 2.5 0 0 0-4.44-2.04Z"/>
                        </svg>
                    </span>
                    <span class="text-sm font-semibold tracking-wide bg-gradient-to-r from-cyan-300 via-cyan-400 to-blue-400 bg-clip-text text-transparent">Thinking Process</span>
                    <span class="flex h-2 w-2 relative thinking-pulse-dot">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-cyan-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-cyan-400 shadow-[0_0_6px_rgba(6,182,212,0.8)]"></span>
                    </span>
                </span>
                <span class="flex items-center gap-2 text-[0.65rem] text-slate-500 font-medium tracking-wide uppercase">
                    <span class="thinking-status-label">Streaming</span>
                    <svg class="thinking-chevron w-3.5 h-3.5 transition-transform duration-300 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                </span>
            </div>
            <div class="thinking-peek border-t border-cyan-500/10 bg-[#070b14]/40">
                <div class="thinking-content markdown-content px-5 pb-4 pt-3"></div>
                <div class="thinking-peek-fade"></div>
            </div>
        </div>

        <div class="streaming-text-container w-full"></div>
        <div class="file-choices-placeholder-container w-full"></div>
    `;
    chatWindow.appendChild(aiNode);
    ensureScrollTracking(chatWindow);
    stickToBottom = true;
    chatWindow.scrollTop = chatWindow.scrollHeight;

    const traceAccordion = aiWrapper.querySelector('.trace-accordion');
    const traceContent = aiWrapper.querySelector('.trace-content');
    const thinkingAccordion = aiWrapper.querySelector('.thinking-accordion');
    const thinkingContent = aiWrapper.querySelector('.thinking-content');
    const thinkingPeek = aiWrapper.querySelector('.thinking-peek');
    const thinkingSummary = aiWrapper.querySelector('.thinking-summary');
    const thinkingChevron = aiWrapper.querySelector('.thinking-chevron');
    const loadingIndicator = aiWrapper.querySelector('.loading-indicator');
    const loadingText = aiWrapper.querySelector('.loading-text');
    const scrapingContainer = aiWrapper.querySelector('.scraping-container');
    const textContainer = aiWrapper.querySelector('.streaming-text-container');
    const fileChoicesContainer = aiWrapper.querySelector('.file-choices-placeholder-container');

    if (thinkingSummary) {
        thinkingSummary.addEventListener('click', () => {
            const isOpen = thinkingAccordion.classList.toggle('open');
            if (thinkingChevron) {
                thinkingChevron.style.transform = isOpen ? 'rotate(180deg)' : '';
            }
        });
    }
    
    let traceStepCount = 0;
    const traceStepCounter = aiWrapper.querySelector('.trace-step-counter');
    const tracePulseDot = aiWrapper.querySelector('.trace-pulse-dot');
    const traceActiveTask = aiWrapper.querySelector('.trace-active-task');
    const traceActiveLabel = aiWrapper.querySelector('.trace-active-label');
    const traceSpinner = aiWrapper.querySelector('.trace-spinner');
    const traceActiveCheck = aiWrapper.querySelector('.trace-active-check');
    const traceCursor = aiWrapper.querySelector('.trace-cursor');
    const traceScanOverlay = aiWrapper.querySelector('.trace-scan-overlay');

    const TRACE_COLORS = {
        cyan:    { text: 'text-cyan-400',    accent: 'bg-cyan-500/50' },
        blue:    { text: 'text-blue-400',    accent: 'bg-blue-500/50' },
        amber:   { text: 'text-amber-400',   accent: 'bg-amber-500/50' },
        emerald: { text: 'text-emerald-400', accent: 'bg-emerald-500/50' },
        indigo:  { text: 'text-indigo-400',  accent: 'bg-indigo-500/50' },
        rose:    { text: 'text-rose-400',    accent: 'bg-rose-500/50' },
        slate:   { text: 'text-slate-300',   accent: 'bg-slate-500/40' },
    };

    function setActiveTask(label, color = 'slate') {
        const c = TRACE_COLORS[color] || TRACE_COLORS.slate;
        traceActiveTask.classList.remove('hidden');
        traceActiveTask.className = traceActiveTask.className.replace(/bg-\w+-\d+\/\d+/g, `bg-${color === 'slate' ? 'slate' : color}-500/5`);
        traceActiveTask.className = traceActiveTask.className.replace(/border-\w+-\d+\/\d+/g, `border-${color === 'slate' ? 'slate' : color}-500/20`);
        traceActiveLabel.className = `trace-active-label ${c.text}`;
        traceActiveLabel.textContent = label;
        if (traceSpinner) { traceSpinner.classList.remove('hidden'); traceSpinner.classList.add('animate-spin'); }
        if (traceActiveCheck) traceActiveCheck.classList.add('hidden');
        if (traceCursor) traceCursor.classList.remove('hidden');
        if (traceScanOverlay) traceScanOverlay.style.display = '';
        traceActiveTask.style.animation = 'none';
        traceActiveTask.offsetHeight;
        traceActiveTask.style.animation = '';
    }

    function completeActiveTask() {
        if (traceActiveTask.classList.contains('hidden')) return;
        if (traceSpinner) traceSpinner.classList.add('hidden');
        if (traceActiveCheck) traceActiveCheck.classList.remove('hidden');
        if (traceCursor) traceCursor.classList.add('hidden');
        if (traceScanOverlay) traceScanOverlay.style.display = 'none';
        traceActiveLabel.className = 'trace-active-label text-emerald-300/90';
    }

    function addTraceEntry(label, color = 'slate') {
        const c = TRACE_COLORS[color] || TRACE_COLORS.slate;

        const row = document.createElement('div');
        row.className = `flex items-start gap-2 py-1 px-2 rounded hover:bg-slate-800/20 transition-colors duration-150`;
        row.innerHTML = `
            <span class="w-0.5 self-stretch rounded-full shrink-0 ${c.accent}"></span>
            <span class="text-[0.7rem] ${c.text} mt-px font-medium tracking-wide flex-1 leading-relaxed">\u2713 ${label}</span>
        `;
        traceContent.appendChild(row);

        traceStepCount++;
        if (traceStepCounter) {
            traceStepCounter.textContent = `${traceStepCount} step${traceStepCount !== 1 ? 's' : ''}`;
        }
    }

    const TOOL_DISPLAY = {
        search_web:              { present: 'Searching the web',               past: 'Searched the web',               query: true },
        search_local:            { present: 'Searching files & memories',      past: 'Searched files & memories',      query: true },
        search_memories:         { present: 'Searching memories',             past: 'Searched memories',              query: true },
        search_calendar:         { present: 'Reading calendar entries',        past: 'Read calendar entries',          query: false },
        search_session_evidence: { present: 'Re-reading earlier search results', past: 'Re-read earlier search results', query: true },
        create_calendar_task:     { present: 'Creating calendar task',          past: 'Created calendar task',          query: false },
    };

    const TOOL_ICONS = {
        search_web:      '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        search_local:    '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        search_memories: '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
        search_calendar: '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        search_session_evidence: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
        create_calendar_task: '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    };

    const TOOL_SPEC = {
        search_web:              { bg: 'bg-sky-500/10',    text: 'text-sky-300',    border: 'border-sky-500/40',    accent: 'bg-sky-400' },
        search_local:            { bg: 'bg-cyan-500/10',   text: 'text-cyan-300',   border: 'border-cyan-500/40',   accent: 'bg-cyan-400' },
        search_memories:         { bg: 'bg-teal-500/10',   text: 'text-teal-300',   border: 'border-teal-500/40',   accent: 'bg-teal-400' },
        search_calendar:         { bg: 'bg-amber-500/10',  text: 'text-amber-300',  border: 'border-amber-500/40',  accent: 'bg-amber-400' },
        search_session_evidence: { bg: 'bg-violet-500/10', text: 'text-violet-300', border: 'border-violet-500/40', accent: 'bg-violet-400' },
        create_calendar_task:     { bg: 'bg-indigo-500/10', text: 'text-indigo-300', border: 'border-indigo-500/40', accent: 'bg-indigo-400' },
    };

    function renderToolBadge(tool, text) {
        const s = TOOL_SPEC[tool] || { bg: 'bg-slate-500/10', text: 'text-slate-300', border: 'border-slate-500/30', accent: 'bg-slate-400' };
        const icon = TOOL_ICONS[tool] || '<circle cx="12" cy="12" r="10"/>';
        const badge = document.createElement('span');
        badge.className = `text-[0.7rem] px-2.5 py-0.5 rounded-md ${s.bg} ${s.text} border ${s.border} flex items-center gap-1.5 font-medium tracking-tight`;
        badge.innerHTML = `<span class="w-1.5 h-1.5 rounded-full ${s.accent} shadow-[0_0_6px_currentColor]"></span><svg class="w-3 h-3 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${icon}</svg>${text}`;
        aiLabelContainer.appendChild(badge);
    }

    function truncateForBadge(s, max = 50) {
        return s.length > max ? s.slice(0, max) + '…' : s;
    }

    let markdownBuffer = "";
    let currentSources = [];
    let sourceIds = [];
    let isFirstToken = true;
    let reasoningSeen = false;
    let consolidatingBadge = null;
    let turnHadEdit = false;
    let activeToolContext = null;
    let thinkingTypewriter = new TypewriterEffect(thinkingContent, thinkingPeek);

    // ── Incremental streaming render ─────────────────────────────────────
    // Full-buffer marked.parse + innerHTML rebuild on every token is O(n^2)
    // and thrashes layout (the vertical "wiggle" + dropped frames). Instead:
    // completed markdown blocks are parsed once and appended to a stable
    // container; the in-progress block renders as a cheap tail; the final
    // authoritative render happens once in the `done` event handler.
    const committedEl = document.createElement('div');
    committedEl.className = 'streaming-committed';
    const tailEl = document.createElement('p');
    tailEl.className = 'streaming-tail';
    const cursorEl = document.createElement('span');
    cursorEl.className = 'streaming-cursor animate-pulse text-cyan-400 font-bold ml-0.5 select-none inline-block';
    cursorEl.textContent = '\u258d';
    tailEl.appendChild(cursorEl);
    textContainer.appendChild(committedEl);
    textContainer.appendChild(tailEl);

    let renderRawLen = 0;      // index into the renderable text already committed
    let lastRenderable = '';   // last fully-stripped display text (for the done flush)
    let renderScheduled = false;

    function escapeHtml(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // Positions of every fenced-code-block marker (``` or ~~~ at line start).
    function fenceMarkers(text) {
        const re = /^```|^~~~/gm;
        const positions = [];
        let m;
        while ((m = re.exec(text)) !== null) positions.push(m.index);
        return positions;
    }

    function isInsideOpenFence(text) {
        return fenceMarkers(text).length % 2 === 1;
    }

    // Last index in `text` where markdown is structurally stable. Blocks are
    // committed only up to this boundary; the remainder is the dirty tail.
    function lastSafeBoundary(text) {
        const len = text.length;
        const fences = fenceMarkers(text);

        // Odd marker count => inside an open code fence. Never use a blank
        // line inside code as a boundary; commit only up to the fence's
        // opening line so the whole fence stays in the tail until it closes.
        if (fences.length % 2 === 1) {
            const openAt = fences[fences.length - 1];
            const lineStart = text.lastIndexOf('\n', openAt - 1);
            return lineStart === -1 ? 0 : lineStart + 1;
        }

        // Even markers => every fence is closed. Commit up to the last blank
        // line, extended to include a closed fence's closing line when it is
        // the trailing content (so a complete fence renders without waiting
        // for a following blank line).
        let boundary = text.lastIndexOf('\n\n');
        boundary = (boundary === -1) ? 0 : boundary + 2;

        if (fences.length > 0) {
            const lastFence = fences[fences.length - 1];
            const lineEnd = text.indexOf('\n', lastFence);
            const fenceEnd = (lineEnd === -1) ? len : lineEnd + 1;
            if (fenceEnd > boundary) boundary = fenceEnd;
        }
        return boundary;
    }

    function postProcess(html) {
        let out = cleanAssistantStreamText(html);
        if (window.parseInlineFiles) {
            out = window.parseInlineFiles(out);
        }
        return out;
    }

    function highlightNew(container) {
        container.querySelectorAll('pre code').forEach((block) => {
            if (!block.classList.contains('hljs')) {
                hljs.highlightElement(block);
            }
        });
    }

    function scheduleRender(renderable) {
        lastRenderable = renderable;
        if (renderScheduled) return;
        renderScheduled = true;
        requestAnimationFrame(() => {
            renderScheduled = false;
            renderFrame();
        });
    }

    function renderFrame() {
        const renderable = lastRenderable;
        const boundary = lastSafeBoundary(renderable);

        if (boundary > renderRawLen) {
            const delta = renderable.slice(renderRawLen, boundary);
            committedEl.insertAdjacentHTML('beforeend', postProcess(marked.parse(delta)));
            highlightNew(committedEl);
            renderRawLen = boundary;
        }

        const tail = renderable.slice(renderRawLen);
        if (tail === '') {
            tailEl.textContent = '';
        } else if (isInsideOpenFence(renderable)) {
            // Escaped code (no markdown mangling) until the fence closes.
            tailEl.innerHTML = '<code style="white-space:pre-wrap">' + escapeHtml(tail) + '</code>';
        } else if (typeof marked.parseInline === 'function') {
            tailEl.innerHTML = marked.parseInline(tail);
        } else {
            tailEl.textContent = tail;
        }
        tailEl.appendChild(cursorEl);
    }

    function flushFinalRender() {
        const renderable = lastRenderable;
        renderRawLen = renderable.length;
        tailEl.textContent = '';
        committedEl.innerHTML = postProcess(marked.parse(renderable));
        highlightNew(committedEl);
    }

    try {
        const response = await fetch('index.php', {
            method: 'POST',
            headers: { 'Accept': 'text/event-stream' },
            body: formData
        });

        if (!response.ok) {
            throw new Error(`HTTP Error: ${response.status}`);
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });
            let lines = buffer.split("\n\n");
            buffer = lines.pop();

            for (let line of lines) {
                if (line.startsWith('data: ')) {
                    const payloadStr = line.substring(6);
                    try {
                        const payload = JSON.parse(payloadStr);
                        const event = payload.event;
                        const data = payload.data;

                        if (event === 'context_overflow') {
                            state.isGenerating = false;
                            if (loadingIndicator && loadingIndicator.parentNode) {
                                loadingIndicator.remove();
                            }
                            lockChatContext();
                            const overflowMsg = document.createElement('div');
                            overflowMsg.className = 'text-rose-400 text-sm py-1';
                            overflowMsg.textContent = (data && data.message) ? data.message : 'Context limit reached.';
                            textContainer.appendChild(overflowMsg);
                            aiBubble.classList.add('parsed');
                            return;
                        }

                        if (event === 'limit_warning') {
                            state.isGenerating = false;
                            aiWrapper.remove();
                            showCondensationModal(formData, originalMessage);
                            return;
                        }

                        if (event === 'error') {
                            state.isGenerating = false;
                            if (loadingIndicator && loadingIndicator.parentNode) {
                                loadingIndicator.remove();
                            }
                            const errMsg = document.createElement('div');
                            errMsg.className = 'text-rose-400 text-sm py-1';
                            errMsg.textContent = (data && data.message) ? data.message : 'AI is busy with another task.';
                            textContainer.appendChild(errMsg);
                            aiBubble.classList.add('parsed');
                            return;
                        }

                        if (event === 'title_updated') {
                            const headerTitle = document.querySelector('header h2');
                            if (headerTitle) headerTitle.innerHTML = `<uk-icon icon="message-square" class="w-5 h-5 text-cyan-500"></uk-icon> ${data.title}`;
                            const activeItemTitle = document.querySelector('.group.bg-slate-800\\/80 .session-title');
                            if (activeItemTitle) activeItemTitle.textContent = data.title;
                            addTraceEntry(`Title assigned: \u201c${data.title}\u201d`, 'cyan');
                        }

                        if (event === 'search_decided') {
                            const truncatedQuery = data.query.length > 50 ? data.query.substring(0, 50) + '...' : data.query;
                            loadingText.textContent = `Searching web for: "${truncatedQuery}"...`;

                            const badge = document.createElement('span');
                            badge.className = "text-[0.65rem] px-2 py-0.5 rounded-full bg-blue-500/20 text-blue-400 border border-blue-500/30 flex items-center gap-1 normal-case tracking-normal shadow-sm";
                            badge.innerHTML = '<uk-icon icon="globe" class="w-3 h-3"></uk-icon> Web Search';
                            aiLabelContainer.appendChild(badge);

                            addTraceEntry(`Web search triggered: \u201c${truncatedQuery}\u201d`, 'blue');
                        }

                        if (event === 'intent_result') {
                            const intentLabels = {
                                'none': 'general conversation',
                                'search_files': 'file search',
                                'calendar_create': 'task creation',
                                'calendar_get': 'task retrieval',
                                'calendar_update': 'task update',
                                'calendar_delete': 'task deletion',
                                'email_briefing': 'email briefing'
                            };

                            const intentList = data.intents === 'none' ? [] : data.intents.split(',');
                            const intentParts = intentList.map(i => intentLabels[i.trim()] || i.trim());

                            let summary;
                            if (data.search_query) {
                                const truncated = data.search_query.length > 50
                                    ? data.search_query.substring(0, 50) + '...' : data.search_query;
                                if (intentParts.length > 0) {
                                    summary = `Classified as ${intentParts.join(', ')} \u2014 web search needed for: \u201c${truncated}\u201d`;
                                } else {
                                    summary = `Web search needed for: \u201c${truncated}\u201d`;
                                }
                            } else {
                                if (intentParts.length > 0) {
                                    summary = `Classified as ${intentParts.join(', ')} \u2014 no web search required`;
                                } else {
                                    summary = 'Classified as general conversation \u2014 no tools or web search needed';
                                }
                            }

                            addTraceEntry(summary, 'indigo');
                        }

                        if (event === 'tool_start') {
                            const tool = data.tool;
                            const t = TOOL_DISPLAY[tool];
                            if (t) {
                                const rawLabel = data.label || '';
                                const colonIdx = rawLabel.indexOf(': ');
                                const query = colonIdx >= 0 ? rawLabel.slice(colonIdx + 2) : '';
                                const presentLabel = (t.query && query) ? `${t.present} for \u201c${truncateForBadge(query, 60)}\u201d` : t.present;
                                setActiveTask(presentLabel, tool === 'create_calendar_task' ? 'indigo' : 'slate');
                                activeToolContext = { tool, past: t.past, showQuery: t.query, queryText: query, shortQuery: truncateForBadge(query, 60) };
                            } else {
                                // Non-tool-turn tool (email briefing, scheduling, …):
                                // the backend already sends a human label; show it as-is.
                                setActiveTask(data.label || tool, 'slate');
                                activeToolContext = null;
                            }
                        }

                        if (event === 'tool_done') {
                            completeActiveTask();
                            const ctx = activeToolContext;
                            activeToolContext = null;
                            if (!ctx) {
                                addTraceEntry(`Completed \u2014 ${data.label || 'Done.'}`, 'emerald');
                            } else if (ctx.tool !== 'create_calendar_task') {
                                const pastLabel = (ctx.showQuery && ctx.queryText) ? `${ctx.past} for \u201c${ctx.shortQuery}\u201d` : ctx.past;
                                addTraceEntry(`Completed \u2014 ${pastLabel}`, 'emerald');
                                renderToolBadge(ctx.tool, pastLabel);
                            }
                            // create_calendar_task: the aggregated calendar_task_created event is the signal.
                        }

                        if (event === 'calendar_task_created') {
                            const tasks = data.tasks || [];
                            const n = data.count || tasks.length;
                            const contents = tasks.map(t => t.content).filter(Boolean);
                            const summary = n === 1 ? 'Created calendar task' : `Created ${n} calendar tasks`;
                            addTraceEntry(contents.length ? `${summary}: ${contents.join(', ')}` : summary, 'indigo');
                            renderToolBadge('create_calendar_task', summary);
                        }

                        if (event === 'trace') {
                            addTraceEntry(data.label || 'Trace entry', data.color || 'slate');
                        }

                        if (event === 'search_no_results') {
                            const truncated = data.query && data.query.length > 50
                                ? data.query.substring(0, 50) + '...' : (data.query || '');
                            addTraceEntry(`No usable web results for: \u201c${truncated}\u201d`, 'rose');
                        }

                        if (event === 'scraping_start') {
                            loadingText.textContent = "Extracting knowledge...";
                            scrapingContainer.classList.remove('hidden');
                            
                            const linkRow = document.createElement('a');
                            linkRow.href = data.url;
                            linkRow.target = "_blank";
                            linkRow.className = "flex items-center gap-2 py-1 px-2 rounded text-xs text-slate-400 bg-slate-900/30 border border-slate-700/30 hover:bg-slate-800/40 hover:text-emerald-400 transition-colors duration-150 font-mono";
                            linkRow.setAttribute('data-url', data.url);
                            linkRow.innerHTML = `
                                <span class="uk-spinner uk-spinner-xs animate-spin text-emerald-500 shrink-0" uk-spinner="ratio: 0.5"></span>
                                <span class="truncate max-w-full text-[0.7rem]">${data.url}</span>
                            `;
                            scrapingContainer.appendChild(linkRow);
                            scrollIfStuck(chatWindow);
                        }

                        if (event === 'scraping_done') {
                            const row = scrapingContainer.querySelector(`[data-url="${data.url}"]`);
                            if (row) {
                                row.classList.remove('text-slate-400');
                                row.classList.add('text-emerald-400');
                                const existingSpinner = row.querySelector('.uk-spinner');
                                if (existingSpinner) existingSpinner.remove();
                                row.insertAdjacentHTML('afterbegin', '<uk-icon icon="check-circle" class="w-3.5 h-3.5 shrink-0"></uk-icon>');
                            }
                        }

                        if (event === 'condensing') {
                            loadingText.textContent = "Condensing information...";
                        }

                        if (event === 'sources') {
                            const sources = data.sources || {};
                            currentSources = Object.entries(sources).map(([id, s]) => ({ id, ...s }));
                            sourceIds = currentSources.map(s => s.id);
                        }

                        if (event === 'generating') {
                            loadingText.textContent = "Thinking...";
                            aiBubble.classList.add('shadow-[0_0_15px_rgba(6,182,212,0.15)]', 'border-cyan-500/30');
                            scrollIfStuck(chatWindow);
                        }

                        if (event === 'context_assembled') {
                            let ctxParts = [];
                            if (data.has_search_context) {
                                ctxParts.push('web results');
                            }
                            const memoryNote = data.message_count > 0
                                ? `${data.message_count} prior messages`
                                : 'new conversation';
                            ctxParts.push(memoryNote);

                            addTraceEntry(`Context assembled with ${ctxParts.join(' + ')}`, 'emerald');
                        }

                        if (event === 'context_data_added') {
                            addContextItem(data);
                        }

                        if (event === 'context_data_atomized') {
                            refreshContextItem(data.id);
                        }

                        if (event === 'status') {
                            loadingText.textContent = data.text;
                        }

                        if (event === 'consolidation_start') {
                            // Deferred atomization runs at the START of this turn
                            // (before the answer) — so this badge is a current-turn
                            // status on the pending message, not a post-answer step
                            // on the previous assistant message. The editor lock
                            // overlay + state.isGenerating stay held (stream is still
                            // open) so the next submission stays blocked until the
                            // compaction finishes.
                            if (!consolidatingBadge && aiLabelContainer) {
                                consolidatingBadge = document.createElement('span');
                                consolidatingBadge.className = 'text-[0.65rem] px-2 py-0.5 rounded-full bg-violet-500/20 text-violet-300 border border-violet-500/30 flex items-center gap-1 normal-case tracking-normal shadow-sm';
                                consolidatingBadge.innerHTML = '<span class="uk-spinner uk-spinner-xs animate-spin shrink-0" uk-spinner="ratio: 0.5"></span> Consolidating evidence...';
                                aiLabelContainer.appendChild(consolidatingBadge);
                            }
                        }

                        if (event === 'consolidation_done' || event === 'consolidation_error') {
                            if (consolidatingBadge) {
                                consolidatingBadge.remove();
                                consolidatingBadge = null;
                            }
                        }

                        if (event === 'file_choices') {
                            renderFileChoices(data, fileChoicesContainer, chatWindow);
                        }

                        if (event === 'reasoning') {
                            if (!reasoningSeen) {
                                reasoningSeen = true;
                                thinkingTypewriter.reset();
                                const summaryLabel = thinkingAccordion.querySelector('.thinking-summary span:first-of-type');
                                const pulseDot = thinkingAccordion.querySelector('.thinking-pulse-dot');
                                const statusLabel = thinkingAccordion.querySelector('.thinking-status-label');
                                if (summaryLabel) {
                                    summaryLabel.className = 'flex items-center gap-2 text-cyan-300';
                                    summaryLabel.innerHTML = '<uk-icon icon=\"brain\" class=\"w-3.5 h-3.5 text-cyan-400\"></uk-icon> Thinking Process';
                                }
                                if (pulseDot) pulseDot.classList.remove('hidden');
                                if (statusLabel) statusLabel.textContent = 'Analyzing...';
                            }
                            if (loadingIndicator && loadingIndicator.parentNode) {
                                loadingIndicator.remove();
                            }
                            thinkingAccordion.classList.remove('hidden');
                            thinkingTypewriter.push(data.chunk);
                            scrollIfStuck(chatWindow);
                        }

                        if (event === 'thought_complete') {
                            const pulseDot = thinkingAccordion.querySelector('.thinking-pulse-dot');
                            const statusLabel = thinkingAccordion.querySelector('.thinking-status-label');
                            if (pulseDot) pulseDot.classList.add('hidden');
                            if (statusLabel) statusLabel.textContent = 'Complete';

                            thinkingTypewriter.onFinish = () => {
                                const displayed = thinkingTypewriter.displayedText;
                                if (displayed) {
                                    const html = marked.parse(displayed);
                                    thinkingContent.innerHTML = html;
                                    thinkingContent.querySelectorAll('pre code').forEach(block => hljs.highlightElement(block));
                                }
                                const summaryLabel = thinkingAccordion.querySelector('.thinking-summary span:first-of-type');
                                if (summaryLabel) {
                                    summaryLabel.className = 'flex items-center gap-2 text-slate-400';
                                    summaryLabel.innerHTML = '<uk-icon icon="check" class="w-3.5 h-3.5 text-emerald-500"></uk-icon> Thought Process Complete';
                                }
                            };
                        }

                        if (event === 'token') {
                            if (isFirstToken) {
                                isFirstToken = false;
                                traceAccordion.open = false;
                                if (tracePulseDot) tracePulseDot.classList.add('hidden');
                                if (loadingIndicator && loadingIndicator.parentNode) {
                                    loadingIndicator.remove();
                                }
                                window.processedBlockIds = new Set();
                            }

                            markdownBuffer += data.chunk;

                            const { thinking, response, format } = extractThinking(markdownBuffer);
                            let thinkingText = thinking;
                            let finalText = response;

                            if (thinking) {
                                thinkingAccordion.classList.remove('hidden');
                                if (response) {
                                    if (thinkingText) {
                                        if (!reasoningSeen) {
                                            thinkingTypewriter.reset();
                                            thinkingTypewriter.push(thinkingText);
                                            thinkingTypewriter.flush();
                                            const displayed = thinkingTypewriter.displayedText;
                                            const html = marked.parse(displayed);
                                            thinkingContent.innerHTML = html;
                                            thinkingContent.querySelectorAll('pre code').forEach(block => hljs.highlightElement(block));
                                        } else {
                                            const html = marked.parse(thinkingText);
                                            thinkingContent.innerHTML = html;
                                            thinkingContent.querySelectorAll('pre code').forEach(block => hljs.highlightElement(block));
                                        }
                                    }
                                    const pulseDot = thinkingAccordion.querySelector('.thinking-pulse-dot');
                                    const statusLabel = thinkingAccordion.querySelector('.thinking-status-label');
                                    if (pulseDot) pulseDot.classList.add('hidden');
                                    if (statusLabel) statusLabel.textContent = 'Complete';
                                } else {
                                    if (!reasoningSeen) {
                                        thinkingTypewriter.push(thinkingText.substring(thinkingTypewriter.displayedText.length));
                                    } else {
                                        thinkingContent.textContent = thinkingText;
                                    }
                                }
                            }

                            let hasAppliedEdit = false;

                            if (finalText) {
                                let parseBuffer = finalText;

                                while (true) {
                                    let startIndex = parseBuffer.indexOf('<update id="');
                                    if (startIndex === -1) break;

                                    let tagEndIndex = parseBuffer.indexOf('">', startIndex);
                                    if (tagEndIndex === -1) break;

                                    let blockId = parseBuffer.substring(startIndex + 12, tagEndIndex);
                                    let endIndex = parseBuffer.indexOf('</update>', tagEndIndex);

                                    if (endIndex !== -1) {
                                        let finalContent = parseBuffer.substring(tagEndIndex + 2, endIndex).trim();
                                        
                                        if (!window.activeEditFile && !window.processedBlockIds.has(blockId)) {
                                            window.processedBlockIds.add(blockId);
                                            window.commitBlockEditDirectly(blockId, finalContent);
                                        }
                                        hasAppliedEdit = true;
                                        if (window.activeEditFile) {
                                            parseBuffer = parseBuffer.substring(0, startIndex) + finalContent + parseBuffer.substring(endIndex + 9);
                                        } else {
                                            parseBuffer = parseBuffer.substring(0, startIndex) + parseBuffer.substring(endIndex + 9);
                                        }
                                    } else {
                                        if (!window.activeEditFile) {
                                            let partialContent = parseBuffer.substring(tagEndIndex + 2).trim();
                                            let nextTagIndex = partialContent.indexOf('<update id="');
                                            if (nextTagIndex !== -1) {
                                                partialContent = partialContent.substring(0, nextTagIndex).trim();
                                            }
                                            window.streamUpdateBlockContent(blockId, partialContent);
                                        }
                                        hasAppliedEdit = true;
                                        parseBuffer = parseBuffer.substring(0, startIndex);
                                        break;
                                    }
                                }

                                parseBuffer = stripCitations(parseBuffer, sourceIds);
                                scheduleRender(parseBuffer);
                            }
 
                            aiBubble.setAttribute('data-raw', markdownBuffer);
 
                            if (hasAppliedEdit) turnHadEdit = true;

                            if (!thinkingAccordion.classList.contains('open')) {
                                scrollIfStuck(chatWindow);
                            }
                        }

                        if (event === 'done') {
                            const cursor = textContainer.querySelector('.streaming-cursor');
                            if (cursor) cursor.remove();
                            flushFinalRender();
                            if (window.evaluateStreamCompletion && window.activeToggledBlocks) {
                                window.evaluateStreamCompletion(turnHadEdit, aiBubble, textContainer);
                            }

                            if (window.activeEditFile && markdownBuffer && markdownBuffer.indexOf('<update id=') !== -1) {
                                const re = /<update id="([^"]+)">([\s\S]*?)<\/update>/g;
                                let match;
                                while ((match = re.exec(markdownBuffer)) !== null) {
                                    const blockId = match[1];
                                    const finalContent = match[2].trim();
                                    const blockExists = window.activeBlocks && window.activeBlocks.some(b => b.id === blockId);
                                    if (blockId && blockExists && !window.processedBlockIds.has(blockId)) {
                                        window.processedBlockIds.add(blockId);
                                        window.commitBlockEditDirectly(blockId, finalContent);
                                    }
                                }
                            }

                            if (loadingIndicator && loadingIndicator.parentNode) {
                                loadingIndicator.remove();
                            }

                            // Finalize thinking accordion state (safety net: thought_complete may not fire)
                            if (thinkingAccordion && (reasoningSeen || thinkingAccordion.classList.contains('open'))) {
                                if (thinkingTypewriter.onFinish) {
                                    thinkingTypewriter.flush();
                                } else {
                                    thinkingTypewriter.flush();
                                    const displayed = thinkingTypewriter.displayedText;
                                    if (displayed && !thinkingContent.querySelector('pre code')) {
                                        const html = marked.parse(displayed);
                                        thinkingContent.innerHTML = html;
                                        thinkingContent.querySelectorAll('pre code').forEach(block => hljs.highlightElement(block));
                                    }
                                    const summaryLabel = thinkingAccordion.querySelector('.thinking-summary span:first-of-type');
                                    const pulseDot = thinkingAccordion.querySelector('.thinking-pulse-dot');
                                    const statusLabel = thinkingAccordion.querySelector('.thinking-status-label');
                                    if (summaryLabel && !summaryLabel.innerHTML.includes('Complete')) {
                                        summaryLabel.className = 'flex items-center gap-2 text-slate-400';
                                        summaryLabel.innerHTML = '<uk-icon icon="check" class="w-3.5 h-3.5 text-emerald-500"></uk-icon> Thought Process Complete';
                                    }
                                    if (pulseDot) pulseDot.classList.add('hidden');
                                    if (statusLabel) statusLabel.textContent = 'Complete';
                                }
                            }

                            if (data.total_session_tokens && typeof maxTokensLimit !== 'undefined') {
                                updateTokenCounter(data.total_session_tokens, maxTokensLimit);
                            }

                            if (data.session_id) {
                                const chatSessionInput = document.querySelector('#chatForm input[name="session_id"]');
                                let oldSessionId = 0;
                                if (chatSessionInput && chatSessionInput.value) {
                                    const parsed = parseInt(chatSessionInput.value, 10);
                                    if (!isNaN(parsed)) {
                                        oldSessionId = parsed;
                                    }
                                }

                                if (oldSessionId === 0) {
                                    const url = new URL(window.location.href);
                                    url.searchParams.set('session_id', data.session_id);
                                    window.location.replace(url.toString());
                                    return;
                                } else {
                                    const sessionIdInputs = document.querySelectorAll('input[name="session_id"]');
                                    sessionIdInputs.forEach(input => {
                                        input.value = data.session_id;
                                    });
                                    const url = new URL(window.location.href);
                                    if (url.searchParams.get('session_id') !== String(data.session_id)) {
                                        url.searchParams.set('session_id', data.session_id);
                                        window.history.pushState({ session_id: data.session_id }, '', url.toString());
                                    }
                                }
                            }

                            if (data.message) {
                                aiBubble.setAttribute('data-raw', data.message);
                            }
                            if (currentSources.length) {
                                renderSourcesList(aiBubble, currentSources);
                            }
                            if (data.perf_metrics) {
                                renderMetricsBubble(aiBubble, data.perf_metrics);
                            }

                            scrollIfStuck(chatWindow);
                        }

                    } catch (e) {}
                }
            }
        }

        aiBubble.classList.add('parsed');

    } catch (error) {
        console.error("Stream Error:", error);
        if (loadingText) loadingText.textContent = "Connection failed.";
        const spinner = loadingIndicator ? loadingIndicator.querySelector('.uk-spinner') : null;
        if (spinner) spinner.remove();
        if (loadingIndicator) loadingIndicator.classList.replace('text-cyan-400', 'text-rose-400');
    } finally {
        state.isGenerating = false;
        
        const lockOverlay = document.getElementById('editor-lock-overlay');
        if (lockOverlay) {
            lockOverlay.classList.remove('opacity-100', 'pointer-events-auto');
            lockOverlay.classList.add('opacity-0', 'pointer-events-none');
        }
    }
}

function renderThinkingContent(container, text) {
    const html = marked.parse(text);
    container.innerHTML = html;
    container.querySelectorAll('pre code').forEach(block => hljs.highlightElement(block));
}
