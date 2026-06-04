let activeTab = currentActiveTab || 'chats';

function switchSidebarTab(tabName) {
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

function parseMarkdownElements() {
    document.querySelectorAll('.markdown-rendered:not(.parsed)').forEach(function(el) {
        el.innerHTML = marked.parse(el.getAttribute('data-markdown'));
        el.classList.add('parsed');
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

async function streamResponse(formData, originalMessage) {
    const tplAi = document.getElementById('tpl-ai-message');
    const aiNode = tplAi.content.cloneNode(true);
    const aiWrapper = aiNode.querySelector('.ai-wrapper');
    const aiBubble = aiNode.querySelector('.ai-bubble');
    const aiLabelContainer = aiNode.querySelector('.ai-label-container');
    
    aiBubble.innerHTML = `
        <div class="flex items-center gap-3 text-cyan-400 font-medium loading-indicator">
            <span class="uk-spinner uk-spinner-sm animate-spin" uk-spinner="ratio: 0.8"></span>
            <span class="loading-text">Initializing...</span>
        </div>
        <div class="scraping-container flex flex-col gap-2 mt-3 hidden"></div>
    `;
    chatWindow.appendChild(aiNode);
    chatWindow.scrollTop = chatWindow.scrollHeight;

    const loadingIndicator = aiWrapper.querySelector('.loading-indicator');
    const loadingText = aiWrapper.querySelector('.loading-text');
    const scrapingContainer = aiWrapper.querySelector('.scraping-container');
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

                        if (event === 'title_updated') {
                            const headerTitle = document.querySelector('header h2');
                            if (headerTitle) headerTitle.innerHTML = `<uk-icon icon="message-square" class="w-5 h-5 text-cyan-500"></uk-icon> ${data.title}`;
                            const activeItemTitle = document.querySelector('.group.bg-slate-800\\/80 .session-title');
                            if (activeItemTitle) activeItemTitle.textContent = data.title;
                        }

                        if (event === 'search_decided') {
                            loadingText.textContent = `Searching web for: "${data.query}"...`;
                            const badge = document.createElement('span');
                            badge.className = "text-[0.65rem] px-2 py-0.5 rounded-full bg-blue-500/20 text-blue-400 border border-blue-500/30 flex items-center gap-1 normal-case tracking-normal shadow-sm";
                            badge.innerHTML = '<uk-icon icon="globe" class="w-3 h-3"></uk-icon> Web Search';
                            aiLabelContainer.appendChild(badge);
                        }

                        if (event === 'cache_used') {
                            const badge = document.createElement('span');
                            badge.className = "text-[0.65rem] px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-400 border border-amber-500/30 flex items-center gap-1 normal-case tracking-normal shadow-sm";
                            badge.innerHTML = '<uk-icon icon="zap" class="w-3 h-3"></uk-icon> Memory Cached';
                            aiLabelContainer.appendChild(badge);
                        }

                        if (event === 'ask_user') {
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
                            
                            const linkRow = document.createElement('div');
                            linkRow.className = "flex items-center gap-2 text-xs text-slate-400 bg-slate-900/50 p-2 rounded border border-slate-700/50";
                            linkRow.setAttribute('data-url', data.url);
                            linkRow.innerHTML = `
                                <span class="uk-spinner uk-spinner-xs animate-spin text-cyan-500" uk-spinner="ratio: 0.5"></span>
                                <span class="truncate max-w-[200px]">${data.url}</span>
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
                                row.insertAdjacentHTML('afterbegin', '<uk-icon icon="check-circle" class="w-3.5 h-3.5"></uk-icon>');
                            }
                        }

                        if (event === 'condensing') {
                            loadingText.textContent = "Condensing information...";
                        }

                        if (event === 'generating') {
                            loadingText.textContent = "Thinking...";
                            if (scrapingContainer.children.length > 0) {
                                scrapingContainer.classList.add('mb-4', 'pb-4', 'border-b', 'border-slate-700/50');
                            }
                            aiBubble.classList.add('shadow-[0_0_15px_rgba(6,182,212,0.15)]', 'border-cyan-500/30');
                            chatWindow.scrollTop = chatWindow.scrollHeight;
                        }

                        if (event === 'token') {
                            if (isFirstToken) {
                                isFirstToken = false;
                                if (loadingIndicator && loadingIndicator.parentNode) {
                                    loadingIndicator.remove();
                                }
                            }

                            markdownBuffer += data.chunk;
                            let htmlContent = marked.parse(markdownBuffer);
                            const cursorHtml = '<span class="animate-pulse text-cyan-400 font-bold ml-0.5 select-none inline-block">▍</span>';
                            
                            let existingScraping = aiBubble.querySelector('.scraping-container');
                            if (existingScraping) {
                                const tempDiv = document.createElement('div');
                                tempDiv.innerHTML = htmlContent + cursorHtml;
                                
                                Array.from(aiBubble.childNodes).forEach(child => {
                                    if (child !== existingScraping) child.remove();
                                });
                                
                                Array.from(tempDiv.childNodes).forEach(child => {
                                    aiBubble.appendChild(child);
                                });
                            } else {
                                aiBubble.innerHTML = htmlContent + cursorHtml;
                            }
                            
                            aiBubble.setAttribute('data-raw', markdownBuffer);
                            chatWindow.scrollTop = chatWindow.scrollHeight;
                        }

                        if (event === 'done') {
                            const cursor = aiBubble.querySelector('.animate-pulse');
                            if (cursor) {
                                cursor.remove();
                            }
                            if (loadingIndicator && loadingIndicator.parentNode) {
                                loadingIndicator.remove();
                            }
                            if (scrapingContainer && scrapingContainer.children.length === 0) {
                                scrapingContainer.remove();
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
    }
}

async function handleChatSubmit(e) {
    e.preventDefault();
    
    const form = document.getElementById('chatForm');
    const inputField = document.getElementById('q');
    const fileInput = document.getElementById('imageInput');
    
    const message = inputField.value.trim();
    const file = fileInput.files[0];
    
    if (!message && !file) return;

    const emptyState = document.getElementById('empty-state');
    if (emptyState) emptyState.remove();

    const formData = new FormData(form);

    inputField.value = '';
    inputField.style.height = '';
    removeImage();

    let fileDataUrl = null;
    if (file) {
        fileDataUrl = document.getElementById('image-preview').src;
    }

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