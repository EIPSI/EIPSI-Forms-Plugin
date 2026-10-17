(function(B){
    'use strict';
    B.emailKinds=[['VERIFY_PARTICIPANT','Validación del email'],['ASSESSMENT_AVAILABLE','Evaluación disponible'],['ASSESSMENT_REMINDER','Recordatorio']];
    B.emailVariables={study_name:'Nombre del estudio',assessment_name:'Evaluación de esta oportunidad',available_time:'Fecha y hora de disponibilidad',due_time:'Fecha y hora de vencimiento',direct_link:'Enlace personal de acceso y validación',withdrawal_link:'Opciones de abandono'};
    B.emailRich=(template,changed,readOnly=false)=>{
        if(!window.wp?.editor?.initialize){const A=window.EIPSIAssessment,p={label:'',label_html:template.html};if(!A?.richText)return B.field('Cuerpo del email',template.html,v=>{template.html=v;changed();},'textarea',{rows:8,disabled:readOnly});return A.richText.editor(p,'label','Cuerpo del email',()=>{if(readOnly)return;template.html=p.label_html||'';changed();});}
        const id=B.id('email-body'),textarea=B.el('textarea',{id,value:template.html,disabled:readOnly,'aria-label':'Cuerpo del email',oninput:e=>{template.html=e.target.value;changed();}}),box=B.el('div',{class:'sb-email-rich-editor'},textarea);
        setTimeout(()=>{if(!box.isConnected)return;window.wp.editor.initialize(id,{mediaButtons:!readOnly,quicktags:!readOnly,tinymce:{height:380,readonly:readOnly,menubar:'edit insert view format',plugins:'charmap colorpicker directionality fullscreen hr image link lists paste textcolor wordpress wpeditimage',toolbar1:'undo redo | formatselect fontselect fontsizeselect | bold italic underline strikethrough',toolbar2:'forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | blockquote subscript superscript | link unlink image hr charmap | removeformat fullscreen',formats:{bold:{inline:'strong'},italic:{inline:'em'}},setup:ed=>{ed.on('change input Undo Redo',()=>{if(readOnly)return;template.html=ed.getContent();changed();});}}});},0);return box;
    };
    B.emailVariableList=()=>{const box=B.el('details',{},B.el('summary',{text:'Shortcodes disponibles'}),B.note('Copiá el shortcode en el asunto o el cuerpo. Se completa con los datos del participante al enviar; los enlaces personales no se guardan en el template.'));
        const table=B.el('table',{class:'widefat striped'});for(const [key,description] of Object.entries(B.emailVariables))table.append(B.el('tr',{},B.el('td',{},B.el('code',{text:'['+key+']'})),B.el('td',{text:description})));box.append(table);return box;};
    B.emailFields=(t,write,readOnly=false)=>B.el('div',{class:'sb-mail-fields'},B.field('Nombre del remitente',t.from_name||'',v=>write('from_name',v),'text',{placeholder:'Usar remitente configurado',disabled:readOnly}),B.field('Asunto',t.subject,v=>write('subject',v),'text',{disabled:readOnly}),B.note('Se usa el email del remitente configurado en el sistema. El asunto y el cuerpo se envían tal como están en este template, completando los shortcodes. No se agregan textos ni enlaces.'));
    B.emailTemplateSelectors=app=>{
        const box=B.propertyGroup('Templates de Emails');box.classList.add('sb-email-selectors');
        if(!app.emailLibrary&&!app.emailLibraryLoading&&!app.emailLibraryFailed){app.emailLibraryLoading=true;app.call('email_library').then(data=>{app.emailLibrary=data;app.emailLibraryLoading=false;app.render();}).catch(e=>{app.emailLibraryLoading=false;app.emailLibraryFailed=true;app.fail(e);});}
        const templates=app.draft.protocol_metadata.longitudinal.initial_screen?.templates||B.initialScreenDefaults().templates;
        for(const [key,label] of B.emailKinds){
            const t=templates[key]||{subject:'',html:''},candidates=(app.emailLibrary?.templates||[]).filter(entry=>entry.purpose===key);
            const custom=!t.source_id&&Boolean(t.subject||t.html||t.from_name||t.from_email),value=t.source_id||(custom?'__existing':'');
            const options=[['','Predeterminado'],...candidates.map(entry=>[entry.id,entry.name])];
            if(value&&!options.some(v=>v[0]===value))options.push([value,t.source_name||'Configuración existente']);
            box.append(B.select(label,value,options,id=>{if(id===value)return;const entry=candidates.find(v=>v.id===id);if(id&&!entry)return;app.mutate(d=>{const c=d.protocol_metadata.longitudinal;c.initial_screen=c.initial_screen||B.initialScreenDefaults();c.initial_screen.templates=c.initial_screen.templates||B.initialScreenDefaults().templates;c.initial_screen.templates[key]=entry?{subject:entry.subject,html:entry.html,from_name:entry.from_name||'',source_id:entry.id,source_revision:entry.revision,source_name:entry.name}:{subject:'',html:''};return d;});}));
        }
        box.append(B.button('Actualizar biblioteca',async()=>{app.emailLibrary=await app.call('email_library');app.render();}),B.note('La selección guarda una copia en este estudio. Editá remitente, asunto y cuerpo en Templates de Emails; actualizar la biblioteca no cambia las copias guardadas.'));
        if(app.readOnly)box.querySelectorAll('select').forEach(e=>e.disabled=true);
        return box;
    };
    const mailButton=(label,action,disabled=false)=>{const b=B.button(label,action,disabled);b.className='ae-button'+(['Guardar template','Nuevo template'].includes(label)?' ae-primary':'');return b;};
    B.emailPreviewModal=(app,preview)=>{
        const focus=document.activeElement,dialog=B.el('dialog',{class:'sb-modal sb-email-preview-modal','aria-label':'Previsualización del template de email'},B.el('header',{class:'ae-topbar'},B.el('h2',{text:'Previsualización del email'}),mailButton('Cerrar previsualización',()=>dialog.close())),B.el('p',{class:'sb-email-preview-subject',text:'Asunto: '+preview.subject}),B.el('iframe',{title:'Vista previa del email',sandbox:'',srcdoc:preview.html}));
        dialog.addEventListener('close',()=>{dialog.remove();focus?.focus();},{once:true});app.root.append(dialog);dialog.showModal();
    };
    class EmailLibraryApp {
        constructor(root,call){this.root=root;this.call=call;this.library={revision:0,templates:[]};this.entry=null;this.dirty=false;this.error='';this.preview=null;window.addEventListener('beforeunload',e=>{if(this.dirty){e.preventDefault();e.returnValue='';}});}
        fail(e){this.error=e.message==='EMAIL_LIBRARY_REVISION'?'La biblioteca cambió en otra pestaña. Conservamos tu edición; copiá los cambios antes de recargar.':e.message;this.render();}
        async load(){if(this.dirty&&!confirm('Descartar los cambios sin guardar y recargar la biblioteca?'))return;this.library=await this.call({operation:'email_library'});this.entry=null;this.dirty=false;this.error='';this.render();}
        defaultEntry(purpose='VERIFY_PARTICIPANT'){const label=B.emailKinds.find(k=>k[0]===purpose)?.[1]||'';return {name:label,purpose,...B.copy(this.library.defaults?.[purpose]||{subject:'',html:'',from_name:''})};}
        select(entry){if(this.dirty&&!confirm('Descartar los cambios sin guardar?'))return;this.entry=B.copy(entry);this.dirty=false;this.error='';this.preview=null;this.render();}
        readBody(){const field=this.root.querySelector('.sb-email-rich-editor textarea');if(!field||!this.entry)return;const ed=window.tinymce?.get(field.id);this.entry.html=ed&&!ed.isHidden()?ed.getContent():field.value;}
        render(){this.root.querySelectorAll('.sb-email-rich-editor textarea').forEach(e=>window.wp?.editor?.remove?.(e.id));this.root.classList.add('ae-root');this.root.replaceChildren();if(this.error)this.root.append(B.el('p',{role:'alert',text:this.error}));
            this.root.append(B.el('header',{class:'ae-topbar'},mailButton('Nuevo template',()=>this.select(this.defaultEntry())),mailButton('Recargar biblioteca',()=>this.load())));
            const layout=B.el('div',{class:'sb-mail-library-layout'}),list=B.el('section',{class:'sb-email-list'},B.el('h2',{text:'Templates'}));for(const [purpose,label] of B.emailKinds)if(this.library.defaults?.[purpose])list.append(mailButton(label+' · Predeterminado',()=>this.select(this.defaultEntry(purpose))));for(const t of this.library.templates)list.append(mailButton(t.name+' · '+B.emailKinds.find(k=>k[0]===t.purpose)?.[1],()=>this.select(t)));layout.append(list);
            if(this.entry){const t=this.entry,panel=B.el('section',{class:'sb-email-properties'}),canvas=B.el('section',{class:'ae-canvas sb-email-body'},B.el('h2',{text:'Cuerpo del email'})),write=(key,value)=>{t[key]=value;this.dirty=true;};
                canvas.append(B.emailRich(t,()=>write('html',t.html)));
                panel.append(B.el('h2',{text:'Propiedades'}),B.field('Nombre del template',t.name,v=>write('name',v)),B.select('Tipo de email',t.purpose,B.emailKinds,v=>{this.readBody();const old=this.defaultEntry(t.purpose),next=this.defaultEntry(v);for(const key of ['name','subject','html','from_name'])if(t[key]===old[key])t[key]=next[key];write('purpose',v);this.render();}),B.emailFields(t,write),B.emailVariableList(),mailButton('Guardar template',async()=>{this.readBody();const entry={...t};delete entry.revision;delete entry.from_email;this.library=await this.call({operation:'email_library_save',revision:this.library.revision,entry});this.dirty=false;this.entry=null;this.preview=null;this.render();}),mailButton('Vista previa — sin envío',async()=>{this.readBody();const entry={purpose:t.purpose,subject:t.subject,html:t.html,from_name:t.from_name||''};this.preview=await this.call({operation:'email_template_preview',entry});B.emailPreviewModal(this,this.preview);}));
                if(t.id)panel.append(mailButton('Eliminar de la biblioteca',async()=>{if(!confirm('Eliminar este template de la biblioteca? Las copias de los estudios se conservan.'))return;this.library=await this.call({operation:'email_library_delete',id:t.id,revision:this.library.revision});this.entry=null;this.dirty=false;this.preview=null;this.render();}));
                const actions=B.el('footer',{class:'ae-topbar sb-email-actions'},...panel.querySelectorAll(':scope>button'));layout.append(B.el('div',{class:'sb-email-editor'},panel,canvas,actions));
            }else layout.append(B.note('Creá un template o seleccioná uno de la biblioteca. No se envían emails desde esta página.'));this.root.append(layout);this.root.querySelectorAll('.sb-field').forEach(e=>e.classList.add('ae-property'));
        }
    }
    B.EmailLibraryApp=EmailLibraryApp;
    const ready=()=>{const root=document.getElementById('eipsi-email-library');if(!root||!window.eipsiStudyBuilder)return;B.active=new EmailLibraryApp(root,B.transport(window.eipsiStudyBuilder));B.active.load().catch(e=>B.active.fail(e));};
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',ready);else ready();
})(window.EIPSIBuilder);
