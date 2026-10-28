/* Explicit field maintenance. All visible values use textContent, never stored HTML. */
(function() {
	'use strict';
	document.addEventListener('DOMContentLoaded', function() {
		document.querySelectorAll('.FlexMaintenance').forEach(function(root) {
			let batches = [], operation = '', selection = [], running = false;
			const messages = JSON.parse(root.dataset.messages);
			const report = root.querySelector('.FlexMaintenanceReport');
			const progress = root.querySelector('.FlexMaintenanceProgress');
			const paths = root.querySelector('.FlexMaintenancePaths');
			const cleanup = root.querySelector('[data-flex-maintenance="cleanup"]');
			const apply = root.querySelector('[data-flex-maintenance="apply"]');
			const buttons = Array.from(root.querySelectorAll('button'));
			async function request(action, payload) {
				const body = new FormData();
				const csrf = root.closest('form').querySelector('input._post_token');
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
			function display(results) {
				const total = {checked: 0, changed: 0, unchanged: 0, wouldChange: 0, conflicts: [], errors: [], retryPageIds: [], renames: [], properties: Object.create(null)};
				results.forEach(function(batch) {
					['checked', 'changed', 'unchanged', 'wouldChange'].forEach(key => total[key] += batch[key]);
					['conflicts', 'errors', 'retryPageIds', 'renames'].forEach(key => total[key].push(...batch[key]));
					Object.values(batch.properties).forEach(function(item) {
						const aggregate = total.properties[item.path] || (total.properties[item.path] = {pageCount: 0, media: 0, pageIds: [], locations: []});
						aggregate.pageCount += item.pageCount; aggregate.media += item.media;
						aggregate.pageIds.push(...item.pageIds); aggregate.locations.push(...item.locations);
					});
				});
				report.replaceChildren();
				function table(title, headers, rows) {
					if(!rows.length) return;
					const heading = document.createElement('h3'); heading.textContent = title;
					const grid = document.createElement('table'); grid.className = 'AdminDataTable uk-table uk-table-striped';
					const head = document.createElement('thead'), body = document.createElement('tbody'), tr = document.createElement('tr');
					headers.forEach(function(text) { const cell = document.createElement('th'); cell.textContent = text; tr.append(cell); });
					head.append(tr); grid.append(head, body);
					rows.forEach(function(row) {
						const tr = document.createElement('tr');
						row.forEach(function(text) { const cell = document.createElement('td'); cell.textContent = String(text); tr.append(cell); });
						body.append(tr);
					});
					report.append(heading, grid);
				}
				table(messages.renames, [messages.from, messages.to, messages.pageIds], total.renames.map(item => [item.from, item.to, item.pageId]));
				table(messages.orphans, [messages.path, messages.pageCount, messages.media, messages.pageIds, messages.address], Object.entries(total.properties).map(([path, item]) => [path, item.pageCount, item.media, item.pageIds.join(', '), item.locations.map(location => location.pageId + ': ' + location.address).join(', ')]));
				table(messages.issues, [messages.pageIds, messages.path, messages.message], total.conflicts.concat(total.errors).map(item => [item.pageId, item.path, item.message]));
				if(total.retryPageIds.length) { const retry = document.createElement('p'); retry.textContent = messages.retry + ' ' + total.retryPageIds.join(', '); report.append(retry); }
				progress.textContent = ['checked', 'changed', 'unchanged', 'wouldChange'].map(key => messages[key] + ': ' + total[key]).join(' · ');
				return total;
			}
			root.addEventListener('click', async function(event) {
				const button = event.target.closest('[data-flex-maintenance]');
				if(!button || running) return;
				event.preventDefault();
				const action = button.dataset.flexMaintenance;
				running = true; buttons.forEach(item => item.disabled = true);
				paths.querySelectorAll('input').forEach(item => item.disabled = true);
				let valid = false;
				try {
					if(action === 'rename' || action === 'orphans') {
						batches = []; operation = action; paths.replaceChildren();
						let after = 0;
						while(true) {
							const batch = await request(action, {after: after});
							batches.push(batch); display(batches);
							if(batch.complete) break;
							after = batch.lastPageId;
						}
						const summary = display(batches);
						progress.textContent += ' ' + (action === 'rename' && !summary.renames.length && batches.every(batch => batch.success) ? messages.noRenames : messages.previewed);
						if(action === 'orphans') {
							const total = summary;
							Object.keys(total.properties).forEach(function(path) {
								const label = document.createElement('label'), check = document.createElement('input');
								check.type = 'checkbox'; check.value = path;
								label.append(check, document.createTextNode(' ' + path + ' (' + total.properties[path].pageCount + ') '));
								paths.append(label, document.createElement('br'));
							});
						}
					} else if(action === 'cleanup') {
						selection = Array.from(paths.querySelectorAll('input:checked')).map(item => item.value);
						if(!selection.length) throw new Error(messages.select);
						const previews = [];
						for(const batch of batches) previews.push(await request('cleanup', {paths: selection, preview: batch}));
						batches = previews; operation = 'cleanup'; display(batches); progress.textContent += ' ' + messages.previewed;
					} else if(action === 'apply') {
						const results = [];
						for(const batch of batches) {
							results.push(await request(operation === 'rename' ? 'applyRename' : 'applyCleanup', {paths: selection, preview: batch}));
							display(results);
						}
						const total = display(results);
						progress.textContent += total.errors.length || total.conflicts.length ? ' ' + messages.retry : operation === 'rename' ? ' ' + messages.renamed : ' ' + messages.cleaned;
						batches = []; operation = '';
					}
					valid = true;
				} catch(error) {
					progress.textContent = error.message + ' ' + messages.retry;
					batches = []; operation = '';
				} finally {
					running = false;
					buttons.forEach(item => item.disabled = false);
					paths.querySelectorAll('input').forEach(item => item.disabled = false);
					cleanup.disabled = !valid || operation !== 'orphans';
					apply.disabled = !valid || !['rename', 'cleanup'].includes(operation) || !batches.some(batch => Object.keys(batch.pages).length && batch.wouldChange > 0);
				}
			});
			paths.addEventListener('change', function() {
				if(operation === 'cleanup') { apply.disabled = true; operation = ''; batches = []; }
			});
		});
	});
})();
