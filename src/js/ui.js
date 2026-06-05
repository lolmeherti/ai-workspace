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

    document.getElementById('condensation-modal-content').classList.remove('hidden');
    document.getElementById('condensation-modal-review').classList.add('hidden');
    document.getElementById('condensation-modal-loading').classList.add('hidden');

    const modalCard = document.getElementById('condensation-modal-card');
    if (modalCard) {
        modalCard.classList.remove('max-w-lg');
        modalCard.classList.add('max-w-md', 'text-center');
    }
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
    const modalReview = document.getElementById('condensation-modal-review');
    const loadingText = document.getElementById('condensation-loading-text');
    
    modalContent.classList.add('hidden');
    modalReview.classList.add('hidden');
    modalLoading.classList.remove('hidden');
    if (loadingText) {
        loadingText.textContent = "Extracting candidate memories...";
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'condense');
        formData.append('session_id', state.pendingFormData.get('session_id'));
        formData.append('commit', '0'); // Dry run
        
        const response = await fetch('index.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.status === 'success') {
            modalLoading.classList.add('hidden');
            
            state.condensationSummary = result.summary;
            state.condensationMemories = result.memories || [];
            
            const memoriesListContainer = document.getElementById('condensation-memories-list');
            if (memoriesListContainer) {
                memoriesListContainer.innerHTML = '';
                
                if (state.condensationMemories.length === 0) {
                    const emptyMsg = document.createElement('div');
                    emptyMsg.className = "text-center py-8 text-xs text-slate-500 italic border border-dashed border-slate-800 rounded-xl";
                    emptyMsg.textContent = "No persistent memories extracted from this context segment.";
                    memoriesListContainer.appendChild(emptyMsg);
                } else {
                    state.condensationMemories.forEach((memory, idx) => {
                        const item = document.createElement('label');
                        item.className = "flex items-start gap-3 bg-[#0a1122]/80 border border-cyan-500/10 hover:border-cyan-500/35 hover:shadow-[0_0_12px_rgba(6,182,212,0.08)] rounded-xl p-3.5 cursor-pointer transition-all duration-150 select-none border-l-2 border-l-cyan-500/40";
                        item.innerHTML = `
                            <input type="checkbox" checked value="${idx}" class="mt-0.5 rounded border-cyan-500/30 text-cyan-500 focus:ring-cyan-500/40 focus:ring-offset-0 focus:outline-none h-4.5 w-4.5 bg-[#0f172a] cursor-pointer" />
                            <span class="text-xs text-slate-200 font-medium leading-relaxed">${memory}</span>
                        `;
                        memoriesListContainer.appendChild(item);
                    });
                }
            }
            
            const modalCard = document.getElementById('condensation-modal-card');
            if (modalCard) {
                modalCard.classList.remove('max-w-md', 'text-center');
                modalCard.classList.add('max-w-lg');
            }
            
            modalReview.classList.remove('hidden');
        } else {
            throw new Error(result.message || 'Failed to condense');
        }
    } catch (e) {
        alert("Something went wrong during condensation analysis: " + e.message);
        closeCondensationModal();
    }
}

export async function applyCondensation() {
    const modalReview = document.getElementById('condensation-modal-review');
    const modalLoading = document.getElementById('condensation-modal-loading');
    const loadingText = document.getElementById('condensation-loading-text');
    
    const checkboxes = document.querySelectorAll('#condensation-memories-list input[type="checkbox"]:checked');
    const selectedMemories = [];
    
    checkboxes.forEach(cb => {
        const index = parseInt(cb.value, 10);
        if (!isNaN(index) && state.condensationMemories[index]) {
            selectedMemories.push(state.condensationMemories[index]);
        }
    });

    modalReview.classList.add('hidden');
    modalLoading.classList.remove('hidden');
    if (loadingText) {
        loadingText.textContent = "Committing approved memories to database...";
    }

    try {
        const formData = new FormData();
        formData.append('action', 'condense');
        formData.append('session_id', state.pendingFormData.get('session_id'));
        formData.append('commit', '1'); // Commit execution
        formData.append('summary', state.condensationSummary || '');
        
        selectedMemories.forEach(memory => {
            formData.append('selected_memories[]', memory);
        });

        const response = await fetch('index.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.status === 'success') {
            closeCondensationModal();
            
            sessionStorage.setItem('pending_chat_prompt', state.pendingMessage);
            window.location.reload();
        } else {
            throw new Error(result.message || 'Failed to write data');
        }
    } catch (e) {
        alert("Memory write operation failed: " + e.message);
        modalReview.classList.remove('hidden');
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