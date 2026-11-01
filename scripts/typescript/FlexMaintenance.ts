/* Explicit field maintenance; stored values are always rendered as text. */
(function() {
    interface Property { path?: string; pageCount: number; media: number; pageIds: number[]; locations: {pageId: number; address: string}[]; }
    interface Issue {pageId: number; path: string; message: string;}
    interface Rename {pageId: number; from: string; to: string;}
    interface Batch {checked: number; changed: number; wouldChange: number; conflicts: Issue[]; errors: Issue[]; renames: Rename[]; properties: Record<string, Property & {path: string}>; pages: Record<string, unknown>; complete: boolean; lastPageId: number;}
    interface Total {checked: number; changed: number; wouldChange: number; issues: Issue[]; renames: Rename[]; properties: Record<string, Property>;}
    function find<T extends HTMLElement = HTMLElement>(scope: ParentNode, selector: string): T {
        const element = scope.querySelector<T>(selector);
        if(!element) throw new Error('Missing maintenance element: ' + selector);
        return element;
    }
    const initialized = new WeakSet<HTMLElement>();
    function initMaintenance(scope: ParentNode = document): void {
        const roots = Array.from(scope.querySelectorAll<HTMLElement>('.FlexMaintenance'));
        if(scope instanceof HTMLElement && scope.matches('.FlexMaintenance')) roots.unshift(scope);
        roots.forEach(function(root) {
            if(initialized.has(root)) return;
            initialized.add(root);
            const messages = JSON.parse(root.dataset.messages || '{}') as Record<string, string>;
            let batches: Batch[] = [], inventory: Batch[] = [], selection: string[] = [], active: HTMLElement | null = null, running = false;
            function show(button: HTMLButtonElement | null, visible: boolean) {
                if(!button) return;
                button.hidden = !visible;
                button.disabled = !visible;
            }
            async function request(action: string, payload: object): Promise<Batch> {
                const body = new FormData(), csrf = root.closest('form')?.querySelector<HTMLInputElement>('input._post_token');
                if(!csrf) throw new Error(messages.csrf);
                body.set(csrf.name, csrf.value);
                body.set('flexMaintenanceAction', action);
                body.set('flexMaintenanceField', root.dataset.field || '');
                body.set('flexMaintenancePayload', JSON.stringify(payload));
                const response = await fetch(window.location.href, {method: 'POST', body: body, credentials: 'same-origin'});
                const result: Batch & {error?: string} = await response.json();
                if(!response.ok || result.error) throw new Error(result.error || response.statusText);
                return result;
            }
            function table(headers: string[], rows: (Node | string | number)[][]) {
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
            function display(results: Batch[], report: HTMLElement, progress: HTMLElement) {
                const total: Total = {checked: 0, changed: 0, wouldChange: 0, issues: [], renames: [], properties: Object.create(null)};
                results.forEach(function(batch) {
                    (['checked', 'changed', 'wouldChange'] as const).forEach(key => total[key] += batch[key]);
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
                progress.textContent = (['checked', 'changed', 'wouldChange'] as const).map(key => messages[key] + ': ' + total[key]).join(' · ');
                if(total.issues.length) progress.textContent += ' ' + messages.retry;
                return total;
            }
            root.addEventListener('click', async function(event) {
                const button = event.target instanceof Element ? event.target.closest<HTMLButtonElement>('[data-flex-maintenance]') : null;
                if(!button || button.disabled || running) return;
                event.preventDefault();
                const task = button.closest<HTMLElement>('.FlexMaintenanceTask')!, action = button.dataset.flexMaintenance!;
                const report = find(task, '.FlexMaintenanceReport'), progress = find(task, '.FlexMaintenanceProgress');
                const paths = task.querySelector<HTMLElement>('.FlexMaintenancePaths');
                if(task !== active || action === 'rename' || action === 'orphans') {
                    batches = []; inventory = []; selection = []; active = task;
                    root.querySelectorAll<HTMLButtonElement>('[data-flex-maintenance="cleanup"], [data-flex-maintenance^="apply"]').forEach(item => show(item, false));
                    root.querySelectorAll('.FlexMaintenancePaths, .FlexMaintenanceReport, .FlexMaintenanceProgress').forEach(item => item.replaceChildren());
                }
                const controls = Array.from(root.querySelectorAll<HTMLButtonElement | HTMLInputElement>('button, input'));
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
                            if(rows.length) paths!.append(table([messages.path, messages.pageCount, messages.media, messages.pageIds, messages.address], rows));
                            else if(!total.issues.length) progress.textContent += ' ' + messages.noOrphans;
                        }
                    } else if(action === 'cleanup') {
                        selection = Array.from(paths!.querySelectorAll<HTMLInputElement>('input:checked')).map(item => item.value);
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
                    progress.textContent += ' ' + (error instanceof Error ? error.message : String(error)) + ' ' + messages.retry;
                    batches = []; inventory = []; selection = [];
                } finally {
                    running = false; root.removeAttribute('aria-busy');
                    controls.forEach((item, index) => item.disabled = disabled[index]);
                    const apply = task.querySelector<HTMLButtonElement>('[data-flex-maintenance^="apply"]');
                    show(apply, canApply);
                    const preview = task.querySelector<HTMLButtonElement>('[data-flex-maintenance="cleanup"]');
                    if(preview) show(preview, canPreview);
                }
            });
            root.addEventListener('change', function(event) {
                if(!(event.target instanceof Element) || !event.target.matches('.FlexMaintenancePaths input') || running) return;
                const task = event.target.closest<HTMLElement>('.FlexMaintenanceTask')!;
                batches = []; selection = [];
                show(task.querySelector<HTMLButtonElement>('[data-flex-maintenance="applyCleanup"]'), false);
                show(task.querySelector<HTMLButtonElement>('[data-flex-maintenance="cleanup"]'), inventory.length > 0 && !!task.querySelector('input:checked'));
                find(task, '.FlexMaintenanceReport').replaceChildren();
                find(task, '.FlexMaintenanceProgress').textContent = '';
            });
        });
    }
    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => initMaintenance());
    else initMaintenance();
    document.addEventListener('reloaded', event => {
        if(event.target instanceof HTMLElement) initMaintenance(event.target);
    });
    document.addEventListener('pw-form-saved', () => initMaintenance());
})();
