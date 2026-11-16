'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const cases = JSON.parse(fs.readFileSync(path.join(root, 'tests/fixtures/row-titles.json'), 'utf8'));
const source = fs.readFileSync(path.join(root, 'FieldtypeFlex.js'), 'utf8');
const start = source.indexOf('    function evaluateTitle(');
const end = source.indexOf('    function plainTitleValue(', start);
assert(start >= 0 && end > start, 'Production title evaluator is available');
const {titleParts} = vm.runInNewContext(`(function() {const FieldtypeFlex = {}; ${source.slice(start, end)}; return FieldtypeFlex;})()`);

function text(parts) {
    return parts.map(part => part.children ? text(part.children) : part.text).join('');
}
function styles(parts) {
    return parts.flatMap(part => [
        ...(part.classes ? [[text([part]), part.classes]] : []),
        ...(part.children ? styles(part.children) : []),
    ]);
}
const actual = cases.map(test => {
    const values = Object.fromEntries(Object.entries(test.values).map(([key, value]) => [key, String(value)]));
    const parts = JSON.parse(JSON.stringify(titleParts({nodes: test.nodes, values, custom: {}, override: null}, test.number)));
    assert.equal(text(parts), test.text, test.name);
    assert.deepEqual(styles(parts), test.styles, test.name + ' styles');
    return parts;
});
if(process.argv.includes('--json')) process.stdout.write(JSON.stringify(actual));
else process.stdout.write(cases.length + ' production JavaScript title cases passed.\n');
