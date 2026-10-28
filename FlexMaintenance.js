/* Explicit field maintenance; stored values are always rendered as text. */
(function() {
    'use strict';
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.FlexMaintenance').forEach(function(root) {
            const messages = JSON.parse(root.dataset.messages);
            let batches = [], inventory = [], selection = [], active = null, running = false;
            function show(button, visible) {
                button.hidden = !visible;
                button.disabled = !visible;
            }
            async function request(action, payload) {
                const body = new FormData(), csrf = root.closest('form').querySelector('input._post_token');
                if(!csrf) throw new Error(messages.csrf);
                body.set(csrf.name, csrf.value);
                body.set('flexMaintenanceAction', action);
                body.set('flexMaintenanceField', root.dataset.field);
                body.set('flexMaintenancePayload', JSON.stringify(payload));
                const response = await fetch(window.location.href, {method: 'POST', body: body, credentials: 'same-origin'});
                const result = await response.json();
                if(!response.ok || result.error) throw new Error(result.error || response.statusText);
                return result;
            }
            function table(headers, rows) {
                const grid = document.createElement('table');
                grid.className = 'AdminDataTable uk-table uk-table-striped';
                const head = grid.createTHead().insertRow(), body = grid.createTBody();
                headers.forEach(function(text) {
                    const cell = document.createElement('th'); cell.textContent = text; head.append(cell);
                });
                rows.forEach(function(values) {
                    const row = body.insertRow();
                    values.forEach(function(value) {
                        const cell = row.insertCell();
                        if(value instanceof Node) cell.append(value);
                        else cell.textContent = String(value);
                    });
                });
                return grid;
            }
            function display(results, report, progress) {
                const total = {checked: 0, changed: 0, wouldChange: 0, issues: [], renames: [], properties: Object.create(null)};
                results.forEach(function(batch) {
                    ['checked', 'changed', 'wouldChange'].forEach(key => total[key] += batch[key]);
                    total.issues.push(...batch.conflicts, ...batch.errors);
                    total.renames.push(...batch.renames);
                    Object.values(batch.properties).forEach(function(item) {
                        const entry = total.properties[item.path] || (total.properties[item.path] = {pageCount: 0, media: 0, pageIds: [], locations: []});
                        entry.pageCount += item.pageCount; entry.media += item.media;
                        entry.pageIds.push(...item.pageIds); entry.locations.push(...item.locations);
                    });
                });
                report.replaceChildren();
                if(total.renames.length) report.append(table([messages.from, messages.to, messages.pageIds], total.renames.map(item => [item.from, item.to, item.pageId])));
                if(total.issues.length) {
                    const heading = document.createElement('h4'); heading.textContent = messages.issues;
                    report.append(heading, table([messages.pageIds, messages.path, messages.message], total.issues.map(item => [item.pageId, item.path, item.message])));
                }
                progress.textContent = ['checked', 'changed', 'wouldChange'].map(key => messages[key] + ': ' + total[key]).join(' · ');
                if(total.issues.length) progress.textContent += ' ' + messages.retry;
                return total;
            }
            root.addEventListener('click', async function(event) {
                const button = event.target.closest('[data-flex-maintenance]');
                if(!button || button.disabled || running) return;
                event.preventDefault();
                const task = button.closest('.FlexMaintenanceTask'), action = button.dataset.flexMaintenance;
                const report = task.querySelector('.FlexMaintenanceReport'), progress = task.querySelector('.FlexMaintenanceProgress');
                const paths = task.querySelector('.FlexMaintenancePaths');
                if(task !== active || action === 'rename' || action === 'orphans') {
                    batches = []; inventory = []; selection = []; active = task;
                    root.querySelectorAll('[data-flex-maintenance="cleanup"], [data-flex-maintenance^="apply"]').forEach(item => show(item, false));
                    root.querySelectorAll('.FlexMaintenancePaths, .FlexMaintenanceReport, .FlexMaintenanceProgress').forEach(item => item.replaceChildren());
                }
                const controls = Array.from(root.querySelectorAll('button, input'));
                const disabled = controls.map(item => item.disabled);
                running = true; root.setAttribute('aria-busy', 'true'); controls.forEach(item => item.disabled = true);
                let canApply = false, canPreview = false;
                try {
                    if(action === 'rename' || action === 'orphans') {
                        let after = 0;
                        while(true) {
                            const batch = await request(action, {after: after});
                            batches.push(batch); display(batches, report, progress);
                            if(batch.complete) break;
                            after = batch.lastPageId;
                        }
                        const total = display(batches, report, progress);
                        if(action === 'rename') {
                            canApply = batches.some(batch => Object.keys(batch.pages).length && batch.wouldChange > 0);
                            if(!total.renames.length && !total.issues.length) progress.textContent += ' ' + messages.noRenames;
                        } else {
                            inventory = batches;
                            const rows = Object.entries(total.properties).map(function([path, item]) {
                                const label = document.createElement('label'), check = document.createElement('input');
                                check.type = 'checkbox'; check.value = path;
                                label.append(check, document.createTextNode(' ' + path));
                                const details = document.createElement('details'), summary = document.createElement('summary');
                                summary.textContent = messages.address;
                                const addresses = document.createElement('pre');
                                addresses.textContent = item.locations.map(location => location.pageId + ': ' + location.address).join('\n');
                                details.append(summary, addresses);
                                return [label, item.pageCount, item.media, item.pageIds.join(', '), details];
                            });
                            if(rows.length) paths.append(table([messages.path, messages.pageCount, messages.media, messages.pageIds, messages.address], rows));
                            else if(!total.issues.length) progress.textContent += ' ' + messages.noOrphans;
                        }
                    } else if(action === 'cleanup') {
                        selection = Array.from(paths.querySelectorAll('input:checked')).map(item => item.value);
                        if(!selection.length) throw new Error(messages.select);
                        const previews = [];
                        for(const batch of inventory) previews.push(await request('cleanup', {paths: selection, preview: batch}));
                        batches = previews;
                        const total = display(batches, report, progress);
                        canApply = batches.some(batch => Object.keys(batch.pages).length && batch.wouldChange > 0);
                        canPreview = true;
                        if(!total.wouldChange && !total.issues.length) progress.textContent += ' ' + messages.noChanges;
                    } else if(action === 'applyRename' || action === 'applyCleanup') {
                        const results = [];
                        for(const batch of batches) {
                            results.push(await request(action, {paths: selection, preview: batch}));
                            display(results, report, progress);
                        }
                        const total = display(results, report, progress);
                        if(!total.issues.length) progress.textContent += ' ' + (action === 'applyRename' ? messages.renamed : messages.cleaned);
                        batches = []; inventory = []; selection = [];
                        if(paths) paths.replaceChildren();
                    }
                } catch(error) {
                    progress.textContent += ' ' + error.message + ' ' + messages.retry;
                    batches = []; inventory = []; selection = [];
                } finally {
                    running = false; root.removeAttribute('aria-busy');
                    controls.forEach((item, index) => item.disabled = disabled[index]);
                    const apply = task.querySelector('[data-flex-maintenance^="apply"]');
                    show(apply, canApply);
                    const preview = task.querySelector('[data-flex-maintenance="cleanup"]');
                    if(preview) show(preview, canPreview);
                }
            });
            root.addEventListener('change', function(event) {
                if(!event.target.matches('.FlexMaintenancePaths input') || running) return;
                const task = event.target.closest('.FlexMaintenanceTask');
                batches = []; selection = [];
                show(task.querySelector('[data-flex-maintenance="applyCleanup"]'), false);
                show(task.querySelector('[data-flex-maintenance="cleanup"]'), inventory.length > 0 && !!task.querySelector('input:checked'));
                task.querySelector('.FlexMaintenanceReport').replaceChildren();
                task.querySelector('.FlexMaintenanceProgress').textContent = '';
            });
        });
    });
})();
