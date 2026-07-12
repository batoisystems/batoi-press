document.documentElement.classList.add('bp-js');

function setEditorFocusMode(editor, button, active) {
    if (active) {
        const current = document.querySelector('.uif-editor.bp-editor-focus-mode');
        if (current && current !== editor) {
            current.querySelector('.bp-editor-focus-toggle')?.click();
        }
        editor.dataset.bpScrollY = String(window.scrollY);
    }

    editor.classList.toggle('bp-editor-focus-mode', active);
    document.body.classList.toggle('bp-editor-focus-open', active);
    button.setAttribute('aria-pressed', active ? 'true' : 'false');
    button.setAttribute('aria-label', active ? 'Exit focus mode' : 'Focus mode');
    button.setAttribute('title', active ? 'Exit focus mode' : 'Focus mode');
    button.querySelector('span').textContent = active ? 'Exit focus' : 'Focus';

    if (active) {
        const editable = editor.querySelector('[contenteditable="true"], .uif-editor-source');
        editable?.focus({ preventScroll: true });
        return;
    }

    const scrollY = Number(editor.dataset.bpScrollY || 0);
    window.scrollTo({ top: scrollY });
    button.focus({ preventScroll: true });
}

function enhanceContentEditors() {
    document.querySelectorAll('[data-bp-content-editor]').forEach((host) => {
        if (host.dataset.bpEditorEnhanced === 'true') {
            return;
        }

        const editor = host.querySelector('.uif-editor');
        const toolbar = editor?.querySelector('.uif-editor-toolbar');
        const mediaSource = host.querySelector('[data-bp-editor-media]');
        if (!editor || !toolbar || !mediaSource) {
            return;
        }

        const mediaAction = document.createElement('a');
        mediaAction.className = 'uif-editor-button bp-editor-toolbar-action';
        mediaAction.href = mediaSource.href;
        mediaAction.target = '_blank';
        mediaAction.rel = 'noopener';
        mediaAction.setAttribute('aria-label', 'Open image media library');
        mediaAction.setAttribute('title', 'Open image media library');
        const mediaLabel = document.createElement('span');
        mediaLabel.textContent = 'Media';
        mediaAction.append(mediaLabel);

        const focusButton = document.createElement('button');
        focusButton.type = 'button';
        focusButton.className = 'uif-editor-button bp-editor-toolbar-action bp-editor-focus-toggle';
        focusButton.setAttribute('aria-label', 'Focus mode');
        focusButton.setAttribute('aria-pressed', 'false');
        focusButton.setAttribute('title', 'Focus mode');
        const focusLabel = document.createElement('span');
        focusLabel.textContent = 'Focus';
        focusButton.append(focusLabel);
        focusButton.addEventListener('click', () => {
            setEditorFocusMode(editor, focusButton, !editor.classList.contains('bp-editor-focus-mode'));
        });

        toolbar.append(mediaAction, focusButton);
        host.dataset.bpEditorEnhanced = 'true';
    });
}

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

    enhanceContentEditors();
    window.requestAnimationFrame(enhanceContentEditors);
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        const activeEditor = document.querySelector('.uif-editor.bp-editor-focus-mode');
        activeEditor?.querySelector('.bp-editor-focus-toggle')?.click();
    });
});
