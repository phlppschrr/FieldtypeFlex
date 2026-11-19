'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM, VirtualConsole} = require('jsdom');
const source = fs.readFileSync(path.join(__dirname, '../../FieldtypeFlex.js'), 'utf8');
const maintenance = fs.readFileSync(path.join(__dirname, '../../FlexMaintenance.js'), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));
const action = name => `<button type="button" class="flex-row-action" data-flex-row-action="${name}" data-flex-remove-label="Remove" data-flex-undo-label="Undo" data-flex-show-label="Show" data-flex-hide-label="Hide"><i></i></button>`;
const rowCheckbox = '<input type="checkbox" class="flex-row-select InputfieldIgnoreChanges" aria-label="Select row" hidden>';
const ref = (ref, classes) => ({choices: [{ref}], ...(classes ? {classes} : {})});
function row(id, text = id, extra = '', title = {}) {
    const data = {nodes: [ref('heading')], values: {heading: text}, sources: {heading: {names: [`heading_${id}`], kind: 'text'}}, custom: {}, override: null, ...title};
    const encoded = JSON.stringify(data).replaceAll('&', '&amp;').replaceAll("'", '&#39;');
    return `<li class="Inputfield flex-row" data-flex-row="${id}" data-flex-type-label="Text" data-flex-title='${encoded}' ${title.refresh ? 'data-flex-title-refresh' : ''}>
    <div class="flex-row-header">${rowCheckbox}<button type="button" class="flex-row-toggle"></button><span class="flex-row-title"></span><button type="button" class="flex-row-sort"></button>${action('duplicate')}${action('remove')}${action('visibility')}</div>
    <input class="flex-row-remove-value" name="remove_${id}" value="0"><input class="flex-row-hidden-value" name="hidden_${id}" value="0">
    <div class="flex-row-content"><div class="Inputfield"><input name="heading_${id}" value="${text}"></div>${extra}</div></li>`;
}
function set(content = row('a') + row('b'), attributes = '') {
    return `<div class="flex-rows" data-flex-set="blocks" data-flex-draft="draft" data-flex-field="content" data-flex-page-url="/edit/?id=42" data-flex-type="text" ${attributes}><ul class="flex-rows-items">${content}</ul><input class="flex-rows-order" name="order"><div class="flex-rows-footer"><button type="button" class="flex-row-add">Add</button></div><p class="flex-rows-error"></p><p class="flex-rows-status"></p></div>`;
}
function tableRow(id, text = id) {
    return `<tr class="flex-row" data-flex-row="${id}" data-flex-type="row"><td class="flex-table-sort"><button type="button" class="flex-row-sort">Sort</button>${rowCheckbox}</td><td class="flex-table-cell"><ul class="Inputfields"><li class="Inputfield"><input name="cell_${id}" value="${text}" required></li></ul></td><td class="flex-row-header flex-table-actions"><details class="flex-table-more"><summary>More</summary><div class="flex-table-secondary flex-clipboard-actions"><button type="button" data-flex-row-action="duplicate" class="flex-row-action"><i class="fa-copy"></i><span class="flex-row-action-label">Duplicate</span></button><button type="button" data-flex-row-action="visibility" class="flex-row-action" data-flex-hide-label="Hide" data-flex-show-label="Show"><i class="fa-eye"></i><span class="flex-row-action-label">Hide</span></button></div></details><button type="button" data-flex-row-action="remove" class="flex-row-action" data-flex-remove-label="Remove" data-flex-undo-label="Undo">Remove</button><span class="flex-row-transport"><input class="flex-row-remove-value" value="0"><input class="flex-row-hidden-value" value="0"></span></td></tr>`;
}
function tableSet(content = tableRow('a') + tableRow('b')) {
    return set('').replace('class="flex-rows"', 'class="flex-rows flex-table"').replace('<ul class="flex-rows-items"></ul>', `<div class="flex-table-scroll"><table><thead><tr><th>Sort</th><th>Cell</th><th>Actions</th></tr></thead><tbody class="flex-rows-items">${content}</tbody></table></div>`);
}
async function harness(content = set(), options = {}) {
    const errors = [];
    const console = new VirtualConsole(); console.on('jsdomError', error => errors.push(error));
    const dom = new JSDOM(`<form id="ProcessPageEdit" data-flex-max-input-vars="1000"><input name="id" value="7"><input class="_post_token" name="TOKEN" value="secret"><div class="InputfieldFlex">${content}</div><button type="submit" name="submit_save">Save</button></form>`, {runScripts: 'dangerously', url: 'https://fixture.test/edit/', virtualConsole: console});
    const {window} = dom;
    const calls = [];
    window.fetch = async (url, init) => { calls.push({url, ...init}); return options.response ? options.response(url, init) : {ok: true, json: async () => ({html: row('new')})}; };
    if(options.before) options.before(window);
    const end = source.lastIndexOf('})(FieldtypeFlex || (FieldtypeFlex = {}));');
    window.eval(source.slice(0, end) + 'window.__flex = FieldtypeFlex; FieldtypeFlex.test = {initRows, rowState, removalState, mediaMetadata, restoreMediaMetadata, upload, refreshMedia, loadRow, treeBusy, setTreeBusy, teardown};\n' + source.slice(end));
    await tick();
    return {dom, window, document: window.document, flex: window.__flex, calls, errors, close() { assert.deepEqual(errors, [], 'no uncaught runtime errors'); window.close(); }};
}
test('native initialization, live labels, safe text and keyboard order without jQuery', async () => {
    const h = await harness(); const {document: d, window: w} = h;
    assert.equal(d.querySelector('.flex-row-title').textContent, 'a');
    const input = d.querySelector('[name="heading_a"]'); input.value = '<b>Changed</b> &amp; safe'; input.dispatchEvent(new w.Event('input', {bubbles:true}));
    assert.equal(d.querySelector('.flex-row-title').textContent, 'Changed & safe');
    const handle = d.querySelector('.flex-row-sort'); handle.dispatchEvent(new w.KeyboardEvent('keydown', {key:'End', bubbles:true})); await tick();
    assert.equal(d.querySelector('.flex-rows-order').value, 'b,a');
    assert.equal(d.activeElement, handle); assert.match(d.querySelector('.flex-rows-status').textContent, /2 of 2/);
    assert(d.querySelector('.InputfieldFlex').classList.contains('InputfieldStateChanged')); h.close();
});
test('table rows share keyboard order, visibility, removal and undo without card headers', async () => {
    const h = await harness(tableSet()); const d = h.document, a = d.querySelector('[data-flex-row="a"]');
    const handle = a.querySelector('.flex-row-sort');
    assert.equal(handle.parentElement, a.firstElementChild);
    handle.dispatchEvent(new h.window.KeyboardEvent('keydown', {key:'End', bubbles:true})); await tick();
    assert.equal(d.querySelector('.flex-rows-order').value, 'b,a'); assert.equal(d.activeElement, handle);
    const more = a.querySelector('.flex-table-more'), trigger = more.querySelector('summary');
    assert.equal(more.open, false);
    trigger.click();
    assert.equal(more.open, true);
    const visibility = a.querySelector('[data-flex-row-action="visibility"]');
    visibility.click();
    assert.equal(a.querySelector('.flex-row-hidden-value').value, '1');
    assert.equal(visibility.querySelector('.flex-row-action-label').textContent, 'Show');
    assert(visibility.querySelector('i').classList.contains('fa-eye-slash'));
    assert.equal(more.open, false); assert.equal(d.activeElement, trigger);
    visibility.click();
    assert.equal(visibility.querySelector('.flex-row-action-label').textContent, 'Hide');
    assert(visibility.querySelector('i').classList.contains('fa-eye'));
    trigger.click();
    more.querySelector('button').dispatchEvent(new h.window.KeyboardEvent('keydown', {key:'Escape', bubbles:true}));
    assert.equal(more.open, false); assert.equal(d.activeElement, trigger);
    trigger.click();
    d.querySelector('[name="cell_b"]').focus();
    assert.equal(more.open, false);
    a.querySelector('[data-flex-row-action="remove"]').click();
    assert.equal(handle.disabled, true);
    assert(a.querySelector('[name="cell_a"]').disabled); assert.equal(a.querySelector('.flex-row-remove-mirror').value, 'a');
    assert.equal(a.querySelectorAll(':scope > input').length, 0, 'transport remains valid table markup');
    a.querySelector('[data-flex-row-action="remove"]').click(); assert(!a.querySelector('[name="cell_a"]').disabled);
    assert.equal(handle.disabled, false); h.close();
});
test('the table action overlay stays anchored and inside the viewport', async () => {
    const h = await harness(tableSet(tableRow('a')));
    const menu = h.document.querySelector('.flex-table-more');
    const trigger = menu.querySelector('summary');
    const panel = menu.querySelector('.flex-table-secondary');
    let position = {left: 400, right: 430, top: 300};
    trigger.getBoundingClientRect = () => position;
    Object.defineProperties(panel, {offsetWidth: {value: 200}, offsetHeight: {value: 160}});
    trigger.click();
    menu.dispatchEvent(new h.window.Event('toggle'));
    assert.equal(panel.style.left, '200px');
    assert.equal(panel.style.top, '300px');
    position = {left: 80, right: 110, top: h.window.innerHeight - 40};
    menu.dispatchEvent(new h.window.Event('scroll'));
    assert.equal(panel.style.left, '8px');
    assert.equal(panel.style.top, h.window.innerHeight - 168 + 'px');
    h.close();
});
test('AJAX table duplication includes unsaved cells and inserts a native table row', async () => {
    const h = await harness(tableSet(tableRow('a')), {response:async () => ({ok:true,json:async () => ({html:tableRow('copy','Copied')})})});
    const d = h.document; d.querySelector('[name="cell_a"]').value = 'Unsaved cell';
    d.querySelector('.flex-table-more summary').click();
    d.querySelector('[data-flex-row-action="duplicate"]').click(); await tick();
    assert.equal(h.calls[0].body.get('cell_a'), 'Unsaved cell'); assert.equal(d.querySelector('tbody').children.length, 2);
    assert.equal(d.querySelector('tbody').lastElementChild.tagName, 'TR'); assert.equal(d.querySelector('.flex-rows-order').value, 'a,copy');
    assert.equal(d.activeElement, d.querySelector('[data-flex-row="copy"] summary'));
    h.close();
});
test('table detail editor keeps thumbnails and unsaved form values through focus, duplication and undo', async () => {
    const content = tableRow('a').replace('<ul class="Inputfields">', `<button class="flex-table-preview" type="button" aria-label="Image" data-flex-preview="image" hidden></button><div class="flex-table-editor"><button class="flex-table-editor-close" type="button" hidden>Done</button><ul class="InputfieldImageList">
        <li class="gridImage"><img src="/first.jpg"></li><li class="gridImage"><img src="/second.jpg"></li>
        <li class="gridImage"><img src="/removed.jpg"><input type="checkbox" class="gridImage__deletebox" checked></li>
        </ul><ul class="Inputfields">`).replace('</li></ul></td>', '</li></ul></div></td>');
    const h = await harness(tableSet(content), {response:async () => ({ok:true,json:async () => ({html:tableRow('copy')})})});
    const d = h.document, row = d.querySelector('[data-flex-row="a"]');
    d.querySelector('table').classList.add('flex-table-grid');
    const input = row.querySelector('[name="cell_a"]'), button = row.querySelector('.flex-table-preview');
    assert.equal(button.hidden, false);
    assert.equal(button.textContent, '');
    assert.deepEqual([...button.querySelectorAll('img')].map(image => new URL(image.src).pathname), ['/first.jpg', '/second.jpg']);
    button.click();
    assert.equal(row.nextElementSibling.cells[0].colSpan, row.cells.length);
    assert.equal(d.querySelector('.flex-table-detail [name="cell_a"]'), input);
    assert.equal(input.form.id, 'ProcessPageEdit');
    input.value = 'Pending';
    row.querySelector('[data-flex-row-action="duplicate"]').click();
    await tick();
    assert.equal(h.calls[0].body.get('cell_a'), 'Pending');
    assert.equal(d.querySelector('.flex-table-detail'), null);
    assert.equal(d.querySelector('.flex-rows-order').value, 'a,copy');
    button.click();
    const remove = row.querySelector('[data-flex-row-action="remove"]');
    remove.click();
    assert.equal(d.querySelector('.flex-table-detail'), null);
    assert.equal(row.querySelector('.flex-row-remove-mirror').value, 'Pending');
    remove.click();
    button.click();
    d.querySelector('.flex-table-editor-close').click();
    assert.equal(row.querySelector('[name="cell_a"]'), input);
    assert.equal(input.value, 'Pending');
    assert.equal(d.activeElement, button);
    button.click();
    input.focus();
    assert.notEqual(d.querySelector('.flex-table-detail'), null);
    const other = d.querySelector('[name="cell_copy"]');
    other.focus();
    assert.equal(d.querySelector('.flex-table-detail'), null);
    assert.equal(d.activeElement, other);
    button.click();
    const sort = row.querySelector('.flex-row-sort');
    sort.dispatchEvent(new h.window.KeyboardEvent('keydown', {key:'ArrowDown', bubbles: true}));
    await tick();
    assert.equal(d.querySelector('.flex-table-detail'), null);
    assert.equal(d.querySelector('.flex-rows-order').value, 'copy,a');
    h.close();
});
test('iframe table editors keep pending text and clear the native loaded marker on close', async () => {
    const content = tableRow('a').replace('<ul class="Inputfields">', `<button class="flex-table-preview" type="button" data-flex-preview="richtext" hidden><span></span></button>
        <div class="flex-table-editor"><div class="InputfieldTinyMCE"><div class="InputfieldTinyMCEPlaceholder"></div>
        <textarea id="rich_a" class="InputfieldTinyMCEEditor InputfieldTinyMCENormal" name="rich_a">&lt;p&gt;Initial&lt;/p&gt;</textarea></div>
        <button class="flex-table-editor-close" type="button" hidden>Done</button><ul class="Inputfields">`).replace('</li></ul></td>', '</li></ul></div></td>');
    const h = await harness(tableSet(content));
    const {document: d, flex} = h;
    const preview = d.querySelector('.flex-table-preview');
    const input = d.querySelector('[name="rich_a"]');
    const close = d.querySelector('.flex-table-editor-close');
    let setups = 0;
    flex.classic.setup = async () => { setups++; input.classList.add('InputfieldTinyMCELoaded'); };
    const tiny = {initialized: false, save() {
        assert(this.initialized);
        input.value = '<p>Pending &amp; saved</p>';
    }};
    h.window.tinymce = {get: id => id === input.id ? tiny : undefined};
    assert.equal(preview.textContent, 'Initial');
    preview.click();
    assert.equal(d.querySelector('.InputfieldTinyMCEPlaceholder'), null);
    assert.equal(input.closest('tr').className, 'flex-table-detail');
    close.click();
    assert.equal(preview.textContent, 'Initial');
    preview.click();
    tiny.initialized = true;
    close.click();
    assert.equal(preview.textContent, 'Pending & saved');
    assert(input.classList.contains('InputfieldTinyMCELazy'));
    assert(!input.classList.contains('InputfieldTinyMCELoaded'));
    assert.equal(new h.window.FormData(input.form).get('rich_a'), '<p>Pending &amp; saved</p>');
    preview.click();
    assert.equal(setups, 3);
    h.close();
});
test('clicking a table cell focuses its visible enabled field and leaves tabs and actions alone', async () => {
    const h = await harness(tableSet(tableRow('a')));
    const d = h.document;
    d.querySelector('table').classList.add('flex-table-grid');
    const cell = d.querySelector('.flex-table-cell');
    cell.querySelector('.Inputfield').outerHTML = `<li class="Inputfield flex-table-input"><div class="InputfieldContent">
        <a href="#language" role="tab">Language</a>
        <input name="disabled" disabled>
        <input name="inactive_language" style="display:none">
        <textarea name="active_language"></textarea>
        </div></li>`;
    const disabled = cell.querySelector('[name="disabled"]');
    const active = cell.querySelector('textarea');
    for(const field of [disabled, active]) field.getClientRects = () => [{width: 100, height: 30}];
    const tab = cell.querySelector('[role="tab"]');
    const actionButton = d.querySelector('[data-flex-row-action="remove"]');
    tab.focus(); tab.click();
    assert.equal(d.activeElement, tab);
    actionButton.focus();
    d.querySelector('.flex-table-actions').click();
    assert.equal(d.activeElement, actionButton);
    cell.click();
    assert.equal(d.activeElement, active);
    active.disabled = true;
    tab.focus(); cell.click();
    assert.equal(d.activeElement, tab);
    const preview = d.createElement('button');
    preview.type = 'button';
    preview.className = 'flex-table-preview';
    preview.setAttribute('aria-expanded', 'false');
    preview.innerHTML = '<span>Preview</span>';
    const editor = d.createElement('div');
    editor.className = 'flex-table-editor';
    editor.hidden = true;
    editor.innerHTML = '<button class="flex-table-editor-close" hidden>Done</button>';
    cell.replaceChildren(preview, editor);
    preview.getClientRects = () => [{width: 100, height: 30}];
    cell.click();
    assert.equal(preview.getAttribute('aria-expanded'), 'true');
    assert.equal(d.activeElement, editor);
    preview.focus();
    cell.click();
    assert.equal(preview.getAttribute('aria-expanded'), 'true');
    assert.equal(d.activeElement, editor);
    preview.click();
    assert.equal(preview.getAttribute('aria-expanded'), 'false');
    h.close();
});
test('a table tag dropdown escapes the scroll container until Selectize closes it', async () => {
    const h = await harness(tableSet(tableRow('a')));
    const d = h.document;
    d.querySelector('table').classList.add('flex-table-grid');
    const scroll = d.querySelector('.flex-table-scroll');
    const source = d.querySelector('[name="cell_a"]');
    source.hidden = true;
    source.insertAdjacentHTML('afterend', '<div class="selectize-control"><input><div class="selectize-dropdown"></div></div>');
    const wrapper = source.nextElementSibling;
    const input = wrapper.querySelector('input');
    const menu = wrapper.querySelector('.selectize-dropdown');
    const callbacks = new Map();
    const settings = {dropdownParent: null};
    let positions = 0;
    const widget = {settings, isOpen: true, $dropdown: [menu],
        on(event, callback) { callbacks.set(event, callback); },
        positionDropdown() { positions++; assert.equal(settings.dropdownParent, 'body'); },
        close() { this.isOpen = false; callbacks.get('dropdown_close')(); },
    };
    source.selectize = widget;
    input.focus();
    assert.equal(menu.parentElement, d.body);
    assert.equal(positions, 1);
    scroll.dispatchEvent(new h.window.Event('scroll'));
    assert.equal(widget.isOpen, false);
    assert.equal(menu.parentElement, wrapper);
    assert.equal(settings.dropdownParent, null);
    input.blur(); input.focus();
    widget.isOpen = true;
    callbacks.get('dropdown_open')();
    assert.equal(menu.parentElement, d.body);
    assert.equal(positions, 2);
    widget.close();
    assert.equal(menu.parentElement, wrapper);
    h.close();
});
test('multilingual table cells hide field headers and their controls', async () => {
    const h = await harness(tableSet(tableRow('a'))), d = h.document;
    const table = d.querySelector('table'); table.classList.add('flex-table-grid');
    const cell = d.querySelector('li.Inputfield'); cell.classList.add('flex-table-input');
    const header = d.createElement('label');
    header.className = 'InputfieldHeader';
    header.innerHTML = 'Column caption<button class="langTabsToggle" type="button">Languages</button><i class="toggle-icon"></i>';
    cell.prepend(header);
    const columnCell = cell.cloneNode(true);
    const headRow = table.createTHead().insertRow();
    const th = d.createElement('th'); th.scope = 'col'; headRow.append(th, d.createElement('th'));
    const headInputs = cell.parentElement.cloneNode(false); th.append(headInputs); headInputs.append(columnCell);
    const columnHeader = columnCell.querySelector('.InputfieldHeader');
    const style = d.createElement('style'); style.textContent = fs.readFileSync(path.join(__dirname, '../../FieldtypeFlex.css'), 'utf8'); d.head.append(style);
    assert.equal(h.window.getComputedStyle(header).position, 'absolute', 'ordinary repeated header is compact');
    for(const nativeState of ['hasLangTabs', 'langTabsOff']) {
        cell.className = 'Inputfield flex-table-input ' + nativeState;
        columnCell.className = cell.className;
        for(const label of [header, columnHeader]) {
            assert.equal(h.window.getComputedStyle(label).position, 'absolute', 'field header occupies no space in the cell');
            assert.equal(h.window.getComputedStyle(label.querySelector('.langTabsToggle')).display, 'none');
            assert.equal(h.window.getComputedStyle(label.querySelector('.toggle-icon')).display, 'none');
        }
    }
    h.close();
});
test('nested titles react to local, parent and root inputs and preserve classes', async () => {
    const title = {
        nodes: [ref('@path'), {text: ' / '}, ref('heading', ['custom', 'flex-strong']),
            {children: [{text: ' — '}, ref('parent.heading'), {text: ' / '}, ref('root.heading')], classes: ['flex-muted']}],
        values: {heading: 'Child', '@path': 'Text', 'parent.heading': 'Parent', 'root.heading': 'Root'},
        sources: {'heading': {names: ['heading_child'], kind: 'text'},
            'parent.heading': {names: ['heading_parent'], kind: 'text'}, 'root.heading': {names: ['heading_root'], kind: 'text'}},
    };
    const nested = set(row('child', 'Child', '', title)).replace('data-flex-set="blocks"', 'data-flex-set="nested"');
    const h = await harness('<input name="heading_root" value="Root">' + set(row('parent', 'Parent', nested)));
    const child = h.document.querySelector('[data-flex-row="child"] .flex-row-title');
    assert.equal(child.textContent, 'Text / Child — Parent / Root');
    function edit(name, value) {
        const input = h.document.querySelector(`[name="${name}"]`);
        input.value = value;
        input.dispatchEvent(new h.window.Event('input', {bubbles: true}));
    }
    edit('heading_root', '');
    assert.equal(child.textContent, 'Text / Child');
    assert.equal(child.querySelector('.flex-muted'), null);
    edit('heading_child', '{heading} <b>New</b>');
    assert.equal(child.querySelector('.custom.flex-strong').textContent, '{heading} New');
    edit('heading_parent', 'Updated parent');
    edit('heading_root', 'New root');
    assert.equal(child.textContent, 'Text / {heading} New — Updated parent / New root');
    assert.equal(h.calls.length, 0);
    h.close();
});
test('titles read native inline text, date parts, Page labels and per-file descriptions', async () => {
    const widgets = `<div class="Inputfield InputfieldTinyMCE"><div class="InputfieldTinyMCEEditor InputfieldTinyMCEInline" name="rich" contenteditable></div></div>
        <div class="Inputfield"><input type="hidden" class="InputfieldDatetimeValue" name="date" value="2026-7-4">
            <select name="date__y"><option>2026</option></select><select name="date__m"><option>7</option></select><select name="date__d"><option>4</option></select></div>
        <div class="Inputfield InputfieldPageListSelect"><input type="hidden" name="page" value="5"><a class="PageListSelectName">Old</a></div>
        <div class="Inputfield InputfieldPageAutocomplete"><input type="hidden" name="pages[]" value="6"><ol>
            <li class="delete"><span class="itemValue">5</span><span class="itemLabel">Deleted choice</span></li>
            <li><span class="itemValue">6</span><span class="itemLabel">Visible choice</span></li></ol></div>
        <div class="Inputfield"><label><input type="checkbox" name="pageboxes[]" value="8" checked>Checked page</label></div>
        <div class="Inputfield InputfieldFile" id="wrap_Inputfield_image"><ul><li><div class="gridImage__overflow"><img data-original="/files/original.png"></div>
            <input type="hidden" name="sort_image_hash" value="0"><div data-flex-metadata-property="description"><input name="file_description" value="Old"></div></li></ul></div>`;
    const sources = {rich: {names:['rich'], kind:'text'}, date:{names:['date'], kind:'date'},
        page:{names:['page'], kind:'page'}, pages:{names:['pages'], kind:'page'}, pageboxes:{names:['pageboxes'], kind:'page'},
        'image.description':{names:[], kind:'media', base:'image', leaf:'description'}, 'image.basename':{names:[], kind:'media', base:'image', leaf:'basename'}};
    const nodes = Object.keys(sources).flatMap(name => [{children: [ref(name, [name.replace('.', '-')]), {text: ' ' }]}]);
    const h = await harness(set(row('a', '', widgets, {nodes, sources, values:{}})));
    const {document: d, window: w} = h;
    const update = (selector, value) => {
        const node = d.querySelector(selector);
        if(value !== undefined) {
            if(node.hasAttribute('contenteditable')) node.innerHTML = value;
            else node.value = value;
        }
        node.dispatchEvent(new w.Event('input', {bubbles: true}));
    };
    update('[name="rich"]', '<strong>Rich text</strong>');
    update('[name="date__d"]');
    update('[name="page"]');
    d.querySelector('.PageListSelectName').textContent = 'Selected after change';
    update('[name="pages[]"]');
    update('[name="pageboxes[]"]');
    update('[name="file_description"]', 'Custom image description');
    await tick();
    assert.equal(d.querySelector('.flex-row-title').textContent, 'Rich text 2026-07-04 Selected after change Visible choice Checked page Custom image description original.png');
    assert.equal(d.querySelector('.flex-row-title .image-description').textContent, 'Custom image description');
    assert.equal(d.querySelector('.flex-row-title strong'), null);
    assert.equal(h.calls.length, 0);
    h.close();
});
test('hook title previews debounce edits, retain local indexes and discard stale responses', async () => {
    const pending = [];
    const content = row('a', 'Alpha', '', {
        nodes: [{text: '#'}, ref('@index'), {text: ' '}, ref('heading'), {children: [{text: ' · '}, ref('@details')], classes: ['flex-muted']}],
        custom: {'@details': 'Initial'}, refresh: true,
    }) + row('b', 'Beta');
    const h = await harness(set(content + '<li data-flex-external-dependencies="enabled"></li>'), {response: () => new Promise(resolve => pending.push(resolve))});
    const {document: d, window: w} = h;
    const external = d.createElement('div');
    external.innerHTML = '<input type="checkbox" name="enabled" value="1" checked><input name="unrelated" value="secret">';
    d.querySelector('.InputfieldFlex').before(external);
    const header = d.querySelector('[data-flex-row="a"] .flex-row-title');
    const input = d.querySelector('[name="heading_a"]');
    const edit = value => { input.value = value; input.dispatchEvent(new w.Event('input', {bubbles: true})); };
    const wait = () => new Promise(resolve => setTimeout(resolve, 270));
    const respond = (index, label) => pending[index]({ok: true, json: async () => ({error: false, titles: {'blocks.a': {custom: {'@details': label}, override: null}}})});
    assert.equal(header.textContent, '#1 Alpha · Initial');
    edit('first'); edit('second');
    await wait();
    assert.equal(h.calls.length, 1);
    assert.equal(h.calls[0].body.get('heading_a'), 'second');
    assert.equal(h.calls[0].body.get('enabled'), '1');
    assert.equal(h.calls[0].body.has('unrelated'), false);
    assert.deepEqual(JSON.parse(h.calls[0].body.get('flex_title_rows')), [['blocks', 'a']]);
    edit('Newest');
    respond(0, 'Stale');
    await tick();
    assert.equal(header.textContent, '#1 Newest · Initial');
    d.querySelector('[data-flex-row="a"] .flex-row-sort').dispatchEvent(new w.KeyboardEvent('keydown', {key: 'End', bubbles: true}));
    assert.equal(header.textContent, '#2 Newest · Initial');
    await wait();
    assert.equal(h.calls.length, 2);
    respond(1, '<em>Safe</em>');
    await tick();
    assert.equal(header.querySelector('.flex-muted').textContent, ' · <em>Safe</em>');
    assert.equal(header.querySelector('em'), null);
    await wait();
    assert.equal(h.calls.length, 2);
    const enabled = d.querySelector('[name="enabled"]');
    enabled.checked = false;
    enabled.dispatchEvent(new w.Event('change', {bubbles: true}));
    await wait();
    assert.equal(h.calls.length, 3);
    assert.equal(h.calls[2].body.has('enabled'), false);
    h.close();
});
test('successful controls match AJAX serialization, empty selections and disabled fieldsets', async () => {
    const h = await harness(); const {document: d, flex} = h;
    const scope = d.createElement('div'); scope.innerHTML = `<input name="zero" value="0"><textarea name="body">one\ntwo</textarea><input type="checkbox" name="no" value="1"><input type="checkbox" name="yes" value="1" checked><select name="many" multiple><option value="a" selected>A</option><option value="b" selected>B</option><option disabled value="c" selected>C</option></select><select name="empty" multiple><option>X</option></select><fieldset disabled><legend><input name="legend" value="ok"></legend><input name="disabled" value="no"></fieldset><button name="button">No</button><input type="file" name="file">`;
    d.body.append(scope);
    assert.deepEqual(Array.from(flex.serialize(scope), pair => Array.from(pair)), [['zero','0'], ['body','one\r\ntwo'], ['yes','1'], ['many','a'], ['many','b'], ['legend','ok']]); h.close();
});
test('deferred removal mirrors values, undo restores original disabled controls and min/max state', async () => {
    const h = await harness(set(row('a','A','<input name="disabled" disabled value="keep"><input name="checked" type="checkbox" checked value="1">') + row('b'), 'data-flex-min="1" data-flex-max="2"'));
    const d = h.document, rowA = d.querySelector('[data-flex-row="a"]'), remove = rowA.querySelector('[data-flex-row-action="remove"]');
    assert(d.querySelector('.flex-row-add').disabled); remove.click();
    assert(rowA.matches('.flex-row-remove-pending')); assert.equal(rowA.querySelector('[name="heading_a"]').disabled, true);
    assert.equal(rowA.querySelector('.flex-row-remove-mirror[name="heading_a"]').value, 'A');
    assert.equal(rowA.querySelector('.flex-row-remove-mirror[name="disabled"]'), null);
    assert.equal(d.querySelector('.flex-row-add').disabled, false);
    assert.equal(d.querySelector('[data-flex-row="b"] [data-flex-row-action="remove"]').disabled, true);
    remove.click(); assert.equal(rowA.querySelector('[name="heading_a"]').disabled, false); assert.equal(rowA.querySelector('[name="disabled"]').disabled, true);
    assert.equal(rowA.querySelector('.flex-row-remove-mirror'), null); h.close();
});
test('bulk remove/undo uses single-row limits and visibility keeps live input', async () => {
    const h = await harness(set(row('a') + row('b') + row('c'), 'data-flex-min="1"'), {before(w) {w.confirm = () => true;}});
    const d = h.document, button = d.querySelector('[data-flex-row-action="remove"]');
    button.dispatchEvent(new h.window.MouseEvent('dblclick', {bubbles:true}));
    assert.equal(d.querySelectorAll('.flex-row-remove-pending').length, 2);
    button.dispatchEvent(new h.window.MouseEvent('dblclick', {bubbles:true})); assert.equal(d.querySelectorAll('.flex-row-remove-pending').length, 0);
    const visibility = d.querySelector('[data-flex-row-action="visibility"]'); visibility.click();
    assert.equal(visibility.getAttribute('aria-pressed'), 'true'); assert.equal(d.querySelector('.flex-row-hidden-value').value, '1');
    assert.equal(d.querySelector('[name="heading_a"]').disabled, false); h.close();
});
test('AJAX duplication copies unsaved values, excludes main Page id and executes native inline configuration', async () => {
    const h = await harness(set(row('a','Original','<input name="unchecked" type="checkbox">')), {response: async () => ({ok:true, json:async () => ({html:row('copy', 'Copied', '<script>window.nativeConfigReady = true;</script>')})})});
    const d = h.document; d.querySelector('[name="heading_a"]').value = 'Unsaved';
    d.querySelector('[data-flex-row-action="duplicate"]').click(); await tick();
    const data = h.calls[0].body; assert.equal(data.get('id'), null); assert.equal(data.get('heading_a'), 'Unsaved'); assert.equal(data.get('unchecked'), null);
    assert.equal(data.get('flex_source'), 'a'); assert.equal(data.get('flex_complete'), 'draft'); assert.equal(h.calls[0].url, '/edit/?id=42');
    assert.equal(d.querySelector('.flex-rows-order').value, 'a,copy'); assert.equal(h.window.nativeConfigReady, true); assert.equal(d.querySelector('.InputfieldFlex').getAttribute('aria-busy'), 'false'); h.close();
});
test('failed AJAX add restores controls and shows text safely', async () => {
    const h = await harness(set(), {response:async () => ({ok:false,json:async () => ({message:'<img src=x> denied'})})});
    h.document.querySelector('.flex-row-add').click(); await tick();
    assert.equal(h.document.querySelector('.flex-rows-error').textContent, '<img src=x> denied'); assert.equal(h.document.querySelector('.flex-rows-error img'), null);
    assert.equal(h.document.querySelector('.flex-row-add').disabled, false); h.close();
});
test('lazy loading shares in-flight request, blocks submit after failure, permits Retry', async () => {
    let resolve, count = 0;
    const lazy = row('lazy').replace('data-flex-row="lazy"', 'data-flex-loaded="0" data-flex-row="lazy"').replace('<div class="flex-row-content">', '<div class="flex-row-content"><p class="flex-row-load-status"></p><button class="flex-row-retry" type="button">Retry</button>');
    const h = await harness(set(lazy), {response: () => { count++; return new Promise(done => resolve = done); }});
    const rowElement = h.document.querySelector('.flex-row'), first = h.flex.test.loadRow(rowElement);
    assert.equal(first, h.flex.test.loadRow(rowElement)); assert.equal(count, 1);
    resolve({ok:false,json:async () => ({message:'Failed'})}); assert.equal(await first, false);
    const form = h.document.querySelector('form'), submit = new h.window.Event('submit', {bubbles:true,cancelable:true});
    assert.equal(form.dispatchEvent(submit), false); assert.match(h.document.querySelector('.flex-form-request-error').textContent, /Unable to load/);
    const second = h.flex.test.loadRow(rowElement);
    resolve({ok:true,json:async () => ({html:row('lazy','Loaded').replace('data-flex-row="lazy"', 'data-flex-loaded="1" data-flex-row="lazy"')})});
    assert.equal(await second, true); assert.equal(rowElement.getAttribute('data-flex-loaded'), '1'); assert.equal(rowElement.querySelector('[name="heading_lazy"]').value, 'Loaded');
    assert.equal(form.dispatchEvent(new h.window.Event('submit', {cancelable:true})), true); h.close();
});
test('max_input_vars and pending transport guards use native FormData', async () => {
    const h = await harness(), form = h.document.querySelector('form'); form.setAttribute('data-flex-max-input-vars', '1');
    assert.equal(form.dispatchEvent(new h.window.Event('submit',{cancelable:true})),false); assert.match(h.document.querySelector('[role="alert"]').textContent, /max_input_vars=1/);
    form.setAttribute('data-flex-max-input-vars', '1000'); h.flex.test.setTreeBusy(h.document.querySelector('.flex-rows'),true);
    assert.equal(form.dispatchEvent(new h.window.Event('submit',{cancelable:true})),false); assert.match(h.document.querySelector('[role="alert"]').textContent,/Wait for/); h.close();
});
function metadata(asset = 'old') {
    return `<div data-flex-metadata="1"><div id="caption_${asset}" data-flex-metadata-property="caption" class="InputfieldStateError"><div class="InputfieldContent"><p class="InputfieldError">Required</p><input name="caption__${asset}__text" value="Pending"></div></div><div data-flex-metadata-property="enabled"><input type="checkbox" name="flag__${asset}__enabled" value="1"></div><div data-flex-metadata-property="choices"><select name="choices__${asset}__options" multiple><option value="a">Alpha</option></select></div><input name="description_${asset}" value="Description"><input name="tags_${asset}" value="tag"><div data-flex-metadata-property="rich"><div class="InputfieldTinyMCEEditor InputfieldTinyMCEInline InputfieldTinyMCELoaded" id="rich__${asset}__editor"><p>Pending inline</p></div></div></div>`;
}
test('media snapshots preserve unchecked, empty multiselect, inline markup and validation through replacement', async () => {
    const h = await harness(metadata()); const scope = h.document.querySelector('[data-flex-metadata]');
    const snapshot = h.flex.test.mediaMetadata(scope); scope.outerHTML = metadata('new'); const fresh = h.document.querySelector('[data-flex-metadata]');
    fresh.querySelector('input').value = ''; fresh.querySelector('[type="checkbox"]').checked = true; fresh.querySelector('select').value = 'a'; fresh.querySelector('.InputfieldError').remove();
    h.flex.test.restoreMediaMetadata(fresh,snapshot,true);
    assert.equal(fresh.querySelector('input').value,'Pending'); assert.equal(fresh.querySelector('[type="checkbox"]').checked,false); assert.equal(fresh.querySelector('select').selectedOptions.length,0);
    assert.equal(fresh.querySelector('.InputfieldError').textContent,'Required'); assert.equal(fresh.querySelector('.InputfieldTinyMCEInline').innerHTML,'<p>Pending inline</p>'); h.close();
});
test('upload replaces maxFiles=1 item and retains pending metadata', async () => {
    const field = `<div class="Inputfield InputfieldImage"><div class="InputfieldContent"><input class="InputfieldImageMaxFiles" value="1"><ul class="InputfieldImageList"><li>${metadata()}</li></ul><div data-flex-upload="1" data-fieldname="content" data-flex-draft="draft" data-flex-path="images" data-posturl="/upload"><input type="file"></div></div></div>`;
    const h = await harness(field, {response:async () => ({ok:true,json:async () => [{markup:`<li>${metadata('new')}</li>`}]})});
    await h.flex.test.upload(h.document.querySelector('[type="file"]'), [new h.window.File(['x'],'new.png')]);
    assert.equal(h.calls[0].headers['X-FILENAME'],'new.png'); assert.equal(h.calls[0].headers['X-TOKEN'],'secret'); assert.equal(h.document.querySelectorAll('.InputfieldImageList > li').length,1);
    assert.equal(h.document.querySelector('[name="caption__new__text"]').value,'Pending'); assert.equal(h.document.querySelector('.flex-upload-status'),null); assert(h.document.querySelector('.InputfieldImage').matches('.InputfieldStateChanged')); h.close();
});
test('media refresh preserves pending controls and native CustomEvent transport', async () => {
    const inner = `${metadata()}<div data-flex-upload="1" data-fieldname="content" data-flex-draft="draft" data-flex-path="images" data-posturl="/render"></div>`;
    const h = await harness(`<div class="Inputfield"><div class="InputfieldContent">${inner}</div></div>`, {response:async () => ({ok:true,json:async () => ({html:inner.replace('value="Pending"','value="Server"')})})});
    h.document.dispatchEvent(new h.window.CustomEvent('flexMediaRefresh',{detail:{draft:'draft',path:'images'}})); await tick();
    assert.equal(h.document.querySelector('[name="caption__old__text"]').value,'Pending'); assert.equal(h.calls[0].body.get('flex_action'),'media-render'); h.close();
});
test('TinyMCE move lifecycle saves/removes editors and retains unrelated inline editors', async () => {
    const calls = [];
    const h = await harness(set(row('a','A','<textarea id="editor" class="InputfieldTinyMCEEditor InputfieldTinyMCELoaded"></textarea><div class="InputfieldTinyMCEInline">Inline</div>')), {before(w) {w.tinymce = {get:id => ({save:()=>calls.push('save:'+id),remove:()=>calls.push('remove:'+id)})};}});
    h.flex.test.teardown(h.document.querySelector('.flex-row'));
    assert.deepEqual(calls,['save:editor','remove:editor']); assert(!h.document.querySelector('textarea').matches('.InputfieldTinyMCELoaded')); h.close();
});
test('classic sortable adapter passes native Elements and does not initialize twice', async () => {
    let options, initCount = 0, disabled;
    const collections = new WeakMap();
    const h = await harness(set(), {before(w) {
        function $(element) {
            if(!collections.has(element)) collections.set(element, {0:element,on(){return this;},trigger(){return this;},hasClass:name=>element.classList.contains(name),sortable(...args){if(typeof args[0]==='object'){options=args[0];initCount++;element.classList.add('ui-sortable');}else disabled=args[2];return this;}});
            return collections.get(element);
        }
        $.fn = {sortable:true}; w.jQuery = $;
    }});
    assert.equal(initCount,1); h.flex.test.initRows(h.document); assert.equal(initCount,1); assert.equal(disabled,false);
    let moved; const list=h.document.createElement('div'); h.flex.sortable.init(list, row=>moved=row, ()=>{});
    const rowElement=h.document.querySelector('.flex-row'); options.start({}, {item:{0:rowElement},placeholder:{height(){}}}); assert.equal(moved,rowElement); h.close();
});
test('maintenance preview invalidates changed cleanup selection and displays server text safely', async () => {
    const dom = new JSDOM(`<form><input class="_post_token" name="TOKEN" value="secret"><div class="FlexMaintenance" data-field="1" data-messages='{"retry":"Retry"}'><section class="FlexMaintenanceTask"><button data-flex-maintenance="orphans">Scan</button><div class="FlexMaintenancePaths"></div><button data-flex-maintenance="cleanup" hidden>Preview</button><button data-flex-maintenance="applyCleanup" hidden>Apply</button><div class="FlexMaintenanceReport"></div><p class="FlexMaintenanceProgress"></p></section></div></form>`,{runScripts:'outside-only',url:'https://fixture.test/'});
    const w=dom.window, d=w.document;
    w.fetch=async()=>({ok:true,json:async()=>({checked:1,changed:0,wouldChange:1,conflicts:[],errors:[],renames:[],pages:{1:{}},complete:true,properties:{unknown:{path:'<script>unknown',pageCount:1,media:0,pageIds:[1],locations:[{pageId:1,address:'root'}]}}})});
    w.eval(maintenance); await tick(); d.querySelector('[data-flex-maintenance="orphans"]').click(); await tick();
    const checkbox=d.querySelector('.FlexMaintenancePaths input'); assert(checkbox); assert.equal(d.querySelector('script'),null);
    checkbox.checked=true; checkbox.dispatchEvent(new w.Event('change',{bubbles:true})); assert.equal(d.querySelector('[data-flex-maintenance="cleanup"]').hidden,false);
    d.querySelector('[data-flex-maintenance="cleanup"]').click(); await tick(); assert.equal(d.querySelector('[data-flex-maintenance="applyCleanup"]').hidden,false);
    checkbox.checked=false; checkbox.dispatchEvent(new w.Event('change',{bubbles:true})); assert.equal(d.querySelector('[data-flex-maintenance="applyCleanup"]').hidden,true); assert.equal(d.querySelector('[data-flex-maintenance="cleanup"]').hidden,true); w.close();
});
test('maintenance rename works without an orphan path-selection element', async () => {
    const errors = [], console = new VirtualConsole(); console.on('jsdomError', error => errors.push(error));
    const dom = new JSDOM(`<form><input class="_post_token" name="TOKEN" value="secret"><div class="FlexMaintenance" data-field="1" data-messages='{}'><section class="FlexMaintenanceTask"><button data-flex-maintenance="rename">Scan</button><button data-flex-maintenance="applyRename" hidden>Apply</button><div class="FlexMaintenanceReport"></div><p class="FlexMaintenanceProgress"></p></section></div></form>`,{runScripts:'outside-only',url:'https://fixture.test/',virtualConsole:console});
    let calls = 0;
    dom.window.fetch=async()=>{calls++;return {ok:true,json:async()=>({checked:1,changed:0,wouldChange:1,conflicts:[],errors:[],renames:[{from:'old',to:'new',pageId:1}],pages:{1:{}},complete:true,properties:{}})};};
    dom.window.eval(maintenance); await tick(); dom.window.document.querySelector('[data-flex-maintenance="rename"]').click(); await tick();
    assert.equal(calls,1); assert.equal(dom.window.document.querySelector('[data-flex-maintenance="applyRename"]').hidden,false); assert.match(dom.window.document.querySelector('.FlexMaintenanceReport').textContent,/old/);
    assert.deepEqual(errors,[]); dom.window.close();
});
test('classic lifecycle translates jQuery collections into Elements and handles nested toggles once', async () => {
    const handlers = [];
    const h=await harness(set(row('parent','Parent',set(row('child','Child')))),{before(w){
        function $(element){return {0:element,on(events,selector,handler){handlers.push({events,selector,handler:handler||selector});return this;},trigger(){return this;}};}
        $.fn={}; w.jQuery=$;
    }});
    const received=[];
    h.flex.classic.events(element=>received.push(['reload',element]),(element,open)=>received.push(['toggle',element,open]),element=>received.push(['tiny',element]),(draft,path)=>received.push(['media',draft,path]));
    const last=handlers.slice(-4), child=h.document.querySelector('[data-flex-row="child"]'), parent=h.document.querySelector('[data-flex-row="parent"]');
    last[1].handler.call(child,{target:child,type:'opened'}); last[1].handler.call(parent,{target:child,type:'opened'});
    assert.equal(received.length,1); assert.equal(received[0][1],child); assert.equal(received[0][2],true);
    last[3].handler({},'draft','images'); last[3].handler({}); assert.deepEqual(received[1],['media','draft','images']); assert.equal(received.length,2); h.close();
});
test('the standalone type picker adds the same type repeatedly and observes the row limit', async () => {
    let sequence = 0;
    const h = await harness(pickerSet(row('a'), 'data-flex-max="3"'), {
        response: async () => ({ok: true, json: async () => ({html: row('added-' + ++sequence)})}),
    });
    const picker = h.document.querySelector('.flex-row-type');
    assert.equal(picker.value, '');
    assert.equal(h.document.querySelector('.flex-row-add'), null);
    for(const _ of [1, 2]) {
        picker.value = 'image';
        picker.dispatchEvent(new h.window.Event('change', {bubbles: true}));
        assert.equal(picker.value, '');
        assert.equal(picker.disabled, true);
        await tick();
    }
    assert.deepEqual(h.calls.map(call => [call.body.get('flex_action'), call.body.get('flex_type')]), [['add', 'image'], ['add', 'image']]);
    assert.equal(h.document.querySelector('.flex-rows-order').value, 'a,added-1,added-2');
    assert.equal(picker.disabled, true);
    h.close();
});
function nativeAdmin(w, calls) {
    w.Bridge = {
        contract:'1',
        legacy:{loaded:()=>false,load:async()=>calls.push(['legacy'])},
        Inputfields:{init:scope=>calls.push(['init',scope]),open(row){row.classList.remove('InputfieldStateCollapsed');row.dispatchEvent(new w.CustomEvent('opened',{bubbles:true}));},close(row){row.classList.add('InputfieldStateCollapsed');row.dispatchEvent(new w.CustomEvent('closed',{bubbles:true}));}},
        sortable:async(list,options)=>{calls.push(['sort',list,options]);return ()=>calls.push(['destroy',list]);},
        dialog:{close:()=>calls.push(['close'])},
        confirm:async(message,ok)=>{calls.push(['confirm',message]);ok();return true;}
    };
    w.ProcessWireAdminTheme = {
        upstream:()=>false,
        trusted:{html:html=>{calls.push(['html',html]);return html;}},
        runScripts:async(scope,options)=>calls.push(['scripts',scope,options]),
        observe:callback=>{calls.push(['observe',callback]);return ()=>{};},
        legacy:{needsStack:()=>true,initElement:scope=>{w.Bridge.Inputfields.init(scope);scope.dispatchEvent(new w.CustomEvent('reloaded',{bubbles:true}));}}
    };
}
test('classic Uikit global does not activate Bridge paths during table AJAX or native setup', async()=>{
    const calls=[];
    const h=await harness(tableSet(tableRow('a')),{
        before(w){
            w.ProcessWireAdminTheme={init(){},setupWireTabs(){}};
            w.ProcessWire={config:{},confirm(message,ok){calls.push('confirm');ok();}};
            function $(element){return {0:element,on(){return this;},trigger(){return this;}};} $.fn={};w.jQuery=$;
            w.Inputfields={init(){calls.push('init');}};
        },
        response:async()=>({ok:true,json:async()=>({html:tableRow('copy')})})
    });
    h.document.querySelector('[data-flex-row-action="duplicate"]').click();await tick();
    assert.equal(h.document.querySelectorAll('tbody > tr').length,2);assert.equal(h.document.querySelector('.flex-rows-error').textContent,'');
    assert(calls.includes('init'));h.flex.classic.confirm('Continue',()=>calls.push('ok'));assert(calls.includes('ok'));
    h.close();
});
test('Bridge uses native sortable and toggle APIs without the proposed files API', async()=>{
    const calls=[];
    const h=await harness(set()+`<div class="Inputfield" data-bridge-component="InputfieldFlexImage" data-bridge-widget="InputfieldImage"></div>`,{before(w){nativeAdmin(w,calls);}});
    assert.equal(h.window.jQuery,undefined); assert.equal(calls.filter(x=>x[0]==='sort').length,1);
    const sort=calls.find(x=>x[0]==='sort'); assert.equal(sort[2].enabled(),true);
    h.flex.test.setTreeBusy(h.document.querySelector('.flex-rows'),true);assert.equal(sort[2].enabled(),false);
    h.flex.test.setTreeBusy(h.document.querySelector('.flex-rows'),false);
    h.document.querySelector('.flex-row-toggle').click();assert(h.document.querySelector('.flex-row').matches('.InputfieldStateCollapsed'));
    h.flex.classic.closeDialog();assert(calls.some(x=>x[0]==='close'));
    const observe=calls.find(x=>x[0]==='observe')[1];sort[1].remove();observe();assert(calls.some(x=>x[0]==='destroy'));h.close();
});
test('Bridge fragment insertion uses the existing Trusted Types policy and response nonce', async()=>{
    const calls=[];
    const h=await harness(set(),{before(w){nativeAdmin(w,calls);},response:async()=>({ok:true,headers:{get:()=> 'response-nonce'},json:async()=>({html:row('copy')})})});
    h.document.querySelector('[data-flex-row-action="duplicate"]').click();await tick();await tick();
    const scripts=calls.find(x=>x[0]==='scripts');assert.equal(scripts[2].nonce,'response-nonce');assert.equal(scripts[2].legacy,false);assert(calls.some(x=>x[0]==='html'));
    assert.equal(h.document.querySelector('.flex-rows-order').value,'a,copy,b');h.close();
});
test('Bridge loads legacy only for a rendered legacy child and binds its later custom events', async()=>{
    const calls=[], handlers=[];
    const h=await harness(set(),{before(w){nativeAdmin(w,calls);}});
    const scope=h.document.createElement('div');scope.innerHTML='<div class="Inputfield" data-bridge-legacy="InputfieldTinyMCE"></div>';h.document.querySelector('.InputfieldFlex').append(scope);
    h.window.jQuery=Object.assign(element=>({0:element,on(events,selector,handler){handlers.push({events,handler:handler||selector});return this;}}),{fn:{}});
    h.document.dispatchEvent(new h.window.Event('pw-legacy-loaded'));
    assert.equal(handlers.length,4);await h.flex.classic.setup(scope);assert.equal(calls.filter(x=>x[0]==='legacy').length,1);
    h.close();
});
test('Bridge media subclasses use the declarative Inputfields lifecycle without a files API', async()=>{
    const calls=[];
    const h=await harness(set(),{before(w){nativeAdmin(w,calls);}});
    const field=h.document.createElement('div');field.className='Inputfield';
    field.setAttribute('data-bridge-component','InputfieldFlexFile');field.setAttribute('data-bridge-widget','InputfieldFile');
    h.document.querySelector('.InputfieldFlex').append(field);
    assert.equal(h.window.Bridge.files,undefined);
    await h.flex.classic.setup(field);
    assert(calls.some(x=>x[0]==='init'&&x[1]===field));
    assert.equal(field.getAttribute('data-bridge-component'),'InputfieldFlexFile');
    assert.equal(field.getAttribute('data-bridge-widget'),'InputfieldFile');
    assert.equal(calls.filter(x=>x[0]==='legacy').length,0);h.close();
});
test('Bridge with a legacy child closes the upstream image dialog instead of the native one', async()=>{
    const calls=[];
    const h=await harness(set(),{before(w){nativeAdmin(w,calls);}});
    h.window.ProcessWireAdminTheme.upstream=()=>true;
    h.window.jQuery=selector=>({dialog:action=>calls.push(['legacyDialog',selector,action])});
    h.flex.classic.closeDialog();
    assert(calls.some(x=>x[0]==='legacyDialog'&&x[2]==='close'));assert.equal(calls.filter(x=>x[0]==='close').length,0);h.close();
});
test('maintenance rebinds a replaced native form once after in-place save', async()=>{
    const markup='<form><input class="_post_token" name="TOKEN" value="secret"><div class="FlexMaintenance" data-field="1" data-messages="{}"><section class="FlexMaintenanceTask"><button data-flex-maintenance="rename">Scan</button><button data-flex-maintenance="applyRename" hidden>Apply</button><div class="FlexMaintenanceReport"></div><p class="FlexMaintenanceProgress"></p></section></div></form>';
    const dom=new JSDOM(markup,{runScripts:'outside-only',url:'https://fixture.test/'});let calls=0;
    dom.window.fetch=async()=>{calls++;return {ok:true,json:async()=>({checked:1,changed:0,wouldChange:0,conflicts:[],errors:[],renames:[],pages:{},properties:{},complete:true})};};
    dom.window.eval(maintenance);await tick();dom.window.document.querySelector('[data-flex-maintenance="rename"]').click();await tick();
    dom.window.document.body.innerHTML=markup;
    dom.window.document.dispatchEvent(new dom.window.CustomEvent('pw-form-saved'));dom.window.document.dispatchEvent(new dom.window.CustomEvent('pw-form-saved'));
    dom.window.document.querySelector('[data-flex-maintenance="rename"]').click();await tick();assert.equal(calls,2);dom.window.close();
});

