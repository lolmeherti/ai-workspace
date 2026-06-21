const TAGS = [
    { key: 'web', label: 'web', desc: 'Search the web', color: 'cyan' },
    { key: 'files', label: 'files', desc: 'Search local files', color: 'emerald' },
    { key: 'memory', label: 'memory', desc: 'Search memories', color: 'amber' },
    { key: 'local', label: 'local', desc: 'Search files & memories', color: 'violet' }
];

const TAG_PATTERN = /^@(?:web|files|memory|local)\b(?:\s+|$)/i;

let activeTags = [];
let autocompleteState = null;
let elements = {};

function getTagMeta(key) {
    return TAGS.find(t => t.key === key) || { key, label: key, desc: '', color: 'cyan' };
}

function renderBadges() {
    if (!elements.badgeContainer) return;
    elements.badgeContainer.innerHTML = '';
    if (activeTags.length === 0) {
        elements.badgeContainer.classList.add('hidden');
        return;
    }
    elements.badgeContainer.classList.remove('hidden');

    activeTags.forEach((key, idx) => {
        const meta = getTagMeta(key);
        const badge = document.createElement('span');
        badge.className = `tag-badge tag-badge-${meta.color}`;
        badge.innerHTML = `
            <span class="tag-badge-label">@${meta.label}</span>
            <button type="button" class="tag-badge-remove" aria-label="Remove tag" data-idx="${idx}">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="w-3 h-3"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        `;
        badge.querySelector('.tag-badge-remove').addEventListener('click', (e) => {
            e.preventDefault();
            activeTags.splice(idx, 1);
            renderBadges();
            focusTextarea();
        });
        elements.badgeContainer.appendChild(badge);
    });
}

function addTag(key) {
    key = key.toLowerCase();
    if (!TAGS.some(t => t.key === key)) return;
    if (activeTags.includes(key)) return;
    activeTags.push(key);
    renderBadges();
}

function clearTags() {
    activeTags = [];
    renderBadges();
}

function focusTextarea() {
    if (elements.textarea) {
        elements.textarea.focus();
    }
}

function syncTagsFromText() {
    if (!elements.textarea) return;
    let changed = false;
    let text = elements.textarea.value;
    while (true) {
        const match = text.match(TAG_PATTERN);
        if (!match) break;
        const raw = match[0].trim();
        const key = raw.slice(1).toLowerCase();
        addTag(key);
        text = text.slice(match[0].length);
        changed = true;
    }
    if (changed) {
        elements.textarea.value = text;
        // Keep caret at the start of the query
        elements.textarea.setSelectionRange(0, 0);
    }
}

function getActiveToken() {
    if (!elements.textarea) return null;
    const text = elements.textarea.value;
    const caret = elements.textarea.selectionStart;
    const before = text.slice(0, caret);
    const match = before.match(/(^|\s)@(\w*)$/);
    if (!match) return null;
    return {
        prefix: match[2].toLowerCase(),
        start: caret - match[0].length,
        length: match[0].length,
        hasLeadingSpace: match[1] !== ''
    };
}

function updateAutocomplete() {
    const token = getActiveToken();
    if (!token) {
        closeAutocomplete();
        return;
    }
    const matches = token.prefix.length === 0
        ? TAGS
        : TAGS.filter(t => t.key.startsWith(token.prefix));
    if (matches.length === 0) {
        closeAutocomplete();
        return;
    }
    autocompleteState = {
        token,
        matches,
        selected: 0
    };
    renderAutocomplete();
    positionAutocomplete();
}

function positionAutocomplete() {
    if (!elements.autocomplete || !elements.textarea) return;
    elements.autocomplete.style.left = '0';
    elements.autocomplete.style.right = '0';
    elements.autocomplete.style.top = 'auto';
    elements.autocomplete.style.bottom = '100%';
    elements.autocomplete.style.marginTop = '0';
    elements.autocomplete.style.marginBottom = '4px';
}

