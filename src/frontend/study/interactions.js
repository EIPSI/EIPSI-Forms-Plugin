/* Shared presentation for the public portal, Native confirmation and dummy experience. */
(function(root){
    'use strict';
    const show=(modal,trigger)=>{if(!modal)return;if(trigger)rememberTrigger(modal,trigger);prepareModal(modal);if(modal.showModal)modal.showModal();else modal.setAttribute('open','');};
    const hide=(modal,restore=false)=>{if(!modal)return;const state=modalTriggers.get(modal);if(state)state.restore=restore;if(modal.close)modal.close();else modal.removeAttribute('open');modalSizing.get(modal)?.();if(restore)restoreTrigger(modal);};
    const emailModals=new WeakMap();
    const loadingIndicators=new WeakMap();
    const loading=(button,active)=>{
        if(!button)return;
        if(active){
            if(loadingIndicators.has(button))return;
            const spinner=document.createElement('span');spinner.className='eipsi-loading-spinner';spinner.setAttribute('aria-hidden','true');
            loadingIndicators.set(button,{spinner,busy:button.getAttribute('aria-busy'),hadClass:button.classList.contains('eipsi-is-loading')});
            button.classList.add('eipsi-is-loading');button.setAttribute('aria-busy','true');
            if(button.tagName==='BUTTON')button.prepend(spinner);
        }else{
            const state=loadingIndicators.get(button);if(!state)return;
            state.spinner.remove();if(!state.hadClass)button.classList.remove('eipsi-is-loading');
            if(state.busy===null)button.removeAttribute('aria-busy');else button.setAttribute('aria-busy',state.busy);
            loadingIndicators.delete(button);
        }
    };
    const raf=(view,fn)=>view.requestAnimationFrame?view.requestAnimationFrame(fn):view.setTimeout(fn,0);
    const cancelRaf=(view,id)=>view.cancelAnimationFrame?view.cancelAnimationFrame(id):view.clearTimeout(id);
    const viewportJobs=new WeakMap();
    const viewport=host=>{
        if(viewportJobs.has(host))return;
        const view=host.ownerDocument.defaultView;
        viewportJobs.set(host,raf(view,()=>{
            viewportJobs.delete(host);if(!host.isConnected)return;
            const scroll=(win,top)=>{if(Math.abs((win.scrollY||0)-top)>.5)win.scrollTo({top,left:win.scrollX||0,behavior:'auto'});};
            // No animation, including reduced-motion; never move scientific focus.
            scroll(view,Math.max(0,host.getBoundingClientRect().top+(view.scrollY||0)));
            try{const frame=view.frameElement;if(frame?.matches('.eipsi-native-assessment-frame')){
                const parent=frame.ownerDocument.defaultView;
                scroll(parent,Math.max(0,frame.getBoundingClientRect().top+(parent.scrollY||0)));
            }}catch(_){/* Cross-origin parents are not presentation owners. */}
        }));
    };
    const resourceStates=new WeakMap();
    const watchRemoval=(node,cleanup)=>{
        const doc=node.ownerDocument;let state=resourceStates.get(doc);
        if(!state){state={items:new Map(),observer:new doc.defaultView.MutationObserver(()=>{
            for(const [item,stop]of [...state.items])if(!item.isConnected){state.items.delete(item);stop();}
            if(!state.items.size){state.observer.disconnect();resourceStates.delete(doc);}
        })};state.observer.observe(doc.documentElement,{childList:true,subtree:true});resourceStates.set(doc,state);}
        state.items.set(node,cleanup);
        return()=>{state.items.delete(node);if(!state.items.size){state.observer.disconnect();resourceStates.delete(doc);}};
    };
    const nativeSizing=new WeakMap();
    const supportsTextarea=doc=>{
        if(nativeSizing.has(doc))return nativeSizing.get(doc);
        if(!doc.defaultView.CSS?.supports?.('field-sizing','content')){nativeSizing.set(doc,false);return false;}
        const probe=doc.createElement('textarea');probe.rows=1;probe.value='x\nx\nx';probe.tabIndex=-1;probe.setAttribute('aria-hidden','true');
        probe.style.cssText='position:absolute;visibility:hidden;pointer-events:none;width:100px;padding:0;border:0;min-height:0;height:auto;line-height:16px;font-size:16px;field-sizing:content';
        doc.body.append(probe);const supported=probe.clientHeight>=47;probe.remove();nativeSizing.set(doc,supported);return supported;
    };
    const autosize=control=>{
        if(supportsTextarea(control.ownerDocument))return;
        const view=control.ownerDocument.defaultView;let job=0,observer,unwatch,started=false,disposed=false,width=-1;
        const stop=()=>{if(disposed)return;disposed=true;if(job)cancelRaf(view,job);observer?.disconnect();view.removeEventListener('resize',schedule);control.removeEventListener('input',schedule);unwatch?.();};
        const schedule=()=>{if(!disposed&&!job)job=raf(view,measure);};
        const measure=()=>{
            job=0;if(!control.isConnected){if(started)stop();return;}
            if(!started){started=true;unwatch=watchRemoval(control,stop);
                if(view.ResizeObserver){observer=new view.ResizeObserver(()=>{if(control.getBoundingClientRect().width!==width)schedule();});observer.observe(control);}
                else view.addEventListener('resize',schedule);
            }
            width=control.getBoundingClientRect().width;
            const old=control.style.height,css=view.getComputedStyle(control);
            control.style.height='auto';
            const height=Math.ceil(control.scrollHeight+(parseFloat(css.borderTopWidth)||0)+(parseFloat(css.borderBottomWidth)||0));
            control.style.height=height>0?height+'px':old;
        };
        control.addEventListener('input',schedule);schedule();control.ownerDocument.fonts?.ready.then(schedule);
    };
    const modalSizing=new WeakMap();
    const prepareModal=modal=>{
        if(modalSizing.has(modal))return;
        const view=modal.ownerDocument.defaultView,vv=view.visualViewport;let job=0,disposed=false,unwatch;
        const update=()=>{job=0;if(disposed)return;
            for(const [key,value]of [['--eipsi-visual-height',vv?.height||view.innerHeight],['--eipsi-visual-top',vv?.offsetTop||0]]){
                const next=Math.max(0,value)+'px';if(modal.style.getPropertyValue(key)!==next)modal.style.setProperty(key,next);
            }
            const active=modal.ownerDocument.activeElement;
            if(modal.open&&modal.contains(active)&&!active.closest('.ae-preview-question')){
                const box=modal.getBoundingClientRect(),field=active.getBoundingClientRect();
                if(box.height>0&&field.height>0){
                    if(field.bottom>box.bottom-12)modal.scrollTop+=field.bottom-box.bottom+12;
                    else if(field.top<box.top+12)modal.scrollTop-=box.top+12-field.top;
                }
            }
        };
        const schedule=()=>{if(!job&&!disposed)job=raf(view,update);};
        const stop=()=>{if(disposed)return;disposed=true;if(job)cancelRaf(view,job);vv?.removeEventListener('resize',schedule);vv?.removeEventListener('scroll',schedule);view.removeEventListener('resize',schedule);modal.removeEventListener('close',stop);modal.style.removeProperty('--eipsi-visual-height');modal.style.removeProperty('--eipsi-visual-top');modalSizing.delete(modal);unwatch?.();};
        modalSizing.set(modal,stop);update();vv?.addEventListener('resize',schedule);vv?.addEventListener('scroll',schedule);view.addEventListener('resize',schedule);modal.addEventListener('close',stop,{once:true});unwatch=watchRemoval(modal,stop);
    };
    const modalTriggers=new WeakMap();
    const safeTrigger=trigger=>trigger?.isConnected&&!trigger.disabled&&!trigger.closest('.ae-preview-question,[data-row-id],[data-widget]')&&!trigger.closest('dialog:not([open])');
    const rememberTrigger=(modal,trigger)=>{
        if(!safeTrigger(trigger))return;
        const doc=trigger.ownerDocument,scope=trigger.closest('#eipsi-study,.ae-native-runtime');
        const scientific=trigger.closest('.ae-native-runtime');
        const state={trigger,scope,scientific,scientificContext:scientific?.dataset.eipsiViewportContext,route:doc.defaultView.location.href,context:scope?.dataset.eipsiViewportContext||scope?.dataset.state,restore:true};
        modalTriggers.set(modal,state);
        if(!modal.dataset.eipsiTriggerTracked){modal.dataset.eipsiTriggerTracked='1';modal.addEventListener('close',()=>restoreTrigger(modal));}
    };
    const restoreTrigger=modal=>{
        const state=modalTriggers.get(modal);if(!state?.restore)return;state.restore=false;
        const {trigger,scope,scientific}=state,doc=trigger.ownerDocument;
        if(scientific&&(!scientific.isConnected||scientific.dataset.eipsiViewportContext!==state.scientificContext))return;
        if(!safeTrigger(trigger)||doc.defaultView.location.href!==state.route||scope&&(!scope.isConnected||(scope.dataset.eipsiViewportContext||scope.dataset.state)!==state.context))return;
        if(doc.activeElement!==trigger)trigger.focus({preventScroll:true});
    };
    const normalize=email=>String(email||'').trim().toLowerCase();
    const message=(content,key,fallback)=>{const node=content?.closest?.('[data-study-messages]')||content?.querySelector?.('[data-study-messages]');if(!node)return fallback;try{return JSON.parse(node.dataset.studyMessages)[key]??fallback;}catch(_){return fallback;}};
    const noticeTimers=new WeakMap();
    const notices=(content,restart=false)=>{
        const items=[...(content.matches?.('.eipsi-portal-notice')?[content]:[]),...content.querySelectorAll('.eipsi-portal-notice')];
        for(const notice of items){
            const parent=notice.closest('.eipsi-portal-notices');if(parent)parent.removeAttribute('aria-live');
            notice.removeAttribute('aria-live');if(!notice.hasAttribute('role'))notice.setAttribute('role',notice.classList.contains('is-error')?'alert':'status');
            const previous=noticeTimers.get(notice);
            if(previous&&!restart&&notice.querySelector('[data-dismiss-notice]'))continue;
            if(previous)root.clearTimeout(previous);
            let close=notice.querySelector('[data-dismiss-notice]');
            if(!close){
                const text=document.createElement('div');text.dataset.noticeContent='';text.append(...notice.childNodes);
                close=document.createElement('button');close.type='button';close.className='eipsi-notice-close';close.dataset.dismissNotice='';close.textContent='×';
                close.setAttribute('aria-label',message(notice,'notice_close_label',document.documentElement.lang==='es'?'Cerrar aviso':'Dismiss notice'));
                notice.append(text,close);
            }
            const dismiss=()=>{root.clearTimeout(noticeTimers.get(notice));noticeTimers.delete(notice);notice.remove();};
            close.onclick=dismiss;
            noticeTimers.set(notice,root.setTimeout(dismiss,40000));
        }
    };
    root.EIPSIStudyInteraction={
        message,notices,loading,viewport,autosize,
        present(content){notices(content);const modal=content.querySelector('.eipsi-withdrawal-dialog[data-withdrawal-error]');if(modal){show(modal);modal.querySelector('input[name=email]')?.focus();}},
        invalid(event){const input=event.target;if(input.name!=='email'||!input.closest('[data-study-messages]'))return;input.setCustomValidity('');if(input.validity.valueMissing)input.setCustomValidity(message(input,'email_required','Ingresá tu email.'));else if(input.validity.typeMismatch)input.setCustomValidity(message(input,'email_invalid','Ingresá un email válido.'));},
        async click(content,event,lookupWithdrawal){
            const open=event.target.closest('[data-open-withdrawal]');
            if(open){const modal=content.querySelector('.eipsi-withdrawal-dialog');show(modal,open);modal?.querySelector('input[name=email]')?.focus();}
            const choice=event.target.closest('[data-withdrawal-choice]');
            if(choice){
                const modal=content.querySelector('.eipsi-withdrawal-confirmation'),form=modal?.querySelector('form');if(!form)return;
                const source=content.querySelector('.eipsi-withdrawal-dialog input[name=email]');if(source){
                    if(!source.reportValidity())return;
                    const entry=source.closest('dialog'),error=entry.querySelector('.eipsi-withdrawal-error'),value=source.value;
                    if(entry.hasAttribute('data-withdrawal-lookup')){
                        if(choice.disabled)return;choice.disabled=true;loading(choice,true);
                        try{if(!lookupWithdrawal)throw Error(message(source,'withdrawal_email_not_found','No encontramos ese email en este estudio.'));const target=await lookupWithdrawal(value,form);if(normalize(value)!==normalize(source.value))return;const revision=form.querySelector('[name=expected_revision]');if(revision)revision.value=String(target.revision);}
                        catch(e){if(error){error.textContent=e.message;error.hidden=false;}source.focus();return;}
                        finally{loading(choice,false);choice.disabled=false;}
                    }
                    const email=form.querySelector('[name=email]');if(email)email.value=value;
                }
                rememberTrigger(modal,choice);
                hide(content.querySelector('.eipsi-withdrawal-dialog'));modal.dataset.phrase=choice.dataset.confirmPhrase;
                modal.querySelectorAll('[data-confirm-description]').forEach(n=>n.textContent=choice.textContent);
                modal.querySelectorAll('[data-confirm-phrase-display]').forEach(n=>n.textContent=choice.dataset.confirmPhrase);
                const input=modal.querySelector('[data-withdrawal-confirm-text]');input.value='';input.setCustomValidity(message(input,'withdrawal_invalid','Escribí exactamente el nombre indicado en mayúsculas.'));
                const mode=form.querySelector('[name=mode]');if(mode)mode.value=choice.dataset.withdrawalChoice;
                modal.querySelector('[data-confirm-withdrawal]').disabled=true;show(modal,choice);input.focus();
            }
            if(event.target.closest('[data-close-withdrawal]'))hide(event.target.closest('dialog'),true);
            if(event.target.closest('[data-cancel-withdrawal-confirmation]')){const modal=event.target.closest('dialog');hide(modal);show(content.querySelector('.eipsi-withdrawal-dialog'));const state=modalTriggers.get(modal);if(state)state.restore=true;restoreTrigger(modal);}
        },
        input(event){
            if(event.target.name==='email'){event.target.setCustomValidity('');const error=event.target.closest('.eipsi-withdrawal-dialog')?.querySelector('.eipsi-withdrawal-error');if(error){error.hidden=true;error.textContent='';}}
            if(!event.target.matches('[data-withdrawal-confirm-text]'))return;
            const modal=event.target.closest('dialog'),valid=event.target.value===modal.dataset.phrase;
            event.target.setCustomValidity(valid?'':message(event.target,'withdrawal_invalid','Escribí exactamente el nombre indicado en mayúsculas.'));modal.querySelector('[data-confirm-withdrawal]').disabled=!valid;
        },
        confirmEmail(form){
            if(!form.hasAttribute('data-previous-email'))return true;
            const input=form.querySelector('[name=email]');if(!input||!input.reportValidity())return false;
            const changed=normalize(input.value)!==normalize(form.dataset.previousEmail);
            const confirmation=form.querySelector('[name=confirm_email_change]');if(confirmation)confirmation.value='0';
            if(!changed)return true;
            if(form.dataset.approvedEmail===normalize(input.value)){if(confirmation)confirmation.value='1';return true;}
            let modal=emailModals.get(form)||form.querySelector('.eipsi-email-change');if(modal)modal.remove();
            let modalDocument=document;try{if(root.frameElement&&root.top.document)modalDocument=root.top.document;}catch(_){/* Keep the local modal when the parent is not accessible. */}
            modal=modalDocument.createElement('dialog');emailModals.set(form,modal);modal.className='eipsi-email-change';if(modalDocument!==document){const typography=root.getComputedStyle(form);for(const key of ['fontFamily','fontSize','lineHeight'])if(typography[key])modal.style[key]=typography[key];}modal.setAttribute('aria-label',message(form,'change_title','Confirmar cambio de correo'));
            const paragraph=modalDocument.createElement('p');paragraph.textContent=message(form,'change_body','El email es diferente al introducido anteriormente. ¿Estás seguro de que querés actualizar el correo?');
            const cancel=modalDocument.createElement('button');cancel.type='button';cancel.textContent=message(form,'change_cancel','Cancelar');cancel.dataset.cancelEmailChange='';cancel.onclick=()=>{hide(modal,true);modal.remove();};
            const accept=modalDocument.createElement('button');accept.type='button';accept.textContent=message(form,'change_accept','Actualizar correo');accept.dataset.acceptEmailChange='';accept.onclick=()=>{form.dataset.approvedEmail=normalize(input.value);if(confirmation)confirmation.value='1';hide(modal);modal.remove();form.requestSubmit(form.querySelector('button[value="confirm_email"]')||undefined);};
            modal.append(paragraph,cancel,accept);(modalDocument===document?form:modalDocument.body).append(modal);modal.addEventListener('close',()=>{modal.remove();if(emailModals.get(form)===modal)emailModals.delete(form);},{once:true});show(modal,form.querySelector('button[value="confirm_email"]')||form.querySelector('button[type=submit],button:not([type])'));cancel.focus();return false;
        }
    };
})(window);
