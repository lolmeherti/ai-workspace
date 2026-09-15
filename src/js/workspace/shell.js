import { confirmAction } from './feedback.js';

let controlId = 0;
const disclosureAnimations = new WeakMap();

export function enhanceControls(root = document) {
    root.querySelectorAll('button[title]:not([aria-label])').forEach(button => {
        if (!button.textContent.trim()) button.setAttribute('aria-label', button.title);
    });
    root.querySelectorAll('.thinking-summary').forEach(summary => {
        summary.setAttribute('role', 'button');
        summary.tabIndex = 0;
        summary.setAttribute('aria-expanded', String(summary.closest('.thinking-accordion').classList.contains('open')));
    });
    root.querySelectorAll('label').forEach(label => {
        if (label.htmlFor || label.querySelector('input,select,textarea')) return;
        const field = label.parentElement?.querySelector('input,select,textarea');
        if (field && !field.id) field.id = 'field-' + (field.name || 'control') + '-' + (++controlId);
        if (field?.id) label.htmlFor = field.id;
    });
}

export function initWorkspaceShell() {
    const toggle = document.getElementById('sidebar-toggle');
    const setCollapsed = collapsed => {
        document.body.classList.toggle('sidebar-collapsed', collapsed);
        toggle?.setAttribute('aria-expanded', String(!collapsed));
        toggle?.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    };
    setCollapsed(matchMedia('(max-width: 760px)').matches || localStorage.getItem('sidebar-collapsed') === 'true');
    toggle?.addEventListener('click', () => {
        const collapsed = !document.body.classList.contains('sidebar-collapsed');
        setCollapsed(collapsed);
        localStorage.setItem('sidebar-collapsed', String(collapsed));
    });
    const confirmed = new WeakSet();
    document.addEventListener('submit', async e => {
        const form = e.target.closest('form[data-confirm]');
        if (!form || confirmed.has(form)) return;
        e.preventDefault();
        if (await confirmAction(form.dataset.confirm, { destructive: true, confirmLabel: 'Delete', title: 'Confirm deletion' })) {
            confirmed.add(form);
            form.requestSubmit(e.submitter || undefined);
            confirmed.delete(form);
        }
    });
    document.addEventListener('keydown', e => {
        if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('.thinking-summary')) {
            e.preventDefault();
            e.target.click();
            e.target.setAttribute('aria-expanded', String(e.target.closest('.thinking-accordion').classList.contains('open')));
        }
    });
    document.addEventListener('click', e => {
        const thinking = e.target.closest('.thinking-summary');
        if (thinking) thinking.setAttribute('aria-expanded', String(thinking.closest('.thinking-accordion').classList.contains('open')));
        const summary = e.target.closest('summary');
        if (!summary || e.target.closest('a,button,input')) return;
        const details = summary.parentElement;
        if (details.tagName !== 'DETAILS' || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        e.preventDefault();
        const previous = disclosureAnimations.get(details);
        const opening = previous ? !previous.opening : !details.open;
        const start = details.getBoundingClientRect().height;
        previous?.animation.cancel();
        details.open = true;
        const end = opening ? details.scrollHeight : summary.getBoundingClientRect().height;
        details.style.overflow = 'hidden';
        const animation = details.animate([{ height: start + 'px' }, { height: end + 'px' }], { duration: 180, easing: 'ease-out' });
        disclosureAnimations.set(details, { animation, opening });
        animation.onfinish = () => {
            details.open = opening;
            details.style.overflow = '';
            disclosureAnimations.delete(details);
        };
    });
    document.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach(field => {
        if (!field.labels?.length && !field.hasAttribute('aria-label') && field.name) field.setAttribute('aria-label', field.name.replaceAll('_', ' '));
    });
    enhanceControls();
    document.addEventListener('workspace-content-ready', () => enhanceControls());
    for (const id of ['gallery-delete-modal', 'condensation-modal']) enhanceLegacyDialog(document.getElementById(id));
}

function enhanceLegacyDialog(overlay) {
    if (!overlay) return;
    let previousFocus;
    overlay.setAttribute('role', 'dialog'); overlay.setAttribute('aria-modal', 'true');
    const heading = overlay.querySelector('h2,h3,h4');
    if (heading) { heading.id ||= overlay.id + '-heading'; overlay.setAttribute('aria-labelledby', heading.id); }
    else overlay.setAttribute('aria-label', overlay.id === 'gallery-delete-modal' ? 'Delete files' : 'Condense conversation');
    const visibleButtons = () => [...overlay.querySelectorAll('button:not(:disabled),input,textarea,a[href]')].filter(el => !el.closest('.hidden'));
    new MutationObserver(() => {
        if (!overlay.classList.contains('hidden')) {
            if (!overlay.contains(document.activeElement)) { previousFocus = document.activeElement; visibleButtons()[0]?.focus(); }
        } else if (overlay.contains(document.activeElement) && previousFocus?.isConnected) previousFocus.focus();
    }).observe(overlay, { attributes: true, attributeFilter: ['class'] });
    overlay.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            e.preventDefault();
            if (overlay.id === 'gallery-delete-modal' && !document.getElementById('delete-modal-confirm')?.disabled) document.getElementById('delete-modal-cancel')?.click();
            else if (overlay.id === 'condensation-modal') window.closeCondensationModal?.();
        }
        if (e.key !== 'Tab') return;
        const targets = visibleButtons(); if (!targets.length) { e.preventDefault(); return; }
        if (e.shiftKey && document.activeElement === targets[0]) { e.preventDefault(); targets.at(-1).focus(); }
        else if (!e.shiftKey && document.activeElement === targets.at(-1)) { e.preventDefault(); targets[0].focus(); }
    });
}
