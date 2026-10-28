'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const cases = JSON.parse(fs.readFileSync(path.join(root, 'tests/fixtures/row-titles.json'), 'utf8'));
const source = fs.readFileSync(path.join(root, 'FieldtypeFlex.js'), 'utf8');

// Execute the production parser directly. No DOM shim, copied parser or production
// test export is needed; fail explicitly if its private function boundary changes.
const start = source.indexOf('    function titleParts(');
const end = source.indexOf('    function contextPath(', start);
assert(start >= 0 && end > start, 'Production titleParts function boundary is available');
const parser = vm.runInNewContext('(function() {' + source.slice(start, end) + '; return {parts: titleParts, title: rowTitle}; })()', {}, {timeout: 1000});
const results = cases.map(test => {
    const parts = parser.parts(test.format, test.values || {}, test.number || 1, test.type || '', test.path || '');
    assert.equal(parts.map(part => part.text).join(''), test.text, test.name);
    assert.deepEqual(Array.from(parts.filter(part => part.badge && part.text !== ''), part => part.text), test.badges || [], test.name + ' badges');
    assert.deepEqual(Array.from(parts.filter(part => part.muted && part.text !== ''), part => part.text), test.muted || [], test.name + ' muted');
    return parts;
});
const titles = cases.map(test => {
    if(test.titleText === undefined) return null;
    const row = {
        attr: name => name === 'data-flex-label-format' ? test.format : (test.type || 'Item'),
        data: () => test.values || {}
    };
    const parts = parser.title(row, (test.number || 1) - 1, test.path || '');
    assert.equal(parts.map(part => part.text).join(''), test.titleText, test.name + ' title/fallback');
    assert.deepEqual(Array.from(parts.filter(part => part.badge && part.text !== ''), part => part.text), test.titleBadges || [], test.name + ' title badges');
    return parts;
});

if(process.argv.includes('--json')) process.stdout.write(JSON.stringify({parts: results, titles}));
else process.stdout.write(cases.length + ' production JavaScript title cases passed.\n');
