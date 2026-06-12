/**
 * @file js/chat/chatEmailCards.js
 * @description Email card cache state and open-email-in-workspace navigation.
 */

export function initEmailCardState() {
    window.emailBodyCache = window.emailBodyCache || {};
    window.activeEmailFetches = window.activeEmailFetches || new Set();
}

export function openEmailDirectly(accountId, uid) {
    if (typeof window.switchSidebarTab === 'function') {
        window.switchSidebarTab('emails');
    }
    const select = document.getElementById('workspace-account-select');
    if (select) {
        select.value = accountId;
    }
    if (typeof window.loadInbox === 'function') {
        window.loadInbox(accountId, 1, uid);
    }
}

initEmailCardState();