function clipboardMenu() {
    return `<details class="flex-row-clipboard"><summary title="Row clipboard">Clipboard</summary><div class="flex-clipboard-actions">${[
        ['clipboard-copy', 'Copy Row to Clipboard'], ['paste-above', 'Paste Row Above'], ['paste-below', 'Paste Row Below']
    ].map(([action, text]) => `<button type="button" class="flex-row-action" data-flex-row-action="${action}">${text}</button>`).join('')}</div></details>`;
}
function pickerSet(content, attributes = '') {
    return set(content, attributes).replace(' data-flex-type="text"', '').replace('<button type="button" class="flex-row-add">Add</button>',
        '<select class="flex-row-type"><option value="" selected disabled>Add item</option><option value="text">Text</option><option value="image">Image</option></select>');
}
function collectionSet(key, markup) {
    const menu = `<details class="flex-collection-menu" hidden><summary>Actions</summary>
        <div class="flex-clipboard-actions"><button type="button" data-flex-selection-action="start">Select rows</button>
        <button type="button" class="flex-row-action" data-flex-row-action="paste-end">Paste from Clipboard</button>
        ${['copy', 'hide', 'remove'].map(action => `<button type="button" data-flex-selection-action="${action}" hidden>${action}</button>`).join('')}</div></details>`;
    const selection = `<button type="button" data-flex-selection-action="done" hidden>Done</button>
        <span class="flex-selection-count" data-flex-count="{count} selected" hidden></span>`;
    const setMarkup = markup.replace('data-flex-set="blocks"', `data-flex-set="${key}"`)
        .replace('</div><p class="flex-rows-error">', menu + selection + '</div><p class="flex-rows-error">');
    return `<div class="Inputfield"><div class="InputfieldHeader">${key}</div><div class="InputfieldContent">${setMarkup}</div></div>`;
}
test('clipboard write begins in the click before collecting live form values via AJAX', async () => {
    const events = [];
    const clipboard = '{"format":"fieldtype-flex-row","version":1}';
    const h = await harness(pickerSet(row('a').replace('</span>', '</span>' + clipboardMenu())), {
        before(w) {
            w.ClipboardItem = class { constructor(data) { this.data = data; } };
            Object.defineProperty(w.navigator, 'clipboard', {value: {
                async write([item]) {
                    events.push('write');
                    const blob = await item.data['text/plain'];
                    assert.equal(blob.type, 'text/plain');
                    const reader = new w.FileReader();
                    await new Promise((resolve, reject) => {
                        reader.onload = resolve; reader.onerror = reject; reader.readAsText(blob);
                    });
                    assert.equal(reader.result, clipboard);
                },
                writeText() { throw new Error('unexpected fallback'); },
            }});
        },
        response: async () => {
            events.push('fetch');
            return {ok: true, json: async () => ({clipboard})};
        },
    });
    const d = h.document;
    d.querySelector('[name="heading_a"]').value = 'Pending source edit';
    const summary = d.querySelector('.flex-row-clipboard summary');
    summary.click();
    d.querySelector('[data-flex-row-action="clipboard-copy"]').click();
    assert.deepEqual(events, ['write']);
    await new Promise(resolve => setTimeout(resolve, 20));
    assert.deepEqual(events, ['write', 'fetch']);
    const request = h.calls[0].body;
    assert.equal(request.get('heading_a'), 'Pending source edit');
    assert.equal(request.get('flex_source'), 'a');
    assert.equal(request.get('id'), null);
    assert.match(d.querySelector('.flex-rows-status').textContent, /Row copied/);
    assert.equal(d.activeElement, summary);
    assert.equal(d.querySelector('.flex-row-clipboard').open, false);
    assert.equal(d.querySelectorAll('.flex-row').length, 1);
    h.close();
});
test('clipboard read places a fresh row above or below an anchor and preserves unsaved target inputs', async () => {
    const events = [];
    const clipboard = '{"format":"fieldtype-flex-row","version":1}';
    const h = await harness(pickerSet(row('a') + row('b').replace('</span>', '</span>' + clipboardMenu())), {
        before(w) {
            Object.defineProperty(w.navigator, 'clipboard', {value: {readText() { events.push('read'); return Promise.resolve(clipboard); }}});
        },
        response: async (_url, {body}) => {
            const action = body.get('flex_action');
            events.push(action);
            return {ok: true, json: async () => ({html: row(action), warning: action === 'paste-below' ? 'Skipped image.png' : ''})};
        },
    });
    const d = h.document;
    const target = d.querySelector('[name="heading_b"]');
    target.value = 'Pending target edit';
    d.querySelector('[data-flex-row-action="paste-above"]').click();
    assert.deepEqual(events, ['read']);
    await tick();
    d.querySelector('[data-flex-row-action="paste-below"]').click();
    await tick();
    assert.deepEqual(events, ['read', 'paste-above', 'read', 'paste-below']);
    assert.equal(d.querySelector('.flex-rows-order').value, 'a,paste-above,b,paste-below');
    assert.equal(h.calls[0].body.get('flex_source'), 'b');
    assert.equal(h.calls[0].body.get('flex_clipboard'), clipboard);
    assert.equal(target.value, 'Pending target edit');
    assert.match(d.querySelector('.flex-rows-status').textContent, /Row pasted.*image\.png/);
    h.close();
});
test('a nested collection footer pastes a batch into an empty set and supports retry', async () => {
    const nested = collectionSet('blocks.a.children', set(''));
    let deny = true;
    const h = await harness(set(row('a', 'Parent', nested)), {
        before(w) {
            Object.defineProperty(w.navigator, 'clipboard', {value: {readText() {
                return deny ? Promise.reject(new w.DOMException('Permission denied', 'NotAllowedError')) : Promise.resolve('JSON');
            }}});
        },
        response: async () => ({ok: true, json: async () => ({rows: [{html: row('child-a')}, {html: row('child-b')}]})}),
    });
    const d = h.document, childSet = d.querySelector('[data-flex-set="blocks.a.children"]');
    const menu = d.querySelector('.flex-rows-footer > .flex-collection-menu');
    assert(menu);
    const paste = menu.querySelector('[data-flex-row-action="paste-end"]');
    assert.equal(childSet.querySelector('.flex-row-add').hidden, false);
    menu.querySelector('summary').click();
    paste.click();
    await tick();
    assert.match(childSet.querySelector('.flex-rows-error').textContent, /Clipboard access was denied/);
    assert.equal(h.calls.length, 0);
    deny = false;
    menu.querySelector('summary').click();
    paste.click();
    await tick();
    assert.equal(h.calls[0].body.get('flex_set'), 'blocks.a.children');
    assert.equal(h.calls[0].body.get('flex_action'), 'paste-end');
    assert.equal(h.calls[0].body.get('flex_source'), null);
    assert.equal(childSet.querySelector('.flex-rows-order').value, 'child-a,child-b');
    assert.equal(childSet.querySelector('.flex-rows-error').textContent, '');
    assert.equal(childSet.querySelector('.flex-rows-status').textContent, 'Rows pasted.');
    h.close();
});

