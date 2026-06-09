// src/js/streamer.js

import { state } from './state.js';
import { showCondensationModal, updateTokenCounter } from './ui.js';

function cleanAssistantStreamText(html) {
    if (!html) return '';
    
    let clean = html.replace(/<pre><code class="language-json">[\s\S]*?"tool"\s*:\s*"search_files"[\s\S]*?<\/code><\/pre>/gi, '');
    clean = clean.replace(/<pre><code>[\s\S]*?"tool"\s*:\s*"search_files"[\s\S]*?<\/code><\/pre>/gi, '');
    clean = clean.replace(/\{[\s\S]*?"tool"\s*:\s*"search_files"[\s\S]*?\}/gi, '');
    clean = clean.replace(/Checking files\.\.\./gi, '');
    clean = clean.replace(/<p>\s*<\/p>/gi, '');
    
    return clean;
}

export async function streamResponse(formData, originalMessage) {
    state.isGenerating = true;
    
    // 1. IMMEDIATELY LOCK THE EDITOR DRAWER AT THE START OF THE THINKING PHASE
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

                        if (event === 'file_choices') {
                            renderFileChoices(data, textContainer, chatWindow);
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
                            let hasAppliedEdit = false;

                            // 2. HIGH-SPEED STATE MACHINE PARSER (ZERO BACKTRACKING)
                            while (true) {
                                let startIndex = displayBuffer.indexOf('<update id="');
                                if (startIndex === -1) break;

                                let tagEndIndex = displayBuffer.indexOf('">', startIndex);
                                if (tagEndIndex === -1) break;

                                let blockId = displayBuffer.substring(startIndex + 12, tagEndIndex);
                                let endIndex = displayBuffer.indexOf('</update>', tagEndIndex);

                                if (endIndex !== -1) {
                                    // A. COMPLETED UPDATE: Parse final content and commit exactly once to PHP
                                    let finalContent = displayBuffer.substring(tagEndIndex + 2, endIndex).trim();
                                    
                                    if (!window.processedBlockIds.has(blockId)) {
                                        window.processedBlockIds.add(blockId);
                                        window.commitBlockEditDirectly(blockId, finalContent);
                                    }
                                    hasAppliedEdit = true;
                                    
                                    // Remove completed update block from the conversational chat display
                                    displayBuffer = displayBuffer.substring(0, startIndex) + displayBuffer.substring(endIndex + 9);
                                } else {
                                    // B. INCOMPLETE UPDATE: Stream partial tokens directly to editor container
                                    let partialContent = displayBuffer.substring(tagEndIndex + 2).trim();
                                    
                                    // Check if another tag opens inside (self-healing boundary protection)
                                    let nextTagIndex = partialContent.indexOf('<update id="');
                                    if (nextTagIndex !== -1) {
                                        partialContent = partialContent.substring(0, nextTagIndex).trim();
                                    }

                                    window.streamUpdateBlockContent(blockId, partialContent);
                                    hasAppliedEdit = true;
                                    
                                    // Omit streaming content from conversational chat bubble view
                                    displayBuffer = displayBuffer.substring(0, startIndex);
                                    break;
                                }
                            }

                            // 3. Render Clean Conversation Text
                            let htmlContent = marked.parse(displayBuffer);
                            htmlContent = cleanAssistantStreamText(htmlContent);
                            
                            if (window.parseInlineFiles) {
                                htmlContent = window.parseInlineFiles(htmlContent);
                            }
                            
                            const cursorHtml = '<span class="animate-pulse text-cyan-400 font-bold ml-0.5 select-none inline-block">▍</span>';
                            
                            textContainer.innerHTML = htmlContent + cursorHtml;
                            textContainer.querySelectorAll('pre code').forEach((block) => {
                                hljs.highlightElement(block);
                            });
                            
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
        
        // 4. ALWAYS RELEASE THE WORKSPACE LOCK WHEN THE STREAM DISCONNECTS OR COMPLETES
        const lockOverlay = document.getElementById('editor-lock-overlay');
        if (lockOverlay) {
            lockOverlay.classList.remove('opacity-100', 'pointer-events-auto');
            lockOverlay.classList.add('opacity-0', 'pointer-events-none');
        }
    }
}

