/* Execute the complete real consumer in VM; credentials here are synthetic. */
const fs=require('fs'),vm=require('vm'),assert=require('assert');const storage=new Map(),listeners=[],requests=[];let response={ok:true,success:true},reloads=0,total=0;
const localStorage={getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)};
function container(id,proof=''){const attrs={'data-randomization-id':'s41-ui','data-reset-assignment-id':id,'data-reset-fingerprint':'fp_server_actual','data-reset-capability':proof};return {getAttribute:k=>attrs[k]||'',removeAttribute:k=>delete attrs[k]};}
let current=container('11','synthetic-owned-proof');const button={textContent:'Restart',disabled:false,closest:()=>current,addEventListener:(type,fn)=>{button.click=fn;}};
const document={cookie:'eipsi_fingerprint=fp_changed',addEventListener:(type,fn)=>listeners.push(fn),querySelectorAll:q=>q==='.eipsi-randomization-container'?[current]:q.includes('[data-action="restart"]')?[button]:[]};
const window={localStorage,sessionStorage:localStorage,eipsiFormsConfig:{ajaxUrl:'/wp-admin/admin-ajax.php'},console:{debug(){},warn(){},error(){}},location:{reload(){reloads++;}}};
const ctx=vm.createContext({window,document,console:{log(){},warn(){},debug(){}},URLSearchParams,Set,Map,Date,fetch:async(url,opts)=>{requests.push(opts);return{ok:response.ok,status:response.ok?200:403,json:async()=>({success:response.success})};},setInterval(){},clearInterval(){},setTimeout(){},clearTimeout(){}});
vm.runInContext(fs.readFileSync('assets/js/eipsi-save-continue.js','utf8'),ctx);listeners.forEach(fn=>fn());
function instance(){const o=Object.create(window.EIPSISaveContinue.prototype);o.form={closest:()=>current};o.config={ajaxUrl:'/wp-admin/admin-ajax.php'};return o;}
async function test(name,fn){await fn();total++;console.log('PASS '+name);}
(async()=>{
 await test('New render retains row-specific capability and class reset transports it',async()=>{assert(storage.size===1);const ok=await instance().closeRandomizationSession();assert(ok);const p=requests.pop().body;assert.equal(p.get('reset_capability'),'synthetic-owned-proof');assert.equal(p.get('user_fingerprint'),'fp_server_actual');assert.equal(storage.size,0);});
 await test('Reload reuses retained credential without a new server issue',async()=>{current=container('12','proof-for-reload');listeners.at(-1)();current=container('12');response={ok:false,success:false};assert.equal(await instance().closeRandomizationSession(),false);assert.equal(requests.pop().body.get('reset_capability'),'proof-for-reload');assert.equal(storage.size,1);});
 await test('Missing or cleared credential fails closed without a reset request',async()=>{storage.clear();current=container('12');const before=requests.length;assert.equal(await instance().closeRandomizationSession(),false);assert.equal(requests.length,before);});
 await test('A stale tab cannot borrow another incarnation credential',async()=>{current=container('14','new-row-proof');listeners.at(-1)();current=container('13');const before=requests.length;assert.equal(await instance().closeRandomizationSession(),false);assert.equal(requests.length,before);});
 await test('Global restart transports proof and reloads only after confirmed success',async()=>{current=container('15','restart-proof');response={ok:true,success:true};await button.click({preventDefault(){}});const req=requests.pop();assert.equal(req.body.get('reset_capability'),'restart-proof');assert.equal(req.body.get('user_fingerprint'),'fp_server_actual');assert.equal(reloads,1);});
 await test('Global restart failure restores button and preserves proof without reload',async()=>{current=container('16','failure-proof');response={ok:false,success:false};button.textContent='Restart';await button.click({preventDefault(){}});assert.equal(reloads,1);assert.equal(button.disabled,false);assert.equal(button.textContent,'Restart');assert(requests.pop().body.get('reset_capability')==='failure-proof');});
 await test('Global restart without proof neither sends request nor reloads',async()=>{current=container('17');const before=requests.length;await button.click({preventDefault(){}});assert.equal(requests.length,before);assert.equal(reloads,1);assert.equal(button.disabled,false);});
 await test('Recovery restart preserves partial and modal when reset is denied',async()=>{
  const restart={textContent:'Empezar de nuevo',disabled:false,addEventListener:(type,fn)=>{restart.click=fn;}};
  const popup={setAttribute(){},querySelector:()=>restart};
  document.querySelector=()=>null;document.createElement=()=>popup;document.body={appendChild(){}};
  popup.querySelector=q=>q.includes('restart')?restart:null;
  const o=instance();let discarded=0,closed=0;
  o.closeRandomizationSession=async()=>false;o.discardPartial=async()=>{discarded++;};o.forceFormVisible=()=>{};o.closeRecoveryPopup=()=>{closed++;};
  o.showRecoveryPopup({});await restart.click();assert.equal(discarded,0);assert.equal(closed,0);assert.equal(restart.disabled,false);assert.equal(restart.textContent,'Empezar de nuevo');
  o.closeRandomizationSession=async()=>true;await restart.click();assert.equal(discarded,1);assert.equal(closed,1);
 });
 console.log(total+' consumer tests, 0 failures');
})().catch(e=>{console.error(e);process.exitCode=1;});
