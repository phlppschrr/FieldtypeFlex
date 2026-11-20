'use strict';

const fs = require('node:fs');
const path = require('node:path');
const {pathToFileURL} = require('node:url');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const cases = JSON.parse(fs.readFileSync(path.join(root, 'tests/fixtures/row-titles.json'), 'utf8'));

function text(parts) {
    return parts.map(part => part.children ? text(part.children) : part.text).join('');
}
function styles(parts) {
    return parts.flatMap(part => [
        ...(part.classes ? [[text([part]), part.classes]] : []),
        ...(part.children ? styles(part.children) : []),
    ]);
}

(async () => {
    const {titleParts} = await import(pathToFileURL(path.join(root, 'scripts/typescript/row-title-parts.mjs')).href);
    const actual = cases.map(test => {
        const values = Object.fromEntries(Object.entries(test.values).map(([key, value]) => [key, String(value)]));
        const parts = JSON.parse(JSON.stringify(titleParts({nodes: test.nodes, values, custom: {}, override: null}, test.number)));
        assert.equal(text(parts), test.text, test.name);
        assert.deepEqual(styles(parts), test.styles, test.name + ' styles');
        return parts;
    });
    if(process.argv.includes('--json')) process.stdout.write(JSON.stringify(actual));
    else process.stdout.write(cases.length + ' production JavaScript title cases passed.\n');
})().catch(error => {
    console.error(error);
    process.exit(1);
});
