let activeTab = currentActiveTab || 'chats';

// Controller to handle switching sidebar panels cleanly
function switchSidebarTab(tabName) {
    activeTab = tabName;
    
    // Hide panels
    document.getElementById('panel-chats').classList.add('hidden');
    document.getElementById('panel-memories').classList.add('hidden');
    document.getElementById('panel-queries').classList.add('hidden');

    // Reset tab navigation styles
    ['chats', 'memories', 'queries'].forEach(t => {
        const btn = document.getElementById(`tab-btn-${t}`);
        if (btn) {
            btn.className = "flex-1 py-2 rounded-md transition-all text-center flex items-center justify-center gap-1 text-slate-400 hover:text-slate-200 cursor-pointer";
        }
    });

    // Display targets
    const targetPanel = document.getElementById(`panel-${tabName}`);
    if (targetPanel) {
        targetPanel.classList.remove('hidden');
    }

    const activeBtn = document.getElementById(`tab-btn-${tabName}`);
    if (activeBtn) {
        activeBtn.className = "flex-1 py-2 rounded-md transition-all text-center flex items-center justify-center gap-1 bg-slate-800 text-white shadow-md font-semibold border border-slate-700/50 cursor-pointer";
    }

    // Preserve parameters inside window history
    const url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    window.history.replaceState({}, '', url);

    // Sync active settings form redirect parameters
    const settingsForm = document.querySelector('#settings-modal form');
    if (settingsForm) {
        const actionUrl = new URL(settingsForm.action, window.location.origin);
        actionUrl.searchParams.set('tab', tabName);
        settingsForm.action = actionUrl.pathname + actionUrl.search;
    }
}

// Inline edit triggers for memories tab
function enableMemoryEdit(id) {
    document.getElementById(`memory-view-${id}`).classList.add('hidden');
    document.getElementById(`memory-edit-${id}`).classList.remove('hidden');
}

function disableMemoryEdit(id) {
    document.getElementById(`memory-view-${id}`).getBoundingClientRect();
    document.getElementById(`memory-view-${id}`).classList.remove('hidden');
    document.getElementById(`memory-edit-${id}`).classList.add('hidden');
}

// Parse markdown targets
function parseMarkdownElements() {
    document.querySelectorAll('.markdown-rendered:not(.parsed)').forEach(function(el) {
        el.innerHTML = marked.parse(el.getAttribute('data-markdown'));
        el.classList.add('parsed');
    });
}

// Clipboard Copy Utility
function copyToClipboard(button) {
    const container = button.closest('.flex-col');
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
    }).catch(err => {
        console.error('Failed to copy text: ', err);
    });
}

// Initial state setups
parseMarkdownElements();
switchSidebarTab(activeTab);

const chatWindow = document.getElementById('chatWindow');
if (chatWindow) {
    chatWindow.scrollTop = chatWindow.scrollHeight;
}

// Image preview handling
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

// Map keydown action: enter submission
const textareaInput = document.getElementById('q');
if (textareaInput) {
    textareaInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            document.getElementById('chatForm').dispatchEvent(new Event('submit'));
        }
    });
}

