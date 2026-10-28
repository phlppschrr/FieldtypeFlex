(function($) {
    'use strict';
    function preserveSelectorPaths() {
        var selector = window.InputfieldSelector;
        if(!selector || selector.flexPathsPreserved) return;
        var change = selector.changeAny;
        // Older core assumes every multi-dot path ends in a synthetic `.id`.
        // Keep the native builder, compensating only while that trimming exists.
        if(!/\.substring\(0,\s*[\w$.]+\.lastIndexOf\(["']\.["']\)\)/.test(String(change))) return;
        var active = false;
        selector.changeAny = function($item) {
            if(active) return change.apply(this, arguments);
            var fields = ProcessWire.config.FieldtypeFlexSelectorFields || [], adjusted = [];
            $item.closest('.InputfieldSelector').find('.select-subfield option:selected').each(function() {
                var path = this.value.split('.');
                if(path.length !== 3 || fields.indexOf(path[0]) < 0) return;
                adjusted.push([this, this.value]);
                this.value += '.__flex_terminal';
            });
            active = true;
            try {
                return change.apply(this, arguments);
            } finally {
                adjusted.forEach(function(item) { item[0].value = item[1]; });
                active = false;
            }
        };
        selector.flexPathsPreserved = true;
    }
    preserveSelectorPaths();
    $(preserveSelectorPaths);
    function message(key, fallback) {
        var config = window.ProcessWire && ProcessWire.config && ProcessWire.config.FieldtypeFlex;
        return config && config[key] ? config[key] : fallback;
    }
    function namedMessage(key, fallback, name) {
        return message(key, fallback).replace('{name}', name);
    }
    function rows($set) {
        return $set.children('.flex-rows-items').children('.flex-row[data-flex-row]');
    }
    function activeRows($set) {
        return rows($set).not('.flex-row-remove-pending');
    }
    function removalState($row, removed) {
        var $body = $row.children('.flex-row-content');
        $row.toggleClass('flex-row-remove-pending', removed);
        $row.children('.flex-row-header').toggleClass('ui-state-error', removed);
        $row.children('.flex-row-remove-value').val(removed ? '1' : '0');
        $row.children('.flex-row-remove-mirror').remove();
        if(removed) {
            // Keep successful native transport values for recovery/undo, while excluding
            // removed controls from browser validation. Originals remain in the DOM.
            $body.find(':input').serializeArray().forEach(function(item) {
                $('<input type="hidden" class="flex-row-remove-mirror">').attr('name', item.name).val(item.value).appendTo($row);
            });
            $body.find(':input').each(function() {
                if($(this).data('flexRemoveDisabled') === undefined) $(this).data('flexRemoveDisabled', this.disabled);
                this.disabled = true;
            });
        } else {
            $body.find(':input').each(function() {
                var before = $(this).data('flexRemoveDisabled');
                if(before !== undefined) { this.disabled = before; $(this).removeData('flexRemoveDisabled'); }
            });
        }
        var $button = $row.children('.flex-row-header').find('[data-flex-row-action="remove"]');
        var label = $button.attr(removed ? 'data-flex-undo-label' : 'data-flex-remove-label');
        $button.attr({'aria-pressed': removed ? 'true' : 'false', 'aria-label': label, title: label});
    }
    function titleParts(format, values, number, type, path, cleanup) {
        var parts = [];
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
        var separator = null, omitted = false;
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
    function rowTitle($row, index, path) {
        var format = $row.attr('data-flex-label-format') || '';
        var type = $row.attr('data-flex-type-label') || '';
        var parts = titleParts(format, $row.data('flexLabelValues') || {}, index + 1, type, path);
        if(!parts.some(function(part) { return part.text.trim() !== ''; })) {
            parts = [{text: (format ? '' : type + ' ') + '#' + (index + 1), badge: false}];
        }
        if(!format) parts.unshift({text: type, badge: true}, {text: ' ', badge: false});
        return parts;
    }
    function contextPath($row) {
        return $row.parents('.flex-row').get().reverse().map(function(ancestor) {
            return $(ancestor).attr('data-flex-type-label') || '';
        }).join(' › ');
    }
    function updateLabels($set) {
        rows($set).each(function(index) {
            var $row = $(this), $title = $row.children('.flex-row-header').find('.flex-row-title').empty();
            rowTitle($row, index, contextPath($row)).forEach(function(part) {
                if(!part.text) return;
                if(part.badge || part.muted) {
                    $('<span>').toggleClass('flex-row-type-badge', !!part.badge)
                        .toggleClass('flex-row-deemphasized', !!part.muted).text(part.text).appendTo($title);
                } else $title.append(document.createTextNode(part.text));
            });
            // Parent edits and reordering also update every descendant breadcrumb.
            $row.children('.flex-row-content').find('.flex-rows').filter(function() {
                return $(this).closest('.flex-row')[0] === $row[0];
            }).each(function() { updateLabels($(this)); });
        });
    }
    function treeBusy($set) {
        return !!$set.closest('.InputfieldFlex').data('flexTreeBusy');
    }
    function setTreeBusy($set, busy) {
        var $tree = $set.closest('.InputfieldFlex');
        $tree.data('flexTreeBusy', busy).attr('aria-busy', busy ? 'true' : 'false');
        $tree.find('.flex-rows').each(function() { updateActions($(this)); });
        var $form = $tree.closest('form');
        if(!busy && !$form.find('.InputfieldFlex[aria-busy="true"], .flex-row[data-flex-load-failed="1"]').length) $form.children('.flex-form-request-error').remove();
    }
    function updateActions($set) {
        var count = activeRows($set).length, min = Number($set.attr('data-flex-min')) || 0;
        var max = Number($set.attr('data-flex-max')) || 0, busy = treeBusy($set);
        $set.children('.flex-row-add').prop('disabled', busy || (max > 0 && count >= max));
        rows($set).each(function() {
            var $header = $(this).children('.flex-row-header');
            $header.find('.flex-row-toggle').prop('disabled', busy);
            $header.find('[data-flex-row-action="duplicate"]').prop('disabled', busy || (max > 0 && count >= max));
            $header.find('[data-flex-row-action="remove"]').prop('disabled', busy || (!$(this).hasClass('flex-row-remove-pending') && count <= min));
            $header.find('.flex-row-sort').prop('disabled', busy || count < 2);
        });
        var $list = $set.children('.flex-rows-items');
        if($.fn.sortable && $list.hasClass('ui-sortable')) $list.sortable('option', 'disabled', busy || count < 2);
    }
    function order($set) {
        $set.children('.flex-rows-order').val(rows($set).map(function() { return $(this).attr('data-flex-row'); }).get().join(',')).trigger('change');
        $set.closest('.InputfieldFlex').addClass('InputfieldStateChanged');
        updateLabels($set);
        updateActions($set);
    }
    function csrf() {
        var $token = $('#ProcessPageEdit input._post_token');
        return $token.length ? {name: $token.attr('name'), value: $token.val()} : null;
    }
    function teardown($row) {
        if(!window.tinymce) return;
        $row.find('textarea.InputfieldTinyMCEEditor').each(function() {
            var editor = window.tinymce.get(this.id);
            if(editor) {
                editor.save();
                // remove() also unregisters the editor, allowing the moved textarea to initialize again.
                editor.remove();
            }
            $(this).removeClass('InputfieldTinyMCELoaded InputfieldTinyMCELazy');
        });
    }
    function setup($row) {
        if(window.Inputfields) window.Inputfields.init($row);
        else if(window.InputfieldsInit) window.InputfieldsInit($row);
        $row.find('.Inputfield').addBack('.Inputfield').trigger('reloaded');
    }
    function syncToggle($row) {
        $row.children('.flex-row-header').find('.flex-row-toggle').attr('aria-expanded', !$row.hasClass('InputfieldStateCollapsed'));
    }
    function openRow($row, open) {
        if(window.Inputfields && window.Inputfields.toggle) {
            window.Inputfields.toggle($row, open, 0);
        } else {
            $row.toggleClass('InputfieldStateCollapsed', !open).trigger(open ? 'opened' : 'closed');
        }
        syncToggle($row);
    }
    function loadRow($row) {
        if($row.attr('data-flex-loaded') !== '0') return Promise.resolve(true);
        if($row.data('flexLoadPromise')) return $row.data('flexLoadPromise');
        var $set = $row.closest('.flex-rows'), $body = $row.children('.flex-row-content');
        if(treeBusy($set)) return Promise.resolve(false);
        var operation = (async function() {
            setTreeBusy($set, true);
            $body.find('.flex-row-load-status').text(message('loading', 'Loading…'));
            $body.find('.flex-row-retry').prop('hidden', true);
            try {
                var data = new URLSearchParams();
                data.set('flex_action', 'row-render');
                data.set('flex_set', $set.attr('data-flex-set'));
                data.set('flex_source', $row.attr('data-flex-row'));
                data.set('flex_complete', $set.attr('data-flex-draft'));
                var token = csrf();
                if(token) data.set(token.name, token.value);
                var response = await fetch($set.attr('data-flex-page-url'), {method: 'POST', credentials: 'same-origin',
                    headers: {'X-Requested-With': 'XMLHttpRequest', 'X-FIELDNAME': $set.attr('data-flex-field'), 'X-FLEX-DRAFT': $set.attr('data-flex-draft')}, body: data});
                var result = await response.json();
                if(!response.ok || result.error) throw new Error(result.message || message('unableToLoad', 'Unable to load item'));
                if(!$row[0].isConnected) return false;
                var $new = $(result.html), $content = $new.children('.flex-row-content');
                if($new.attr('data-flex-loaded') !== '1' || !$content.length) throw new Error(message('unableToLoad', 'Unable to load item'));
                $body.empty().append($content.contents());
                $row.attr('data-flex-loaded', '1').removeAttr('data-flex-load-failed');
                setup($row);
                if($row.hasClass('flex-row-remove-pending')) removalState($row, true);
                return true;
            } catch(error) {
                $row.attr('data-flex-load-failed', '1');
                $body.find('.flex-row-load-status').text(error instanceof SyntaxError ? message('unableToLoad', 'Unable to load item') : error.message);
                $body.find('.flex-row-retry').prop('hidden', false);
                return false;
            } finally {
                $row.removeData('flexLoadPromise');
                setTreeBusy($set, false);
            }
        })();
        $row.data('flexLoadPromise', operation);
        return operation;
    }
    function moved($set, $row) {
        var position = rows($set).index($row) + 1;
        $set.children('.flex-rows-status').text(message('itemMoved', 'Item moved to position {position} of {count}')
            .replace('{position}', position).replace('{count}', rows($set).length));
    }
    function initRows($context) {
        $context.find('.flex-rows').addBack('.flex-rows').each(function() {
            var $set = $(this), $list = $set.children('.flex-rows-items');
            rows($set).each(function() {
                var $row = $(this);
                if($row.hasClass('flex-row-remove-pending') && !$row.data('flexRemovalReady')) {
                    removalState($row, true);
                    $row.data('flexRemovalReady', true);
                }
                if(!$row.hasClass('flex-row-remove-pending') && $row.find('.InputfieldStateError').length) openRow($row, true);
                syncToggle($row);
            });
            if($.fn.sortable && !$list.hasClass('ui-sortable')) {
                // Keep inner-row sort events away from any containing native Repeater.
                $list.on('mousedown.flexRows', function(event) {
                    if($(event.target).closest('.flex-rows-items')[0] === this) event.stopPropagation();
                });
                $list.on('sortstart.flexRows sortstop.flexRows sortupdate.flexRows', function(event) {
                    if(event.target === this) event.stopPropagation();
                }).sortable({
                    items: '> .flex-row[data-flex-row]',
                    handle: '.flex-row-sort',
                    cancel: ':input:not(.flex-row-sort), a',
                    axis: 'y',
                    distance: 5,
                    tolerance: 'pointer',
                    placeholder: 'flex-row-placeholder',
                    start: function(event, ui) {
                        ui.placeholder.height(ui.item.outerHeight());
                        teardown(ui.item);
                    },
                    stop: function(event, ui) {
                        order($set);
                        setup(ui.item);
                        moved($set, ui.item);
                    }
                });
            }
            updateLabels($set);
            updateActions($set);
        });
    }
    document.addEventListener('submit', function(event) {
        var form = event.target;
        if(!form.matches('form[data-flex-max-input-vars]')) return;
        var $pending = $(form).find('.InputfieldFlex[aria-busy="true"], .flex-row[data-flex-load-failed="1"]').filter(function() { return !$(this).closest('.flex-row-remove-pending').length; }).first();
        if($pending.length) {
            event.preventDefault();
            event.stopImmediatePropagation();
            var $error = $(form).children('.flex-form-limit-error');
            if(!$error.length) $error = $('<p class="flex-form-limit-error notice-error" role="alert" tabindex="-1"></p>').prependTo(form);
            $error.addClass('flex-form-request-error').text($pending.is('.flex-row') ? message('unableToLoad', 'Unable to load item') : message('requestPending', 'Wait for the current request to finish before saving.')).trigger('focus');
            return;
        }
        var max = Number(form.getAttribute('data-flex-max-input-vars'));
        if(max <= 0) return;
        var count = event.submitter && event.submitter.name && !event.submitter.disabled ? 1 : 0;
        for(var entry of new FormData(form).entries()) {
            if(typeof entry[1] === 'string') count++;
        }
        if(count <= max) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        var $error = $(form).children('.flex-form-limit-error');
        if(!$error.length) $error = $('<p class="flex-form-limit-error notice-error" role="alert" tabindex="-1"></p>').prependTo(form);
        $error.removeClass('flex-form-request-error').text(message('inputVarsExceeded', 'This form contains {count} input values, exceeding PHP max_input_vars={max}.')
            .replace('{count}', count).replace('{max}', max)).trigger('focus');
    }, true);
    $(function() { initRows($(document)); });
    $(document).on('reloaded.flexRows', '.Inputfield', function(event) { initRows($(event.target)); });
    $(document).on('opened.flexRows closed.flexRows', '.flex-row', function(event) {
        if(event.target !== this) return;
        syncToggle($(this));
        if(event.type === 'opened') loadRow($(this));
    });
    $(document).on('click', '.flex-row-retry', function(event) {
        event.preventDefault();
        loadRow($(this).closest('.flex-row'));
    });
    $(document).on('click', '.flex-row-toggle', function(event) {
        event.preventDefault();
        event.stopPropagation();
        var $row = $(this).closest('.flex-row');
        openRow($row, $row.hasClass('InputfieldStateCollapsed'));
    });
    $(document).on('keydown', '.flex-row-sort', function(event) {
        if(!['ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        event.stopPropagation();
        var $row = $(this).closest('.flex-row'), $set = $row.closest('.flex-rows');
        if(treeBusy($set)) return;
        var $target = event.key === 'ArrowUp' ? $row.prev('.flex-row') : $row.next('.flex-row');
        if(event.key === 'Home') $target = rows($set).first();
        if(event.key === 'End') $target = rows($set).last();
        if(!$target.length || $target.is($row)) return;
        teardown($row);
        if(event.key === 'ArrowUp' || event.key === 'Home') $row.insertBefore($target); else $row.insertAfter($target);
        order($set);
        setup($row);
        $row.children('.flex-row-header').find('.flex-row-sort').trigger('focus');
        moved($set, $row);
    });
    $(document).on('input change', '[data-flex-label-property] :input', function() {
        var $input = $(this), $property = $input.closest('[data-flex-label-property]');
        var $row = $property.closest('.flex-row'), $set = $row.closest('.flex-rows');
        if(!$row.length) return;
        var kind = $property.attr('data-flex-label-kind');
        // Descriptions, tags and Page-autocomplete search text are not the label value.
        if(kind === 'file' || kind === 'image' || (kind === 'page' && !$input.is('select'))) return;
        var value = $input.val();
        if($input.is('select')) value = $input.find('option:selected').map(function() { return $(this).text(); }).get().join(', ');
        else if($input.is(':checkbox, :radio')) {
            var options = $property.data('flexLabelOptions') || {};
            value = $property.find(':checked').map(function() {
                return Object.prototype.hasOwnProperty.call(options, this.value) ? options[this.value] : this.value;
            }).get().join(', ');
        }
        else if($input.is('[type="file"], [type="hidden"], button')) return;
        // Decode text entities without parsing image/script elements or loading their resources.
        var text = document.createElement('textarea');
        text.innerHTML = String(value || '').replace(/<[^>]*>/g, '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        value = text.value.replace(/\s+/g, ' ').trim().slice(0, 160);
        var values = $row.data('flexLabelValues') || {};
        values[$property.attr('data-flex-label-property')] = value;
        $row.data('flexLabelValues', values);
        updateLabels($set);
    });
    $(document).on('change', '.InputfieldTinyMCE[data-flex-label-property]', function(event) {
        if(event.target !== this || !window.tinymce) return;
        $(this).find('textarea').each(function() {
            var editor = window.tinymce.get(this.id);
            if(editor) {
                editor.save();
                $(this).trigger('input');
            }
        });
    });
    document.addEventListener('invalid', function(event) {
        $(event.target).parents('.flex-row').each(function() { openRow($(this), true); });
    }, true);
    $(document).on('dblclick', '[data-flex-row-action="remove"]', function(event) {
        event.preventDefault();
        event.stopPropagation();
        var $button = $(this), $set = $button.closest('.flex-rows');
        if($button.prop('disabled') || treeBusy($set)) return;
        // The two preceding clicks restore the original state, just like native Repeaters.
        var undo = $button.closest('.flex-row').hasClass('flex-row-remove-pending');
        function applyAll() {
            rows($set).each(function() {
                var $row = $(this);
                if($row.hasClass('flex-row-remove-pending') === undo) {
                    // Reuse single-row handling, including minItems and input recovery.
                    $row.children('.flex-row-header').find('[data-flex-row-action="remove"]').trigger('click');
                }
            });
        }
        if(undo) applyAll();
        else ProcessWire.confirm(message('removeAll', 'Remove all items?'), applyAll);
    });
    $(document).on('click', '.flex-row-add, .flex-row-action', async function(event) {
        event.preventDefault();
        event.stopPropagation();
        var $button = $(this), $set = $button.closest('.flex-rows'), $row = $button.closest('.flex-row');
        if($button.prop('disabled') || treeBusy($set)) return;
        var action = $button.hasClass('flex-row-add') ? 'add' : $button.attr('data-flex-row-action');
        var $error = $set.children('.flex-rows-error').text('');
        if(action === 'visibility') {
            var hidden = !$row.hasClass('flex-row-hidden');
            $row.toggleClass('flex-row-hidden', hidden);
            $row.children('.flex-row-hidden-value').val(hidden ? '1' : '0').trigger('change');
            var label = $button.attr(hidden ? 'data-flex-show-label' : 'data-flex-hide-label');
            $button.attr({'aria-pressed': hidden ? 'true' : 'false', 'aria-label': label, title: label});
            $button.find('i').toggleClass('fa-eye', !hidden).toggleClass('fa-eye-slash', hidden);
            return;
        }
        if(action === 'remove') {
            var removed = !$row.hasClass('flex-row-remove-pending');
            if(window.tinymce) window.tinymce.triggerSave();
            if(removed) openRow($row, false);
            removalState($row, removed);
            $row.data('flexRemovalReady', true);
            order($set);
            $button.trigger('focus');
            return;
        }
        if(action === 'duplicate' && $row.attr('data-flex-loaded') === '0' && !(await loadRow($row))) return;
        setTreeBusy($set, true);
        updateActions($set);
        try {
            if(window.tinymce) window.tinymce.triggerSave();
            var data = new URLSearchParams();
            data.set('flex_action', action); data.set('flex_set', $set.attr('data-flex-set'));
            data.set('flex_order', activeRows($set).map(function() { return $(this).attr('data-flex-row'); }).get().join(','));
            var activeOrders = {};
            $set.closest('.InputfieldFlex').find('.flex-rows').each(function() {
                var $current = $(this);
                if($current.closest('.flex-row-remove-pending').length) return;
                activeOrders[$current.attr('data-flex-set')] = activeRows($current).map(function() { return $(this).attr('data-flex-row'); }).get();
            });
            data.set('flex_orders', JSON.stringify(activeOrders));
            if(action === 'add') {
                var type = $set.attr('data-flex-type') || $set.find('> .flex-row-type-control .flex-row-type').val();
                data.set('flex_type', type);
            } else if(action === 'duplicate') {
                data.set('flex_source', $row.attr('data-flex-row'));
                // Ancestor/Page dependencies need the same live values as a full save.
                // Only the requested source row is applied by the duplicate handler.
                $row.closest('form').serializeArray().forEach(function(item) {
                    // ProcessPageEdit prioritizes POST id over the owner URL. Inside
                    // a Repeater the full form id belongs to the main editor Page.
                    if(item.name !== 'id') data.append(item.name, item.value);
                });
            }
            var token = csrf();
            if(token) data.set(token.name, token.value);
            data.set('flex_complete', $set.attr('data-flex-draft'));
            var response = await fetch($set.attr('data-flex-page-url'), {method: 'POST', credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest', 'X-FIELDNAME': $set.attr('data-flex-field'), 'X-FLEX-DRAFT': $set.attr('data-flex-draft')}, body: data});
            var result = await response.json();
            if(!response.ok || result.error) throw new Error(result.message || message('unableToAdd', 'Unable to add item'));
            if(!$set[0].isConnected) return;
            var $new = $(result.html);
            if(action === 'duplicate') $new.insertAfter($row); else $set.children('.flex-rows-items').append($new);
            order($set);
            setup($new);
            initRows($set);
            $new.children('.flex-row-header').find('.flex-row-toggle').trigger('focus');
            $(document).trigger('flexRowAdded', [$new]);
        } catch(error) { $error.text(error.message); }
        finally {
            setTreeBusy($set, false);
            updateActions($set);
        }
    });
    async function upload(input, files) {
        var $upload = $(input).closest('[data-flex-upload="1"]'), $field = $upload.closest('.Inputfield');
        var token = csrf();
        if(!files.length) return;
        var $tree = $upload.closest('.InputfieldFlex');
        if(treeBusy($upload)) { input.value = ''; return; }
        setTreeBusy($upload, true);
        input.disabled = true;
        var $status = $('<p class="flex-upload-status" role="status"></p>').appendTo($upload);
        try {
            for(var file of files) {
                $status.text(namedMessage('uploading', 'Uploading {name}…', file.name));
                var headers = {'X-Requested-With': 'XMLHttpRequest', 'X-FIELDNAME': $upload.attr('data-fieldname'),
                    'X-FLEX-DRAFT': $upload.attr('data-flex-draft'), 'X-FLEX-PATH': $upload.attr('data-flex-path'), 'X-FILENAME': encodeURIComponent(file.name), 'Content-Type': 'application/octet-stream'};
                if(token) headers['X-' + token.name] = token.value;
                var response = await fetch($upload.attr('data-posturl'), {method:'POST', credentials:'same-origin', headers:headers, body:file});
                var result = await response.json();
                if(!response.ok || result.error) throw new Error(result.message || message('unableToUpload', 'Unable to upload file'));
                if(!Array.isArray(result)) throw new Error(message('unableToUpload', 'Unable to upload file'));
                for(var item of result) {
                    if(item.error) throw new Error(item.message || message('unableToUpload', 'Unable to upload file'));
                    var $list = $field.find('.InputfieldFileList').first();
                    if(!$list.length) $list = $field.find('.InputfieldImageList').first();
                    if(!$list.length) {
                        $list = $('<ul></ul>').addClass($field.hasClass('InputfieldImage') ? 'InputfieldImageList gridImages ui-helper-clearfix' : 'InputfieldFileList ui-helper-clearfix');
                        $list.insertBefore($upload);
                        $field.find('.InputfieldFileListPlaceholder').remove();
                    }
                    if(Number($field.find('.InputfieldFileMaxFiles, .InputfieldImageMaxFiles').first().val()) === 1) $list.children('li').remove();
                    $list.append(item.markup);
                }
                $field.removeClass('InputfieldFileEmpty').addClass('InputfieldStateChanged');
                setup($field);
            }
            $status.remove();
        } catch(error) { $status.attr('role','alert').text(error.message); }
        finally { input.value = ''; input.disabled = false; setTreeBusy($upload, false); }
    }
    // Native File/Image listeners also handle change/drop when noAjax=1.
    // Intercept this one transport in capture phase, before those listeners.
    document.addEventListener('change', function(event) {
        if(!event.target.matches('input[type="file"]') || !event.target.closest('[data-flex-upload="1"]')) return;
        event.stopImmediatePropagation(); upload(event.target, Array.from(event.target.files || []));
    }, true);
    ['dragenter','dragover','dragleave','drop'].forEach(function(name) { document.addEventListener(name, function(event) {
        var field = event.target.closest('.Inputfield');
        if(!field || !event.dataTransfer || !Array.from(event.dataTransfer.types || []).includes('Files')) return;
        var input = $(field).find('[data-flex-upload="1"] input[type="file"]')[0];
        if(!input || $(input).closest('.Inputfield')[0] !== field) return;
        event.preventDefault(); event.stopImmediatePropagation();
        if(name === 'dragleave' && event.relatedTarget && field.contains(event.relatedTarget)) return;
        var active = name === 'dragenter' || name === 'dragover';
        $(field).toggleClass('pw-drag-in-file', active);
        $(field).children('.InputfieldContent').toggleClass('ui-state-hover', active);
        if(name === 'drop') upload(input, Array.from(event.dataTransfer.files));
    }, true); });
    $(document).on('flexMediaRefresh', async function(event, draft, path) {
        var $upload = $('[data-flex-upload="1"]').filter(function() { return $(this).attr('data-flex-draft') === draft && $(this).attr('data-flex-path') === path; }).first();
        var $field = $upload.closest('.Inputfield'), token = csrf();
        try {
            var data = new URLSearchParams({flex_action:'media-render', flex_path:path});
            if(token) data.set(token.name, token.value);
            data.set('flex_complete', draft);
            var response = await fetch($upload.attr('data-posturl'), {method:'POST', credentials:'same-origin',
                headers:{'X-Requested-With':'XMLHttpRequest','X-FIELDNAME':$upload.attr('data-fieldname'),'X-FLEX-DRAFT':draft}, body:data});
            var result = await response.json();
            if(!response.ok || result.error) throw new Error(result.message || message('unableToRefreshImage', 'Unable to refresh image'));
            var edits = $field.find(':input').serializeArray();
            $field.find('> .InputfieldContent').html(result.html);
            // Preserve metadata controls whose file identity did not change.
            edits.forEach(function(item) { if(/^(description|tags|focus)_/.test(item.name)) $field.find(':input').filter(function() { return this.name === item.name; }).val(item.value); });
            setup($field); $field.addClass('InputfieldStateChanged');
            $('iframe.ui-dialog-content').dialog('close');
        } catch(error) { $('<p role="alert" class="flex-upload-status"></p>').text(error.message).appendTo($field); }
    });
})(jQuery);
