import { ensureAIAvailable } from '../workspace/availability.js';
import { captureDraft, recoverSubmittedDraft } from './chatNavigation.js';
import { state } from '../state.js';
/**
 * @file js/chat/chatUnifiedBriefing.js
 * @description Trigger the unified email/Todoist briefing pipeline from chat.
 */

export async function triggerUnifiedBriefing() {
    if (!ensureAIAvailable()) return;
    if (typeof window.switchSidebarTab === 'function') {
        window.switchSidebarTab('chats');
    }

    const chatInput = document.getElementById("q");
    const form = document.getElementById("chatForm");
    const includeReadCheckbox = document.getElementById("briefing-include-read");
    const includeRead = includeReadCheckbox ? includeReadCheckbox.checked : false;

    if (chatInput && form) {
        const draft = captureDraft(); const sessionId = state.sessionId;
        chatInput.value = "[TRIGGER_BRIEFING_PIPELINE]" + (includeRead ? ":include_read" : "");
        chatInput.style.height = 'auto';
        chatInput.style.height = chatInput.scrollHeight + 'px';
        await window.handleChatSubmit(new Event('submit', { cancelable: true }));
        if (draft.text || draft.file || draft.references.length) recoverSubmittedDraft(sessionId, draft, { label: 'Your earlier chat draft is available.' });
    }
}
