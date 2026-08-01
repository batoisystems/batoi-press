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

function enhanceMenuBuilder() {
    document.querySelectorAll('[data-bp-menu-builder]').forEach((builder) => {
        const list = builder.querySelector('[data-bp-menu-list]');
        const template = builder.querySelector('[data-bp-menu-template]');
        const preview = builder.querySelector('[data-bp-menu-preview]');
        const empty = builder.querySelector('[data-bp-menu-empty]');
        if (!(list instanceof HTMLElement) || !(template instanceof HTMLTemplateElement)) {
            return;
        }

        const rows = () => Array.from(list.querySelectorAll('[data-bp-menu-item]'));
        const rowId = (row) => row.getAttribute('data-item-id') || '';
        const rowLabel = (row) => row.querySelector('[data-bp-menu-label]')?.value.trim() || 'Untitled item';
        const rowType = (row) => row.querySelector('[data-bp-menu-type]')?.value || 'link';
        const rowParent = (row) => row.querySelector('[data-bp-menu-parent]')?.value || '';

        const makeId = () => {
            const random = window.crypto?.randomUUID?.().replaceAll('-', '').slice(0, 16)
                || `${Date.now().toString(16)}${Math.random().toString(16).slice(2, 10)}`;
            return `mi_${random}`;
        };

        const updateParentOptions = () => {
            const currentRows = rows();
            currentRows.forEach((row) => {
                const select = row.querySelector('[data-bp-menu-parent]');
                if (!(select instanceof HTMLSelectElement)) return;
                const selected = select.value;
                const id = rowId(row);
                select.replaceChildren(new Option('Top level', ''));
                currentRows.forEach((candidate) => {
                    if (candidate === row || rowType(candidate) === 'separator') return;
                    select.add(new Option(rowLabel(candidate), rowId(candidate)));
                });
                if (selected !== id && Array.from(select.options).some((option) => option.value === selected)) {
                    select.value = selected;
                }
            });
        };

        const depthFor = (row, currentRows) => {
            const byId = new Map(currentRows.map((candidate) => [rowId(candidate), candidate]));
            const seen = new Set([rowId(row)]);
            let parent = rowParent(row);
            let depth = 0;
            while (parent && byId.has(parent) && !seen.has(parent) && depth < 3) {
                seen.add(parent);
                depth += 1;
                parent = rowParent(byId.get(parent));
            }
            return depth;
        };

        const updateRow = (row, currentRows) => {
            const label = rowLabel(row);
            const type = rowType(row);
            const url = row.querySelector('[data-bp-menu-url]');
            row.querySelector('[data-bp-menu-summary-label]').textContent = label || 'New menu item';
            row.querySelector('[data-bp-menu-summary-type]').textContent = type.charAt(0).toUpperCase() + type.slice(1);
            row.querySelector('[data-bp-menu-summary-url]').textContent = url?.value.trim() || '';
            row.style.setProperty('--bp-menu-depth', String(depthFor(row, currentRows)));

            const hasDestination = !['heading', 'separator'].includes(type);
            if (url instanceof HTMLInputElement) {
                url.disabled = !hasDestination;
                url.required = hasDestination;
            }
            const labelInput = row.querySelector('[data-bp-menu-label]');
            if (labelInput instanceof HTMLInputElement) {
                labelInput.required = type !== 'separator';
            }
            row.classList.toggle('is-disabled', !(row.querySelector('[data-bp-menu-enabled]')?.checked ?? true));
        };

        const buildPreview = () => {
            if (!(preview instanceof HTMLElement)) return;
            const currentRows = rows();
            const children = new Map();
            currentRows.forEach((row) => {
                const parent = rowParent(row);
                if (!children.has(parent)) children.set(parent, []);
                children.get(parent).push(row);
            });

            const renderLevel = (parent, trail = new Set()) => {
                const levelRows = children.get(parent) || [];
                if (!levelRows.length) return null;
                const listNode = document.createElement('ol');
                levelRows.forEach((row) => {
                    const id = rowId(row);
                    if (trail.has(id)) return;
                    const item = document.createElement('li');
                    if (!(row.querySelector('[data-bp-menu-enabled]')?.checked ?? true)) item.classList.add('is-disabled');
                    const summary = document.createElement('span');
                    const strong = document.createElement('strong');
                    strong.textContent = rowLabel(row);
                    const small = document.createElement('small');
                    const presentation = row.querySelector('[data-bp-menu-presentation]')?.value || 'link';
                    const column = row.querySelector('[data-bp-menu-column]')?.value || '1';
                    small.textContent = `${rowType(row)}${presentation !== 'link' ? ` · ${presentation}` : ''}${presentation === 'mega' ? ` · column ${column}` : ''}`;
                    summary.append(strong, small);
                    item.append(summary);
                    const nextTrail = new Set(trail);
                    nextTrail.add(id);
                    const childList = renderLevel(id, nextTrail);
                    if (childList) item.append(childList);
                    listNode.append(item);
                });
                return listNode;
            };

            preview.replaceChildren(renderLevel('') || Object.assign(document.createElement('p'), { textContent: 'No menu items configured.' }));
        };

        const refresh = () => {
            updateParentOptions();
            const currentRows = rows();
            currentRows.forEach((row) => updateRow(row, currentRows));
            empty?.classList.toggle('is-visible', currentRows.length === 0);
            buildPreview();
        };

        const addItem = (values = {}) => {
            if (rows().length >= 100) return;
            const id = makeId();
            const fragment = template.content.cloneNode(true);
            fragment.querySelectorAll('[name], [data-item-id]').forEach((element) => {
                if (element.hasAttribute('name')) element.setAttribute('name', element.getAttribute('name').replaceAll('__ID__', id));
                if (element.hasAttribute('data-item-id')) element.setAttribute('data-item-id', id);
            });
            fragment.querySelector('input[name="item_order[]"]')?.setAttribute('value', id);
            const idInput = fragment.querySelector(`input[name="menu_items[${id}][id]"]`);
            if (idInput) idInput.value = id;
            const row = fragment.querySelector('[data-bp-menu-item]');
            if (!row) return;
            row.querySelector('[data-bp-menu-label]').value = values.label || '';
            row.querySelector('[data-bp-menu-url]').value = values.url || '';
            row.querySelector('[data-bp-menu-type]').value = values.type || 'link';
            list.append(fragment);
            refresh();
            row.querySelector('[data-bp-menu-label]')?.focus();
        };

        builder.addEventListener('click', (event) => {
            const add = event.target.closest('[data-bp-add-menu-item]');
            if (add) {
                addItem();
                return;
            }
            const library = event.target.closest('[data-bp-add-menu-library]');
            if (library) {
                addItem({
                    label: library.getAttribute('data-label') || '',
                    url: library.getAttribute('data-url') || '',
                    type: library.getAttribute('data-type') || 'link',
                });
                return;
            }
            const action = event.target.closest('[data-bp-menu-action], [data-bp-move]');
            const row = action?.closest('[data-bp-menu-item]');
            if (!action || !row) return;
            const kind = action.getAttribute('data-bp-menu-action') || action.getAttribute('data-bp-move');
            if (kind === 'remove') {
                if (window.confirm(`Remove “${rowLabel(row)}” from this menu?`)) {
                    const removedId = rowId(row);
                    rows().forEach((candidate) => {
                        const parent = candidate.querySelector('[data-bp-menu-parent]');
                        if (parent?.value === removedId) parent.value = rowParent(row);
                    });
                    row.remove();
                }
            } else if (kind === 'indent') {
                const previous = row.previousElementSibling;
                if (previous?.matches('[data-bp-menu-item]')) row.querySelector('[data-bp-menu-parent]').value = rowId(previous);
            } else if (kind === 'outdent') {
                const parentId = rowParent(row);
                const parent = rows().find((candidate) => rowId(candidate) === parentId);
                row.querySelector('[data-bp-menu-parent]').value = parent ? rowParent(parent) : '';
            } else {
                const sibling = kind === 'up' ? row.previousElementSibling : row.nextElementSibling;
                if (sibling) {
                    if (kind === 'up') list.insertBefore(row, sibling);
                    else list.insertBefore(sibling, row);
                }
            }
            refresh();
            action.focus();
        });

        builder.addEventListener('input', refresh);
        builder.addEventListener('change', refresh);

        let dragged = null;
        list.addEventListener('dragstart', (event) => {
            const row = event.target.closest('[data-bp-menu-item]');
            if (!row) return;
            dragged = row;
            row.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
        });
        list.addEventListener('dragover', (event) => {
            if (!dragged) return;
            event.preventDefault();
            const target = event.target.closest('[data-bp-menu-item]');
            if (!target || target === dragged) return;
            const box = target.getBoundingClientRect();
            list.insertBefore(dragged, event.clientY < box.top + box.height / 2 ? target : target.nextElementSibling);
        });
        list.addEventListener('dragend', () => {
            dragged?.classList.remove('is-dragging');
            dragged = null;
            refresh();
        });

        refresh();
    });
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

function enhanceAifPanels() {
    document.querySelectorAll('[data-bp-aif-panel]').forEach((panel) => {
        if (panel.dataset.bpAifEnhanced === 'true') return;
        const form = panel.closest('form');
        const result = panel.querySelector('[data-bp-aif-result]');
        const endpoint = panel.getAttribute('data-endpoint') || '';
        if (!(form instanceof HTMLFormElement) || !(result instanceof HTMLElement) || !endpoint) return;

        const fieldValue = (name) => {
            const field = form.elements.namedItem(name);
            if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement) {
                return field.value;
            }
            if (name === 'body') {
                return Array.from(form.querySelectorAll('[name^="body_text["]'))
                    .map((input) => input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement ? input.value : '')
                    .filter(Boolean)
                    .join(' ');
            }
            return '';
        };
        const setField = (name, value) => {
            const field = form.elements.namedItem(name);
            if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement)) return false;
            field.value = Array.isArray(value) ? value.join(', ') : String(value ?? '');
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.focus();
            return true;
        };
        const renderSuggestions = (suggestions) => {
            result.replaceChildren();
            if (typeof suggestions.score === 'number') {
                const score = document.createElement('strong');
                score.className = 'bp-aif-score';
                score.textContent = `Content health ${suggestions.score}/100`;
                result.append(score);
            }
            if (Array.isArray(suggestions.checks)) {
                const list = document.createElement('ul');
                list.className = 'bp-aif-checks';
                suggestions.checks.forEach((check) => {
                    const item = document.createElement('li');
                    item.className = check.passed ? 'is-passed' : 'is-warning';
                    item.textContent = `${check.passed ? 'Pass' : 'Review'}: ${check.guidance || check.id}`;
                    list.append(item);
                });
                result.append(list);
            }
            const applyFields = new Set(['seo_title', 'seo_description', 'subtitle', 'tags', 'featured_image_alt']);
            Object.entries(suggestions).forEach(([name, value]) => {
                if (['score', 'checks', 'word_count'].includes(name)) return;
                const card = document.createElement('article');
                const label = document.createElement('strong');
                label.textContent = name.replaceAll('_', ' ');
                const output = document.createElement('p');
                output.textContent = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
                card.append(label, output);
                if (applyFields.has(name) && form.elements.namedItem(name)) {
                    const apply = document.createElement('button');
                    apply.type = 'button';
                    apply.className = 'bp-button bp-button-secondary';
                    apply.textContent = `Apply ${name.replaceAll('_', ' ')}`;
                    apply.addEventListener('click', () => {
                        if (setField(name, value)) apply.textContent = 'Applied — review before saving';
                    });
                    card.append(apply);
                } else {
                    const copy = document.createElement('button');
                    copy.type = 'button';
                    copy.className = 'bp-button bp-button-secondary';
                    copy.textContent = 'Copy suggestion';
                    copy.addEventListener('click', async () => {
                        await copyText(output.textContent || '');
                        copy.textContent = 'Copied';
                    });
                    card.append(copy);
                }
                result.append(card);
            });
        };

        panel.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-bp-aif-task]');
            if (!(button instanceof HTMLButtonElement)) return;
            const task = button.getAttribute('data-bp-aif-task') || '';
            const csrf = fieldValue('csrf_token');
            const body = new URLSearchParams({
                format: 'json',
                csrf_token: csrf,
                task,
                content_type: panel.getAttribute('data-content-type') || '',
                title: fieldValue('title'),
                body: fieldValue('body'),
                seo_title: fieldValue('seo_title'),
                seo_description: fieldValue('seo_description'),
                subtitle: fieldValue('subtitle'),
                category: fieldValue('category'),
                tags: fieldValue('tags'),
                featured_image: fieldValue('featured_image'),
                featured_image_alt: fieldValue('featured_image_alt'),
            });
            const buttons = Array.from(panel.querySelectorAll('[data-bp-aif-task]'));
            buttons.forEach((candidate) => { candidate.disabled = true; });
            button.textContent = 'Working…';
            result.hidden = false;
            result.classList.remove('is-error');
            result.textContent = 'Batoi AIF is preparing a suggestion.';
            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                    body: body.toString(),
                    credentials: 'same-origin',
                });
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.error || 'Batoi AIF request failed.');
                renderSuggestions(payload.suggestions || {});
                result.focus({ preventScroll: true });
            } catch (error) {
                result.classList.add('is-error');
                result.textContent = error instanceof Error ? error.message : 'Batoi AIF request failed.';
            } finally {
                buttons.forEach((candidate) => { candidate.disabled = false; });
                const labels = { content_health: 'Check content', seo_assist: 'Suggest SEO', summarize: 'Summarize', tags: 'Suggest tags', draft_content: 'Build outline' };
                button.textContent = labels[task] || 'Run again';
            }
        });
        panel.dataset.bpAifEnhanced = 'true';
    });
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

    enhanceMenuBuilder();
    enhanceContentEditors();
    window.requestAnimationFrame(enhanceContentEditors);
    enhanceAifPanels();
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