function renderAutocomplete() {
    if (!elements.autocomplete || !autocompleteState) return;
    elements.autocomplete.innerHTML = '';
    elements.autocomplete.classList.remove('hidden');

    autocompleteState.matches.forEach((tag, idx) => {
        const item = document.createElement('div');
        item.className = 'tag-autocomplete-item' + (idx === autocompleteState.selected ? ' tag-autocomplete-selected' : '');
        item.innerHTML = `
            <span class="tag-autocomplete-key">@${tag.label}</span>
            <span class="tag-autocomplete-desc">${tag.desc}</span>
        `;
        item.addEventListener('click', () => {
            autocompleteState.selected = idx;
            insertSelectedTag();
        });
        item.addEventListener('mouseenter', () => {
            autocompleteState.selected = idx;
            renderAutocomplete();
        });
        elements.autocomplete.appendChild(item);
    });
}

function closeAutocomplete() {
    autocompleteState = null;
    if (elements.autocomplete) {
        elements.autocomplete.classList.add('hidden');
        elements.autocomplete.innerHTML = '';
    }
}

function insertSelectedTag() {
    if (!autocompleteState || !elements.textarea) return;
    const tag = autocompleteState.matches[autocompleteState.selected];
    if (!tag) return;

    const text = elements.textarea.value;
    const token = autocompleteState.token;
    const before = text.slice(0, token.start);
    const after = text.slice(token.start + token.length);
    elements.textarea.value = (before + after).replace(/^\s+/, '');
    addTag(tag.key);
    closeAutocomplete();
    elements.textarea.setSelectionRange(before.length, before.length);
    focusTextarea();
    syncTagsFromText();
}

function onInput() {
    syncTagsFromText();
    updateAutocomplete();
}

function onKeyDown(e) {
    if (!autocompleteState) return;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        autocompleteState.selected = (autocompleteState.selected + 1) % autocompleteState.matches.length;
        renderAutocomplete();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        autocompleteState.selected = (autocompleteState.selected - 1 + autocompleteState.matches.length) % autocompleteState.matches.length;
        renderAutocomplete();
    } else if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        insertSelectedTag();
    } else if (e.key === 'Escape') {
        e.preventDefault();
        closeAutocomplete();
    }
}

export function parseTagsFromText(text) {
    const tags = [];
    let remaining = text.trim();
    while (true) {
        const match = remaining.match(/^@(\w+)\b(?:\s+|$)/i);
        if (!match) break;
        const key = match[1].toLowerCase();
        if (!TAGS.some(t => t.key === key)) break;
        tags.push(key);
        remaining = remaining.slice(match[0].length).trim();
    }
    return { tags, query: remaining };
}

export function renderTagBadges(tags) {
    if (!tags || tags.length === 0) return '';
    return tags.map(key => {
        const meta = getTagMeta(key);
        return `<span class="tag-badge tag-badge-${meta.color}"><span class="tag-badge-label">@${meta.label}</span></span>`;
    }).join(' ');
}

export function getComposedQuery() {
    const query = elements.textarea ? elements.textarea.value.trim() : '';
    if (activeTags.length === 0) return query;
    return activeTags.map(k => '@' + k).join(' ') + (query ? ' ' + query : '');
}

export function clearActiveTags() {
    clearTags();
}

export function initTagInput() {
    const textarea = document.getElementById('q');
    const badgeContainer = document.getElementById('tag-badges');
    const autocomplete = document.getElementById('tag-autocomplete');
    if (!textarea || !badgeContainer || !autocomplete) return;

    elements = { textarea, badgeContainer, autocomplete };

    textarea.addEventListener('input', onInput);
    textarea.addEventListener('keydown', onKeyDown);

    document.addEventListener('click', (e) => {
        if (!autocomplete.contains(e.target) && e.target !== textarea) {
            closeAutocomplete();
        }
    });

    window.getComposedQuery = getComposedQuery;
    window.clearActiveTags = clearActiveTags;

    renderBadges();
}
