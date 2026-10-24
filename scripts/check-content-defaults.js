'use strict';
const fs=require('fs'),assert=require('node:assert/strict'),path=require('path');
function check(override=null){
 const base=path.resolve(__dirname,'..'),c=override??JSON.parse(fs.readFileSync(path.join(base,'includes/i18n/content-defaults.json'))),tokens=s=>(s.match(/\[[a-zA-Z_]+\]|\{\{[a-z_]+\}\}/g)||[]).sort();
 function walk(en,es,at=''){
  assert.equal(typeof en,typeof es,at);assert.equal(en===null,es===null,at);assert.equal(Array.isArray(en),Array.isArray(es),at);
  if(en&&typeof en==='object'){assert.deepEqual(Object.keys(en).sort(),Object.keys(es).sort(),at);for(const key of Object.keys(en))walk(en[key],es[key],at+'.'+key);}
  else if(typeof en==='string'){assert.deepEqual(tokens(en),tokens(es),at+' placeholders');assert(!/<(?:script|iframe|object)\b|\bon\w+\s*=|(?:href|src)\s*=\s*[\"']?\s*(?:javascript:|data:)/i.test(en+es),at+' unsafe markup');for(const html of [en,es])for(const match of html.matchAll(/<\/?([a-z][a-z0-9]*)\b/gi))assert('p br strong b em i u s del sub sup h1 h2 h3 h4 h5 h6 ul ol li blockquote span figure figcaption a img hr pre code table thead tbody tfoot tr th td'.split(' ').includes(match[1].toLowerCase()),at+' tag');}
  else assert.equal(en,es,at+' technical value');
 }
 for(const language of ['en','es']){assert.deepEqual(c[language].flow.messages,c[language].flow_messages);assert.deepEqual(c[language].initial.templates,c[language].emails);}
 walk(c.en,c.es);assert.equal(Object.keys(c.en.question_defaults).length,13);assert.equal(Object.keys(c.en.public_messages).length,43);assert.equal(Object.keys(c.en.emails).length,3);
 const generated='/* Generated from includes/i18n/content-defaults.json. */\nglobalThis.EIPSIContentCatalog='+fs.readFileSync(path.join(base,'includes/i18n/content-defaults.json'),'utf8').trim()+';\n';assert.equal(fs.readFileSync(path.join(base,'src/admin/i18n/content-catalog.js'),'utf8'),generated,'Content catalogue stale');
 for(const row of JSON.parse(fs.readFileSync(path.join(base,'includes/study-engine/runtime/public-messages.json'))))if(!row.retired)for(const language of ['en','es'])assert.equal(row.defaults[language],c[language].public_messages[row.key]);
}
module.exports={check};if(require.main===module){check();console.log('Content defaults: parity, tokens, types, HTML, 13 questions, 43 messages, 3 emails PASS');}
