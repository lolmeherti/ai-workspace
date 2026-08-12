/**
 * @file js/streamer/streamResponse.js
 * @description SSE chat response streaming handler with automatic HTML tag-level thought parsing.
 */

import { state } from '../state.js';
import { showCondensationModal, updateTokenCounter } from '../ui.js';
import { cleanAssistantStreamText } from './streamTextCleaner.js';
import { renderFileChoices } from './streamFileChoices.js';
import { extractThinking } from '../markdown.js';

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

    let markdownBuffer = "";
    let isFirstToken = true;
    let reasoningSeen = false;
    let thinkingTypewriter = new TypewriterEffect(thinkingContent, thinkingPeek);

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

                        if (event === 'limit_warning') {
                            state.isGenerating = false;
                            aiWrapper.remove();
                            showCondensationModal(formData, originalMessage);
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
                                'todoist_create': 'task creation',
                                'todoist_get': 'task retrieval',
                                'todoist_update': 'task update',
                                'todoist_delete': 'task deletion',
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
                            const toolLabel = data.label || data.tool;
                            setActiveTask(`Executing \u2014 ${toolLabel}`, 'slate');

                            const icons = {
                                search_web:      '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
                                search_local:    '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
                                search_calendar: '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
                            };
                            const spec = {
                                search_web:      { bg: 'bg-sky-500/10', text: 'text-sky-300', border: 'border-sky-500/40', accent: 'bg-sky-400' },
                                search_local:    { bg: 'bg-cyan-500/10', text: 'text-cyan-300', border: 'border-cyan-500/40', accent: 'bg-cyan-400' },
                                search_calendar: { bg: 'bg-amber-500/10', text: 'text-amber-300', border: 'border-amber-500/40', accent: 'bg-amber-400' },
                            };
                            const s = spec[data.tool] || { bg: 'bg-slate-500/10', text: 'text-slate-300', border: 'border-slate-500/30', accent: 'bg-slate-400' };
                            const icon = icons[data.tool] || '<circle cx="12" cy="12" r="10"/>';

                            const badge = document.createElement('span');
                            badge.className = `text-[0.7rem] px-2.5 py-0.5 rounded-md ${s.bg} ${s.text} border ${s.border} flex items-center gap-1.5 font-medium tracking-tight`;
                            badge.innerHTML = `<span class="w-1.5 h-1.5 rounded-full ${s.accent} shadow-[0_0_6px_currentColor]"></span><svg class="w-3 h-3 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${icon}</svg>${data.tool}`;
                            aiLabelContainer.appendChild(badge);
                        }

                        if (event === 'tool_done') {
                            const doneLabel = data.label || 'Done.';
                            completeActiveTask();
                            addTraceEntry(`Completed \u2014 ${doneLabel}`, 'emerald');
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

                        if (event === 'status') {
                            loadingText.textContent = data.text;
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

                                let htmlContent = marked.parse(parseBuffer);
                                htmlContent = cleanAssistantStreamText(htmlContent);

                                if (window.parseInlineFiles) {
                                    htmlContent = window.parseInlineFiles(htmlContent);
                                }

                                const cursorHtml = '<span class="animate-pulse text-cyan-400 font-bold ml-0.5 select-none inline-block">\u258d</span>';
                                
                                textContainer.innerHTML = htmlContent + cursorHtml;
                                textContainer.querySelectorAll('pre code').forEach((block) => {
                                    hljs.highlightElement(block);
                                });
                            }
 
                            aiBubble.setAttribute('data-raw', markdownBuffer);
 
                            if (payload.done) {
                                window.evaluateStreamCompletion(hasAppliedEdit, aiBubble, textContainer);
                            }

                            if (!thinkingAccordion.classList.contains('open')) {
                                scrollIfStuck(chatWindow);
                            }
                        }

                        if (event === 'done') {
                            const cursor = textContainer.querySelector('.animate-pulse');
                            if (cursor) cursor.remove();

                            // Tool-call detection: scan full text for tool syntax (including multi-line)
                            // Strip code fences first, then scan remaining text
                            const noFences = markdownBuffer.replace(/```[\s\S]*?```/g, '');
                            const toolPattern = /search_(local|web|calendar)\s*\(\s*queries[:=]\s*(.+?)\)/gs;
                            const detectedActions = [];
                            let match;
                            while ((match = toolPattern.exec(noFences)) !== null) {
                                detectedActions.push({
                                    tool: `search_${match[1]}`,
                                    queries: match[2].split(',').map(s => s.trim()).filter(s => s.length > 0)
                                });
                            }

                            if (detectedActions.length > 0) {
                                state.isGenerating = false;
                                if (loadingIndicator && loadingIndicator.parentNode) {
                                    loadingIndicator.remove();
                                }
                                aiBubble.classList.add('parsed');
                                traceAccordion.open = false;
                                if (tracePulseDot) tracePulseDot.classList.add('hidden');

                                const lockOverlay = document.getElementById('editor-lock-overlay');
                                if (lockOverlay) {
                                    lockOverlay.classList.remove('opacity-100', 'pointer-events-auto');
                                    lockOverlay.classList.add('opacity-0', 'pointer-events-none');
                                }

                                renderToolApprovalCard(data.session_id, detectedActions, aiBubble, markdownBuffer, textContainer);
                                scrollIfStuck(chatWindow);
                                return;
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