// Keep the rest of your file-accordion helper functions identical
function renderFileChoices(data, textContainer, chatWindow) {
    const files = data.files;
    if (!files || files.length === 0) return;

    const bubble = textContainer.closest('.chat-assistant');
    if (bubble) {
        const checkElement = Array.from(bubble.childNodes).find(node => node.nodeType === Node.TEXT_NODE && node.textContent.includes('Checking files'));
        if (checkElement) checkElement.remove();
    }

    const choiceContainer = document.createElement('div');
    choiceContainer.className = "flex flex-col gap-2 p-4 bg-[#0d1321]/85 border border-slate-800/80 rounded-xl mt-4 select-none relative w-full file-accordion-list";
    
    const header = document.createElement('div');
    header.className = "text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2 text-left flex items-center gap-1.5 border-b border-slate-850 pb-2";
    header.innerHTML = `
        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><path d="m21 16-4 4-4-4"/><path d="M17 20V4"/><path d="m3 8 4-4 4 4"/><path d="M7 4v16"/></svg>
        Multiple matching files found for '${data.query}'
    `;
    choiceContainer.appendChild(header);

    files.forEach(file => {
        const isImage = file.file_type === 'image';
        
        const fileItem = document.createElement('div');
        fileItem.className = "file-accordion-item border border-slate-850 rounded-lg overflow-hidden bg-slate-900/20";
        
        fileItem.innerHTML = `
            <div class="flex items-center justify-between p-3.5 cursor-pointer bg-slate-950/40 hover:bg-slate-950/80 transition-colors duration-150" onclick="window.toggleFileAccordion(this)">
                <div class="flex items-center gap-3 truncate flex-1 min-w-0">
                    <span class="flex items-center justify-center shrink-0 w-8 h-8 bg-slate-950 rounded border border-cyan-500/10 text-cyan-400">
                        ${isImage 
                            ? '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>'
                            : '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>'}
                    </span>
                    <div class="truncate flex-1 min-w-0 text-left">
                        <div class="text-[11px] font-bold text-slate-200 truncate tracking-wide">${file.generated_title}</div>
                        <div class="text-[9px] text-slate-500 truncate">${file.original_name}</div>
                    </div>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="accordion-arrow text-slate-500 shrink-0 ml-3"><polyline points="9 18 15 12 9 6"/></svg>
            </div>
            
            <div class="file-accordion-content bg-[#070b13]/55">
                <div class="p-4 border-t border-slate-950 flex flex-col gap-3">
                    ${isImage 
                        ? `<img src="uploads/${file.physical_name}" class="w-full h-auto max-h-72 object-contain rounded-lg border border-slate-850 bg-slate-950/40 p-1 block" alt="${file.generated_title}"/>`
                        : `<div class="bg-slate-950/95 border border-slate-850 rounded-lg p-3 text-xs font-mono text-slate-300 max-h-60 overflow-y-auto whitespace-pre-wrap text-left relative min-h-[60px]">
                             <span class="lazy-loading-indicator text-cyan-400 flex items-center gap-2 font-sans font-medium">
                                 <svg class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                 Reading full file contents...
                             </span>
                             <pre class="document-lazy-load hidden text-[11px] leading-relaxed select-text" data-file="${file.physical_name}" data-loaded="false"></pre>
                           </div>`}
                    
                    <div class="flex items-center gap-2 select-none border-t border-slate-850/60 pt-3">
                        <button type="button" class="flex items-center justify-center gap-1.5 px-3 py-1.5 text-[10px] font-extrabold tracking-wider uppercase bg-slate-800 hover:bg-slate-750 text-slate-300 hover:text-cyan-400 border border-slate-700 hover:border-cyan-500/30 rounded-lg transition-all cursor-pointer" onclick="window.showFileInExplorer('${file.physical_name}', this)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
                            Show in Explorer
                        </button>
                        <button type="button" class="flex items-center justify-center gap-1.5 px-3 py-1.5 text-[10px] font-extrabold tracking-wider uppercase bg-cyan-950/40 hover:bg-cyan-900/60 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 rounded-lg transition-all cursor-pointer" onclick="window.appendFileFromAccordion(this, ${JSON.stringify(file).replace(/"/g, '&quot;')})">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-cyan-400"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>
                            Append to Chat
                        </button>
                        ${!isImage ? `
                        <button type="button" class="flex items-center justify-center gap-1.5 px-3 py-1.5 text-[10px] font-extrabold tracking-wider uppercase bg-blue-950/40 hover:bg-blue-900/60 text-blue-400 border border-blue-500/20 hover:border-blue-400/50 rounded-lg transition-all cursor-pointer" onclick="window.openEditorDrawer('${file.physical_name}', this)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-blue-400"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            Edit Document
                        </button>
                        ` : ''}
                    </div>
                </div>
            </div>
        `;
        choiceContainer.appendChild(fileItem);
    });

    textContainer.appendChild(choiceContainer);
    chatWindow.scrollTop = chatWindow.scrollHeight;
}

window.renderFileChoices = renderFileChoices;