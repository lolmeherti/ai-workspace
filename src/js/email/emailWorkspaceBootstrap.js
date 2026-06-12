/**
 * @file js/email/emailWorkspaceBootstrap.js
 * @description Bootstrap email workspace modules and expose handlers on window.
 */

import './emailState.js';
import { loadInbox } from './emailInboxLoader.js';
import { navigateEmails } from './emailPagination.js';
import { loadEmailBody } from './emailBodyLoader.js';
import { toggleReplyForm, submitEmailReply } from './emailReplyForm.js';
import { triggerAiReplyAssist } from './emailAiReplyAssist.js';

window.loadInbox = loadInbox;
window.navigateEmails = navigateEmails;
window.loadEmailBody = loadEmailBody;
window.toggleReplyForm = toggleReplyForm;
window.submitEmailReply = submitEmailReply;
window.triggerAiReplyAssist = triggerAiReplyAssist;
