/**
 * @file js/streamer.js
 * @description Event Streamer. Handles server connections, reads streamed token payloads, and renders trace accordion elements.
 */

import { state } from './state.js';
import { showCondensationModal, updateTokenCounter } from './ui.js';

export async function streamResponse(formData, originalMessage) {
    state.isGenerating = true;
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
        <div class="streaming-text-container w-full"></div>
    `;
    chatWindow.appendChild(aiNode);
    chatWindow.scrollTop = chatWindow.scrollHeight;

    const traceAccordion = aiWrapper.querySelector('.trace-accordion');
    const traceContent = aiWrapper.querySelector('.trace-content');
    const loadingIndicator = aiWrapper.querySelector('.loading-indicator');
    const loadingText = aiWrapper.querySelector('.loading-text');
    const scrapingContainer = aiWrapper.querySelector('.scraping-container');
    const textContainer = aiWrapper.querySelector('.streaming-text-container');
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

                        if (event === 'token') {
                            if (isFirstToken) {
                                isFirstToken = false;
                                traceAccordion.open = false;
                                if (loadingIndicator && loadingIndicator.parentNode) {
                                    loadingIndicator.remove();
                                }
                            }

                            markdownBuffer += data.chunk;
                            let htmlContent = marked.parse(markdownBuffer);
                            const cursorHtml = '<span class="animate-pulse text-cyan-400 font-bold ml-0.5 select-none inline-block">▍</span>';
                            
                            textContainer.innerHTML = htmlContent + cursorHtml;
                            textContainer.querySelectorAll('pre code').forEach((block) => {
                                hljs.highlightElement(block);
                            });
                            
                            aiBubble.setAttribute('data-raw', markdownBuffer);
                            chatWindow.scrollTop = chatWindow.scrollHeight;
                        }

                        if (event === 'done') {
                            state.isGenerating = false;
                            const cursor = textContainer.querySelector('.animate-pulse');
                            if (cursor) {
                                cursor.remove();
                            }
                            if (loadingIndicator && loadingIndicator.parentNode) {
                                loadingIndicator.remove();
                            }
                            
                            if (data.total_session_tokens && typeof maxTokensLimit !== 'undefined') {
                                updateTokenCounter(data.total_session_tokens, maxTokensLimit);
                            }
                            
                            chatWindow.scrollTop = chatWindow.scrollHeight;
                        }

                    } catch (e) {}
                }
            }
        }

        state.isGenerating = false;
        aiBubble.classList.add('parsed');

    } catch (error) {
        state.isGenerating = false;
        console.error("Stream Error:", error);
        if (loadingText) loadingText.textContent = "Connection failed.";
        const spinner = loadingIndicator ? loadingIndicator.querySelector('.uk-spinner') : null;
        if (spinner) spinner.remove();
        if (loadingIndicator) loadingIndicator.classList.replace('text-cyan-400', 'text-rose-400');
    }
}