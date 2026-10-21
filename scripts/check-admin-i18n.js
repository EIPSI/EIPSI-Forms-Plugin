'use strict';
const fs = require('fs');
const path = require('path');
const parser = require('@babel/parser');
const traverse = require('@babel/traverse').default;
function parseCatalog(source) {
    const value = JSON.parse(source);
    const visit = node => {
        if (node.type !== 'ObjectExpression') return;
        const seen = new Set();
        for (const property of node.properties) {
            const key = property.key.value;
            if (seen.has(key)) throw new Error('Duplicate admin catalog key: ' + key);
            seen.add(key);visit(property.value);
        }
    };
    visit(parser.parseExpression(source));
    return value;
}
function validateCatalog(catalog) {
    if (!catalog || Array.isArray(catalog) || typeof catalog !== 'object') throw new Error('Invalid admin catalog');
    const tokens = text => (text.match(/\[[a-zA-Z_][a-zA-Z_0-9]*\]|\{[a-zA-Z_][a-zA-Z_0-9]*\}|%%|%(?:\d+\$)?[-+0# ]*\d*(?:\.\d+)?[sdif]/g) || []).sort();
    const requireText = (text, key) => { if (typeof text !== 'string' || !text.trim()) throw new Error('Missing admin translation: ' + key); };
    const match = (a, b, key) => {
        if (JSON.stringify(tokens(a)) !== JSON.stringify(tokens(b))) throw new Error('Admin translation placeholders differ: ' + key);
        const code=text=>[...text.matchAll(/<code>(.*?)<\/code>/g)].map(m=>m[1]).sort();
        if(JSON.stringify(code(a))!==JSON.stringify(code(b)))throw new Error('Admin translation technical code differs: '+key);
    };
    for (const [english, spanish] of Object.entries(catalog)) {
        requireText(english, english);
        if (spanish && typeof spanish === 'object' && !Array.isArray(spanish)) {
            if (Object.keys(spanish).sort().join(',') !== 'one,other,plural') throw new Error('Invalid admin plural forms: ' + english);
            for (const text of Object.values(spanish)) requireText(text, english);
            match(english, spanish.one, english);match(spanish.plural, spanish.other, english);match(english, spanish.plural, english);
        } else { requireText(spanish, english);match(english, spanish, english); }
    }
    return Object.keys(catalog).length;
}
function check() {
    const root=path.resolve(__dirname,'..'), catalog=parseCatalog(fs.readFileSync(path.join(root,'includes/i18n/admin-catalog.json'),'utf8'));
    const count=validateCatalog(catalog),missing=[];
    const fields=parseCatalog(fs.readFileSync(path.join(root,'includes/i18n/admin-fields.json'),'utf8'));
    for(const [id,english] of Object.entries(fields))if(!Object.hasOwn(catalog,english))missing.push('field '+id+': '+english);
    for(const dir of ['src/admin/assessment-editor','src/admin/study-builder','assets/js'])for(const file of fs.readdirSync(path.join(root,dir))){
        if(!file.endsWith('.js') || dir==='assets/js'&&!['configuration-panel.js','email-test.js'].includes(file))continue;
        const source=fs.readFileSync(path.join(root,dir,file),'utf8');
        traverse(parser.parse(source,{sourceType:'unambiguous'}),{CallExpression(p){const call=p.node,name=source.slice(call.callee.start,call.callee.end);if(!/^(?:(?:window|root)\.EIPSIAdminI18n|I)\.[tfn]$/.test(name))return;const arg=call.arguments[0];if(arg?.type==='StringLiteral'&&!Object.hasOwn(catalog,arg.value))missing.push(dir+'/'+file+': '+arg.value);}});
    }
    const php=['admin/configuration.php','admin/services/class-smtp-service.php','admin/privacy-dashboard.php','admin/tabs/schema-status-tab.php','admin/ajax-handlers.php','includes/bootstrap/asset-callbacks.php','includes/assessment-editor/Admin.php','includes/study-engine/builder/Admin.php','includes/study-engine/builder/EmailLibrary.php','includes/external-api/Admin.php','includes/external-api/OperationsAdmin.php','includes/external-api/ExternalApiHealth.php','includes/scheduler/Admin.php','includes/storage/class-external-submission-store.php','includes/schema/class-schema-diagnostic.php'];
    for(const file of php){const source=fs.readFileSync(path.join(root,file),'utf8');for(const match of source.matchAll(/eipsi_admin_(?:translate|format|plural)\(\s*('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")/g)){const raw=match[1],english=raw[0]==='"'?JSON.parse(raw):raw.slice(1,-1).replace(/\\(['\\])/g,'$1');if(!Object.hasOwn(catalog,english))missing.push(file+': '+english);}}
    const config=fs.readFileSync(path.join(root,'includes/scheduler/Config.php'),'utf8');
    for(const match of config.matchAll(/new ConfigError\('([^']+)'/g))if(!fields['scheduler_error_'+match[1]])missing.push('Scheduler error '+match[1]);
    if(missing.length)throw new Error('Missing approved admin catalog keys:\n'+[...new Set(missing)].join('\n'));
    return count;
}
module.exports = { parseCatalog, validateCatalog, check };
if (require.main === module) console.log('Admin i18n: ' + check() + ' approved catalog entries validated');
