/* Execute actual consumers in a small deterministic DOM; no mirrored requests. */
const fs = require('fs'), vm = require('vm'), assert = require('assert');
let total=0;
function test(name, fn){fn();total++;console.log('PASS '+name);}
const nodes=new Map(), events=new Map(), requests=[], alerts=[];
function $(key){if(typeof key==='function')return; if(key&&key._key)key=key._key;
 if(!nodes.has(key))nodes.set(key,{_key:key,length:1,values:{},handlers:{},val(v){if(arguments.length){this.value=v;return this;}return this.value;},text(v){if(arguments.length){this.label=v;return this;}return this.label;},html(v){this.markup=v;return this;},css(k,v){this.values[k]=v;return this;},data(k,v){if(arguments.length===2){this.values[k]=v;return this;}return this.values[k];},prop(k,v){this.values[k]=v;return this;},on(k,fn){this.handlers[k]=fn;return this;},off(){return this;},ready(){return this;},show(){this.visible=true;return this;},hide(){this.visible=false;return this;},empty(){return this;},append(){return this;}});
 return nodes.get(key);
}
$.ajax=r=>{requests.push(r);return {done(){return this;},fail(){return this;}};};
$.post=(url,data,success)=>$.ajax({url,data,success});
const window={currentStudyId:999203,currentStudyWaves:[{id:999222,name:'S3 T2',status:'active'}],eipsiParticipantDashboardL10n:{ajaxUrl:'/wp-admin/admin-ajax.php',nonce:'participant-nonce'}};
const context=vm.createContext({window,document:{},jQuery:$,$,ajaxurl:'/wp-admin/admin-ajax.php',eipsiStudyDash:{nonce:'admin-nonce',ajaxUrl:'/wp-admin/admin-ajax.php'},eipsiAutoRefresh:{nonce:'refresh-nonce',ajaxUrl:'/wp-admin/admin-ajax.php'},alert:m=>alerts.push(m),console:{log(){},error(){}},setTimeout(){},setInterval(){},clearInterval(){},Date});
const modal=fs.readFileSync('admin/templates/study-dashboard-modal.php','utf8');
const reminder=modal.slice(modal.indexOf('function showReminderModal('),modal.indexOf('</script>',modal.indexOf('function showReminderModal(')));
vm.runInContext(reminder,context);
const fixture={};
function capture(key, action, nonceAction){const r=requests.pop();assert.equal(r.data.action,action);fixture[key]={action,nonce_action:nonceAction,data:{...r.data}};delete fixture[key].data.action;delete fixture[key].data.nonce;return r;}
test('Reminder modal resolves canonical wave id/name',()=>{context.showReminderModal(999207,'fixture@example.invalid');assert.equal($('#reminder-wave-id').val(),999222);assert.equal($('#reminder-wave-name').text(),'S3 T2');});
test('Reminder actual emitter uses localized admin nonce',()=>{context.sendIndividualReminderConfirmed();const r=capture('reminder','eipsi_send_individual_reminder','eipsi_study_dashboard_nonce');assert.equal(r.data.nonce,'admin-nonce');r.success({success:false,data:{message:'Controlled failure'}});assert(alerts.pop().includes('Controlled failure'));});
test('Reminder without active wave emits zero, not stale wave',()=>{window.currentStudyWaves=[];context.showReminderModal(999207,'fixture@example.invalid');assert.equal($('#reminder-wave-id').val(),0);});
const recalcStart=modal.indexOf('(function($) {',modal.indexOf('let currentStudyId = 0;')-30);
vm.runInContext(modal.slice(recalcStart,modal.indexOf('</script>',recalcStart)),context);
test('Recalc preview click emits real study and renders counts',()=>{$('#action-recalculate-times').handlers.click.call($('#action-recalculate-times'));const r=capture('preview','eipsi_recalculate_preview','eipsi_study_dashboard_nonce');assert.equal(r.data.study_id,999203);r.success({success:true,data:{affected_participants:1,waves_to_update:2}});assert.equal($('#recalc-assignments-count').text(),'2 tomas serán recalculadas');});
test('Recalc apply click emits same context and renders message',()=>{$('#btn-recalculate').handlers.click.call($('#btn-recalculate'));const r=capture('apply','eipsi_recalculate_waves','eipsi_study_dashboard_nonce');assert.equal(r.data.study_id,999203);r.success({success:false,data:{message:'Controlled partial failure'}});assert($('#recalc-result').markup.includes('Controlled partial failure'));});
vm.runInContext(fs.readFileSync('assets/js/participant-dashboard.js','utf8'),context);
test('Dashboard actual refresh emits no client identity and renders payload',()=>{window.EIPSIDashboard.refresh();const r=capture('dashboard','eipsi_get_participant_dashboard','eipsi_participant_dashboard');assert.deepEqual(Object.keys(r.data).sort(),['action','nonce']);r.success({success:true,data:{progress_percentage:33,completed_waves:1,pending_waves:2}});assert.equal($('.eipsi-progress-fill').values.width,'33%');assert($('.stat-pending').text().includes('2 pendientes'));});
const auto=fs.readFileSync('assets/js/participant-dashboard-auto-refresh.js','utf8').replace('const AutoRefresh = {','const AutoRefresh = window.__s3AutoRefresh = {');
vm.runInContext(auto,context);
test('Wave polling actual consumer emits wave with localized nonce',()=>{window.__s3AutoRefresh.state.currentWaveId=999222;window.__s3AutoRefresh.checkBackendState();const r=capture('wave','eipsi_check_wave_state','eipsi_auto_refresh');assert.equal(r.data.nonce,'refresh-nonce');});
const study=fs.readFileSync('assets/js/study-dashboard.js','utf8').replace('const StudyDashboard = {','const StudyDashboard = window.__s3StudyDashboard = {');
vm.runInContext(study,context);
test('Study data loader propagates modal context',()=>{window.__s3StudyDashboard.loadStudyData(999203);assert.equal(window.currentStudyId,999203);assert.equal($('#action-recalculate-times').data('study-id'),999203);requests.pop();});
test('Empty study waves clears reminder context',()=>{window.__s3StudyDashboard.renderWaves([]);assert.deepEqual(Array.from(window.currentStudyWaves),[]);});
test('Dashboard error leaves visible stats unchanged',()=>{window.EIPSIDashboard.refresh();const r=requests.pop();const before=$('.stat-pending').text();r.success({success:false,data:{message:'Denied'}});assert.equal($('.stat-pending').text(),before);});
test('Reminder success gives confirmation only on success response',()=>{window.currentStudyWaves=[{id:999222,name:'S3 T2',status:'active'}];context.showReminderModal(999207,'fixture@example.invalid');context.sendIndividualReminderConfirmed();requests.pop().success({success:true,data:{sent_count:1}});assert.equal(alerts.pop(),'Recordatorio enviado correctamente');});
test('Preview error renders explicit failure',()=>{$('#action-recalculate-times').handlers.click.call($('#action-recalculate-times'));requests.pop().success({success:false,data:{message:'Denied'}});assert($('#recalc-preview').markup.includes('Error al cargar preview'));});
test('Wave response reaches actual state-change consumer',()=>{let message;window.__s3AutoRefresh.state.currentWaveStatus='pending';window.__s3AutoRefresh.triggerRefresh=m=>{message=m;};window.__s3AutoRefresh.handleStateResponse({status:'submitted',is_locked:false,was_skipped:false});assert(message.includes('submitted'));});
fs.writeFileSync('tests/s3/consumer-requests.json',JSON.stringify(fixture,null,2)+'\n');
console.log(total+' consumer tests, 0 failures');
