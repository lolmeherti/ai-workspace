/**
 * @file js/email/emailPagination.js
 * @description Email inbox pagination navigation.
 */

export function navigateEmails(dir) {
    const nextPage = window.currentEmailPage + dir;
    if (nextPage >= 1 && nextPage <= window.totalEmailPages) {
        window.loadInbox(window.selectedEmailAccountId, nextPage);
    }
}
