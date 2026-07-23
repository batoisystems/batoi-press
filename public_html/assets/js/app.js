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

function activeEditorSurface(editor) {
    const source = editor.querySelector('.uif-editor-source');
    if (source instanceof HTMLTextAreaElement && !source.hidden) {
        return source;
    }
    return editor.querySelector('.uif-editor-surface[contenteditable="true"]');
}

function insertEditorSnippet(host, kind) {
    const editor = host.querySelector('.uif-editor');
    const surface = editor ? activeEditorSurface(editor) : null;
    if (!editor || !surface) {
        return;
    }

    const snippets = {
        'inline-math': { content: '\\( E = mc^2 \\)', selected: 'E = mc^2', html: false },
        'display-math': { content: '\\[\nE = mc^2\n\\]', selected: 'E = mc^2', html: false },
        'code-block': { content: '<pre><code class="language-text">your_code_here()</code></pre>', selected: 'your_code_here()', html: true },
    };
    const snippet = snippets[kind];
    if (!snippet) {
        return;
    }

    if (surface instanceof HTMLTextAreaElement) {
        const start = surface.selectionStart ?? surface.value.length;
        const end = surface.selectionEnd ?? start;
        const prefix = start > 0 && surface.value[start - 1] !== '\n' ? '\n' : '';
        const suffix = end < surface.value.length && surface.value[end] !== '\n' ? '\n' : '';
        const inserted = prefix + snippet.content + suffix;
        surface.setRangeText(inserted, start, end, 'end');
        const selectedOffset = inserted.indexOf(snippet.selected);
        if (selectedOffset >= 0) {
            surface.setSelectionRange(start + selectedOffset, start + selectedOffset + snippet.selected.length);
        }
        surface.dispatchEvent(new Event('input', { bubbles: true }));
        surface.focus();
        return;
    }

    const selection = window.getSelection();
    let range = selection?.rangeCount ? selection.getRangeAt(0) : null;
    if (!range || !surface.contains(range.commonAncestorContainer)) {
        range = document.createRange();
        range.selectNodeContents(surface);
        range.collapse(false);
    }
    range.deleteContents();

    let selectedNode;
    if (snippet.html) {
        const pre = document.createElement('pre');
        const code = document.createElement('code');
        code.className = 'language-text';
        code.textContent = snippet.selected;
        pre.append(code);
        range.insertNode(pre);
        selectedNode = code.firstChild;
    } else {
        const text = document.createTextNode(snippet.content);
        range.insertNode(text);
        selectedNode = text;
    }
    surface.dispatchEvent(new Event('input', { bubbles: true }));
    if (selection && selectedNode) {
        const selectedOffset = selectedNode.textContent?.indexOf(snippet.selected) ?? -1;
        if (selectedOffset >= 0) {
            const selectedRange = document.createRange();
            selectedRange.setStart(selectedNode, selectedOffset);
            selectedRange.setEnd(selectedNode, selectedOffset + snippet.selected.length);
            selection.removeAllRanges();
            selection.addRange(selectedRange);
        }
    }
    surface.focus();
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

        const inlineMathButton = document.createElement('button');
        inlineMathButton.type = 'button';
        inlineMathButton.className = 'uif-editor-button bp-editor-toolbar-action';
        inlineMathButton.setAttribute('aria-label', 'Insert inline LaTeX');
        inlineMathButton.setAttribute('title', 'Insert inline LaTeX');
        inlineMathButton.innerHTML = '<span>Math</span>';
        inlineMathButton.addEventListener('click', () => insertEditorSnippet(host, 'inline-math'));

        const codeBlockButton = document.createElement('button');
        codeBlockButton.type = 'button';
        codeBlockButton.className = 'uif-editor-button bp-editor-toolbar-action';
        codeBlockButton.setAttribute('aria-label', 'Insert code block');
        codeBlockButton.setAttribute('title', 'Insert code block');
        codeBlockButton.innerHTML = '<span>Code block</span>';
        codeBlockButton.addEventListener('click', () => insertEditorSnippet(host, 'code-block'));

        host.querySelectorAll('[data-bp-editor-insert]').forEach((button) => {
            button.addEventListener('click', () => {
                insertEditorSnippet(host, button.getAttribute('data-bp-editor-insert'));
            });
        });

        toolbar.append(mediaAction, inlineMathButton, codeBlockButton, focusButton);
        host.dataset.bpEditorEnhanced = 'true';
    });
}

