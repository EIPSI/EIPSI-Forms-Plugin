(function(B){
    'use strict';
    B.cleanLongitudinalBlocks=d=>{
        if(d.state!=='DRAFT'||d.protocol_metadata.study_type!=='LONGITUDINAL')return d;
        const ordered=v=>Array.isArray(v)?v.map(ordered):v&&typeof v==='object'?Object.fromEntries(Object.keys(v).sort().map(k=>[k,ordered(v[k])])):v;
        for(const n of d.nodes)if(n.metadata?.role==='LONGITUDINAL_BLOCK'){
            const schedule=B.schedule('EXECUTION_CREATED');schedule.timezone_policy=d.timezone.policy;
            if(JSON.stringify(ordered(n.schedule))!==JSON.stringify(ordered(schedule)))n.schedule=schedule;
            delete n.metadata.duration_days;
        }return d;
    };
    B.longitudinalAdd=(d,kind,parent)=>{
        const group=B.node(d,parent);if(group?.metadata.role!=='BLOCK_RANDOMIZATION')parent=d.root_block_ids[0];
        if(kind==='randomization')parent=d.root_block_ids[0];
        let x=B.add(d,parent,'MACRO'),n=x.nodes[x.nodes.length-1];
        n.metadata={label:window.EIPSIContent.get('authoring',window.EIPSIContent.language(d))[kind==='randomization'?'block_selection':'new_block'],role:kind==='randomization'?'BLOCK_RANDOMIZATION':'LONGITUDINAL_BLOCK',independent_children:true};
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
        const palette=B.el('aside',{class:'sb-palette'},B.el('h3',{text:window.EIPSIAdminI18n.t("Elements")}),B.note(window.EIPSIAdminI18n.t("Drag a longitudinal block or randomization into the workspace. You can also click to add one.")),item(window.EIPSIAdminI18n.t("＋ Randomization"),'randomization'),item(window.EIPSIAdminI18n.t("\uff0b Longitudinal block"),'block'));
        const canvas=B.el('div',{class:'sb-canvas','aria-label':window.EIPSIAdminI18n.t("Longitudinal composition"),ondragover:drag,ondragleave:e=>e.currentTarget.classList.remove('sb-drag-over'),ondrop:drop(null)},B.el('h3',{text:window.EIPSIAdminI18n.t("Longitudinal study")}));
        const card=n=>B.el('button',{type:'button',class:'sb-assessment-card'+(selected?.id===n.id?' sb-selected':''),onclick:()=>choose(n.id)},B.el('strong',{text:B.label(n)}),B.el('span',{text:window.EIPSIAdminI18n.n('%d interaction',B.children(d,n.id).length)}));
        B.children(d,d.root_block_ids[0]).forEach(n=>{
            if(n.metadata?.role==='LONGITUDINAL_BLOCK')canvas.append(card(n));
            else if(n.metadata?.role==='BLOCK_RANDOMIZATION'){
                const children=B.children(d,n.id),container=B.el('div',{class:'sb-random-container',ondragover:drag,ondragleave:e=>e.currentTarget.classList.remove('sb-drag-over'),ondrop:drop(n.id)},B.el('button',{type:'button',class:'sb-random-heading'+(selected?.id===n.id?' sb-selected':''),text:window.EIPSIAdminI18n.f('⤨ %1$s · %2$s of %3$d',B.label(n),n.randomization?.draw_count||window.EIPSIAdminI18n.t('Not configured'),children.length),onclick:()=>choose(n.id)}));
                children.forEach(b=>container.append(card(b)));if(!children.length)container.append(B.note(window.EIPSIAdminI18n.t("Drop blocks here or select candidates in Properties.")));canvas.append(container);
            }
        });
        if(!blocks.length&&!groups.length)canvas.append(B.el('div',{class:'sb-drop-placeholder',text:window.EIPSIAdminI18n.t("Drop a longitudinal block here to configure its calendar.")}));
        const inspector=B.el('aside',{class:'sb-properties'},B.el('h3',{text:window.EIPSIAdminI18n.t("Properties")}));
        if(selected){
            inspector.append(B.field(window.EIPSIAdminI18n.t("Name"),B.label(selected),v=>app.mutate(x=>{B.node(x,selected.id).metadata.label=v;return x;})));
            if(selected.metadata.role==='BLOCK_RANDOMIZATION'){
                inspector.append(B.el('h4',{text:window.EIPSIAdminI18n.t("Block selection")}),B.note(window.EIPSIAdminI18n.t("Selection is retained per participant. Selected blocks keep their calendars.")),B.blockRandomControls(app,selected.id),B.button(window.EIPSIAdminI18n.t("Remove randomization"),()=>app.mutate(x=>{const owner=B.node(x,selected.id);for(const child of B.children(x,owner.id))child.parent_id=owner.parent_id;return B.remove(B.reindex(x),owner.id);}),app.readOnly));
            }else{
                inspector.append(B.button(window.EIPSIAdminI18n.t("Move up"),()=>app.mutate(x=>B.move(x,selected.id,selected.parent_id,Math.max(0,selected.position-1))),app.readOnly),B.button(window.EIPSIAdminI18n.t("Move down"),()=>app.mutate(x=>B.move(x,selected.id,selected.parent_id,selected.position+1)),app.readOnly),B.button(window.EIPSIAdminI18n.t("Duplicate block"),()=>app.mutate(x=>B.longitudinalDuplicate(x,selected.id)),app.readOnly),B.button(window.EIPSIAdminI18n.t("Delete block"),()=>{if(confirm(window.EIPSIAdminI18n.t("Delete this block and all its interactions from the draft?")))app.mutate(x=>B.remove(x,selected.id));},app.readOnly));
            }
        }else inspector.append(B.note(window.EIPSIAdminI18n.t("Select an element to configure its properties.")));
        B.organizeProperties(inspector);
        if(app.readOnly)inspector.querySelectorAll('input,select,button').forEach(c=>c.disabled=true);
        const workspace=B.el('div',{class:'sb-workspace'},palette,canvas,inspector),box=B.el('section',{class:'sb-longitudinal-workspace'},workspace);
        if(selected?.metadata.role==='LONGITUDINAL_BLOCK')box.append(B.calendarEditor(app,d,selected));return box;
    };
})(window.EIPSIBuilder);
