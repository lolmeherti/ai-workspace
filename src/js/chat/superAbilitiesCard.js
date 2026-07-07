/**
 * @file js/chat/superAbilitiesCard.js
 * @description Renders the super_abilities card INSIDE the AI response bubble.
 * User selects tools via click-to-toggle rows (no checkboxes) with border activation.
 */

const TOOL_DEFS = {
    search_web:       { label: 'Web Search',      icon: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>', accent: 'cyan' },
    search_files:     { label: 'File Search',      icon: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>', accent: 'blue' },
    search_memories:  { label: 'Memory Search',    icon: '<path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2z"/><polyline points="12 6 12 12 16 14"/>', accent: 'purple' },
    calendar:         { label: 'Calendar',         icon: '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>', accent: 'amber' },
};

const ACCENT_CLASSES = {
    cyan:    { border: 'border-cyan-500/60',    shadow: 'shadow-[0_0_12px_rgba(6,182,212,0.3)]',    bg: 'bg-cyan-500/10',    iconBg: 'bg-cyan-500/15', iconBorder: 'border-cyan-400/40', iconText: 'text-cyan-300',   checkBorder: 'border-cyan-400/60',  checkText: 'text-cyan-400' },
    blue:    { border: 'border-blue-500/60',     shadow: 'shadow-[0_0_12px_rgba(59,130,246,0.3)]',   bg: 'bg-blue-500/10',    iconBg: 'bg-blue-500/15',  iconBorder: 'border-blue-400/40',  iconText: 'text-blue-300',    checkBorder: 'border-blue-400/60',   checkText: 'text-blue-400' },
    purple:  { border: 'border-purple-500/60',   shadow: 'shadow-[0_0_12px_rgba(168,85,247,0.3)]',  bg: 'bg-purple-500/10',  iconBg: 'bg-purple-500/15', iconBorder: 'border-purple-400/40', iconText: 'text-purple-300',  checkBorder: 'border-purple-400/60', checkText: 'text-purple-400' },
    amber:   { border: 'border-amber-500/60',    shadow: 'shadow-[0_0_12px_rgba(245,158,11,0.3)]',  bg: 'bg-amber-500/10',   iconBg: 'bg-amber-500/15',  iconBorder: 'border-amber-400/40',  iconText: 'text-amber-300',   checkBorder: 'border-amber-400/60',  checkText: 'text-amber-400' },
};

const BASE_ITEM = 'sa-ability-item flex items-center gap-2.5 p-2.5 rounded-lg cursor-pointer transition-all duration-200 border border-slate-700/50 bg-slate-900/20 hover:border-slate-600/50';
const BASE_ICON = 'sa-icon w-5 h-5 rounded-md flex items-center justify-center shrink-0 transition-all duration-200';
const BASE_CHECK = 'sa-check w-4 h-4 rounded-full border border-slate-600 flex items-center justify-center opacity-0 scale-75 transition-all duration-200';

function itemHTML(tool) {
    const def = TOOL_DEFS[tool];
    const acc = ACCENT_CLASSES[def.accent];
    return `
        <div class="${BASE_ITEM}" data-tool="${tool}" data-accent="${def.accent}">
            <span class="${BASE_ICON} bg-${def.accent}-500/10 border border-${def.accent}-500/20 text-${def.accent}-400">
                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${def.icon}</svg>
            </span>
            <span class="flex-1 text-xs text-slate-300">${def.label}</span>
            <span class="${BASE_CHECK}">
                <svg class="w-2.5 h-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
            </span>
        </div>`;
}

export function renderSuperAbilitiesCard(sessionId, query, aiBubble) {
    removeExistingCard();

    const card = document.createElement('div');
    card.id = 'super-abilities-card';
    card.className = 'mt-4 pt-4 border-t border-slate-800/60';
    card.innerHTML = `
        <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
                <span class="relative flex items-center justify-center w-6 h-6 rounded-lg bg-cyan-500/15 border border-cyan-500/30">
                    <svg class="w-3.5 h-3.5 text-cyan-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
                    </svg>
                </span>
                <span class="text-xs font-semibold text-cyan-300 tracking-wide">Super Abilities</span>
            </div>
            <button id="sa-dismiss-btn" class="p-1 rounded text-slate-600 hover:text-slate-400 hover:bg-slate-800/40 transition-colors cursor-pointer" title="Dismiss">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <div class="space-y-1.5 mb-3">
            ${itemHTML('search_web')}
            ${itemHTML('search_files')}
            ${itemHTML('search_memories')}
            ${itemHTML('calendar')}
        </div>

        <button id="sa-submit-btn" class="w-full px-4 py-2 rounded-lg bg-transparent border border-cyan-500/40 text-cyan-400 font-semibold text-xs transition-all duration-200 flex items-center justify-center gap-1.5 hover:bg-cyan-600 hover:border-cyan-400 hover:text-white hover:shadow-lg hover:shadow-cyan-500/25 hover:-translate-y-px active:translate-y-0 active:scale-[0.98]">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2L15 22l-4-9-9-4 20-7z"/></svg>
            Activate & Search
        </button>
    `;

    aiBubble.appendChild(card);

    // Click-to-toggle on each ability item
    card.querySelectorAll('.sa-ability-item').forEach(item => {
        item.addEventListener('click', () => toggleItem(item));
    });

    const submitBtn = card.querySelector('#sa-submit-btn');
    submitBtn.addEventListener('click', () => handleCardSubmit(card, sessionId, query, aiBubble));

    const dismissBtn = card.querySelector('#sa-dismiss-btn');
    dismissBtn.addEventListener('click', () => {
        removeExistingCard();
    });

    const chatWindow = document.getElementById('chatWindow');
    if (chatWindow) {
        chatWindow.scrollTop = chatWindow.scrollHeight;
    }
}

function toggleItem(item) {
    const isSelected = item.dataset.selected === 'true';
    const accent = item.dataset.accent;
    const acc = ACCENT_CLASSES[accent];
    const icon = item.querySelector('.sa-icon');
    const check = item.querySelector('.sa-check');

    if (isSelected) {
        item.dataset.selected = 'false';
        item.classList.remove(acc.border, acc.shadow, acc.bg);
        item.classList.add('border-slate-700/50', 'bg-slate-900/20');
        icon.classList.remove(acc.iconBg, acc.iconBorder, acc.iconText);
        check.classList.remove('opacity-100', 'scale-100', acc.checkBorder, acc.checkText);
        check.classList.add('opacity-0', 'scale-75', 'border-slate-600');
    } else {
        item.dataset.selected = 'true';
        item.classList.remove('border-slate-700/50', 'bg-slate-900/20');
        item.classList.add(acc.border, acc.shadow, acc.bg);
        icon.classList.add(acc.iconBg, acc.iconBorder, acc.iconText);
        check.classList.remove('opacity-0', 'scale-75', 'border-slate-600');
        check.classList.add('opacity-100', 'scale-100', acc.checkBorder, acc.checkText);
    }
}

function handleCardSubmit(card, sessionId, query, aiBubble) {
    const selected = card.querySelectorAll('.sa-ability-item[data-selected="true"]');
    const selectedTools = Array.from(selected).map(el => el.dataset.tool);

    if (selectedTools.length === 0) {
        const btn = card.querySelector('#sa-submit-btn');
        btn.classList.add('bg-rose-600', 'hover:bg-rose-500');
        btn.classList.remove('bg-cyan-600', 'hover:bg-cyan-500');
        setTimeout(() => {
            btn.classList.remove('bg-rose-600', 'hover:bg-rose-500');
            btn.classList.add('bg-cyan-600', 'hover:bg-cyan-500');
        }, 800);
        return;
    }

    card.innerHTML = `
        <div class="flex items-center gap-3 py-3">
            <span class="uk-spinner uk-spinner-sm animate-spin text-cyan-400 shrink-0" uk-spinner="ratio: 0.7"></span>
            <span class="text-xs text-slate-400">Activating super abilities...</span>
        </div>
    `;

    const formData = new FormData();
    formData.append('session_id', sessionId);
    formData.append('q', query);
    formData.append('active_tools', selectedTools.join(','));

    import('../streamer/streamResponse.js').then(module => {
        module.streamIntoBubble(aiBubble, formData, card);
    });
}

export function removeExistingCard() {
    const existing = document.getElementById('super-abilities-card');
    if (existing) {
        existing.remove();
    }
}
