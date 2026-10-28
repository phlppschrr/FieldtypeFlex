(function($) {
    'use strict';
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
    function updateLabels($set) {
        rows($set).each(function(index) {
            var $row = $(this), values = $row.data('flexLabelValues') || {};
            var format = $row.attr('data-flex-label-format') || '';
            var label = format.replace(/#n/gi, '#' + (index + 1)).replace(/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/g, function(match, name) {
                return Object.prototype.hasOwnProperty.call(values, name) ? values[name] : '';
            }).trim();
            if(!label) label = ($row.attr('data-flex-label-fallback') || '').replace(/#n/gi, '#' + (index + 1));
            $row.children('.flex-row-header').find('.flex-row-title').text(label);
        });
    }
    function treeBusy($set) {
        return !!$set.closest('.InputfieldFlex').data('flexTreeBusy');
    }
    function setTreeBusy($set, busy) {
        var $tree = $set.closest('.InputfieldFlex');
        $tree.data('flexTreeBusy', busy).attr('aria-busy', busy ? 'true' : 'false');
        $tree.find('.flex-rows').each(function() { updateActions($(this)); });
    }
    function updateActions($set) {
        var count = rows($set).length, min = Number($set.attr('data-flex-min')) || 0;
        var max = Number($set.attr('data-flex-max')) || 0, busy = treeBusy($set);
        $set.children('.flex-row-add').prop('disabled', busy || (max > 0 && count >= max));
        rows($set).each(function() {
            var $header = $(this).children('.flex-row-header');
            $header.find('[data-flex-row-action="duplicate"]').prop('disabled', busy || (max > 0 && count >= max));
            $header.find('[data-flex-row-action="remove"]').prop('disabled', busy || count <= min);
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
                if($row.find('.InputfieldStateError').length) openRow($row, true);
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
        $error.text(message('inputVarsExceeded', 'This form contains {count} input values, exceeding PHP max_input_vars={max}.')
            .replace('{count}', count).replace('{max}', max)).trigger('focus');
    }, true);
    $(function() { initRows($(document)); });
    $(document).on('reloaded.flexRows', '.Inputfield', function(event) { initRows($(event.target)); });
    $(document).on('opened.flexRows closed.flexRows', '.flex-row', function(event) {
        if(event.target === this) syncToggle($(this));
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
    document.addEventListener('invalid', function(event) {
        $(event.target).parents('.flex-row').each(function() { openRow($(this), true); });
    }, true);
    $(document).on('click', '.flex-row-add, .flex-row-action', async function(event) {
        event.preventDefault();
        event.stopPropagation();
        var $button = $(this), $set = $button.closest('.flex-rows'), $row = $button.closest('.flex-row');
        if($button.prop('disabled') || treeBusy($set)) return;
        var action = $button.hasClass('flex-row-add') ? 'add' : $button.attr('data-flex-row-action');
        var $error = $set.children('.flex-rows-error').text('');
        if(action === 'remove') {
            var $focus = $row.next('.flex-row');
            if(!$focus.length) $focus = $row.prev('.flex-row');
            teardown($row);
            $row.remove();
            order($set);
            ($focus.length ? $focus.find('.flex-row-toggle').first() : $set.children('.flex-row-add')).trigger('focus');
            return;
        }
        setTreeBusy($set, true);
        updateActions($set);
        try {
            if(window.tinymce) window.tinymce.triggerSave();
            var data = new URLSearchParams();
            data.set('flex_action', action); data.set('flex_set', $set.attr('data-flex-set'));
            data.set('flex_order', $set.children('.flex-rows-order').val());
            var activeOrders = {};
            $set.closest('.InputfieldFlex').find('.flex-rows').each(function() {
                var $current = $(this), ids = $current.children('.flex-rows-order').val();
                activeOrders[$current.attr('data-flex-set')] = ids ? ids.split(',') : [];
            });
            data.set('flex_orders', JSON.stringify(activeOrders));
            if(action === 'add') {
                var type = $set.attr('data-flex-type') || $set.find('> .flex-row-type-control .flex-row-type').val();
                data.set('flex_type', type);
            } else if(action === 'duplicate') {
                data.set('flex_source', $row.attr('data-flex-row'));
                $row.find(':input').serializeArray().forEach(function(item) { data.append(item.name, item.value); });
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
