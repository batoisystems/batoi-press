document.documentElement.classList.add('bp-js');

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.bp-template-code-editor').forEach((editor) => {
        editor.disabled = false;
        editor.readOnly = false;
        editor.removeAttribute('disabled');
        editor.removeAttribute('readonly');
        editor.setAttribute('aria-readonly', 'false');
    });

    document.querySelectorAll('[data-copy-target]').forEach((button) => {
        button.addEventListener('click', async () => {
            const targetId = button.getAttribute('data-copy-target');
            const target = targetId ? document.getElementById(targetId) : null;
            if (!(target instanceof HTMLInputElement)) {
                return;
            }

            try {
                await navigator.clipboard.writeText(target.value);
            } catch (_error) {
                target.select();
                document.execCommand('copy');
            }

            const label = button.querySelector('span');
            if (label) {
                const original = label.textContent;
                label.textContent = 'Copied';
                window.setTimeout(() => {
                    label.textContent = original;
                }, 1600);
            }
        });
    });

    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const message = form.getAttribute('data-confirm');
            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
});
