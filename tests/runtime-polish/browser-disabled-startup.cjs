'use strict';
// Optional real-browser check: EIPSI_BROWSER=firefox node tests/runtime-polish/browser-disabled-startup.cjs
const path=require('path'),assert=require('assert/strict'),puppeteer=require('puppeteer-core');
const base=path.resolve(__dirname,'../..');
(async()=>{
 const firefox=process.env.EIPSI_BROWSER==='firefox',results=[];
 const browser=await puppeteer.launch({browser:firefox?'firefox':'chrome',executablePath:firefox?'/usr/bin/firefox-esr':'/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 try{for(const width of [360,1366]){
  const page=await browser.newPage();await page.setViewport({width,height:768});await page.setContent('<main id="root" class="ae-root ae-native-runtime"></main>');
  await page.addStyleTag({path:path.join(base,'assets/css/assessment-editor.css')});await page.addScriptTag({path:path.join(base,'assets/js/assessment-editor.js')});await page.addScriptTag({path:path.join(base,'src/admin/assessment-editor/product-runtime.js')});
  await page.evaluate(()=>{const A=window.EIPSIAssessment,d=A.assessment();d.elements=[A.element('vas')];d.elements[0].properties.required=true;window.key=d.elements[0].response_key;let f={execution_id:'NX_DISABLED_BROWSER',version_id:'NV_BROWSER',definition_hash:'fixed',definition:d,revision:0,state:'ACTIVE',page:0,session:null,csrf:'fixture',participant_contract:'EIPSI_PARTICIPANT_FRAME_V2',projection_ref:'fixed'};window.app=A.mountProductRuntime(document.getElementById('root'),f,async q=>{if(q.action==='open')await new Promise(r=>window.release=r);f={...f,revision:f.revision+1,session:{answers:q.answers,touched:q.touched,page:0,metadata:q.metadata}};return JSON.parse(JSON.stringify(f));});});
  await page.waitForFunction(()=>!!window.release&&document.querySelector('input[type=range]').disabled);
  let box=await (await page.$('input[type=range]')).boundingBox();await page.mouse.click(box.x+box.width*.82,box.y+box.height/2);
  const blocked=await page.evaluate(()=>({answers:window.app.session.answers,touched:window.app.session.touched,widget:document.querySelector('input[type=range]').dataset.touched}));
  assert.deepEqual(blocked.answers,{});assert.deepEqual(blocked.touched,{});assert.equal(blocked.widget,'false');
  await page.evaluate(async()=>{window.release();await window.app.ready;});assert(await page.$eval('input[type=range]',n=>!n.disabled));
  box=await (await page.$('input[type=range]')).boundingBox();await page.mouse.click(box.x+box.width*.82,box.y+box.height/2);
  const selected=await page.evaluate(()=>({value:window.app.session.answers[window.key],touched:window.app.session.touched[window.key],errors:window.app.session.errors().length}));assert(selected.value>60);assert.equal(selected.touched,true);assert.equal(selected.errors,0);results.push({width,blocked,selected});await page.close();
 }console.log(JSON.stringify({browser:await browser.version(),results},null,2));}finally{await browser.close();}
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
