namespace FieldtypeFlex {
    export interface TitlePart {
        text?: string;
        children?: TitlePart[];
        index?: boolean;
        classes?: string[];
    }
    interface TitleNode {
        text?: string;
        choices?: ({text: string} | {ref: string})[];
        children?: TitleNode[];
        classes?: string[];
    }
    export interface TitleSource {
        kind: string;
        names: string[];
        options?: Record<string, string>;
        unchecked?: string;
        base?: string;
        leaf?: string;
        language?: number;
    }
    export interface TitleData {
        nodes: TitleNode[];
        values: Record<string, string>;
        sources: Record<string, TitleSource>;
        custom: Record<string, string>;
        override: TitlePart[] | null;
        refresh: boolean;
    }

    function evaluateTitle(nodes: TitleNode[], values: Record<string, string>, group = false): TitlePart[] {
        const parts: TitlePart[] = [];
        let omittedGroup = false;
        for(const node of nodes) {
            const attributes = node.classes ? {classes: node.classes} : {};
            if(node.children) {
                const children = evaluateTitle(node.children, values, true);
                omittedGroup = !children.length;
                if(children.length) parts.push(...(node.classes ? [{children, ...attributes}] : children));
                continue;
            }
            let text = node.text ?? '';
            if(omittedGroup && /\s$/.test(parts[parts.length - 1]?.text ?? '')) text = text.trimStart();
            let position = {};
            for(const choice of node.choices ?? []) {
                const candidate = 'text' in choice ? choice.text : Object.prototype.hasOwnProperty.call(values, choice.ref) ? values[choice.ref] : '';
                if(candidate.trim() === '') continue;
                text = candidate;
                if('ref' in choice && choice.ref === '@index') position = {index: true};
                break;
            }
            if(text !== '') {
                parts.push({text, ...attributes, ...position});
                omittedGroup = false;
            } else if(group && node.choices) return [];
        }
        return parts;
    }

    function trimTitleEdge(parts: TitlePart[], end: boolean): void {
        while(parts.length) {
            const index = end ? parts.length - 1 : 0;
            const part = parts[index];
            if(part.children) {
                trimTitleEdge(part.children, end);
                if(part.children.length) return;
            } else {
                part.text = end ? part.text!.trimEnd() : part.text!.trimStart();
                if(part.text !== '') return;
            }
            parts.splice(index, 1);
        }
    }

    export function titleParts(title: TitleData, index: number): TitlePart[] {
        function indexed(parts: TitlePart[]): TitlePart[] {
            return parts.map(part => part.children ? {...part, children: indexed(part.children)} : (part.index ? {...part, text: String(index)} : part));
        }
        if(title.override?.length) return indexed(title.override);
        const parts = title.override ?? evaluateTitle(title.nodes, {...title.values, ...title.custom, '@index': String(index)});
        trimTitleEdge(parts, false);
        trimTitleEdge(parts, true);
        return parts.length ? parts : [{text: '#'}, {text: String(index), index: true}];
    }

    export function titleFragment(parts: TitlePart[]): DocumentFragment {
        const result = document.createDocumentFragment();
        for(const part of parts) {
            const content = part.children ? titleFragment(part.children) : document.createTextNode(part.text ?? '');
            if(part.classes?.length) {
                const span = document.createElement('span');
                span.classList.add(...part.classes);
                span.append(content);
                result.append(span);
            } else result.append(content);
        }
        return result;
    }

    function plainTitleValue(value: string): string {
        const decoder = document.createElement('textarea');
        setHTML(decoder, value.replace(/<[^>]*>/g, '').replace(/</g, '&lt;'));
        return Array.from(decoder.value.replace(/\s+/g, ' ').trim()).slice(0, 160).join('');
    }

    export function sourceMatches(source: TitleSource, target: HTMLElement): boolean {
        const name = attr(target, 'name').replace(/\[\]$/, '');
        if(source.kind === 'media') return target.closest('.InputfieldFile')?.id === 'wrap_Inputfield_' + source.base;
        return source.names.some(base => name === base || (source.kind === 'date' && name.startsWith(base + '__')) ||
            target.id === 'wrap_Inputfield_' + base || target.id === 'wrap_Inputfield_' + base.replace(/__\d+$/, ''));
    }

