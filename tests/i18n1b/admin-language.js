'use strict';
const fs=require('fs'),path=require('path');
/** Existing DOM regressions explicitly exercise their original Spanish UI fixture. */
module.exports=function adminLanguage(window,language='es'){
    window.eipsiAdminLanguage={language,fields:JSON.parse(fs.readFileSync(path.resolve(__dirname,'../../includes/i18n/admin-fields.json'),'utf8')),messages:JSON.parse(fs.readFileSync(path.resolve(__dirname,'../../includes/i18n/admin-catalog.json'),'utf8'))};
    window.eval(fs.readFileSync(path.resolve(__dirname,'../../src/admin/i18n/runtime.js'),'utf8'));
};
