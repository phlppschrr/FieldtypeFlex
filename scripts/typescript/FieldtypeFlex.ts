namespace FieldtypeFlex {
    interface RowResponse {html: string; error?: boolean; message?: string;}
    interface UploadItem {markup: string; error?: boolean; message?: string;}
    interface RowState { labels?: Record<string, string>; removalReady?: boolean; load?: Promise<boolean>; }
    interface TitlePart { text: string; badge: boolean; literal?: boolean; muted?: boolean; }
    const state = new WeakMap<HTMLElement, RowState>();
    const disabledBeforeRemoval = new WeakMap<Control, boolean>();
    const busyTrees = new WeakSet<HTMLElement>();
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
    function titleParts(format: string, values: Record<string, string>, number: number, type: string, path: string, cleanup = true): TitlePart[] {
        const parts: TitlePart[] = [];
        format.split(/(\[[^\[\]]*\]|_\{[^{}]+\}_|\{[^{}]+\}|#n)/gi).filter(Boolean).forEach(function(part) {
            if(part[0] === '[' && part.slice(-1) === ']') {
                var details = titleParts(part.slice(1, -1), values, number, type, path, false);
                if(!details.length) details.push({text: '', badge: false, literal: false});
                details.forEach(function(detail) {
                    detail.muted = true;
                    parts.push(detail);
                });
                return;
            }
            var badge = part.slice(0, 2) === '_{' && part.slice(-2) === '}_';
            var token = badge ? part.slice(1, -1) : part, value = part, literal = false;
            if(token.toLowerCase() === '#n') value = '#' + number;
            else if(token[0] === '{' && token.slice(-1) === '}') {
                value = '';
                token.slice(1, -1).split('|').some(function(name) {
                    var candidate = name === '@type' ? type : (name === '@path' ? path : (Object.prototype.hasOwnProperty.call(values, name) ? values[name] : ''));
                    if(String(candidate).trim() === '') return false;
                    value = String(candidate);
                    return true;
                });
            } else literal = true;
            parts.push({text: value, badge: badge, literal: literal});
        });
        if(cleanup === false) return parts;
        parts.forEach(function(part, i) {
            if(!part.literal || !/^[\s›|/:;,–—-]+$/.test(part.text)) return;
            var before = parts.some(function(other, j) { return j < i && other.text.trim() !== '' && (!other.literal || !/^[\s›|/:;,–—-]+$/.test(other.text)); });
            var after = parts.some(function(other, j) { return j > i && other.text.trim() !== '' && (!other.literal || !/^[\s›|/:;,–—-]+$/.test(other.text)); });
            if(!before || !after) part.text = '';
        });
        let separator: number | null = null, omitted = false;
        parts.forEach(function(part, i) {
            if(part.text === '') {
                if(!part.literal) omitted = true;
                return;
            }
            if(part.literal && /^[\s›|/:;,–—-]+$/.test(part.text)) {
                if(separator !== null && omitted) parts[separator].text = '';
                separator = i;
            } else separator = null;
            omitted = false;
        });
        return parts;
    }
    function rowTitle(row: HTMLElement, index: number, path: string): TitlePart[] {
        const format = attr(row, 'data-flex-label-format'), type = attr(row, 'data-flex-type-label');
        const values = rowState(row).labels ?? JSON.parse(attr(row, 'data-flex-label-values') || '{}');
        let parts = titleParts(format, values, index + 1, type, path);
        if(!parts.some(part => part.text.trim() !== '')) parts = [{text: (format ? '' : type + ' ') + '#' + (index + 1), badge: false}];
        if(!format) parts.unshift({text: type, badge: true}, {text: ' ', badge: false});
        return parts;
    }
    function contextPath(row: HTMLElement): string {
        const path: string[] = [];
        for(let parent = row.parentElement?.closest<HTMLElement>('.flex-row'); parent; parent = parent.parentElement?.closest<HTMLElement>('.flex-row')) path.unshift(attr(parent, 'data-flex-type-label'));
        return path.join(' › ');
    }
    function updateLabels(set: HTMLElement): void {
        rows(set).forEach((row, index) => {
            const title = row.querySelector<HTMLElement>(':scope > .flex-row-header .flex-row-title');
            if(!title) return;
            title.replaceChildren();
            rowTitle(row, index, contextPath(row)).forEach(part => {
                if(!part.text) return;
                if(part.badge || part.muted) {
                    const span = document.createElement('span');
                    span.classList.toggle('flex-row-type-badge', !!part.badge); span.classList.toggle('flex-row-deemphasized', !!part.muted);
                    span.textContent = part.text; title.append(span);
                } else title.append(document.createTextNode(part.text));
            });
            all(row, ':scope > .flex-row-content .flex-rows').filter(set => set.closest('.flex-row') === row).forEach(updateLabels);
        });
    }
    function treeBusy(scope: HTMLElement): boolean { return busyTrees.has(closest(scope, '.InputfieldFlex')); }
    function setTreeBusy(scope: HTMLElement, busy: boolean): void {
        const tree = closest(scope, '.InputfieldFlex');
        if(busy) busyTrees.add(tree); else busyTrees.delete(tree);
        tree.setAttribute('aria-busy', String(busy)); all(tree, '.flex-rows').forEach(updateActions);
        const form = closest(tree, 'form');
        if(!busy && !form.querySelector('.InputfieldFlex[aria-busy="true"], .flex-row[data-flex-load-failed="1"]')) all(form, ':scope > .flex-form-request-error').forEach(error => error.remove());
    }
    function updateActions(set: HTMLElement): void {
        const count = activeRows(set).length, min = Number(attr(set, 'data-flex-min')) || 0, max = Number(attr(set, 'data-flex-max')) || 0, busy = treeBusy(set);
        all<HTMLButtonElement>(set, ':scope > .flex-row-add').forEach(button => button.disabled = busy || (max > 0 && count >= max));
        rows(set).forEach(row => {
            const header = one(row, ':scope > .flex-row-header');
            const toggle = header.querySelector<HTMLButtonElement>('.flex-row-toggle');
            if(toggle) toggle.disabled = busy;
            one<HTMLButtonElement>(header, '[data-flex-row-action="duplicate"]').disabled = busy || (max > 0 && count >= max);
            one<HTMLButtonElement>(header, '[data-flex-row-action="remove"]').disabled = busy || (!row.matches('.flex-row-remove-pending') && count <= min);
            one<HTMLButtonElement>(header, '.flex-row-sort').disabled = busy || count < 2;
        });
        sortable.disabled(rowList(set), busy || count < 2);
    }
    function order(set: HTMLElement): void {
        change(one<HTMLInputElement>(set, ':scope > .flex-rows-order'), rows(set).map(row => attr(row, 'data-flex-row')).join(','));
        closest(set, '.InputfieldFlex').classList.add('InputfieldStateChanged'); updateLabels(set); updateActions(set);
    }
    function csrf(): HTMLInputElement | null { return document.querySelector('#ProcessPageEdit input._post_token'); }
    function teardown(row: HTMLElement): void {
        if(!window.tinymce) return;
        all<HTMLTextAreaElement>(row, 'textarea.InputfieldTinyMCEEditor').forEach(input => {
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
                await classic.setup(row); if(row.matches('.flex-row-remove-pending')) removalState(row, true);
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
    function moved(set: HTMLElement, row: HTMLElement): void {
        one(set, ':scope > .flex-rows-status').textContent = message('itemMoved', 'Item moved to position {position} of {count}').replace('{position}', String(rows(set).indexOf(row) + 1)).replace('{count}', String(rows(set).length));
    }
    function initRows(context: ParentNode): void {
        const sets = all(context, '.flex-rows'); if(context instanceof HTMLElement && context.matches('.flex-rows')) sets.unshift(context);
        sets.forEach(set => {
            rows(set).forEach(row => {
                if(row.matches('.flex-row-remove-pending') && !rowState(row).removalReady) { removalState(row, true); rowState(row).removalReady = true; }
                if(!row.matches('.flex-row-remove-pending') && row.querySelector('.InputfieldStateError')) openRow(row, true);
                syncToggle(row);
            });
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
            if(editor) { editor.save(); input.dispatchEvent(new Event('input', {bubbles: true})); }
        });
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
    delegate('click', '.flex-row-toggle', (event, button) => {
        event.preventDefault(); event.stopPropagation(); const row = closest(button, '.flex-row'); openRow(row, row.matches('.InputfieldStateCollapsed'));
    });
    delegate('keydown', '.flex-row-sort', async (event, button) => {
        if(!['ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault(); event.stopPropagation();
        const row = closest(button, '.flex-row'), set = closest(row, '.flex-rows'); if(treeBusy(set)) return;
        let target = event.key === 'ArrowUp' ? row.previousElementSibling : row.nextElementSibling;
        if(event.key === 'Home') target = rows(set)[0]; if(event.key === 'End') target = rows(set).slice(-1)[0];
        if(!target?.matches('.flex-row') || target === row) return;
        teardown(row);
        if(event.key === 'ArrowUp' || event.key === 'Home') target.before(row); else target.after(row);
        order(set); await classic.setup(row); one(row, ':scope > .flex-row-header .flex-row-sort').focus(); moved(set, row);
    });
    function labelChanged(input: Control): void {
        const property = input.closest<HTMLElement>('[data-flex-label-property]'), row = property?.closest<HTMLElement>('.flex-row');
        if(!property || !row) return;
        const kind = attr(property, 'data-flex-label-kind');
        if(kind === 'file' || kind === 'image' || (kind === 'page' && !(input instanceof HTMLSelectElement))) return;
        let label: string | string[] = value(input);
        if(input instanceof HTMLSelectElement) label = Array.from(input.selectedOptions, option => option.textContent).join(', ');
        else if(input.matches('[type="checkbox"], [type="radio"]')) {
            const options: Record<string, string> = JSON.parse(attr(property, 'data-flex-label-options') || '{}');
            label = all<HTMLInputElement>(property, ':checked').map(option => Object.prototype.hasOwnProperty.call(options, option.value) ? options[option.value] : option.value).join(', ');
        } else if(input.matches('[type="file"], [type="hidden"], button')) return;
        const text = document.createElement('textarea'); setHTML(text, String(label || '').replace(/<[^>]*>/g, '').replace(/</g, '&lt;').replace(/>/g, '&gt;'));
        const current = rowState(row); current.labels ??= JSON.parse(attr(row, 'data-flex-label-values') || '{}');
        current.labels![attr(property, 'data-flex-label-property')] = text.value.replace(/\s+/g, ' ').trim().slice(0, 160);
        updateLabels(closest(row, '.flex-rows'));
    }
    ['input', 'change'].forEach(name => document.addEventListener(name, event => {
        if(event.target instanceof HTMLInputElement || event.target instanceof HTMLSelectElement || event.target instanceof HTMLTextAreaElement || event.target instanceof HTMLButtonElement) labelChanged(event.target);
    }));
    document.addEventListener('invalid', event => {
        if(!(event.target instanceof Element)) return;
        for(let row = event.target.closest<HTMLElement>('.flex-row'); row; row = row.parentElement?.closest<HTMLElement>('.flex-row') || null) openRow(row, true);
    }, true);
    delegate('dblclick', '[data-flex-row-action="remove"]', (event, element) => {
        event.preventDefault(); event.stopPropagation();
        const button = element as HTMLButtonElement, set = closest(button, '.flex-rows'); if(button.disabled || treeBusy(set)) return;
        const undo = closest(button, '.flex-row').matches('.flex-row-remove-pending');
        const apply = () => rows(set).forEach(row => { if(row.matches('.flex-row-remove-pending') === undo) one<HTMLButtonElement>(row, ':scope > .flex-row-header [data-flex-row-action="remove"]').click(); });
        if(undo) apply(); else classic.confirm(message('removeAll', 'Remove all items?'), apply);
    });
    delegate('click', '.flex-row-add, .flex-row-action', (event, button) => { void rowAction(event, button as HTMLButtonElement); });
    async function rowAction(event: MouseEvent, button: HTMLButtonElement): Promise<void> {
        event.preventDefault(); event.stopPropagation();
        const set = closest(button, '.flex-rows'), row = button.closest<HTMLElement>('.flex-row');
        if(button.disabled || treeBusy(set)) return;
        const action = button.matches('.flex-row-add') ? 'add' : attr(button, 'data-flex-row-action');
        const error = one(set, ':scope > .flex-rows-error'); error.textContent = '';
        if(action === 'visibility' && row) {
            const hidden = !row.matches('.flex-row-hidden'); row.classList.toggle('flex-row-hidden', hidden);
            change(one<HTMLInputElement>(row, ':scope > .flex-row-hidden-value, :scope > .flex-table-actions .flex-row-hidden-value'), hidden ? '1' : '0');
            const label = attr(button, hidden ? 'data-flex-show-label' : 'data-flex-hide-label');
            button.setAttribute('aria-pressed', String(hidden)); button.setAttribute('aria-label', label); button.title = label;
            const icon = one(button, 'i'); icon.classList.toggle('fa-eye', !hidden); icon.classList.toggle('fa-eye-slash', hidden); return;
        }
        if(action === 'remove' && row) {
            const removed = !row.matches('.flex-row-remove-pending'); window.tinymce?.triggerSave(); if(removed) openRow(row, false);
            removalState(row, removed); rowState(row).removalReady = true; order(set); button.focus(); return;
        }
        if(action === 'duplicate' && row && attr(row, 'data-flex-loaded') === '0' && !(await loadRow(row))) return;
        setTreeBusy(set, true);
        try {
            window.tinymce?.triggerSave();
            const data = new URLSearchParams({flex_action: action, flex_set: attr(set, 'data-flex-set'), flex_order: activeRows(set).map(row => attr(row, 'data-flex-row')).join(',')});
            const orders: Record<string, string[]> = {};
            all(closest(set, '.InputfieldFlex'), '.flex-rows').forEach(current => {
                if(!current.closest('.flex-row-remove-pending')) orders[attr(current, 'data-flex-set')] = activeRows(current).map(row => attr(row, 'data-flex-row'));
            });
            data.set('flex_orders', JSON.stringify(orders));
            if(action === 'add') data.set('flex_type', attr(set, 'data-flex-type') || one<HTMLSelectElement>(set, ':scope > .flex-row-type-control .flex-row-type').value);
            else if(action === 'duplicate' && row) {
                data.set('flex_source', attr(row, 'data-flex-row'));
                // A Repeater's owner URL must not be overridden by the main PageEdit id.
                serialize(closest(row, 'form')).forEach(([name, value]) => { if(name !== 'id') data.append(name, value); });
            }
            const token = csrf(); if(token) data.set(token.name, token.value); data.set('flex_complete', attr(set, 'data-flex-draft'));
            const response = await fetch(attr(set, 'data-flex-page-url'), {method: 'POST', credentials: 'same-origin', headers: requestHeaders(set), body: data});
            const result: RowResponse = await response.json();
            if(!response.ok || result.error) throw new Error(result.message || message('unableToAdd', 'Unable to add item'));
            if(!set.isConnected) return;
            const fresh = parseHTML(result.html);
            if(action === 'duplicate' && row) row.after(fresh); else rowList(set).append(fresh);
            await activateScripts(fresh, response); order(set); await classic.setup(fresh); initRows(set); (fresh.querySelector<HTMLElement>(':scope > .flex-row-header .flex-row-toggle') || one<HTMLElement>(fresh, ':scope > .flex-row-header .flex-row-sort')).focus(); classic.added(fresh);
        } catch(problem) { error.textContent = errorMessage(problem); }
        finally { setTreeBusy(set, false); }
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
                field.classList.remove('InputfieldFileEmpty'); field.classList.add('InputfieldStateChanged'); await classic.setup(field);
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
            restoreMediaMetadata(field, metadata, false); await classic.setup(field); field.classList.add('InputfieldStateChanged'); classic.closeDialog();
        } catch(error) {
            const status = document.createElement('p'); status.setAttribute('role', 'alert'); status.className = 'flex-upload-status'; status.textContent = errorMessage(error); field.append(status);
        }
    }
}
