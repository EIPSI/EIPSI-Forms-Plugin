const fs = require('fs');
const path = require('path');
const assert = require('assert/strict');
const { minify } = require('terser');
const root = path.resolve(__dirname, '../..');
(async () => {
    const sourceBlocks = fs.readdirSync(path.join(root, 'src/blocks'))
        .filter(name => fs.existsSync(path.join(root, 'src/blocks', name, 'block.json'))).sort();
    const builtBlocks = fs.readdirSync(path.join(root, 'build/blocks')).sort();
    assert.equal(sourceBlocks.length, 13, 'Expected 13 source blocks');
    assert.deepEqual(builtBlocks, sourceBlocks, 'Built block set differs from sources');
    for (const block of builtBlocks) {
        const directory = path.join(root, 'build/blocks', block);
        for (const file of ['block.json', 'index.js', 'index.asset.php']) {
            assert(fs.statSync(path.join(directory, file)).size > 0, `Missing/empty ${block}/${file}`);
        }
        const metadata = JSON.parse(fs.readFileSync(path.join(directory, 'block.json')));
        for (const field of ['editorScript', 'script', 'viewScript', 'style', 'editorStyle']) {
            for (const ref of [].concat(metadata[field] || [])) {
                if (ref.startsWith('file:')) assert(fs.existsSync(path.resolve(directory, ref.slice(5))), `Missing ${block} ${ref}`);
            }
        }
    }
    const directory = path.join(root, 'src/frontend/forms');
    const manifest = JSON.parse(fs.readFileSync(path.join(directory, 'manifest.json')));
    assert.equal(manifest.entry_order.length, 18, 'Expected 18 Forms fragments');
    const source = manifest.entry_order.map(file => fs.readFileSync(path.join(directory, file), 'utf8')).join('');
    const compiled = await minify(source, { compress: false, mangle: false, format: { comments: false } });
    const expected = '/* Generated from src/frontend/forms/manifest.json; edit those sources. */\n' + compiled.code + '\n';
    assert.equal(fs.readFileSync(path.join(root, 'assets/js/eipsi-forms.js'), 'utf8'), expected, 'Forms runtime is stale');
    console.log('Build artifacts OK: 13 blocks, Forms runtime from 18 fragments');
})().catch(error => { console.error(error); process.exitCode = 1; });
