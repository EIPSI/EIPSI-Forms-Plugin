(function(B){
  'use strict';
  const rules=B.logicalRules;
  const blockColor=id=>id?[...id].reduce((sum,c)=>sum+c.charCodeAt(0),0)%8:0;
  const duration=d=>d?.seconds??(d?.amount||0)*({MINUTE:60,HOUR:3600,DAY:86400,WEEK:604800}[d?.unit]||86400);
  // Definition possibilities only. This never draws a participant's random outcome.
  B.timelineCells=(d,choices={})=>{
    const cells=[];
    const walk=(id,context=null,inherited=0)=>{
      const n=B.node(d,id);if(!n)return;
      if(n.metadata?.role==='LONGITUDINAL_BLOCK')context=id;
      const t=n.schedule?.temporal_rule;let at=inherited;
      if(t?.kind==='LOCAL_DAY_TIME')at=(t.day_index-1)*86400+Number(t.time.slice(0,2))*3600+Number(t.time.slice(3,5))*60;
      else if(t?.duration)at=inherited+duration(t.duration);
      if(n.randomization&&!choices[id]){cells.push({kind:'random',id,context,at});return;}
      if(n.completion_boundary==='ASSESSMENT'){cells.push({kind:'assessment',id,context,at});return;}
      (choices[id]||B.children(d,id).map(n=>n.id)).forEach(child=>walk(child,context,at));
    };d.root_block_ids.forEach(id=>walk(id));return cells;
  };
  const selectedQuestions=(app,id,native)=>B.nativeQuestionOrder(B.logicalQuestions(app,B.node(app.draft,id)),native,id).filter(q=>(q.randomization_path||[]).every(p=>!native[id+'|'+p.randomization_id]||native[id+'|'+p.randomization_id].includes(p.candidate_id)));
  B.nativeOutcomes=(r,offset=0)=>{
    const always=r.mode==='all'?[]:r.always_show||[],pool=r.candidates.filter(id=>!always.includes(id)),fixed=r.fixed||[],seen=new Set(),outcomes=[];let visited=0,more=false;
    function* choose(ids,n,start=0,prefix=[]){if(prefix.length===n){yield prefix;return;}for(let i=start;i<ids.length;i++)yield* choose(ids,n,i+1,[...prefix,ids[i]]);}
    function* permute(ids,prefix=[]){if(!ids.length){yield prefix;return;}for(let i=0;i<ids.length;i++)yield* permute(ids.filter((_,j)=>i!==j),[...prefix,ids[i]]);}
    const selections=r.mode==='all'?[r.candidates]:choose(pool,r.count);
    outer:for(const selection of selections){const visible=r.candidates.filter(id=>selection.includes(id)||always.includes(id)),pins=visible.filter(id=>fixed.includes(id)),movable=visible.filter(id=>!fixed.includes(id));for(const drawn of permute(movable)){const order=Array(visible.length).fill(null);let previous=-1;for(let i=0;i<pins.length;i++){const slot=Math.max(previous+1,Math.min(r.candidates.indexOf(pins[i]),visible.length-pins.length+i));order[slot]=pins[i];previous=slot;}let j=0;for(let i=0;i<order.length;i++)if(order[i]===null)order[i]=drawn[j++];const key=order.join('|');if(seen.has(key))continue;seen.add(key);if(visited++<offset)continue;if(outcomes.length===8){more=true;break outer;}outcomes.push(order);}}
    return {outcomes,more};
  };
  function possibilities(app,limit){
    const out=[],randoms=B.logicalRandoms(app);let more=false;
    const visit=(choices,native)=>{
      if(out.length>=limit){more=true;return;}
      const cells=B.timelineCells(app.draft,choices),pending=cells.find(c=>c.kind==='random');
      let random=pending&&randoms.find(r=>r.key===pending.id);
      if(!random)for(const cell of cells){for(const q of selectedQuestions(app,cell.id,native)){const path=(q.randomization_path||[]).find(p=>!native[cell.id+'|'+p.randomization_id]);if(path){random=randoms.find(r=>r.key===cell.id+'|'+path.randomization_id);break;}}if(random)break;}
      if(!random){out.push({choices,nativeChoices:native,cells});return;}
      let offset=0;
      do{const batch=random.randomization_id?B.nativeOutcomes(random.config,offset):B.randomOutcomes(random.config,offset,8);
        for(const outcome of batch.outcomes){visit(random.randomization_id?choices:{...choices,[random.key]:outcome},random.randomization_id?{...native,[random.key]:outcome}:native);if(more)return;}
        if(!batch.more)break;offset+=8;
      }while(!more);
    };visit({},{});return {paths:out,more};
  }
  function predicateValue(app,p,path){
    if(p.op==='NOT'){const v=predicateValue(app,p.arg,path);return v===null?null:!v;}
    if(p.args){const values=p.args.map(x=>predicateValue(app,x,path));return p.op==='AND'?(values.includes(false)?false:values.includes(null)?null:true):(values.includes(true)?true:values.includes(null)?null:false);}
    const r=p.reference;let value;
    if(r.kind==='RANDOM_SELECTED'||r.kind==='RANDOM_RESOLVED'){const selected=(r.assessment_id?path.nativeChoices:path.choices)[r.assessment_id?r.assessment_id+'|'+r.randomization_id:r.randomization_id];if(!selected)return null;value=r.kind==='RANDOM_RESOLVED'||selected.includes(r.candidate_id);}
    else if(r.kind==='QUESTION_SELECTED'){const assessment=B.node(app.draft,r.assessment_id);if(!assessment)return null;value=path.cells.some(c=>c.id===r.assessment_id)&&selectedQuestions(app,r.assessment_id,path.nativeChoices).some(q=>q.id===r.question_id);}
    else return null;
    if(p.op==='EQ')return value===p.value;if(p.op==='NEQ')return value!==p.value;if(p.op==='IN')return p.value.includes(value);return null;
  }
  function tokens(app,path){
    const list=[];for(const c of path.cells){const n=B.node(app.draft,c.id);list.push({...c,kind:'header',key:'H|'+c.id,label:B.label(n)});for(const q of selectedQuestions(app,c.id,path.nativeChoices))list.push({...c,kind:'question',key:'Q|'+c.id+'|'+q.id,question:q,label:q.label||q.id});list.push({...c,kind:'end',key:'E|'+c.id});}return list;
  }
  function applyRule(app,path,sequence,r){
    if(r.action_scope==='TRACK_FUTURE_V1'){const scope=B.logicalPathScope(app,{...path,sequence},{...r.source,event:r.event});if(!scope)return null;r={...r,actions:r.actions.filter(action=>scope.future(action))};if(!r.actions.length)return null;}
    const source=sequence.findIndex(t=>t.id===r.source.assessment_id&&(r.source.question_id?t.question?.id===r.source.question_id:t.kind==='end'));
    if(source<0)return null;
    const marker={...sequence[source],kind:'condition',question:null,key:'C|'+r.id,label:r.label,condition_id:r.id};
    let result=[...sequence.slice(0,source+1),marker,...sequence.slice(source+1)];
    const visualSequence=result=>result.flatMap(t=>{
      if(t.kind!=='cancel')return [t];
      const action=r.actions.find(a=>a.type==='CANCEL_BLOCK'&&t.key==='X|'+r.id+'|'+a.target_id);
      if(!action)return [t];
      const ids=new Set([action.target_id,...B.descendants(app.draft,action.target_id)]);
      return [t,...sequence.slice(source+1).filter(token=>ids.has(token.id)).map(token=>({...token,inactive:true,key:'INACTIVE|'+r.id+'|'+token.key}))];
    });
    const finish=viability=>{
      const visual=viability.inactive?[...sequence.slice(0,source+1),marker,{...marker,kind:'truncate',key:'X|INCOMPATIBLE|'+r.id,label:window.EIPSIAdminI18n.t("Truncated by condition"),reason:viability.reason},...sequence.slice(source+1).map(t=>({...t,inactive:true,key:'INACTIVE|'+r.id+'|'+t.key}))]:visualSequence(result);
      return {...path,...viability,sequence:result,visual_sequence:visual};
    };
    for(const action of r.actions){
      if(action.type==='CANCEL_BLOCK'){const ids=new Set([action.target_id,...B.descendants(app.draft,action.target_id)]);result=result.filter((t,i)=>i<=source+1||!ids.has(t.id));result.splice(source+2,0,{...marker,kind:'cancel',key:'X|'+r.id+'|'+action.target_id,label:window.EIPSIAdminI18n.t("Cancelled element: ")+B.label(B.node(app.draft,action.target_id))});}
      if(action.type==='END_ASSESSMENT')result=result.filter((t,i)=>i<=source+1||t.id!==r.source.assessment_id);
      if(['GO_QUESTION','JUMP','WAIT'].includes(action.type)){
        const target=sequence.findIndex(t=>t.id===action.target_id&&(action.question_id?t.question?.id===action.question_id:t.kind==='header'));
        if(target<0)return finish({inactive:true,reason:window.EIPSIAdminI18n.t("The destination is outside this randomized possibility")});
        const tail=sequence.slice(target);if(action.question_id)tail.unshift({...sequence[target],kind:'header',question:null,key:'ENTRY|'+r.id,label:B.label(B.node(app.draft,action.target_id))+window.EIPSIAdminI18n.t(" \u00b7 from ")+sequence[target].label});
        const from=sequence[source].at,targetAt=sequence[target].at;
        if(action.type==='WAIT')result=[...result.slice(0,source+2),{...marker,kind:'wait',key:'W|'+r.id,label:window.EIPSIAdminI18n.t("Wait until ")+B.label(B.node(app.draft,action.target_id)),until:targetAt,at:from},...tail];
        else{const delta=action.type==='JUMP'?Math.max(0,targetAt-from):0;result=[...result.slice(0,source+2),...tail.map(t=>({...t,at:Math.max(from,t.at-delta)}))];}
      }
    }
    // Structural actions survive later flow actions in the same ordered transition.
    for(const action of r.actions.filter(a=>a.type==='CANCEL_BLOCK')){const ids=new Set([action.target_id,...B.descendants(app.draft,action.target_id)]);result=result.filter((t,i)=>i<=source+1||!ids.has(t.id));if(!result.some(t=>t.kind==='cancel'&&t.key==='X|'+r.id+'|'+action.target_id))result.push({...marker,kind:'cancel',key:'X|'+r.id+'|'+action.target_id,label:window.EIPSIAdminI18n.t("Cancelled element: ")+B.label(B.node(app.draft,action.target_id))});}
    const viability=B.trajectoryViability(app,{...path,actions:r.actions});
    return finish(viability);
  }
  B.trajectoryTracks=(app,limit=64)=>{
    const expanded=possibilities(app,limit),tracks=[];
    // All random paths are constructed first; conditions add their own distinct suffixes.
    expanded.paths.forEach(path=>{
      const sequence=tokens(app,path),description=Object.entries({...path.choices,...path.nativeChoices}).map(([key,outcome])=>{const r=B.logicalRandoms(app).find(r=>r.key===key);return outcome.map(id=>r?.candidates.find(c=>c.id===id)?.label||id).join(' → ');}).join(' / ');
      const zero=Object.entries(path.choices).some(([key,ids])=>ids.some(id=>B.node(app.draft,key).randomization.weights?.[id]===0));const base={...path,id:'PATH_'+JSON.stringify([Object.entries(path.choices).sort(),Object.entries(path.nativeChoices).sort()]),label:description||window.EIPSIAdminI18n.t("Journey without randomization"),sequence,actions:[],inactive:zero,reason:zero?window.EIPSIAdminI18n.t("Zero-weight candidate"):null};tracks.push(base);
      for(const r of [...rules(app.draft)].sort((a,b)=>a.priority-b.priority||a.id.localeCompare(b.id))){
        if(predicateValue(app,r.predicate,path)===false)continue;
        const branch=applyRule(app,path,sequence,r);if(branch)tracks.push({...branch,inactive:base.inactive||branch.inactive,reason:base.reason||branch.reason,id:base.id+'|'+r.id,parent:base.id,condition_id:r.id,actions:r.actions,label:r.label+window.EIPSIAdminI18n.t(" · true condition")});
      }
    });tracks.sort((a,b)=>{const left=a.visual_sequence||a.sequence,right=b.visual_sequence||b.sequence;for(let i=0;i<Math.min(left.length,right.length);i++){const diff=left[i].key.localeCompare(right[i].key);if(diff)return diff;}return left.length-right.length||a.id.localeCompare(b.id);});return {tracks,more:expanded.more,randomPaths:expanded.paths.length};
  };
  // Prefix trie: only ancestors are shared. Equal future tokens after a fork never merge.
  B.trajectoryPrefix=tracks=>{
    const root={children:new Map(),rows:[],depth:0};
    tracks.forEach((track,row)=>{let node=root;node.rows.push(row);const sequence=track.visual_sequence||track.sequence;for(const token of sequence){let child=node.children.get(token.key);if(!child){child={token,children:new Map(),rows:[],parent:node,depth:node.depth+1};node.children.set(token.key,child);}child.rows.push(row);node=child;}});return root;
  };
  B.logicalPathScope=(app,track,context)=>{
    const ancestors=id=>{const result=[];let n=B.node(app.draft,id);while(n){result.push(n.id);n=B.node(app.draft,n.parent_id);}return result;};
    const sequence=[...track.sequence].sort((a,b)=>(a.at||0)-(b.at||0));
    let point=sequence.findIndex(t=>t.id===context.assessment_id&&(context.event==='COMPLETE'?t.kind==='end':context.question_id?t.question?.id===context.question_id:t.kind==='header'));
    if(point<0&&context.event==='COMPLETE')point=sequence.findLastIndex(t=>t.id===context.assessment_id&&!['condition','cancel','truncate','wait'].includes(t.kind));
    if(point<0)return null;
    const prefix=sequence.slice(0,point+1),suffix=sequence.slice(point+1),blocks=new Set(prefix.flatMap(t=>ancestors(t.id))),questions=new Set(prefix.filter(t=>t.question).map(t=>t.id+'|'+t.question.id)),randoms=new Set(),futureRandoms=new Set();
    for(const t of prefix)for(const entry of t.question?.randomization_path||[])randoms.add(t.id+'|'+entry.randomization_id);
    for(const t of suffix)for(const entry of t.question?.randomization_path||[])futureRandoms.add(t.id+'|'+entry.randomization_id);
    const futureBlocks=new Set(suffix.filter(t=>t.id!==context.assessment_id).flatMap(t=>ancestors(t.id))),futureAssessments=new Set(suffix.filter(t=>t.kind==='header'&&t.id!==context.assessment_id).map(t=>t.id)),futureQuestions=new Set(suffix.filter(t=>t.question).map(t=>t.id+'|'+t.question.id));
    const allows=ref=>ref.randomization_id?(ref.assessment_id?randoms.has(ref.assessment_id+'|'+ref.randomization_id):blocks.has(ref.randomization_id)):ref.question_id?questions.has(ref.assessment_id+'|'+ref.question_id):blocks.has(ref.node_id||ref.assessment_id);
    const future=action=>{
      if(action.type==='END_ASSESSMENT')return true;
      if(action.type==='GO_QUESTION')return action.target_id===context.assessment_id&&futureQuestions.has(action.target_id+'|'+action.question_id);
      if(['JUMP','WAIT'].includes(action.type))return futureAssessments.has(action.target_id)&&(!action.question_id||futureQuestions.has(action.target_id+'|'+action.question_id));
      if(action.type==='CANCEL_BLOCK')return action.target_id!==context.assessment_id&&futureBlocks.has(action.target_id);
      if(action.randomization_id&&action.target_id===context.assessment_id){const source=B.logicalQuestions(app,B.node(app.draft,context.assessment_id)).find(q=>q.id===context.question_id),random=B.logicalRandoms(app).find(r=>r.key===action.target_id+'|'+action.randomization_id);if(!source||!random||source.page>=random.config.page)return false;}
      const key=action.target_id+'|'+action.randomization_id;
      return action.randomization_id?futureRandoms.has(key)&&!randoms.has(key):futureBlocks.has(action.target_id)&&!blocks.has(action.target_id);
    };
    return {track,allows,blocks,future};
  };
  B.logicalPastScope=(app,context)=>{
    const model=B.trajectoryTracks(app,app.trajectoryState?.limit||64),roots=new Set(app.draft.root_block_ids);
    const paths=model.tracks.filter(t=>!t.inactive&&(!context.track_ids||context.track_ids.includes(t.id))).map(track=>B.logicalPathScope(app,track,context)).filter(Boolean);
    return {paths,more:model.more,allows:ref=>paths.some(p=>p.allows(ref)),count:ref=>paths.filter(p=>p.allows(ref)).length,future:action=>paths.some(p=>p.future(action)),futureCount:action=>paths.filter(p=>p.future(action)).length,blocks:app.draft.nodes.filter(n=>n.kind==='BLOCK'&&!roots.has(n.id)&&paths.some(p=>p.blocks.has(n.id)))};
  };
  B.fitTrajectoryContents=(card,contents,stash)=>{
    if(!card.classList.contains('is-compact'))return;
    const body=card.querySelector('.sb-daw-floating-content');body.append(contents);card.classList.add('is-measuring-content');card.classList.remove('has-inline-content');
    const style=window.getComputedStyle(card),available=card.clientHeight-(parseFloat(style.paddingTop)||0)-(parseFloat(style.paddingBottom)||0);
    const fits=available>0&&body.scrollHeight>0&&body.scrollHeight<=available&&body.scrollWidth<=body.clientWidth;
    card.classList.remove('is-measuring-content');card.classList.toggle('has-inline-content',fits);
    if(!fits)stash.append(contents);return fits;
  };
  B.trajectoryWorkspace=app=>{
    const state=app.trajectoryState||(app.trajectoryState={}),d=app.draft;
    state.limit??=64;state.zoom=0.65;state.expanded??={};state.hide??=false;
    const longitudinal=B.studyType(d)==='LONGITUDINAL';
    if(longitudinal&&!d.nodes.some(n=>n.kind==='BLOCK'&&n.completion_boundary==='ASSESSMENT'))return B.el('section',{class:'sb-trajectory-workspace sb-daw-workspace'});
    const unconfigured=d.nodes.filter(n=>n.metadata?.role==='BLOCK_RANDOMIZATION'&&!n.randomization&&B.children(d,n.id).length);
    if(unconfigured.length){const warning=B.el('section',{class:'sb-trajectory-workspace'},B.note(window.EIPSIAdminI18n.t("Some block randomizations have no selection configured. Configure them to represent mutually exclusive paths and save the same behavior for participants.")));unconfigured.forEach(n=>warning.append(B.button(window.EIPSIAdminI18n.t("Configure ")+B.label(n),()=>B.blockRandomDialog(app,n.id),app.readOnly)));return warning;}
    const box=B.el('section',{class:'sb-trajectory-workspace sb-daw-workspace'}),refresh=()=>app.render(),model=B.trajectoryTracks(app,state.limit),tracks=model.tracks.filter(t=>!state.hide||!t.inactive);
    const visible=tracks,truncated=model.tracks.filter(t=>(t.visual_sequence||t.sequence).some(token=>token.kind==='truncate')).length,real=model.tracks.filter(t=>!t.inactive).length;
    box.append(B.el('div',{class:'sb-daw-heading'},B.el('div',{},B.el('h3',{text:window.EIPSIAdminI18n.t("Logical conditions · Paths")}),B.note(window.EIPSIAdminI18n.t("Each row is a possible journey. Shared history is drawn once; after branching, tracks never merge again."))),B.el('strong',{text:[window.EIPSIAdminI18n.n('%d randomization path',model.randomPaths),window.EIPSIAdminI18n.n('%d path',model.tracks.length),window.EIPSIAdminI18n.n('%d truncated path',truncated),window.EIPSIAdminI18n.n('%d real path',real)].join(' · ')+(model.more?window.EIPSIAdminI18n.t(' (partial view)'):'')})));
    const legend=B.el('div',{class:'sb-daw-legend','aria-label':window.EIPSIAdminI18n.t("Color legend")},B.el('strong',{text:window.EIPSIAdminI18n.t("Colors:")}));
    const roots=new Set(d.root_block_ids),contexts=[...new Set(model.tracks.flatMap(t=>t.cells.map(c=>c.context)))].filter(id=>id&&!roots.has(id));
    contexts.forEach(id=>legend.append(B.el('span',{class:'sb-daw-legend-block sb-block-color-'+blockColor(id)},B.el('i',{'aria-hidden':'true'}),B.el('span',{text:id?B.label(B.node(d,id)):window.EIPSIAdminI18n.t("Study")}))));
    [['condition',window.EIPSIAdminI18n.t("Condition")],['inactive',window.EIPSIAdminI18n.t("Inactive / waiting")],['truncated',window.EIPSIAdminI18n.t("Truncated")]].forEach(([kind,label])=>legend.append(B.el('span',{class:'sb-daw-legend-state sb-legend-'+kind},B.el('i',{'aria-hidden':'true'}),B.el('span',{text:label}))));box.append(legend);
    box.append(B.el('div',{class:'sb-toolbar'},B.check(window.EIPSIAdminI18n.t("Hide inactive paths"),state.hide,v=>{state.hide=v;refresh();}),B.button(window.EIPSIAdminI18n.t("Collapse all rows"),()=>{state.expanded={};refresh();}),...(model.more?[B.button(window.EIPSIAdminI18n.t("Show more possible paths"),()=>{state.limit+=64;refresh();})]:[])));
    const scroll=B.el('div',{class:'sb-daw-scroll'}),canvas=B.el('div',{class:'sb-daw-canvas'}),tree=B.trajectoryPrefix(visible),labelWidth=190,blockWidth=240,gap=24,segments=[],rowTop=[],rowSize=[];let maxX=0,totalHeight=46;
    visible.forEach((track,row)=>{const expanded=!!state.expanded[track.id];rowTop[row]=totalHeight;rowSize[row]=expanded?224:32;totalHeight+=rowSize[row];
      const label=B.el('div',{class:'sb-daw-label',onclick:()=>{if(!expanded){state.expanded[track.id]=true;refresh();}}}),toggle=B.el('button',{type:'button',class:'sb-daw-row-toggle','aria-expanded':String(expanded),'aria-label':window.EIPSIAdminI18n.f("%1$s track %2$d",expanded?window.EIPSIAdminI18n.t('Collapse'):window.EIPSIAdminI18n.t('Expand'),row+1),text:(expanded?'▾ ':'▸ ')+window.EIPSIAdminI18n.f("Track %d",row+1),onclick:e=>{e.stopPropagation();state.expanded[track.id]=!expanded;refresh();}});label.append(toggle);
      if(expanded){const name=d.protocol_metadata.trajectory_track_names?.[track.id]||track.label;
        if(state.editingName===track.id){const input=B.el('input',{class:'sb-daw-name-input',value:name,maxlength:120,'aria-label':window.EIPSIAdminI18n.t("Track name"),onclick:e=>e.stopPropagation()});const save=()=>{const value=input.value.trim();state.editingName=null;app.mutate(x=>{x.protocol_metadata.trajectory_track_names={...x.protocol_metadata.trajectory_track_names};if(value)x.protocol_metadata.trajectory_track_names[track.id]=value;else delete x.protocol_metadata.trajectory_track_names[track.id];return x;});};input.addEventListener('change',save);input.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();save();}if(e.key==='Escape'){state.editingName=null;refresh();}});label.append(input);}
        else label.append(B.el('button',{type:'button',class:'sb-daw-track-name',text:name,title:window.EIPSIAdminI18n.t("Edit track name"),disabled:app.readOnly,onclick:e=>{e.stopPropagation();state.editingName=track.id;refresh();}}));
        if(track.inactive)label.append(B.el('span',{text:track.reason||'Inactiva'}));
      }
      canvas.append(B.el('div',{class:'sb-daw-lane'+(expanded?' is-expanded':' is-compact')+(track.inactive?' is-inactive':''),'data-track':track.id,style:'top:'+rowTop[row]+'px;height:'+rowSize[row]+'px'},label));
    });
    const openConditions=selection=>{app.logicalSelection=selection;state.conditionModal=true;refresh();};
    const walk=(node,previous=null)=>{
      for(const child of node.children.values()){
        if(child.token.kind==='end'){walk(child,previous);continue;}let end=child,group=[child.token];
        if(['header','question'].includes(child.token.kind))while(end.children.size===1){const next=[...end.children.values()][0];if(!['question','end'].includes(next.token.kind)||next.token.id!==child.token.id||next.rows.length!==child.rows.length)break;group.push(next.token);end=next;}
        const x=0,width=blockWidth;
        const segment={node:child,end,group,x,width,previous};segments.push(segment);maxX=Math.max(maxX,x+width);walk(end,segment);
      }
    };walk(tree);
    // One global time axis: reserve enough uniform slots for every branch at each instant.
    const temporalLabel=at=>at?window.EIPSIAdminI18n.f("Day %1$d · +%2$s h",(Math.floor(at/86400)+1),(at/3600).toFixed(1)):window.EIPSIAdminI18n.t("Start");
    const periods=new Map();
    for(const segment of segments){
      const at=segment.group[0].at||0,counts=new Map(segment.previous?.timeSlots||[]),slot=counts.get(at)||0;
      counts.set(at,slot+1);segment.timeSlots=counts;segment.slot=slot;
      const period=periods.get(at)||{at,slots:0,x:0};period.slots=Math.max(period.slots,slot+1);periods.set(at,period);
    }
    let cursor=0;const ranges=[...periods.values()].sort((a,b)=>a.at-b.at);
    ranges.forEach(period=>{period.x=cursor;cursor+=period.slots*(blockWidth+gap);});
    segments.forEach(segment=>{segment.x=periods.get(segment.group[0].at||0).x+segment.slot*(blockWidth+gap);});
    maxX=Math.max(0,cursor-gap);
    const ruler=B.el('div',{class:'sb-daw-ruler',style:'width:'+(labelWidth+maxX+100)+'px'},B.el('strong',{text:window.EIPSIAdminI18n.t("TIME →")}));
    ranges.forEach((period,i)=>ruler.append(B.el('span',{class:'sb-daw-time-range',text:temporalLabel(period.at),title:temporalLabel(period.at),style:'left:'+(labelWidth+period.x)+'px;width:'+((ranges[i+1]?.x??maxX+80)-period.x)+'px'})));
    const rulers=[B.el('div',{class:'sb-daw-time-group',style:'top:0;height:'+totalHeight+'px;width:'+(labelWidth+maxX+100)+'px'},ruler)];
    const compactContents=[];
    [...segments].sort((a,b)=>a.x-b.x).forEach(segment=>{const t=segment.group[0],first=segment.node.rows[0],last=segment.node.rows.at(-1),shared=segment.node.rows.length>1,opened=segment.node.rows.filter(row=>state.expanded[visible[row].id]),expanded=opened.length>0,top=rowTop[first]+(expanded?8:6),height=rowTop[last]+rowSize[last]-top-(expanded?8:2);
      const color=blockColor(t.context),card=B.el('article',{class:'sb-daw-clip '+(expanded?'is-expanded ':'is-compact ')+'sb-block-color-'+color+(shared?' is-shared':'')+((t.inactive||segment.node.rows.every(row=>visible[row].inactive))?' is-inactive':'')+(['wait','cancel','condition','truncate'].includes(t.kind)?' is-'+t.kind:''),'data-element':t.id,'data-shared-rows':segment.node.rows.length,style:'left:'+(labelWidth+segment.x)+'px;top:'+top+'px;width:'+segment.width+'px;height:'+height+'px;--sb-compact-lines:'+Math.max(1,Math.floor((height-7)/17))+';--sb-compact-title-height:'+Math.max(17,Math.floor((height-7)/17)*17)+'px'});

      const title=['header','question'].includes(t.kind)?(t.kind==='question'?B.label(B.node(d,t.id))+window.EIPSIAdminI18n.t(" · continuation"):t.label):(t.label||window.EIPSIAdminI18n.t("Assessment end"));
      const floating=B.el('div',{class:'sb-daw-floating-label',style:'margin-top:'+(expanded?Math.max(4,rowTop[opened[0]]-top+8):0)+'px'},B.el('button',{type:'button',class:'sb-daw-assessment-title',text:title,title:t.reason?title+' — '+t.reason+' — '+(rules(d).find(r=>r.id===t.condition_id)?.label||''):title,onclick:()=>{if(['header','question'].includes(t.kind))openConditions({assessment_id:t.id,track_ids:segment.node.rows.map(row=>visible[row].id)});else if(['condition','cancel','wait','truncate'].includes(t.kind)){const r=rules(d).find(r=>r.id===t.condition_id);if(r)openConditions(r.source);}}}));const body=B.el('div',{class:'sb-daw-floating-content',style:floating.getAttribute('style')});floating.removeAttribute('style');body.append(floating);card.append(body);
      const contents=B.el('div',{class:'sb-daw-clip-content'});
      if(['header','question'].includes(t.kind)){
        const n=B.node(d,t.id);if(t.context&&!roots.has(t.context))contents.append(B.el('small',{class:'sb-daw-block-name',text:B.label(B.node(d,t.context))}));
        const questions=segment.group.filter(t=>t.kind==='question'),all=selectedQuestions(app,t.id,visible[opened[0]??first].nativeChoices),cards=B.el('div',{class:'sb-daw-question-strip',tabindex:'0','aria-label':window.EIPSIAdminI18n.f("Questions in %s",B.label(n))});
        const questionGrid=B.el('div',{class:'sb-daw-question-grid'});cards.append(questionGrid);
        questions.forEach(q=>{const number=all.findIndex(item=>item.id===q.question.id)+1,variable=q.question.variable_name||q.question.response_key||q.question.id;questionGrid.append(B.el('button',{type:'button',class:'sb-question-card','data-question':q.question.id,title:number+' · '+variable+' — '+q.label,'aria-label':window.EIPSIAdminI18n.t("Question ")+number+': '+variable+' — '+q.label,onclick:()=>{openConditions({assessment_id:q.id,question_id:q.question.id,track_ids:segment.node.rows.map(row=>visible[row].id)});}},B.el('span',{class:'sb-question-number',text:String(number)}),B.el('span',{class:'sb-question-variable',text:variable})));});contents.append(cards);
      }else if(t.kind==='condition')contents.append(B.el('small',{text:window.EIPSIAdminI18n.t("BRANCH · TRUE CONDITION")}),B.el('button',{type:'button',text:t.label,onclick:()=>{const r=rules(d).find(r=>r.id===t.condition_id);openConditions(r.source);}}));
      else if(['cancel','truncate'].includes(t.kind)){const rule=rules(d).find(r=>r.id===t.condition_id);contents.append(B.el('small',{text:window.EIPSIAdminI18n.f("Condition: %s",(rule?.label||t.condition_id))}),B.el('span',{text:t.kind==='truncate'?t.reason:window.EIPSIAdminI18n.t("This block is cancelled; the journey may continue through other blocks.")}));}else contents.append(B.el('strong',{text:t.label||window.EIPSIAdminI18n.t("Assessment end")}));
      if(shared)contents.append(B.el('small',{class:'sb-daw-shared-label',text:window.EIPSIAdminI18n.n('Shared past · %d track',segment.node.rows.length)}));if(expanded)body.append(contents);else{const stash=document.createDocumentFragment();stash.append(contents);compactContents.push({card,contents,stash});}if(t.kind==='truncate')card.classList.add('is-truncated');canvas.append(card);

    });
    const links=document.createElementNS('http://www.w3.org/2000/svg','svg');links.setAttribute('class','sb-daw-connections');links.setAttribute('width',String(labelWidth+maxX+100));links.setAttribute('height',String(totalHeight));links.setAttribute('aria-hidden','true');
    const byNode=new Map(segments.map(s=>[s.node,s])),nextClips=node=>[...node.children.values()].flatMap(child=>byNode.has(child)?[byNode.get(child)]:nextClips(child));
    for(const source of segments)for(const destination of nextClips(source.end)){const x1=labelWidth+source.x+source.width,x2=labelWidth+destination.x,y1=(rowTop[source.node.rows[0]]+rowTop[source.node.rows.at(-1)]+rowSize[source.node.rows.at(-1)])/2,y2=(rowTop[destination.node.rows[0]]+rowTop[destination.node.rows.at(-1)]+rowSize[destination.node.rows.at(-1)])/2,middle=x1+gap/2;const line=document.createElementNS('http://www.w3.org/2000/svg','path');line.setAttribute('d',`M ${x1} ${y1} H ${middle} V ${y2} H ${x2}`);links.append(line);}canvas.prepend(links);
    canvas.prepend(B.el('div',{class:'sb-daw-column-mask','aria-hidden':'true',style:'width:'+labelWidth+'px;height:'+totalHeight+'px'}),...rulers);canvas.style.width=(labelWidth+maxX+100)+'px';canvas.style.height=totalHeight+'px';scroll.append(canvas);box.append(scroll);
    const fitContents=()=>{if(!box.isConnected)return;compactContents.forEach(({card,contents,stash})=>B.fitTrajectoryContents(card,contents,stash));};
    queueMicrotask(fitContents);
    if(window.ResizeObserver&&compactContents.length){const observer=new window.ResizeObserver(()=>{if(!box.isConnected){observer.disconnect();return;}fitContents();});compactContents.forEach(({card})=>observer.observe(card));}
    document.fonts?.ready.then(fitContents);
    if(!visible.length)box.append(B.note(window.EIPSIAdminI18n.t("Add an assessment to see its journey.")));
    if(state.conditionModal&&app.logicalSelection&&B.node(d,app.logicalSelection.assessment_id)){
      const assessment=B.node(d,app.logicalSelection.assessment_id),ancestors=[];let parent=B.node(d,assessment.parent_id);
      while(parent){if(!roots.has(parent.id))ancestors.unshift(parent);parent=B.node(d,parent.parent_id);}
      const owner=[...ancestors].reverse().find(n=>n.metadata?.role==='LONGITUDINAL_BLOCK'),context=B.el('div',{class:'sb-condition-context sb-block-color-'+blockColor(owner?.id)},...(ancestors.length?[B.el('small',{text:window.EIPSIAdminI18n.t("Blocks: ")+ancestors.map(n=>B.label(n)).join(' › ')})]:[]),B.el('strong',{text:window.EIPSIAdminI18n.f("Assessment: %s",B.label(assessment))}));
      if(app.logicalSelection.question_id){const question=B.logicalQuestions(app,assessment).find(q=>q.id===app.logicalSelection.question_id);context.append(B.el('span',{text:window.EIPSIAdminI18n.t("Question: ")+(question?.variable_name||question?.response_key||app.logicalSelection.question_id)+' — '+(question?.label||'')}));}
      const close=()=>{state.conditionModal=false;refresh();},modal=B.el('dialog',{class:'sb-modal sb-condition-modal','aria-label':window.EIPSIAdminI18n.t("Logical conditions"),'aria-modal':'true'},B.el('div',{class:'sb-condition-modal-header'},B.el('h2',{text:window.EIPSIAdminI18n.t("Logical conditions")}),B.button(window.EIPSIAdminI18n.t("Close"),close)),context,B.logicalConditionInspector(app,app.logicalSelection.assessment_id,app.logicalSelection.question_id));
      modal.addEventListener('cancel',e=>{e.preventDefault();close();});box.append(modal);
      queueMicrotask(()=>{if(!modal.isConnected)return;if(modal.showModal)modal.showModal();else modal.setAttribute('open','');const focus=state.editingName?null:modal.querySelector('input,select,button');focus?.focus();});
    }
    if(state.editingName)queueMicrotask(()=>{const input=box.querySelector('.sb-daw-name-input');input?.focus();input?.select();});
    return box;
  };
})(window.EIPSIBuilder);
