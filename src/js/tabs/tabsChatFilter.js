/**
 * @file js/tabs/tabsChatFilter.js
 * @description Filter chat session list by all or starred.
 */

window.currentChatFilter = localStorage.getItem('chat_list_filter') || 'all';

export function setChatFilter(filter) {
    window.currentChatFilter = filter;
    localStorage.setItem('chat_list_filter', filter);

    const btnAll = document.getElementById('btn-filter-all');
    const btnStarred = document.getElementById('btn-filter-starred');
    const items = document.querySelectorAll('.chat-session-item');

    const activeFilterClass = "bg-[#0b1324] border-cyan-500/30 text-cyan-400 shadow-[0_0_8px_rgba(6,182,212,0.15)] font-semibold";
    const inactiveFilterClass = "bg-transparent border-transparent text-slate-400 hover:text-slate-200 hover:bg-slate-800/20";

    if (filter === 'all') {
        if (btnAll) btnAll.className = `flex-1 py-1.5 px-3 rounded-md border text-[11px] transition-all duration-200 text-center cursor-pointer ${activeFilterClass}`;
        if (btnStarred) btnStarred.className = `flex-1 py-1.5 px-3 rounded-md border text-[11px] transition-all duration-200 text-center flex items-center justify-center gap-1.5 cursor-pointer ${inactiveFilterClass}`;

        items.forEach(item => {
            item.classList.remove('hidden');
        });
    } else if (filter === 'starred') {
        if (btnAll) btnAll.className = `flex-1 py-1.5 px-3 rounded-md border text-[11px] transition-all duration-200 text-center cursor-pointer ${inactiveFilterClass}`;
        if (btnStarred) btnStarred.className = `flex-1 py-1.5 px-3 rounded-md border text-[11px] transition-all duration-200 text-center flex items-center justify-center gap-1.5 cursor-pointer ${activeFilterClass}`;

        items.forEach(item => {
            const isStarred = item.getAttribute('data-starred') === '1';
            if (isStarred) {
                item.classList.remove('hidden');
            } else {
                item.classList.add('hidden');
            }
        });
    }
}

export function initChatFilter() {
    document.addEventListener('DOMContentLoaded', () => {
        setChatFilter(window.currentChatFilter);
    });
}

initChatFilter();
