'use strict';
const assert=require('assert/strict'),fs=require('fs'),path=require('path');
const {base,plain,tick,env,definition,frame,mount,withdrawal,trace}=require('./support');
let tests=0,failures=0;
async function test(id,fn){tests++;const e=env();try{await fn(e);console.log('PASS '+id);}catch(x){failures++;console.log('FAIL '+id+': '+x.stack);}finally{e.w.close();}}
const click=e=>e.host.querySelector('.ae-nav-forward').click();
(async()=>{
for(const id of ['F1','F2','F3','F4','F5','F6'])await test(id,async e=>{
 const d=definition(e);let hold=false,release,count=0,fail=id==='F5';const f=frame(d);
 const {view}=await mount(e,d,async q=>{if(hold){count++;await new Promise(r=>release=r);if(fail)throw Error('Network');}return plain(f);});hold=true;
 const button=e.host.querySelector('.ae-nav-forward'),text=button.textContent,html=button.innerHTML;button.click();
 if(id==='F1'){assert.equal(button.textContent,text);assert.equal(button.querySelectorAll('.ae-loading-spinner').length,1);assert.equal(button.querySelector('label'),null);}
 if(id==='F2'){assert(button.disabled);assert.equal(button.getAttribute('aria-busy'),'true');assert(e.host.querySelector('input').disabled);}
 if(id==='F3'){button.click();button.click();assert.equal(count,1);}
 if(id==='F6'){assert.equal(button.innerHTML.replace(/<span[^>]*><\/span>/,''),html);const css=fs.readFileSync(path.join(base,'assets/css/assessment-editor.css'),'utf8');assert(/\.ae-loading-spinner\s*\{[^}]*position: absolute/s.test(css));assert(!/button\.ae-is-loading\s*\{[^}]*(?:padding|width|height|display|gap):/s.test(css));}
 release();await tick();await tick();if(['F4','F5'].includes(id)){assert(!e.host.querySelector('.ae-loading-spinner'));assert.equal(e.host.getAttribute('aria-busy'),null);assert(!e.host.querySelector('button').disabled);if(fail)assert(e.host.querySelector('[role=status]').textContent);}
 view.destroy();
});
for(const id of ['W1','W2','W3','W4','W5','W6'])await test(id,async e=>{
 const button=withdrawal(e),I=e.w.EIPSIStudyInteraction;let release,calls=0;const fail=id==='W5',lookup=async email=>{calls++;assert.equal(email,'fixture@example.test');await new Promise(r=>release=r);if(fail)throw Error('EXACT_LOOKUP_ERROR');return{revision:7};};
 const run=I.click(e.host,{target:button},lookup);
 if(id==='W1')assert(button.querySelector('.eipsi-loading-spinner'));
 if(id==='W2'){assert.equal(button.getAttribute('aria-busy'),'true');assert(button.disabled);}
 if(id==='W3'){await I.click(e.host,{target:button},lookup);assert.equal(calls,1);}
 release();await run;
 if(id==='W4'){const modal=e.host.querySelector('.eipsi-withdrawal-confirmation');assert(modal.open);assert.equal(modal.dataset.phrase,'KEEP');assert.equal(modal.querySelector('[name=mode]').value,'RETAIN_FOR_ANALYSIS');assert.equal(modal.querySelector('[name=expected_revision]').value,'7');assert.equal(modal.querySelector('[name=email]').value,'fixture@example.test');}
 if(id==='W5'){assert.equal(e.host.querySelector('.eipsi-withdrawal-error').textContent,'EXACT_LOOKUP_ERROR');assert(!e.host.querySelector('.eipsi-withdrawal-confirmation').open);}
 assert(!button.querySelector('.eipsi-loading-spinner'));assert.equal(button.getAttribute('aria-busy'),null);assert(!button.disabled);
});
for(const id of ['V1','V2','V3','V4','V5','V6','V7','V8'])await test(id,async e=>{
 const d=definition(e,['text','text']);d.flow.metadata_profile='detailed';for(const [i,q]of d.elements.entries()){q.properties.required=true;q.properties.required_message='REQUIRED_'+i;}
 const {view}=await mount(e,d),inputs=[...e.host.querySelectorAll('.ae-widget input')];let focused=0;e.host.addEventListener('focusin',()=>focused++);
 const before=plain(view.session.metadata.snapshot());click(e);
 if(id==='V1')assert(inputs.every(n=>n.getAttribute('aria-invalid')==='true'));
 if(id==='V2')for(const n of inputs){const error=e.w.document.getElementById(n.getAttribute('aria-describedby'));assert(error);assert(error.closest('.ae-preview-question').contains(n));}
 if(id==='V3')assert.deepEqual([...e.host.querySelectorAll('.ae-field-error')].map(n=>n.textContent),plain(view.session.errors().map(x=>x.message)));
 if(id==='V4'){assert.equal(view.session.errors().length,2);assert(d.elements.every(q=>q.properties.required));}
 if(id==='V5'){for(const n of inputs){n.value='Valid';n.dispatchEvent(new e.w.Event('input',{bubbles:true}));}click(e);await tick();assert.equal(e.host.querySelector('[aria-invalid=true]'),null);}
 if(id==='V6'){const ids=inputs.map(n=>n.getAttribute('aria-describedby'));assert.notEqual(ids[0],ids[1]);assert.equal(e.w.document.getElementById(ids[1]).textContent,'REQUIRED_1');}
 if(id==='V7')assert.equal(focused,0);
 if(id==='V8')assert.deepEqual(plain(view.session.metadata.snapshot()),before);
 assert.equal(e.host.querySelectorAll('.ae-field-error[role=alert],.ae-field-error[aria-live=polite]').length,0);view.destroy();
});
for(const id of ['N1','N2','N3','N4','N5','N6'])await test(id,e=>{
 const d=definition(e,['text','text','number']);d.elements[0].properties.content_type='number';const session=new e.A.PreviewSession(d);const nodes=d.elements.map(q=>e.A.widget(q,session).querySelector('input'));
 if(id==='N1'){assert.equal(nodes[0].type,'text');assert.equal(nodes[0].inputMode,'decimal');}
 if(id==='N2'){assert.equal(nodes[1].type,'text');assert.equal(nodes[1].inputMode,'');}
 if(id==='N3'){assert.equal(nodes[2].type,'text');assert.equal(nodes[2].inputMode,'decimal');nodes[2].value='-7.5';nodes[2].dispatchEvent(new e.w.Event('input'));assert.equal(session.answers[d.elements[2].response_key],-7.5);}
 for(const raw of ['-12.50','1,5','  -3.5  ','1e3','not-a-number']){nodes[0].value=raw;nodes[0].dispatchEvent(new e.w.Event('input'));assert.equal(session.answers[d.elements[0].response_key],raw);}
 if(id==='N5'){const raw='-123.45';nodes[0].dispatchEvent(new e.w.Event('paste',{bubbles:true,cancelable:true}));nodes[0].value=raw;nodes[0].dispatchEvent(new e.w.Event('input'));assert.equal(nodes[0].value,raw);assert.equal(session.answers[d.elements[0].response_key],raw);}
 if(id==='N6'){const before=JSON.parse(fs.readFileSync(path.join(__dirname,'before-contract.json'),'utf8'));assert.deepEqual(['-12.50','1,5','  -3.5  ','1e3','not-a-number'].map(raw=>e.A.controls.answerError(d.elements[0],raw,true)),before.validation.numericText);}
});
for(const id of ['C1','C2','C3','C4','C5','C6','C7'])await test(id,async e=>{
 const d=definition(e,['date']);d.flow.metadata_profile='detailed';const session=new e.A.PreviewSession(d),q=d.elements[0];session.set(q,'2026-11-04');const box=e.A.widget(q,session);e.host.append(box);box.querySelector('.ae-calendar-open').click();
 const [prev,next]=box.querySelectorAll('.ae-calendar-navigation button');const before=plain(session.metadata.snapshot());let focuses=0;box.addEventListener('focusin',()=>focuses++);
 const button=id==='C2'?next:prev;button.focus();focuses=0;button.click();button.click();assert.equal(e.w.document.activeElement,button);assert(button.isConnected);
 if(id==='C3')assert.notEqual(e.w.document.activeElement,e.w.document.body);
 if(id==='C4'){next.click();next.click();assert.equal(box.querySelector('[aria-current=date]').textContent,'4');box.querySelector('[aria-current=date]').click();assert.equal(session.answers[q.response_key],'2026-11-04');}
 if(id==='C5'){const input=box.querySelector('input');input.value='0001-01-01';box.querySelector('.ae-calendar-open').click();assert(prev.disabled);input.value='9999-12-31';box.querySelector('.ae-calendar-open').click();assert(next.disabled);assert(!input.hasAttribute('min'));assert(!input.hasAttribute('max'));}
 if(id==='C6'){assert.equal(prev.tabIndex,0);assert.equal(next.tabIndex,0);assert.deepEqual([...box.querySelector('.ae-calendar-navigation').querySelectorAll('button')],[prev,next]);const cancel=new e.w.Event('cancel',{cancelable:true});box.querySelector('dialog').dispatchEvent(cancel);assert.equal(e.w.document.activeElement,box.querySelector('.ae-calendar-open'));assert(!box.querySelector('dialog').open);}
 if(id==='C7'){assert.equal(focuses,0);assert.deepEqual(plain(session.metadata.snapshot()),before);
   const {view}=await mount(e,d);e.host.querySelector('.ae-calendar-open').click();const nativePrevious=e.host.querySelector('.ae-calendar-navigation button');nativePrevious.focus();const nativeBefore=plain(view.session.metadata.snapshot());nativePrevious.click();nativePrevious.click();assert.equal(e.w.document.activeElement,nativePrevious);assert.deepEqual(plain(view.session.metadata.snapshot()),nativeBefore);view.destroy();}

});
for(const id of ['A1','A2','A3','A4','A5','A6','A7','A8'])await test(id,e=>{
 const types=['single_choice','multiple_choice','likert'],d=definition(e,types);for(const q of d.elements){q.properties.helper='Authored instructions';q.properties.label_html='<p><strong>Authored question</strong></p>';}
 const view=e.A.mountParticipant(e.host,d);for(const q of d.elements){const block=e.host.querySelector('[data-ae-question-id="'+q.id+'"]'),box=block.querySelector('.ae-widget'),inputs=[...box.querySelectorAll('input')];
 if(['A1','A2','A3'].includes(id)){assert.equal(box.getAttribute('role'),q.type==='multiple_choice'?'group':'radiogroup');assert.equal(e.w.document.getElementById(box.getAttribute('aria-labelledby')).textContent,'Authored question');}
 if(id==='A4')assert.equal(e.w.document.getElementById(box.getAttribute('aria-describedby')).textContent,'Authored instructions');
 if(id==='A5'){assert(inputs.every(n=>n.closest('label').textContent));inputs[0].click();assert(view.session.touched[q.response_key]);}
 if(id==='A6')assert.deepEqual(inputs.map(n=>n.value),plain(q.options.map(o=>o.id)));
 if(id==='A7')assert.deepEqual(inputs.map(n=>n.closest('label').textContent),plain(q.options.map(o=>o.label)));
 if(id==='A8'){assert(inputs.every(n=>n.tabIndex===0&&!n.hasAttribute('role')));assert(inputs.every(n=>n.type===(q.type==='multiple_choice'?'checkbox':'radio')));assert.equal(box.onkeydown,null);}
 }view.destroy();
});
await test('V-server-matrix-and-clear',async e=>{
 const d=definition(e,['text','matrix']);let reject=false;const f=frame(d),row=d.elements[1].properties.matrix_rows[0];f.has_logic=true;
 const {view}=await mount(e,d,async()=>{if(reject){const error=Error('Validation');error.status=400;error.validationErrors=[{id:d.elements[0].id,message:'AUTHORED_TEXT'},{id:row.id,message:'AUTHORED_ROW'}];throw error;}return plain(f);});reject=true;click(e);await tick();
 const source=e.host.querySelector('[data-row-id="'+row.id+'"]');assert(source.querySelector('input[aria-invalid=true]'));assert.equal(source.querySelector('.ae-field-error').textContent,'AUTHORED_ROW');assert.equal(e.host.querySelectorAll('.ae-field-error').length,2);
 const before=plain(view.session.metadata.snapshot());e.A.presentValidationErrors(e.host,[]);assert.equal(e.host.querySelector('[aria-invalid=true]'),null);assert.deepEqual(plain(view.session.metadata.snapshot()),before);view.destroy();
});
await test('V-helper-description-survives-clear',e=>{
 const d=definition(e,['single_choice']),q=d.elements[0];q.properties.helper='Instruction';const view=e.A.mountParticipant(e.host,d),box=e.host.querySelector('.ae-widget'),helper=box.getAttribute('aria-describedby');
 e.A.presentValidationErrors(e.host,[{id:q.id,message:'EXACT'}]);const error=e.host.querySelector('.ae-field-error').id;assert(box.getAttribute('aria-describedby').split(' ').includes(error));e.A.presentValidationErrors(e.host,[]);assert.equal(box.getAttribute('aria-describedby'),helper);view.destroy();
});
const expected=JSON.parse(fs.readFileSync(path.join(__dirname,'before-contract.json'),'utf8'));let actual;
for(const key of ['definition','answers','values','required','validation','randomization','metadata','calls','navigation','conditional'])await test('EQ-'+key,async()=>{actual=actual||await trace();assert.deepEqual(actual[key],expected[key]);});
console.log(`${tests} tests, ${failures} failures`);process.exitCode=failures?1:0;
})();