test('the collection menu opens below its trigger or above near the viewport edge', async () => {
    const h = await harness(collectionSet('blocks', set(row('a'))));
    const menu = h.document.querySelector('.flex-rows-footer > .flex-collection-menu');
    const trigger = menu.querySelector('summary');
    const panel = menu.querySelector('.flex-clipboard-actions');
    const matches = panel.matches.bind(panel);
    let inTopLayer = false;
    panel.matches = selector => selector === ':popover-open' ? inTopLayer : matches(selector);
    panel.showPopover = () => { inTopLayer = true; };
    panel.hidePopover = () => { inTopLayer = false; };
    let anchor = {left: 400, right: 430, top: 300, bottom: 320};
    trigger.getBoundingClientRect = () => anchor;
    Object.defineProperties(panel, {offsetWidth: {value: 200}, offsetHeight: {value: 160}});
    trigger.click();
    menu.dispatchEvent(new h.window.Event('toggle'));
    assert.equal(panel.getAttribute('popover'), 'manual');
    assert(inTopLayer);
    assert.equal(panel.style.left, '400px');
    assert.equal(panel.style.top, '320px');
    anchor = {left: 64, right: 94, top: h.window.innerHeight - 50, bottom: h.window.innerHeight - 30};
    h.window.dispatchEvent(new h.window.Event('resize'));
    assert.equal(panel.style.left, '64px');
    assert.equal(panel.style.top, h.window.innerHeight - 210 + 'px');
    menu.open = false;
    menu.dispatchEvent(new h.window.Event('toggle'));
    assert(!inTopLayer);
    h.close();
});

