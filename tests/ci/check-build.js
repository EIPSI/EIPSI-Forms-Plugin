const fs = require('fs');
const path = require('path');
const assert = require('assert/strict');
const { minify } = require('terser');
const root = path.resolve(__dirname, '../..');
(async () => {
    require('../../scripts/check-admin-i18n').check();
    require('../../scripts/check-content-defaults').check();
    const i18n = await minify(fs.readFileSync(path.join(root,'src/admin/i18n/runtime.js'),'utf8'), { compress:false,mangle:false });
    assert.equal(fs.readFileSync(path.join(root,'assets/js/admin-i18n.js'),'utf8'),'/* Generated from src/admin/i18n/runtime.js. */\n'+i18n.code+'\n','Admin i18n runtime is stale');
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
    const study = await minify(['interactions.js','runtime.js'].map(file=>fs.readFileSync(path.join(root,'src/frontend/study',file),'utf8')).join('\n'), { compress: false, mangle: false });
    assert.equal(fs.readFileSync(path.join(root, 'assets/js/study-runtime.js'), 'utf8'), '/* Generated from src/frontend/study/runtime.js. */\n' + study.code + '\n', 'Study runtime is stale');
    const builderDir = path.join(root, 'src/admin/study-builder');
    const builderManifest = JSON.parse(fs.readFileSync(path.join(builderDir, 'manifest.json'), 'utf8'));
    const builderSource = builderManifest.entry_order.map(file => fs.readFileSync(path.join(builderDir, file), 'utf8')).join('\n');
    const builder = await minify(builderSource, { compress: false, mangle: false });
    assert.equal(fs.readFileSync(path.join(root, 'assets/js/study-builder.js'), 'utf8'), '/* Generated from src/admin/study-builder/manifest.json. */\n' + builder.code + '\n', 'Study Builder is stale');
    const sourceEditor = await minify(fs.readFileSync(path.join(root, 'src/admin/study-builder/source-editor.js'), 'utf8'), { compress: false, mangle: false });
    assert.equal(fs.readFileSync(path.join(root, 'assets/js/study-builder-source.js'), 'utf8'), '/* Generated from src/admin/study-builder/source-editor.js. */\n' + sourceEditor.code + '\n', 'Gutenberg source bridge is stale');
    const nativeDir = path.join(root, 'src/admin/assessment-editor');
    const nativeManifest = JSON.parse(fs.readFileSync(path.join(nativeDir, 'manifest.json'), 'utf8'));
    const native = await minify(nativeManifest.entry_order.map(file => fs.readFileSync(path.join(nativeDir, file), 'utf8')).join('\n'), { compress: false, mangle: false });
    assert.equal(fs.readFileSync(path.join(root, 'assets/js/assessment-editor.js'), 'utf8'), '/* Generated from src/admin/assessment-editor/manifest.json. */\n' + native.code + '\n', 'Native Assessment Editor is stale');
    const sharedManifest = JSON.parse(fs.readFileSync(path.join(nativeDir, 'participant-manifest.json')));
    const shared = await minify(sharedManifest.entry_order.map(file => fs.readFileSync(path.join(nativeDir, file), 'utf8')).join('\n'), { compress: false, mangle: false });
    assert.equal(fs.readFileSync(path.join(root, 'assets/js/assessment-participant.js'), 'utf8'), '/* Generated shared native widgets; transport remains host-owned. */\n' + shared.code + '\n', 'Shared participant widgets stale');
    console.log('Build artifacts OK: 13 blocks, Forms runtime from 18 fragments');
})().catch(error => { console.error(error); process.exitCode = 1; });
