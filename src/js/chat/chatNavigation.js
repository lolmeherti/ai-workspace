import { state } from '../state.js';
import { previewFile, removeFile } from '../fileHandler.js';
import { updateTokenCounter } from '../ui.js';
import { notify, clearNotice, requestJson } from '../workspace/feedback.js';
import { paintAvailability } from '../workspace/availability.js';
import { enhanceControls } from '../workspace/shell.js';
import { showChatSkeleton, hideChatSkeleton } from '../workspace/chatSkeleton.js';

const conversations = new Map();
const drafts = new Map();
let sequence = 0;
let loading;

export function captureDraft() {
    return {
        text: document.getElementById('q')?.value || '',
        file: state.selectedFile || document.getElementById('fileInput')?.files?.[0] || state.pastedImageFile,
        references: [...(window.selectedFileReferences || [])]
    };
}

export function restoreDraft(draft = {}) {
    const input = document.getElementById('q');
    if (input) {
        input.value = draft.text || '';
        input.style.height = '';
        input.style.height = Math.min(input.scrollHeight, 240) + 'px';
    }
    removeFile();
    if (draft.file) previewFile({ files: [draft.file] });
    window.selectedFileReferences = [...(draft.references || [])];
    window.updateFileReferencesUI?.();
}

export function recoverSubmittedDraft(sessionId, draft, { label = 'The previous request could not start.' } = {}) {
    const existing = sessionId === state.sessionId ? captureDraft() : drafts.get(sessionId);
    if (!existing?.text && !existing?.file && !existing?.references?.length) {
        drafts.set(sessionId, draft);
        if (sessionId === state.sessionId) restoreDraft(draft);
        return;
    }
    notify(label + ' Your newer draft is unchanged.', {
        id: 'recover-draft-' + sessionId, kind: 'warning', action: 'Recover previous draft',
        onAction: () => {
            // Keep the newer draft available when the user explicitly swaps drafts.
            const newer = sessionId === state.sessionId ? captureDraft() : drafts.get(sessionId);
            drafts.set(sessionId, draft);
            if (sessionId === state.sessionId) restoreDraft(draft);
            notify('Previous draft recovered.', { id: 'recover-draft-' + sessionId, kind: 'success',
                action: 'Restore newer draft', onAction: () => {
                    drafts.set(sessionId, newer);
                    if (sessionId === state.sessionId) restoreDraft(newer);
                } });
        }
    });
}

function rememberCurrent() {
    const chat = document.getElementById('chatWindow');
    const context = document.getElementById('context-data-items');
    if (!chat || !context) return;
    drafts.set(state.sessionId, captureDraft());
    const existing = conversations.get(state.sessionId) || {};
    conversations.set(state.sessionId, { ...existing, chat, context,
        title: document.getElementById('conversation-title')?.textContent || 'New conversation',
        scroll: chat.scrollTop, contextLocked: state.contextLocked });
}

function paintSelection(pendingId = null) {
    document.querySelectorAll('.chat-session-item').forEach(row => {
        const id = Number(row.dataset.sessionId);
        row.classList.toggle('is-current', id === state.sessionId);
        row.classList.toggle('is-pending', id === pendingId);
        row.classList.remove('bg-slate-800/80');
        const link = row.querySelector('.session-link');
        if (id === state.sessionId) link?.setAttribute('aria-current', 'page');
        else link?.removeAttribute('aria-current');
        row.setAttribute('aria-busy', String(id === pendingId));
    });
}

function setURL(id, replace = false) {
    const url = new URL(window.location.href);
    url.searchParams.delete('new_chat');
    url.searchParams.set('session_id', id);
    url.searchParams.set('tab', 'chats');
    if (replace || url.href === window.location.href) history.replaceState({ session_id: id }, '', url);
    else history.pushState({ session_id: id }, '', url);
    document.querySelectorAll('form[action]').forEach(form => {
        const action = new URL(form.getAttribute('action'), window.location.href);
        if (action.searchParams.has('session_id')) {
            action.searchParams.set('session_id', id);
            form.action = action.href;
        }
    });
}

