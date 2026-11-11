namespace FieldtypeFlex {
    export type Control = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement | HTMLButtonElement;
    export const controlSelector = 'input, select, textarea, button';
    export function all<T extends Element = HTMLElement>(scope: ParentNode, selector: string): T[] {
        return Array.from(scope.querySelectorAll<T>(selector));
    }
    export function one<T extends Element = HTMLElement>(scope: ParentNode, selector: string): T {
        const element = scope.querySelector<T>(selector);
        if(!element) throw new Error('Missing Flex element: ' + selector);
        return element;
    }
    export function closest(element: Element, selector: string): HTMLElement {
        const parent = element.closest<HTMLElement>(selector);
        if(!parent) throw new Error('Missing Flex parent: ' + selector);
        return parent;
    }
    export function attr(element: Element, name: string): string { return element.getAttribute(name) || ''; }
    export function ready(callback: () => void): void {
        if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', callback, {once: true});
        else callback();
    }
    export function value(control: Control): string | string[] {
        if(control instanceof HTMLSelectElement && control.multiple) return Array.from(control.selectedOptions, option => option.value);
        return control.value;
    }
    export function setValue(control: Control, value: string | string[]): void {
        if(control instanceof HTMLSelectElement && Array.isArray(value)) {
            Array.from(control.options).forEach(option => option.selected = value.includes(option.value));
        } else control.value = Array.isArray(value) ? value.join(',') : value;
    }
    // Match successful form controls, including fieldset disabling and CRLF encoding.
    // Files and submit buttons are deliberately excluded, as in native AJAX forms.
    export function serialize(scope: HTMLElement): [string, string][] {
        const controls = scope instanceof HTMLFormElement ? Array.from(scope.elements) : all(scope, controlSelector);
        return controls.flatMap(element => {
            if(!(element instanceof HTMLInputElement || element instanceof HTMLSelectElement || element instanceof HTMLTextAreaElement)) return [];
            if(!element.name || element.matches(':disabled') || /^(file|submit|button|image|reset)$/i.test(element.type)) return [];
            if(element instanceof HTMLInputElement && /^(checkbox|radio)$/i.test(element.type) && !element.checked) return [];
            const values = element instanceof HTMLSelectElement
                ? Array.from(element.selectedOptions).filter(option => !option.disabled && !(option.parentElement instanceof HTMLOptGroupElement && option.parentElement.disabled)).map(option => option.value)
                : [element.value];
            return values.map(item => [element.name, item.replace(/\r?\n/g, '\r\n')] as [string, string]);
        });
    }
    export function trustedHTML(html: string): string {
        return bridgeTheme()?.trusted.html(html) ?? html;
    }
    export function setHTML(scope: HTMLElement, html: string): void { scope.innerHTML = trustedHTML(html); }
    export function insertHTML(scope: HTMLElement, where: InsertPosition, html: string): void { scope.insertAdjacentHTML(where, trustedHTML(html)); }
    export function parseHTML(html: string): HTMLElement {
        const template = document.createElement('template');
        template.innerHTML = trustedHTML(html.trim());
        return one<HTMLElement>(template.content, '*');
    }
    // Native Inputfields can return inline configuration scripts in AJAX markup.
    // template/innerHTML keep them inert; execute them once after insertion, as before.
    export async function activateScripts(scope: HTMLElement, response?: Response): Promise<void> {
        const theme = bridgeTheme();
        if(theme) {
            await theme.runScripts(scope, {legacy: needsLegacy(scope), nonce: response?.headers?.get('X-Bridge-Nonce') || ''});
            return;
        }
        all<HTMLScriptElement>(scope, 'script').forEach(inert => {
            if(inert.type && !/^(text|application)\/javascript$/.test(inert.type) && inert.type !== 'module') return;
            const script = document.createElement('script');
            Array.from(inert.attributes).forEach(attribute => script.setAttribute(attribute.name, attribute.value));
            script.textContent = inert.textContent;
            script.async = false;
            inert.replaceWith(script);
        });
    }
    export function change(control: Control, value: string): void {
        control.value = value;
        control.dispatchEvent(new Event('change', {bubbles: true}));
    }
    export function delegate<K extends keyof DocumentEventMap>(name: K, selector: string, callback: (event: DocumentEventMap[K], element: HTMLElement) => void): void {
        document.addEventListener(name, event => {
            if(!(event.target instanceof Element)) return;
            const element = event.target.closest<HTMLElement>(selector);
            if(element) callback(event, element);
        });
    }
}
