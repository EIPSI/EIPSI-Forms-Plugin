/** Compose the historical shared closure without reordering registrations/globals. */
const fs = require('fs');
const path = require('path');
const { minify } = require('terser');
require('./check-admin-i18n').check();
const root = path.resolve(__dirname, '..');
fs.writeFileSync(path.join(root,'src/admin/i18n/content-catalog.js'),'/* Generated from includes/i18n/content-defaults.json. */\nglobalThis.EIPSIContentCatalog='+fs.readFileSync(path.join(root,'includes/i18n/content-defaults.json'),'utf8').trim()+';\n');
require('./check-content-defaults').check();
const sourceDir = path.join(root, 'src/frontend/forms');
const manifest = JSON.parse(fs.readFileSync(path.join(sourceDir, 'manifest.json'), 'utf8'));
const source = manifest.entry_order.map(file => fs.readFileSync(path.join(sourceDir, file), 'utf8')).join('');
(async () => {
    const adminI18n = await minify(fs.readFileSync(path.join(root, 'src/admin/i18n/runtime.js'), 'utf8'), { compress: false, mangle: false });
    if (!adminI18n.code) throw new Error('Empty admin i18n runtime');
    fs.writeFileSync(path.join(root, 'assets/js/admin-i18n.js'), '/* Generated from src/admin/i18n/runtime.js. */\n' + adminI18n.code + '\n');
    // Do not compress/reorder logic or rename externally observable functions.
    const result = await minify(source, { compress: false, mangle: false, format: { comments: false } });
    if (!result.code) { throw new Error('Empty Forms runtime'); }
    fs.writeFileSync(path.join(root, 'assets/js/eipsi-forms.js'), '/* Generated from src/frontend/forms/manifest.json; edit those sources. */\n' + result.code + '\n');
    console.log(`Forms runtime: ${manifest.entry_order.length} ordered fragments → assets/js/eipsi-forms.js`);
    const study = await minify(['interactions.js','runtime.js'].map(file=>fs.readFileSync(path.join(root,'src/frontend/study',file),'utf8')).join('\n'), { compress: false, mangle: false });
    if (!study.code) throw new Error('Empty Study runtime');
    fs.writeFileSync(path.join(root, 'assets/js/study-runtime.js'), '/* Generated from src/frontend/study/runtime.js. */\n' + study.code + '\n');
    const builderDir = path.join(root, 'src/admin/study-builder');
    fs.writeFileSync(path.join(builderDir,'message-catalog.js'),'/* Generated from includes/study-engine/runtime/public-messages.json. */\nwindow.EIPSIBuilder.publicMessageCatalog='+JSON.stringify(JSON.parse(fs.readFileSync(path.join(root,'includes/study-engine/runtime/public-messages.json'),'utf8')))+';\n');
    const builderManifest = JSON.parse(fs.readFileSync(path.join(builderDir, 'manifest.json'), 'utf8'));
    const builderSource = builderManifest.entry_order.map(file => fs.readFileSync(path.join(builderDir, file), 'utf8')).join('\n');
    const builder = await minify(builderSource, { compress: false, mangle: false });
    if (!builder.code) throw new Error('Empty Study Builder');
    fs.writeFileSync(path.join(root, 'assets/js/study-builder.js'), '/* Generated from src/admin/study-builder/manifest.json. */\n' + builder.code + '\n');
    const sourceEditor = await minify(fs.readFileSync(path.join(root, 'src/admin/study-builder/source-editor.js'), 'utf8'), { compress: false, mangle: false });
    fs.writeFileSync(path.join(root, 'assets/js/study-builder-source.js'), '/* Generated from src/admin/study-builder/source-editor.js. */\n' + sourceEditor.code + '\n');
    const nativeDir = path.join(root, 'src/admin/assessment-editor');
    const nativeManifest = JSON.parse(fs.readFileSync(path.join(nativeDir, 'manifest.json'), 'utf8'));
    const native = await minify(nativeManifest.entry_order.map(file => fs.readFileSync(path.join(nativeDir, file), 'utf8')).join('\n'), { compress: false, mangle: false });
    if (!native.code) throw new Error('Empty native Assessment Editor');
    fs.writeFileSync(path.join(root, 'assets/js/assessment-editor.js'), '/* Generated from src/admin/assessment-editor/manifest.json. */\n' + native.code + '\n');
    const sharedManifest = JSON.parse(fs.readFileSync(path.join(nativeDir, 'participant-manifest.json'), 'utf8'));
    const shared = await minify(sharedManifest.entry_order.map(file => fs.readFileSync(path.join(nativeDir, file), 'utf8')).join('\n'), { compress: false, mangle: false });
    fs.writeFileSync(path.join(root, 'assets/js/assessment-participant.js'), '/* Generated shared native widgets; transport remains host-owned. */\n' + shared.code + '\n');
})().catch(error => { console.error(error); process.exitCode = 1; });