// Submit via AJAX
async function handleChatSubmit(e) {
    e.preventDefault();
    
    const form = document.getElementById('chatForm');
    const inputField = document.getElementById('q');
    const fileInput = document.getElementById('imageInput');
    
    const message = inputField.value.trim();
    const file = fileInput.files[0];
    
    if (!message && !file) return;

    const emptyState = document.querySelector('.flex.flex-col.items-center.opacity-80');
    if (emptyState) emptyState.remove();

    const formData = new FormData(form);

    inputField.value = '';
    inputField.style.height = '';
    removeImage();

    let fileDataUrl = null;
    if (file) {
        fileDataUrl = document.getElementById('image-preview').src;
    }

    // Construct User Bubble DOM programmatically to maintain raw attributes safely
    const userWrapper = document.createElement('div');
    userWrapper.className = "flex flex-col w-full max-w-[92%] mx-auto space-y-1 items-end mb-4";

    const userLabelRow = document.createElement('div');
    userLabelRow.className = "flex items-center gap-2 flex-row-reverse mr-1";

    const userLabel = document.createElement('span');
    userLabel.className = "text-xs text-slate-500 font-semibold uppercase tracking-wider";
    userLabel.textContent = "You";

    const userCopyBtn = document.createElement('button');
    userCopyBtn.className = "text-slate-500 hover:text-cyan-400 p-0.5 rounded transition-colors duration-150 cursor-pointer flex items-center justify-center";
    userCopyBtn.setAttribute('title', 'Copy message');
    userCopyBtn.setAttribute('onclick', 'copyToClipboard(this)');
    userCopyBtn.innerHTML = '<uk-icon icon="copy" class="w-3.5 h-3.5"></uk-icon>';

    userLabelRow.appendChild(userLabel);
    userLabelRow.appendChild(userCopyBtn);

    const userBubble = document.createElement('div');
    userBubble.className = "chat-user rounded-2xl rounded-tr-sm px-5 py-4 text-[0.95rem] leading-relaxed max-w-[85%]";
    userBubble.setAttribute('data-raw', message);

    if (fileDataUrl) {
        const img = document.createElement('img');
        img.src = fileDataUrl;
        img.className = "max-w-xs rounded-lg mb-3 border border-white/20 shadow-md block";
        img.alt = "Upload";
        userBubble.appendChild(img);
    }

    const textSpan = document.createElement('span');
    textSpan.innerHTML = message.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/\n/g, '<br>');
    userBubble.appendChild(textSpan);

    userWrapper.appendChild(userLabelRow);
    userWrapper.appendChild(userBubble);
    chatWindow.appendChild(userWrapper);
    
    let typingHtml = `
        <div class="flex flex-col w-full max-w-[92%] mx-auto space-y-1 items-start mb-4" id="typingIndicator">
            <span class="text-xs text-slate-500 font-semibold uppercase tracking-wider ml-1">Assistant</span>
            <div class="chat-assistant rounded-2xl rounded-tl-sm px-5 py-4 text-sm leading-relaxed max-w-[85%] border border-cyan-500/30 shadow-[0_0_15px_rgba(6,182,212,0.15)]">
                <div class="flex items-center gap-3 text-cyan-400 font-medium">
                    <span class="uk-spinner uk-spinner-sm animate-spin" uk-spinner="ratio: 0.8"></span>
                    Analyzing data...
                </div>
            </div>
        </div>
    `;
    chatWindow.insertAdjacentHTML('beforeend', typingHtml);
    chatWindow.scrollTop = chatWindow.scrollHeight;

    try {
        const response = await fetch('index.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: formData
        });
        
        const data = await response.json();
        const indicator = document.getElementById('typingIndicator');
        if (indicator) indicator.remove();
        
        if (data.status === 'success') {
            const aiWrapper = document.createElement('div');
            aiWrapper.className = "flex flex-col w-full max-w-[92%] mx-auto space-y-1 items-start mb-4";
            
            const aiLabelRow = document.createElement('div');
            aiLabelRow.className = "flex items-center gap-2 ml-1";
            
            const label = document.createElement('span');
            label.className = "text-xs text-slate-500 font-semibold uppercase tracking-wider";
            label.textContent = "Assistant";
            
            const aiCopyBtn = document.createElement('button');
            aiCopyBtn.className = "text-slate-500 hover:text-cyan-400 p-0.5 rounded transition-colors duration-150 cursor-pointer flex items-center justify-center";
            aiCopyBtn.setAttribute('title', 'Copy message');
            aiCopyBtn.setAttribute('onclick', 'copyToClipboard(this)');
            aiCopyBtn.innerHTML = '<uk-icon icon="copy" class="w-3.5 h-3.5"></uk-icon>';
            
            aiLabelRow.appendChild(label);
            aiLabelRow.appendChild(aiCopyBtn);
            
            const bubble = document.createElement('div');
            bubble.className = "chat-assistant rounded-2xl rounded-tl-sm px-5 py-4 text-[0.95rem] leading-relaxed max-w-[85%] markdown-content markdown-rendered";
            bubble.setAttribute('data-markdown', data.message);
            bubble.setAttribute('data-raw', data.message);
            
            aiWrapper.appendChild(aiLabelRow);
            aiWrapper.appendChild(bubble);
            chatWindow.appendChild(aiWrapper);
            
            parseMarkdownElements();

            if (data.title) {
                const headerTitle = document.querySelector('header h2');
                if (headerTitle) headerTitle.innerHTML = `<uk-icon icon="message-square" class="w-5 h-5 text-cyan-500"></uk-icon> ${data.title}`;
                
                const activeItemTitle = document.querySelector('.group.bg-slate-800\\/80 .session-title');
                if (activeItemTitle) activeItemTitle.textContent = data.title;
            }
        } else {
            alert('Error: ' + data.message);
        }
    } catch (error) {
        const indicator = document.getElementById('typingIndicator');
        if (indicator) indicator.remove();
        alert('Connection failed.');
    }
    
    chatWindow.scrollTop = chatWindow.scrollHeight;
}