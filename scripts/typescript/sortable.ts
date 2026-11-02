namespace FieldtypeFlex {
    const nativeSorts = new Map<HTMLElement, {disabled: boolean; destroy?: () => void}>();
    let observing = false;
    // Element-only contract: replacing this adapter does not affect Row logic.
    export const sortable = {
        init(list: HTMLElement, start: (row: HTMLElement) => void, stop: (row: HTMLElement) => void): void {
            if(window.ProcessWireAdminTheme) {
                if(window.Bridge?.contract !== '1' || nativeSorts.has(list)) return;
                const state = {disabled: false} as {disabled: boolean; destroy?: () => void};
                nativeSorts.set(list, state);
                void window.Bridge.sortable(list, {
                    items: '.flex-row[data-flex-row]', handle: '.flex-row-sort',
                    cancel: 'input, select, textarea, a, button:not(.flex-row-sort)', axis: 'y', threshold: 5,
                    enabled: () => !state.disabled && list.isConnected, start, stop
                }).then(destroy => {
                    if(!list.isConnected) { destroy(); nativeSorts.delete(list); }
                    else state.destroy = destroy;
                }).catch(error => { nativeSorts.delete(list); console.error('Flex sorting failed', error); });
                if(!observing) {
                    observing = true;
                    window.ProcessWireAdminTheme.observe(() => nativeSorts.forEach((state, list) => {
                        if(!list.isConnected) { state.destroy?.(); nativeSorts.delete(list); }
                    }));
                }
                return;
            }
            const $ = window.jQuery;
            if(!$?.fn.sortable || $(list).hasClass('ui-sortable')) return;
            list.addEventListener('mousedown', event => {
                if(event.target instanceof Element && event.target.closest('.flex-rows-items') === list) event.stopPropagation();
            });
            $(list).on('sortstart.flexRows sortstop.flexRows sortupdate.flexRows', event => {
                if(event.target === list) event.stopPropagation();
            }).sortable({
                items: '> .flex-row[data-flex-row]', handle: '.flex-row-sort', cancel: ':input:not(.flex-row-sort), a',
                axis: 'y', distance: 5, tolerance: 'pointer', placeholder: 'flex-row-placeholder',
                start(_event, ui) { ui.placeholder.height(ui.item[0].getBoundingClientRect().height); start(ui.item[0]); },
                stop(_event, ui) { stop(ui.item[0]); }
            });
        },
        disabled(list: HTMLElement, disabled: boolean): void {
            const state = nativeSorts.get(list);
            if(state) { state.disabled = disabled; return; }
            const $ = window.jQuery;
            if($?.fn.sortable && $(list).hasClass('ui-sortable')) $(list).sortable('option', 'disabled', disabled);
        }
    };
}
