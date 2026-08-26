/**
 * @file js/tabs/tabsEmailAccount.js
 * @description Email account form custom IMAP field toggle + AJAX add-account
 *              submit with live connection test.
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

export function submitEmailAccount(event) {
    event.preventDefault();
    const form = event.target;
    const errorBox = document.getElementById('add-email-error');
    const btn = form.querySelector('button[type="submit"]');
    const originalText = btn ? btn.textContent : 'Connect Account';

    if (errorBox) {
        errorBox.classList.add('hidden');
        errorBox.textContent = '';
    }

    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Testing connection...';
    }

    fetch('index.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: new FormData(form)
    })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                // Account verified + saved. Reload so the sidebar list and
                // Com Deck select pick up the new (authenticated) account.
                window.location.reload();
                return;
            }
            if (btn) {
                btn.disabled = false;
                btn.textContent = originalText;
            }
            if (errorBox) {
                errorBox.textContent = formatAccountError(data);
                errorBox.classList.remove('hidden');
            }
        })
        .catch(err => {
            if (btn) {
                btn.disabled = false;
                btn.textContent = originalText;
            }
            if (errorBox) {
                errorBox.textContent = 'Network error: ' + err.message;
                errorBox.classList.remove('hidden');
            }
        });

    return false;
}

function formatAccountError(data) {
    const msg = data.message || 'Could not connect the account.';
    if (data.type === 'IMAP_ERROR' && data.detail && data.detail !== data.message) {
        return msg + ' — ' + data.detail;
    }
    return msg;
}
