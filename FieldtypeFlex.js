"use strict";
var FieldtypeFlex;
(function (FieldtypeFlex) {
    FieldtypeFlex.controlSelector = 'input, select, textarea, button';
    function all(scope, selector) {
        return Array.from(scope.querySelectorAll(selector));
    }
    FieldtypeFlex.all = all;
    function one(scope, selector) {
        const element = scope.querySelector(selector);
        if (!element)
            throw new Error('Missing Flex element: ' + selector);
        return element;
    }
    FieldtypeFlex.one = one;
    function closest(element, selector) {
        const parent = element.closest(selector);
        if (!parent)
            throw new Error('Missing Flex parent: ' + selector);
        return parent;
    }
    FieldtypeFlex.closest = closest;
    function attr(element, name) { return element.getAttribute(name) || ''; }
    FieldtypeFlex.attr = attr;
    function ready(callback) {
        if (document.readyState === 'loading')
            document.addEventListener('DOMContentLoaded', callback, { once: true });
        else
            callback();
    }
    FieldtypeFlex.ready = ready;
    function value(control) {
        if (control instanceof HTMLSelectElement && control.multiple)
            return Array.from(control.selectedOptions, option => option.value);
        return control.value;
    }
    FieldtypeFlex.value = value;
    function setValue(control, value) {
        if (control instanceof HTMLSelectElement && Array.isArray(value)) {
            Array.from(control.options).forEach(option => option.selected = value.includes(option.value));
        }
        else
            control.value = Array.isArray(value) ? value.join(',') : value;
    }
    FieldtypeFlex.setValue = setValue;
    // Match successful form controls, including fieldset disabling and CRLF encoding.
    // Files and submit buttons are deliberately excluded, as in native AJAX forms.
    function serialize(scope) {
        const controls = scope instanceof HTMLFormElement ? Array.from(scope.elements) : all(scope, FieldtypeFlex.controlSelector);
        return controls.flatMap(element => {
            if (!(element instanceof HTMLInputElement || element instanceof HTMLSelectElement || element instanceof HTMLTextAreaElement))
                return [];
            if (!element.name || element.matches(':disabled') || /^(file|submit|button|image|reset)$/i.test(element.type))
                return [];
            if (element instanceof HTMLInputElement && /^(checkbox|radio)$/i.test(element.type) && !element.checked)
                return [];
            const values = element instanceof HTMLSelectElement
                ? Array.from(element.selectedOptions).filter(option => !option.disabled && !(option.parentElement instanceof HTMLOptGroupElement && option.parentElement.disabled)).map(option => option.value)
                : [element.value];
            return values.map(item => [element.name, item.replace(/\r?\n/g, '\r\n')]);
        });
    }
    FieldtypeFlex.serialize = serialize;
    function trustedHTML(html) {
        return window.ProcessWireAdminTheme?.trusted.html(html) ?? html;
    }
    FieldtypeFlex.trustedHTML = trustedHTML;
    function setHTML(scope, html) { scope.innerHTML = trustedHTML(html); }
    FieldtypeFlex.setHTML = setHTML;
    function insertHTML(scope, where, html) { scope.insertAdjacentHTML(where, trustedHTML(html)); }
    FieldtypeFlex.insertHTML = insertHTML;
    function parseHTML(html) {
        const template = document.createElement('template');
        template.innerHTML = trustedHTML(html.trim());
        return one(template.content, '*');
    }
    FieldtypeFlex.parseHTML = parseHTML;
    // Native Inputfields can return inline configuration scripts in AJAX markup.
    // template/innerHTML keep them inert; execute them once after insertion, as before.
    async function activateScripts(scope, response) {
        if (window.ProcessWireAdminTheme) {
            await window.ProcessWireAdminTheme.runScripts(scope, { legacy: FieldtypeFlex.needsLegacy(scope), nonce: response?.headers?.get('X-Bridge-Nonce') || '' });
            return;
        }
        all(scope, 'script').forEach(inert => {
            if (inert.type && !/^(text|application)\/javascript$/.test(inert.type) && inert.type !== 'module')
                return;
            const script = document.createElement('script');
            Array.from(inert.attributes).forEach(attribute => script.setAttribute(attribute.name, attribute.value));
            script.textContent = inert.textContent;
            script.async = false;
            inert.replaceWith(script);
        });
    }
    FieldtypeFlex.activateScripts = activateScripts;
    function change(control, value) {
        control.value = value;
        control.dispatchEvent(new Event('change', { bubbles: true }));
    }
    FieldtypeFlex.change = change;
    function delegate(name, selector, callback) {
        document.addEventListener(name, event => {
            if (!(event.target instanceof Element))
                return;
            const element = event.target.closest(selector);
            if (element)
                callback(event, element);
        });
    }
    FieldtypeFlex.delegate = delegate;
})(FieldtypeFlex || (FieldtypeFlex = {}));
// Admin integration: Bridge public APIs, with the classic ProcessWire stack as fallback.
var FieldtypeFlex;
(function (FieldtypeFlex) {
    FieldtypeFlex.classic = {
        async setup(scope) {
            if (window.ProcessWireAdminTheme) {
                if (FieldtypeFlex.needsLegacy(scope))
                    await window.Bridge?.legacy.load();
                window.ProcessWireAdminTheme.legacy.initElement(scope);
                return;
            }
            const $ = window.jQuery;
            if (!$)
                return;
            if (window.Inputfields)
                window.Inputfields.init($(scope));
            else
                window.InputfieldsInit?.($(scope));
            const inputs = FieldtypeFlex.all(scope, '.Inputfield');
            if (scope.matches('.Inputfield'))
                inputs.unshift(scope);
            inputs.forEach(input => $(input).trigger('reloaded'));
        },
        toggle(row, open) {
            if (FieldtypeFlex.nativeBridge() && window.Bridge?.contract === '1') {
                if (open)
                    window.Bridge.Inputfields.open(row);
                else
                    window.Bridge.Inputfields.close(row);
                return;
            }
            if (window.jQuery && window.Inputfields?.toggle)
                window.Inputfields.toggle(window.jQuery(row), open, 0);
            else {
                row.classList.toggle('InputfieldStateCollapsed', !open);
                row.dispatchEvent(new Event(open ? 'opened' : 'closed', { bubbles: true }));
            }
        },
        events(reloaded, toggled, tinyChanged, mediaRefresh) {
            document.addEventListener('reloaded', event => {
                if (event.target instanceof HTMLElement && event.target.matches('.Inputfield'))
                    reloaded(event.target);
            });
            ['opened', 'closed'].forEach(name => document.addEventListener(name, event => {
                if (event.target instanceof HTMLElement && event.target.matches('.flex-row'))
                    toggled(event.target, name === 'opened');
            }));
            document.addEventListener('change', event => {
                if (event.target instanceof HTMLElement && event.target.matches('.InputfieldTinyMCE[data-flex-label-property]'))
                    tinyChanged(event.target);
            });
            // Upstream custom events are jQuery-only. Bind when a legacy child loads the stack.
            let bound = false;
            const bindLegacy = () => {
                const $ = window.jQuery;
                if (!$ || bound)
                    return;
                bound = true;
                $(document).on('reloaded.flexRows', '.Inputfield', function (event) {
                    if (!event.originalEvent && event.target === this)
                        reloaded(event.target);
                });
                $(document).on('opened.flexRows closed.flexRows', '.flex-row', function (event) {
                    if (!event.originalEvent && event.target === this)
                        toggled(event.target, event.type === 'opened');
                });
                $(document).on('change.flexRows', '.InputfieldTinyMCE[data-flex-label-property]', function (event) {
                    if (!event.originalEvent && event.target === this)
                        tinyChanged(event.target);
                });
                $(document).on('flexMediaRefresh.flexRows', (_event, draft, path) => {
                    if (typeof draft === 'string' && typeof path === 'string')
                        mediaRefresh(draft, path);
                });
            };
            bindLegacy();
            document.addEventListener('pw-legacy-loaded', bindLegacy);
            document.addEventListener('flexMediaRefresh', event => {
                const detail = event.detail;
                if (detail)
                    mediaRefresh(detail.draft, detail.path);
            });
        },
        added(row) {
            if (window.jQuery)
                window.jQuery(document).trigger('flexRowAdded', [window.jQuery(row)]);
            else
                document.dispatchEvent(new CustomEvent('flexRowAdded', { detail: { row } }));
        },
        saveReady(scope) {
            if (window.jQuery)
                FieldtypeFlex.all(scope, '.InputfieldTinyMCE').forEach(input => window.jQuery(input).trigger('saveReady'));
        },
        destroyEditors(scope) {
            if (window.jQuery)
                window.InputfieldTinyMCE?.destroyEditorsIn(window.jQuery(scope));
        },
        initInline(editor) { window.InputfieldTinyMCE?.init(editor, 'flex.mediaMetadata'); },
        closeDialog() {
            if (FieldtypeFlex.nativeBridge())
                window.Bridge?.dialog.close();
            else
                window.jQuery?.('iframe.ui-dialog-content').dialog('close');
        },
        confirm(message, callback) {
            if (window.ProcessWireAdminTheme) {
                void window.Bridge?.confirm(message, callback);
                return;
            }
            if (window.ProcessWire?.confirm)
                window.ProcessWire.confirm(message, callback);
            else if (window.confirm(message))
                callback();
        },
        preserveSelectorPaths() {
            const selector = window.InputfieldSelector;
            if (!selector || selector.flexPathsPreserved)
                return;
            const original = selector.changeAny;
            if (!/\.substring\(0,\s*[\w$.]+\.lastIndexOf\(["']\.["']\)\)/.test(String(original)))
                return;
            let active = false;
            selector.changeAny = function (item) {
                if (active)
                    return original.call(this, item);
                const fields = window.ProcessWire?.config.FieldtypeFlexSelectorFields || [];
                const adjusted = [];
                FieldtypeFlex.all(FieldtypeFlex.closest(item[0], '.InputfieldSelector'), '.select-subfield option:checked').forEach(option => {
                    const path = option.value.split('.');
                    if (path.length !== 3 || !fields.includes(path[0]))
                        return;
                    adjusted.push([option, option.value]);
                    option.value += '.__flex_terminal';
                });
                active = true;
                try {
                    return original.call(this, item);
                }
                finally {
                    adjusted.forEach(([option, value]) => option.value = value);
                    active = false;
                }
            };
            selector.flexPathsPreserved = true;
        }
    };
})(FieldtypeFlex || (FieldtypeFlex = {}));
(function (FieldtypeFlex) {
    function nativeBridge() { return !!window.ProcessWireAdminTheme && !window.ProcessWireAdminTheme.upstream(); }
    FieldtypeFlex.nativeBridge = nativeBridge;
    function needsLegacy(scope) {
        const fields = FieldtypeFlex.all(scope, '.Inputfield[data-bridge-legacy]');
        if (scope.matches('.Inputfield[data-bridge-legacy]'))
            fields.unshift(scope);
        return fields.some(field => window.ProcessWireAdminTheme?.legacy.needsStack(field));
    }
    FieldtypeFlex.needsLegacy = needsLegacy;
})(FieldtypeFlex || (FieldtypeFlex = {}));
var FieldtypeFlex;
(function (FieldtypeFlex) {
    const nativeSorts = new Map();
    let observing = false;
    // Element-only contract: replacing this adapter does not affect Row logic.
    FieldtypeFlex.sortable = {
        init(list, start, stop) {
            if (window.ProcessWireAdminTheme) {
                if (window.Bridge?.contract !== '1' || nativeSorts.has(list))
                    return;
                const state = { disabled: false };
                nativeSorts.set(list, state);
                void window.Bridge.sortable(list, {
                    items: '.flex-row[data-flex-row]', handle: '.flex-row-sort',
                    cancel: 'input, select, textarea, a, button:not(.flex-row-sort)', axis: 'y', threshold: 5,
                    enabled: () => !state.disabled && list.isConnected, start, stop
                }).then(destroy => {
                    if (!list.isConnected) {
                        destroy();
                        nativeSorts.delete(list);
                    }
                    else
                        state.destroy = destroy;
                }).catch(error => { nativeSorts.delete(list); console.error('Flex sorting failed', error); });
                if (!observing) {
                    observing = true;
                    window.ProcessWireAdminTheme.observe(() => nativeSorts.forEach((state, list) => {
                        if (!list.isConnected) {
                            state.destroy?.();
                            nativeSorts.delete(list);
                        }
                    }));
                }
                return;
            }
            const $ = window.jQuery;
            if (!$?.fn.sortable || $(list).hasClass('ui-sortable'))
                return;
            list.addEventListener('mousedown', event => {
                if (event.target instanceof Element && event.target.closest('.flex-rows-items') === list)
                    event.stopPropagation();
            });
            $(list).on('sortstart.flexRows sortstop.flexRows sortupdate.flexRows', event => {
                if (event.target === list)
                    event.stopPropagation();
            }).sortable({
                items: '> .flex-row[data-flex-row]', handle: '.flex-row-sort', cancel: ':input:not(.flex-row-sort), a',
                axis: 'y', distance: 5, tolerance: 'pointer', placeholder: 'flex-row-placeholder',
                start(_event, ui) { ui.placeholder.height(ui.item[0].getBoundingClientRect().height); start(ui.item[0]); },
                stop(_event, ui) { stop(ui.item[0]); }
            });
        },
        disabled(list, disabled) {
            const state = nativeSorts.get(list);
            if (state) {
                state.disabled = disabled;
                return;
            }
            const $ = window.jQuery;
            if ($?.fn.sortable && $(list).hasClass('ui-sortable'))
                $(list).sortable('option', 'disabled', disabled);
        }
    };
})(FieldtypeFlex || (FieldtypeFlex = {}));
var FieldtypeFlex;
(function (FieldtypeFlex) {
    const state = new WeakMap();
    const disabledBeforeRemoval = new WeakMap();
    const busyTrees = new WeakSet();
    function rowState(row) {
        let current = state.get(row);
        if (!current) {
            current = {};
            state.set(row, current);
        }
        return current;
    }
    function message(key, fallback) { return window.ProcessWire?.config.FieldtypeFlex?.[key] || fallback; }
    function errorMessage(error) { return error instanceof Error ? error.message : String(error); }
    function rows(set) { return FieldtypeFlex.all(set, ':scope > .flex-rows-items > .flex-row[data-flex-row]'); }
    function activeRows(set) { return rows(set).filter(row => !row.matches('.flex-row-remove-pending')); }
    function removalState(row, removed) {
        const body = FieldtypeFlex.one(row, ':scope > .flex-row-content');
        row.classList.toggle('flex-row-remove-pending', removed);
        FieldtypeFlex.one(row, ':scope > .flex-row-header').classList.toggle('ui-state-error', removed);
        FieldtypeFlex.one(row, ':scope > .flex-row-remove-value').value = removed ? '1' : '0';
        FieldtypeFlex.all(row, ':scope > .flex-row-remove-mirror').forEach(input => input.remove());
        if (removed) {
            FieldtypeFlex.serialize(body).forEach(([name, value]) => {
                const mirror = document.createElement('input');
                mirror.type = 'hidden';
                mirror.className = 'flex-row-remove-mirror';
                mirror.name = name;
                mirror.value = value;
                row.append(mirror);
            });
        }
        FieldtypeFlex.all(body, FieldtypeFlex.controlSelector).forEach(input => {
            if (removed) {
                if (!disabledBeforeRemoval.has(input))
                    disabledBeforeRemoval.set(input, input.disabled);
                input.disabled = true;
            }
            else if (disabledBeforeRemoval.has(input)) {
                input.disabled = disabledBeforeRemoval.get(input);
                disabledBeforeRemoval.delete(input);
            }
        });
        const button = FieldtypeFlex.one(row, ':scope > .flex-row-header [data-flex-row-action="remove"]');
        const label = FieldtypeFlex.attr(button, removed ? 'data-flex-undo-label' : 'data-flex-remove-label');
        button.setAttribute('aria-pressed', String(removed));
        button.setAttribute('aria-label', label);
        button.title = label;
    }
    function titleParts(format, values, number, type, path, cleanup = true) {
        const parts = [];
        format.split(/(\[[^\[\]]*\]|_\{[^{}]+\}_|\{[^{}]+\}|#n)/gi).filter(Boolean).forEach(function (part) {
            if (part[0] === '[' && part.slice(-1) === ']') {
                var details = titleParts(part.slice(1, -1), values, number, type, path, false);
                if (!details.length)
                    details.push({ text: '', badge: false, literal: false });
                details.forEach(function (detail) {
                    detail.muted = true;
                    parts.push(detail);
                });
                return;
            }
            var badge = part.slice(0, 2) === '_{' && part.slice(-2) === '}_';
            var token = badge ? part.slice(1, -1) : part, value = part, literal = false;
            if (token.toLowerCase() === '#n')
                value = '#' + number;
            else if (token[0] === '{' && token.slice(-1) === '}') {
                value = '';
                token.slice(1, -1).split('|').some(function (name) {
                    var candidate = name === '@type' ? type : (name === '@path' ? path : (Object.prototype.hasOwnProperty.call(values, name) ? values[name] : ''));
                    if (String(candidate).trim() === '')
                        return false;
                    value = String(candidate);
                    return true;
                });
            }
            else
                literal = true;
            parts.push({ text: value, badge: badge, literal: literal });
        });
        if (cleanup === false)
            return parts;
        parts.forEach(function (part, i) {
            if (!part.literal || !/^[\s›|/:;,–—-]+$/.test(part.text))
                return;
            var before = parts.some(function (other, j) { return j < i && other.text.trim() !== '' && (!other.literal || !/^[\s›|/:;,–—-]+$/.test(other.text)); });
            var after = parts.some(function (other, j) { return j > i && other.text.trim() !== '' && (!other.literal || !/^[\s›|/:;,–—-]+$/.test(other.text)); });
            if (!before || !after)
                part.text = '';
        });
        let separator = null, omitted = false;
        parts.forEach(function (part, i) {
            if (part.text === '') {
                if (!part.literal)
                    omitted = true;
                return;
            }
            if (part.literal && /^[\s›|/:;,–—-]+$/.test(part.text)) {
                if (separator !== null && omitted)
                    parts[separator].text = '';
                separator = i;
            }
            else
                separator = null;
            omitted = false;
        });
        return parts;
    }
    function rowTitle(row, index, path) {
        const format = FieldtypeFlex.attr(row, 'data-flex-label-format'), type = FieldtypeFlex.attr(row, 'data-flex-type-label');
        const values = rowState(row).labels ?? JSON.parse(FieldtypeFlex.attr(row, 'data-flex-label-values') || '{}');
        let parts = titleParts(format, values, index + 1, type, path);
        if (!parts.some(part => part.text.trim() !== ''))
            parts = [{ text: (format ? '' : type + ' ') + '#' + (index + 1), badge: false }];
        if (!format)
            parts.unshift({ text: type, badge: true }, { text: ' ', badge: false });
        return parts;
    }
    function contextPath(row) {
        const path = [];
        for (let parent = row.parentElement?.closest('.flex-row'); parent; parent = parent.parentElement?.closest('.flex-row'))
            path.unshift(FieldtypeFlex.attr(parent, 'data-flex-type-label'));
        return path.join(' › ');
    }
    function updateLabels(set) {
        rows(set).forEach((row, index) => {
            const title = FieldtypeFlex.one(row, ':scope > .flex-row-header .flex-row-title');
            title.replaceChildren();
            rowTitle(row, index, contextPath(row)).forEach(part => {
                if (!part.text)
                    return;
                if (part.badge || part.muted) {
                    const span = document.createElement('span');
                    span.classList.toggle('flex-row-type-badge', !!part.badge);
                    span.classList.toggle('flex-row-deemphasized', !!part.muted);
                    span.textContent = part.text;
                    title.append(span);
                }
                else
                    title.append(document.createTextNode(part.text));
            });
            FieldtypeFlex.all(row, ':scope > .flex-row-content .flex-rows').filter(set => set.closest('.flex-row') === row).forEach(updateLabels);
        });
    }
    function treeBusy(scope) { return busyTrees.has(FieldtypeFlex.closest(scope, '.InputfieldFlex')); }
    function setTreeBusy(scope, busy) {
        const tree = FieldtypeFlex.closest(scope, '.InputfieldFlex');
        if (busy)
            busyTrees.add(tree);
        else
            busyTrees.delete(tree);
        tree.setAttribute('aria-busy', String(busy));
        FieldtypeFlex.all(tree, '.flex-rows').forEach(updateActions);
        const form = FieldtypeFlex.closest(tree, 'form');
        if (!busy && !form.querySelector('.InputfieldFlex[aria-busy="true"], .flex-row[data-flex-load-failed="1"]'))
            FieldtypeFlex.all(form, ':scope > .flex-form-request-error').forEach(error => error.remove());
    }
    function updateActions(set) {
        const count = activeRows(set).length, min = Number(FieldtypeFlex.attr(set, 'data-flex-min')) || 0, max = Number(FieldtypeFlex.attr(set, 'data-flex-max')) || 0, busy = treeBusy(set);
        FieldtypeFlex.all(set, ':scope > .flex-row-add').forEach(button => button.disabled = busy || (max > 0 && count >= max));
        rows(set).forEach(row => {
            const header = FieldtypeFlex.one(row, ':scope > .flex-row-header');
            FieldtypeFlex.one(header, '.flex-row-toggle').disabled = busy;
            FieldtypeFlex.one(header, '[data-flex-row-action="duplicate"]').disabled = busy || (max > 0 && count >= max);
            FieldtypeFlex.one(header, '[data-flex-row-action="remove"]').disabled = busy || (!row.matches('.flex-row-remove-pending') && count <= min);
            FieldtypeFlex.one(header, '.flex-row-sort').disabled = busy || count < 2;
        });
        FieldtypeFlex.sortable.disabled(FieldtypeFlex.one(set, ':scope > .flex-rows-items'), busy || count < 2);
    }
    function order(set) {
        FieldtypeFlex.change(FieldtypeFlex.one(set, ':scope > .flex-rows-order'), rows(set).map(row => FieldtypeFlex.attr(row, 'data-flex-row')).join(','));
        FieldtypeFlex.closest(set, '.InputfieldFlex').classList.add('InputfieldStateChanged');
        updateLabels(set);
        updateActions(set);
    }
    function csrf() { return document.querySelector('#ProcessPageEdit input._post_token'); }
    function teardown(row) {
        if (!window.tinymce)
            return;
        FieldtypeFlex.all(row, 'textarea.InputfieldTinyMCEEditor').forEach(input => {
            const editor = window.tinymce.get(input.id);
            if (editor) {
                editor.save();
                editor.remove();
            }
            input.classList.remove('InputfieldTinyMCELoaded', 'InputfieldTinyMCELazy');
        });
    }
    function syncToggle(row) { FieldtypeFlex.one(row, ':scope > .flex-row-header .flex-row-toggle').setAttribute('aria-expanded', String(!row.matches('.InputfieldStateCollapsed'))); }
    function openRow(row, open) { FieldtypeFlex.classic.toggle(row, open); syncToggle(row); }
    function loadRow(row) {
        if (FieldtypeFlex.attr(row, 'data-flex-loaded') !== '0')
            return Promise.resolve(true);
        if (rowState(row).load)
            return rowState(row).load;
        const set = FieldtypeFlex.closest(row, '.flex-rows'), body = FieldtypeFlex.one(row, ':scope > .flex-row-content');
        if (treeBusy(set))
            return Promise.resolve(false);
        const operation = (async () => {
            setTreeBusy(set, true);
            FieldtypeFlex.one(body, '.flex-row-load-status').textContent = message('loading', 'Loading…');
            FieldtypeFlex.one(body, '.flex-row-retry').hidden = true;
            try {
                const data = new URLSearchParams({ flex_action: 'row-render', flex_set: FieldtypeFlex.attr(set, 'data-flex-set'), flex_source: FieldtypeFlex.attr(row, 'data-flex-row'), flex_complete: FieldtypeFlex.attr(set, 'data-flex-draft') });
                const token = csrf();
                if (token)
                    data.set(token.name, token.value);
                const response = await fetch(FieldtypeFlex.attr(set, 'data-flex-page-url'), { method: 'POST', credentials: 'same-origin', headers: requestHeaders(set), body: data });
                const result = await response.json();
                if (!response.ok || result.error)
                    throw new Error(result.message || message('unableToLoad', 'Unable to load item'));
                if (!row.isConnected)
                    return false;
                const fresh = FieldtypeFlex.parseHTML(result.html), content = fresh.querySelector(':scope > .flex-row-content');
                if (FieldtypeFlex.attr(fresh, 'data-flex-loaded') !== '1' || !content)
                    throw new Error(message('unableToLoad', 'Unable to load item'));
                body.replaceChildren(...content.childNodes);
                await FieldtypeFlex.activateScripts(body, response);
                row.setAttribute('data-flex-loaded', '1');
                row.removeAttribute('data-flex-load-failed');
                await FieldtypeFlex.classic.setup(row);
                if (row.matches('.flex-row-remove-pending'))
                    removalState(row, true);
                return true;
            }
            catch (error) {
                row.setAttribute('data-flex-load-failed', '1');
                FieldtypeFlex.one(body, '.flex-row-load-status').textContent = error instanceof SyntaxError ? message('unableToLoad', 'Unable to load item') : errorMessage(error);
                FieldtypeFlex.one(body, '.flex-row-retry').hidden = false;
                return false;
            }
            finally {
                delete rowState(row).load;
                setTreeBusy(set, false);
            }
        })();
        rowState(row).load = operation;
        return operation;
    }
    function requestHeaders(set) {
        return { 'X-Requested-With': 'XMLHttpRequest', 'X-FIELDNAME': FieldtypeFlex.attr(set, 'data-flex-field'), 'X-FLEX-DRAFT': FieldtypeFlex.attr(set, 'data-flex-draft') };
    }
    function moved(set, row) {
        FieldtypeFlex.one(set, ':scope > .flex-rows-status').textContent = message('itemMoved', 'Item moved to position {position} of {count}').replace('{position}', String(rows(set).indexOf(row) + 1)).replace('{count}', String(rows(set).length));
    }
    function initRows(context) {
        const sets = FieldtypeFlex.all(context, '.flex-rows');
        if (context instanceof HTMLElement && context.matches('.flex-rows'))
            sets.unshift(context);
        sets.forEach(set => {
            rows(set).forEach(row => {
                if (row.matches('.flex-row-remove-pending') && !rowState(row).removalReady) {
                    removalState(row, true);
                    rowState(row).removalReady = true;
                }
                if (!row.matches('.flex-row-remove-pending') && row.querySelector('.InputfieldStateError'))
                    openRow(row, true);
                syncToggle(row);
            });
            FieldtypeFlex.sortable.init(FieldtypeFlex.one(set, ':scope > .flex-rows-items'), teardown, async (row) => { order(set); await FieldtypeFlex.classic.setup(row); moved(set, row); });
            updateLabels(set);
            updateActions(set);
        });
    }
    function formError(form, text, pending) {
        let error = form.querySelector(':scope > .flex-form-limit-error');
        if (!error) {
            error = document.createElement('p');
            error.className = 'flex-form-limit-error notice-error';
            error.setAttribute('role', 'alert');
            error.tabIndex = -1;
            form.prepend(error);
        }
        error.classList.toggle('flex-form-request-error', pending);
        error.textContent = text;
        error.focus();
    }
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-flex-max-input-vars]'))
            return;
        const pending = FieldtypeFlex.all(form, '.InputfieldFlex[aria-busy="true"], .flex-row[data-flex-load-failed="1"]').find(item => !item.closest('.flex-row-remove-pending'));
        if (pending) {
            event.preventDefault();
            event.stopImmediatePropagation();
            formError(form, pending.matches('.flex-row') ? message('unableToLoad', 'Unable to load item') : message('requestPending', 'Wait for the current request to finish before saving.'), true);
            return;
        }
        const max = Number(FieldtypeFlex.attr(form, 'data-flex-max-input-vars'));
        if (max <= 0)
            return;
        const submitter = event.submitter;
        let count = submitter?.name && !submitter.disabled ? 1 : 0;
        for (const [, value] of new FormData(form))
            if (typeof value === 'string')
                count++;
        if (count <= max)
            return;
        event.preventDefault();
        event.stopImmediatePropagation();
        formError(form, message('inputVarsExceeded', 'This form contains {count} input values, exceeding PHP max_input_vars={max}.').replace('{count}', String(count)).replace('{max}', String(max)), false);
    }, true);
    FieldtypeFlex.classic.preserveSelectorPaths();
    FieldtypeFlex.ready(() => { FieldtypeFlex.classic.preserveSelectorPaths(); initRows(document); });
    document.addEventListener('pw-runtime-ready', () => { initRows(document); });
    FieldtypeFlex.classic.events(scope => { initRows(scope); }, (row, open) => { syncToggle(row); if (open)
        void loadRow(row); }, scope => {
        FieldtypeFlex.all(scope, 'textarea').forEach(input => {
            const editor = window.tinymce?.get(input.id);
            if (editor) {
                editor.save();
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
    }, (draft, path) => { void refreshMedia(draft, path); });
    FieldtypeFlex.delegate('click', '.flex-row-retry', (event, button) => { event.preventDefault(); void loadRow(FieldtypeFlex.closest(button, '.flex-row')); });
    FieldtypeFlex.delegate('click', '.flex-row-toggle', (event, button) => {
        event.preventDefault();
        event.stopPropagation();
        const row = FieldtypeFlex.closest(button, '.flex-row');
        openRow(row, row.matches('.InputfieldStateCollapsed'));
    });
    FieldtypeFlex.delegate('keydown', '.flex-row-sort', async (event, button) => {
        if (!['ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key))
            return;
        event.preventDefault();
        event.stopPropagation();
        const row = FieldtypeFlex.closest(button, '.flex-row'), set = FieldtypeFlex.closest(row, '.flex-rows');
        if (treeBusy(set))
            return;
        let target = event.key === 'ArrowUp' ? row.previousElementSibling : row.nextElementSibling;
        if (event.key === 'Home')
            target = rows(set)[0];
        if (event.key === 'End')
            target = rows(set).slice(-1)[0];
        if (!target?.matches('.flex-row') || target === row)
            return;
        teardown(row);
        if (event.key === 'ArrowUp' || event.key === 'Home')
            target.before(row);
        else
            target.after(row);
        order(set);
        await FieldtypeFlex.classic.setup(row);
        FieldtypeFlex.one(row, ':scope > .flex-row-header .flex-row-sort').focus();
        moved(set, row);
    });
    function labelChanged(input) {
        const property = input.closest('[data-flex-label-property]'), row = property?.closest('.flex-row');
        if (!property || !row)
            return;
        const kind = FieldtypeFlex.attr(property, 'data-flex-label-kind');
        if (kind === 'file' || kind === 'image' || (kind === 'page' && !(input instanceof HTMLSelectElement)))
            return;
        let label = FieldtypeFlex.value(input);
        if (input instanceof HTMLSelectElement)
            label = Array.from(input.selectedOptions, option => option.textContent).join(', ');
        else if (input.matches('[type="checkbox"], [type="radio"]')) {
            const options = JSON.parse(FieldtypeFlex.attr(property, 'data-flex-label-options') || '{}');
            label = FieldtypeFlex.all(property, ':checked').map(option => Object.prototype.hasOwnProperty.call(options, option.value) ? options[option.value] : option.value).join(', ');
        }
        else if (input.matches('[type="file"], [type="hidden"], button'))
            return;
        const text = document.createElement('textarea');
        FieldtypeFlex.setHTML(text, String(label || '').replace(/<[^>]*>/g, '').replace(/</g, '&lt;').replace(/>/g, '&gt;'));
        const current = rowState(row);
        current.labels ?? (current.labels = JSON.parse(FieldtypeFlex.attr(row, 'data-flex-label-values') || '{}'));
        current.labels[FieldtypeFlex.attr(property, 'data-flex-label-property')] = text.value.replace(/\s+/g, ' ').trim().slice(0, 160);
        updateLabels(FieldtypeFlex.closest(row, '.flex-rows'));
    }
    ['input', 'change'].forEach(name => document.addEventListener(name, event => {
        if (event.target instanceof HTMLInputElement || event.target instanceof HTMLSelectElement || event.target instanceof HTMLTextAreaElement || event.target instanceof HTMLButtonElement)
            labelChanged(event.target);
    }));
    document.addEventListener('invalid', event => {
        if (!(event.target instanceof Element))
            return;
        for (let row = event.target.closest('.flex-row'); row; row = row.parentElement?.closest('.flex-row') || null)
            openRow(row, true);
    }, true);
    FieldtypeFlex.delegate('dblclick', '[data-flex-row-action="remove"]', (event, element) => {
        event.preventDefault();
        event.stopPropagation();
        const button = element, set = FieldtypeFlex.closest(button, '.flex-rows');
        if (button.disabled || treeBusy(set))
            return;
        const undo = FieldtypeFlex.closest(button, '.flex-row').matches('.flex-row-remove-pending');
        const apply = () => rows(set).forEach(row => { if (row.matches('.flex-row-remove-pending') === undo)
            FieldtypeFlex.one(row, ':scope > .flex-row-header [data-flex-row-action="remove"]').click(); });
        if (undo)
            apply();
        else
            FieldtypeFlex.classic.confirm(message('removeAll', 'Remove all items?'), apply);
    });
    FieldtypeFlex.delegate('click', '.flex-row-add, .flex-row-action', (event, button) => { void rowAction(event, button); });
    async function rowAction(event, button) {
        event.preventDefault();
        event.stopPropagation();
        const set = FieldtypeFlex.closest(button, '.flex-rows'), row = button.closest('.flex-row');
        if (button.disabled || treeBusy(set))
            return;
        const action = button.matches('.flex-row-add') ? 'add' : FieldtypeFlex.attr(button, 'data-flex-row-action');
        const error = FieldtypeFlex.one(set, ':scope > .flex-rows-error');
        error.textContent = '';
        if (action === 'visibility' && row) {
            const hidden = !row.matches('.flex-row-hidden');
            row.classList.toggle('flex-row-hidden', hidden);
            FieldtypeFlex.change(FieldtypeFlex.one(row, ':scope > .flex-row-hidden-value'), hidden ? '1' : '0');
            const label = FieldtypeFlex.attr(button, hidden ? 'data-flex-show-label' : 'data-flex-hide-label');
            button.setAttribute('aria-pressed', String(hidden));
            button.setAttribute('aria-label', label);
            button.title = label;
            const icon = FieldtypeFlex.one(button, 'i');
            icon.classList.toggle('fa-eye', !hidden);
            icon.classList.toggle('fa-eye-slash', hidden);
            return;
        }
        if (action === 'remove' && row) {
            const removed = !row.matches('.flex-row-remove-pending');
            window.tinymce?.triggerSave();
            if (removed)
                openRow(row, false);
            removalState(row, removed);
            rowState(row).removalReady = true;
            order(set);
            button.focus();
            return;
        }
        if (action === 'duplicate' && row && FieldtypeFlex.attr(row, 'data-flex-loaded') === '0' && !(await loadRow(row)))
            return;
        setTreeBusy(set, true);
        try {
            window.tinymce?.triggerSave();
            const data = new URLSearchParams({ flex_action: action, flex_set: FieldtypeFlex.attr(set, 'data-flex-set'), flex_order: activeRows(set).map(row => FieldtypeFlex.attr(row, 'data-flex-row')).join(',') });
            const orders = {};
            FieldtypeFlex.all(FieldtypeFlex.closest(set, '.InputfieldFlex'), '.flex-rows').forEach(current => {
                if (!current.closest('.flex-row-remove-pending'))
                    orders[FieldtypeFlex.attr(current, 'data-flex-set')] = activeRows(current).map(row => FieldtypeFlex.attr(row, 'data-flex-row'));
            });
            data.set('flex_orders', JSON.stringify(orders));
            if (action === 'add')
                data.set('flex_type', FieldtypeFlex.attr(set, 'data-flex-type') || FieldtypeFlex.one(set, ':scope > .flex-row-type-control .flex-row-type').value);
            else if (action === 'duplicate' && row) {
                data.set('flex_source', FieldtypeFlex.attr(row, 'data-flex-row'));
                // A Repeater's owner URL must not be overridden by the main PageEdit id.
                FieldtypeFlex.serialize(FieldtypeFlex.closest(row, 'form')).forEach(([name, value]) => { if (name !== 'id')
                    data.append(name, value); });
            }
            const token = csrf();
            if (token)
                data.set(token.name, token.value);
            data.set('flex_complete', FieldtypeFlex.attr(set, 'data-flex-draft'));
            const response = await fetch(FieldtypeFlex.attr(set, 'data-flex-page-url'), { method: 'POST', credentials: 'same-origin', headers: requestHeaders(set), body: data });
            const result = await response.json();
            if (!response.ok || result.error)
                throw new Error(result.message || message('unableToAdd', 'Unable to add item'));
            if (!set.isConnected)
                return;
            const fresh = FieldtypeFlex.parseHTML(result.html);
            if (action === 'duplicate' && row)
                row.after(fresh);
            else
                FieldtypeFlex.one(set, ':scope > .flex-rows-items').append(fresh);
            await FieldtypeFlex.activateScripts(fresh, response);
            order(set);
            await FieldtypeFlex.classic.setup(fresh);
            initRows(set);
            FieldtypeFlex.one(fresh, ':scope > .flex-row-header .flex-row-toggle').focus();
            FieldtypeFlex.classic.added(fresh);
        }
        catch (problem) {
            error.textContent = errorMessage(problem);
        }
        finally {
            setTreeBusy(set, false);
        }
    }
    function metadataProperty(element) { return element.closest('[data-flex-metadata-property]')?.getAttribute('data-flex-metadata-property') || ''; }
    function nativeMetadataName(name) { return (name.match(/^(description[0-9]*|tags)_/) || [])[1] || ''; }
    function mediaMetadata(scope) {
        FieldtypeFlex.classic.saveReady(scope);
        return {
            inline: FieldtypeFlex.all(scope, '.InputfieldTinyMCEEditor.InputfieldTinyMCEInline').map(editor => ({ id: editor.id, suffix: editor.id.split('__').slice(2).join('__'), html: editor.innerHTML, loaded: editor.matches('.InputfieldTinyMCELoaded'), property: metadataProperty(editor) })),
            controls: FieldtypeFlex.all(scope, FieldtypeFlex.controlSelector).filter(input => input.name && (/^(description[0-9]*|tags|focus)_/.test(input.name) || input.closest('[data-flex-metadata]'))).map(input => ({
                name: input.name, value: FieldtypeFlex.value(input), type: input.type, checked: input instanceof HTMLInputElement && input.checked,
                property: metadataProperty(input), suffix: input.name.split('__').slice(2).join('__'), native: nativeMetadataName(input.name)
            })),
            errors: FieldtypeFlex.all(scope, '[data-flex-metadata-property].InputfieldStateError').map(wrapper => ({ id: wrapper.id, property: FieldtypeFlex.attr(wrapper, 'data-flex-metadata-property'), markup: FieldtypeFlex.all(wrapper, ':scope > .InputfieldContent > .InputfieldError').map(error => error.outerHTML).join('') }))
        };
    }
    function restoreMediaMetadata(scope, snapshot, replacement) {
        snapshot.controls.forEach(item => {
            FieldtypeFlex.all(scope, FieldtypeFlex.controlSelector).filter(input => {
                let match = input.name === item.name;
                if (replacement && item.property)
                    match = metadataProperty(input) === item.property && input.name.split('__').slice(2).join('__') === item.suffix;
                else if (replacement && item.native)
                    match = nativeMetadataName(input.name) === item.native;
                return match && (!/^(checkbox|radio)$/.test(item.type) || input.value === item.value);
            }).forEach(input => {
                if (input instanceof HTMLInputElement && /^(checkbox|radio)$/.test(item.type))
                    input.checked = item.checked;
                else
                    FieldtypeFlex.setValue(input, item.value);
            });
        });
        snapshot.errors.forEach(item => {
            FieldtypeFlex.all(scope, '[data-flex-metadata-property]').filter(wrapper => replacement ? FieldtypeFlex.attr(wrapper, 'data-flex-metadata-property') === item.property : wrapper.id === item.id).forEach(wrapper => {
                wrapper.classList.add('InputfieldStateError', 'ui-state-error');
                if (!wrapper.querySelector('.InputfieldError'))
                    FieldtypeFlex.insertHTML(FieldtypeFlex.one(wrapper, ':scope > .InputfieldContent'), 'afterbegin', item.markup);
            });
        });
        snapshot.inline.forEach(item => {
            FieldtypeFlex.all(scope, '.InputfieldTinyMCEEditor.InputfieldTinyMCEInline').filter(editor => replacement ? metadataProperty(editor) === item.property && editor.id.split('__').slice(2).join('__') === item.suffix : editor.id === item.id).forEach(editor => {
                FieldtypeFlex.setHTML(editor, item.html);
                // Recreate the native hidden transport even if this editor is never focused.
                if (item.loaded)
                    FieldtypeFlex.classic.initInline(editor);
            });
        });
    }
    async function upload(input, files) {
        const upload = FieldtypeFlex.closest(input, '[data-flex-upload="1"]'), field = FieldtypeFlex.closest(upload, '.Inputfield'), token = csrf();
        if (!files.length)
            return;
        if (treeBusy(upload)) {
            input.value = '';
            return;
        }
        setTreeBusy(upload, true);
        input.disabled = true;
        const status = document.createElement('p');
        status.className = 'flex-upload-status';
        status.setAttribute('role', 'status');
        upload.append(status);
        try {
            for (const file of files) {
                status.textContent = message('uploading', 'Uploading {name}…').replace('{name}', file.name);
                const headers = { 'X-Requested-With': 'XMLHttpRequest', 'X-FIELDNAME': FieldtypeFlex.attr(upload, 'data-fieldname'), 'X-FLEX-DRAFT': FieldtypeFlex.attr(upload, 'data-flex-draft'), 'X-FLEX-PATH': FieldtypeFlex.attr(upload, 'data-flex-path'), 'X-FILENAME': encodeURIComponent(file.name), 'Content-Type': 'application/octet-stream' };
                if (token)
                    headers['X-' + token.name] = token.value;
                const response = await fetch(FieldtypeFlex.attr(upload, 'data-posturl'), { method: 'POST', credentials: 'same-origin', headers, body: file });
                const result = await response.json();
                if (!response.ok || (!Array.isArray(result) && result.error))
                    throw new Error((!Array.isArray(result) && result.message) || message('unableToUpload', 'Unable to upload file'));
                if (!Array.isArray(result))
                    throw new Error(message('unableToUpload', 'Unable to upload file'));
                for (const item of result) {
                    if (item.error)
                        throw new Error(item.message || message('unableToUpload', 'Unable to upload file'));
                    let list = field.querySelector('.InputfieldFileList') || field.querySelector('.InputfieldImageList');
                    if (!list) {
                        list = document.createElement('ul');
                        list.className = field.matches('.InputfieldImage') ? 'InputfieldImageList gridImages ui-helper-clearfix' : 'InputfieldFileList ui-helper-clearfix';
                        upload.before(list);
                        FieldtypeFlex.all(field, '.InputfieldFileListPlaceholder').forEach(item => item.remove());
                    }
                    const replacement = Number(field.querySelector('.InputfieldFileMaxFiles, .InputfieldImageMaxFiles')?.value) === 1;
                    const metadata = replacement ? mediaMetadata(list) : null;
                    if (replacement) {
                        FieldtypeFlex.classic.destroyEditors(list);
                        FieldtypeFlex.all(list, ':scope > li').forEach(item => item.remove());
                    }
                    const fresh = FieldtypeFlex.parseHTML(item.markup);
                    list.append(fresh);
                    await FieldtypeFlex.activateScripts(fresh, response);
                    if (metadata)
                        restoreMediaMetadata(fresh, metadata, true);
                }
                field.classList.remove('InputfieldFileEmpty');
                field.classList.add('InputfieldStateChanged');
                await FieldtypeFlex.classic.setup(field);
            }
            status.remove();
        }
        catch (error) {
            status.setAttribute('role', 'alert');
            status.textContent = errorMessage(error);
        }
        finally {
            input.value = '';
            input.disabled = false;
            setTreeBusy(upload, false);
        }
    }
    // Capture only the Flex transport before native File/Image listeners see it.
    document.addEventListener('change', event => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || input.type !== 'file' || !input.closest('[data-flex-upload="1"]'))
            return;
        event.stopImmediatePropagation();
        void upload(input, Array.from(input.files || []));
    }, true);
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(name => document.addEventListener(name, rawEvent => {
        const event = rawEvent;
        if (!(event.target instanceof Element))
            return;
        const field = event.target.closest('.Inputfield');
        if (!field || !event.dataTransfer || !Array.from(event.dataTransfer.types).includes('Files'))
            return;
        const input = field.querySelector('[data-flex-upload="1"] input[type="file"]');
        if (!input || input.closest('.Inputfield') !== field)
            return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (name === 'dragleave' && event.relatedTarget instanceof Node && field.contains(event.relatedTarget))
            return;
        const active = name === 'dragenter' || name === 'dragover';
        field.classList.toggle('pw-drag-in-file', active);
        FieldtypeFlex.all(field, ':scope > .InputfieldContent').forEach(content => content.classList.toggle('ui-state-hover', active));
        if (name === 'drop')
            void upload(input, Array.from(event.dataTransfer.files));
    }, true));
    async function refreshMedia(draft, path) {
        const upload = FieldtypeFlex.all(document, '[data-flex-upload="1"]').find(upload => FieldtypeFlex.attr(upload, 'data-flex-draft') === draft && FieldtypeFlex.attr(upload, 'data-flex-path') === path);
        if (!upload)
            return;
        const field = FieldtypeFlex.closest(upload, '.Inputfield'), token = csrf();
        try {
            const data = new URLSearchParams({ flex_action: 'media-render', flex_path: path, flex_complete: draft });
            if (token)
                data.set(token.name, token.value);
            const response = await fetch(FieldtypeFlex.attr(upload, 'data-posturl'), { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-FIELDNAME': FieldtypeFlex.attr(upload, 'data-fieldname'), 'X-FLEX-DRAFT': draft }, body: data });
            const result = await response.json();
            if (!response.ok || result.error)
                throw new Error(result.message || message('unableToRefreshImage', 'Unable to refresh image'));
            const metadata = mediaMetadata(field);
            FieldtypeFlex.classic.destroyEditors(field);
            const content = FieldtypeFlex.one(field, ':scope > .InputfieldContent');
            FieldtypeFlex.setHTML(content, result.html);
            await FieldtypeFlex.activateScripts(content, response);
            restoreMediaMetadata(field, metadata, false);
            await FieldtypeFlex.classic.setup(field);
            field.classList.add('InputfieldStateChanged');
            FieldtypeFlex.classic.closeDialog();
        }
        catch (error) {
            const status = document.createElement('p');
            status.setAttribute('role', 'alert');
            status.className = 'flex-upload-status';
            status.textContent = errorMessage(error);
            field.append(status);
        }
    }
})(FieldtypeFlex || (FieldtypeFlex = {}));
