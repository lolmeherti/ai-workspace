/**
 * @file js/chat/chatMarkedInit.js
 * @description Initialize marked with KaTeX extension for chat markdown rendering.
 */

export function initChatMarked() {
    if (typeof marked !== 'undefined' && typeof markedKatex !== 'undefined') {
        marked.use(markedKatex({
            throwOnError: false,
            nonStandard: true
        }));
    }
}

initChatMarked();