test('a native collection reload replaces the footer menu and enables paste into an empty set', async () => {
    const h = await harness(collectionSet('blocks', set(row('a'))));
    const d = h.document;
    const oldMenu = d.querySelector('.flex-rows-footer > .flex-collection-menu');
    const owner = oldMenu.closest('.Inputfield');
    const refreshed = d.createElement('div');
    refreshed.innerHTML = collectionSet('blocks', set(''));
    owner.querySelector(':scope > .InputfieldContent').replaceWith(refreshed.querySelector('.InputfieldContent'));
    owner.dispatchEvent(new d.defaultView.CustomEvent('reloaded', {bubbles: true}));
    const menus = owner.querySelectorAll('.flex-rows-footer > .flex-collection-menu');
    assert.equal(menus.length, 1);
    assert.equal(oldMenu.isConnected, false);
    assert.equal(owner.querySelector('.InputfieldHeader .flex-collection-menu'), null);
    assert.equal(menus[0].previousElementSibling.className, 'flex-row-add');
    assert(!menus[0].hidden);
    assert(menus[0].querySelector('[data-flex-selection-action="start"]').disabled);
    assert(!menus[0].querySelector('[data-flex-row-action="paste-end"]').disabled);
    h.close();
});

test('collection selection collapses cards, adds a shift range, copies live values and respects removal limits', async () => {
    const h = await harness(collectionSet('blocks', set(row('a') + row('b').replace('Inputfield flex-row', 'Inputfield flex-row InputfieldStateCollapsed') + row('c') + row('d'), 'data-flex-min="2"')), {
        before(w) {
            Object.defineProperty(w.navigator, 'clipboard', {value: {writeText: async () => {}}});
        },
        response: async () => ({ok: true, json: async () => ({clipboard: 'copied'})}),
    });
    const d = h.document;
    const menu = d.querySelector('.flex-collection-menu');
    const bar = d.querySelector('.flex-rows-footer');
    const card = id => d.querySelector(`[data-flex-row="${id}"]`);
    const checkbox = id => card(id).querySelector('.flex-row-select');
    const button = action => bar.querySelector(`[data-flex-selection-action="${action}"]`);
    const click = (id, options) => card(id).querySelector('.flex-row-header').dispatchEvent(new h.window.MouseEvent('click', {bubbles: true, ...options}));
    const summary = menu.querySelector('summary');
    const counter = bar.querySelector('.flex-selection-count');
    const start = menu.querySelector('[data-flex-selection-action="start"]');
    assert(button('copy').hidden);
    assert(button('done').hidden);
    summary.click();
    start.click();
    assert(!menu.open);
    assert(start.hidden);
    assert(menu.querySelector('[data-flex-row-action="paste-end"]').hidden);
    assert(!button('copy').hidden);
    assert(button('copy').disabled);
    assert.equal(button('copy').closest('details'), menu);
    assert(!button('done').hidden);
    assert.equal(button('done').nextElementSibling, counter);
    assert(!counter.hidden);
    assert.equal(d.querySelectorAll('.flex-row.InputfieldStateCollapsed').length, 4);
    assert.equal(d.activeElement, checkbox('a'));
    assert(!checkbox('a').hidden);
    assert(card('a').querySelector('.flex-row-toggle').hasAttribute('inert'));
    checkbox('c').click();
    assert(checkbox('c').checked);
    click('a', {shiftKey: true});
    click('b', {ctrlKey: true});
    assert.equal(counter.textContent, '2 selected');
    assert(checkbox('a').checked);
    assert(!checkbox('b').checked);
    click('d', {metaKey: true});
    assert(button('remove').disabled);
    click('d', {metaKey: true});
    assert(!button('remove').disabled);
    d.querySelector('[name="heading_a"]').value = 'Live value';
    summary.click();
    button('copy').click();
    assert(!menu.open);
    assert.equal(d.activeElement, summary);
    await tick();
    assert.equal(h.calls[0].body.get('flex_sources'), 'a,c');
    assert.equal(h.calls[0].body.get('heading_a'), 'Live value');
    summary.click();
    button('hide').click();
    assert.equal(d.querySelectorAll('.flex-row-hidden').length, 2);
    summary.click();
    button('remove').click();
    assert.equal(d.querySelectorAll('.flex-row-remove-pending').length, 2);
    button('done').click();
    assert(counter.hidden);
    assert(button('done').hidden);
    assert(button('copy').hidden);
    assert(!start.hidden);
    assert.equal(d.activeElement, summary);
    assert(d.querySelector('[data-flex-row="b"]').matches('.InputfieldStateCollapsed'));
    assert(!d.querySelector('[data-flex-row="d"]').matches('.InputfieldStateCollapsed'));
    assert(checkbox('d').hidden);
    assert(!checkbox('d').checked);
    assert(!card('d').querySelector('.flex-row-toggle').hasAttribute('inert'));
    const restore = d.querySelector('[data-flex-row="a"] [data-flex-row-action="remove"]');
    restore.click();
    assert.equal(d.querySelector('[name="heading_a"]').value, 'Live value');
    assert(!d.querySelector('[name="heading_a"]').disabled);
    h.close();
});