    function mediaTitleValue(tree: HTMLElement, source: TitleSource): string | undefined {
        const wrapper = all(tree, '.InputfieldFile').find(input => input.id === 'wrap_Inputfield_' + source.base);
        if(!wrapper) return;
        const prefix = 'sort_' + source.base + '_';
        const items = all<HTMLInputElement>(wrapper, 'input[name^="sort_"]').filter(input => input.name.startsWith(prefix))
            .map(input => {
                const suffix = input.name.slice(5);
                const item = input.closest('li') || wrapper;
                const find = <T extends Control>(name: string) => all<T>(item, '[name]').find(control => control.name === name + '_' + suffix);
                return {find, item};
            }).filter(({find}) => !find<HTMLInputElement>('delete')?.checked);
        const first = items[0];
        if(!first) return '';
        if(source.leaf === 'description' || source.leaf === 'tags') {
            const leaf = source.leaf;
            const metadata = all(first.item, '[data-flex-metadata-property]').find(field => attr(field, 'data-flex-metadata-property') === leaf);
            if(metadata) {
                const controls = all<HTMLInputElement | HTMLTextAreaElement>(metadata, 'input:not([type="hidden"]), textarea');
                const localized = source.language ? controls.find(control => control.name.endsWith('__' + source.language)) : undefined;
                return localized?.value.trim() ? localized.value : controls[0]?.value;
            }
            const localized = leaf === 'description' && source.language ? first.find('description' + source.language) : undefined;
            return localized?.value.trim() ? localized.value : first.find(leaf)?.value;
        }
        const basename = (item: Element) => {
            const original = item.querySelector('.gridImage__overflow img[data-original]');
            const link = item.querySelector<HTMLAnchorElement>('a.InputfieldFileName, a.InputfieldFileLink');
            const image = item.querySelector<HTMLImageElement>('.InputfieldImageEdit__image[src]');
            const url = original?.getAttribute('data-original') || link?.href || image?.src;
            if(!url) return '';
            try { return decodeURIComponent(new URL(url, document.baseURI).pathname.split('/').pop() || ''); } catch { return ''; }
        };
        const names = (source.leaf === 'basename' ? [first] : items).map(({item}) => basename(item)).filter(Boolean);
        return names.length ? names.join(', ') : undefined;
    }

    export function titleSourceValue(tree: HTMLElement, source: TitleSource): string | undefined {
        if(source.kind === 'media') {
            const value = mediaTitleValue(tree, source);
            return value === undefined ? value : plainTitleValue(value);
        }
        let current: string | undefined;
        for(const name of source.names) {
            // ProcessWire generates these names from the authenticated field and schema identifiers.
            const controls = all<HTMLElement>(tree, `[name="${name}"], [name="${name}[]"]`);
            if(!controls.length) continue;
            const control = controls[0];
            const label = (key: string) => source.options && Object.prototype.hasOwnProperty.call(source.options, key) ? source.options[key] : key;
            if(control.matches('.InputfieldTinyMCEInline')) {
                current = control.innerHTML;
            } else if(control instanceof HTMLSelectElement) {
                current = Array.from(control.selectedOptions).filter(option => source.kind !== 'page' || Boolean(+option.value))
                    .map(option => source.options ? label(option.value) : option.textContent ?? '').join(', ');
            } else if(control instanceof HTMLInputElement && control.matches('[type="checkbox"], [type="radio"]')) {
                current = controls.filter((option): option is HTMLInputElement => option instanceof HTMLInputElement && option.checked)
                    .map(option => source.kind === 'page' ? option.labels?.[0]?.textContent?.trim() ?? '' : label(option.value)).join(', ');
                if(!current && source.unchecked !== undefined) current = label(source.unchecked);
            } else if(control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement) {
                if(source.kind === 'page') {
                    if(!control.value || control.value === '0') current = '';
                    else {
                        const widget = control.closest('.Inputfield');
                        const selected = widget && all(widget, 'li:not(.itemTemplate, .itemDeleted, .delete) > .itemLabel');
                        if(selected?.length) current = selected.map(item => item.textContent ?? '').join(', ');
                        else current = widget?.querySelector('.PageListSelectName')?.textContent ??
                            (control.id ? (tree.ownerDocument.getElementById(control.id + '_input') as HTMLInputElement | null)?.value : undefined);
                    }
                } else {
                    current = label(control.value);
                    if(control.matches('.InputfieldDatetimeValue')) {
                        const dates = ['y', 'm', 'd'].map(part => tree.querySelector<HTMLSelectElement>(`[name="${name}__${part}"]`)?.value ?? '');
                        current = dates.every(value => /^\d+$/.test(value)) ? dates.map((value, part) => value.padStart(part ? 2 : 4, '0')).join('-') : '';
                    } else if(source.kind === 'date') {
                        const time = tree.querySelector<HTMLInputElement>(`[name="${name}__time"]`);
                        if(time?.value) current += ' ' + time.value;
                    }
                }
            }
            if(current && current.trim()) break;
        }
        return current === undefined ? current : plainTitleValue(current);
    }
}
