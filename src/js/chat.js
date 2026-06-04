let activeTab = currentActiveTab || 'chats';
let isGenerating = false;
let pastedImageFile = null;
let pendingFormData = null;
let pendingMessage = null;

window.addEventListener('beforeunload', function (e) {
    if (isGenerating) {
        e.preventDefault();
        e.returnValue = '';
    }
});

function switchSidebarTab(tabName) {
    if (isGenerating) {
        const proceed = confirm("A prompt is currently in progress. Switching tabs now will void the computation. Do you want to proceed?");
        if (!proceed) {
            return;
        }
    }

    activeTab = tabName;
    
    document.getElementById('panel-chats').classList.add('hidden');
    document.getElementById('panel-memories').classList.add('hidden');
    document.getElementById('panel-queries').classList.add('hidden');

    ['chats', 'memories', 'queries'].forEach(t => {
        const btn = document.getElementById(`tab-btn-${t}`);
        if (btn) {
            btn.className = "flex-1 py-2 rounded-md transition-all text-center flex items-center justify-center gap-1 text-slate-400 hover:text-slate-200 cursor-pointer";
        }
    });

    const targetPanel = document.getElementById(`panel-${tabName}`);
    if (targetPanel) {
        targetPanel.classList.remove('hidden');
    }

    const activeBtn = document.getElementById(`tab-btn-${tabName}`);
    if (activeBtn) {
        activeBtn.className = "flex-1 py-2 rounded-md transition-all text-center flex items-center justify-center gap-1 bg-slate-800 text-white shadow-md font-semibold border border-slate-700/50 cursor-pointer";
    }

    const url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    window.history.replaceState({}, '', url);

    const settingsForm = document.querySelector('#settings-modal form');
    if (settingsForm) {
        const actionUrl = new URL(settingsForm.action, window.location.origin);
        actionUrl.searchParams.set('tab', tabName);
        settingsForm.action = actionUrl.pathname + actionUrl.search;
    }
}

function enableMemoryEdit(id) {
    document.getElementById(`memory-view-${id}`).classList.add('hidden');
    document.getElementById(`memory-edit-${id}`).classList.remove('hidden');
}

function disableMemoryEdit(id) {
    document.getElementById(`memory-view-${id}`).getBoundingClientRect();
    document.getElementById(`memory-view-${id}`).classList.remove('hidden');
    document.getElementById(`memory-edit-${id}`).classList.add('hidden');
}

window.addEventListener('paste', function (e) {
    const items = (e.clipboardData || e.originalEvent.clipboardData).items;
    
    for (let i = 0; i < items.length; i++) {
        if (items[i].type.indexOf('image') !== -1) {
            const blob = items[i].getAsFile();
            if (blob) {
                pastedImageFile = blob;
                
                const reader = new FileReader();
                reader.onload = function(event) {
                    document.getElementById('image-preview').src = event.target.result;
                    document.getElementById('image-preview-container').style.display = 'flex';
                    document.getElementById('q').removeAttribute('required');
                };
                reader.readAsDataURL(blob);
            }
        }
    }
});

function parseMarkdownElements() {
    document.querySelectorAll('.markdown-rendered:not(.parsed)').forEach(function(el) {
        el.innerHTML = marked.parse(el.getAttribute('data-markdown'));
        el.classList.add('parsed');
        el.querySelectorAll('pre code').forEach((block) => {
            hljs.highlightElement(block);
        });
    });
}

function copyToClipboard(button) {
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
    }).catch(err => {});
}

parseMarkdownElements();
switchSidebarTab(activeTab);

const chatWindow = document.getElementById('chatWindow');
if (chatWindow) {
    chatWindow.scrollTop = chatWindow.scrollHeight;
}

function previewImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('image-preview').src = e.target.result;
            document.getElementById('image-preview-container').style.display = 'flex';
        }
        reader.readAsDataURL(input.files[0]);
        document.getElementById('q').removeAttribute('required');
    }
}

function removeImage() {
    document.getElementById('imageInput').value = '';
    document.getElementById('image-preview-container').style.display = 'none';
    document.getElementById('q').setAttribute('required', 'required');
    pastedImageFile = null;
}

const textareaInput = document.getElementById('q');
if (textareaInput) {
    textareaInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            document.getElementById('chatForm').dispatchEvent(new Event('submit'));
        }
    });
}

