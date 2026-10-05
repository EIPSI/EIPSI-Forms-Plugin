const fs=require('fs');
const path=require('path');
const crypto=require('crypto');
const assert=require('assert/strict');
const root=path.resolve(__dirname,'../..');
const contracts=require('./contracts.json');
let count=0;
for(const [file,expected] of Object.entries(contracts.unchanged_files)) {
    assert.equal(crypto.createHash('sha256').update(fs.readFileSync(path.join(root,file))).digest('hex'),expected,'Unrelated boundary changed: '+file);
    console.log('PASS unchanged boundary '+file); count++;
}
const manifest=require('../../src/frontend/forms/manifest.json');
const source=manifest.entry_order.map(file=>fs.readFileSync(path.join(root,'src/frontend/forms',file),'utf8')).join('');
assert.equal(crypto.createHash('sha256').update(source).digest('hex'),manifest.baseline_sha256);
new Function(source);
new Function(fs.readFileSync(path.join(root,'assets/js/eipsi-forms.js'),'utf8'));
console.log('PASS classic composition, baseline hash and executable artifact');count++;
assert.equal(fs.existsSync(path.join(root,'src/frontend/eipsi-save-continue.js')),false,'Unused duplicate remains');
assert.ok(fs.existsSync(path.join(root,'assets/js/eipsi-save-continue.js')),'Active partial client deleted');
console.log('PASS conditioned purge retains active partial consumer');count++;
console.log(`${count} tests, 0 failures`);