function copyText(value) {
    if (navigator.clipboard?.writeText) {
        return navigator.clipboard.writeText(value);
    }
    const fallback = document.createElement('textarea');
    fallback.value = value;
    fallback.setAttribute('readonly', '');
    fallback.style.position = 'fixed';
    fallback.style.opacity = '0';
    document.body.append(fallback);
    fallback.select();
    document.execCommand('copy');
    fallback.remove();
    return Promise.resolve();
}

function enhancePublicCodeBlocks() {
    document.querySelectorAll('.bp-public-body .bp-prose pre > code').forEach((code) => {
        const pre = code.parentElement;
        if (!(pre instanceof HTMLElement) || pre.parentElement?.classList.contains('bp-code-block')) {
            return;
        }

        const languageClass = Array.from(code.classList).find((className) => className.startsWith('language-'));
        const language = languageClass ? languageClass.slice('language-'.length) : 'Code';
        const wrapper = document.createElement('div');
        wrapper.className = 'bp-code-block';
        const header = document.createElement('div');
        header.className = 'bp-code-block-header';
        const label = document.createElement('span');
        label.textContent = language === 'Code' ? language : language.toUpperCase();
        const copy = document.createElement('button');
        copy.type = 'button';
        copy.className = 'bp-code-copy';
        copy.textContent = 'Copy';
        copy.setAttribute('aria-label', 'Copy code block');
        copy.addEventListener('click', async () => {
            await copyText(code.textContent || '');
            copy.textContent = 'Copied';
            window.setTimeout(() => {
                copy.textContent = 'Copy';
            }, 1600);
        });
        header.append(label, copy);
        pre.before(wrapper);
        wrapper.append(header, pre);
        pre.setAttribute('tabindex', '0');
    });
}

function loadMathJaxWhenNeeded() {
    const prose = document.querySelector('.bp-public-body .bp-prose');
    const text = prose?.textContent || '';
    if (!prose || !/\\\([\s\S]+?\\\)|\\\[[\s\S]+?\\\]/.test(text) || document.querySelector('script[data-bp-mathjax]')) {
        return;
    }

    window.MathJax = window.MathJax || {
        tex: {
            inlineMath: [['\\(', '\\)']],
            displayMath: [['\\[', '\\]']],
        },
    };
    const script = document.createElement('script');
    script.src = 'https://cdn.jsdelivr.net/npm/mathjax@4.0.0/tex-mml-chtml.js';
    script.defer = true;
    script.dataset.bpMathjax = 'true';
    document.head.append(script);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-bp-slug-source]').forEach((source) => {
        const form = source.closest('form');
        const target = form?.querySelector('[data-bp-slug-target]');
        if (!(source instanceof HTMLInputElement) || !(target instanceof HTMLInputElement)) {
            return;
        }
        let automatic = target.value.trim() === '';
        const slugify = (value) => value
            .normalize('NFKD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
        source.addEventListener('input', () => {
            if (automatic) {
                target.value = slugify(source.value);
            }
        });
        target.addEventListener('input', () => {
            automatic = target.value.trim() === '';
        });
    });

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

    document.querySelectorAll('[data-bp-reorder-list]').forEach((list) => {
        list.addEventListener('click', (event) => {
            const button = event.target.closest('[data-bp-move]');
            const row = button?.closest('.bp-reorder-row');
            if (!button || !row) {
                return;
            }
            const direction = button.getAttribute('data-bp-move');
            const sibling = direction === 'up' ? row.previousElementSibling : row.nextElementSibling;
            if (!sibling) {
                return;
            }
            if (direction === 'up') {
                list.insertBefore(row, sibling);
            } else {
                list.insertBefore(sibling, row);
            }
            row.querySelector('input, textarea')?.focus();
        });
    });

    enhanceContentEditors();
    window.requestAnimationFrame(enhanceContentEditors);
    enhancePublicCodeBlocks();
    loadMathJaxWhenNeeded();
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        const activeEditor = document.querySelector('.uif-editor.bp-editor-focus-mode');
        activeEditor?.querySelector('.bp-editor-focus-toggle')?.click();
    });
});
