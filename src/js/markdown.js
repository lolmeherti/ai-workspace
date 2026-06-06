/**
 * @file js/markdown.js
 * @description Markdown rendering helper. Parses markdown content, highlights pre/code blocks, and executes copy-to-clipboard behaviors.
 */

export function parseMarkdownElements() {
    document.querySelectorAll('.markdown-rendered:not(.parsed)').forEach(function(el) {
        if (typeof marked !== 'undefined') {
            el.innerHTML = marked.parse(el.getAttribute('data-markdown') || '');
        }
        el.classList.add('parsed', 'markdown-content');
        
        if (typeof hljs !== 'undefined') {
            el.querySelectorAll('pre code').forEach((block) => {
                hljs.highlightElement(block);
            });
        }
    });
}

export function copyToClipboard(button) {
    const container = button.closest('.chat-message-container');
    if (!container) return;
    
    const bubble = container.querySelector('[data-raw]');
    if (!bubble) return;
    
    const textToCopy = bubble.getAttribute('data-raw');
    
    navigator.clipboard.writeText(textToCopy).then(() => {
        const icon = button.querySelector('uk-icon');
        if (icon) {
            icon.setAttribute('icon', 'check');
            button.classList.add('text-emerald-400');
            button.classList.remove('text-slate-500', 'hover:text-cyan-400');
            
            setTimeout(() => {
                icon.setAttribute('icon', 'copy');
                button.classList.remove('text-emerald-400');
                button.classList.add('text-slate-500', 'hover:text-cyan-400');
            }, 1500);
        }
    }).catch(() => {});
}