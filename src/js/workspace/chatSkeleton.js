// Shared loading skeleton for the chat window. Used by conversation navigation
// and by actions that cause a full page reload (delete, clear history) so the
// user always sees that work is in progress.
let chatSkeleton = null;

export function showChatSkeleton() {
    hideChatSkeleton();
    const pane = document.getElementById('chat-pane');
    if (!pane) return;
    const skeleton = document.createElement('div');
    skeleton.className = 'chat-skeleton';
    skeleton.setAttribute('aria-hidden', 'true');
    skeleton.innerHTML = `
        <div class="chat-skeleton-message is-user"><div class="chat-skeleton-bubble"></div></div>
        <div class="chat-skeleton-message is-assistant">
            <div class="chat-skeleton-line w-90"></div>
            <div class="chat-skeleton-line w-70"></div>
            <div class="chat-skeleton-line w-45"></div>
        </div>
        <div class="chat-skeleton-message is-user"><div class="chat-skeleton-bubble"></div></div>
        <div class="chat-skeleton-message is-assistant">
            <div class="chat-skeleton-line w-80"></div>
            <div class="chat-skeleton-line w-55"></div>
        </div>`;
    pane.append(skeleton);
    chatSkeleton = skeleton;
}

export function hideChatSkeleton() {
    chatSkeleton?.remove();
    chatSkeleton = null;
}
