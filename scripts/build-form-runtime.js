/** Compose the historical shared closure without reordering registrations/globals. */
const fs = require('fs');
const path = require('path');
const { minify } = require('terser');
const root = path.resolve(__dirname, '..');
const sourceDir = path.join(root, 'src/frontend/forms');
const manifest = JSON.parse(fs.readFileSync(path.join(sourceDir, 'manifest.json'), 'utf8'));
const source = manifest.entry_order.map(file => fs.readFileSync(path.join(sourceDir, file), 'utf8')).join('');
(async () => {
    // Do not compress/reorder logic or rename externally observable functions.
    const result = await minify(source, { compress: false, mangle: false, format: { comments: false } });
    if (!result.code) { throw new Error('Empty Forms runtime'); }
    fs.writeFileSync(path.join(root, 'assets/js/eipsi-forms.js'), '/* Generated from src/frontend/forms/manifest.json; edit those sources. */\n' + result.code + '\n');
    console.log(`Forms runtime: ${manifest.entry_order.length} ordered fragments → assets/js/eipsi-forms.js`);
})().catch(error => { console.error(error); process.exitCode = 1; });
