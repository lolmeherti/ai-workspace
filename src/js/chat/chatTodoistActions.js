/**
 * @file js/chat/chatTodoistActions.js
 * @description Direct Todoist task create/delete actions from chat inline cards.
 */

export function deleteTodoistTaskDirectly(taskId, button) {
    if (!taskId) return;

    const originalHTML = button.innerHTML;
    button.disabled = true;
    button.innerHTML = `
        <svg class="animate-spin h-3.5 w-3.5 text-rose-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        Deleting...
    `;

    fetch(`index.php?api_action=delete_todoist_task&task_id=${encodeURIComponent(taskId)}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                button.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-emerald-400"><polyline points="20 6 9 17 4 12"/></svg>
                    Deleted!
                `;
                button.className = button.className
                    .replace('text-rose-400', 'text-emerald-400')
                    .replace('border-rose-500/30', 'border-emerald-500/40')
                    .replace('bg-rose-950/40', 'bg-emerald-950/20');

                const bubble = button.closest('.chat-assistant');
                if (bubble) {
                    bubble.classList.remove('streaming', 'generating', 'typing');
                    const cursor = bubble.querySelector('.streaming-cursor, .typing-indicator, .cursor, .pending-cursor, span[class*="cursor"]');
                    if (cursor) {
                        cursor.remove();
                    }
                }

                const card = button.closest('.todoist-delete-card');
                if (card) {
                    setTimeout(() => {
                        card.style.transition = 'all 0.5s ease';
                        card.style.opacity = '0';
                        card.style.maxHeight = '0';
                        card.style.padding = '0';
                        card.style.margin = '0';
                        card.style.border = 'none';
                        setTimeout(() => card.remove(), 500);
                    }, 1500);
                }
            } else {
                alert(`Error deleting task: ${data.message}`);
                button.innerHTML = originalHTML;
                button.disabled = false;
            }
        })
        .catch(err => {
            alert(`Error communicating with system: ${err.message}`);
            button.innerHTML = originalHTML;
            button.disabled = false;
        });
}

export function createTodoistTaskDirectly(content, dueString, button, bypass = false) {
    if (!content) return;

    const originalHTML = button.innerHTML;
    button.disabled = true;
    button.innerHTML = `
        <svg class="animate-spin h-3.5 w-3.5 text-indigo-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        Scheduling...
    `;

    const bypassParam = bypass ? "&bypass=1" : "";

    fetch(`index.php?api_action=create_todoist_task&content=${encodeURIComponent(content)}&due_string=${encodeURIComponent(dueString)}${bypassParam}`)
        .then(async res => {
            const text = await res.text();
            console.log("RAW PHP OUTPUT:", text);
            return JSON.parse(text);
        })
        .then(data => {
            if (data.status === 'success') {
                button.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-emerald-400"><polyline points="20 6 9 17 4 12"/></svg>
                    Scheduled!
                `;
                button.className = button.className
                    .replace('text-indigo-400', 'text-emerald-400')
                    .replace('border-indigo-500/30', 'border-emerald-500/40')
                    .replace('bg-indigo-950/40', 'bg-emerald-950/20');

                const card = button.closest('.todoist-suggest-card');
                if (card) {
                    setTimeout(() => {
                        card.style.transition = 'all 0.5s ease';
                        card.style.opacity = '0';
                        card.style.maxHeight = '0';
                        card.style.padding = '0';
                        card.style.margin = '0';
                        card.style.border = 'none';
                        setTimeout(() => card.remove(), 500);
                    }, 1500);
                }
            } else if (data.status === 'conflict') {
                button.innerHTML = originalHTML;
                button.disabled = false;

                if (confirm(data.message)) {
                    createTodoistTaskDirectly(content, dueString, button, true);
                }
            } else {
                alert(`Error creating task: ${data.message}`);
                button.innerHTML = originalHTML;
                button.disabled = false;
            }
        })
        .catch(err => {
            alert(`Error communicating with system: ${err.message}`);
            button.innerHTML = originalHTML;
            button.disabled = false;
        });
}
