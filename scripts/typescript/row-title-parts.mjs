/**
 * @typedef {import('./row-title').TitleNode} TitleNode
 * @typedef {import('./row-title').TitlePart} TitlePart
 * @typedef {import('./row-title').TitleData} TitleData
 */

/**
 * @param {TitleNode[]} nodes
 * @param {Record<string, string>} values
 * @param {boolean} [group=false]
 * @returns {TitlePart[]}
 */
function evaluateTitle(nodes, values, group = false) {
    /** @type {TitlePart[]} */
    const parts = [];
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

/**
 * @param {TitlePart[]} parts
 * @param {boolean} end
 * @returns {void}
 */
function trimTitleEdge(parts, end) {
    while(parts.length) {
        const index = end ? parts.length - 1 : 0;
        const part = parts[index];
        if(part.children) {
            trimTitleEdge(part.children, end);
            if(part.children.length) return;
        } else {
            part.text = end ? (part.text ?? '').trimEnd() : (part.text ?? '').trimStart();
            if(part.text !== '') return;
        }
        parts.splice(index, 1);
    }
}

/**
 * @param {Pick<TitleData, 'nodes' | 'values' | 'custom' | 'override'>} title
 * @param {number} index
 * @returns {TitlePart[]}
 */
export function titleParts(title, index) {
    /** @param {TitlePart[]} parts @returns {TitlePart[]} */
    function indexed(parts) {
        return parts.map(part => part.children ? {...part, children: indexed(part.children)} : (part.index ? {...part, text: String(index)} : part));
    }
    if(title.override?.length) return indexed(title.override);
    const parts = title.override ?? evaluateTitle(title.nodes, {...title.values, ...title.custom, '@index': String(index)});
    trimTitleEdge(parts, false);
    trimTitleEdge(parts, true);
    return parts.length ? parts : [{text: '#'}, {text: String(index), index: true}];
}
