/**
 * @file js/chat/chatBriefingCards.js
 * @description Shared briefing card rendering (action "Suggested Tasks" cards)
 *              plus page-load hydration of persisted briefing_cards JSON.
 */

function renderBriefingActions(bubble, actions) {
    if (!actions || !actions.length) return;

    const escAttr = (s) => String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");

    const section = document.createElement('div');
    section.className = 'briefing-actions my-4 max-w-xl rounded-xl border border-indigo-500/30 bg-slate-950/60 shadow-md overflow-hidden text-left';

    const header = document.createElement('div');
    header.className = 'px-4 pt-3 pb-2 flex items-center gap-2 border-b border-slate-800/60';
    header.innerHTML = '<span class="text-[10px] font-extrabold uppercase tracking-wider text-indigo-400">Suggested Tasks</span>'
        + '<span class="ml-auto text-[10px] px-1.5 py-0.5 rounded-full bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 font-mono">' + actions.length + '</span>';
    section.appendChild(header);

    for (const a of actions) {
        const content = a && a.content ? String(a.content) : '';
        const due = a && a.due_string ? String(a.due_string) : '';

        const card = document.createElement('div');
        card.className = 'flex items-center justify-between gap-3 px-4 py-3 border-b border-slate-800/40 last:border-0';

        const textWrap = document.createElement('div');
        textWrap.className = 'min-w-0 flex-1';

        const title = document.createElement('p');
        title.className = 'text-xs font-bold text-slate-200 leading-relaxed';
        title.textContent = content;
        textWrap.appendChild(title);

        if (due) {
            const dueEl = document.createElement('p');
            dueEl.className = 'text-[10px] text-indigo-400/80 font-mono mt-0.5';
            dueEl.textContent = 'Suggested schedule: ' + due;
            textWrap.appendChild(dueEl);
        }

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-create-todoist flex items-center justify-center gap-1.5 px-3 py-1.5 text-[10px] font-extrabold tracking-wider uppercase bg-indigo-950/40 hover:bg-indigo-900/60 text-indigo-400 border border-indigo-500/30 hover:border-indigo-400/50 rounded-lg transition-all cursor-pointer outline-none shrink-0';
        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-indigo-400"><polyline points="20 6 9 17 4 12"/></svg>Accept & Create Task';
        btn.setAttribute('onclick', "window.createTodoistTaskDirectly('" + escAttr(content) + "', '" + escAttr(due) + "', this)");

        card.appendChild(textWrap);
        card.appendChild(btn);
        section.appendChild(card);
    }

    bubble.appendChild(section);
}

/**
 * Page-load hydration: merge persisted email-card maps into the global
 * window.briefingEmailCards (so [E#] anchors resolve to email cards) and
 * render each briefing message's action cards. Must run BEFORE the markdown
 * renderers call parseInlineFiles, so [E#] -> [Email:...] conversion works.
 */
export function hydrateBriefingCards() {
    document.querySelectorAll('.markdown-rendered[data-briefing-cards]').forEach((el) => {
        if (el.dataset.briefingCardsHydrated === '1') return;
        el.dataset.briefingCardsHydrated = '1';

        let data;
        try {
            data = JSON.parse(el.getAttribute('data-briefing-cards') || '{}');
        } catch (e) {
            return;
        }

        if (data.emails && typeof data.emails === 'object' && Object.keys(data.emails).length) {
            window.briefingEmailCards = window.briefingEmailCards || {};
            Object.assign(window.briefingEmailCards, data.emails);
        }

        const actions = Array.isArray(data.actions) ? data.actions : [];
        if (actions.length) {
            const bubble = el.closest('.chat-assistant') || el.parentNode;
            if (bubble) renderBriefingActions(bubble, actions);
        }
    });
}

export { renderBriefingActions };
