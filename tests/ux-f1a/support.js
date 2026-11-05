'use strict';
const fs=require('fs'),path=require('path'),{createHash}=require('crypto'),{JSDOM}=require('jsdom');
const base=path.resolve(__dirname,'../..'),plain=v=>JSON.parse(JSON.stringify(v)),tick=()=>new Promise(r=>setTimeout(r,0));
function env(baseline=null){
 const dom=new JSDOM('<main id="root" class="ae-root ae-native-runtime ae-designed"></main>',{url:'https://fixture.invalid/',runScripts:'outside-only'}),w=dom.window;let counter=1;
 Object.defineProperty(w,'crypto',{value:{getRandomValues(values){const bytes=createHash('sha256').update(String(counter++)).digest();for(let i=0;i<values.length;i++)values[i]=bytes[i%bytes.length];return values;},randomUUID(){return 'fixture-uuid';}}});
 Object.defineProperty(w.navigator,'hardwareConcurrency',{value:4});
 w.Date.now=()=>1700000000000;w.HTMLCanvasElement.prototype.getContext=()=>null;
 require('../i18n1b/admin-language.js')(w);
 const read=f=>fs.readFileSync(baseline&&fs.existsSync(path.join(baseline,f))?path.join(baseline,f):path.join(base,f),'utf8');
 for(const f of JSON.parse(read('src/admin/assessment-editor/manifest.json')).entry_order)w.eval(read(path.normalize('src/admin/assessment-editor/'+f)));
 w.eval(read('src/admin/assessment-editor/product-runtime.js'));
 return{dom,w,A:w.EIPSIAssessment,host:w.document.getElementById('root')};
}
function definition(e,types=['text']){const d=e.A.assessment();d.elements=types.map(t=>e.A.element(t));return d;}
const frame=d=>({execution_id:'NX_F1A',version_id:'NV_F1A',definition_hash:'a'.repeat(64),definition:d,revision:0,state:'ACTIVE',page:0,session:null,csrf:'fixture'});
async function mount(e,d=definition(e),override=null){let f=frame(d),calls=[];const view=e.A.mountProductRuntime(e.host,f,override|| (async q=>{calls.push(plain(q));if(q.action==='next')f.page++;if(q.action==='back')f.page--;if(q.action==='submit')f.state='COMPLETED';return{...plain(f),revision:++f.revision};}));await view.ready;return{view,calls};}
function withdrawal(e){e.host.className='eipsi-study';e.host.innerHTML='<dialog class="eipsi-withdrawal-dialog" data-withdrawal-lookup="1"><input name="email" type="email" value="fixture@example.test"><p class="eipsi-withdrawal-error" role="alert" hidden></p><button data-withdrawal-choice="RETAIN_FOR_ANALYSIS" data-confirm-phrase="KEEP">Keep</button></dialog><dialog class="eipsi-withdrawal-confirmation"><form><input name="expected_revision"><input name="email"><input name="mode"><input data-withdrawal-confirm-text><button data-confirm-withdrawal disabled>Confirm</button></form></dialog>';return e.host.querySelector('[data-withdrawal-choice]');}
async function trace(baseline=null){
 const e=env(baseline),{A,w,host}=e;try{
 const d=definition(e,['text','single_choice','multiple_choice','likert','number','date','vas','matrix','page_break','text']);delete d.elements.find(q=>q.type==='date').properties.date_year_label;d.flow.metadata_profile='detailed';d.flow.allow_back=true;d.elements[1].properties.randomize_options=true;d.elements[1].properties.label_html='<p>Question <a href="https://fixture.invalid/context">reference</a></p>';d.elements[1].properties.helper_html='<p>Instruction <a href="https://fixture.invalid/help">reference</a></p>';d.elements[0].properties.content_type='free';d.elements[0].properties.required=true;
 let f=frame(d);f.randomization=w.EIPSIAssessmentRandomization.resolve(d,'F1A_fixed_seed',1700000000000);const calls=[];
 const app=A.mountProductRuntime(host,f,async q=>{calls.push(plain(q));if(q.action==='next')f.page++;if(q.action==='back')f.page--;if(q.action==='submit')f.state='COMPLETED';return{...plain(f),revision:++f.revision};});await app.ready;
 for(const link of host.querySelectorAll('.ae-preview-question .ae-label a,.ae-preview-question .ae-helper a')){link.focus();link.blur();}
 const q=d.elements[0],input=host.querySelector('[data-widget=text] input');input.focus();input.value='-12.50';input.dispatchEvent(new w.Event('input',{bubbles:true}));input.blur();
 const validation=plain(app.session.errors());
 host.querySelector('[data-widget=single_choice] input').click();host.querySelector('[data-widget=multiple_choice] input').click();host.querySelector('[data-widget=likert] input').click();
 for(const [type,value]of [['number','-7.25'],['date','2026-11-04'],['vas','75']]){const n=host.querySelector(`[data-widget=${type}] input`);n.value=value;n.dispatchEvent(new w.Event('input',{bubbles:true}));}
 host.querySelectorAll('[data-widget=matrix] tr[data-row-id]').forEach(row=>row.querySelector('input').click());
 const answers=plain(app.session.answers),values=plain(app.session.values()),metadata=plain(app.session.metadata.snapshot());
 const before=A.serialize(d);host.querySelector('.ae-nav-forward').click();await tick();await tick();host.querySelector('.ae-nav-back').click();await tick();await tick();host.querySelector('.ae-nav-forward').click();await tick();await tick();
 const result={definition:before,answers,values,required:d.elements.map(x=>[x.id,x.properties.required]),validation:{errors:validation,numericText:['-12.50','1,5','  -3.5  ','1e3','not-a-number'].map(raw=>A.controls.answerError({...q,properties:{...q.properties,content_type:'number'}},raw,true))},randomization:plain(app.session.randomization),metadata,calls,navigation:{page:app.session.page,state:app.frame.state,visible:[...host.querySelectorAll('[id^=native-]')].map(n=>n.id)}};app.destroy();return {...plain(result),conditional:await conditionalTrace(baseline)};
 }finally{w.close();}
}
async function conditionalTrace(baseline){
 const e=env(baseline),{A,w,host}=e;try{
 const d=definition(e,['text','text','text']);d.flow.metadata_profile='detailed';let f=frame(d);f.has_logic=true;f.participant_contract='EIPSI_PARTICIPANT_FRAME_V2';f.projection_ref='before';f.randomization={contract:'EIPSI_RANDOMIZATION_REFERENCE_V1',reference:'fixed'};const calls=[];
 const app=A.mountProductRuntime(host,f,async q=>{calls.push(plain(q));f={...f,revision:f.revision+1,session:{answers:q.answers,touched:q.touched,page:0,metadata:q.metadata}};if(q.action==='logic'){f.logical_skipped_ids=[d.elements[1].id];f.projection_ref='after';}return plain(f);});await app.ready;
 const before=[...host.querySelectorAll('.ae-preview-question')].map(n=>n.id),input=host.querySelector('input');input.value='branch';input.dispatchEvent(new w.Event('input',{bubbles:true}));input.dispatchEvent(new w.Event('change',{bubbles:true}));await new Promise(r=>setTimeout(r,300));
 const out={before,after:[...host.querySelectorAll('.ae-preview-question')].map(n=>n.id),calls,answers:plain(app.session.answers),metadata:plain(app.session.metadata.snapshot()),randomization:plain(app.session.randomization)};app.destroy();return out;
 }finally{w.close();}
}
module.exports={base,plain,tick,env,definition,frame,mount,withdrawal,trace};
if(require.main===module)trace(process.env.UX_F1A_BASELINE||null).then(v=>console.log(JSON.stringify(v,null,2))).catch(e=>{console.error(e.stack);process.exitCode=1;});
