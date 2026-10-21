/** Execute both frozen and composed classic runtimes against the same DOM contracts. */
const fs = require('fs');
const path = require('path');
const assert = require('assert/strict');
const { JSDOM } = require('jsdom');
const root = path.resolve(__dirname, '../..');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'src/frontend/forms/manifest.json')));
const composed = manifest.entry_order.map(f => fs.readFileSync(path.join(root, 'src/frontend/forms', f), 'utf8')).join('');
const baseline = fs.readFileSync(path.join(__dirname, 'runtime-baseline.js'), 'utf8');
assert.equal(composed, baseline, 'Source composition changed the frozen runtime');
const variants = [baseline, fs.readFileSync(path.join(root, 'assets/js/eipsi-forms.js'), 'utf8')];
const group = (type, input) => `<div class="form-group" data-field-name="${type}" data-field-type="${type}" data-required="true">${input}<div class="form-error"></div></div>`;
function env(source) {
    const markup = `<div class="eipsi-form"><form data-form-id="m3-dom" data-total-pages="3"><input type="hidden" class="eipsi-current-page" value="1"><div class="eipsi-page" data-page="1">${group('text', '<input name="text" required>')}${group('textarea', '<textarea name="textarea" required></textarea>')}${group('select', '<select name="select" required><option value="">Choose</option><option value="yes">Yes</option></select>')}${group('radio', '<input type="radio" name="radio" value="yes" required><input type="radio" name="radio" value="no">')}${group('vas-slider', '<input type="range" name="vas" min="0" max="100" value="50" data-touched="false" required>')}</div><div class="eipsi-page" data-page="2">${group('next', '<input name="next" required>')}<input name="logic-key" type="hidden" value="keep"></div><div class="eipsi-page" data-page="3"><input name="last"></div><button type="submit">Submit</button></form></div>`;
    const dom = new JSDOM(markup, { url: 'https://example.invalid/', runScripts: 'outside-only', pretendToBeVisual: true });
    const w = dom.window;
    w.console = { log(){}, warn(){}, error(){} };
    w.matchMedia = () => ({ matches: false, addListener(){}, addEventListener(){} });
    w.HTMLElement.prototype.scrollIntoView = function(){};
    Object.defineProperty(w.HTMLElement.prototype, 'offsetHeight', { get(){ return this.style.display === 'none' ? 0 : 100; } });
    Object.defineProperty(w.HTMLElement.prototype, 'offsetWidth', { get(){ return this.style.display === 'none' ? 0 : 100; } });
    Object.defineProperty(w.HTMLElement.prototype, 'offsetParent', { get(){ return this.style.display === 'none' ? null : this.parentElement; } });
    w.setInterval = () => 1;
    w.clearInterval = () => {};
    w.fetch = async () => ({ json: async () => ({ success: false, data: { message: 'controlled failure' } }) });
    w.eipsiFormsConfig = { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'test-nonce', strings: {}, settings: {} };
    w.eval(source);
    const api = w.EIPSIForms;
    // Avoid unrelated network/IndexedDB integrations while exercising real DOM methods.
    api.config = w.eipsiFormsConfig;
    const form = w.document.querySelector('form');
    return { dom, w, api, form, field: name => form.querySelector(`[name="${name}"]`) };
}
function navigator(e) { e.api.initForm(e.form); return e.api.getNavigator(e.form); }
const tests = [];
function test(name, run) { tests.push([name, run]); }
for (const [name, valid] of [['text','answer'], ['textarea','answer'], ['select','yes']]) {
    test(`${name}: required empty rejected, answered accepted`, e => {
        const f=e.field(name); assert.equal(e.api.validateField(f), false); assert.equal(f.getAttribute('aria-invalid'), 'true');
        f.value=valid; assert.equal(e.api.validateField(f), true); assert.equal(f.hasAttribute('aria-invalid'), false);
    });
}
test('radio: whole group errors and selected value clears them', e => { const f=e.field('radio');assert.equal(e.api.validateField(f),false);f.checked=true;assert.equal(e.api.validateField(f),true);assert.equal(e.form.querySelectorAll('[name="radio"].error').length,0); });
test('VAS: untouched midpoint is not an answer', e => {const f=e.field('vas');assert.equal(e.api.validateField(f),false);f.dataset.touched='true';assert.equal(e.api.validateField(f),true);});
test('disabled required field does not block submit', e => {const f=e.field('text');f.disabled=true;assert.equal(e.api.validateField(f),true);});
test('optional field accepts empty value', e => {const f=e.field('text');f.removeAttribute('required');f.closest('.form-group').dataset.required='false';assert.equal(e.api.validateField(f),true);});
test('page visibility disables hidden controls but preserves logic keys', e => {e.api.updatePageVisibility(e.form,1);assert.equal(e.field('next').disabled,true);assert.equal(e.field('logic-key').disabled,false);assert.equal(e.field('text').disabled,false);e.api.updatePageVisibility(e.form,2);assert.equal(e.field('next').disabled,false);assert.equal(e.field('text').disabled,true);});
test('hidden radio/select skip required validation', e => {e.form.querySelector('[data-page="1"]').style.display='none';assert.equal(e.api.validateField(e.field('radio')),true);assert.equal(e.api.validateField(e.field('select')),true);});
test('current page normalizes invalid and out of bounds values', e => {const f=e.form.querySelector('.eipsi-current-page');f.value='99';assert.equal(e.api.getCurrentPage(e.form),3);f.value='-2';assert.equal(e.api.getCurrentPage(e.form),1);f.value='bad';e.form.dataset.currentPage='2';assert.equal(e.api.getCurrentPage(e.form),2);});
test('restore current page synchronizes hidden field and dataset', e => {e.api.setCurrentPage(e.form,2,{trackChange:false});assert.equal(e.api.getCurrentPage(e.form),2);assert.equal(e.form.dataset.currentPage,'2');assert.equal(e.form.querySelector('.eipsi-current-page').value,'2');});
test('thank-you page excluded from regular page count', e => {delete e.form.dataset.totalPages;const page=e.w.document.createElement('div');page.className='eipsi-page';page.dataset.pageType='thank_you';e.form.append(page);assert.equal(e.api.getTotalPages(e.form),3);});
test('validation reset removes visible and ARIA errors', e => {e.api.validateField(e.field('text'));e.api.resetValidationState(e.form);assert.equal(e.form.querySelectorAll('.has-error,[aria-invalid="true"],input.error').length,0);});
test('classic API remains exposed with shared navigator map', e => {assert.equal(e.api.conditionalNavigators,e.api.navigators);for(const method of ['init','initForm','validateForm','handlePagination','submitForm','updatePageVisibility'])assert.equal(typeof e.api[method],'function');});
test('next and back retain page values and visibility', e => {for(const f of e.form.querySelectorAll('[required]')){f.removeAttribute('required');f.closest('.form-group').dataset.required='false';}e.api.handlePagination(e.form,'next');assert.equal(e.api.getCurrentPage(e.form),2);e.api.handlePagination(e.form,'prev');assert.equal(e.api.getCurrentPage(e.form),1);});
test('conditional malformed JSON safely falls back', e => {const n=navigator(e);assert.equal(n.parseConditionalLogic('{bad'),null);assert.equal(n.parseConditionalLogic('true'),null);});
test('conditional next default and explicit branch target', e => {const n=navigator(e);assert.equal(n.getNextPage(1).targetPage,2);e.field('radio').checked=true;const g=e.field('radio').closest('.form-group');g.dataset.conditionalLogic=JSON.stringify({enabled:true,rules:[{conditions:[{fieldId:'radio',value:'yes'}],action:'goToPage',targetPage:3}]});assert.equal(n.getNextPage(1).targetPage,3);});
test('conditional terminal action submits rather than changing page', e => {const n=navigator(e);e.field('radio').checked=true;e.field('radio').closest('.form-group').dataset.conditionalLogic=JSON.stringify({enabled:true,rules:[{conditions:[{fieldId:'radio',value:'yes'}],action:'submit'}]});assert.equal(n.getNextPage(1).action,'submit');});
test('conditional numeric VAS distinguishes untouched and zero', e => {const n=navigator(e),p=e.form.querySelector('[data-page="1"]'),f=e.field('vas');const c={fieldId:'vas-slider',fieldType:'numeric',operator:'>=',threshold:0};assert.equal(n.evaluateCondition(c,p),false);f.dataset.touched='true';f.value='0';assert.equal(n.evaluateCondition(c,p),true);});
test('init is idempotent and keeps navigator history', e => {e.api.initForm(e.form);const n=e.api.getNavigator(e.form);e.api.initForm(e.form);assert.equal(e.api.forms.length,1);assert.equal(e.api.getNavigator(e.form),n);assert.equal(n.visitedPages.has(1),true);});
test('consent checkbox blocks and unblocks navigation', e => {const g=e.w.document.createElement('div');g.dataset.consentBlock='true';g.dataset.required='true';g.innerHTML='<input type="checkbox" name="consent"><button class="eipsi-next-button">Next</button>';e.form.querySelector('[data-page="1"]').append(g);e.api.updateNavigationForConsent(e.form);assert.equal(g.querySelector('button').disabled,true);g.querySelector('input').checked=true;e.api.updateNavigationForConsent(e.form);assert.equal(g.querySelector('button').disabled,false);});
test('completed state sends tracking completion after state update', e => {let state; e.w.EIPSITracking={registerForm(){},setTotalPages(){},setCurrentPage(id,page){state=[e.form.dataset.formStatus,page];}};e.api.markFormCompleted(e.form);assert.deepEqual(state,['completed','completed']);const thanks=e.w.document.createElement('div');thanks.className='eipsi-page eipsi-thank-you-page-block';thanks.dataset.page='thank-you';thanks.style.display='none';thanks.textContent='Thank you';e.form.append(thanks);e.api.showIntegratedThankYouPage(e.form);assert.equal(thanks.style.display,'block');for(const page of e.form.querySelectorAll('.eipsi-page:not([data-page="thank-you"])'))assert.equal(page.style.display,'none');delete e.w.EIPSITracking;});
function partial(e) {e.w.eval(fs.readFileSync(path.join(root,'assets/js/eipsi-save-continue.js'),'utf8'));e.form.eipsiSaveContinue={};const p=Object.create(e.w.EIPSISaveContinue.prototype);Object.assign(p,{form:e.form,config:{settings:{}},accumulatedResponses:{},dirtyFields:new Set()});return p;}
test('active partial client restores radio, text, textarea, select and VAS', e => {const p=partial(e);for(const [name,value] of [['text','saved'],['textarea','long'],['select','yes'],['radio','no'],['vas','80']])p.setFieldValue(name,value);assert.equal(e.field('text').value,'saved');assert.equal(e.field('textarea').value,'long');assert.equal(e.field('select').value,'yes');assert.equal(e.form.querySelector('[name="radio"][value="no"]').checked,true);assert.equal(e.field('vas').value,'80');});
test('partial restore resumes page and preserves navigation state', async e => {e.api.initForm(e.form);const p=partial(e);await p.restorePartial({page_index:2,responses:{text:'restored',next:'saved'}});assert.equal(e.api.getCurrentPage(e.form),2);assert.equal(e.field('text').value,'restored');assert.equal(e.field('next').value,'saved');assert.equal(e.api.getNavigator(e.form).visitedPages.has(2),true);});
async function flush(){for(let i=0;i<12;i++)await Promise.resolve();}
test('submit client preserves action, nonce, identifiers and untouched VAS', async e => {let body;e.field('vas').classList.add('vas-slider');e.w.fetch=async(url,options)=>{body=options.body;return {json:async()=>({success:false,data:{message:'controlled failure'}})};};e.api.submitForm(e.form);await flush();assert.equal(body.get('action'),'eipsi_forms_submit_form');assert.equal(body.get('nonce'),'test-nonce');assert.ok(body.get('participant_id'));assert.ok(body.get('session_id'));assert.equal(body.get('vas'),'');assert.equal(e.form.querySelector('button[type="submit"]').disabled,false);assert.equal(e.form.dataset.submitting,undefined);});
test('transport failure restores submit button and shows error', async e => {e.w.fetch=async()=>{throw new Error('controlled network failure');};e.api.submitForm(e.form);await flush();assert.equal(e.form.querySelector('button[type="submit"]').disabled,false);assert.equal(e.form.dataset.submitting,undefined);assert.ok(e.form.parentElement.querySelector('.form-message--error'));});
(async()=>{
let failures=0;
for(const [name,run] of tests){try{for(const source of variants){const e=env(source);try{await run(e);}finally{e.dom.window.close();}}console.log('PASS '+name);}catch(error){failures++;console.error('FAIL '+name+': '+error.message);}}
console.log(`${tests.length} tests, ${failures} failures (each executed against baseline and build)`);
process.exitCode=failures ? 1 : 0;

})().catch(error=>{console.error(error);process.exitCode=1;});
