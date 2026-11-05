'use strict';
const assert=require('assert/strict'),fs=require('fs'),path=require('path'),{base,env,definition,frame,tick}=require('./support');
(async()=>{const evidence=[];for(const width of [320,360,390,430]){const e=env();try{
 Object.defineProperty(e.w,'innerWidth',{value:width});const d=definition(e);d.elements[0].properties.content_type='number';let held=false,release;const f=frame(d),app=e.A.mountProductRuntime(e.host,f,async()=>{if(held)await new Promise(r=>release=r);return JSON.parse(JSON.stringify(f));});await app.ready;
 const input=e.host.querySelector('input');assert.equal(input.type,'text');assert.equal(input.inputMode,'decimal');input.value='-12.50';input.dispatchEvent(new e.w.Event('input',{bubbles:true}));const value=app.session.answers[d.elements[0].response_key];assert.equal(value,'-12.50');
 const button=e.host.querySelector('.ae-nav-forward'),label=button.textContent;held=true;button.click();assert(button.disabled);assert.strictEqual(e.host.querySelector('.ae-nav-forward'),button);assert.equal(button.textContent,label);assert(button.querySelector('.ae-loading-spinner'));release();await tick();
 const css=fs.readFileSync(path.join(base,'assets/css/assessment-editor.css'),'utf8');assert(/\.ae-loading-spinner\s*\{[^}]*position: absolute/s.test(css));evidence.push({width,buttonNodePreserved:true,labelPreserved:true,inputType:input.type,inputMode:input.inputMode,rawValue:value,spinnerOutOfFlowByCSS:true,layoutMeasured:false});app.destroy();
 }finally{e.w.close();}}
 console.log(JSON.stringify({kind:'Conceptual/DOM checks only, no browser engine or virtual keyboard',evidence},null,2));})().catch(e=>{console.error(e.stack);process.exitCode=1;});
