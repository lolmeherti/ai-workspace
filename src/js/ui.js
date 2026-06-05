/**
 * @file js/ui.js
 * @description UI Components. Handles memory editing transitions, token budget indicators, and user warning modals.
 */

import { state } from './state.js';
import { streamResponse } from './streamer.js';

export function enableMemoryEdit(id) {
    document.getElementById(`memory-view-${id}`).classList.add('hidden');
    document.getElementById(`memory-edit-${id}`).classList.remove('hidden');
}

export function disableMemoryEdit(id) {
    document.getElementById(`memory-view-${id}`).getBoundingClientRect(); // triggers reflow
    document.getElementById(`memory-view-${id}`).classList.remove('hidden');
    document.getElementById(`memory-edit-${id}`).classList.add('hidden');
}

export function showCondensationModal(formData, originalMessage) {
    state.pendingFormData = formData;
    state.pendingMessage = originalMessage;
    
    document.getElementById('condensation-modal').classList.remove('hidden');
    document.getElementById('q').disabled = true;
    const submitBtn = document.querySelector('#chatForm button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;
}

export function closeCondensationModal() {
    document.getElementById('condensation-modal').classList.add('hidden');
    const inputField = document.getElementById('q');
    if (inputField) inputField.disabled = false;
    
    const submitBtn = document.querySelector('#chatForm button[type="submit"]');
    if (submitBtn) submitBtn.disabled = false;
}

export async function bypassCondensation() {
    closeCondensationModal();
    if (state.pendingFormData) {
        state.pendingFormData.set('bypass_warning', '1');
        await streamResponse(state.pendingFormData, state.pendingMessage);
    }
}

export async function confirmCondensation() {
    const modalContent = document.getElementById('condensation-modal-content');
    const modalLoading = document.getElementById('condensation-modal-loading');
    
    modalContent.classList.add('hidden');
    modalLoading.classList.remove('hidden');
    
    try {
        const formData = new FormData();
        formData.append('action', 'condense');
        formData.append('session_id', state.pendingFormData.get('session_id'));
        
        const response = await fetch('index.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.status === 'success') {
            closeCondensationModal();
            modalContent.classList.remove('hidden');
            modalLoading.classList.add('hidden');
            
            sessionStorage.setItem('pending_chat_prompt', state.pendingMessage);
            window.location.reload();
        } else {
            throw new Error(result.message || 'Failed to condense');
        }
    } catch (e) {
        alert("Something went wrong during condensation: " + e.message);
        modalContent.classList.remove('hidden');
        modalLoading.classList.add('hidden');
    }
}

export function updateTokenCounter(current, max) {
    const counterContainer = document.getElementById('token-counter-container');
    const counterText = document.getElementById('token-counter-text');
    const counterBar = document.getElementById('token-counter-bar');
    
    if (!counterContainer || !counterText || !counterBar) return;
    
    counterContainer.classList.remove('hidden');
    
    const formattedCurrent = current.toLocaleString();
    const formattedMax = max.toLocaleString();
    
    counterText.textContent = `${formattedCurrent} / ${formattedMax}`;
    
    const percentage = Math.min((current / max) * 100, 100);
    counterBar.style.width = `${percentage}%`;
    
    if (percentage >= 80) {
        counterBar.className = "h-full bg-rose-500 transition-all duration-300";
        counterText.className = "text-rose-400 font-bold";
    } else if (percentage >= 50) {
        counterBar.className = "h-full bg-amber-500 transition-all duration-300";
        counterText.className = "text-amber-400 font-bold";
    } else {
        counterBar.className = "h-full bg-cyan-500 transition-all duration-300";
        counterText.className = "text-slate-200 font-bold";
    }
}