document.documentElement.classList.add('bp-js');

document.addEventListener('DOMContentLoaded', () => {
    if (!window.fetch || !window.AbortController) return;
    document.querySelectorAll('[data-bp-date-formats]').forEach((panel) => {
        const form = panel.closest('form');
        const error = panel.querySelector('[data-bp-format-error]');
        let timer, generation = 0, pending;
        const update = () => {
            const current = ++generation;
            clearTimeout(timer);
            pending?.abort();
            timer = setTimeout(async () => {
                const data = new FormData();
                for (const name of ['csrf_token', 'timezone', 'date_format', 'time_format', 'date_format_custom', 'time_format_custom']) {
                    const input = form.querySelector('[name="' + name + '"]:checked') || form.querySelector('[name="' + name + '"]');
                    if (input) data.set(name, input.value);
                }
                pending = new AbortController();
                try {
                    const response = await fetch(panel.dataset.previewUrl, {method: 'POST', body: data, credentials: 'same-origin', redirect: 'error', signal: pending.signal});
                    const result = await response.json();
                    if (current !== generation) return;
                    if (!response.ok || !result.ok) throw new Error(result.message || 'Preview unavailable.');
                    for (const kind of ['date', 'time']) panel.querySelector('[data-bp-format-preview="' + kind + '"]').textContent = result[kind];
                    panel.querySelectorAll('.bp-format-choice input').forEach((input) => {
                        const example = result.examples?.[input.name.split('_')[0]]?.[input.value];
                        if (example !== undefined) input.nextElementSibling.textContent = example;
                    });
                    error.hidden = true;
                } catch (failure) {
                    if (current !== generation || failure.name === 'AbortError') return;
                    error.textContent = failure.message || 'Preview unavailable. Save Settings to refresh it.';
                    error.hidden = false;
                }
            }, 250);
        };
        panel.addEventListener('input', (event) => {
            if (event.target.name?.endsWith('_format_custom')) {
                const kind = event.target.name.split('_')[0];
                panel.querySelector('[name="' + kind + '_format"][value="custom"]').checked = true;
            }
            update();
        });
        form.querySelector('[name="timezone"]')?.addEventListener('change', update);
    });
});

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

// Read the active UIF surface before taking a form snapshot, including source mode.
function syncContentEditorFields(form) {
    form.querySelectorAll('[data-bp-content-editor]').forEach((host) => {
        const field = host.querySelector('textarea[name]');
        const editor = host.querySelector('.uif-editor');
        const surface = editor && activeEditorSurface(editor);
        if (!field || !surface) return;
        field.value = surface instanceof HTMLTextAreaElement ? surface.value : surface.innerHTML;
    });
}

