import { ensureAIAvailable } from '../workspace/availability.js';
import { captureDraft, recoverSubmittedDraft } from './chatNavigation.js';
import { state } from '../state.js';
/**
 * @file js/chat/chatUnifiedBriefing.js
 * @description Trigger the unified email/Todoist briefing pipeline from chat.
 * The briefing always runs in its own fresh conversation — never inside the
 * chat the user currently has open.
 */

// Set the composer to the briefing trigger and submit. Returns false when the
// chat form or AI is unavailable.
export async function startBriefing(includeRead) {
    if (!ensureAIAvailable()) return false;
    if (typeof window.switchSidebarTab === 'function') {
        window.switchSidebarTab('chats');
    }

    const chatInput = document.getElementById('q');
    const form = document.getElementById('chatForm');
    if (!chatInput || !form) return false;

    chatInput.value = '[TRIGGER_BRIEFING_PIPELINE]' + (includeRead ? ':include_read' : '');
    chatInput.style.height = 'auto';
    chatInput.style.height = chatInput.scrollHeight + 'px';
    await window.handleChatSubmit(new Event('submit', { cancelable: true }));
    return true;
}

export async function triggerUnifiedBriefing() {
    if (!ensureAIAvailable()) return;

    const includeReadCheckbox = document.getElementById('briefing-include-read');
    const includeRead = includeReadCheckbox ? includeReadCheckbox.checked : false;

    // The briefing is its own conversation. If the current session already has
    // messages, move to a fresh (reused-or-created) empty conversation first —
    // the server's new_chat=1 does that — then auto-start once we land.
    if (!document.getElementById('empty-state')) {
        sessionStorage.setItem('briefing_autostart', includeRead ? '1' : '0');
        window.location.href = 'index.php?new_chat=1';
        return;
    }

    const draft = captureDraft();
    const sessionId = state.sessionId;
    const started = await startBriefing(includeRead);
    if (started && (draft.text || draft.file || draft.references.length)) {
        recoverSubmittedDraft(sessionId, draft, { label: 'Your earlier chat draft is available.' });
    }
}

window.startBriefing = startBriefing;
