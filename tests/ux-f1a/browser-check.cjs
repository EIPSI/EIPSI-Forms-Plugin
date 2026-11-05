'use strict';
// Optional real-browser probe. Uses existing puppeteer-core; never downloads Chromium.
const fs=require('fs'),path=require('path'),assert=require('assert/strict'),puppeteer=require('puppeteer-core');
const base=path.resolve(__dirname,'../..'),executablePath=process.env.EIPSI_BROWSER_EXECUTABLE||'/usr/bin/chromium';
(async()=>{
 if(!fs.existsSync(executablePath)){console.error('UNAVAILABLE: no installed browser executable at '+executablePath);process.exitCode=2;return;}
 const browser=await puppeteer.launch({executablePath,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 const evidence=[];try{const page=await browser.newPage();
 for(const width of [320,360,390,430]){
  await page.setViewport({width,height:900});await page.setContent('<main id="root" class="ae-root ae-native-runtime ae-designed"></main>');
  for(const asset of ['assets/css/assessment-editor.css','assets/css/study-runtime.css'])await page.addStyleTag({path:path.join(base,asset)});
  await page.addScriptTag({path:path.join(base,'assets/js/assessment-editor.js')});await page.addScriptTag({path:path.join(base,'src/admin/assessment-editor/product-runtime.js')});
  await page.evaluate(async()=>{const A=window.EIPSIAssessment,d=A.assessment();d.flow.metadata_profile='basic';d.elements=[A.element('text')];const f={execution_id:'NX_BROWSER',version_id:'NV_BROWSER',definition_hash:'h',definition:d,revision:0,state:'ACTIVE',page:0,session:null,csrf:'x'};window.hold=false;window.view=A.mountProductRuntime(document.getElementById('root'),f,async()=>{if(window.hold)await new Promise(r=>window.release=r);return JSON.parse(JSON.stringify(f));});await window.view.ready;window.hold=true;});
  const box=()=>page.$eval('.ae-nav-forward',b=>{const r=b.getBoundingClientRect();return{x:r.x,y:r.y,width:r.width,height:r.height,text:b.textContent};});
  const before=await box();await page.click('.ae-nav-forward');const during=await box();assert.deepEqual(during,before);
  assert(await page.$eval('.ae-loading-spinner',n=>getComputedStyle(n).position==='absolute'&&n.getBoundingClientRect().width>0));
  await page.evaluate(()=>window.release());await page.waitForFunction(()=>!document.querySelector('.ae-loading-spinner'));assert.deepEqual(await box(),before);
  await page.evaluate(()=>{window.view.destroy();const A=window.EIPSIAssessment,d=A.assessment();d.elements=[A.element('date'),A.element('single_choice'),A.element('multiple_choice'),A.element('likert')];d.elements.forEach(q=>q.properties.required=true);window.session=new A.PreviewSession(d);A.renderParticipantElements(d.elements,document.getElementById('root'),window.session,'native');A.presentValidationErrors(document.getElementById('root'),window.session.errors());});
  assert(await page.$eval('[data-widget=date] input',n=>n.getAttribute('aria-invalid')==='true'&&!!document.getElementById(n.getAttribute('aria-describedby'))));
  await page.click('.ae-calendar-open');await page.focus('.ae-calendar-navigation button');await page.keyboard.press('Enter');assert(await page.$eval('.ae-calendar-navigation button',n=>document.activeElement===n));
  await page.keyboard.press('Tab');assert(await page.$eval('[data-ae-calendar-year]',n=>document.activeElement===n));await page.keyboard.press('Tab');assert(await page.$eval('.ae-calendar-navigation button:last-child',n=>document.activeElement===n));await page.keyboard.down('Shift');await page.keyboard.press('Tab');await page.keyboard.press('Tab');await page.keyboard.up('Shift');assert(await page.$eval('.ae-calendar-navigation button',n=>document.activeElement===n));await page.keyboard.press('Escape');
  assert(await page.$$eval('.ae-widget[role=group],.ae-widget[role=radiogroup]',nodes=>nodes.length===3&&nodes.every(n=>!!document.getElementById(n.getAttribute('aria-labelledby')))));
  const numeric=await page.evaluate(()=>{const q=window.EIPSIAssessment.element('text');q.properties.content_type='number';const input=window.EIPSIAssessment.widget(q).querySelector('input');return{type:input.type,inputmode:input.inputMode};});assert.deepEqual(numeric,{type:'text',inputmode:'decimal'});
  evidence.push({width,button:{before,during,after:before},validation:'associated',calendar:'Enter/Tab/Shift+Tab/Escape passed',groups:3,numeric});
 }
 console.log(JSON.stringify({browser:await browser.version(),evidence},null,2));
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