export async function navigateConversation(id, { fromHistory = false, refresh = false } = {}) {
    id = Number(id);
    if (!Number.isInteger(id) || id < 0) return;
    const request = ++sequence;
    loading?.abort();
    paintSelection(id);
    state.navigationPending = true;
    paintAvailability();
    const canLeave = await (window.canLeaveContext?.() ?? true);
    if (request !== sequence) return;
    if (!canLeave) {
        state.navigationPending = false;
        paintAvailability();
        paintSelection();
        if (fromHistory) setURL(state.sessionId, true);
        return;
    }
    rememberCurrent();
    if (id === state.sessionId && !refresh) {
        state.navigationPending = false;
        paintAvailability();
        paintSelection();
        window.switchSidebarTab?.('chats');
        return;
    }
    loading = new AbortController();
    try {
        let entry = refresh ? null : conversations.get(id);
        if (!entry?.chat) {
            showChatSkeleton();
            const data = await requestJson('index.php?api_action=get_conversation&session_id=' + id, { signal: loading.signal });
            if (request !== sequence) return;
            const chat = document.createElement('div');
            chat.id = 'chatWindow';
            chat.className = document.getElementById('chatWindow').className;
            chat.innerHTML = data.messages_html;
            const context = document.createElement('div');
            context.id = 'context-data-items';
            context.className = 'context-list';
            context.innerHTML = data.context_html;
            entry = { chat, context, title: data.title, tokens: Number(data.tokens) || 0, scroll: null };
            conversations.set(id, entry);
        }
        if (request !== sequence) return;
        // Capture edits made while the request was loading before leaving.
        rememberCurrent();
        document.getElementById('chatWindow').replaceWith(entry.chat);
        document.getElementById('context-data-items').replaceWith(entry.context);
        if (state.editorVisible) window.closeEditorDrawer?.();
        conversations.set(id, entry);
        state.sessionId = id;
        state.contextLocked = !!entry.contextLocked;
        document.querySelector('#chatForm input[name="session_id"]').value = id;
        document.getElementById('conversation-title').textContent = entry.title;
        document.getElementById('context-data-count').textContent = entry.context.querySelectorAll('.context-item').length;
        window.resetContextDetail?.();
        document.getElementById('q').disabled = !!state.contextLocked;
        window.hydrateConversation?.();
        document.dispatchEvent(new Event('workspace-content-ready'));
        enhanceControls(entry.chat);
        restoreDraft(drafts.get(id));
        window.switchSidebarTab?.('chats');
        setURL(id, fromHistory);
        if (typeof maxTokensLimit !== 'undefined') updateTokenCounter(entry.tokens || 0, maxTokensLimit);
        entry.chat.scrollTop = entry.scroll === null ? entry.chat.scrollHeight : entry.scroll;
        paintSelection();
        paintAvailability();
        // Bound retained message DOM while keeping every draft and active stream.
        for (const [key, value] of conversations) {
            if (conversations.size <= 6) break;
            if (key !== id && key !== state.generation?.sessionId) {
                value.chat = null;
                conversations.delete(key);
            }
        }
    } catch (error) {
        if (error.name !== 'AbortError' && request === sequence) {
            notify(error.message || 'Unable to open this conversation.', { id: 'conversation-error', kind: 'error',
                target: document.getElementById('composer-notices'), action: 'Try again', onAction: () => navigateConversation(id) });
            if (fromHistory) setURL(state.sessionId, true);
        }
    } finally {
        if (request === sequence) {
            hideChatSkeleton();
            state.navigationPending = false;
            paintAvailability();
            paintSelection();
        }
    }
}

