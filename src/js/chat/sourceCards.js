const SOURCE_GLOBE_ICON = '<svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>';
const SOURCE_EXT_ICON = '<svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';

export function renderSourcesList(bubble, sources) {
    const panel = document.createElement('div');
    panel.className = 'sources-panel relative w-full mt-4 overflow-hidden rounded-xl border border-cyan-500/20 bg-gradient-to-b from-[#0d1321]/90 to-[#0d1321]/70 backdrop-blur-sm shadow-[0_0_25px_rgba(6,182,212,0.08),inset_0_1px_0_rgba(6,182,212,0.06)]';

    const accent = document.createElement('span');
    accent.className = 'absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-cyan-400/60 to-transparent';
    panel.appendChild(accent);

    const header = document.createElement('div');
    header.className = 'flex items-center gap-2 px-4 pt-3 pb-2';
    header.innerHTML = `
        <span class="relative flex items-center justify-center w-6 h-6 rounded-md bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 shadow-[0_0_10px_rgba(6,182,212,0.12)]">${SOURCE_GLOBE_ICON}</span>
        <span class="text-xs font-semibold tracking-normal normal-case bg-gradient-to-r from-cyan-300 via-blue-400 to-emerald-400 bg-clip-text text-transparent">Sources</span>
        <span class="relative flex h-1.5 w-1.5">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-cyan-400 opacity-75"></span>
            <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-cyan-400"></span>
        </span>
        <span class="ml-auto text-xs px-1.5 py-0.5 rounded-full bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 font-mono">${sources.length}</span>
    `;
    panel.appendChild(header);

    const list = document.createElement('div');
    list.className = 'px-3 pb-3 flex flex-col gap-1.5';

    for (const s of sources) {
        const a = document.createElement('a');
        try {
            const url = new URL(s.url);
            if (['http:', 'https:'].includes(url.protocol)) a.href = url.href;
        } catch {}
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.className = 'group relative flex items-center gap-3 px-3 py-2 rounded-lg border border-slate-700/40 bg-slate-900/30 hover:border-cyan-500/30 hover:bg-cyan-500/5 hover:shadow-[0_0_16px_rgba(6,182,212,0.10)] transition-all duration-200';

        const iconBox = document.createElement('span');
        iconBox.className = 'flex items-center justify-center w-7 h-7 shrink-0 rounded-md bg-slate-800/60 border border-slate-700/50 text-cyan-400 group-hover:border-cyan-500/40 group-hover:text-cyan-300 group-hover:shadow-[0_0_12px_rgba(6,182,212,0.25)] transition-all';
        iconBox.innerHTML = SOURCE_GLOBE_ICON;
        a.appendChild(iconBox);

        const textWrap = document.createElement('span');
        textWrap.className = 'flex flex-col min-w-0 flex-1';

        const titleText = s.title || s.domain || s.url || '';
        const title = document.createElement('span');
        title.className = 'text-xs text-slate-300 truncate group-hover:text-cyan-200 transition-colors';
        title.textContent = titleText;
        textWrap.appendChild(title);

        const hostname = s.domain || (a.href ? new URL(a.href).hostname : '');
        if (hostname && hostname !== titleText) {
            const domain = document.createElement('span');
            domain.className = 'text-xs text-slate-500 truncate font-mono group-hover:text-slate-400 transition-colors';
            domain.textContent = hostname;
            textWrap.appendChild(domain);
        }

        a.appendChild(textWrap);

        const ext = document.createElement('span');
        ext.className = 'shrink-0 text-slate-600 group-hover:text-cyan-400 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 transition-all';
        ext.innerHTML = SOURCE_EXT_ICON;
        a.appendChild(ext);

        list.appendChild(a);
    }

    panel.appendChild(list);
    bubble.appendChild(panel);
}

