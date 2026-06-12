/**
 * @file js/tabs/tabsEmailAccount.js
 * @description Email account form custom IMAP field toggle.
 */

export function toggleCustomImapFields(provider) {
    const wrapper = document.getElementById('custom-imap-wrapper');
    const hostInput = wrapper.querySelector('input[name="imap_host"]');
    const portInput = wrapper.querySelector('input[name="imap_port"]');
    if (provider === 'Custom IMAP') {
        wrapper.classList.remove('hidden');
        hostInput.required = true;
        portInput.required = true;
    } else {
        wrapper.classList.add('hidden');
        hostInput.required = false;
        portInput.required = false;
        hostInput.value = '';
        portInput.value = '';
    }
}
