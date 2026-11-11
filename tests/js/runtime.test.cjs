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
function row(id, text = id, extra = '') {
    return `<li class="Inputfield flex-row" data-flex-row="${id}" data-flex-type-label="Text" data-flex-label-format="{heading}" data-flex-label-values='{"heading":"${text}"}'>
    <div class="flex-row-header"><button type="button" class="flex-row-toggle"></button><span class="flex-row-title"></span><button type="button" class="flex-row-sort"></button>${action('duplicate')}${action('remove')}${action('visibility')}</div>
    <input class="flex-row-remove-value" name="remove_${id}" value="0"><input class="flex-row-hidden-value" name="hidden_${id}" value="0">
    <div class="flex-row-content"><div data-flex-label-property="heading"><input name="heading_${id}" value="${text}"></div>${extra}</div></li>`;
}
function set(content = row('a') + row('b'), attributes = '') {
    return `<div class="flex-rows" data-flex-set="blocks" data-flex-draft="draft" data-flex-field="content" data-flex-page-url="/edit/?id=42" data-flex-type="text" ${attributes}><ul class="flex-rows-items">${content}</ul><input class="flex-rows-order" name="order"><button type="button" class="flex-row-add">Add</button><p class="flex-rows-error"></p><p class="flex-rows-status"></p></div>`;
}
function tableRow(id, text = id) {
    return `<tr class="flex-row" data-flex-row="${id}" data-flex-type="row"><td class="flex-table-cell"><ul class="Inputfields"><li class="Inputfield"><input name="cell_${id}" value="${text}" required></li></ul></td><td class="flex-row-header flex-table-actions"><button type="button" class="flex-row-sort">Sort</button><button type="button" data-flex-row-action="duplicate" class="flex-row-action">Duplicate</button><button type="button" data-flex-row-action="remove" class="flex-row-action" data-flex-remove-label="Remove" data-flex-undo-label="Undo">Remove</button><button type="button" data-flex-row-action="visibility" class="flex-row-action" data-flex-hide-label="Hide" data-flex-show-label="Show"><i></i></button><span class="flex-row-transport"><input class="flex-row-remove-value" value="0"><input class="flex-row-hidden-value" value="0"></span></td></tr>`;
}
function tableSet(content = tableRow('a') + tableRow('b')) {
    return set('').replace('class="flex-rows"', 'class="flex-rows flex-table"').replace('<ul class="flex-rows-items"></ul>', `<div class="flex-table-scroll"><table><thead><tr><th>Cell</th><th>Actions</th></tr></thead><tbody class="flex-rows-items">${content}</tbody></table></div>`);
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
    handle.dispatchEvent(new h.window.KeyboardEvent('keydown', {key:'End', bubbles:true})); await tick();
    assert.equal(d.querySelector('.flex-rows-order').value, 'b,a'); assert.equal(d.activeElement, handle);
    a.querySelector('[data-flex-row-action="visibility"]').click(); assert.equal(a.querySelector('.flex-row-hidden-value').value, '1');
    a.querySelector('[data-flex-row-action="remove"]').click();
    assert(a.querySelector('[name="cell_a"]').disabled); assert.equal(a.querySelector('.flex-row-remove-mirror').value, 'a');
    assert.equal(a.querySelectorAll(':scope > input').length, 0, 'transport remains valid table markup');
    a.querySelector('[data-flex-row-action="remove"]').click(); assert(!a.querySelector('[name="cell_a"]').disabled); h.close();
});
test('AJAX table duplication includes unsaved cells and inserts a native table row', async () => {
    const h = await harness(tableSet(tableRow('a')), {response:async () => ({ok:true,json:async () => ({html:tableRow('copy','Copied')})})});
    const d = h.document; d.querySelector('[name="cell_a"]').value = 'Unsaved cell';
    d.querySelector('[data-flex-row-action="duplicate"]').click(); await tick();
    assert.equal(h.calls[0].body.get('cell_a'), 'Unsaved cell'); assert.equal(d.querySelector('tbody').children.length, 2);
    assert.equal(d.querySelector('tbody').lastElementChild.tagName, 'TR'); assert.equal(d.querySelector('.flex-rows-order').value, 'a,copy'); h.close();
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
    const actionButton = d.querySelector('[data-flex-row-action="duplicate"]');
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
test('nested labels retain original sibling values and parent breadcrumbs', async () => {
    const nested = set(row('child', 'Child')).replace('data-flex-set="blocks"', 'data-flex-set="nested"');
    const h = await harness(set(row('parent', 'Parent', nested)));
    const child = h.document.querySelector('[data-flex-row="child"]'); child.setAttribute('data-flex-label-format', '{@path} / {heading}');
    h.flex.test.initRows(h.document);
    assert.equal(child.querySelector('.flex-row-title').textContent, 'Text / Child'); h.close();
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
test('native add uses the selected type and appends a new Row', async () => {
    const markup=set(row('a')).replace('data-flex-type="text"', '').replace('<button type="button" class="flex-row-add">', '<div class="flex-row-type-control"><select class="flex-row-type"><option value="image" selected>Image</option></select></div><button type="button" class="flex-row-add">');
    const h=await harness(markup); h.document.querySelector('.flex-row-add').click(); await tick();
    assert.equal(h.calls[0].body.get('flex_type'),'image'); assert.equal(h.calls[0].body.get('flex_action'),'add'); assert.equal(h.document.querySelector('.flex-rows-order').value,'a,new'); h.close();
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