test('table selection uses the whole row and suspends cell editing without changing submitted values', async () => {
    const h = await harness(collectionSet('blocks', tableSet(tableRow('a') + tableRow('b') + tableRow('c'))));
    const d = h.document;
    const row = id => d.querySelector(`[data-flex-row="${id}"]`);
    const cell = id => row(id).querySelector('.flex-table-cell');
    const checkbox = id => row(id).querySelector('.flex-row-select');
    const input = cell('a').querySelector('input');
    input.value = 'Pending edit';
    d.querySelector('[data-flex-selection-action="start"]').click();
    assert(!checkbox('a').hidden);
    assert.equal(d.activeElement, checkbox('a'));
    assert(input.closest('[inert]'));
    assert(!input.disabled);
    assert.equal(new h.window.FormData(d.querySelector('form')).get('cell_a'), 'Pending edit');
    cell('a').click();
    assert(checkbox('a').checked);
    assert.equal(d.activeElement, checkbox('a'));
    cell('c').dispatchEvent(new h.window.MouseEvent('click', {bubbles: true, shiftKey: true}));
    assert(['a', 'b', 'c'].every(id => checkbox(id).checked));
    checkbox('b').click();
    assert(!checkbox('b').checked);
    row('a').lastElementChild.click();
    assert(!checkbox('a').checked);
    d.querySelector('[data-flex-selection-action="done"]').click();
    assert(checkbox('a').hidden);
    assert(!checkbox('c').checked);
    assert.equal(input.closest('[inert]'), null);
    input.focus();
    assert.equal(d.activeElement, input);
    h.close();
});