function enhanceEditorRecovery() {
    document.querySelectorAll('form[data-bp-recovery]').forEach((form) => {
        const status = form.querySelector('[data-bp-recovery-status]');
        const retry = form.querySelector('[data-bp-recovery-retry]');
        if (!status || !window.crypto?.getRandomValues || !window.fetch) return;
        const enabled = form.dataset.bpRecoveryEnabled === 'true';
        const fields = new Set(JSON.parse(form.dataset.bpRecoveryFields));
        const branch = Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, '0')).join('');
        const snapshot = () => {
            syncContentEditorFields(form);
            const data = new FormData(form), values = {};
            if (form.querySelector('[data-bp-page-blocks]')) {
                fields.forEach((name) => { if (name.startsWith('block_')) values[name] = []; });
            }
            for (const [key, value] of data) {
                if (typeof value !== 'string') continue;
                const indexed = key.match(/^([a-z_]+)\[([0-9]*)\]$/);
                const name = indexed ? indexed[1] : key;
                if (!fields.has(name)) continue;
                if (!indexed) values[name] = value;
                else {
                    values[name] ??= indexed[2] === '' ? [] : {};
                    if (indexed[2] === '') values[name].push(value);
                    else values[name][indexed[2]] = value;
                }
            }
            // Full form changes also include publication and code fields, which are never autosaved.
            return {fields: values, full: JSON.stringify(Array.from(data, ([key, value]) => [key, typeof value === 'string' ? value : value.name]))};
        };
        const first = snapshot();
        let savedFields = JSON.stringify(first.fields), dirty = form.dataset.bpRecovered === 'true';
        let revision = '', busy = false, timer, failures = 0, submitting = false, submitPending = false;
        let finished = Promise.resolve(), settle;
        const initial = first.full;
        const schedule = (delay = 1800) => { clearTimeout(timer); timer = setTimeout(save, delay); };
        const save = async () => {
            if (busy || submitting || !enabled) return;
            const current = snapshot(), encoded = JSON.stringify(current.fields);
            if (encoded === savedFields && !(dirty && revision === '' && form.dataset.bpRecovered === 'true')) return;
            busy = true;
            finished = new Promise((resolve) => { settle = resolve; });
            retry.hidden = true;
            status.textContent = 'Saving private recovery…';
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 20000);
            try {
                const response = await fetch(form.dataset.bpRecoveryUrl, {
                    method: 'POST', credentials: 'same-origin', redirect: 'error', signal: controller.signal,
                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': form.elements.namedItem('csrf_token')?.value || ''},
                    body: JSON.stringify({type: form.dataset.bpRecovery, identifier: form.dataset.bpRecoveryIdentifier,
                        branch, base_revision: form.elements.namedItem('expected_revision')?.value || '', recovery_revision: revision, fields: current.fields})
                });
                if (!response.headers.get('Content-Type')?.includes('application/json')) throw new Error('Sign-in may have expired. Keep this editor open and copy your text before signing in.');
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.error || 'Recovery is unavailable. Your editor values remain here.');
                revision = result.recovery.revision;
                savedFields = encoded;
                failures = 0;
                status.textContent = 'Private recovery saved. Use the save action to apply changes.';
            } catch (error) {
                failures += 1;
                status.textContent = error.name === 'AbortError' ? 'Recovery timed out. Your editor values remain here.' : error.message;
                retry.hidden = false;
            } finally {
                clearTimeout(timeout);
                busy = false;
                settle();
                if (!submitting && JSON.stringify(snapshot().fields) !== savedFields && failures < 4) schedule(Math.min(30000, 2000 * (2 ** failures)));
            }
        };
        const changed = () => {
            const current = snapshot();
            dirty = current.full !== initial || form.dataset.bpRecovered === 'true';
            if (JSON.stringify(current.fields) !== savedFields) {
                status.textContent = enabled ? 'Unsaved changes. Private recovery pending…' : 'Unsaved changes. Private recovery needs host encryption support.';
                schedule();
            } else if (dirty) status.textContent = 'Unsaved changes. Use the save action to apply changes.';
        };
        form.addEventListener('input', changed);
        form.addEventListener('change', changed);
        form.addEventListener('bp:content-change', changed);
        // Adding/removing/reordering blocks can change hidden fields without an input event.
        const blocks = form.querySelector('[data-bp-page-blocks]');
        if (blocks) new MutationObserver(changed).observe(blocks, {childList: true, subtree: true});
        retry.addEventListener('click', () => { failures = 0; save(); });
        form.addEventListener('submit', async (event) => {
            if (submitting) return;
            syncContentEditorFields(form);
            if (!enabled) { submitting = true; return; }
            event.preventDefault();
            if (submitPending) return;
            submitPending = true;
            clearTimeout(timer);
            // Preserve the latest form before a validation/conflict response replaces the editor.
            await finished;
            await save();
            clearTimeout(timer);
            submitting = true;
            form.requestSubmit(event.submitter instanceof HTMLElement ? event.submitter : undefined);
        });
        window.addEventListener('beforeunload', (event) => {
            if (!submitting && (dirty || snapshot().full !== initial)) { event.preventDefault(); event.returnValue = ''; }
        });
        if (!enabled) status.textContent = 'Private recovery needs host encryption support. Use the save action to retain changes.';
        else if (dirty) schedule();
    });
}

