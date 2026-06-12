/**
 * @file js/tabs/tabsQueryCache.js
 * @description Search cache ledger viewer modal interactions.
 */

export function initQueryCacheTab() {
    document.addEventListener('DOMContentLoaded', () => {
        const viewerTrigger = document.getElementById('trigger-modal-cache-viewer');
        const modalQuery = document.getElementById('modal-cache-query');
        const modalKey = document.getElementById('modal-cache-key');
        const modalValue = document.getElementById('modal-cache-value');
        const modalRendered = document.getElementById('modal-cache-render-pane');
        const copyBtn = document.getElementById('modal-cache-copy');
        const tabBtnRender = document.getElementById('tab-btn-render');
        const tabBtnRaw = document.getElementById('tab-btn-raw');
        const rawPane = document.getElementById('modal-cache-raw-pane');
        const renderPane = document.getElementById('modal-cache-render-pane');

        if (!viewerTrigger || !tabBtnRender || !tabBtnRaw) return;

        let rawCacheValue = '';

        tabBtnRender.addEventListener('click', () => {
            tabBtnRender.classList.add('border-cyan-500', 'text-cyan-400');
            tabBtnRender.classList.remove('border-transparent', 'text-slate-400');
            tabBtnRaw.classList.add('border-transparent', 'text-slate-400');
            tabBtnRaw.classList.remove('border-cyan-500', 'text-cyan-400');
            renderPane.classList.remove('hidden');
            rawPane.classList.add('hidden');
        });

        tabBtnRaw.addEventListener('click', () => {
            tabBtnRaw.classList.add('border-cyan-500', 'text-cyan-400');
            tabBtnRaw.classList.remove('border-transparent', 'text-slate-400');
            tabBtnRender.classList.add('border-transparent', 'text-slate-400');
            tabBtnRender.classList.remove('border-cyan-500', 'text-cyan-400');
            rawPane.classList.remove('hidden');
            renderPane.classList.add('hidden');
        });

        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.view-cache-btn');
            if (!btn) return;

            const key = btn.dataset.key;
            const query = btn.dataset.query;

            modalQuery.textContent = query;
            modalKey.textContent = key;

            modalValue.textContent = 'Loading raw source...';
            modalRendered.innerHTML = '<span class="text-xs text-slate-500 animate-pulse">Loading parsed markdown preview...</span>';
            rawCacheValue = '';

            tabBtnRender.click();
            viewerTrigger.click();

            fetch(`index.php?api_action=get_cache&key=${encodeURIComponent(key)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        rawCacheValue = data.value;
                        let formattedValue = data.value;
                        let markdownText = data.value;

                        if (data.is_json) {
                            try {
                                formattedValue = JSON.stringify(data.decoded, null, 2);
                                const obj = data.decoded;
                                if (obj && typeof obj === 'object') {
                                    if (obj.response) markdownText = obj.response;
                                    else if (obj.message) markdownText = obj.message;
                                    else if (obj.text) markdownText = obj.text;
                                    else if (obj.content) markdownText = obj.content;
                                    else if (obj.result) {
                                        markdownText = typeof obj.result === 'string' ? obj.result : JSON.stringify(obj.result, null, 2);
                                    } else {
                                        markdownText = '```json\n' + formattedValue + '\n```';
                                    }
                                }
                            } catch (err) {}
                        }

                        modalValue.textContent = formattedValue;

                        if (typeof hljs !== 'undefined') {
                            delete modalValue.dataset.highlighted;
                            hljs.highlightElement(modalValue);
                        }

                        if (typeof marked !== 'undefined') {
                            modalRendered.innerHTML = marked.parse(markdownText);
                            const internalCodeBlocks = modalRendered.querySelectorAll('pre code');
                            internalCodeBlocks.forEach(block => {
                                if (typeof hljs !== 'undefined') {
                                    hljs.highlightElement(block);
                                }
                            });
                        } else {
                            modalRendered.innerHTML = markdownText.replace(/\n/g, '<br>');
                        }
                    } else {
                        const errMsg = `Error: ${data.message || 'Failed to fetch value.'}`;
                        modalValue.textContent = errMsg;
                        modalRendered.innerHTML = `<span class="text-rose-400 text-xs">${errMsg}</span>`;
                    }
                })
                .catch(err => {
                    const errMsg = `Error connecting to server: ${err.message}`;
                    modalValue.textContent = errMsg;
                    modalRendered.innerHTML = `<span class="text-rose-400 text-xs">${errMsg}</span>`;
                });
        });

        copyBtn.addEventListener('click', () => {
            const textToCopy = rawCacheValue || modalValue.textContent;
            navigator.clipboard.writeText(textToCopy).then(() => {
                const originalHTML = copyBtn.innerHTML;
                copyBtn.innerHTML = '<uk-icon icon="check" class="w-3.5 h-3.5 text-emerald-400"></uk-icon> Copied!';
                setTimeout(() => {
                    copyBtn.innerHTML = originalHTML;
                }, 2000);
            }).catch(err => {
                console.error('Copy failed: ', err);
            });
        });
    });
}

initQueryCacheTab();
