/**
 * @file js/streamer/streamResponse.js
 * @description SSE chat response streaming handler with automatic HTML tag-level thought parsing.
 */

import { state } from '../state.js';
import { showCondensationModal, updateTokenCounter } from '../ui.js';
import { cleanAssistantStreamText } from './streamTextCleaner.js';
import { renderFileChoices } from './streamFileChoices.js';

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

        <details class="w-full bg-slate-900/40 border border-slate-800/80 rounded-lg mb-4 overflow-hidden group trace-accordion hidden">
            <summary class="flex items-center justify-between px-4 py-3 text-xs font-semibold text-slate-400 hover:text-slate-200 hover:bg-slate-800/30 cursor-pointer select-none">
                <span class="flex items-center gap-2">
                    <uk-icon icon="settings" class="w-3.5 h-3.5 group-open:rotate-90 transition-transform duration-200"></uk-icon>
                    Agent Execution Trace
                </span>
                <span class="text-[0.65rem] text-slate-500 font-normal">Click to expand</span>
            </summary>
            <div class="px-4 pb-4 pt-2 border-t border-slate-800/50 space-y-2 trace-content flex flex-col items-stretch w-full">
                <div class="scraping-container flex flex-col gap-2 mt-2 hidden w-full"></div>
            </div>
        </details>

        <!-- Collapsible Thinking Process Box -->
        <details class="w-full mb-4 overflow-hidden group thinking-accordion hidden rounded-xl border border-cyan-500/20 bg-gradient-to-b from-[#0d1321]/90 to-[#0d1321]/70 backdrop-blur-sm shadow-[0_0_25px_rgba(6,182,212,0.06),inset_0_1px_0_rgba(6,182,212,0.05)] transition-all duration-300" open>
            <summary class="flex items-center justify-between px-5 py-3 cursor-pointer select-none bg-gradient-to-r from-cyan-500/5 via-cyan-500/3 to-transparent hover:from-cyan-500/10 hover:via-cyan-500/5 transition-all duration-200">
                <span class="flex items-center gap-3">
                    <span class="relative flex items-center justify-center w-8 h-8 rounded-lg bg-cyan-500/10 border border-cyan-500/20 shadow-[0_0_10px_rgba(6,182,212,0.15)]">
                        <svg class="w-4 h-4 text-cyan-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9.5 2A2.5 2.5 0 0 1 12 4.5v15a2.5 2.5 0 0 1-4.96.44 2.5 2.5 0 0 1-2.96-3.08 3 3 0 0 1-.34-5.58 2.5 2.5 0 0 1 1.32-4.24 2.5 2.5 0 0 1 4.44-2.04Z"/>
                            <path d="M14.5 2A2.5 2.5 0 0 0 12 4.5v15a2.5 2.5 0 0 0 4.96.44 2.5 2.5 0 0 0 2.96-3.08 3 3 0 0 0 .34-5.58 2.5 2.5 0 0 0-1.32-4.24 2.5 2.5 0 0 0-4.44-2.04Z"/>
                        </svg>
                        <span class="absolute inset-0 rounded-lg border border-cyan-400/40 animate-ping opacity-0 group-open:opacity-0"></span>
                    </span>
                    <span class="text-sm font-semibold tracking-wide bg-gradient-to-r from-cyan-300 via-cyan-400 to-blue-400 bg-clip-text text-transparent">Thinking Process</span>
                    <span class="flex h-2 w-2 relative thinking-pulse-dot">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-cyan-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-cyan-400 shadow-[0_0_6px_rgba(6,182,212,0.8)]"></span>
                    </span>
                </span>
                <span class="flex items-center gap-2 text-[0.65rem] text-slate-500 font-medium tracking-wide uppercase">
                    <span class="thinking-status-label">Streaming</span>
                    <svg class="w-3.5 h-3.5 transition-transform duration-300 group-open:rotate-180 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                </span>
            </summary>
            <div class="px-5 pb-4 pt-3 border-t border-cyan-500/10 bg-[#070b14]/40">
                <div class="thinking-content text-sm text-slate-300 leading-relaxed markdown-content space-y-3"></div>
            </div>
        </details>

        <div class="streaming-text-container w-full"></div>
        <div class="file-choices-placeholder-container w-full"></div>
    `;
    chatWindow.appendChild(aiNode);
    chatWindow.scrollTop = chatWindow.scrollHeight;

    const traceAccordion = aiWrapper.querySelector('.trace-accordion');
    const traceContent = aiWrapper.querySelector('.trace-content');
    const thinkingAccordion = aiWrapper.querySelector('.thinking-accordion');
    const thinkingContent = aiWrapper.querySelector('.thinking-content');
    const loadingIndicator = aiWrapper.querySelector('.loading-indicator');
    const loadingText = aiWrapper.querySelector('.loading-text');
    const scrapingContainer = aiWrapper.querySelector('.scraping-container');
    const textContainer = aiWrapper.querySelector('.streaming-text-container');
    const fileChoicesContainer = aiWrapper.querySelector('.file-choices-placeholder-container');
    
    let markdownBuffer = "";
    let isFirstToken = true;

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
                        }

                        if (event === 'search_decided') {
                            traceAccordion.classList.remove('hidden');
                            traceAccordion.open = true;
                            
                            const truncatedQuery = data.query.length > 50 ? data.query.substring(0, 50) + '...' : data.query;
                            loadingText.textContent = `Searching web for: "${truncatedQuery}"...`;

                            const badge = document.createElement('span');
                            badge.className = "text-[0.65rem] px-2 py-0.5 rounded-full bg-blue-500/20 text-blue-400 border border-blue-500/30 flex items-center gap-1 normal-case tracking-normal shadow-sm";
                            badge.innerHTML = '<uk-icon icon="globe" class="w-3 h-3"></uk-icon> Web Search';
                            aiLabelContainer.appendChild(badge);

                            const triggerRow = document.createElement('div');
                            triggerRow.className = "text-xs text-blue-400 flex items-center gap-1.5 font-medium w-full";
                            triggerRow.innerHTML = `<uk-icon icon="globe" class="w-3.5 h-3.5 shrink-0"></uk-icon> <span class="truncate">Web Search Triggered: "${truncatedQuery}"</span>`;
                            traceContent.insertBefore(triggerRow, traceContent.firstChild);
                        }

                        if (event === 'cache_used') {
                            traceAccordion.classList.remove('hidden');
                            traceAccordion.open = true;

                            const badge = document.createElement('span');
                            badge.className = "text-[0.65rem] px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-400 border border-amber-500/30 flex items-center gap-1 normal-case tracking-normal shadow-sm";
                            badge.innerHTML = '<uk-icon icon="zap" class="w-3 h-3"></uk-icon> Memory Cached';
                            aiLabelContainer.appendChild(badge);

                            const cacheRow = document.createElement('div');
                            cacheRow.className = "text-xs text-amber-400 flex items-center gap-1.5 font-medium w-full";
                            cacheRow.innerHTML = '<uk-icon icon="zap" class="w-3.5 h-3.5 shrink-0"></uk-icon> <span>Memory Cache matched successfully</span>';
                            traceContent.insertBefore(cacheRow, traceContent.firstChild);
                        }

                        if (event === 'ask_user') {
                            state.isGenerating = false;
                            aiWrapper.remove();

                            const tplAsk = document.getElementById('tpl-ask-user');
                            const askNode = tplAsk.content.cloneNode(true);
                            const askWrapper = askNode.querySelector('.cache-prompt-bubble');

                            askNode.querySelector('.ask-topic').textContent = `"${data.query_text}"`;

                            const btnUse = askNode.querySelector('.btn-use-cache');
                            const btnForce = askNode.querySelector('.btn-force-live');
 
                            btnUse.onclick = function() {
                                askWrapper.remove();
                                const newForm = new FormData();
                                newForm.append('session_id', data.session_id);
                                newForm.append('q', originalMessage);
                                newForm.append('cache_action', 'use_cache');
                                newForm.append('cache_key', data.cache_key);
                                streamResponse(newForm, originalMessage);
                            };

                            btnForce.onclick = function() {
                                askWrapper.remove();
                                const newForm = new FormData();
                                newForm.append('session_id', data.session_id);
                                newForm.append('q', originalMessage);
                                newForm.append('cache_action', 'force_live');
                                newForm.append('cache_key', data.cache_key);
                                streamResponse(newForm, originalMessage);
                            };

                            chatWindow.appendChild(askNode);
                            chatWindow.scrollTop = chatWindow.scrollHeight;
                            return;
                        }

                        if (event === 'scraping_start') {
                            loadingText.textContent = "Extracting knowledge...";
                            scrapingContainer.classList.remove('hidden');
                            
                            const linkRow = document.createElement('a');
                            linkRow.href = data.url;
                            linkRow.target = "_blank";
                            linkRow.className = "flex items-center gap-2 text-xs text-slate-400 bg-slate-900/50 p-2 rounded border border-slate-700/50 hover:bg-slate-800/50 hover:text-emerald-400 transition-colors block w-full";
                            linkRow.setAttribute('data-url', data.url);
                            linkRow.innerHTML = `
                                <span class="uk-spinner uk-spinner-xs animate-spin text-cyan-500 shrink-0" uk-spinner="ratio: 0.5"></span>
                                <span class="truncate max-w-full">${data.url}</span>
                            `;
                            scrapingContainer.appendChild(linkRow);
                            chatWindow.scrollTop = chatWindow.scrollHeight;
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
                            chatWindow.scrollTop = chatWindow.scrollHeight;
                        }

                        if (event === 'status') {
                            loadingText.textContent = data.text;
                        }

                        if (event === 'file_choices') {
                            renderFileChoices(data, fileChoicesContainer, chatWindow);
                        }

                        if (event === 'reasoning') {
                            if (loadingIndicator && loadingIndicator.parentNode) {
                                loadingIndicator.remove();
                            }
                            thinkingAccordion.classList.remove('hidden');
                            renderThinkingContent(thinkingContent, thinkingContent.textContent + data.chunk);
                            chatWindow.scrollTop = chatWindow.scrollHeight;
                        }

                        if (event === 'token') {
                            if (isFirstToken) {
                                isFirstToken = false;
                                traceAccordion.open = false;
                                if (loadingIndicator && loadingIndicator.parentNode) {
                                    loadingIndicator.remove();
                                }
                                window.processedBlockIds = new Set();
                            }

                            markdownBuffer += data.chunk;

                            let displayBuffer = markdownBuffer;
                            let thinkingText = "";
                            let finalText = "";

                            let thinkStart = -1;
                            let thinkEnd = -1;
                            let thinkTagLen = 0;
                            let closeTagLen = 0;

                            // Try Gemma format first: <|channel>thought ... <channel|>
                            thinkStart = displayBuffer.indexOf("<|channel>thought");
                            if (thinkStart !== -1) {
                                thinkTagLen = 17;
                                thinkEnd = displayBuffer.indexOf("<channel|>", thinkStart + thinkTagLen);
                                closeTagLen = 10;
                            }

                            // Fall back to DeepSeek format: <think> ... </think>
                            if (thinkStart === -1) {
                                thinkStart = displayBuffer.indexOf("<think>");
                                if (thinkStart !== -1) {
                                    thinkTagLen = 7;
                                    thinkEnd = displayBuffer.indexOf("</think>", thinkStart + thinkTagLen);
                                    closeTagLen = 8;
                                }
                            }

                            // Handle partial: close tag arrived before open tag in buffer
                            if (thinkStart === -1) {
                                let gemmaEnd = displayBuffer.indexOf("<channel|>");
                                if (gemmaEnd !== -1) {
                                    thinkStart = 0;
                                    thinkTagLen = 0;
                                    thinkEnd = gemmaEnd;
                                    closeTagLen = 10;
                                } else {
                                    let dsEnd = displayBuffer.indexOf("</think>");
                                    if (dsEnd !== -1) {
                                        thinkStart = 0;
                                        thinkTagLen = 0;
                                        thinkEnd = dsEnd;
                                        closeTagLen = 8;
                                    }
                                }
                            }

                            if (thinkStart !== -1) {
                                thinkingAccordion.classList.remove('hidden');
                                if (thinkEnd !== -1) {
                                    thinkingText = displayBuffer.substring(thinkStart + thinkTagLen, thinkEnd);
                                    finalText = displayBuffer.substring(thinkEnd + closeTagLen);
                                    thinkingAccordion.open = false;

                                    const summaryLabel = thinkingAccordion.querySelector('summary span:first-of-type');
                                    const pulseDot = thinkingAccordion.querySelector('.thinking-pulse-dot');
                                    const statusLabel = thinkingAccordion.querySelector('.thinking-status-label');
                                    if (summaryLabel && !summaryLabel.innerHTML.includes('Complete')) {
                                        summaryLabel.className = 'flex items-center gap-2 text-slate-400';
                                        summaryLabel.innerHTML = '<uk-icon icon="check" class="w-3.5 h-3.5 text-emerald-500"></uk-icon> Thought Process Complete';
                                    }
                                    if (pulseDot) pulseDot.classList.add('hidden');
                                    if (statusLabel) statusLabel.textContent = 'Complete';
                                } else {
                                    thinkingText = displayBuffer.substring(thinkStart + thinkTagLen);
                                    thinkingAccordion.open = true;
                                }

                                thinkingContent.textContent = thinkingText;
                                renderThinkingContent(thinkingContent, thinkingText);
                            } else {
                                finalText = displayBuffer;
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
                                        
                                        if (!window.processedBlockIds.has(blockId)) {
                                            window.processedBlockIds.add(blockId);
                                            window.commitBlockEditDirectly(blockId, finalContent);
                                        }
                                        hasAppliedEdit = true;
                                        parseBuffer = parseBuffer.substring(0, startIndex) + parseBuffer.substring(endIndex + 9);
                                    } else {
                                        let partialContent = parseBuffer.substring(tagEndIndex + 2).trim();
                                        let nextTagIndex = partialContent.indexOf('<update id="');
                                        if (nextTagIndex !== -1) {
                                            partialContent = partialContent.substring(0, nextTagIndex).trim();
                                        }

                                        window.streamUpdateBlockContent(blockId, partialContent);
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

                                const cursorHtml = '<span class="animate-pulse text-cyan-400 font-bold ml-0.5 select-none inline-block">▍</span>';
                                
                                textContainer.innerHTML = htmlContent + cursorHtml;
                                textContainer.querySelectorAll('pre code').forEach((block) => {
                                    hljs.highlightElement(block);
                                });
                            }
 
                            aiBubble.setAttribute('data-raw', markdownBuffer);
 
                            if (payload.done) {
                                window.evaluateStreamCompletion(hasAppliedEdit, aiBubble, textContainer);
                            }
                            
                            chatWindow.scrollTop = chatWindow.scrollHeight;
                        }

                        if (event === 'done') {
                            const cursor = textContainer.querySelector('.animate-pulse');
                            if (cursor) cursor.remove();

                            if (loadingIndicator && loadingIndicator.parentNode) {
                                loadingIndicator.remove();
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

                            chatWindow.scrollTop = chatWindow.scrollHeight;
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