export function updateConversation(id, { title, tokens, newId, contextLocked } = {}) {
    const entry = conversations.get(id);
    if (entry) {
        if (title !== undefined) entry.title = title;
        if (tokens !== undefined) entry.tokens = tokens;
        if (contextLocked !== undefined) entry.contextLocked = contextLocked;
    }
    if (contextLocked !== undefined && state.sessionId === id) state.contextLocked = contextLocked;
    if (title !== undefined) {
        const label = document.querySelector('.chat-session-item[data-session-id="' + id + '"] .session-title');
        if (label) label.textContent = title;
        if (state.sessionId === id) document.getElementById('conversation-title').textContent = title;
    }
    if (newId && newId !== id) {
        if (entry) { conversations.set(newId, entry); conversations.delete(id); }
        if (drafts.has(id)) { drafts.set(newId, drafts.get(id)); drafts.delete(id); }
        if (state.sessionId === id) {
            state.sessionId = newId;
            document.querySelector('#chatForm input[name="session_id"]').value = newId;
            setURL(newId, true);
        }
        if (state.generation?.sessionId === id) state.generation.sessionId = newId;
        addConversationRow(newId, title || entry?.title || 'New conversation');
    }
    if (tokens !== undefined && state.sessionId === (newId || id) && typeof maxTokensLimit !== 'undefined') {
        updateTokenCounter(tokens, maxTokensLimit);
    }
    paintSelection();
}

function addConversationRow(id, title) {
    if (document.querySelector('.chat-session-item[data-session-id="' + id + '"]')) return;
    const row = document.createElement('div');
    row.className = 'chat-session-item group flex items-center rounded-lg';
    row.dataset.sessionId = String(id);
    row.dataset.starred = '0';
    const link = document.createElement('a');
    link.href = 'index.php?session_id=' + id + '&tab=chats';
    link.className = 'session-link flex-1 flex items-center gap-2 truncate';
    link.innerHTML = '<span class="select-check-icon hidden">✓</span><uk-icon icon="message-square" class="w-4 h-4" aria-hidden="true"></uk-icon>';
    const text = document.createElement('span');
    text.className = 'session-title truncate';
    text.textContent = title;
    link.append(text);
    const star = document.createElement('button');
    star.type = 'button';
    star.className = 'btn-star-session';
    star.setAttribute('aria-label', 'Star conversation');
    star.setAttribute('aria-pressed', 'false');
    star.innerHTML = '<svg class="star-icon star-glow-inactive" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15 8 22 9 17 14 18 22 12 18 6 22 7 14 2 9 9 8"/></svg>';
    star.addEventListener('click', event => window.toggleStarSession(event, id));
    row.append(link, star);
    document.getElementById('chats-list-container')?.prepend(row);
}

export function initChatNavigation() {
    state.sessionId = Number(document.querySelector('#chatForm input[name="session_id"]')?.value) || 0;
    rememberCurrent();
    const entry = conversations.get(state.sessionId);
    if (entry && typeof initialSessionTokens !== 'undefined') entry.tokens = initialSessionTokens;
    paintSelection();
    document.addEventListener('click', e => {
        if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || state.isChatEditMode) return;
        // Only SPA-navigate existing sessions. "#new-chat-link" must NOT be
        // intercepted: it is a plain href to index.php?new_chat=1, and the
        // server is what creates (or reuses the single empty) "New Conversation"
        // row — intercepting it here and navigating to session 0 skipped that
        // and left the composer stuck on a non-existent session id.
        const link = e.target.closest('.session-link');
        if (!link) return;
        e.preventDefault();
        clearNotice('conversation-error');
        const id = Number(link.closest('.chat-session-item').dataset.sessionId);
        navigateConversation(id);
    });
    window.addEventListener('popstate', () => {
        const url = new URL(location.href);
        const tab = url.searchParams.get('tab') || 'chats';
        navigateConversation(Number(url.searchParams.get('session_id') || 0), { fromHistory: true }).then(() => window.switchSidebarTab?.(tab));
    });
    window.navigateConversation = navigateConversation;
}

export function appendDraftReference(sessionId, reference) {
    if (sessionId === state.sessionId) { window.addFileReference(reference); return; }
    const draft = drafts.get(sessionId) || { text: '', references: [] };
    if (!draft.references.some(ref => ref.physical_name === reference.physical_name)) draft.references.push(reference);
    drafts.set(sessionId, draft);
}
