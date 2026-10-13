
/**
 * @file js/state.js
 * @description Centralized state container. Stores reactive application state like generation status, selected files, and edit modes.
 */
export const state = {
    activeTab: 'chats',
    sessionId: 0,
    generation: null,
    jobRun: null,
    memoryConsolidating: false,
    /** Last read of the app's own background work for this conversation (see chat/pipelineStatus.js). Null = not read yet. */
    pipeline: null,
    contextLocked: false,
    isGenerating: false,
    pastedImageFile: null,
    pendingFormData: null,
    pendingMessage: null,
    selectedFile: null,
    isChatEditMode: false,
    selectedChatIds: []
};
