import { requestJson, notify } from '../workspace/feedback.js';
export async function toggleStarSession(event, sessionId) {
    event.preventDefault(); event.stopPropagation();
    const button = event.currentTarget, row = button.closest('.chat-session-item');
    if (!row || button.disabled) return;
    button.disabled = true; button.setAttribute('aria-busy', 'true');
    const paint = starred => {
        row.dataset.starred = starred ? '1' : '0';
        button.setAttribute('aria-pressed', String(starred)); button.setAttribute('aria-label', starred ? 'Unstar conversation' : 'Star conversation');
        const svg = button.querySelector('.star-icon'); svg?.setAttribute('fill', starred ? 'currentColor' : 'none'); svg?.classList.toggle('star-glow-active', starred); svg?.classList.toggle('star-glow-inactive', !starred);
        if (window.currentChatFilter === 'starred') row.classList.toggle('hidden', !starred);
    };
    try { const data = await requestJson(`index.php?toggle_star=${sessionId}&ajax=1`); paint(!!data.is_starred); }
    catch {
        try { const actual = await requestJson(`index.php?api_action=get_conversation&session_id=${sessionId}`); paint(!!actual.is_starred); }
        catch { notify('Could not confirm the star change. Reopen this conversation before trying again.'); }
    } finally { button.disabled = false; button.removeAttribute('aria-busy'); }
}
