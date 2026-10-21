const assert = require('node:assert/strict');
const fs = require('node:fs');
const { JSDOM } = require('jsdom');
const source = fs.readFileSync('src/frontend/study/interactions.js','utf8')+'\n'+fs.readFileSync('src/frontend/study/runtime.js', 'utf8');
let tests = 0, failures = 0;
const markup = (command = 'CMD_ONE', extra = '') => `<main id="eipsi-study"><h1 tabindex="-1">Evaluación</h1>${extra}<form class="eipsi-study-form" action="https://example.test/?eipsi_study=PUBLIC"><input name="csrf" value="SCOPED"><input name="command_id" value="${command}"><input name="expected_revision" value="0"><input name="email" value="test@example.test"><button name="action" value="enroll">Continuar</button><p role="status"></p></form></main>`;
const fixture = fetch => {
    const dom = new JSDOM(markup(), { url: 'https://example.test/', runScripts: 'outside-only' });
    dom.window.fetch = fetch;dom.window.eval(source);return dom;
};
const submit = (dom, action = 'enroll') => {
    const form = dom.window.document.querySelector('form');const button = form.querySelector('button');button.value = action;
    form.dispatchEvent(new dom.window.SubmitEvent('submit', { bubbles: true, cancelable: true, submitter: button }));
};
const tick = () => new Promise(resolve => setImmediate(resolve));
const result = (html = markup('CMD_TWO'), ok = true, state = 'OPEN_ASSESSMENT') => ({ ok, json: async () => ({ html, state, revision: 1 }) });
async function test(name, fn) { tests++;try { await fn();console.log('PASS ' + name); } catch (error) { failures++;console.log('FAIL ' + name + ': ' + error.message); } }
(async () => {
    await test('Study enrollment carries scoped token command and email', async () => {
        let call;const dom = fixture(async (url, options) => { call = { url, options };return result(); });submit(dom);await tick();
        assert.equal(call.options.body.get('csrf'), 'SCOPED');assert.equal(call.options.body.get('command_id'), 'CMD_ONE');assert.equal(call.options.body.get('expected_revision'), '0');assert.equal(call.options.body.get('email'), 'test@example.test');dom.window.close();
    });
    await test('Fetch retains same-origin recovery cookie and bypasses browser cache', async () => {
        let opts;const dom = fixture(async (url, options) => { opts = options;return result(); });submit(dom);await tick();assert.equal(opts.credentials, 'same-origin');assert.equal(opts.cache, 'no-store');assert.equal(opts.headers.Accept, 'application/json');dom.window.close();
    });
    await test('Double click is serialized while first submit is pending', async () => {
        let calls = 0, release;const dom = fixture(() => { calls++;return new Promise(resolve => { release = resolve; }); });submit(dom, 'enroll');submit(dom, 'enroll');assert.equal(calls, 1);release(result());await tick();dom.window.close();
    });
    await test('Network failure retains same command for retry without obsolete form messages', async () => {
        const dom = fixture(async () => { throw new Error('Network'); });submit(dom, 'enroll');await tick();assert.equal(dom.window.document.querySelector('[name=command_id]').value, 'CMD_ONE');assert.equal(dom.window.document.querySelector('button').disabled, false);assert.equal(dom.window.document.querySelector('[role=status]').textContent, '');dom.window.close();
    });
    await test('CAS conflict renders server recovery and focuses accessible alert', async () => {
        const dom = fixture(async () => result(markup('FRESH', '<p role="alert">Otra pestaña</p>'), false, 'ERROR'));submit(dom);await tick();assert.equal(dom.window.document.activeElement.getAttribute('role'), 'alert');assert.equal(dom.window.document.querySelector('[role=status]').textContent, '');dom.window.close();
    });
    await test('Study response focuses the returned heading', async () => {
        const dom = fixture(async () => result());submit(dom);await tick();assert.equal(dom.window.document.activeElement.tagName, 'H1');assert.equal(dom.window.document.querySelector('[name=command_id]').value, 'CMD_TWO');dom.window.close();
    });
    await test('Study script does not autosave scientific question fields', async () => {
        let calls=0;const dom=fixture(async()=>{calls++;return result();});const input=dom.window.document.createElement('input');input.name='responses[R_one]';dom.window.document.querySelector('form').append(input);input.dispatchEvent(new dom.window.Event('change',{bubbles:true}));await new Promise(resolve=>setTimeout(resolve,250));assert.equal(calls,0);dom.window.close();
    });
    await test('Metadata captures screen and fingerprint without embedding recovery credential', async () => {
        let data;const dom = fixture(async (url, options) => { data = JSON.parse(options.body.get('metadata_json'));return result(); });submit(dom);await tick();assert.ok(Object.hasOwn(data, 'screen_width'));assert.ok(Object.hasOwn(data, 'screen_height'));assert.match(data.fingerprint, /^ua-/);assert.equal(Object.hasOwn(data, 'token'), false);dom.window.close();
    });
    await test('Study script ignores Native participant forms', async () => {
        let calls=0;const dom=fixture(async()=>{calls++;return result();});dom.window.document.querySelector('form').className='eipsi-native-form';submit(dom);await tick();assert.equal(calls,0);dom.window.close();
    });
    await test('Native assessment iframe returned by the Study is preserved', async () => {
        const html='<main id="eipsi-study" data-state="OPEN_ASSESSMENT"><iframe class="eipsi-native-assessment-frame" src="/?eipsi_native=NX_ASSIGNED"></iframe></main>';const dom=fixture(async()=>result(html));submit(dom);await tick();assert.equal(dom.window.document.querySelector('iframe').getAttribute('src'),'/?eipsi_native=NX_ASSIGNED');assert.equal(dom.window.document.querySelector('form'),null);dom.window.close();
    });
    console.log(`${tests} tests, ${failures} failures`);process.exitCode = failures ? 1 : 0;
})();
