/* Shared presentation for the public portal, Native confirmation and dummy experience. */
(function(root){
    'use strict';
    const show=modal=>{if(!modal)return;if(modal.showModal)modal.showModal();else modal.setAttribute('open','');};
    const hide=modal=>{if(!modal)return;if(modal.close)modal.close();else modal.removeAttribute('open');};
    const emailModals=new WeakMap();
    const normalize=email=>String(email||'').trim().toLowerCase();
    const message=(content,key,fallback)=>{const node=content?.closest?.('[data-study-messages]')||content?.querySelector?.('[data-study-messages]');if(!node)return fallback;try{return JSON.parse(node.dataset.studyMessages)[key]??fallback;}catch(_){return fallback;}};
    const noticeTimers=new WeakMap();
    const notices=(content,restart=false)=>{
        const items=[...(content.matches?.('.eipsi-portal-notice')?[content]:[]),...content.querySelectorAll('.eipsi-portal-notice')];
        for(const notice of items){
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
        message,notices,
        present(content){notices(content);const modal=content.querySelector('.eipsi-withdrawal-dialog[data-withdrawal-error]');if(modal){show(modal);modal.querySelector('input[name=email]')?.focus();}},
        invalid(event){const input=event.target;if(input.name!=='email'||!input.closest('[data-study-messages]'))return;input.setCustomValidity('');if(input.validity.valueMissing)input.setCustomValidity(message(input,'email_required','Ingresá tu email.'));else if(input.validity.typeMismatch)input.setCustomValidity(message(input,'email_invalid','Ingresá un email válido.'));},
        async click(content,event,lookupWithdrawal){
            const open=event.target.closest('[data-open-withdrawal]');
            if(open){const modal=content.querySelector('.eipsi-withdrawal-dialog');show(modal);modal?.querySelector('input[name=email]')?.focus();}
            const choice=event.target.closest('[data-withdrawal-choice]');
            if(choice){
                const modal=content.querySelector('.eipsi-withdrawal-confirmation'),form=modal?.querySelector('form');if(!form)return;
                const source=content.querySelector('.eipsi-withdrawal-dialog input[name=email]');if(source){
                    if(!source.reportValidity())return;
                    const entry=source.closest('dialog'),error=entry.querySelector('.eipsi-withdrawal-error'),value=source.value;
                    if(entry.hasAttribute('data-withdrawal-lookup')){
                        if(choice.disabled)return;choice.disabled=true;
                        try{if(!lookupWithdrawal)throw Error(message(source,'withdrawal_email_not_found','No encontramos ese email en este estudio.'));const target=await lookupWithdrawal(value,form);if(normalize(value)!==normalize(source.value))return;const revision=form.querySelector('[name=expected_revision]');if(revision)revision.value=String(target.revision);}
                        catch(e){if(error){error.textContent=e.message;error.hidden=false;}source.focus();return;}
                        finally{choice.disabled=false;}
                    }
                    const email=form.querySelector('[name=email]');if(email)email.value=value;
                }
                hide(content.querySelector('.eipsi-withdrawal-dialog'));modal.dataset.phrase=choice.dataset.confirmPhrase;
                modal.querySelectorAll('[data-confirm-description]').forEach(n=>n.textContent=choice.textContent);
                modal.querySelectorAll('[data-confirm-phrase-display]').forEach(n=>n.textContent=choice.dataset.confirmPhrase);
                const input=modal.querySelector('[data-withdrawal-confirm-text]');input.value='';input.setCustomValidity(message(input,'withdrawal_invalid','Escribí exactamente el nombre indicado en mayúsculas.'));
                const mode=form.querySelector('[name=mode]');if(mode)mode.value=choice.dataset.withdrawalChoice;
                modal.querySelector('[data-confirm-withdrawal]').disabled=true;show(modal);input.focus();
            }
            if(event.target.closest('[data-close-withdrawal]'))hide(event.target.closest('dialog'));
            if(event.target.closest('[data-cancel-withdrawal-confirmation]')){hide(event.target.closest('dialog'));show(content.querySelector('.eipsi-withdrawal-dialog'));}
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
            const cancel=modalDocument.createElement('button');cancel.type='button';cancel.textContent=message(form,'change_cancel','Cancelar');cancel.dataset.cancelEmailChange='';cancel.onclick=()=>{hide(modal);modal.remove();};
            const accept=modalDocument.createElement('button');accept.type='button';accept.textContent=message(form,'change_accept','Actualizar correo');accept.dataset.acceptEmailChange='';accept.onclick=()=>{form.dataset.approvedEmail=normalize(input.value);if(confirmation)confirmation.value='1';hide(modal);modal.remove();form.requestSubmit(form.querySelector('button[value="confirm_email"]')||undefined);};
            modal.append(paragraph,cancel,accept);(modalDocument===document?form:modalDocument.body).append(modal);modal.addEventListener('close',()=>{modal.remove();if(emailModals.get(form)===modal)emailModals.delete(form);},{once:true});show(modal);cancel.focus();return false;
        }
    };
})(window);