function openEditorMedia(host, link, event) {
    if (!window.HTMLDialogElement || !window.fetch) return;
    event.preventDefault();
    const editor = host.querySelector('.uif-editor');
    const surface = editor ? activeEditorSurface(editor) : host.querySelector('textarea[name]');
    if (!surface) return;
    const cursor = surface instanceof HTMLTextAreaElement ? [surface.selectionStart, surface.selectionEnd] : null;
    const selection = window.getSelection();
    const range = !cursor && selection?.rangeCount && surface.contains(selection.getRangeAt(0).commonAncestorContainer) ? selection.getRangeAt(0).cloneRange() : null;
    const returnFocus = event.currentTarget;
    const dialog = document.createElement('dialog');
    dialog.className = 'bp-media-picker';
    dialog.setAttribute('aria-label', 'Insert an image');
    // This fixed markup contains no library metadata or user-provided HTML.
    dialog.innerHTML = '<header><h2>Insert an image</h2><button type="button" class="bp-button bp-button-secondary" data-picker-close>Cancel</button></header>' +
        '<form class="bp-filter-form" data-picker-search><label>Search images<input type="search" name="q" maxlength="160"></label><button class="bp-button bp-button-secondary">Search</button></form>' +
        '<p role="status" aria-live="polite" data-picker-status></p><div class="bp-media-picker-grid" data-picker-items></div>' +
        '<nav aria-label="Image results"><button type="button" class="bp-button bp-button-secondary" data-picker-prev>Previous</button><span data-picker-page></span><button type="button" class="bp-button bp-button-secondary" data-picker-next>Next</button></nav>' +
        '<form class="bp-form bp-compact-form" data-picker-insert hidden><strong data-picker-selected></strong><label>Alt text<input name="alt" maxlength="1000"><span class="bp-field-help">Describe the image’s purpose in this content.</span></label>' +
        '<label class="bp-check-option"><input type="checkbox" name="decorative">Decorative image (empty alt text)</label><label>Caption<textarea name="caption" rows="2" maxlength="2000"></textarea></label><button class="bp-button">Insert image</button></form>' +
        '<details><summary>Upload images</summary><form class="bp-form bp-compact-form" data-picker-upload><label>Image files<input type="file" name="media[]" accept="image/jpeg,image/png,image/webp,image/avif,image/gif" multiple required></label><label class="bp-check-option"><input type="checkbox" name="generate_variants" value="1" checked>Generate responsive copies when supported</label><span class="bp-field-help" data-picker-limit></span><button class="bp-button bp-button-secondary">Upload images</button></form></details>';
    document.body.append(dialog);
    const status = dialog.querySelector('[data-picker-status]');
    const items = dialog.querySelector('[data-picker-items]');
    const search = dialog.querySelector('[data-picker-search]');
    const insert = dialog.querySelector('[data-picker-insert]');
    const upload = dialog.querySelector('[data-picker-upload]');
    let page = 1, pages = 1, chosen, generation = 0, closed = false, inserted = false, pending;
    const readJson = async (response) => {
        if (!response.headers.get('Content-Type')?.includes('application/json')) throw new Error('Sign-in may have expired. Keep your editor open and sign in again.');
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'The image library is unavailable.');
        return data;
    };
    const load = async (requested = 1) => {
        const current = ++generation;
        pending?.abort(); const controller = new AbortController(); pending = controller;
        const timeout = setTimeout(() => controller.abort(), 20000);
        status.textContent = 'Loading images…';
        try {
            const url = new URL(link.dataset.bpPickerUrl, location.href);
            url.searchParams.set('q', search.elements.q.value); url.searchParams.set('page', String(requested));
            const data = await readJson(await fetch(url, {credentials: 'same-origin', redirect: 'error', signal: controller.signal}));
            if (closed || current !== generation) return;
            page = data.page; pages = data.pages; chosen = null; insert.hidden = true; items.replaceChildren();
            for (const item of data.items) {
                const url = new URL(item.url, location.href);
                if (url.origin !== location.origin || !['https:', 'http:'].includes(url.protocol)) continue;
                const button = document.createElement('button'); button.type = 'button'; button.className = 'bp-media-picker-item';
                button.setAttribute('aria-pressed', 'false');
                const image = document.createElement('img'); image.src = url.href; image.alt = ''; image.loading = 'lazy'; image.decoding = 'async';
                const name = document.createElement('span'); name.textContent = item.name;
                const dimensions = document.createElement('small'); dimensions.textContent = item.width + ' × ' + item.height;
                button.append(image, name, dimensions);
                button.addEventListener('click', async () => {
                    chosen = {...item, url: url.pathname};
                    items.querySelectorAll('button').forEach((row) => row.setAttribute('aria-pressed', String(row === button)));
                    insert.hidden = false;
                    insert.querySelector('[data-picker-selected]').textContent = item.name;
                    insert.elements.alt.value = item.metadata.alt; insert.elements.alt.disabled = false;
                    insert.elements.decorative.checked = false; insert.elements.caption.value = item.metadata.caption;
                    insert.elements.alt.focus();
                    // Strong byte/variant inspection is limited to the selected image, not the collection.
                    try {
                        const detail = new URL(link.dataset.bpPickerUrl, location.href); detail.searchParams.set('id', item.id);
                        const data = await readJson(await fetch(detail, {credentials: 'same-origin', redirect: 'error'}));
                        if (!closed && chosen?.id === item.id) chosen.srcset = data.items[0]?.srcset || '';
                    } catch (_) { /* The original remains usable when optional responsive copies are unavailable. */ }
                });
                items.append(button);
            }
            dialog.querySelector('[data-picker-page]').textContent = 'Page ' + page + ' of ' + pages;
            dialog.querySelector('[data-picker-prev]').disabled = page <= 1;
            dialog.querySelector('[data-picker-next]').disabled = page >= pages;
            dialog.querySelector('[data-picker-limit]').textContent = 'Up to 20 files; ' + Math.floor(data.max_upload_bytes / 1048576) + ' MB each. Host upload rules also apply.';
            status.textContent = items.childElementCount ? 'Choose an image, then review its alt text and caption.' : 'No matching images. Upload an image or change the search.';
        } catch (error) { if (!closed && current === generation) status.textContent = error.name === 'AbortError' ? 'Image search timed out. Try again.' : error.message; }
        finally { clearTimeout(timeout); }
    };
    search.addEventListener('submit', (event) => { event.preventDefault(); load(); });
    dialog.querySelector('[data-picker-prev]').addEventListener('click', () => load(page - 1));
    dialog.querySelector('[data-picker-next]').addEventListener('click', () => load(page + 1));
    dialog.querySelector('[data-picker-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { closed = true; pending?.abort(); dialog.remove(); (inserted ? surface : returnFocus)?.focus({preventScroll: true}); });
    insert.elements.decorative.addEventListener('change', () => { insert.elements.alt.disabled = insert.elements.decorative.checked; });
    insert.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!chosen) return;
        const decorative = insert.elements.decorative.checked, alt = insert.elements.alt.value.trim();
        if (!decorative && !alt) { status.textContent = 'Add alt text or mark this image decorative.'; insert.elements.alt.focus(); return; }
        const image = document.createElement('img'); image.src = chosen.url; image.alt = decorative ? '' : alt;
        image.width = chosen.width; image.height = chosen.height; image.loading = 'lazy'; image.decoding = 'async';
        if (chosen.srcset && chosen.srcset.split(', ').every((entry) => {
            const parts = entry.match(/^(\S+) ([0-9]+)w$/);
            return parts && new URL(parts[1], location.href).origin === location.origin;
        })) { image.srcset = chosen.srcset; image.sizes = '100vw'; }
        let node = image;
        const caption = insert.elements.caption.value.trim();
        if (caption) { node = document.createElement('figure'); const text = document.createElement('figcaption'); text.textContent = caption; node.append(image, text); }
        if (cursor) surface.setRangeText(node.outerHTML, cursor[0], cursor[1], 'end');
        else if (range && surface.contains(range.commonAncestorContainer)) { range.deleteContents(); range.insertNode(node); range.setStartAfter(node); range.collapse(true); selection.removeAllRanges(); selection.addRange(range); }
        else surface.append(node);
        surface.dispatchEvent(new Event('input', {bubbles: true}));
        syncContentEditorFields(host.closest('form') || host);
        host.dispatchEvent(new Event('bp:content-change', {bubbles: true}));
        inserted = true; dialog.close();
    });
    upload.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!upload.reportValidity()) return;
        const files = upload.elements.namedItem('media[]').files;
        if (files.length > 20) { status.textContent = 'Choose at most 20 files.'; return; }
        const data = new FormData(upload);
        data.set('csrf_token', host.closest('form')?.elements.namedItem('csrf_token')?.value || '');
        const button = upload.querySelector('button'); button.disabled = true; status.textContent = 'Uploading images…';
        try {
            await readJson(await fetch(link.dataset.bpUploadUrl, {method: 'POST', body: data, credentials: 'same-origin', redirect: 'error', headers: {'Accept': 'application/json'}}));
            if (!closed) { upload.reset(); search.reset(); await load(); }
        } catch (error) { if (!closed) status.textContent = error.message; }
        finally { button.disabled = false; }
    });
    dialog.showModal(); load();
}

