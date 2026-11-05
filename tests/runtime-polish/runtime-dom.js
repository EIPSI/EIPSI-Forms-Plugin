'use strict';
const assert=require('assert/strict'),fs=require('fs'),path=require('path');
const {base,env,definition,frame,plain,mount,tick}=require('../ux-f1a/support');
let tests=0,failed=0;
async function test(name,fn){tests++;const e=env();try{await fn(e);console.log('PASS '+name);}catch(error){failed++;console.log('FAIL '+name+': '+error.stack);}finally{e.w.close();}}
(async()=>{
for(const position of ['top','bottom'])for(const mode of ['none','pages','bar','both'])await test('Progress '+position+' '+mode,async e=>{
 const d=definition(e,['text']);d.flow.progress=mode;d.flow.progress_position=position;
 const {view}=await mount(e,d);const progress=e.host.querySelector('.ae-assessment-progress');
 if(mode==='none')assert(!progress);else{
  assert(progress);assert.equal(progress.querySelectorAll('progress').length,['bar','both'].includes(mode)?1:0);
  assert.equal(progress.querySelectorAll('.ae-page-count').length,['pages','both'].includes(mode)?1:0);
  if(position==='top')assert.equal(e.host.firstElementChild,progress);
  else assert(progress.previousElementSibling.classList.contains('ae-preview-actions'));
 }
 e.host.querySelector('.ae-nav-forward').click();await tick();assert(!e.host.querySelector('.ae-assessment-progress'));view.destroy();
});
for(const kind of ['pointerup','touchend','keyup'])await test('First VAS '+kind+' survives an older logic projection',async e=>{
 const d=definition(e,['text','vas']);d.elements[1].properties.required=true;
 let f=frame(d),release=null,blocked=false;const requests=[];f.has_logic=true;f.participant_contract='EIPSI_PARTICIPANT_FRAME_V2';f.projection_ref='before';
 const app=e.A.mountProductRuntime(e.host,f,async request=>{
  requests.push(plain(request));if(request.action==='logic'&&!blocked){blocked=true;await new Promise(r=>release=r);}
  f={...f,revision:f.revision+1,session:{answers:request.answers,touched:request.touched,page:0,metadata:request.metadata}};
  if(request.action==='logic')f.projection_ref='after';if(request.action==='submit')f.state='COMPLETED';return plain(f);
 });await app.ready;
 const text=e.host.querySelector('[data-widget=text] input');text.value='branch';text.dispatchEvent(new e.w.Event('input',{bubbles:true}));text.dispatchEvent(new e.w.Event('change',{bubbles:true}));
 await new Promise(r=>setTimeout(r,270));assert(release);
 const range=e.host.querySelector('input[type=range]');range.dispatchEvent(kind==='keyup'?new e.w.KeyboardEvent(kind,{key:'Home',bubbles:true}):new e.w.Event(kind,{bubbles:true}));
 release();await tick();await tick();assert.equal(app.session.touched[d.elements[1].response_key],true);assert.equal(app.session.answers[d.elements[1].response_key],Number(range.value));assert.equal(app.session.errors().length,0);e.host.querySelector('.ae-nav-forward').click();await tick();await tick();assert.equal(app.frame.state,'COMPLETED');assert.equal(requests.find(r=>r.action==='submit').touched[d.elements[1].response_key],true);app.destroy();
});
for(const position of ['top','bottom'])await test('Assessment preview respects '+position,e=>{
 const d=definition(e);d.flow.progress='both';d.flow.progress_position=position;const preview=e.A.openPreview(d),surface=preview.dialog.querySelector('.ae-preview-frame'),progress=surface.querySelector('.ae-assessment-progress');
 assert(progress);if(position==='top')assert.equal(surface.firstElementChild,progress);else assert(progress.previousElementSibling.classList.contains('ae-preview-actions'));preview.close();
});
await test('Progress inspector defaults to top without mutating old definitions',e=>{
 const d=definition(e);delete d.flow.progress_position;const before=JSON.stringify(d);e.A.flowInspector(d,e.host,()=>{});assert.equal(JSON.stringify(d),before);
 const position=e.host.querySelector('[data-flow-property=progress_position]');assert.equal(position.value,'top');position.value='bottom';position.dispatchEvent(new e.w.Event('change'));assert.equal(d.flow.progress_position,'bottom');
});
await test('Tall initial assessment expands synchronously without waiting for stable shrink',e=>{
 let queued=[];e.w.requestAnimationFrame=fn=>(queued.push(fn),queued.length);e.w.cancelAnimationFrame=()=>{};
 class Observer{constructor(fn){this.fn=fn;}observe(){}disconnect(){}}e.w.ResizeObserver=Observer;e.w.MutationObserver=Observer;
 e.w.eval(fs.readFileSync(path.join(base,'src/frontend/study/runtime.js'),'utf8'));
 const iframe=e.w.document.createElement('iframe');e.host.append(iframe);iframe.className='eipsi-native-assessment-frame';iframe.style.height='500px';iframe.contentDocument.body.style.margin='0';iframe.contentDocument.body.getBoundingClientRect=()=>({bottom:2400});
 e.w.EIPSIStudyInteraction.fitAssessment(iframe);assert.equal(iframe.style.height,'2400px');
});
console.log(`${tests} tests, ${failed} failures`);if(failed)process.exitCode=1;
})().catch(error=>{console.error(error);process.exitCode=1;});
