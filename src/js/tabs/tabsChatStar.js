/**
 * @file js/tabs/tabsChatStar.js
 * @description Toggle star state on chat session list items.
 */

export function toggleStarSession(event, sessionId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    const btn = event.currentTarget;
    const item = btn.closest('.chat-session-item');
    const svg = btn.querySelector('.star-icon');
    if (!item || !svg) return;

    const isStarredNow = item.getAttribute('data-starred') === '1';
    const nextStarredState = !isStarredNow;

    item.setAttribute('data-starred', nextStarredState ? '1' : '0');

    if (nextStarredState) {
        btn.classList.remove('opacity-0');
        btn.classList.add('opacity-100');
        svg.setAttribute('fill', 'currentColor');
        svg.className.baseVal = "star-icon star-glow-active transition-all duration-300";
    } else {
        btn.classList.add('opacity-0');
        btn.classList.remove('opacity-100');
        svg.setAttribute('fill', 'none');
        svg.className.baseVal = "star-icon star-glow-inactive transition-all duration-300";
    }

    fetch(`index.php?toggle_star=${sessionId}&ajax=1`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                const confirmed = !!data.is_starred;
                item.setAttribute('data-starred', confirmed ? '1' : '0');
                if (confirmed) {
                    btn.classList.remove('opacity-0');
                    btn.classList.add('opacity-100');
                    svg.setAttribute('fill', 'currentColor');
                    svg.className.baseVal = "star-icon star-glow-active transition-all duration-300";
                } else {
                    btn.classList.add('opacity-0');
                    btn.classList.remove('opacity-100');
                    svg.setAttribute('fill', 'none');
                    svg.className.baseVal = "star-icon star-glow-inactive transition-all duration-300";
                }

                if (window.currentChatFilter === 'starred' && !confirmed) {
                    item.classList.add('hidden');
                }
            }
        })
        .catch(err => {
            console.error('Failed to star session:', err);

            item.setAttribute('data-starred', isStarredNow ? '1' : '0');
            if (isStarredNow) {
                btn.classList.add('opacity-100');
                btn.classList.remove('opacity-0');
                svg.setAttribute('fill', 'currentColor');
                svg.className.baseVal = "star-icon star-glow-active transition-all duration-300";
            } else {
                btn.classList.add('opacity-0');
                btn.classList.remove('opacity-100');
                svg.setAttribute('fill', 'none');
                svg.className.baseVal = "star-icon star-glow-inactive transition-all duration-300";
            }
        });
}
