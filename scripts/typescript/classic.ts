// Admin integration: Bridge public APIs, with the classic ProcessWire stack as fallback.
namespace FieldtypeFlex {
    export interface Editor { save(): void; remove(): void; }
    interface LegacyEvent { target: HTMLElement; type: string; originalEvent?: Event; stopPropagation(): void; }
    export interface LegacyCollection {
        0: HTMLElement;
        on(events: string, selector: string | ((event: LegacyEvent, ...args: string[]) => void), callback?: (this: HTMLElement, event: LegacyEvent, ...args: string[]) => void): LegacyCollection;
        trigger(name: string, args?: unknown[]): LegacyCollection;
        dialog(action: string): void;
        hasClass(name: string): boolean;
        sortable(options: SortOptions): LegacyCollection;
        sortable(action: string, option: string, value: boolean): LegacyCollection;
    }
    export interface LegacyJQuery {
        (element: Element | Document | string): LegacyCollection;
        fn: {sortable?: unknown};
    }
    export interface SelectorAPI {
        flexPathsPreserved?: boolean;
        changeAny: (item: LegacyCollection) => unknown;
    }
    export interface TinyMCEAPI {
        init(element: HTMLElement, source: string): void;
        destroyEditorsIn(scope: LegacyCollection): void;
    }
    export interface SortOptions {
        items: string; handle: string; cancel: string; axis: string; distance: number; tolerance: string; placeholder: string;
        start(event: LegacyEvent, ui: {item: LegacyCollection; placeholder: {height(value: number): void}}): void;
        stop(event: LegacyEvent, ui: {item: LegacyCollection}): void;
    }
    export const classic = {
        async setup(scope: HTMLElement): Promise<void> {
            if(window.ProcessWireAdminTheme) {
                if(needsLegacy(scope)) await window.Bridge?.legacy.load();
                window.ProcessWireAdminTheme.legacy.initElement(scope);
                return;
            }
            const $ = window.jQuery;
            if(!$) return;
            if(window.Inputfields) window.Inputfields.init($(scope));
            else window.InputfieldsInit?.($(scope));
            const inputs = all(scope, '.Inputfield');
            if(scope.matches('.Inputfield')) inputs.unshift(scope);
            inputs.forEach(input => $(input).trigger('reloaded'));
        },
        toggle(row: HTMLElement, open: boolean): void {
            if(nativeBridge() && window.Bridge?.contract === '1') {
                if(open) window.Bridge.Inputfields.open(row); else window.Bridge.Inputfields.close(row);
                return;
            }
            if(window.jQuery && window.Inputfields?.toggle) window.Inputfields.toggle(window.jQuery(row), open, 0);
            else {
                row.classList.toggle('InputfieldStateCollapsed', !open);
                row.dispatchEvent(new Event(open ? 'opened' : 'closed', {bubbles: true}));
            }
        },
        events(reloaded: (scope: HTMLElement) => void, toggled: (row: HTMLElement, open: boolean) => void, tinyChanged: (scope: HTMLElement) => void, mediaRefresh: (draft: string, path: string) => void): void {
            document.addEventListener('reloaded', event => {
                if(event.target instanceof HTMLElement && event.target.matches('.Inputfield')) reloaded(event.target);
            });
            ['opened', 'closed'].forEach(name => document.addEventListener(name, event => {
                if(event.target instanceof HTMLElement && event.target.matches('.flex-row')) toggled(event.target, name === 'opened');
            }));
            document.addEventListener('change', event => {
                if(event.target instanceof HTMLElement && event.target.matches('.InputfieldTinyMCE[data-flex-label-property]')) tinyChanged(event.target);
            });
            // Upstream custom events are jQuery-only. Bind when a legacy child loads the stack.
            let bound = false;
            const bindLegacy = () => {
                const $ = window.jQuery;
                if(!$ || bound) return;
                bound = true;
                $(document).on('reloaded.flexRows', '.Inputfield', function(event) {
                    if(!event.originalEvent && event.target === this) reloaded(event.target);
                });
                $(document).on('opened.flexRows closed.flexRows', '.flex-row', function(event) {
                    if(!event.originalEvent && event.target === this) toggled(event.target, event.type === 'opened');
                });
                $(document).on('change.flexRows', '.InputfieldTinyMCE[data-flex-label-property]', function(event) {
                    if(!event.originalEvent && event.target === this) tinyChanged(event.target);
                });
                $(document).on('flexMediaRefresh.flexRows', (_event, draft, path) => {
                    if(typeof draft === 'string' && typeof path === 'string') mediaRefresh(draft, path);
                });
            };
            bindLegacy();
            document.addEventListener('pw-legacy-loaded', bindLegacy);
            document.addEventListener('flexMediaRefresh', event => {
                const detail = (event as CustomEvent<{draft: string; path: string}>).detail;
                if(detail) mediaRefresh(detail.draft, detail.path);
            });
        },
        added(row: HTMLElement): void {
            if(window.jQuery) window.jQuery(document).trigger('flexRowAdded', [window.jQuery(row)]);
            else document.dispatchEvent(new CustomEvent('flexRowAdded', {detail: {row}}));
        },
        saveReady(scope: HTMLElement): void {
            if(window.jQuery) all(scope, '.InputfieldTinyMCE').forEach(input => window.jQuery!(input).trigger('saveReady'));
        },
        destroyEditors(scope: HTMLElement): void {
            if(window.jQuery) window.InputfieldTinyMCE?.destroyEditorsIn(window.jQuery(scope));
        },
        initInline(editor: HTMLElement): void { window.InputfieldTinyMCE?.init(editor, 'flex.mediaMetadata'); },
        closeDialog(): void {
            if(nativeBridge()) window.Bridge?.dialog.close();
            else window.jQuery?.('iframe.ui-dialog-content').dialog('close');
        },
        confirm(message: string, callback: () => void): void {
            if(window.ProcessWireAdminTheme) { void window.Bridge?.confirm(message, callback); return; }
            if(window.ProcessWire?.confirm) window.ProcessWire.confirm(message, callback);
            else if(window.confirm(message)) callback();
        },
        preserveSelectorPaths(): void {
            const selector = window.InputfieldSelector;
            if(!selector || selector.flexPathsPreserved) return;
            const original = selector.changeAny;
            if(!/\.substring\(0,\s*[\w$.]+\.lastIndexOf\(["']\.["']\)\)/.test(String(original))) return;
            let active = false;
            selector.changeAny = function(item) {
                if(active) return original.call(this, item);
                const fields = window.ProcessWire?.config.FieldtypeFlexSelectorFields || [];
                const adjusted: [HTMLOptionElement, string][] = [];
                all<HTMLOptionElement>(closest(item[0], '.InputfieldSelector'), '.select-subfield option:checked').forEach(option => {
                    const path = option.value.split('.');
                    if(path.length !== 3 || !fields.includes(path[0])) return;
                    adjusted.push([option, option.value]); option.value += '.__flex_terminal';
                });
                active = true;
                try { return original.call(this, item); }
                finally { adjusted.forEach(([option, value]) => option.value = value); active = false; }
            };
            selector.flexPathsPreserved = true;
        }
    };
}

interface Window {
    jQuery?: FieldtypeFlex.LegacyJQuery;
    ProcessWire?: {config: {FieldtypeFlex?: Record<string, string>; FieldtypeFlexSelectorFields?: string[]}; confirm(message: string, callback: () => void): void};
    Inputfields?: {init(scope: FieldtypeFlex.LegacyCollection): void; toggle(row: FieldtypeFlex.LegacyCollection, open: boolean, duration: number): void};
    InputfieldsInit?: (scope: FieldtypeFlex.LegacyCollection) => void;
    InputfieldSelector?: FieldtypeFlex.SelectorAPI;
    InputfieldTinyMCE?: FieldtypeFlex.TinyMCEAPI;
    tinymce?: {get(id: string): FieldtypeFlex.Editor | undefined; triggerSave(): void};
}

namespace FieldtypeFlex {
    export interface BridgeAPI {
        contract: string;
        Inputfields: {init(scope: ParentNode): void; open(row: HTMLElement): void; close(row: HTMLElement): void};
        legacy: {loaded(): boolean; load(): Promise<void>};
        sortable(list: HTMLElement, options: {items: string; handle: string; cancel: string; axis: 'y'; threshold: number; enabled(): boolean; start(row: HTMLElement): void; stop(row: HTMLElement): void}): Promise<() => void>;
        dialog: {close(): void};
        confirm(message: string, callback: () => void): Promise<boolean>;
    }
    export interface ThemeAPI {
        upstream(): boolean;
        trusted: {html(value: string): string};
        runScripts(scope: HTMLElement, options: {legacy: boolean; nonce: string}): Promise<void>;
        observe(callback: () => void): () => void;
        legacy: {needsStack(field: Element): boolean; initElement(scope: HTMLElement): void};
    }
    export function nativeBridge(): boolean { return !!window.ProcessWireAdminTheme && !window.ProcessWireAdminTheme.upstream(); }
    export function needsLegacy(scope: HTMLElement): boolean {
        const fields = all(scope, '.Inputfield[data-bridge-legacy]');
        if(scope.matches('.Inputfield[data-bridge-legacy]')) fields.unshift(scope);
        return fields.some(field => window.ProcessWireAdminTheme?.legacy.needsStack(field));
    }
}
interface Window {
    Bridge?: FieldtypeFlex.BridgeAPI;
    ProcessWireAdminTheme?: FieldtypeFlex.ThemeAPI;
}
