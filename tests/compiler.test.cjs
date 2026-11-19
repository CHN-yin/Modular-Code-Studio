const { test } = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const { createHash } = require('node:crypto');
const { runInNewContext } = require('node:vm');
const { join } = require('node:path');

const moduleData = (id, settings = {}) => ({
    key: id, moduleName: id,
    html: '<div>Example</div>', css: '', js: '',
    enableHtml: true, enableCss: true, enableJs: true,
    rootSelector: '', wrapper: false, dependencies: '',
    ...settings,
});

const compile = (modules, id = 17) => {
    const result = spawnSync('php', [join(__dirname, 'compile-fixture.php')], {
        input: JSON.stringify({ id, base: 'https://example.org/a/b/', modules }), encoding: 'utf8',
    });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return JSON.parse(result.stdout);
};

const hash = (data) => createHash('sha256').update(data).digest('hex');

test('rebases real CSS URL tokens and retains modern rule structure and order', () => {
    const result = compile([
        moduleData('a:1', { css: `@layer base, ui;
.first { --img: url(../img/a.png); --literal: "url(bad.png)";
  &.is-open { background: url("#local") }
}
@media (width < 768px) { .mobile { background: url("small.png") } }` }),
        moduleData('b:1', { css: `@import "./extra.css" screen;
@font-face { font-family: Test; src: url(font.woff2), url(//cdn.example.org/font.woff2) }
@keyframes fade { from { opacity: 0 } to { opacity: 1 } }
.last:hover { background: url(data:image/gif;base64,R0lGOD) }` }),
    ]);
    assert.equal(result.issues.css.length, 0, JSON.stringify(result.issues));
    assert.match(result.css, /^@import "https:\/\/example.org\/a\/b\/extra.css" screen/);
    for (const expected of ['https://example.org/a/img/a.png', '"url(bad.png)"', '#local', 'b/small.png',
        'b/font.woff2', '//cdn.example.org/font.woff2', 'data:image/gif', '@keyframes fade']) assert.ok(result.css.includes(expected), expected);
    assert.ok(result.css.indexOf('.first') < result.css.indexOf('.last'));
    assert.deepEqual(result.selectors, ['.first', '.first.is-open', '.mobile', '.last:hover']);
});

test('emits one ordered runtime with scoped roots, dependency deduplication and failure isolation', () => {
    const built = compile([
        moduleData('a:1', { js: 'const label = "a"; window.sequence.push(label); throw new Error("one");', dependencies: 'jquery, mcs.shared' }),
        moduleData('a:2', { js: 'const label = "b"; window.sequence.push(label + ":" + root.dataset.name);', dependencies: 'jquery', wrapper: true }),
        moduleData('bad:1', { js: 'invalid const =' }),
        moduleData('c:1', { js: 'window.sequence.push(instanceId);' }),
    ]);
    assert.deepEqual(built.dependencies, ['jquery', 'mcs.shared']);
    assert.equal(built.issues.js.length, 1);
    const errors = [];
    const window = { sequence: [] };
    const context = {
        window, console: { error: (...args) => errors.push(args) },
        document: { querySelectorAll: (selector) => selector === '[data-mcs-instance]' ? [{ dataset: { mcsInstance: 'a:2', name: 'second' } }] : [] },
    };
    runInNewContext(built.js, context);
    runInNewContext(built.js, context);
    assert.deepEqual(window.sequence, ['a', 'b:second', 'c:1']);
    assert.equal(errors.length, 1);
});

test('hashes are independent, builds respect toggles, and page JS is isolated', () => {
    const base = moduleData('a:1', { css: '.a{color:red}', js: 'window.a = 1;' });
    const original = compile([base]);
    const cssEdit = compile([{ ...base, css: '.a{color:blue}' }]);
    const jsEdit = compile([{ ...base, js: 'window.a = 2;' }]);
    assert.equal(hash(original.js), hash(cssEdit.js));
    assert.notEqual(hash(original.css), hash(cssEdit.css));
    assert.equal(hash(original.css), hash(jsEdit.css));
    assert.notEqual(hash(original.js), hash(jsEdit.js));
    assert.notEqual(hash(original.js), hash(compile([base], 18).js));
    assert.equal(compile([{ ...base, enableCss: false, enableJs: false }]).js, '');
    assert.equal(compile([]).css, '');
});
