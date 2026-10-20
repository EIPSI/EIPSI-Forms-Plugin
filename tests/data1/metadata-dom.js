'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict');
let tests=0,failed=0;
function test(name,fn){tests++;try{fn();console.log('PASS '+name);}catch(e){failed++;console.log('FAIL '+name+': '+e.stack);}}
function setup(profile,policy,snapshot=null,environment=null){
 const root={EIPSIAssessment:{copy:v=>JSON.parse(JSON.stringify(v)),walk:(es,fn)=>es.forEach(fn),effectiveMetadataPolicy:policy}};
 if(environment)Object.assign(root,environment);else Object.defineProperty(root,'navigator',{get(){throw Error('Forbidden browser access');}});
 vm.runInNewContext(fs.readFileSync('src/admin/assessment-editor/metadata.js','utf8'),{globalThis:root,Intl,Map});
 const d={id:'A',contract:'X',flow:{metadata_profile:profile},elements:[{id:'I',type:'number',response_key:'R',properties:{variable_name:'n'}}]};
 return new root.EIPSIAssessment.MetadataRecorder(d,()=>1000,snapshot);
}
const off={session:[],page:[],item:[],client:[],events:[]};
test('Disabled browser metadata is never read in Detailed',()=>{const r=setup('detailed',off);assert.equal(r.snapshot().context,undefined);});
test('Basic never reads browser metadata',()=>{const r=setup('basic',null);assert.equal(r.snapshot().context,undefined);});
test('Disabled fields are absent from transmitted session page item and events',()=>{const r=setup('detailed',off);r.present('P',r.definition.elements);r.interact(r.definition.elements[0],4);r.complete();const s=r.snapshot();assert.equal(s.started_at,undefined);assert.equal(s.items.R.interaction_count,undefined);assert.equal(s.items.R.trajectory,undefined);assert.equal(s.events.length,0);});
test('Restoring filtered counters can navigate interact and complete without NaN',()=>{const r=setup('detailed',off);r.present('P',r.definition.elements);const next=setup('detailed',off,r.snapshot());next.present('P',next.definition.elements);next.interact(next.definition.elements[0],5);next.resume();next.complete();assert.equal(Number.isFinite(next.data.items.R.interaction_count),true);assert.equal(Number.isFinite(next.data.duration),true);});
test('Requested client characteristics retain numeric boolean and renderer types',()=>{
 const gl={VENDOR:1,RENDERER:2,getExtension:()=>null,getParameter:k=>k===1?'Vendor':'Renderer'};
 const env={navigator:{hardwareConcurrency:8,cookieEnabled:false},screen:{colorDepth:24},devicePixelRatio:1.25,document:{createElement:()=>({getContext:()=>gl})}};
 const policy={...off,client:['webgl_renderer','screen_depth','pixel_ratio','cpu_cores','cookies_enabled']};
 const c=setup('detailed',policy,null,env).snapshot().context;
 assert.equal(c.webgl_renderer,'Vendor | Renderer');assert.equal(c.screen_depth,24);assert.equal(c.pixel_ratio,1.25);assert.equal(c.cpu_cores,8);assert.equal(c.cookies_enabled,false);assert.equal(c.ip_address,undefined);
});
test('Unavailable client characteristics stay null rather than invented values',()=>{
 const policy={...off,client:['webgl_renderer','screen_depth','pixel_ratio','cpu_cores','cookies_enabled']};
 const c=setup('standard',policy,null,{navigator:{},document:{createElement:()=>({getContext:()=>null})}}).snapshot().context;
 for(const value of Object.values(c))assert.equal(value,null);
});
test('Disabled WebGL and hardware fields are never probed',()=>{
 const env={};for(const key of ['navigator','screen','document','devicePixelRatio'])Object.defineProperty(env,key,{enumerable:true,get(){throw Error('Forbidden '+key);}});
 // Define traps on the VM root without eagerly copying accessor values.
 const root={EIPSIAssessment:{copy:v=>JSON.parse(JSON.stringify(v)),walk:()=>{},effectiveMetadataPolicy:off}};Object.defineProperties(root,Object.getOwnPropertyDescriptors(env));
 vm.runInNewContext(fs.readFileSync('src/admin/assessment-editor/metadata.js','utf8'),{globalThis:root,Intl,Map});new root.EIPSIAssessment.MetadataRecorder({id:'A',contract:'X',elements:[],flow:{metadata_profile:'detailed'}}).snapshot();
});
console.log(`${tests} tests, ${failed} failures`);process.exitCode=failed?1:0;
