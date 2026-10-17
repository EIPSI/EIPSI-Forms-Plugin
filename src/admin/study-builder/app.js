(function (B) {
    'use strict';
    class App {
        constructor(root, transport) {
            this.root = root;this.transport = transport;this.current = null;this.draft = null;this.studies = [];this.templates = [];this.nativeTemplates = [];this.templateChoice = {};this.dirty = false;this.tick = 0;this.pendingSave = null;this.savePromise = null;this.timer = null;this.conflict = false;this.selected = null;this.validation = null;this.acknowledgements = new Set();this.simulation = null;this.commands = [];this.simResponses = {};this.context = { created_at: '2026-10-12T00:00:00Z', timezone: 'UTC', seed_hex: '01'.repeat(32) };this.view = 'structure';this.error = '';this.status = '';this.newAdmin = { name: '' };this.createRequest = B.id('CREATE');
            root.addEventListener('click', e => { if (e.target.closest('button')) Promise.resolve().then(() => {}).catch(error => this.fail(error)); });
            window.addEventListener('beforeunload', e => { if (this.dirty || this.pendingSave) { e.preventDefault();e.returnValue = ''; } });
        }
        get readOnly() { return this.current?.state !== 'DRAFT'; }
        async call(operation, data = {}) { return this.transport({ operation, ...data }); }
        fail(error) { this.error = B.messages[error.message] || error.message || 'La operación falló.';if (error.issues?.length && this.draft) this.validation = { issues: error.issues, diff: [], summary: { number: this.draft.version_number, blocks: this.draft.nodes.filter(n => n.kind === 'BLOCK').length, assessments: this.draft.nodes.filter(n => n.completion_boundary === 'ASSESSMENT').length, enrollment: this.draft.protocol_metadata.enrollment_mode || 'ANONYMOUS', timezone: this.draft.timezone } };this.render();this.root.querySelector('[role=alert]')?.focus(); }
        async start() { try { this.studies = (await this.call('list')).studies;this.templates = (await this.call('templates')).templates;this.nativeTemplates = (await this.call('native_templates')).templates;this.render(); } catch (e) { this.fail(e); } }
        accept(envelope) { this.current = envelope;this.draft = B.copy(envelope.definition);this.context.timezone = this.draft.timezone.default;this.dirty = false;this.conflict = false;this.pendingSave = null;this.validation = null;this.simulation = null;this.commands = [];this.simResponses = {};this.acknowledgements.clear();this.selected = this.selected && B.node(this.draft, this.selected) ? this.selected : this.draft.root_block_ids[0];this.render(); }
        async open(id) { if (this.dirty && !confirm('Descartar los cambios locales sin guardar y abrir la versión del servidor?')) return;clearTimeout(this.timer);this.accept(await this.call('load', { id })); }
        select(id) { this.selected = id;this.render();this.root.querySelector('.sb-inspector input, .sb-inspector button, .sb-inspector select')?.focus(); }
        mutate(fn) {
            if (this.readOnly) return;
            try { this.draft = fn(B.copy(this.draft));this.draft.protocol_metadata.execution_planning='DEPENDENCY_PLAN_V1';this.tick++;this.dirty = true;this.validation = null;this.simulation = null;this.acknowledgements.clear();this.status = 'Cambios locales pendientes';this.render();clearTimeout(this.timer); } catch (e) { this.fail(e); }
        }
        async save() {
            clearTimeout(this.timer);if (this.savePromise) { await this.savePromise;return; }if (this.readOnly || (!this.dirty && !this.pendingSave)) return;
            if (this.conflict) throw Error('DRAFT_REVISION_CONFLICT');
            if (!this.pendingSave) this.pendingSave = { tick: this.tick, payload: { id: this.current.id, revision: this.current.revision, request_id: B.id('SAVE'), definition: B.serialize(this.draft) } };
            const saved = this.pendingSave;this.status = 'Guardando…';this.render();
            this.savePromise = this.call('save', saved.payload).then(envelope => { this.current = envelope;if (saved.tick === this.tick) { this.draft = B.copy(envelope.definition);this.dirty = false; }this.pendingSave = null;this.status = this.dirty ? 'Nuevos cambios pendientes' : 'Draft guardado · revisión ' + envelope.revision; }).catch(error => { if (['DRAFT_REVISION_CONFLICT', 'PUBLISHED_VERSION_IMMUTABLE', 'SAVE_KEY_REUSED'].includes(error.message)) this.conflict = true;throw error; }).finally(() => { this.savePromise = null;this.render(); });
            await this.savePromise;
        }
        async validate(save = true) { if(save&&(this.dirty||this.pendingSave))throw Error('Guardá el draft antes de validar.');this.validation = await this.call('validate', { id: this.current.id });this.render();return this.validation; }
        async preview(commands) { if(this.dirty||this.pendingSave)throw Error('Guardá el draft antes de simular.');return this.call('simulate', { id: this.current.id, context: this.context, commands }); }
        async simulate() { this.view = 'timeline';this.simulation = await this.preview(this.commands);this.simResponses = {};this.render(); }
        async publish() { await this.save();const validation = await this.validate(false);const warnings = validation.issues.filter(i => i.severity === 'WARNING').map(i => i.code + '|' + (i.node_id || i.path || ''));if (validation.issues.some(i => i.severity !== 'WARNING')) throw Error('Corregí los errores antes de publicar.');if (warnings.some(k => !this.acknowledgements.has(k))) throw Error('Revisá y reconocé los warnings antes de publicar.');if (!confirm('¿Publicar la versión ' + this.draft.version_number + '? Los cambios posteriores se guardarán en una nueva versión.')) return;this.accept(await this.call('publish', { id: this.current.id, revision: this.current.revision, acknowledgements: warnings }));this.publicUrlDialog(); }
        render() {
            if(this.management){B.renderManagement(this);return;}
            this.root.replaceChildren();if (this.error) this.root.append(B.el('p', { role: 'alert', tabindex: '-1', text: this.error }), B.button('Cerrar error', () => { this.error = '';this.render(); }));
            const heading = B.el('h2', { class:'sb-study-title', tabindex: '-1', text: this.current ? (this.draft.protocol_metadata.study_name ?? this.current.administration.name) : 'Study Engine' });
            if (!this.current) { this.renderList();return; }
            const toolbar = B.el('div', { class: 'sb-toolbar' }, B.button('Gestionar',()=>B.openManagement(this,this.current.study_id)), B.button('Lista de estudios', async () => { if (this.dirty && !confirm('Descartar cambios locales sin guardar?')) return;clearTimeout(this.timer);this.current = null;await this.start(); }), B.el('span', { role: 'status', text: this.status || 'Revisión ' + this.current.revision }));
            if (!this.readOnly) toolbar.append(B.button('Guardar draft', () => this.save()), B.button('Publicar versión', () => this.publish(), !this.validation || this.validation.issues.some(i => i.severity !== 'WARNING')), B.button('Descartar draft', async () => { if (confirm('Eliminar permanentemente este draft?')) { clearTimeout(this.timer);await this.call('discard', { id: this.current.id, revision: this.current.revision });this.current = null;this.dirty = false;await this.start(); } }));
            else toolbar.append(B.note('Snapshot científico read-only. Los participantes conservan la versión publicada.'), B.button('Crear nueva versión', async () => this.accept(await this.call('clone', { id: this.current.id, revision: this.current.revision }))), B.button('Retirar versión', async () => { if (confirm('Retirar impide nuevos ingresos en esta versión. Los participantes existentes siguen pinned. Continuar?')) this.accept(await this.call('retire', { id: this.current.id, revision: this.current.revision })); }, this.current.state === 'RETIRED'));
            toolbar.querySelectorAll('button').forEach(button=>{button.className='ae-button'+(button.textContent==='Guardar draft'?' ae-primary':'');});this.root.append(B.el('header',{class:'ae-topbar sb-study-topbar'},heading,B.studyStateBadge(this.current.lifecycle?.state||'DRAFT'),toolbar));
            if(B.studyEditor){this.root.append(B.studyEditor(this));return;}
            const settings = B.el('fieldset', { class: 'sb-version-settings' }, B.el('legend', { text: 'Protocolo de la versión' }));settings.disabled = this.readOnly;
            settings.append(B.select('Comportamiento horario', this.draft.timezone.policy, [['STUDY_TIMEZONE', 'Zona del estudio'], ['PARTICIPANT_LOCAL', 'Hora local del participante']], policy => this.mutate(d => { const old = d.timezone.policy;d.timezone.policy = policy;d.nodes.filter(n => n.schedule?.timezone_policy === old).forEach(n => { n.schedule.timezone_policy = policy; });return d; })), B.field('Zona IANA del estudio / fallback', this.draft.timezone.default, value => this.mutate(d => { d.timezone.default = value;return d; }), 'text', { list: 'sb-timezones' }));
            const zones = typeof Intl.supportedValuesOf === 'function' ? ['UTC', ...Intl.supportedValuesOf('timeZone')] : ['UTC', 'Europe/Berlin', 'America/Argentina/Buenos_Aires'];settings.append(B.el('datalist', { id: 'sb-timezones' }, zones.map(value => B.el('option', { value }))));
            settings.append(B.notificationEditor(this.draft, metadata => this.mutate(d => ({ ...d, protocol_metadata: metadata })), async () => { const p = await this.call('email_preview');this.emailPreview = p;this.render(); }));this.root.append(settings);
            if (this.emailPreview) this.root.append(B.el('details', { open: '' }, B.el('summary', { text: 'Email preview — sin envío' }), B.el('p', { text: this.emailPreview.availability.subject }), B.el('iframe', { title: 'Preview de disponibilidad sin envío', sandbox: '', srcdoc: this.emailPreview.availability.html }), B.note('Placeholders soportados: study_name, assessment_name, available_time, due_time, direct_link, withdrawal_link. Catálogo fijo P1-F.')));
            this.root.append(B.el('div', { class: 'sb-tabs' }, B.button('Structure', () => { this.view = 'structure';this.render(); }), B.button('Timeline / Simulate Study', async () => { this.view = 'timeline';if (!this.simulation) await this.simulate();else this.render(); })));
            const layout = B.el('div', { class: this.view === 'structure' ? 'sb-layout' : 'sb-timeline' });if (this.view === 'structure') layout.append(B.tree(this, this.draft, this.selected), B.inspector(this, this.draft, this.selected));else layout.append(B.simulationPanel(this));layout.append(B.validationPanel(this));this.root.append(layout);
        }
        renderList() {
            const refresh = async () => { this.studies = (await this.call('list')).studies;this.render(); };
            const form = B.el('form', { class: 'sb-create', onsubmit: async e => { e.preventDefault();this.accept(await this.call('create', { administration: this.newAdmin, request_id: this.createRequest }));this.createRequest = B.id('CREATE');this.newAdmin = { name: '' }; } }, B.el('h3', { text: 'Crear estudio' }), B.field('Nombre', this.newAdmin.name, value => { this.newAdmin.name = value; }, 'text', { required: true, maxlength: 200 }), B.note('El identificador público se genera automáticamente y se conserva al renombrar el estudio.'), B.el('button', { type: 'submit', class: 'button button-primary', text: 'Crear estudio' }));
            this.root.append(form);
            this.root.append(B.check('Mostrar estudios archivados', this.showArchived || false, value => { this.showArchived = value;this.render(); }));
            const filter=B.el('div',{class:'mg-filters'},B.field('Buscar estudio',this.studySearch||'',v=>{this.studySearch=v;this.render();}),B.select('Tipo',this.studyTypeFilter||'',[['','Todos'],['TRANSVERSAL','Transversal'],['LONGITUDINAL','Longitudinal']],v=>{this.studyTypeFilter=v;this.render();}),B.select('Estado',this.studyStateFilter||'',[['','Todos'],['DRAFT','Draft'],['ACTIVE','Activo'],['PAUSED','Pausado'],['FINALIZED','Finalizado']],v=>{this.studyStateFilter=v;this.render();}));const listBlock=B.el('section',{class:'sb-study-list'},filter);
            const visible = this.studies.filter(s => (this.showArchived || !s.archived)&&(!this.studySearch||s.name.toLowerCase().includes(this.studySearch.toLowerCase()))&&(!this.studyTypeFilter||s.type===this.studyTypeFilter)&&(!this.studyStateFilter||s.lifecycle?.state===this.studyStateFilter));
            if (!visible.length) listBlock.append(B.note('Todavía no hay estudios. Creá el primero y añadí una evaluación.'));
            const grid = B.el('div', { class: 'sb-study-grid' });
            visible.forEach(s => {
                const latest = s.versions[0], published = s.versions.find(v => v.id === s.active);
                const card = B.el('section', { class: 'sb-study' }, B.el('div',{class:'sb-study-card-heading'},B.el('h3', { text: s.name })),B.el('div',{class:'sb-study-card-badges'}, B.el('span', { class: 'sb-badge', text: s.type === 'LONGITUDINAL' ? 'Longitudinal' : 'Transversal' }), B.studyStateBadge(s.lifecycle?.state||(published?'ACTIVE':'DRAFT'))), B.note('Identificador público: ' + s.public_id), B.note('Actualizado: ' + (s.updated_at || '—')));
                if(s.management)card.append(B.note(s.management.participations+' participaciones · todos los ciclos'),B.note('Última respuesta confirmada: '+(s.management.last_response||'Sin respuestas')),B.note('Incidencias de envío registradas: '+s.management.delivery_incidents));
                if(s.archived)card.append(B.el('span',{class:'sb-badge',text:'Archivado'}));
                if (s.public_url && published && !s.archived && s.lifecycle?.state!=='FINALIZED') {
                    const input=B.el('input',{type:'text',readonly:'',value:s.public_url,'aria-label':'URL pública de '+s.name}),icon=B.el('span',{class:'dashicons dashicons-admin-page','aria-hidden':'true'}),copy=B.button('',async()=>{await navigator.clipboard.writeText(s.public_url);copy.title='URL copiada';copy.setAttribute('aria-label','URL copiada');icon.className='dashicons dashicons-yes';});
                    copy.classList.add('sb-copy-url');copy.title='Copiar URL';copy.setAttribute('aria-label','Copiar URL');copy.append(icon);card.append(B.el('div',{class:'sb-public-url'},input,copy));
                }
                const menu=B.el('details',{class:'sb-card-menu'}),toggle=B.el('summary',{'aria-label':'Opciones de '+s.name,title:'Opciones del estudio',text:'⋮'}),panel=B.el('div',{class:'sb-card-menu-panel'});
                panel.append(B.button('Duplicar', async () => { const source = await this.call('load', { id: latest.id });this.accept(await this.call('duplicate_study', { id: latest.id, revision: source.revision, request_id: B.id('DUPLICATE') })); }), B.button('Archivar', async () => { if (!confirm('Archivar este estudio y cerrar nuevos ingresos? Se conservan versiones, participantes y respuestas.')) return;const source = await this.call('load', { id: latest.id });await this.call('archive_study', { id: latest.id, revision: source.revision });await refresh(); }, s.archived), B.button('Eliminar definitivamente', () => this.deleteStudyDialog(s, refresh)));menu.append(toggle,panel);
                menu.addEventListener('click',event=>{if(event.target.closest('button'))menu.open=false;},true);
                menu.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();menu.open=false;toggle.focus();}});
                menu.addEventListener('focusout',event=>{if(!menu.contains(event.relatedTarget))menu.open=false;});
                card.querySelector('.sb-study-card-heading').append(menu);
                card.append(B.el('div', { class: 'sb-card-actions' }, B.button('Gestionar',()=>B.openManagement(this,s.id)), B.button('Editar', async () => this.accept(await this.call('edit', { id: latest.id })))));
                grid.append(card);
            });listBlock.append(grid);this.root.append(listBlock);
        }
        async changeStudyState(state) {
            if(this.dirty||this.pendingSave)throw Error('Guardá el draft antes de cambiar el estado del estudio.');
            if(state!=='FINALIZED'){
                const message=state==='REOPEN'?'¿Reabrir este estudio? Los participantes anteriores quedan fuera. Cada ingreso se registrará como un sujeto nuevo.':state==='PAUSED'?'¿Pausar nuevos ingresos? Los participantes actuales podrán continuar.':'¿Activar el estudio para nuevos participantes?';
                if(!confirm(message))return;
                this.accept(await this.call('study_state',{id:this.current.id,lifecycle_revision:this.current.lifecycle.revision,state,erase_emails:false,request_id:B.id('STATE')}));return;
            }
            const summary=await this.call('study_state_summary',{id:this.current.id}),request=B.id('FINALIZE');let erase=true;
            const dialog=B.el('dialog',{class:'sb-modal sb-finalize-modal','aria-label':'Finalizar estudio'},B.el('h2',{text:'Finalizar estudio'}),B.el('p',{text:summary.participants+' participaciones · '+summary.assessments_in_progress+' evaluaciones en curso. Se cerrará toda participación y se cancelarán los envíos pendientes. Las respuestas y parciales guardados se conservarán para consulta y exportación.'}),B.check('Eliminar los emails de los participantes',true,value=>erase=value),B.note('Si conservás los emails, quedarán como datos históricos y no se usarán para recuperar participaciones al reabrir.'));
            const error=B.el('p',{role:'alert',hidden:true}),submit=B.button('Finalizar estudio',async()=>{submit.disabled=true;try{const result=await this.call('study_state',{id:this.current.id,lifecycle_revision:summary.lifecycle.revision,state,erase_emails:erase,request_id:request});dialog.close();this.accept(result);}catch(e){submit.disabled=false;error.textContent=B.messages[e.message]||e.message;error.hidden=false;}});
            dialog.append(error,B.button('Cancelar',()=>dialog.close()),submit);dialog.addEventListener('close',()=>dialog.remove(),{once:true});this.root.append(dialog);if(dialog.showModal)dialog.showModal();else dialog.setAttribute('open','');
        }
        publicUrlDialog() {
            const focus=document.activeElement,url=this.current.public_url;
            const dialog=B.el('dialog',{class:'sb-modal sb-public-url-modal','aria-label':'Estudio publicado'},B.el('header',{class:'ae-topbar'},B.el('h2',{text:'Versión publicada'})),B.el('p',{},B.el('a',{href:url,target:'_blank',rel:'noopener',text:url})),B.note('El estado y la activación se gestionan en Configuración General → Estado del estudio.'));
            const copy=B.button('Copiar URL',async()=>{await navigator.clipboard.writeText(url);copy.textContent='URL copiada';}),close=B.button('Cerrar',()=>dialog.close());
            copy.className='ae-button ae-primary';close.className='ae-button';dialog.append(B.el('div',{class:'sb-toolbar'},copy,close));dialog.addEventListener('close',()=>{dialog.remove();focus?.focus();},{once:true});this.root.append(dialog);if(dialog.showModal)dialog.showModal();else dialog.setAttribute('open','');
        }
        deleteStudyDialog(study, refresh) {
            const dialog = B.el('dialog', { class: 'sb-modal', 'aria-label': 'Eliminar estudio definitivamente' });
            let confirmation = '';
            const expected = 'ELIMINAR ' + study.id;
            dialog.append(B.el('h2', { text: 'Eliminar definitivamente ' + study.name }), B.el('p', { text: 'Se eliminarán todas las versiones, configuraciones, participantes propios de este estudio, asignaciones, ejecuciones, respuestas, metadatos, parciales, recuperación y trabajos vinculados. Esta acción no se puede deshacer. Los Assessments compartidos y los datos de otros estudios se conservan.' }), B.field('Escribí ' + expected, '', value => { confirmation = value; }), B.button('Cancelar', () => dialog.remove()), B.button('Eliminar definitivamente', async () => { if (confirmation !== expected) throw Error('La confirmación no coincide.');await this.call('delete_study', { study_id: study.id, confirmation });dialog.remove();await refresh(); }));
            this.root.append(dialog);if (dialog.showModal) dialog.showModal();else dialog.setAttribute('open', '');
        }
    }
    B.studyStateBadge=state=>B.el('span',{class:'sb-study-state','data-state':state,text:({DRAFT:'Draft',ACTIVE:'Activo',PAUSED:'Pausado',FINALIZED:'Finalizado'})[state]||state});
    B.studyStatePanel=app=>{
        const lifecycle=app.current.lifecycle||{state:'DRAFT',revision:0,cycle:1},state=lifecycle.state,box=B.el('section',{class:'sb-study-state-panel'},B.el('h3',{text:'Estado del estudio'}),B.studyStateBadge(state));
        const actions=state==='FINALIZED'?[['REOPEN','Reapertura']]:state==='DRAFT'?[['ACTIVE','Activar']]:[['ACTIVE','Activar'],['PAUSED','Pausar'],['FINALIZED','Finalizar estudio']].filter(([key])=>key!==state);
        box.append(B.el('div',{class:'sb-toolbar'},actions.map(([key,label])=>B.button(label,()=>app.changeStudyState(key),state==='DRAFT'&&app.current.state!=='PUBLISHED'&&!lifecycle.published_version_id))));
        if(state==='DRAFT')box.append(B.note('Publicá una versión antes de activar el estudio.'));if(state==='FINALIZED')box.append(B.note('Las participaciones anteriores están cerradas. La reapertura conserva este estudio y registra sujetos nuevos.'));
        return box;
    };
    B.App = App;
    B.transport = config => async payload => { const body = new URLSearchParams({ action: 'eipsi_study_builder', nonce: config.nonce, payload: JSON.stringify(payload) });const response = await fetch(config.url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });const result = await response.json();if (!response.ok || !result.success) { const error = Error(result.data?.code || 'BUILDER_REQUEST_FAILED');error.issues = result.data?.issues || [];throw error; }return result.data; };
    // Every control's async/sync error is routed to the accessible error panel.
    const button = B.button;B.button = (label, action, disabled) => button(label, () => { try { Promise.resolve(action()).catch(e => B.active?.fail(e)); } catch (e) { B.active?.fail(e); } }, disabled);
    const ready = () => { const root = document.getElementById('eipsi-study-builder');if (!root || !window.eipsiStudyBuilder) return;B.active = new App(root, B.transport(window.eipsiStudyBuilder));B.active.start(); };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);else ready();
})(window.EIPSIBuilder);