function showCondensationModal(formData, originalMessage) {
    pendingFormData = formData;
    pendingMessage = originalMessage;
    
    document.getElementById('condensation-modal').classList.remove('hidden');
    document.getElementById('q').disabled = true;
    const submitBtn = document.querySelector('#chatForm button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;
}

function closeCondensationModal() {
    document.getElementById('condensation-modal').classList.add('hidden');
    document.getElementById('q').disabled = false;
    const submitBtn = document.querySelector('#chatForm button[type="submit"]');
    if (submitBtn) submitBtn.disabled = false;
}

async function bypassCondensation() {
    closeCondensationModal();
    if (pendingFormData) {
        pendingFormData.set('bypass_warning', '1');
        await streamResponse(pendingFormData, pendingMessage);
    }
}

async function confirmCondensation() {
    const modalContent = document.getElementById('condensation-modal-content');
    const modalLoading = document.getElementById('condensation-modal-loading');
    
    modalContent.classList.add('hidden');
    modalLoading.classList.remove('hidden');
    
    try {
        const formData = new FormData();
        formData.append('action', 'condense');
        formData.append('session_id', pendingFormData.get('session_id'));
        
        const response = await fetch('index.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.status === 'success') {
            closeCondensationModal();
            modalContent.classList.remove('hidden');
            modalLoading.classList.add('hidden');
            
            sessionStorage.setItem('pending_chat_prompt', pendingMessage);
            window.location.reload();
        } else {
            throw new Error(result.message || 'Failed to condense');
        }
    } catch (e) {
        alert("Something went wrong during condensation: " + e.message);
        modalContent.classList.remove('hidden');
        modalLoading.classList.add('hidden');
    }
}

function updateTokenCounter(current, max) {
    const counterContainer = document.getElementById('token-counter-container');
    const counterText = document.getElementById('token-counter-text');
    const counterBar = document.getElementById('token-counter-bar');
    
    if (!counterContainer || !counterText || !counterBar) return;
    
    counterContainer.classList.remove('hidden');
    
    const formattedCurrent = current.toLocaleString();
    const formattedMax = max.toLocaleString();
    
    counterText.textContent = `${formattedCurrent} / ${formattedMax}`;
    
    const percentage = Math.min((current / max) * 100, 100);
    counterBar.style.width = `${percentage}%`;
    
    if (percentage >= 80) {
        counterBar.className = "h-full bg-rose-500 transition-all duration-300";
        counterText.className = "text-rose-400 font-bold";
    } else if (percentage >= 50) {
        counterBar.className = "h-full bg-amber-500 transition-all duration-300";
        counterText.className = "text-amber-400 font-bold";
    } else {
        counterBar.className = "h-full bg-cyan-500 transition-all duration-300";
        counterText.className = "text-slate-200 font-bold";
    }
}

async function streamResponse(formData, originalMessage) {
    isGenerating = true;

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
                            isGenerating = false;
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
                            isGenerating = false;
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
                            isGenerating = false;
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

        isGenerating = false;
        aiBubble.classList.add('parsed');

    } catch (error) {
        isGenerating = false;
        console.error("Stream Error:", error);
        if (loadingText) loadingText.textContent = "Connection failed.";
        const spinner = loadingIndicator ? loadingIndicator.querySelector('.uk-spinner') : null;
        if (spinner) spinner.remove();
        if (loadingIndicator) loadingIndicator.classList.replace('text-cyan-400', 'text-rose-400');
    }
}

async function handleChatSubmit(e) {
    e.preventDefault();
    
    const form = document.getElementById('chatForm');
    const inputField = document.getElementById('q');
    const fileInput = document.getElementById('imageInput');
    
    const message = inputField.value.trim();
    const file = fileInput.files[0];
    
    if (!message && !file && !pastedImageFile) return;

    const emptyState = document.getElementById('empty-state');
    if (emptyState) emptyState.remove();

    let fileDataUrl = null;
    if (file || pastedImageFile) {
        fileDataUrl = document.getElementById('image-preview').src;
    }

    const formData = new FormData(form);
    if (pastedImageFile) {
        formData.append('image', pastedImageFile, 'pasted_image.png');
    }

    inputField.value = '';
    inputField.style.height = '';
    removeImage();

    const tplUser = document.getElementById('tpl-user-message');
    const userNode = tplUser.content.cloneNode(true);
    
    const userBubble = userNode.querySelector('.bubble-content');
    const msgText = userNode.querySelector('.msg-text');
    const imgElement = userNode.querySelector('.upload-img');
    
    userBubble.setAttribute('data-raw', message);
    msgText.innerHTML = message.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/\n/g, '<br>');
    
    if (fileDataUrl) {
        imgElement.src = fileDataUrl;
        imgElement.classList.remove('hidden');
    }
    
    chatWindow.appendChild(userNode);
    chatWindow.scrollTop = chatWindow.scrollHeight;

    await streamResponse(formData, message);
}

window.addEventListener('DOMContentLoaded', () => {
    if (typeof initialSessionTokens !== 'undefined' && typeof maxTokensLimit !== 'undefined') {
        updateTokenCounter(initialSessionTokens, maxTokensLimit);
    }
    
    const pendingPrompt = sessionStorage.getItem('pending_chat_prompt');
    if (pendingPrompt) {
        sessionStorage.removeItem('pending_chat_prompt');
        const textarea = document.getElementById('q');
        if (textarea) {
            textarea.value = pendingPrompt;
            textarea.style.height = '';
            textarea.style.height = textarea.scrollHeight + 'px';
            document.getElementById('chatForm').dispatchEvent(new Event('submit'));
        }
    }
});