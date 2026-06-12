/**
 * @file js/chat/chatUnifiedBriefing.js
 * @description Trigger the unified email/Todoist briefing pipeline from chat.
 */

export function triggerUnifiedBriefing() {
    if (typeof window.switchSidebarTab === 'function') {
        window.switchSidebarTab('chats');
    }

    const chatInput = document.getElementById("q");
    const form = document.getElementById("chatForm");
    const includeReadCheckbox = document.getElementById("briefing-include-read");
    const includeRead = includeReadCheckbox ? includeReadCheckbox.checked : false;

    if (chatInput && form) {
        chatInput.value = "[TRIGGER_BRIEFING_PIPELINE]" + (includeRead ? ":include_read" : "");
        chatInput.style.height = 'auto';
        chatInput.style.height = chatInput.scrollHeight + 'px';
        form.dispatchEvent(new Event('submit', { cancelable: true }));
    }
}