function enhanceEditorMedia() {
    document.querySelectorAll('[data-bp-editor-media]').forEach((link) => {
        link.addEventListener('click', (event) => openEditorMedia(link.closest('[data-bp-content-editor]'), link, event));
    });
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
        mediaAction.addEventListener('click', (event) => openEditorMedia(host, mediaSource, event));

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

    document.querySelectorAll('form[data-bp-code-submit]').forEach((form) => {
        const source = form.querySelector('textarea[name="source"]');
        const status = document.querySelector('[data-bp-save-status]');
        form.querySelector('[data-bp-source-download]')?.addEventListener('click', () => {
            const url = URL.createObjectURL(new Blob([source.value], {type: 'text/plain;charset=utf-8'}));
            const link = document.createElement('a');
            link.href = url;
            link.download = 'template-recovery.txt';
            link.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        });
        let saving = false;
        form.addEventListener('submit', async (event) => {
            if (event.submitter?.hasAttribute('formaction')) return;
            if (!window.fetch || !window.TextEncoder) return; // Native POST remains supported.
            event.preventDefault();
            if (saving) return;
            saving = true;
            const submittedSource = source.value;
            let reference = '';
            try {
                const bytes = new TextEncoder().encode(submittedSource);
                let binary = '';
                for (let offset = 0; offset < bytes.length; offset += 0x8000) {
                    binary += String.fromCharCode(...bytes.subarray(offset, offset + 0x8000));
                }
                const data = new FormData(form);
                data.set('source_encoded', window.btoa(binary));
                data.delete('source');
                status.textContent = 'Saving… Keep this editor open.';
                // Do not follow hosting/login redirects: 301/302/303 can drop the
                // POST body, and a redirect must never be treated as a saved file.
                const response = await fetch(form.action, {method: 'POST', body: data, credentials: 'same-origin', redirect: 'error', headers: {'X-Press-Editor': '1'}});
                reference = response.headers.get('X-Request-ID') || '';
                if (response.redirected) throw new Error('The save request was redirected; saving is not confirmed.');
                if (!(response.headers.get('Content-Type') || '').toLowerCase().includes('application/json')) {
                    throw new Error('The server returned HTTP ' + response.status + ' instead of a save confirmation. Check the login session and hosting redirect/error logs.');
                }
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.message || 'Save failed.');
                if (source.value !== submittedSource) {
                    status.textContent = 'The submitted version was saved. You have newer unsaved edits; save again to keep them.';
                } else if (result.location) window.location.assign(result.location);
            } catch (error) {
                status.textContent = 'Save was not confirmed. Your source remains here. Download it before reloading. ' + (error.message || '') + (reference ? ' Reference: ' + reference + '.' : '') + ' If hosting returned an error, ask the host to inspect the request and redirect chain.';
            } finally {
                saving = false;
                source.disabled = false;
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

    document.querySelectorAll('[data-bp-page-blocks]').forEach((builder) => {
        const list = builder.querySelector('[data-bp-block-list]');
        const template = builder.querySelector('[data-bp-block-template]');
        const addType = builder.querySelector('[data-bp-add-block-type]');
        const refresh = (row) => {
            const type = row.querySelector('[data-bp-block-type]')?.value || 'html';
            row.querySelectorAll('[data-bp-block-field]').forEach((field) => {
                field.hidden = !(field.getAttribute('data-bp-block-field') || '').split(' ').includes(type);
            });
        };
        if (!(list instanceof HTMLElement) || !(template instanceof HTMLTemplateElement)) return;
        list.querySelectorAll('[data-bp-block-row]').forEach((row) => refresh(row));
        list.addEventListener('change', (event) => {
            const select = event.target.closest('[data-bp-block-type]');
            if (select) refresh(select.closest('[data-bp-block-row]'));
        });
        list.addEventListener('click', (event) => {
            const remove = event.target.closest('[data-bp-remove-block]');
            const row = remove?.closest('[data-bp-block-row]');
            if (!remove || !row || list.querySelectorAll('[data-bp-block-row]').length === 1) return;
            row.remove();
        });
        builder.querySelector('[data-bp-add-block]')?.addEventListener('click', () => {
            const fragment = template.content.cloneNode(true);
            const row = fragment.querySelector('[data-bp-block-row]');
            if (!row) return;
            const suffix = `${Date.now()}-${Math.floor(Math.random() * 10000)}`;
            row.querySelectorAll('[id]').forEach((element) => {
                const previousId = element.id;
                const nextId = `${previousId}-${suffix}`;
                row.querySelectorAll('[for]').forEach((label) => { if (label.getAttribute('for') === previousId) label.setAttribute('for', nextId); });
                element.id = nextId;
            });
            const select = row.querySelector('[data-bp-block-type]');
            if (select && addType) select.value = addType.value;
            refresh(row);
            list.append(row);
            row.querySelector('input, textarea, select')?.focus();
        });
    });

    enhanceMenuBuilder();
    enhanceContentEditors();
    window.requestAnimationFrame(enhanceContentEditors);
    enhanceEditorRecovery();
    enhanceEditorMedia();
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

// Navigation remains visible when JavaScript is unavailable.
document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.querySelector('[data-bp-admin-nav-toggle]');
    if (!toggle) return;
    const small = window.matchMedia('(max-width: 720px)');
    const close = document.querySelector('[data-bp-admin-nav-close]');
    close?.addEventListener('click', () => { document.body.classList.remove('bp-admin-nav-open'); toggle.setAttribute('aria-expanded', 'false'); toggle.focus(); });
    document.body.classList.add('bp-admin-nav-ready');
    const sync = () => { toggle.hidden = !small.matches; if (close) close.hidden = !small.matches; if (!small.matches) { document.body.classList.remove('bp-admin-nav-open'); toggle.setAttribute('aria-expanded', 'false'); } };
    toggle.addEventListener('click', () => { const open = document.body.classList.toggle('bp-admin-nav-open'); toggle.setAttribute('aria-expanded', String(open)); if (open) document.querySelector('#bp-admin-navigation a[aria-current]')?.focus(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && document.body.classList.contains('bp-admin-nav-open')) { document.body.classList.remove('bp-admin-nav-open'); toggle.setAttribute('aria-expanded', 'false'); toggle.focus(); } });
    small.addEventListener('change', sync); sync();
});

// Field IDs are authored explicitly; adding a row never changes existing submission keys.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-bp-form-builder]').forEach(builder => {
        const list = builder.querySelector('[data-bp-form-fields]');
        const add = builder.querySelector('[data-bp-add-form-field]');
        if (!list || !add) return;
        const sync = () => { add.disabled = list.children.length >= 20; };
        add.hidden = false;
        add.addEventListener('click', () => {
            if (list.children.length >= 20) return;
            const field = list.querySelector('fieldset')?.cloneNode(true);
            if (!field) return;
            field.querySelector('legend').textContent = `Field ${list.children.length + 1}`;
            field.querySelectorAll('input,textarea').forEach(input => { input.value = input.type === 'number' ? '254' : ''; });
            field.querySelectorAll('select').forEach(select => { select.selectedIndex = 0; });
            list.append(field);
            builder.querySelector('[data-bp-field-status]').textContent = `Field ${list.children.length} added. Enter a stable ID and label.`;
            field.querySelector('input')?.focus();
            sync();
        });
        sync();
    });
});
