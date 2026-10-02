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

    // The look of the segment lives in styles.css (.chat-filter-btn / .is-active), so the
    // active state is one class toggle here — the old version rebuilt a Tailwind class
    // list in JS, whose inactive hover (slate-800/20) was invisible on this background.
    if (btnAll) btnAll.classList.toggle('is-active', filter !== 'starred');
    if (btnStarred) btnStarred.classList.toggle('is-active', filter === 'starred');

    items.forEach(item => {
        const show = filter === 'starred' ? item.getAttribute('data-starred') === '1' : true;
        item.classList.toggle('hidden', !show);
    });
}

export function initChatFilter() {
    document.addEventListener('DOMContentLoaded', () => {
        setChatFilter(window.currentChatFilter);
    });
}

initChatFilter();
