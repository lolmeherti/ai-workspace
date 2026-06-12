/**
 * @file js/email/emailState.js
 * @description Shared pagination and selection state for the email workspace.
 */

export function initEmailState() {
    window.currentEmailPage = window.currentEmailPage ?? 1;
    window.totalEmailPages = window.totalEmailPages ?? 1;
    window.selectedEmailAccountId = window.selectedEmailAccountId ?? null;
    window.selectedEmailUid = window.selectedEmailUid ?? null;
}

initEmailState();
