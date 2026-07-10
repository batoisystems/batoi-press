document.documentElement.classList.add('bp-js');

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.bp-template-code-editor').forEach((editor) => {
        editor.disabled = false;
        editor.readOnly = false;
        editor.removeAttribute('disabled');
        editor.removeAttribute('readonly');
        editor.setAttribute('aria-readonly', 'false');
    });
});
