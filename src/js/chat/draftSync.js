import { requestJson, notify } from '../workspace/feedback.js';
let queue = Promise.resolve();
let failure = null;
let changes = new Map();
const revisions = new Map();
let timer;
export function queueDraftChange(blockId, content, replaceRange) {
    const file = window.activeEditFile;
    if (!file) return;
    const key = file + '\u0000' + blockId;
    const revision = (revisions.get(key) || 0) + 1;
    revisions.set(key, revision);
    changes.set(key, { revision, payload: { file, block_id: blockId, content, ...(replaceRange ? { replace_range: replaceRange } : {}) } });
    clearTimeout(timer); timer = setTimeout(() => { flushDraftChanges().catch(() => {}); }, 450);
}
export async function flushDraftChanges() {
    clearTimeout(timer);
    for (const [key, entry] of changes) {
        const { revision, payload: change } = entry;
        changes.delete(key);
        queue = queue.catch(() => {}).then(async () => {
            try {
                const data = await requestJson('index.php?api_action=update_draft', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(change) });
                if (window.activeEditFile === change.file) window.activeBlocks = data.blocks;
                failure = null;
            } catch (e) {
                failure = e;
                // A newer edit may already be in the queue. Retrying an older
                // failed write after it would overwrite the user's latest text.
                if (revisions.get(key) === revision && !changes.has(key)) changes.set(key, entry);
                notify('Draft could not sync. Your typed text is kept. Save again to retry.', { target: document.getElementById('editor-notices') });
                throw e;
            }
        });
    }
    await queue;
    if (failure || changes.size) throw failure || new Error('Some draft changes are still waiting to sync.');
}
