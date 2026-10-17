(function(B){
    'use strict';
    B.longitudinalAdd=(d,kind,parent)=>{
        const group=B.node(d,parent);if(group?.metadata.role!=='BLOCK_RANDOMIZATION')parent=d.root_block_ids[0];
        if(kind==='randomization')parent=d.root_block_ids[0];
        let x=B.add(d,parent,'MACRO'),n=x.nodes[x.nodes.length-1];
        n.metadata={label:kind==='randomization'?'Selección de bloques':'Nuevo bloque',role:kind==='randomization'?'BLOCK_RANDOMIZATION':'LONGITUDINAL_BLOCK',independent_children:true,...(kind==='block'?{duration_days:7}:{})};
        n.children_strategy='PARALLEL_AVAILABLE';n.schedule=B.schedule('EXECUTION_CREATED');n.schedule.timezone_policy=x.timezone.policy;
        const owner=B.node(x,parent);
        if(kind==='block'&&owner?.metadata.role==='BLOCK_RANDOMIZATION'){
            if(!owner.randomization){owner.children_strategy='WEIGHTED_RANDOM';owner.randomization={...B.randomConfig(x,owner,'WEIGHTED_RANDOM'),scope:'EXECUTION',draw_count:1,order:'DEFINITION'};}
            x=B.reindex(x);
        }
        return x;
    };
    B.longitudinalDuplicate=(d,id)=>{const out=B.duplicate(d,id),original=B.node(d,id),owner=B.node(out,original.parent_id),copy=out.nodes.find(n=>n.parent_id===original.parent_id&&!B.node(d,n.id));if(owner?.randomization&&copy){owner.randomization.weights[copy.id]=owner.randomization.weights[id]||1;}return out;};
    B.longitudinalWorkspace=app=>{
        const d=app.draft,blocks=d.nodes.filter(n=>n.metadata?.role==='LONGITUDINAL_BLOCK'),groups=d.nodes.filter(n=>n.metadata?.role==='BLOCK_RANDOMIZATION');
        const selected=B.node(d,app.longitudinalSelected||app.calendarBlock)||blocks[0]||groups[0];
        const choose=id=>{if(app.calendarBlock!==id)app.calendarStart=1;app.longitudinalSelected=id;app.calendarBlock=B.node(d,id)?.metadata.role==='LONGITUDINAL_BLOCK'?id:null;app.render();};
        const add=(kind,parent)=>{let id;app.mutate(x=>{const out=B.longitudinalAdd(x,kind,parent);id=out.nodes[out.nodes.length-1].id;app.longitudinalSelected=id;app.calendarBlock=kind==='block'?id:null;app.calendarStart=1;return out;});};
        const item=(text,kind)=>B.el('button',{type:'button',class:'sb-palette-item',text,disabled:app.readOnly,draggable:!app.readOnly,onclick:()=>add(kind,selected?.metadata.role==='BLOCK_RANDOMIZATION'?selected.id:null),ondragstart:e=>{e.dataTransfer.setData('application/x-eipsi-longitudinal-element',JSON.stringify({kind}));e.dataTransfer.effectAllowed='copy';}});
        const drop=parent=>e=>{e.preventDefault();e.stopPropagation();e.currentTarget.classList.remove('sb-drag-over');if(app.readOnly)return;let p;try{p=JSON.parse(e.dataTransfer.getData('application/x-eipsi-longitudinal-element'));}catch(_){return;}if(['block','randomization'].includes(p.kind))add(p.kind,parent);};
        const drag=e=>{if(!app.readOnly){e.preventDefault();e.currentTarget.classList.add('sb-drag-over');}};
        const palette=B.el('aside',{class:'sb-palette'},B.el('h3',{text:'Elementos'}),B.note('Arrastrá un bloque longitudinal o una randomización al área de trabajo. También podés añadirlos con un clic.'),item('＋ Randomización','randomization'),item('＋ Bloque longitudinal','block'));
        const canvas=B.el('div',{class:'sb-canvas','aria-label':'Composición longitudinal',ondragover:drag,ondragleave:e=>e.currentTarget.classList.remove('sb-drag-over'),ondrop:drop(null)},B.el('h3',{text:'Estudio longitudinal'}));
        const card=n=>B.el('button',{type:'button',class:'sb-assessment-card'+(selected?.id===n.id?' sb-selected':''),onclick:()=>choose(n.id)},B.el('strong',{text:B.label(n)}),B.el('span',{text:'Bloque longitudinal · '+B.children(d,n.id).length+' interacciones · '+(n.metadata.duration_days||7)+' días'}));
        B.children(d,d.root_block_ids[0]).forEach(n=>{
            if(n.metadata?.role==='LONGITUDINAL_BLOCK')canvas.append(card(n));
            else if(n.metadata?.role==='BLOCK_RANDOMIZATION'){
                const children=B.children(d,n.id),container=B.el('div',{class:'sb-random-container',ondragover:drag,ondragleave:e=>e.currentTarget.classList.remove('sb-drag-over'),ondrop:drop(n.id)},B.el('button',{type:'button',class:'sb-random-heading'+(selected?.id===n.id?' sb-selected':''),text:'⤨ '+B.label(n)+' · '+(n.randomization?.draw_count||'Sin configurar')+' de '+children.length,onclick:()=>choose(n.id)}));
                children.forEach(b=>container.append(card(b)));if(!children.length)container.append(B.note('Soltá bloques aquí o seleccioná los candidatos en Propiedades.'));canvas.append(container);
            }
        });
        if(!blocks.length&&!groups.length)canvas.append(B.el('div',{class:'sb-drop-placeholder',text:'Soltá un bloque longitudinal aquí para configurar su calendario.'}));
        const inspector=B.el('aside',{class:'sb-properties'},B.el('h3',{text:'Propiedades'}));
        if(selected){
            inspector.append(B.field('Nombre',B.label(selected),v=>app.mutate(x=>{B.node(x,selected.id).metadata.label=v;return x;})));
            if(selected.metadata.role==='BLOCK_RANDOMIZATION'){
                inspector.append(B.el('h4',{text:'Selección de bloques'}),B.note('La selección se conserva por participante. Los bloques elegidos mantienen sus calendarios.'),B.blockRandomControls(app,selected.id),B.button('Quitar randomización',()=>app.mutate(x=>{const owner=B.node(x,selected.id);for(const child of B.children(x,owner.id))child.parent_id=owner.parent_id;return B.remove(B.reindex(x),owner.id);}),app.readOnly));
            }else{
                inspector.append(B.el('h4',{text:'Calendario'}),B.field('Duración del bloque (días)',selected.metadata.duration_days||7,v=>{const last=Math.max(1,...B.children(d,selected.id).map(n=>B.calendarSlot(n).day));if(!Number.isInteger(v)||v<last)throw Error('La duración debe cubrir las interacciones programadas (mínimo '+last+' días).');app.mutate(x=>{B.node(x,selected.id).metadata.duration_days=v;return x;});},'number',{min:1,step:1}),B.el('details',{},B.el('summary',{text:'Disponibilidad del bloque'}),B.scheduleEditor(d,selected,s=>app.mutate(x=>{B.node(x,selected.id).schedule=s;return x;}))),B.button('Subir',()=>app.mutate(x=>B.move(x,selected.id,selected.parent_id,Math.max(0,selected.position-1))),app.readOnly),B.button('Bajar',()=>app.mutate(x=>B.move(x,selected.id,selected.parent_id,selected.position+1)),app.readOnly),B.button('Duplicar bloque',()=>app.mutate(x=>B.longitudinalDuplicate(x,selected.id)),app.readOnly),B.button('Eliminar bloque',()=>{if(confirm('Eliminar este bloque y todas sus interacciones del draft?'))app.mutate(x=>B.remove(x,selected.id));},app.readOnly));
            }
        }else inspector.append(B.note('Seleccioná un elemento para configurar sus propiedades.'));
        B.organizeProperties(inspector);
        if(app.readOnly)inspector.querySelectorAll('input,select,button').forEach(c=>c.disabled=true);
        const workspace=B.el('div',{class:'sb-workspace'},palette,canvas,inspector),box=B.el('section',{class:'sb-longitudinal-workspace'},workspace);
        if(selected?.metadata.role==='LONGITUDINAL_BLOCK')box.append(B.calendarEditor(app,d,selected));return box;
    };
})(window.EIPSIBuilder);
