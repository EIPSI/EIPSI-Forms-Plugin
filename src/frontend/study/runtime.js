/* Study authentication and withdrawal transport. Assessments own their own transport. */
(() => {
    let pending = false;
    const metadata = () => ({
        screen_width: window.screen.width,
        screen_height: window.screen.height,
        fingerprint: 'ua-' + Array.from(navigator.userAgent).reduce((n, c) => ((n * 31 + c.charCodeAt(0)) >>> 0), 0).toString(16),
    });
    // Waiting remains visible; a browser reload returns to the initial screen with its session.
    const reloadDestination = root => {
        const state=root?.dataset.state;if(!state&&!root?.dataset.unavailable)return;
        const url=new URL(window.location.href);
        if(root.dataset.unavailable==='1')url.searchParams.set('eipsi_notice','unavailable');else url.searchParams.delete('eipsi_notice');
        ['eipsi_confirm','eipsi_cap'].forEach(key=>url.searchParams.delete(key));
        if(state==='WAIT'||state==='ASSESSMENT_COMPLETED'||root.dataset.unavailable==='1'){
            ['eipsi_confirm','eipsi_cap','eipsi_completion'].forEach(key=>url.searchParams.delete(key));
            url.searchParams.set('eipsi_initial','1');
        }
        else if(!['EMAIL_ENTRY','EMAIL_VERIFICATION_REQUIRED'].includes(state))url.searchParams.delete('eipsi_initial');
        if(url.href!==window.location.href)window.history.replaceState(window.history.state,'',url);
    };
    // Same-origin Native assessments grow with their content; the outer page scrolls.
    const frameObservers = new WeakMap();
    const fitAssessment = frame => {
        if (!frame.matches?.('.eipsi-native-assessment-frame')) return;
        frameObservers.get(frame)?.();
        let doc;
        try { doc = frame.contentDocument; if (!doc?.body) return; } catch (_) { return; }
        doc.documentElement.style.minHeight='0';doc.body.style.minHeight='0';
        let job=0,disposed=false,settled=false,candidate=0,last=parseFloat(frame.style.height)||0,observer,mutation,removal;
        const child=doc.defaultView;
        const cleanup=()=>{
            if(disposed)return;disposed=true;
            if(job)window.cancelAnimationFrame(job);
            observer?.disconnect();mutation?.disconnect();removal?.disconnect();
            doc.removeEventListener('load',schedule,true);window.removeEventListener('resize',schedule);child?.removeEventListener('pagehide',cleanup);
            if(frameObservers.get(frame)===cleanup)frameObservers.delete(frame);
        };
        const measure=()=>{
            job=0;if(disposed)return;if(!frame.isConnected){cleanup();return;}
            if(doc.querySelector('.ae-native-runtime[aria-busy="true"]'))return;
            const bottom=Math.max(doc.body.getBoundingClientRect().bottom,...Array.from(doc.body.children,node=>node.getBoundingClientRect().bottom+Math.max(0,parseFloat(child.getComputedStyle(node).marginBottom)||0)));
            const height=Math.ceil(bottom+(child.scrollY||0)+(parseFloat(child.getComputedStyle(doc.body).marginBottom)||0));
            if(!Number.isFinite(height)||height<=0)return; // Keep the last valid height while content is transiently empty.
            if(settled&&height===last){candidate=0;return;}
            if(candidate!==height){candidate=height;schedule();return;} // Confirm a positive measurement on the next frame.
            last=height;candidate=0;settled=true;
            frame.style.height=height+'px';frame.style.minHeight='0px';
        };
        const schedule=()=>{if(!disposed&&!job)job=window.requestAnimationFrame(measure);};
        if(window.ResizeObserver){observer=new ResizeObserver(schedule);observer.observe(doc.body);}else window.addEventListener('resize',schedule);
        mutation=new MutationObserver(schedule);mutation.observe(doc.body,{childList:true,subtree:true,attributes:true,characterData:true});
        removal=new MutationObserver(()=>{if(!frame.isConnected)cleanup();});removal.observe(frame.ownerDocument.documentElement,{childList:true,subtree:true});
        frameObservers.set(frame,cleanup);doc.addEventListener('load',schedule,true);child?.addEventListener('pagehide',cleanup,{once:true});doc.fonts?.ready.then(schedule);schedule();
    };
    window.EIPSIStudyInteraction.fitAssessment=fitAssessment;
    document.addEventListener('load', event => fitAssessment(event.target), true);
    const ready=()=>{const content=document.getElementById('eipsi-study');reloadDestination(content);if(content){window.EIPSIStudyInteraction.present(content);content.querySelectorAll('.eipsi-native-assessment-frame').forEach(fitAssessment);}};
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',ready,{once:true});else ready();
    const showOperationError=(root,text)=>{
                let notices=root.querySelector('.eipsi-portal-notices');
                if(!notices){notices=document.createElement('div');notices.className='eipsi-portal-notices';root.prepend(notices);}
                let notice=notices.querySelector('[data-operation-error]');
                if(!notice){notice=document.createElement('section');notice.className='eipsi-portal-notice is-error';notice.dataset.operationError='1';notice.setAttribute('role','alert');notices.append(notice);}
                notice.textContent=text;window.EIPSIStudyInteraction.notices(notice,true);    };
    const post = async (form, action, button) => {
        if (pending) return;
        pending = true;
        const data = new FormData(form);
        if (button && button.name && button.name !== 'action') data.set(button.name, button.value);
        data.set('action', action);
        if (action === 'enroll') {
            try { data.set('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone); } catch (ignored) { /* Server uses study fallback. */ }
        }
        data.set('metadata_json', JSON.stringify(metadata()));
        const controls = [...form.querySelectorAll('input, select, textarea, button')];
        const disabled = controls.map(control => control.disabled);
        const active = button || form.querySelector('button[type="submit"],button:not([type]),input[type="submit"]');
        form.setAttribute('aria-busy','true');
        window.EIPSIStudyInteraction.loading(active,true);
        controls.forEach(el => { el.disabled = true; });
        try {
            const response = await fetch(form.getAttribute('action') || window.location.href, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
            const result = await response.json();
            if (typeof result.html !== 'string') throw new Error('Invalid response');
            const root = document.getElementById('eipsi-study');
            if (!response.ok && [429,500,503].includes(response.status) && typeof result.error_message === 'string') {
                showOperationError(root,result.error_message);controls.forEach(el=>{el.disabled=false;});return;
            }
            root.outerHTML = result.html;
            const updated = document.getElementById('eipsi-study');
            reloadDestination(updated);window.EIPSIStudyInteraction.present(updated);
            const focus = updated.querySelector('[role="alert"]:not([hidden])') || updated.querySelector('h1');
            if (focus) { focus.setAttribute('tabindex', '-1'); focus.focus(); }
            updated.dispatchEvent(new CustomEvent('eipsi-study-presented', { bubbles: true, detail: { state: result.state } }));
        } catch (error) {
            // Retain the command ID and form for a network retry; never show false success.
            controls.forEach(el => { el.disabled = false; });
            showOperationError(document.getElementById('eipsi-study'),window.EIPSIStudyInteraction.message(form,'operation_failed','No pudimos completar la operación.'));
        } finally {
            pending = false;
            form.removeAttribute('aria-busy');
            window.EIPSIStudyInteraction.loading(active,false);
            controls.forEach((control,index)=>{control.disabled=disabled[index];});
        }
    };
    const lookupWithdrawal=async(email,form)=>{
        const data=new FormData();data.set('action','withdrawal_lookup');data.set('email',email);data.set('csrf',form.querySelector('[name=csrf]').value);data.set('command_id','LOOKUP_'+crypto.randomUUID().replaceAll('-',''));
        let response,result;
        try{response=await fetch(form.getAttribute('action')||window.location.href,{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'},cache:'no-store'});result=await response.json();}
        catch(_){throw Error(window.EIPSIStudyInteraction.message(form,'operation_failed','No pudimos completar la operación.'));}
        if(!response.ok||!result.withdrawal_check)throw Error(result.error_message||window.EIPSIStudyInteraction.message(form,'operation_failed','No pudimos completar la operación.'));
        return result.withdrawal_check;
    };
    document.addEventListener('click',event=>{const content=event.target.closest('#eipsi-study');if(content)window.EIPSIStudyInteraction.click(content,event,lookupWithdrawal);});
    document.addEventListener('invalid',event=>window.EIPSIStudyInteraction.invalid(event),true);
    document.addEventListener('input',event=>window.EIPSIStudyInteraction.input(event));
    document.addEventListener('submit', event => {
        const form = event.target.closest('.eipsi-study-form');
        if (!form) return;
        event.preventDefault();
        const button = event.submitter;
        const action = button && button.name === 'action' ? button.value : (form.querySelector('input[name="action"]')?.value || form.querySelector('button[name="action"]')?.value || '');
        if(!['enroll','confirm_email','withdraw'].includes(action))return;
        const confirmation=form.querySelector('[data-withdrawal-confirm-text]');
        if(confirmation&&confirmation.value!==confirmation.closest('dialog').dataset.phrase)return;
        if(action==='confirm_email'&&!window.EIPSIStudyInteraction.confirmEmail(form))return;
        post(form, action, button);
    });
})();
