(function (B) {
    'use strict';
    const A = () => window.EIPSIAssessment;
    B.openExperience = async app => {
        if (app.dirty || app.pendingSave) throw new Error(window.EIPSIAdminI18n.t("Save the draft before simulating."));
        const transversal = app.draft.protocol_metadata?.study_type === 'TRANSVERSAL';
        const opener = document.activeElement, dialog = B.el('dialog', { class: 'sb-modal sb-experience', 'aria-label': window.EIPSIAdminI18n.t("Simulated experience") }), chrome = B.el('div', { class: 'sb-experience-chrome' }), surface = B.el('div', { class: 'sb-experience-surface',lang:window.EIPSIContent.language(app.draft) });
        let commands = [], result = null, session = null, pane = 'participant', busy = false, amount = 1, unit = 3600, selectedMail = null, withdrawalMessage = null, pending = 0, queue = Promise.resolve(), seenEmails = new Set();
        const seedHex = number => number === 1 ? '01'.repeat(32) : number.toString(16).padStart(64, '0');
        const seedNumber = hex => hex === '01'.repeat(32) ? 1 : Number(BigInt('0x' + hex) % 1000001n);
        let draftSeed = seedNumber(app.context.seed_hex ?? '01'.repeat(32));
        let context = {...B.copy(app.context), seed_hex: seedHex(draftSeed)};
        const error = B.el('p', { role: 'alert', class: 'sb-experience-error', hidden: true });
        const close = () => { if (busy) return;if (dialog.close) dialog.close();else dialog.dispatchEvent(new Event('close')); };
        dialog.addEventListener('close', () => { dialog.remove();opener?.focus(); }, { once: true });
        dialog.addEventListener('cancel', e => { if (busy) e.preventDefault(); });
        const currentAnswers = () => ({ answers: B.copy({...session?.answers}), touched: B.copy({...session?.touched}), ...(result?.assessment ? { instance: result.assessment.instance } : {}) });
        const participantKey = value => {
            if(pane!=='participant'||!value?.assessment||value.review||value.completion||value.withdrawal_html||value.verification_required||!value.inside||value.snapshot.withdrawal)return null;
            const a=value.assessment;
            return JSON.stringify([a.instance,a.session.page,window.EIPSIAssessmentRandomization.apply(a.definition,a.session.randomization)]);
        };
        // Blur/change can start an autosave immediately before the Finish click.
        // Serialize commands and capture their payloads rather than dropping that click.
        const call = (command = null, flush = false, restartContext = null) => {
            const save = flush && session && result?.assessment ? { kind: 'ANSWER', data: currentAnswers() } : null;
            pending++;busy = true;dialog.setAttribute('aria-busy', 'true');
            const run = async () => {
                error.hidden = true;
                const candidateContext = B.copy(restartContext || context), next = restartContext ? [] : [...commands];if (save) next.push(save);if (command) next.push(command);
                try {
                    const value = await app.call('experience', { id: app.current.id, context: candidateContext, commands: next });
                    const first = result === null, previousKey=participantKey(result), keepSurface=command?.kind==='ANSWER'&&session&&previousKey!==null&&previousKey===participantKey(value);commands = next;context = candidateContext;result = value;
                    if(restartContext){withdrawalMessage=null;draftSeed=seedNumber(context.seed_hex);app.context.seed_hex=context.seed_hex;pane=!transversal&&value.initial_html?'initial':'participant';selectedMail=null;seenEmails.clear();}
                    if (first && value.initial_html && !value.enrolled) pane = 'initial';
                    render(keepSurface);return true;
                } catch (e) { error.textContent = e.message;error.hidden = false;return false; }
                finally { pending--;busy = pending > 0;if (!busy) dialog.removeAttribute('aria-busy'); }
            };
            queue = queue.then(run, run);return queue;
        };
        const send = (kind, data = {}, flush = false) => call({ kind, data }, flush);
        const restart = () => {
            const text=String(draftSeed).trim(), number=Number(text);
            if(!/^\d+$/.test(text)||!Number.isInteger(number)||number<0||number>1000000){error.textContent=window.EIPSIAdminI18n.t("Enter an integer between 0 and 1,000,000.");error.hidden=false;return;}
            return call(transversal?{kind:'ENTER',data:{}}:null,false,{...context,seed_hex:seedHex(number)});
        };
        const html = value => { const div = B.el('div', { class: 'sb-experience-content' });div.innerHTML = value;return div; };
        const render = (keepSurface = false) => {
            if(pane==='mail')result.emails.forEach(m=>seenEmails.add(m.id));
            surface.lang=window.EIPSIContent.language(app.draft);chrome.replaceChildren();if(!keepSurface){surface.replaceChildren();session = null;}
            chrome.append(B.el('div', { class: 'sb-experience-heading' }, B.el('h2', { text: window.EIPSIAdminI18n.t("Simulated experience") }), B.el('div',{class:'sb-experience-restart'},B.field(window.EIPSIAdminI18n.t("Seed"),draftSeed,v=>{draftSeed=v;},'number',{'aria-label':window.EIPSIAdminI18n.t("Simulation seed"),min:0,max:1000000,step:1,title:window.EIPSIAdminI18n.t("Number between 0 and 1,000,000. Applied when restarting."),oninput:e=>{draftSeed=e.target.value;}}),B.button(window.EIPSIAdminI18n.t("Restart journey"),restart)), B.button(window.EIPSIAdminI18n.t("Close"), close)), B.note(window.EIPSIAdminI18n.t(transversal?"Dummy participant · no responses saved in the study.":"Dummy participant and emails · no delivery or responses saved in the study.")));
            if(!transversal){
            const durationUnit=B.el('select',{'aria-label':window.EIPSIAdminI18n.t("Time unit"),class:'sb-experience-time-unit',onchange:e=>{unit=Number(e.target.value);}},[['60',window.EIPSIAdminI18n.t('Minutes')],['3600',window.EIPSIAdminI18n.t('Hours')],['86400',window.EIPSIAdminI18n.t("days")],['604800',window.EIPSIAdminI18n.t('Weeks')]].map(([value,text])=>B.el('option',{value,text})));durationUnit.value=String(unit);
            const clock = B.el('div', { class: 'sb-experience-clock' }, B.el('strong', { text: result.snapshot.clock + ' · ' + result.snapshot.timezone }), B.el('input',{type:'number',class:'sb-experience-time-amount',value:amount,min:1,step:1,'aria-label':window.EIPSIAdminI18n.t("Time to advance"),oninput:e=>{amount=Number(e.target.value);},onchange:e=>{amount=Number(e.target.value);}}), durationUnit, B.button(window.EIPSIAdminI18n.t("Advance time"), () => { if (!Number.isSafeInteger(amount) || amount < 1) { error.textContent = window.EIPSIAdminI18n.t("Enter an integer greater than zero.");error.hidden = false;return; }return send('ADVANCE', { seconds: amount * unit }, true); }));
            if (result.next_event) clock.append(B.button(window.EIPSIAdminI18n.t("Next event"), () => send('ADVANCE', { seconds: Math.max(1, (Date.parse(result.next_event) - Date.parse(result.snapshot.clock)) / 1000) }, true)));
            if(result.study_state==='FINALIZED')clock.querySelectorAll('input,select,button').forEach(e=>e.disabled=true);chrome.append(clock);
            }
            if(result.study_state){const state=result.study_state,controls=B.el('div',{class:'sb-experience-study-state'},B.el('span',{text:window.EIPSIAdminI18n.t("Study status (simulation): ")}),B.studyStateBadge(state));if(state==='FINALIZED')controls.append(B.button(window.EIPSIAdminI18n.t("Reopen"),()=>send('REOPEN')));else controls.append(B.button(state==='PAUSED'?window.EIPSIAdminI18n.t("Activate"):window.EIPSIAdminI18n.t("Pause"),()=>send(state==='PAUSED'?'ACTIVATE':'PAUSE')),B.button(window.EIPSIAdminI18n.t("Finalize study"),()=>{let erase=true;const modal=B.el('dialog',{class:'sb-modal','aria-label':window.EIPSIAdminI18n.t("Finalize simulated study")},B.el('h2',{text:window.EIPSIAdminI18n.t("Finalize simulated study")}),transversal?null:B.check(window.EIPSIAdminI18n.t("Delete participant email addresses"),true,v=>erase=v),B.button(window.EIPSIAdminI18n.t("Cancel"),()=>modal.close()),B.button(window.EIPSIAdminI18n.t("Finalize"),async()=>{modal.close();await send('FINALIZE',{erase_emails:erase});}));modal.addEventListener('close',()=>modal.remove(),{once:true});dialog.append(modal);modal.showModal();}));chrome.append(controls);}

            const tabs = B.el('div', { class: 'sb-experience-tabs', role: 'tablist' }), unread = result.emails.filter(m=>!seenEmails.has(m.id)).length;
            const choices = transversal ? [['participant',window.EIPSIAdminI18n.t('Assessment')],['trace',window.EIPSIAdminI18n.t('Journey')]] : [['initial',window.EIPSIAdminI18n.t("Initial screen")], ['mail','Emails'], ['trace',window.EIPSIAdminI18n.t('Journey')]];
            choices.forEach(([key,label])=>{
                const tab=B.el('button',{type:'button',role:'tab','data-tab':key,'aria-selected':String(pane===key),class:key==='mail'&&unread?'has-unread':'',text:label,onclick:async()=>{
                    if(busy)return;
                    if(session&&result.assessment&&!await send('ANSWER',currentAnswers()))return;
                    pane=key;render();
                }});
                if(key==='mail'){
                    tab.setAttribute('aria-label',window.EIPSIAdminI18n.f("Emails: %1$d total, %2$d new",result.emails.length,unread));
                    tab.append(B.el('span',{class:'sb-experience-email-badge','aria-hidden':'true',text:String(result.emails.length)}));
                }tabs.append(tab);
            });chrome.append(tabs);
            const notices=B.el('div',{class:'sb-experience-notices','aria-live':'polite'});chrome.append(notices);
            if(result.notice&&!result.notice_in_portal)notices.append(B.el('p',{class:'sb-experience-notice',text:result.notice}));
            if(!transversal&&unread)chrome.append(B.el('span',{class:'sb-experience-email-announcement',role:'status',text:window.EIPSIAdminI18n.n('%d new email',unread)}));
            if(keepSurface)return;
            if(withdrawalMessage){renderEntry();const target=surface.querySelector('.eipsi-portal-notices');if(target)target.prepend(html(withdrawalMessage));return;}
            if (pane === 'mail') { renderMail();return; }
            if (pane === 'trace') { renderTrace();return; }
            if (pane === 'initial') { renderEntry();return; }
            if (result.withdrawal_html) { const content=html(result.withdrawal_html);bindStudyContent(content);surface.append(content);return; }
            if (result.snapshot.withdrawal) { notices.append(B.el('p',{class:'sb-experience-notice',text:window.EIPSIAdminI18n.t("Participation withdrawn. This simulated participant has no assessments available.")}));return; }
            if (result.inside && result.review) { renderReview();return; }
            if (result.completion && result.inside && !result.review) { surface.lang=result.completion_content_language||window.EIPSIContent.language(app.draft);const c=result.completion;if(c.title)surface.append(B.el('h2',{text:c.title}));surface.append(html(c.message_html));if(c.image_url)surface.append(B.el('img',{src:c.image_url,alt:''}));return; }
            if (!result.inside || !result.enrolled || result.verification_required) { renderEntry();return; }
            if (result.assessment) { renderAssessment();return; }
            if(result.status_html){const content=html(result.status_html);bindStudyContent(content);surface.append(content);return;}
            if(result.show_next_time&&result.next_action.kind==='WAIT'&&result.next_action.payload?.until)surface.append(B.note((result.public_messages?.next_time??'Próxima disponibilidad:')+' '+result.next_action.payload.until));
            const finished=['COMPLETED','TERMINATED_BY_RULE','WITHDRAWN'].includes(result.snapshot.execution.state);notices.append(B.el('h2', { text: finished ? 'Participación finalizada' : 'Por ahora no hay evaluaciones disponibles' }), B.note(finished ? 'Este recorrido terminó.' : 'Te avisaremos por email cuando haya una evaluación disponible. Podés avanzar el reloj para continuar.'));
        };
        // Bind only the injected dummy surface; no public transport or real form action.
        const bindStudyContent = content => {
            window.EIPSIStudyInteraction.notices(content);
            content.addEventListener('invalid',event=>window.EIPSIStudyInteraction.invalid(event),true);
            content.querySelectorAll('noscript').forEach(e=>e.remove());
            content.addEventListener('click',e=>{const link=e.target.closest('a');if(link){e.preventDefault();const url=new URL(link.getAttribute('href')||'',window.location.href);if(url.searchParams.has('eipsi_initial')){pane='initial';render();}return;}window.EIPSIStudyInteraction.click(content,e,async email=>{if(!result.enrolled||email.trim().toLowerCase()!==result.email.toLowerCase())throw Error(result.public_messages.withdrawal_email_not_found);return {revision:result.snapshot.execution.revision};});});
            content.addEventListener('input',e=>window.EIPSIStudyInteraction.input(e));
            content.querySelectorAll('form').forEach(form=>{
                form.removeAttribute('action');form.addEventListener('submit',async e=>{
                    e.preventDefault();const action=e.submitter?.name==='action'?e.submitter.value:(form.querySelector('input[name=action]')?.value||'enroll');
                    const text=form.querySelector('[data-withdrawal-confirm-text]');if(text&&text.value!==text.closest('dialog').dataset.phrase)return;
                    if(action==='confirm_email'&&!window.EIPSIStudyInteraction.confirmEmail(form))return;
                    if(!form.reportValidity())return;
                    let okay=false;
                    if(action==='withdraw')okay=await send('WITHDRAW',{email:form.querySelector('[name=email]')?.value,mode:form.querySelector('[name=mode]:checked')?.value||form.querySelector('input[type=hidden][name=mode]')?.value,confirmation_text:text?.value||''});
                    else if(action==='confirm_email')okay=await send('CONFIRM_EMAIL',{email:form.querySelector('[name=email]').value,confirm_email_change:form.querySelector('[name=confirm_email_change]')?.value==='1'});
                    else if(action==='refresh')okay=await send('ADVANCE',{seconds:1});
                    else if(action==='logout')okay=await send('LOGOUT');
                    else okay=await send('ENTER',{email:form.querySelector('[name=email]')?.value||result.email});
                    if(okay&&action==='withdraw'){const message=result.withdrawal_completion_html;if(await call(null,false,{...context})){withdrawalMessage=message;render();}return;}
                    if(okay&&action==='enroll'){pane='participant';render();}
                });
            });
        };
        const renderEntry = () => {
            if(result.initial_html){const content=html(pane==='initial'?(result.landing_html||result.initial_html):result.initial_html);bindStudyContent(content);surface.append(content);}
            else surface.append(B.el('h2',{text:app.current.administration?.name||'Evaluación'}),B.button(window.EIPSIAdminI18n.t("Start assessment"),()=>send('ENTER')));
            
        };
        const renderReview = () => { const content=html(result.review_html);bindStudyContent(content);surface.append(content); };
        const renderAssessment = () => {
            const packet=result.assessment, d=packet.definition, saved=packet.session, a=A();session=new a.PreviewSession(d,saved.randomization);session.page=saved.page;session.answers={...B.copy(saved.answers)};session.touched={...B.copy(saved.touched)};
            const form=B.el('div',{class:'ae-preview sb-experience-assessment',lang:window.EIPSIContent.language(d)}), flow=d.flow,page=flow.pages[saved.page];
            if(flow.show_title!==false)form.append(B.el('h2',{text:d.title}));if(page?.title)form.append(B.el('h3',{text:page.title}));if(page?.description_html)form.append(html(page.description_html));
            if(saved.expired)form.append(B.note((flow.messages||window.EIPSIContent.get('flow_messages',window.EIPSIContent.language(d)))[flow.save_continue.enabled?'expired_saved':'expired']));
            a.renderParticipantElements(session.pages[session.page],form,session,'dummy');
            a.applyDesign(form,flow.design);const nav=B.el('div',{class:'ae-preview-nav ae-preview-actions','data-alignment':flow.design?.navigation_alignment||'edges'});if(flow.allow_back&&saved.page>0)nav.append(B.el('button',{type:'button',class:'ae-nav-back',onclick:()=>send('BACK',currentAnswers()),text:flow.navigation_labels.back||window.EIPSIContent.get('flow',window.EIPSIContent.language(d)).navigation_labels.back}));
            const forward=B.el('button',{type:'button',onclick:()=>send(saved.page<session.pages.length-1?'NEXT':'SUBMIT',currentAnswers()),text:saved.page<session.pages.length-1?(flow.navigation_labels.next||window.EIPSIContent.get('flow',window.EIPSIContent.language(d)).navigation_labels.next):(flow.navigation_labels.finish||window.EIPSIContent.get('flow',window.EIPSIContent.language(d)).navigation_labels.finish)});forward.classList.add('ae-nav-forward');nav.append(forward);form.append(B.note((flow.messages?.progress??window.EIPSIContent.get('flow_messages',window.EIPSIContent.language(d)).progress).replaceAll('[ACTUAL]',String(saved.page+1)).replaceAll('[TOTAL]',String(session.pages.length))),nav);surface.append(form);
            // The exact native widgets collect values; the server evaluates conditions and navigation.
            form.addEventListener('change',()=>send('ANSWER',currentAnswers()));
        };
        const renderMail = () => {
            const layout=B.el('div',{class:'sb-experience-inbox'}),list=B.el('div',{class:'sb-experience-mail-list'}),body=B.el('div',{class:'sb-experience-mail-body'});if(!result.emails.length)chrome.querySelector('.sb-experience-notices').append(B.note(window.EIPSIAdminI18n.t("No emails have arrived yet. Advance time or enter the study.")));
            result.emails.slice().reverse().forEach(m=>list.append(B.button(m.content.subject+' · '+m.at,()=>{selectedMail=m.id;render();})));
            const m=result.emails.find(m=>m.id===selectedMail)||result.emails[result.emails.length-1];if(m){body.append(B.el('h3',{text:m.content.subject}),B.note(window.EIPSIAdminI18n.f("To: %1$s · %2$s",m.to,m.at)));const frame=B.el('iframe',{title:window.EIPSIAdminI18n.t("Simulated email content"),sandbox:'allow-same-origin',class:'sb-experience-email-frame'});frame.srcdoc='<!doctype html><html lang="'+window.EIPSIContent.language(app.draft)+'"><head><meta charset="utf-8"><style>body{font:16px system-ui;padding:16px;color:#243247}a{color:#35608d}</style></head><body>'+m.content.html+'</body></html>';frame.addEventListener('load',()=>{frame.contentDocument?.addEventListener('click',e=>{const link=e.target.closest('a');if(!link)return;e.preventDefault();if(link.getAttribute('href')?.includes('/dummy/'+m.id)){pane='participant';send('EMAIL',{id:m.id});}else if(link.getAttribute('href')?.endsWith('/dummy/withdrawal')){pane='initial';render();}else{error.textContent=window.EIPSIAdminI18n.t("Communication preferences do not change notices in this simulation.");error.hidden=false;}});});body.append(frame);chrome.querySelector('.sb-experience-notices').append(B.note(m.revoked?window.EIPSIAdminI18n.t("This link was revoked when the email was corrected."):window.EIPSIAdminI18n.f("The link opens inside this experience. Expires: %s",m.expires_at)));}layout.append(list,body);surface.append(layout);
        };
        const renderTrace = () => { const table=B.el('table',{class:'widefat'},B.el('thead',{},B.el('tr',{},[window.EIPSIAdminI18n.t("Assessment / element"),window.EIPSIAdminI18n.t("Status"),window.EIPSIAdminI18n.t("Available"),window.EIPSIAdminI18n.t("Expires")].map(text=>B.el('th',{text}))))),body=B.el('tbody');Object.values(result.snapshot.instances).forEach(i=>body.append(B.el('tr',{},[B.label(B.node(app.draft,i.definition_id)||{id:i.definition_id}),i.state,i.available_at||window.EIPSIAdminI18n.t("Pending"),i.due_at||'—'].map(text=>B.el('td',{text})))));table.append(body);surface.append(table,B.el('details',{},B.el('summary',{text:window.EIPSIAdminI18n.t("Engine decisions and events")}),B.el('pre',{text:JSON.stringify({random:result.snapshot.random_decisions,conditions:result.snapshot.logical_trajectory?.audit,events:result.snapshot.events},null,2)}))); };
        dialog.append(chrome,error,surface);app.root.append(dialog);if(dialog.showModal)dialog.showModal();else dialog.setAttribute('open','');await call(transversal?{kind:'ENTER',data:{}}:null);
    };
})(window.EIPSIBuilder);
