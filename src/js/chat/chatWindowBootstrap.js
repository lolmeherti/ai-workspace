/**
 * @file js/chat/chatWindowBootstrap.js
 * @description Bootstrap chat window modules and expose handlers on window for PHP onclick.
 */

import './chatMarkedInit.js';
import './chatDomInit.js';
import { toggleFileAccordion, showFileInExplorer, appendFileFromAccordion } from './chatFileAccordion.js';
import { deleteTodoistTaskDirectly, createTodoistTaskDirectly } from './chatTodoistActions.js';
import { openEmailDirectly } from './chatEmailCards.js';
import { addFileReference, removeFileReference, updateFileReferencesUI } from './chatFileReferences.js';
import { copyPathToClipboard, copyChatMessageToClipboard } from './chatClipboard.js';
import { parseInlineFiles } from './chatInlineFileParser.js';
import { openEditorDrawer, closeEditorDrawer, saveEditorDraft } from './chatEditorOpenClose.js';
import { renderEditorBlocks } from './chatEditorRenderBlocks.js';
import { toggleBlockSelection, isSelectionSequential, updateActiveTargetPill, clearActiveBlockToggles } from './chatEditorBlockSelection.js';
import { enableManualBlockEdit, handleBlockInput, enableFusedRangeEdit } from './chatEditorBlockEdit.js';
import { streamUpdateBlockContent, commitBlockEditDirectly, evaluateStreamCompletion } from './chatEditorBlockStream.js';
import { deleteSelectedBlocks, deleteSingleBlockDirectly } from './chatEditorBlockDelete.js';
import { triggerUnifiedBriefing } from './chatUnifiedBriefing.js';

window.toggleFileAccordion = toggleFileAccordion;
window.showFileInExplorer = showFileInExplorer;
window.appendFileFromAccordion = appendFileFromAccordion;
window.deleteTodoistTaskDirectly = deleteTodoistTaskDirectly;
window.createTodoistTaskDirectly = createTodoistTaskDirectly;
window.openEmailDirectly = openEmailDirectly;
window.addFileReference = addFileReference;
window.removeFileReference = removeFileReference;
window.updateFileReferencesUI = updateFileReferencesUI;
window.copyPathToClipboard = copyPathToClipboard;
window.copyToClipboard = copyChatMessageToClipboard;
window.parseInlineFiles = parseInlineFiles;
window.openEditorDrawer = openEditorDrawer;
window.closeEditorDrawer = closeEditorDrawer;
window.saveEditorDraft = saveEditorDraft;
window.renderEditorBlocks = renderEditorBlocks;
window.toggleBlockSelection = toggleBlockSelection;
window.isSelectionSequential = isSelectionSequential;
window.updateActiveTargetPill = updateActiveTargetPill;
window.clearActiveBlockToggles = clearActiveBlockToggles;
window.enableManualBlockEdit = enableManualBlockEdit;
window.handleBlockInput = handleBlockInput;
window.enableFusedRangeEdit = enableFusedRangeEdit;
window.streamUpdateBlockContent = streamUpdateBlockContent;
window.commitBlockEditDirectly = commitBlockEditDirectly;
window.evaluateStreamCompletion = evaluateStreamCompletion;
window.deleteSelectedBlocks = deleteSelectedBlocks;
window.deleteSingleBlockDirectly = deleteSingleBlockDirectly;
window.triggerUnifiedBriefing = triggerUnifiedBriefing;
