const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const source=fs.readFileSync(__dirname+'/../../assets/js/eipsi-randomization.js','utf8');
async function run(response, transportError=false){
 const container={innerHTML:''},loading={style:{}};let init=0,calls=0,request;
 const document={getElementById(id){return id==='randomized-form-container'?container:loading;}};
 const window={eipsiRandomizationFormLoad:{nonce:'public-load-nonce',ajaxUrl:'/ajax'},EIPSIForms:{init(){init++;}},location:{search:''}};
 class FormData{constructor(){this.values={};}append(k,v){this.values[k]=v;}}
 const quiet={info(){},warn(){},error(){}};
 const context={window,document,console:quiet,FormData,URLSearchParams,setTimeout(fn){fn();},fetch:async(url,options)=>{calls++;request={url,data:options.body.values};if(transportError)throw new Error('transport');return{ok:true,json:async()=>response};}};
 vm.runInNewContext(source,context);await window.eipsiRandomizeForm(42,'false');return{container,loading,init,calls,request};
}
(async()=>{
 let r=await run({success:true,data:'<form>loaded</form>'});assert.equal(r.request.url,'/ajax');assert.equal(r.request.data.action,'eipsi_load_form');assert.equal(r.request.data.form_id,42);assert.equal(r.request.data.nonce,'public-load-nonce');assert.equal(r.container.innerHTML,'<form>loaded</form>');assert.equal(r.init,1);console.log('PASS M7 frontend HTML load uses public nonce and initializes Forms');
 r=await run({success:false,data:'denied'});assert.equal(r.init,0);assert.match(r.container.innerHTML,/Error/);assert.equal(r.loading.style.display,'none');console.log('PASS M7 frontend authorization failure is handled without initialization');
 r=await run(null,true);assert.equal(r.calls,3);assert.equal(r.init,0);assert.match(r.container.innerHTML,/Error/);console.log('PASS M7 transport retry handles failure and completes loading');
 console.log('3 tests, 0 failures');
})().catch(e=>{console.error(e);process.exitCode=1;});