test('column sorting uses live scalar values, preserves ties and pending removal, and only runs on click', async () => {
    const values = [
        ['a', 'Bravo', '10', '2,5'],
        ['b', '', '', ''],
        ['c', 'alpha', '2', '-3,25'],
        ['d', 'Alpha', '2', '0'],
        ['e', 'Pending', '999', '99'],
    ];
    const kinds = ['text', 'integer', 'float'];
    const content = values.map(([id, ...cells]) => tableRow(id).replace(/<td class="flex-table-cell">.*?<\/td>/,
        cells.map((value, i) => `<td class="flex-table-cell" data-flex-sort-input="cell_${id}_${i}" data-flex-sort-value="${value}"><input name="cell_${id}_${i}" value="${value}"></td>`).join('')));
    const headings = kinds.map(kind => `<th><button type="button" class="flex-table-column-sort" data-flex-sort-type="${kind}" data-flex-sort-asc="Ascending" data-flex-sort-desc="Descending" hidden><span>↕</span></button></th>`).join('');
    const h = await harness(tableSet(content.join('')).replace('<table>', '<table class="flex-table-grid" data-flex-sort-locale="en" data-flex-sort-decimal=",">').replace('<th>Cell</th>', headings));
    const d = h.document;
    const buttons = d.querySelectorAll('.flex-table-column-sort');
    const order = () => d.querySelector('.flex-rows-order').value;
    const edit = d.querySelector('[name="cell_a_1"]');
    d.querySelector('[data-flex-row="e"] [data-flex-row-action="remove"]').click();
    assert.equal(order(), 'a,b,c,d,e');
    assert(!buttons[1].hidden);
    buttons[1].click();
    assert.equal(order(), 'c,d,a,b,e');
    assert.equal(buttons[1].closest('th').getAttribute('aria-sort'), 'ascending');
    buttons[1].click();
    assert.equal(order(), 'a,c,d,b,e');
    assert.equal(buttons[1].closest('th').getAttribute('aria-sort'), 'descending');
    edit.value = '-1';
    edit.dispatchEvent(new h.window.Event('input', {bubbles: true}));
    assert.equal(order(), 'a,c,d,b,e');
    assert(!buttons[1].closest('th').hasAttribute('aria-sort'));
    buttons[1].click();
    assert.equal(order(), 'a,c,d,b,e');
    buttons[2].click();
    assert.equal(order(), 'c,d,a,b,e');
    assert(!buttons[1].closest('th').hasAttribute('aria-sort'));
    buttons[0].click();
    assert.equal(order(), 'c,d,a,b,e');
    assert.equal(d.querySelectorAll('th[aria-sort]').length, 1);
    assert.equal(new h.window.FormData(d.querySelector('form')).get('cell_a_1'), '-1');
    assert.equal(h.calls.length, 0);
    h.close();
});
