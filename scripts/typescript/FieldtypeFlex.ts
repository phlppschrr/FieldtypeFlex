namespace FieldtypeFlex {
    interface RowResponse {html: string; error?: boolean; message?: string; warning?: string;}
    interface ClipboardResponse {clipboard: string; error?: boolean; message?: string;}
    interface UploadItem {markup: string; error?: boolean; message?: string;}
    interface RowState { title?: TitleData; removalReady?: boolean; load?: Promise<boolean>; }
    const state = new WeakMap<HTMLElement, RowState>();
    const titleRequests = new WeakMap<HTMLElement, {revision: number; timer?: number; queued: boolean}>();
    const disabledBeforeRemoval = new WeakMap<Control, boolean>();
    const busyTrees = new WeakSet<HTMLElement>();
    const rowMenus = '.flex-table-more, .flex-row-clipboard, .flex-row-add-menu';
    const tableEditors = new WeakMap<HTMLElement, {button: HTMLButtonElement; editor: HTMLElement; detail: HTMLTableRowElement}>();
    const initializedDetailEditors = new WeakSet<HTMLElement>();
    function rowState(row: HTMLElement): RowState {
        let current = state.get(row);
        if(!current) { current = {}; state.set(row, current); }
        return current;
    }
    function message(key: string, fallback: string): string { return window.ProcessWire?.config.FieldtypeFlex?.[key] || fallback; }
    function errorMessage(error: unknown): string { return error instanceof Error ? error.message : String(error); }
    function rowList(set: HTMLElement): HTMLElement { return one(set, '.flex-rows-items'); }
    function rows(set: HTMLElement): HTMLElement[] { return all(rowList(set), ':scope > .flex-row[data-flex-row]'); }
    function rowBodies(row: HTMLElement): HTMLElement[] { return all(row, ':scope > .flex-row-content, :scope > .flex-table-cell'); }
    function activeRows(set: HTMLElement): HTMLElement[] { return rows(set).filter(row => !row.matches('.flex-row-remove-pending')); }
    function removalState(row: HTMLElement, removed: boolean): void {
        closeRowEditors(row);
        const bodies = rowBodies(row);
        const transport = row.querySelector<HTMLElement>(':scope > .flex-table-actions .flex-row-transport') || row;
        row.classList.toggle('flex-row-remove-pending', removed);
        one(row, ':scope > .flex-row-header').classList.toggle('ui-state-error', removed);
        one<HTMLInputElement>(row, ':scope > .flex-row-remove-value, :scope > .flex-table-actions .flex-row-remove-value').value = removed ? '1' : '0';
        all(transport, ':scope > .flex-row-remove-mirror').forEach(input => input.remove());
        if(removed) {
            bodies.flatMap(serialize).forEach(([name, value]) => {
                const mirror = document.createElement('input');
                mirror.type = 'hidden'; mirror.className = 'flex-row-remove-mirror'; mirror.name = name; mirror.value = value; transport.append(mirror);
            });
        }
        bodies.flatMap(body => all<Control>(body, controlSelector)).forEach(input => {
            if(removed) {
                if(!disabledBeforeRemoval.has(input)) disabledBeforeRemoval.set(input, input.disabled);
                input.disabled = true;
            } else if(disabledBeforeRemoval.has(input)) {
                input.disabled = disabledBeforeRemoval.get(input)!; disabledBeforeRemoval.delete(input);
            }
        });
        const button = one(row, ':scope > .flex-row-header [data-flex-row-action="remove"]');
        const label = attr(button, removed ? 'data-flex-undo-label' : 'data-flex-remove-label');
        button.setAttribute('aria-pressed', String(removed)); button.setAttribute('aria-label', label); button.title = label;
    }
    function rowTitleData(row: HTMLElement): TitleData | undefined {
        const data = attr(row, 'data-flex-title');
        if(data) return rowState(row).title ??= JSON.parse(data) as TitleData;
    }
    function renderTitle(row: HTMLElement, title: TitleData, index: number): void {
        row.querySelector(':scope > .flex-row-header .flex-row-title')?.replaceChildren(titleFragment(titleParts(title, index)));
    }
    function syncTitleSources(row: HTMLElement, target?: HTMLElement, afterWidgetUpdate = false): void {
        const title = rowTitleData(row);
        const tree = row.closest<HTMLElement>('.InputfieldFlex');
        if(!title || !tree) return;
        let changed = false;
        for(const [reference, source] of Object.entries(title.sources)) {
            if(target && !sourceMatches(source, target)) continue;
            if(target && source.kind === 'page' && !afterWidgetUpdate) {
                // Core dispatches change just before replacing the visible selected label.
                queueMicrotask(() => syncTitleSources(row, target, true));
                continue;
            }
            const value = titleSourceValue(tree, source);
            if(value === undefined || value === title.values[reference]) continue;
            title.values[reference] = value;
            changed = true;
        }
        if(changed) renderTitle(row, title, rows(closest(row, '.flex-rows')).indexOf(row) + 1);
    }
    function updateLabels(set: HTMLElement): void {
        rows(set).forEach((row, index) => {
            const title = rowTitleData(row);
            if(title) renderTitle(row, title, index + 1);
        });
    }
    function treeBusy(scope: HTMLElement): boolean { return busyTrees.has(closest(scope, '.InputfieldFlex')); }
    function setTreeBusy(scope: HTMLElement, busy: boolean): void {
        const tree = closest(scope, '.InputfieldFlex');
        if(busy) busyTrees.add(tree); else busyTrees.delete(tree);
        tree.setAttribute('aria-busy', String(busy)); all(tree, '.flex-rows').forEach(updateActions);
        const form = closest(tree, 'form');
        if(!busy && titleRequests.get(tree)?.queued) queueTitleRefresh(tree);
        if(!busy && !form.querySelector('.InputfieldFlex[aria-busy="true"], .flex-row[data-flex-load-failed="1"]')) all(form, ':scope > .flex-form-request-error').forEach(error => error.remove());
    }
    function updateActions(set: HTMLElement): void {
        const count = activeRows(set).length, min = Number(attr(set, 'data-flex-min')) || 0, max = Number(attr(set, 'data-flex-max')) || 0, busy = treeBusy(set);
        const atLimit = max > 0 && count >= max;
        const menu = set.querySelector<HTMLDetailsElement>(':scope > .flex-row-add-menu');
        if(menu) {
            menu.hidden = count > 0;
            if(menu.hidden) menu.open = false;
            one<HTMLButtonElement>(set, ':scope > .flex-row-add').hidden = !menu.hidden;
        }
        all<HTMLButtonElement | HTMLSelectElement>(set, ':scope > .flex-row-add, :scope > .flex-row-type, :scope > .flex-row-add-menu button').forEach(control => control.disabled = busy || atLimit);
        rows(set).forEach(row => {
            const header = one(row, ':scope > .flex-row-header');
            const toggle = header.querySelector<HTMLButtonElement>('.flex-row-toggle');
            const removed = row.matches('.flex-row-remove-pending');
            if(toggle) toggle.disabled = busy;
            one<HTMLButtonElement>(header, '[data-flex-row-action="duplicate"]').disabled = busy || atLimit;
            all<HTMLButtonElement>(header, '[data-flex-row-action="clipboard-copy"], [data-flex-row-action^="paste-"]').forEach(button => {
                button.disabled = busy || removed || attr(button, 'data-flex-row-action') !== 'clipboard-copy' && atLimit;
            });
            one<HTMLButtonElement>(header, '[data-flex-row-action="remove"]').disabled = busy || (!removed && count <= min);
            one<HTMLButtonElement>(row, ':scope > .flex-row-header .flex-row-sort, :scope > .flex-table-sort > .flex-row-sort').disabled = busy || removed || count < 2;
        });
        sortable.disabled(rowList(set), busy || count < 2);
    }
    function order(set: HTMLElement): void {
        change(one<HTMLInputElement>(set, ':scope > .flex-rows-order'), rows(set).map(row => attr(row, 'data-flex-row')).join(','));
        closest(set, '.InputfieldFlex').classList.add('InputfieldStateChanged'); updateLabels(set); updateActions(set);
    }
    function csrf(): HTMLInputElement | null { return document.querySelector('#ProcessPageEdit input._post_token'); }
    function teardown(row: HTMLElement): void {
        const table = row.closest<HTMLElement>('.flex-table');
        if(table && row.tagName === 'TR') closeTableEditor(table);
        closeRowEditors(row);
        if(!window.tinymce) return;
        all<HTMLTextAreaElement>(row, 'textarea.InputfieldTinyMCEEditor').forEach(input => {
            if(input.closest('.flex-table-editor[hidden]')) return;
            const editor = window.tinymce!.get(input.id);
            if(editor) { editor.save(); editor.remove(); }
            input.classList.remove('InputfieldTinyMCELoaded', 'InputfieldTinyMCELazy');
        });
    }
    function syncToggle(row: HTMLElement): void { row.querySelector(':scope > .flex-row-header .flex-row-toggle')?.setAttribute('aria-expanded', String(!row.matches('.InputfieldStateCollapsed'))); }
    function openRow(row: HTMLElement, open: boolean): void { if(row.tagName === 'TR') return; classic.toggle(row, open); syncToggle(row); }
    function loadRow(row: HTMLElement): Promise<boolean> {
        if(attr(row, 'data-flex-loaded') !== '0') return Promise.resolve(true);
        if(rowState(row).load) return rowState(row).load!;
        const set = closest(row, '.flex-rows'), body = one(row, ':scope > .flex-row-content');
        if(treeBusy(set)) return Promise.resolve(false);
        const operation = (async () => {
            setTreeBusy(set, true);
            one(body, '.flex-row-load-status').textContent = message('loading', 'Loading…'); one(body, '.flex-row-retry').hidden = true;
            try {
                const data = new URLSearchParams({flex_action: 'row-render', flex_set: attr(set, 'data-flex-set'), flex_source: attr(row, 'data-flex-row'), flex_complete: attr(set, 'data-flex-draft')});
                const token = csrf(); if(token) data.set(token.name, token.value);
                const response = await fetch(attr(set, 'data-flex-page-url'), {method: 'POST', credentials: 'same-origin', headers: requestHeaders(set), body: data});
                const result: RowResponse = await response.json();
                if(!response.ok || result.error) throw new Error(result.message || message('unableToLoad', 'Unable to load item'));
                if(!row.isConnected) return false;
                const fresh = parseHTML(result.html), content = fresh.querySelector(':scope > .flex-row-content');
                if(attr(fresh, 'data-flex-loaded') !== '1' || !content) throw new Error(message('unableToLoad', 'Unable to load item'));
                body.replaceChildren(...content.childNodes); await activateScripts(body, response); row.setAttribute('data-flex-loaded', '1'); row.removeAttribute('data-flex-load-failed');
                initRows(row); await classic.setup(row); if(row.matches('.flex-row-remove-pending')) removalState(row, true);
                return true;
            } catch(error) {
                row.setAttribute('data-flex-load-failed', '1');
                one(body, '.flex-row-load-status').textContent = error instanceof SyntaxError ? message('unableToLoad', 'Unable to load item') : errorMessage(error);
                one(body, '.flex-row-retry').hidden = false; return false;
            } finally { delete rowState(row).load; setTreeBusy(set, false); }
        })();
        rowState(row).load = operation; return operation;
    }
    function requestHeaders(set: HTMLElement): Record<string, string> {
        return {'X-Requested-With': 'XMLHttpRequest', 'X-FIELDNAME': attr(set, 'data-flex-field'), 'X-FLEX-DRAFT': attr(set, 'data-flex-draft')};
    }
    function externalTitleDependencies(tree: HTMLElement): Set<string> {
        return new Set(all(tree, '[data-flex-external-dependencies]').flatMap(input => attr(input, 'data-flex-external-dependencies').split(' ')));
    }
    function dependencyName(name: string): string { return name.replace(/\[\]$|^_|_$/g, '').replace(/__\d+$/, ''); }
    function queueTitleRefresh(tree: HTMLElement): void {
        if(!tree.querySelector('.flex-row[data-flex-title-refresh]')) return;
        const request = titleRequests.get(tree) ?? {revision: 0, queued: false};
        titleRequests.set(tree, request);
        const revision = ++request.revision;
        clearTimeout(request.timer);
        request.queued = true;
        if(treeBusy(tree)) return;
        request.timer = window.setTimeout(async () => {
            if(!tree.isConnected || treeBusy(tree)) return;
            request.queued = false;
            const targets = all(tree, '.flex-row[data-flex-title-refresh]').filter(row =>
                row.closest('.InputfieldFlex') === tree && !row.closest('.flex-row-remove-pending'));
            if(!targets.length) return;
            const set = closest(targets[0], '.flex-rows');
            const addresses = targets.map(row => [attr(closest(row, '.flex-rows'), 'data-flex-set'), attr(row, 'data-flex-row')]);
            const token = csrf(), draft = attr(set, 'data-flex-draft');
            if(!token || !draft) return;
            classic.saveReady(tree);
            const data = new URLSearchParams(serialize(tree));
            const dependencies = externalTitleDependencies(tree);
            if(dependencies.size) {
                serialize(closest(tree, 'form')).forEach(([name, value]) => {
                    if(name !== 'id' && (dependencies.has(name) || dependencies.has(dependencyName(name)))) data.append(name, value);
                });
            }
            data.set(token.name, token.value);
            data.set('flex_action', 'row-titles');
            data.set('flex_title_rows', JSON.stringify(addresses));
            data.set('flex_complete', draft);
            try {
                const response = await fetch(attr(set, 'data-flex-page-url'), {method: 'POST', credentials: 'same-origin', headers: requestHeaders(set), body: data});
                const result: {error: boolean; titles: Record<string, Pick<TitleData, 'custom' | 'override'>>} = await response.json();
                if(!response.ok || result.error || request.revision !== revision || !tree.isConnected) return;
                targets.forEach((row, index) => {
                    const title = rowTitleData(row), update = result.titles[addresses[index].join('.')];
                    if(!title || !update || !row.isConnected) return;
                    Object.assign(title, update);
                    renderTitle(row, title, rows(closest(row, '.flex-rows')).indexOf(row) + 1);
                });
            } catch {
                // Retain the last title if the draft or network is unavailable.
            }
        }, 240);
    }
    function moved(set: HTMLElement, row: HTMLElement): void {
        const status = one(set, ':scope > .flex-rows-status');
        status.classList.add('uk-hidden-visually');
        status.textContent = message('itemMoved', 'Item moved to position {position} of {count}').replace('{position}', String(rows(set).indexOf(row) + 1)).replace('{count}', String(rows(set).length));
    }
    function updateImagePreview(button: HTMLButtonElement, editor: HTMLElement): void {
        const images = all(editor, '.InputfieldImageList > li.gridImage, .InputfieldImageEditSingle')
            .filter(item => !item.querySelector('.gridImage__deletebox:checked'));
        const thumbnails = images.flatMap(item => {
            const source = item.querySelector<HTMLImageElement>('img[src]:not([src=""])');
            if(!source) return [];
            const thumb = document.createElement('img');
            thumb.src = source.src;
            thumb.alt = '';
            return [thumb];
        });
        button.replaceChildren(...thumbnails);
    }
    function richTextPreview(editor: HTMLElement): string {
        const field = editor.querySelector<HTMLElement>('.InputfieldTinyMCE');
        const inputs = field ? all<HTMLElement>(field, '.InputfieldTinyMCEEditor') : [];
        const source = inputs.find(input => input.closest('.LanguageSupportCurrent')) ?? inputs[0];
        const html = source instanceof HTMLTextAreaElement ? source.value : source?.innerHTML || '';
        const text = document.createElement('textarea');
        setHTML(text, html.replace(/<[^>]*>/g, ' ').replace(/</g, '&lt;').replace(/>/g, '&gt;'));
        return text.value.replace(/\s+/g, ' ').trim();
    }
    function updateTablePreview(button: HTMLButtonElement, editor: HTMLElement): void {
        const kind = attr(button, 'data-flex-preview');
        if(kind === 'image') {
            updateImagePreview(button, editor);
        } else {
            const text = one(button, 'span');
            if(kind === 'page') {
                const id = editor.querySelector<HTMLInputElement>('.InputfieldPageListSelectData')?.value;
                const label = editor.querySelector('.PageListSelectName');
                if(!Number(id)) text.textContent = '';
                else if(label?.textContent?.trim()) text.textContent = label.textContent.trim();
            } else if(kind === 'richtext') {
                text.textContent = richTextPreview(editor);
            } else {
                const labels = kind === 'file'
                    ? all(editor, '.InputfieldFileList > li:not(:has(.InputfieldFileDelete input:checked)) .InputfieldFileName')
                    : all(editor, '.InputfieldPage > .InputfieldContent ol > li:not(.itemTemplate) > .itemLabel');
                text.textContent = labels.length ? (labels[0].textContent || '').trim() : '';
                if(labels.length > 1) text.textContent += ' +' + (labels.length - 1);
            }
            button.title = text.textContent || '';
        }
        button.classList.toggle('ui-state-error', !!editor.querySelector('.InputfieldStateError, .InputfieldError'));
    }
    function closeTableEditor(set: HTMLElement, focus = false): void {
        const active = tableEditors.get(set);
        if(!active) return;
        const {button, editor, detail} = active;
        const richText = button.matches('[data-flex-preview="richtext"]');
        if(richText) classic.saveReady(editor);
        updateTablePreview(button, editor);
        editor.hidden = true;
        if(richText && editor.querySelector('.InputfieldTinyMCENormal')) {
            // TinyMCE iframes are recreated after moving back into the detail row.
            classic.destroyEditors(editor);
            all(editor, '.InputfieldTinyMCENormal').forEach(input => {
                input.classList.remove('InputfieldTinyMCELoaded');
                input.classList.add('InputfieldTinyMCELazy');
            });
        }
        one<HTMLButtonElement>(editor, '.flex-table-editor-close').hidden = true;
        button.after(editor);
        button.setAttribute('aria-expanded', 'false');
        detail.remove();
        tableEditors.delete(set);
        if(focus) button.focus({preventScroll: true});
    }
    function closeRowEditors(row: HTMLElement): void {
        const set = row.closest<HTMLElement>('.flex-table');
        if(set && tableEditors.get(set)?.button.closest('.flex-row') === row) closeTableEditor(set);
        all(row, '.flex-table').forEach(set => closeTableEditor(set));
    }
    function openTableEditor(button: HTMLButtonElement, focus = true): void {
        const row = button.closest('.flex-row');
        if(!(row instanceof HTMLTableRowElement)) return;
        const set = closest(button, '.flex-table');
        if(treeBusy(set) || row.matches('.flex-row-remove-pending')) return;
        if(tableEditors.get(set)?.button === button) {
            closeTableEditor(set, focus);
            return;
        }
        closeTableEditor(set);
        const editor = button.nextElementSibling;
        if(!(editor instanceof HTMLElement) || !editor.matches('.flex-table-editor')) return;
        const detail = document.createElement('tr');
        detail.className = 'flex-table-detail';
        const cell = detail.insertCell();
        cell.colSpan = row.cells.length;
        row.after(detail);
        cell.append(editor);
        editor.hidden = false;
        const close = one<HTMLButtonElement>(editor, '.flex-table-editor-close');
        close.hidden = false;
        button.setAttribute('aria-expanded', 'true');
        tableEditors.set(set, {button, editor, detail});
        if(focus) {
            editor.tabIndex = -1;
            editor.focus();
        }
        if(button.matches('[data-flex-preview="richtext"]')) {
            all(editor, '.InputfieldTinyMCEPlaceholder').forEach(placeholder => placeholder.remove());
            void classic.setup(editor);
        } else if(!initializedDetailEditors.has(editor)) {
            initializedDetailEditors.add(editor);
            void classic.setup(editor);
        }
    }
    function initRows(context: ParentNode): void {
        const sets = all(context, '.flex-rows'); if(context instanceof HTMLElement && context.matches('.flex-rows')) sets.unshift(context);
        sets.forEach(set => {
            let errorPreview: HTMLButtonElement | undefined;
            rows(set).forEach(row => {
                all<HTMLButtonElement>(row, ':scope > .flex-table-cell > .flex-table-preview[hidden]').forEach(button => {
                    const editor = one<HTMLElement>(button.parentElement!, ':scope > .flex-table-editor');
                    updateTablePreview(button, editor);
                    editor.hidden = true;
                    if(button.matches('[data-flex-preview="richtext"]')) {
                        all(editor, '.InputfieldTinyMCENormal').forEach(input => input.classList.add('InputfieldTinyMCELazy'));
                    }
                    button.hidden = false;
                    if(!row.matches('.flex-row-remove-pending') && button.matches('.ui-state-error')) errorPreview ??= button;
                });
                if(row.matches('.flex-row-remove-pending') && !rowState(row).removalReady) { removalState(row, true); rowState(row).removalReady = true; }
                if(!row.matches('.flex-row-remove-pending') && row.querySelector('.InputfieldStateError')) openRow(row, true);
                syncToggle(row);
            });
            if(errorPreview && !tableEditors.has(set)) openTableEditor(errorPreview, false);
            sortable.init(rowList(set), teardown, async row => { order(set); await classic.setup(row); moved(set, row); });
            updateLabels(set); updateActions(set);
        });
    }
    function formError(form: HTMLFormElement, text: string, pending: boolean): void {
        let error = form.querySelector<HTMLElement>(':scope > .flex-form-limit-error');
        if(!error) { error = document.createElement('p'); error.className = 'flex-form-limit-error notice-error'; error.setAttribute('role', 'alert'); error.tabIndex = -1; form.prepend(error); }
        error.classList.toggle('flex-form-request-error', pending); error.textContent = text; error.focus();
    }
    document.addEventListener('submit', event => {
        const form = event.target;
        if(!(form instanceof HTMLFormElement) || !form.matches('[data-flex-max-input-vars]')) return;
        const pending = all(form, '.InputfieldFlex[aria-busy="true"], .flex-row[data-flex-load-failed="1"]').find(item => !item.closest('.flex-row-remove-pending'));
        if(pending) {
            event.preventDefault(); event.stopImmediatePropagation();
            formError(form, pending.matches('.flex-row') ? message('unableToLoad', 'Unable to load item') : message('requestPending', 'Wait for the current request to finish before saving.'), true); return;
        }
        const max = Number(attr(form, 'data-flex-max-input-vars')); if(max <= 0) return;
        const submitter = event.submitter as HTMLButtonElement | HTMLInputElement | null;
        let count = submitter?.name && !submitter.disabled ? 1 : 0;
        for(const [, value] of new FormData(form)) if(typeof value === 'string') count++;
        if(count <= max) return;
        event.preventDefault(); event.stopImmediatePropagation();
        formError(form, message('inputVarsExceeded', 'This form contains {count} input values, exceeding PHP max_input_vars={max}.').replace('{count}', String(count)).replace('{max}', String(max)), false);
    }, true);
    classic.preserveSelectorPaths();
    ready(() => { classic.preserveSelectorPaths(); initRows(document); });
    document.addEventListener('pw-runtime-ready', () => { initRows(document); });
    classic.events(scope => { initRows(scope); }, (row, open) => { syncToggle(row); if(open) void loadRow(row); }, scope => {
        all<HTMLTextAreaElement>(scope, 'textarea').forEach(input => {
            const editor = window.tinymce?.get(input.id);
            if(editor) editor.save();
        });
        titlesChanged(scope);
    }, (draft, path) => { void refreshMedia(draft, path); });
    function tableColumnResizer(handle: HTMLElement): ((distance: number) => void) | undefined {
        const table = handle.closest<HTMLTableElement>('.flex-table-grid');
        const heading = handle.closest<HTMLTableCellElement>('th');
        const headings = table?.tHead?.rows[0]?.cells;
        if(!table || !heading || !headings) return;
        const columns = all<HTMLTableColElement>(table, ':scope > colgroup > col');
        const index = heading.cellIndex;
        const widths = Array.from(headings, cell => cell.getBoundingClientRect().width);
        const width = widths[index];
        if(!width || columns.length !== widths.length) return;
        const tableWidth = table.getBoundingClientRect().width;
        const direction = getComputedStyle(table).direction === 'rtl' ? -1 : 1;
        return distance => {
            const nextWidth = Math.max(Math.min(64, width), width + direction * distance);
            // Freeze the displayed widths so the table layout cannot redistribute space.
            columns.forEach((column, i) => { column.style.width = `${i === index ? nextWidth : widths[i]}px`; });
            table.style.width = `${tableWidth + nextWidth - width}px`;
            table.classList.add('flex-table-resized');
        };
    }
    delegate('pointerdown', '.flex-table-resize', (event, handle) => {
        if(event.button !== 0 || !event.isPrimary) return;
        const resize = tableColumnResizer(handle);
        if(!resize) return;
        event.preventDefault();
        handle.setPointerCapture(event.pointerId);
        const listeners = new AbortController();
        let moved = false;
        handle.addEventListener('pointermove', move => {
            if(move.pointerId !== event.pointerId) return;
            if(move.clientX !== event.clientX) moved = true;
            if(moved) resize(move.clientX - event.clientX);
        }, {signal: listeners.signal});
        handle.addEventListener('lostpointercapture', () => listeners.abort(), {once: true});
    });
    delegate('keydown', '.flex-table-resize', (event, handle) => {
        if(event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        tableColumnResizer(handle)?.(event.key === 'ArrowRight' ? 16 : -16);
    });
    delegate('click', '.flex-table-grid > tbody > tr > .flex-table-cell, .flex-table-grid > thead > tr > th', (event, cell) => {
        const target = event.target;
        if(event.defaultPrevented || !(target instanceof Element) || target.closest('td, th') !== cell) return;
        if(target.closest('input, textarea, select, button, a, label, [contenteditable], [role="button"], [role="tab"]')) return;
        if(window.getSelection()?.type === 'Range') return;
        const control = all<HTMLElement>(cell, '.flex-table-input > .InputfieldContent :is(input:not([type="hidden"]), textarea, select):not(:disabled)')
            .find(input => input.getClientRects().length && getComputedStyle(input).visibility === 'visible');
        control?.focus({preventScroll: true});
    });
    delegate('click', '.flex-row-retry', (event, button) => { event.preventDefault(); void loadRow(closest(button, '.flex-row')); });
    const tableCells = '.flex-table-grid > tbody > .flex-row > :is(td, th), .flex-table-grid > thead > tr > th';
    let tablePointer: number | undefined;
    let closeAfterPointer: (() => void) | undefined;
    delegate('pointerdown', tableCells, event => {
        if(event.isPrimary && event.button === 0) tablePointer = event.pointerId;
    });
    for(const type of ['pointerup', 'pointercancel'] as const) {
        document.addEventListener(type, event => {
            if(tablePointer !== event.pointerId) return;
            // Let the click finish before collapsing a detail row above the clicked cell.
            if(closeAfterPointer) window.setTimeout(closeAfterPointer, 0);
            tablePointer = undefined;
            closeAfterPointer = undefined;
        }, true);
    }
    delegate('focusin', tableCells, (_event, cell) => {
        const set = closest(cell, '.flex-table');
        const active = tableEditors.get(set);
        if(!active || active.button.parentElement === cell) return;
        const close = () => {
            if(tableEditors.get(set) === active && cell.contains(document.activeElement) && !treeBusy(set)) closeTableEditor(set);
        };
        if(tablePointer === undefined) close(); else closeAfterPointer = close;
    });
    delegate('click', '.flex-table-preview', (_event, button) => openTableEditor(button as HTMLButtonElement));
    delegate('click', '.flex-table-editor-close', (_event, button) => {
        if(!treeBusy(button)) closeTableEditor(closest(button, '.flex-table'), true);
    });
    function positionTableMenu(): void {
        const menu = document.querySelector<HTMLDetailsElement>('.flex-table-more[open]');
        if(!menu) return;
        const summary = one(menu, 'summary');
        const panel = one(menu, '.flex-table-secondary');
        if(typeof panel.showPopover === 'function' && !panel.matches(':popover-open')) {
            panel.setAttribute('popover', 'manual');
            panel.showPopover();
        }
        const anchor = summary.getBoundingClientRect();
        const margin = 8;
        const width = document.documentElement.clientWidth || window.innerWidth;
        const height = document.documentElement.clientHeight || window.innerHeight;
        const rtl = getComputedStyle(summary).direction === 'rtl';
        const left = rtl ? anchor.right : anchor.left - panel.offsetWidth;
        panel.style.left = Math.max(margin, Math.min(left, width - panel.offsetWidth - margin)) + 'px';
        panel.style.top = Math.max(margin, Math.min(anchor.top, height - panel.offsetHeight - margin)) + 'px';
    }
    document.addEventListener('toggle', event => {
        const menu = event.target;
        if(!(menu instanceof HTMLDetailsElement) || !menu.matches('.flex-table-more')) return;
        if(menu.open) positionTableMenu();
        else {
            const panel = one(menu, '.flex-table-secondary');
            if(typeof panel.hidePopover === 'function' && panel.matches(':popover-open')) panel.hidePopover();
        }
    }, true);
    document.addEventListener('scroll', positionTableMenu, {capture: true, passive: true});
    window.addEventListener('resize', positionTableMenu);
    for(const name of ['click', 'focusin']) document.addEventListener(name, event => {
        const target = event.target;
        if(!(target instanceof Node)) return;
        all<HTMLDetailsElement>(document, `:is(${rowMenus})[open]`).forEach(details => {
            if(!details.contains(target)) details.open = false;
        });
    }, true);
    delegate('keydown', `:is(${rowMenus})[open]`, (event, details) => {
        if(event.key !== 'Escape') return;
        event.preventDefault(); event.stopPropagation();
        (details as HTMLDetailsElement).open = false;
        one(details, 'summary').focus();
    });
    delegate('click', '.flex-row-toggle', (event, button) => {
        event.preventDefault(); event.stopPropagation(); const row = closest(button, '.flex-row'); openRow(row, row.matches('.InputfieldStateCollapsed'));
    });
    delegate('keydown', '.flex-row-sort', async (event, button) => {
        if(!['ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault(); event.stopPropagation();
        const row = closest(button, '.flex-row'), set = closest(row, '.flex-rows'); if(treeBusy(set)) return;
        if(set.matches('.flex-table')) closeTableEditor(set);
        const siblings = rows(set);
        const index = siblings.indexOf(row);
        let target = event.key === 'ArrowUp' ? siblings[index - 1] : siblings[index + 1];
        if(event.key === 'Home') target = siblings[0]; if(event.key === 'End') target = siblings[siblings.length - 1];
        if(!target?.matches('.flex-row') || target === row) return;
        teardown(row);
        if(event.key === 'ArrowUp' || event.key === 'Home') target.before(row); else target.after(row);
        order(set); await classic.setup(row); button.focus(); moved(set, row);
    });
    function titlesChanged(target: HTMLElement): void {
        const tree = target.closest<HTMLElement>('.InputfieldFlex');
        if(tree) {
            all(tree, '.flex-row[data-flex-title]').forEach(row => syncTitleSources(row, target));
            queueTitleRefresh(tree);
            return;
        }
        const form = target.closest('form');
        if(!form) return;
        const name = attr(target, 'name');
        if(!name) return;
        all(form, '.InputfieldFlex').forEach(field => {
            if(!field.querySelector('[data-flex-title-refresh]')) return;
            const dependencies = externalTitleDependencies(field);
            if(dependencies.has(name) || dependencies.has(dependencyName(name))) queueTitleRefresh(field);
        });
    }
    ['input', 'change'].forEach(name => document.addEventListener(name, event => {
        if(event.target instanceof HTMLElement && event.target.matches('input:not(.flex-rows-order), select, textarea, .InputfieldTinyMCEEditor[contenteditable]')) titlesChanged(event.target);
    }));
    let openingInvalidDetail = false;
    document.addEventListener('invalid', event => {
        if(!(event.target instanceof Element)) return;
        for(let row = event.target.closest<HTMLElement>('.flex-row'); row; row = row.parentElement?.closest<HTMLElement>('.flex-row') || null) openRow(row, true);
        const editor = event.target.closest<HTMLElement>('.flex-table-editor[hidden]');
        const button = editor?.previousElementSibling;
        if(button instanceof HTMLButtonElement && !openingInvalidDetail) {
            // Keep the first invalid detail open until the browser focuses the control.
            openingInvalidDetail = true;
            queueMicrotask(() => { openingInvalidDetail = false; });
            openTableEditor(button, false);
        }
    }, true);
    delegate('dblclick', '[data-flex-row-action="remove"]', (event, element) => {
        event.preventDefault(); event.stopPropagation();
        const button = element as HTMLButtonElement, set = closest(button, '.flex-rows'); if(button.disabled || treeBusy(set)) return;
        const undo = closest(button, '.flex-row').matches('.flex-row-remove-pending');
        const apply = () => rows(set).forEach(row => { if(row.matches('.flex-row-remove-pending') === undo) one<HTMLButtonElement>(row, ':scope > .flex-row-header [data-flex-row-action="remove"]').click(); });
        if(undo) apply(); else classic.confirm(message('removeAll', 'Remove all items?'), apply);
    });
    delegate('click', '.flex-row-add, .flex-row-action', (event, control) => { void rowAction(event, control as HTMLButtonElement); });
    delegate('change', '.flex-row-type', (event, control) => { void rowAction(event, control as HTMLSelectElement); });
    async function rowAction(event: Event, control: HTMLButtonElement | HTMLSelectElement): Promise<void> {
        event.preventDefault(); event.stopPropagation();
        const set = closest(control, '.flex-rows');
        const ancestor = control.closest<HTMLElement>('.flex-row');
        const row = ancestor?.closest('.flex-rows') === set ? ancestor : null;
        if(control.disabled || treeBusy(set)) return;
        const more = control.closest<HTMLDetailsElement>(rowMenus);
        if(more) {
            more.open = false;
            one(more, 'summary').focus();
        }
        let type = attr(set, 'data-flex-type'), action = attr(control, 'data-flex-row-action');
        if(control instanceof HTMLSelectElement) {
            type = control.value;
            if(!type) return;
            control.selectedIndex = 0;
            action = type === '@clipboard' ? 'paste-end' : 'add';
        } else if(control.matches('.flex-row-add')) action = 'add';
        const pasting = action.startsWith('paste-');
        const error = one(set, ':scope > .flex-rows-error');
        const status = one(set, ':scope > .flex-rows-status');
        error.textContent = '';
        status.textContent = '';
        status.classList.toggle('uk-hidden-visually', action !== 'clipboard-copy' && !pasting);
        if(action === 'clipboard-copy' && row) {
            setTreeBusy(set, true);
            try {
                const clipboard = navigator.clipboard;
                if(!clipboard?.writeText) throw new Error(message('clipboardUnavailable', 'The clipboard is unavailable. Open the editor over HTTPS.'));
                const text = Promise.resolve().then(() => copyRow(set, row));
                // WebKit needs the write to start in the click handler, before the AJAX result.
                if(window.ClipboardItem && clipboard.write) {
                    const blob = text.then(value => new Blob([value], {type: 'text/plain'}));
                    const writing = clipboard.write([new ClipboardItem({'text/plain': blob})]);
                    await Promise.all([text, writing.catch(async problem => {
                        await text;
                        throw problem;
                    })]);
                } else {
                    await clipboard.writeText(await text);
                }
                status.textContent = message('rowCopied', 'Row copied to the clipboard.');
            } catch(problem) { error.textContent = clipboardError(problem); }
            finally { setTreeBusy(set, false); }
            return;
        }
        if(action === 'visibility' && row) {
            const hidden = !row.matches('.flex-row-hidden'); row.classList.toggle('flex-row-hidden', hidden);
            change(one<HTMLInputElement>(row, ':scope > .flex-row-hidden-value, :scope > .flex-table-actions .flex-row-hidden-value'), hidden ? '1' : '0');
            const label = attr(control, hidden ? 'data-flex-show-label' : 'data-flex-hide-label');
            control.setAttribute('aria-pressed', String(hidden)); control.setAttribute('aria-label', label); control.title = label;
            const text = control.querySelector('.flex-row-action-label');
            if(text) text.textContent = label;
            const icon = one(control, 'i'); icon.classList.toggle('fa-eye', !hidden); icon.classList.toggle('fa-eye-slash', hidden); return;
        }
        if(action === 'remove' && row) {
            const removed = !row.matches('.flex-row-remove-pending'); window.tinymce?.triggerSave(); if(removed) openRow(row, false);
            removalState(row, removed); rowState(row).removalReady = true; order(set); queueTitleRefresh(closest(set, '.InputfieldFlex')); control.focus(); return;
        }
        if(row) closeRowEditors(row);
        if(action === 'duplicate' && row && attr(row, 'data-flex-loaded') === '0' && !(await loadRow(row))) return;
        setTreeBusy(set, true);
        let focus: HTMLElement | undefined;
        try {
            const clipboard = pasting ? await readClipboard() : '';
            window.tinymce?.triggerSave();
            const data = new URLSearchParams({flex_action: action, flex_set: attr(set, 'data-flex-set'), flex_order: activeRows(set).map(row => attr(row, 'data-flex-row')).join(',')});
            const orders: Record<string, string[]> = {};
            all(closest(set, '.InputfieldFlex'), '.flex-rows').forEach(current => {
                if(!current.closest('.flex-row-remove-pending')) orders[attr(current, 'data-flex-set')] = activeRows(current).map(row => attr(row, 'data-flex-row'));
            });
            data.set('flex_orders', JSON.stringify(orders));
            if(action === 'add') data.set('flex_type', type);
            else if(action === 'duplicate' && row) appendSource(data, row);
            if(pasting) {
                data.set('flex_clipboard', clipboard);
                if(action !== 'paste-end' && row) data.set('flex_source', attr(row, 'data-flex-row'));
            }
            const token = csrf(); if(token) data.set(token.name, token.value); data.set('flex_complete', attr(set, 'data-flex-draft'));
            const response = await fetch(attr(set, 'data-flex-page-url'), {method: 'POST', credentials: 'same-origin', headers: requestHeaders(set), body: data});
            const result: RowResponse = await response.json();
            if(!response.ok || result.error) throw new Error(result.message || message('unableToAdd', 'Unable to add item'));
            if(!set.isConnected) return;
            const fresh = parseHTML(result.html);
            if(action === 'paste-above' && row) row.before(fresh);
            else if((action === 'duplicate' || action === 'paste-below') && row) row.after(fresh);
            else rowList(set).append(fresh);
            await activateScripts(fresh, response); order(set); await classic.setup(fresh); initRows(set);
            [fresh, ...all(fresh, '.flex-row[data-flex-title]')].forEach(row => syncTitleSources(row));
            queueTitleRefresh(closest(set, '.InputfieldFlex'));
            focus = fresh.querySelector<HTMLElement>(':scope > .flex-row-header .flex-row-toggle, :scope > .flex-table-actions summary') || undefined;
            classic.added(fresh);
            if(pasting) status.textContent = [message('rowPasted', 'Row pasted.'), result.warning].filter(Boolean).join(' ');
        } catch(problem) { error.textContent = pasting ? clipboardError(problem) : errorMessage(problem); }
        finally { setTreeBusy(set, false); }
        focus?.focus();
    }
    function appendSource(data: URLSearchParams, row: HTMLElement): void {
        data.set('flex_source', attr(row, 'data-flex-row'));
        // A Repeater owner URL must not be overridden by the main PageEdit id.
        serialize(closest(row, 'form')).forEach(([name, value]) => { if(name !== 'id') data.append(name, value); });
    }
    async function copyRow(set: HTMLElement, row: HTMLElement): Promise<string> {
        window.tinymce?.triggerSave();
        classic.saveReady(closest(set, '.InputfieldFlex'));
        const data = new URLSearchParams({flex_action: 'clipboard-copy', flex_set: attr(set, 'data-flex-set')});
        appendSource(data, row);
        const token = csrf(); if(token) data.set(token.name, token.value);
        data.set('flex_complete', attr(set, 'data-flex-draft'));
        const response = await fetch(attr(set, 'data-flex-page-url'), {method: 'POST', credentials: 'same-origin', headers: requestHeaders(set), body: data});
        const result: ClipboardResponse = await response.json();
        if(!response.ok || result.error || typeof result.clipboard !== 'string') throw new Error(result.message || message('unableToLoad', 'Unable to load item'));
        return result.clipboard;
    }
    function readClipboard(): Promise<string> {
        if(!navigator.clipboard?.readText) throw new Error(message('clipboardUnavailable', 'The clipboard is unavailable. Open the editor over HTTPS.'));
        return navigator.clipboard.readText();
    }
    function clipboardError(problem: unknown): string {
        return problem instanceof DOMException && (problem.name === 'NotAllowedError' || problem.name === 'SecurityError')
            ? message('clipboardDenied', 'Clipboard access was denied. Allow access in your browser and try again.')
            : errorMessage(problem);
    }
    interface MetadataSnapshot {
        controls: {name: string; value: string | string[]; type: string; checked: boolean; property: string; suffix: string; native: string}[];
        errors: {id: string; property: string; markup: string}[];
        inline: {id: string; suffix: string; html: string; loaded: boolean; property: string}[];
    }
    function metadataProperty(element: Element): string { return element.closest('[data-flex-metadata-property]')?.getAttribute('data-flex-metadata-property') || ''; }
    function nativeMetadataName(name: string): string { return (name.match(/^(description[0-9]*|tags)_/) || [])[1] || ''; }
    function mediaMetadata(scope: HTMLElement): MetadataSnapshot {
        classic.saveReady(scope);
        return {
            inline: all(scope, '.InputfieldTinyMCEEditor.InputfieldTinyMCEInline').map(editor => ({id: editor.id, suffix: editor.id.split('__').slice(2).join('__'), html: editor.innerHTML, loaded: editor.matches('.InputfieldTinyMCELoaded'), property: metadataProperty(editor)})),
            controls: all<Control>(scope, controlSelector).filter(input => input.name && (/^(description[0-9]*|tags|focus)_/.test(input.name) || input.closest('[data-flex-metadata]'))).map(input => ({
                name: input.name, value: value(input), type: input.type, checked: input instanceof HTMLInputElement && input.checked,
                property: metadataProperty(input), suffix: input.name.split('__').slice(2).join('__'), native: nativeMetadataName(input.name)
            })),
            errors: all(scope, '[data-flex-metadata-property].InputfieldStateError').map(wrapper => ({id: wrapper.id, property: attr(wrapper, 'data-flex-metadata-property'), markup: all(wrapper, ':scope > .InputfieldContent > .InputfieldError').map(error => error.outerHTML).join('')}))
        };
    }
    function restoreMediaMetadata(scope: HTMLElement, snapshot: MetadataSnapshot, replacement: boolean): void {
        snapshot.controls.forEach(item => {
            all<Control>(scope, controlSelector).filter(input => {
                let match = input.name === item.name;
                if(replacement && item.property) match = metadataProperty(input) === item.property && input.name.split('__').slice(2).join('__') === item.suffix;
                else if(replacement && item.native) match = nativeMetadataName(input.name) === item.native;
                return match && (!/^(checkbox|radio)$/.test(item.type) || input.value === item.value);
            }).forEach(input => {
                if(input instanceof HTMLInputElement && /^(checkbox|radio)$/.test(item.type)) input.checked = item.checked;
                else setValue(input, item.value);
            });
        });
        snapshot.errors.forEach(item => {
            all(scope, '[data-flex-metadata-property]').filter(wrapper => replacement ? attr(wrapper, 'data-flex-metadata-property') === item.property : wrapper.id === item.id).forEach(wrapper => {
                wrapper.classList.add('InputfieldStateError', 'ui-state-error');
                if(!wrapper.querySelector('.InputfieldError')) insertHTML(one(wrapper, ':scope > .InputfieldContent'), 'afterbegin', item.markup);
            });
        });
        snapshot.inline.forEach(item => {
            all(scope, '.InputfieldTinyMCEEditor.InputfieldTinyMCEInline').filter(editor => replacement ? metadataProperty(editor) === item.property && editor.id.split('__').slice(2).join('__') === item.suffix : editor.id === item.id).forEach(editor => {
                setHTML(editor, item.html);
                // Recreate the native hidden transport even if this editor is never focused.
                if(item.loaded) classic.initInline(editor);
            });
        });
    }
    async function upload(input: HTMLInputElement, files: File[]): Promise<void> {
        const upload = closest(input, '[data-flex-upload="1"]'), field = closest(upload, '.Inputfield'), token = csrf();
        if(!files.length) return;
        if(treeBusy(upload)) { input.value = ''; return; }
        setTreeBusy(upload, true); input.disabled = true;
        const status = document.createElement('p'); status.className = 'flex-upload-status'; status.setAttribute('role', 'status'); upload.append(status);
        try {
            for(const file of files) {
                status.textContent = message('uploading', 'Uploading {name}…').replace('{name}', file.name);
                const headers: Record<string, string> = {'X-Requested-With': 'XMLHttpRequest', 'X-FIELDNAME': attr(upload, 'data-fieldname'), 'X-FLEX-DRAFT': attr(upload, 'data-flex-draft'), 'X-FLEX-PATH': attr(upload, 'data-flex-path'), 'X-FILENAME': encodeURIComponent(file.name), 'Content-Type': 'application/octet-stream'};
                if(token) headers['X-' + token.name] = token.value;
                const response = await fetch(attr(upload, 'data-posturl'), {method: 'POST', credentials: 'same-origin', headers, body: file});
                const result: UploadItem[] | {error?: boolean; message?: string} = await response.json();
                if(!response.ok || (!Array.isArray(result) && result.error)) throw new Error((!Array.isArray(result) && result.message) || message('unableToUpload', 'Unable to upload file'));
                if(!Array.isArray(result)) throw new Error(message('unableToUpload', 'Unable to upload file'));
                for(const item of result) {
                    if(item.error) throw new Error(item.message || message('unableToUpload', 'Unable to upload file'));
                    let list = field.querySelector<HTMLElement>('.InputfieldFileList') || field.querySelector<HTMLElement>('.InputfieldImageList');
                    if(!list) {
                        list = document.createElement('ul'); list.className = field.matches('.InputfieldImage') ? 'InputfieldImageList gridImages ui-helper-clearfix' : 'InputfieldFileList ui-helper-clearfix';
                        upload.before(list); all(field, '.InputfieldFileListPlaceholder').forEach(item => item.remove());
                    }
                    const replacement = Number(field.querySelector<HTMLInputElement>('.InputfieldFileMaxFiles, .InputfieldImageMaxFiles')?.value) === 1;
                    const metadata = replacement ? mediaMetadata(list) : null;
                    if(replacement) { classic.destroyEditors(list); all(list, ':scope > li').forEach(item => item.remove()); }
                    const fresh = parseHTML(item.markup); list.append(fresh); await activateScripts(fresh, response);
                    if(metadata) restoreMediaMetadata(fresh, metadata, true);
                }
                field.classList.remove('InputfieldFileEmpty'); field.classList.add('InputfieldStateChanged'); await classic.setup(field); titlesChanged(field);
            }
            status.remove();
        } catch(error) { status.setAttribute('role', 'alert'); status.textContent = errorMessage(error); }
        finally { input.value = ''; input.disabled = false; setTreeBusy(upload, false); }
    }
    // Capture only the Flex transport before native File/Image listeners see it.
    document.addEventListener('change', event => {
        const input = event.target;
        if(!(input instanceof HTMLInputElement) || input.type !== 'file' || !input.closest('[data-flex-upload="1"]')) return;
        event.stopImmediatePropagation(); void upload(input, Array.from(input.files || []));
    }, true);
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(name => document.addEventListener(name, rawEvent => {
        const event = rawEvent as DragEvent;
        if(!(event.target instanceof Element)) return;
        const field = event.target.closest<HTMLElement>('.Inputfield');
        if(!field || !event.dataTransfer || !Array.from(event.dataTransfer.types).includes('Files')) return;
        const input = field.querySelector<HTMLInputElement>('[data-flex-upload="1"] input[type="file"]');
        if(!input || input.closest('.Inputfield') !== field) return;
        event.preventDefault(); event.stopImmediatePropagation();
        if(name === 'dragleave' && event.relatedTarget instanceof Node && field.contains(event.relatedTarget)) return;
        const active = name === 'dragenter' || name === 'dragover'; field.classList.toggle('pw-drag-in-file', active);
        all(field, ':scope > .InputfieldContent').forEach(content => content.classList.toggle('ui-state-hover', active));
        if(name === 'drop') void upload(input, Array.from(event.dataTransfer.files));
    }, true));
    async function refreshMedia(draft: string, path: string): Promise<void> {
        const upload = all(document, '[data-flex-upload="1"]').find(upload => attr(upload, 'data-flex-draft') === draft && attr(upload, 'data-flex-path') === path);
        if(!upload) return;
        const field = closest(upload, '.Inputfield'), token = csrf();
        try {
            const data = new URLSearchParams({flex_action: 'media-render', flex_path: path, flex_complete: draft}); if(token) data.set(token.name, token.value);
            const response = await fetch(attr(upload, 'data-posturl'), {method: 'POST', credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest', 'X-FIELDNAME': attr(upload, 'data-fieldname'), 'X-FLEX-DRAFT': draft}, body: data});
            const result: RowResponse = await response.json();
            if(!response.ok || result.error) throw new Error(result.message || message('unableToRefreshImage', 'Unable to refresh image'));
            const metadata = mediaMetadata(field); classic.destroyEditors(field);
            const content = one(field, ':scope > .InputfieldContent'); setHTML(content, result.html); await activateScripts(content, response);
            restoreMediaMetadata(field, metadata, false); await classic.setup(field); field.classList.add('InputfieldStateChanged'); titlesChanged(field); classic.closeDialog();
        } catch(error) {
            const status = document.createElement('p'); status.setAttribute('role', 'alert'); status.className = 'flex-upload-status'; status.textContent = errorMessage(error); field.append(status);
        }
    }
}
