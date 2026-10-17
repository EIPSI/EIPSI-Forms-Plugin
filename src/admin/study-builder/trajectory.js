(function (B) {
  'use strict';
  B.logicalHelpSections=[
    ['Cómo armar una condición',[
      ['Cuándo → Fuente → Comparación → Acción','Primero elegí el momento que dispara la revisión. Después indicá qué dato se compara y qué debe pasar si la expresión es verdadera.','Al completar Evaluación 1: ansiedad ≥ 7 ⇒ Esperar Evaluación 3.'],
      ['Origen y destino','Las fuentes usan el pasado o el punto actual de cada pista; las acciones apuntan hacia su futuro. El mismo elemento puede estar antes en una pista y después en otra. Revisá las rutas de origen/destino que muestra el modal.','Una respuesta de Evaluación 2 puede usarse desde Evaluación 3 sólo en las pistas donde Evaluación 2 ya ocurrió.'],
      ['Verdadero, falso e indeterminado','Sólo verdadero ejecuta acciones. Un dato aún no disponible produce un resultado indeterminado, no falso. NOT no convierte un dato desconocido en verdadero.','Si una randomización todavía no está resuelta, su resultado no puede darse por conocido.']
    ]],
    ['Operadores y agrupación',[
      ['= · Igual','Exige el mismo valor y tipo de dato. En preguntas con opciones se selecciona la opción del catálogo.','ansiedad = 7 se cumple con 7; no con 8.'],
      ['≠ · Distinto','Se cumple cuando un dato conocido es diferente al valor indicado.','grupo ≠ Control.'],
      ['< · Menor','Compara números o fechas; excluye el límite.','edad < 18 no incluye 18.'],
      ['≤ · Menor o igual','Compara números o fechas; incluye el límite.','edad ≤ 18 incluye 18.'],
      ['> · Mayor','Compara números o fechas; excluye el límite.','ansiedad > 7 incluye 8, pero no 7.'],
      ['≥ · Mayor o igual','Compara números o fechas; incluye el límite.','ansiedad ≥ 7 incluye 7.'],
      ['∈ · Pertenece','Comprueba si el valor está entre los elegidos. En una respuesta múltiple alcanza con que coincida al menos una selección.','síntomas ∈ {insomnio, fatiga} se cumple si marcó insomnio, fatiga o ambos.'],
      ['AND','Todas las comparaciones del grupo deben ser verdaderas.','edad ≥ 18 AND consentimiento = Sí.'],
      ['OR','Alcanza con que una comparación del grupo sea verdadera.','ansiedad ≥ 7 OR tristeza ≥ 7.'],
      ['NOT','Invierte una expresión conocida: verdadero pasa a falso y falso a verdadero. No invierte un resultado indeterminado.','NOT (grupo = Control).'],
      ['Paréntesis y ⇒','Los paréntesis delimitan grupos; ⇒ separa la expresión de sus acciones. Usá grupos para hacer explícita la combinación.','(ansiedad ≥ 7 OR tristeza ≥ 7) AND consentimiento = Sí ⇒ Saltar a evaluación.']
    ]],
    ['Cuándo se evalúa',[
      ['Al cambiar una respuesta','Revisa la condición cuando cambia una respuesta en su contexto. Permite reaccionar dentro de la evaluación.','Si riesgo = Sí, ir a una pregunta de seguimiento de esa evaluación.'],
      ['Al completar','Revisa la condición al completar la evaluación de origen. Es el momento para decidir recorridos externos con sus respuestas finales.','Si la puntuación supera el umbral al completar, saltar a otra evaluación.'],
      ['Al resolver randomización','Revisa la condición cuando se conoce el resultado de una randomización.','Si fue seleccionado el candidato A, restringir una randomización posterior.'],
      ['Al cambiar de estado','Elegí un elemento y el estado al que debe pasar. La condición se revisa en esa transición. Disponible incluye la apertura por calendario; no hace falta un evento separado de disponibilidad.','Cuando Evaluación 2 pasa a Expirado, cancelar una oportunidad futura.']
    ]],
    ['Fuentes: qué dato estás consultando',[
      ['Respuesta','Consulta el valor respondido. Los comparadores disponibles dependen del tipo: número, fecha, opción, texto o selección múltiple.','Un VAS o un Likert numérico permite comparar con ≥ 7.'],
      ['Estado de pregunta','Distingue answered (respondida), unanswered (sin responder), not_reached (no alcanzada), skipped_by_logic (omitida por lógica) y not_selected_by_randomization (no seleccionada).','No seleccionada por randomización no significa que el participante dejó una pregunta sin responder.'],
      ['Pregunta mostrada','Consulta si la pregunta fue mostrada; se compara con Verdadero o Falso.','Pregunta mostrada(pregunta de seguimiento) = Verdadero.'],
      ['Pregunta seleccionada','Consulta si la pregunta forma parte de la selección. Ser seleccionada y haber sido mostrada son hechos distintos.','Una pregunta seleccionada puede todavía no haberse mostrado.'],
      ['Candidato seleccionado','Consulta si una randomización eligió un candidato determinado.','Candidato seleccionado(Randomización A, Evaluación B) = Verdadero.'],
      ['Randomización resuelta','Consulta si el resultado de la randomización ya está definido.','Usala para distinguir una decisión conocida de una pendiente.'],
      ['Estado de bloque','Consulta el estado de una evaluación o contenedor permitido por el contexto.','Estado de bloque(Evaluación 1) = COMPLETED.']
    ]],
    ['Estados de los elementos',[
      ['Bloqueado por una dependencia · BLOCKED','Todavía depende de que se resuelva otro elemento.','No equivale a una respuesta faltante.'],
      ['Programado · SCHEDULED','Tiene programación, pero todavía no está disponible.','Una evaluación prevista para mañana.'],
      ['Disponible · AVAILABLE','Puede comenzar según su programación y las reglas de acceso.','Se alcanzó la hora de apertura.'],
      ['En progreso · IN_PROGRESS','La evaluación comenzó y sigue en curso.','El participante está respondiendo.'],
      ['Completado · COMPLETED','La evaluación se completó.','Puede servir como antecedente de otra condición.'],
      ['Expirado · EXPIRED','Terminó su período de disponibilidad sin una finalización vigente.','No es lo mismo que vencer la sesión de memoria del cuestionario.'],
      ['Omitido por condición · SKIPPED_BY_RULE','Una condición hizo que se omitiera el elemento.','Una evaluación intermedia saltada por la ruta elegida.'],
      ['Cancelado o no seleccionado · NOT_SCHEDULED','No forma parte de la programación efectiva por cancelación o selección.','Un candidato que no fue elegido.'],
      ['Retirado · WITHDRAWN','La participación o el elemento quedó retirado.','No equivale a completado.'],
      ['Terminado por condición · TERMINATED_BY_RULE','El recorrido se terminó por una condición.','Revisá el resultado de la regla en Pruebas.']
    ]],
    ['Acciones: qué pasa cuando se cumple',[
      ['Ir a pregunta','Dirige a una pregunta futura de la misma evaluación.','Si una respuesta requiere seguimiento, ir a la pregunta de seguimiento.'],
      ['Terminar evaluación','Cierra la evaluación actual por regla. No significa abandonar todo el estudio ni borrar respuestas previas.','Si la respuesta descarta continuar ese cuestionario, terminarlo.'],
      ['Saltar a evaluación','Dirige a una evaluación futura, desde el inicio o desde una pregunta elegida. Puede adelantar su apertura; las evaluaciones intermedias que se saltean quedan omitidas.','Pasar directamente a Evaluación 14, pregunta 2.'],
      ['Esperar evaluación','Deja el recorrido esperando hasta que el destino esté disponible en su horario. No adelanta su apertura. Puede indicar una pregunta de entrada.','Esperar la evaluación del día 7, sin comenzar antes del día 7.'],
      ['Cancelar elemento','Cancela el elemento futuro elegido: evaluación, bloque longitudinal o randomización. En una evaluación repetida afecta sólo la oportunidad programada elegida. Conserva respuestas completadas y evaluaciones en progreso.','Cancelar la oportunidad del día 4; para cerrar la evaluación actual usá Terminar evaluación.'],
      ['Restringir candidatos futuros','Deja competir sólo a los candidatos indicados, sin elegir por sí sola un resultado único.','De A, B y C, dejar únicamente A y B.'],
      ['Excluir candidatos futuros','Retira los candidatos indicados de una randomización futura.','De A, B y C, excluir C.'],
      ['Forzar resultado futuro','Fija los candidatos indicados como resultado de la randomización futura, respetando su cantidad y configuración.','Si la selección es de uno, forzar B.']
    ]],
    ['Prioridades, randomizaciones y lectura de las pistas',[
      ['Prioridad y orden de acciones','Un número menor tiene mayor prioridad. Se ejecutan las acciones compatibles; la prioridad resuelve destinos incompatibles. En empate se usa el ID de condición. Las acciones de una condición se procesan en el orden mostrado.','Entre prioridad 1 y 2, gana 1 si ambas intentan imponer recorridos incompatibles.'],
      ['Randomización dentro o fuera de la evaluación','La semilla se conserva. Dentro de la evaluación, cambiar una respuesta puede recalcular una selección dependiente con la misma semilla si se permite volver. Una decisión externa dependiente de esas respuestas se fija al completar la evaluación de origen.','Volver y corregir una respuesta puede cambiar la selección interna; las respuestas provisionales no fijan el bloque externo.'],
      ['Límites de los candidatos','No se modifica una randomización externa ya resuelta ni se permite dejar un conjunto vacío o demasiado pequeño para la cantidad requerida.','Seleccionar 2 exige conservar al menos 2 candidatos elegibles.'],
      ['Pistas, espera, cancelación y truncado','Cada pista representa una posibilidad, no una ejecución simultánea. La espera es un intervalo inactivo. Un elemento cancelado queda inactivo. El truncado marca dónde una trayectoria se vuelve imposible; lo posterior queda inactivo.','Una parte inactiva por espera o cancelación no vuelve imposible por sí sola toda la pista.'],
      ['Comprobar antes de publicar','Revisá las fuentes y destinos de cada pista, validá la definición y recorré las simulaciones de Pruebas. Si la vista indica que es parcial, mostrá más caminos antes de interpretar el total.','Probá un caso verdadero, uno falso y uno donde el dato todavía no esté disponible.']
    ]]
  ];
  B.logicalHelp=app=>{
    const opener=document.activeElement,titleId=B.id('logical-help'),dialog=B.el('dialog',{class:'sb-modal sb-logical-help','aria-labelledby':titleId}),body=B.el('div',{class:'sb-logical-help-body'});
    const close=()=>{if(dialog.close)dialog.close();else dialog.dispatchEvent(new Event('close'));};
    dialog.append(B.el('header',{class:'sb-logical-help-header'},B.el('h2',{id:titleId,text:'Guía de Condiciones Lógicas'}),B.button('Cerrar ayuda',close)),B.note('Elegí una sección. Cada operación incluye qué significa y un ejemplo; la ayuda no modifica el estudio.'));
    const index=B.el('nav',{'aria-label':'Secciones de la guía'});
    B.logicalHelpSections.forEach(([name,rows],i)=>{const section=B.el('details',{class:'sb-logical-help-section',...(i===0?{open:''}:{})},B.el('summary',{text:name}));index.append(B.button(name,()=>{section.open=true;section.scrollIntoView?.({block:'start'});}));rows.forEach(([label,explanation,example])=>section.append(B.el('article',{},B.el('h3',{text:label}),B.el('p',{text:explanation}),B.el('p',{class:'sb-logical-help-example'},B.el('strong',{text:'Ejemplo: '}),example))));body.append(section);});
    dialog.append(index,body);dialog.addEventListener('close',()=>{dialog.remove();opener?.focus();},{once:true});app.root.append(dialog);if(dialog.showModal)dialog.showModal();else dialog.setAttribute('open','');dialog.querySelector('button').focus();
  };
  const rules = d => d.protocol_metadata.trajectory_conditions || [];
  B.logicalQuestions = (app, n) => {const catalog=(app.nativeTemplates||[]).find(t=>t.id===n.metadata.native_version_id)?.questions||[];return (n.metadata.native_questions||catalog).map(q=>({...q,variable_name:q.variable_name||catalog.find(t=>t.id===q.id)?.variable_name||q.response_key}));};
  B.logicalRandoms=app=>app.draft.nodes.flatMap(n=>{
    const out=[];
    if(n.randomization)out.push({key:n.id,target_id:n.id,label:B.label(n),config:n.randomization,candidates:n.randomization.candidates.map(id=>({id,label:B.label(B.node(app.draft,id))}))});
    const native=n.metadata?.native_randomizations||(app.nativeTemplates||[]).find(t=>t.id===n.metadata?.native_version_id)?.randomizations||[];
    native.forEach(r=>out.push({key:n.id+'|'+r.id,target_id:n.id,randomization_id:r.id,label:B.label(n)+' / '+r.label,config:r,candidates:r.candidates.map(id=>({id,label:B.logicalQuestions(app,n).find(q=>q.id===id)?.label||id}))}));return out;
  });
  B.logicalRules = rules;
  B.logicalPredicate = (assessment, question) => ({op:'EQ',reference:{kind:question?'ANSWER':'BLOCK_STATE',...(question?{assessment_id:assessment,question_id:question.id}:{node_id:assessment})},value:question?.value_type==='NUMBER'?0:question?.value_type==='SET'?[]:question?'':'COMPLETED'});
  const names = {ANSWER:'Respuesta',QUESTION_STATE:'Estado de pregunta',QUESTION_SHOWN:'Pregunta mostrada',QUESTION_SELECTED:'Pregunta seleccionada',RANDOM_SELECTED:'Candidato seleccionado',RANDOM_RESOLVED:'Randomización resuelta',BLOCK_STATE:'Estado de bloque'};
  B.logicalNotation=(app,p)=>{
    if(p.args)return '('+p.args.map(arg=>B.logicalNotation(app,arg)).join(p.op==='AND'?' AND ':' OR ')+')';
    if(p.arg)return 'NOT ('+B.logicalNotation(app,p.arg)+')';
    const ref=p.reference,n=B.node(app.draft,ref.assessment_id||ref.node_id||ref.randomization_id),q=ref.question_id&&B.logicalQuestions(app,n||{metadata:{}}).find(q=>q.id===ref.question_id);
    let source=q?(q.variable_name||q.response_key||q.label):B.label(n||{id:ref.randomization_id});
    if(ref.kind!=='ANSWER')source=names[ref.kind]+'('+source+(ref.candidate_id?', '+B.label(B.node(app.draft,ref.candidate_id)||{id:ref.candidate_id}):'')+')';
    const value=v=>{if(typeof v==='boolean')return v?'Verdadero':'Falso';if(typeof v==='number')return String(v);const option=q?.options?.find(o=>o.id===v);return JSON.stringify(option?.label||v);};
    return source+' '+({EQ:'=',NEQ:'≠',LT:'<',LTE:'≤',GT:'>',GTE:'≥',IN:'∈'}[p.op]||p.op)+' '+(Array.isArray(p.value)?'{'+p.value.map(value).join(', ')+'}':value(p.value));
  };
  B.logicalOrigin=(app,id,questionId)=>{
    const parts=[],seen=new Set();let n=B.node(app.draft,id);
    while(n&&!seen.has(n.id)){seen.add(n.id);if(!app.draft.root_block_ids.includes(n.id))parts.unshift(B.label(n));n=B.node(app.draft,n.parent_id);}
    if(questionId){const q=B.logicalQuestions(app,B.node(app.draft,id)||{metadata:{}}).find(q=>q.id===questionId);parts.push((q?.variable_name||q?.response_key||questionId)+(q?.label?' — '+q.label:''));}
    return parts.join(' › ');
  };
  B.logicalReferenceOrigin=(app,ref)=>{
    if(!ref.randomization_id)return B.logicalOrigin(app,ref.assessment_id||ref.node_id,ref.question_id);
    const key=ref.assessment_id?ref.assessment_id+'|'+ref.randomization_id:ref.randomization_id,r=B.logicalRandoms(app).find(r=>r.key===key),base=B.logicalOrigin(app,ref.assessment_id||ref.randomization_id);
    return base+(ref.assessment_id?' › Randomización: '+(r?.label.split(' / ').at(-1)||ref.randomization_id):'')+(ref.candidate_id?' › Candidato: '+(r?.candidates.find(c=>c.id===ref.candidate_id)?.label||ref.candidate_id):'');
  };
  B.logicalExpression = (app,p,change,depth=0,context=null) => {
    const d=app.draft, scope=context?B.logicalPastScope(app,context):null, questionPool=n=>B.logicalQuestions(app,n).filter(q=>!scope||scope.allows({assessment_id:n.id,question_id:q.id})), assessments=d.nodes.filter(n=>n.completion_boundary==='ASSESSMENT'&&(!scope||scope.allows({node_id:n.id}))), blocks=scope?.blocks||d.nodes.filter(n=>n.kind==='BLOCK'), randomPool=B.logicalRandoms(app).filter(r=>!scope||scope.allows({randomization_id:r.randomization_id||r.target_id,...(r.randomization_id?{assessment_id:r.target_id}:{})})), first=assessments.find(n=>n.id===(p.reference?.assessment_id||context?.assessment_id)&&questionPool(n).length)||assessments.find(n=>questionPool(n).length), leaf=()=>B.logicalPredicate(first?.id||assessments[0]?.id,questionPool(first||{metadata:{}})[0]);
    const symbol=(text,title)=>B.el('span',{class:'sb-equation-symbol',text,title});
    const box=B.el('div',{class:'sb-logical-expression '+(p.args||p.arg?'sb-equation-group':'sb-equation-atom')});
    const structure=B.select('Estructura',p.args?p.op:p.arg?'NOT':'ATOM',[['ATOM','a = b'],['AND','AND'],['OR','OR'],['NOT','NOT']],op=>{
      if(op==='ATOM')change(p.args?p.args[0]:p.arg||p);
      else if(op==='NOT')change({op,arg:p.arg||p});
      else change({op,args:p.args||[p.arg||p,leaf()]});
    });structure.classList.add('sb-equation-structure');box.append(structure);
    if(depth>=20)return box;
    if(p.args){const terms=B.el('div',{class:'sb-equation-terms'},symbol('(','Inicio de grupo'));p.args.forEach((arg,i)=>{
      if(i)terms.append(symbol(p.op==='AND'?'AND':'OR',p.op==='AND'?'Conjunción (Y)':'Disyunción (O)'));
      const term=B.el('div',{class:'sb-equation-term'},B.logicalExpression(app,arg,v=>change({...p,args:p.args.map((x,j)=>i===j?v:x)}),depth+1,context),B.button('Quitar comparación',()=>change({...p,args:p.args.filter((_,j)=>i!==j)}),p.args.length<=2));terms.append(term);
    });terms.append(symbol(')','Fin de grupo'));box.append(terms,B.button('Añadir comparación',()=>change({...p,args:[...p.args,leaf()]})));return box;}
    if(p.arg){box.append(B.el('div',{class:'sb-equation-terms'},symbol('NOT','Negación'),symbol('(','Inicio de grupo'),B.logicalExpression(app,p.arg,arg=>change({...p,arg}),depth+1,context),symbol(')','Fin de grupo')));return box;}
    const ref=p.reference, patch=reference=>{const q=assessments.flatMap(n=>questionPool(n).map(q=>({...q,assessment_id:n.id}))).find(q=>q.id===reference.question_id&&q.assessment_id===reference.assessment_id);const type=reference.kind==='ANSWER'?q?.value_type:['QUESTION_SHOWN','QUESTION_SELECTED','RANDOM_SELECTED','RANDOM_RESOLVED'].includes(reference.kind)?'BOOLEAN':'STRING';change({op:type==='SET'?'IN':'EQ',reference,value:type==='BOOLEAN'?true:type==='NUMBER'?0:type==='SET'?['']:''});};
    box.append(B.select('Fuente',ref.kind,Object.entries(names),kind=>{if(kind.startsWith('QUESTION')||kind==='ANSWER')patch({kind,assessment_id:first?.id,question_id:questionPool(first||{metadata:{}}).find(q=>q.id===(ref.question_id||context?.question_id))?.id||questionPool(first||{metadata:{}})[0]?.id});else if(kind.startsWith('RANDOM')){const r=randomPool[0];patch({kind,randomization_id:r?.randomization_id||r?.target_id,...(r?.randomization_id?{assessment_id:r.target_id}:{}),...(kind==='RANDOM_SELECTED'?{candidate_id:r?.candidates[0]?.id}:{})});}else patch({kind,node_id:blocks.find(n=>n.id===(ref.node_id||context?.assessment_id))?.id||blocks[0]?.id});}));
    const sourceField=[...box.querySelectorAll('label')].find(label=>label.querySelector('span')?.textContent==='Fuente');
    sourceField?.querySelectorAll('option').forEach(option=>{option.disabled=option.value.startsWith('RANDOM')?!randomPool.length:option.value==='BLOCK_STATE'?!blocks.length:!assessments.some(n=>questionPool(n).length);});
    if(scope?.more)box.append(B.note('Vista parcial de caminos: usá Mostrar más caminos posibles para incorporar sus fuentes.'));
    if(scope&&!scope.allows(ref))box.append(B.note('Esta fuente no pertenece al pasado de las pistas seleccionadas. Elegí una fuente anterior; no se reemplazó automáticamente.'));
    let type='STRING',options=[];
    if(ref.assessment_id&&!ref.kind.startsWith('RANDOM')){const n=B.node(d,ref.assessment_id),q=questionPool(n||{metadata:{}}).find(q=>q.id===ref.question_id);box.append(B.select('Evaluación',ref.assessment_id,assessments.map(n=>[n.id,B.logicalOrigin(app,n.id)]),id=>patch({...ref,assessment_id:id,question_id:questionPool(B.node(d,id))[0]?.id})),B.select('Pregunta',ref.question_id,questionPool(n||{metadata:{}}).map(q=>[q.id,q.variable_name||q.response_key||q.label]),id=>patch({...ref,question_id:id})));type=ref.kind==='ANSWER'?q?.value_type:['QUESTION_SHOWN','QUESTION_SELECTED'].includes(ref.kind)?'BOOLEAN':'STRING';options=ref.kind==='QUESTION_STATE'?['answered','unanswered','not_reached','skipped_by_logic','not_selected_by_randomization']:q?.options||[];}
    else if(ref.randomization_id){const randoms=randomPool,key=ref.assessment_id?ref.assessment_id+'|'+ref.randomization_id:ref.randomization_id,r=randoms.find(r=>r.key===key);box.append(B.select('Randomización',key,randoms.map(r=>[r.key,r.label]),key=>{const r=randoms.find(r=>r.key===key);patch({kind:ref.kind,randomization_id:r.randomization_id||r.target_id,...(r.randomization_id?{assessment_id:r.target_id}:{}),...(ref.kind==='RANDOM_SELECTED'?{candidate_id:r.candidates[0]?.id}:{})});}));if(ref.kind==='RANDOM_SELECTED')box.append(B.select('Candidato',ref.candidate_id,(r?.candidates||[]).map(c=>[c.id,c.label]),candidate_id=>patch({...ref,candidate_id})));type='BOOLEAN';}
    else box.append(B.select('Bloque',ref.node_id,blocks.map(n=>[n.id,B.logicalOrigin(app,n.id)||B.label(n)]),node_id=>patch({...ref,node_id})));
    box.append(B.note('Origen del dato: '+B.logicalReferenceOrigin(app,ref)+(scope?' · Anterior o actual en '+scope.count(ref)+' de '+scope.paths.length+' pistas de este contexto.':'')));
    const ordered=['NUMBER','DATE'].includes(type),numericOps=['LT','LTE','GT','GTE'];
    const ops=[['EQ','= (igual)'],['NEQ','≠ (distinto)'],['LT','< (menor)'],['LTE','≤ (menor o igual)'],['GT','> (mayor)'],['GTE','≥ (mayor o igual)'],['IN','∈ (pertenece)']];
    const comparator=B.select('Comparador',p.op,ops,op=>change({...p,op,value:op==='IN'?(Array.isArray(p.value)?p.value:[p.value]):(Array.isArray(p.value)?p.value[0]:p.value)}));
    comparator.querySelectorAll('option').forEach(option=>{option.disabled=(numericOps.includes(option.value)&&!ordered)||(type==='SET'&&option.value!=='IN');});
    box.append(comparator);
    if(!ordered)box.append(B.el('small',{class:'sb-equation-type-hint',text:'<, ≤, > y ≥ requieren una respuesta numérica o una fecha. Seleccioná Fuente: Respuesta y una pregunta de ese tipo.'}));
    if(type==='BOOLEAN'&&p.op!=='IN')box.append(B.select('Valor',String(p.value),[['true','Verdadero'],['false','Falso']],value=>change({...p,value:value==='true'})));
    else if(options.length&&p.op==='IN')options.forEach(o=>{const id=typeof o==='string'?o:o.id;box.append(B.check(typeof o==='string'?o:o.label,p.value.includes(id),yes=>change({...p,value:yes?[...p.value,id]:p.value.filter(x=>x!==id)})));});
    else if(options.length&&p.op!=='IN')box.append(B.select('Valor',p.value,options.map(o=>typeof o==='string'?o:[o.id??o.value??o.label,o.label]),value=>change({...p,value})));
    else box.append(B.field(p.op==='IN'?'Valores separados por comas':'Valor',Array.isArray(p.value)?p.value.join(', '):p.value,value=>change({...p,value:p.op==='IN'?String(value).split(',').map(v=>type==='NUMBER'?Number(v.trim()):type==='BOOLEAN'?v.trim()==='true':v.trim()):value}),type==='NUMBER'&&p.op!=='IN'?'number':type==='DATE'&&p.op!=='IN'?'date':'text'));
    return box;
  };
  B.logicalConditionInspector=(app,assessmentId,questionId)=>{
    const d=app.draft,n=B.node(d,assessmentId),q=B.logicalQuestions(app,n).find(q=>q.id===questionId),box=B.el('aside',{class:'sb-trajectory-properties'},B.el('h3',{text:q?.label||B.label(n)}));
    const mutate=fn=>app.mutate(x=>{x.protocol_metadata.trajectory_conditions=fn(rules(x));return x;});
    box.append(B.note('Las fuentes se limitan al pasado y al punto actual de las pistas seleccionadas. En otra pista donde el dato esté después o ausente, esa comparación queda indeterminada.'));
    box.append(B.note('AND conjunción · OR disyunción · NOT negación · ∈ pertenece · ⇒ ejecuta las acciones. Los paréntesis delimitan cada grupo.'));
    box.append(B.note('Prioridad menor = mayor prioridad. Se ejecutan todas las acciones compatibles; la prioridad decide los destinos incompatibles.'));
    rules(d).filter(r=>r.source.assessment_id===assessmentId&&(r.source.question_id||null)===(questionId||null)).forEach(r=>{
      const context={...r.source,event:r.event,track_ids:app.logicalSelection?.track_ids},scope=B.logicalPastScope(app,context),patch=p=>mutate(all=>all.map(x=>x.id===r.id?{...x,...p,reference_scope:'TRACK_PAST_V1',action_scope:'TRACK_FUTURE_V1'}:x)), card=B.el('fieldset',{},B.el('legend',{text:r.label||'Condición'}),B.note('Origen del evento: '+B.logicalOrigin(app,assessmentId,questionId)),B.field('Nombre',r.label,label=>patch({label})),B.field('Prioridad',r.priority,priority=>patch({priority}),'number',{min:1,step:1}),B.select('Cuándo',r.event==='SCHEDULE'?'BLOCK_STATE':r.event,[['ANSWER','Al cambiar una respuesta'],['COMPLETE','Al completar'],['RANDOM_RESOLVED','Al resolver randomización'],['BLOCK_STATE','Al cambiar de estado']],event=>patch({event,trigger:event==='BLOCK_STATE'?{node_id:assessmentId,state:'AVAILABLE'}:undefined})),B.el('div',{class:'sb-equation-preview',text:B.logicalNotation(app,r.predicate),'aria-label':'Expresión lógica'}),B.logicalExpression(app,r.predicate,predicate=>patch({predicate}),0,context));
      if(r.event==='BLOCK_STATE'||r.event==='SCHEDULE'){
        const trigger=r.trigger||{node_id:assessmentId,state:'AVAILABLE'},states=[['BLOCKED','Bloqueado por una dependencia'],['SCHEDULED','Programado'],['AVAILABLE','Disponible'],['IN_PROGRESS','En progreso'],['COMPLETED','Completado'],['EXPIRED','Expirado'],['SKIPPED_BY_RULE','Omitido por condición'],['NOT_SCHEDULED','Cancelado o no seleccionado'],['WITHDRAWN','Retirado'],['TERMINATED_BY_RULE','Terminado por condición']];
        card.insertBefore(B.el('div',{class:'sb-state-trigger'},B.select('Elemento que cambia',trigger.node_id,scope.blocks.map(n=>[n.id,B.logicalOrigin(app,n.id)||B.label(n)]),node_id=>patch({event:'BLOCK_STATE',trigger:{...trigger,node_id}})),B.select('Al pasar a',trigger.state,states,state=>patch({event:'BLOCK_STATE',trigger:{...trigger,state}}))),card.querySelector('.sb-equation-preview'));
        if(!scope.allows({node_id:trigger.node_id}))card.append(B.note('El elemento que cambia queda fuera del pasado de estas pistas. Seleccioná un elemento anterior o el actual.'));
        if(!r.trigger)card.append(B.note('Esta condición anterior usaba un disparador general. Elegí elemento y estado; al guardar se convertirá a una transición explícita.'));
      }
      card.append(B.el('div',{class:'sb-equation-implication',text:'⇒',title:'Si la expresión es verdadera, ejecutar las acciones en este orden'}));
      r.actions.forEach((a,i)=>{
        const update=action=>patch({actions:r.actions.map((x,j)=>i===j?action:x)}),types=[['GO_QUESTION','Ir a pregunta'],['END_ASSESSMENT','Terminar evaluación'],['JUMP','Saltar a evaluación'],['WAIT','Esperar evaluación'],['CANCEL_BLOCK','Cancelar elemento'],['RESTRICT_RANDOM','Restringir candidatos futuros'],['EXCLUDE_RANDOM','Excluir candidatos futuros'],['FORCE_RANDOM','Forzar resultado futuro']];
        const questions=(id,type)=>B.logicalQuestions(app,B.node(d,id)||{metadata:{}}).filter(q=>scope.future({type,target_id:id,question_id:q.id}));
        const randoms=B.logicalRandoms(app).filter(random=>scope.future({type:'FORCE_RANDOM',target_id:random.target_id,...(random.randomization_id?{randomization_id:random.randomization_id}:{})}));
        const targets=type=>d.nodes.filter(n=>type==='GO_QUESTION'?n.id===assessmentId&&questions(n.id,type).length:type==='CANCEL_BLOCK'?n.kind==='BLOCK'&&!d.root_block_ids.includes(n.id)&&scope.future({type,target_id:n.id}):n.completion_boundary==='ASSESSMENT'&&scope.future({type,target_id:n.id}));
        const row=B.el('fieldset',{},B.el('legend',{text:'Acción '+(i+1)}),B.select('Acción',a.type,types,type=>{const target=targets(type)[0],random=type.includes('RANDOM')?randoms[0]:null;update({type,...(type==='END_ASSESSMENT'?{}:{target_id:random?.target_id||target?.id}),...(type.includes('RANDOM')?{candidate_ids:random?.candidates.slice(0,1).map(c=>c.id)||[],...(random?.randomization_id?{randomization_id:random.randomization_id}:{})}:{}),...(type==='GO_QUESTION'?{question_id:questions(target?.id,type)[0]?.id}:{})});}));
        row.querySelectorAll('select option').forEach(option=>{option.disabled=option.value==='END_ASSESSMENT'?false:option.value.includes('RANDOM')?!randoms.length:!targets(option.value).length;});
        if(a.type!=='END_ASSESSMENT'&&!a.type.includes('RANDOM'))row.append(B.select('Destino',a.target_id,targets(a.type).map(n=>[n.id,B.logicalOrigin(app,n.id)]),target_id=>update({...a,target_id,...(a.type==='GO_QUESTION'||a.question_id?{question_id:questions(target_id,a.type)[0]?.id}:{})})));
        if(a.type==='CANCEL_BLOCK')row.append(B.el('p',{className:'description',text:'Se cancela el elemento futuro de esta pista. Una evaluación afecta sólo esa oportunidad programada. En contenedores se conservan las respuestas completadas y las evaluaciones en progreso; para cerrar la actual usá Terminar evaluación.'}));
        if(['GO_QUESTION','JUMP','WAIT'].includes(a.type))row.append(B.select('Entrar en',a.question_id||'',[...(a.type==='GO_QUESTION'?[]:[['','Inicio']]),...questions(a.target_id,a.type).map(q=>[q.id,(q.variable_name||q.response_key||q.id)+' — '+q.label])],id=>{const next={...a};if(id)next.question_id=id;else delete next.question_id;update(next);}));
        if(a.type.includes('RANDOM')){const key=a.target_id+(a.randomization_id?'|'+a.randomization_id:''),random=randoms.find(r=>r.key===key);row.append(B.select('Randomización futura',key,randoms.map(r=>[r.key,B.logicalReferenceOrigin(app,{randomization_id:r.randomization_id||r.target_id,...(r.randomization_id?{assessment_id:r.target_id}:{})})]),key=>{const random=randoms.find(r=>r.key===key);update({type:a.type,target_id:random.target_id,...(random.randomization_id?{randomization_id:random.randomization_id}:{}),candidate_ids:random.candidates.slice(0,1).map(c=>c.id)});}));row.append(B.el('p',{className:'description',text:a.randomization_id&&a.target_id===r.source.assessment_id?'Dentro de esta evaluación, cambiar la respuesta recalcula la selección con la misma semilla inicial.':'Esta randomización se define al completar la evaluación de origen; las respuestas provisionales no la fijan.'}));(random?.candidates||[]).forEach(c=>row.append(B.check(c.label,a.candidate_ids.includes(c.id),yes=>update({...a,candidate_ids:yes?[...a.candidate_ids,c.id]:a.candidate_ids.filter(x=>x!==c.id)}))));}
        if(a.type==='END_ASSESSMENT')row.append(B.note('Destino: evaluación actual · '+B.logicalOrigin(app,assessmentId)));
        else row.append(B.note('Destino: '+(a.type.includes('RANDOM')?B.logicalReferenceOrigin(app,{randomization_id:a.randomization_id||a.target_id,...(a.randomization_id?{assessment_id:a.target_id}:{})}):B.logicalOrigin(app,a.target_id,a.question_id))+' · Futuro en '+scope.futureCount(a)+' de '+scope.paths.length+' pistas de este contexto.'));
        if(!scope.future(a))row.append(B.note('Esta acción no tiene un destino futuro válido en estas pistas. No se reemplazó automáticamente; seleccioná otro destino.'));
        if(scope.more)row.append(B.note('Vista parcial: usá Mostrar más caminos posibles para incorporar otros destinos.'));
        row.append(B.button('Subir acción',()=>{const actions=B.copy(r.actions);[actions[i-1],actions[i]]=[actions[i],actions[i-1]];patch({actions});},i===0),B.button('Quitar acción',()=>patch({actions:r.actions.filter((_,j)=>j!==i)}),r.actions.length===1));card.append(row);
      });
      card.append(B.button('Añadir acción',()=>patch({actions:[...r.actions,{type:'END_ASSESSMENT'}]})),B.button('Eliminar condición',()=>mutate(all=>all.filter(x=>x.id!==r.id))));box.append(card);
    });
    box.append(B.button('Añadir condición',()=>mutate(all=>[...all,{id:B.id('LC'),label:'Nueva condición',reference_scope:'TRACK_PAST_V1',action_scope:'TRACK_FUTURE_V1',source:{assessment_id:assessmentId,...(questionId?{question_id:questionId}:{})},event:questionId?'ANSWER':'COMPLETE',priority:all.length+1,predicate:B.logicalPredicate(assessmentId,q),actions:[{type:'END_ASSESSMENT'}]}])));return box;
  };
  // One branch is expanded at a time. Choices are prefixes, never a Cartesian product.
  B.trajectoryPlan=(d,choices={},actions=[])=>{
    const cells=[], seen=new Set(),cancelled=new Set(actions.filter(a=>a.type==='CANCEL_BLOCK').flatMap(a=>[a.target_id,...B.descendants(d,a.target_id)]));let immediate=0;
    const walk=(id,context=null)=>{if(seen.has(id))return;seen.add(id);const n=B.node(d,id);if(!n)return;if(n.metadata?.role==='LONGITUDINAL_BLOCK')context=n.id;
      if(cancelled.has(id)){cells.push({kind:'cancelled',id,at:immediate,context:context||id});return;}let at=immediate;const t=n.schedule?.temporal_rule;if(t?.kind==='LOCAL_DAY_TIME')at=(t.day_index-1)*86400+Number(t.time.slice(0,2))*3600+Number(t.time.slice(3))*60;else if(t?.duration)at=t.duration.seconds??t.duration.amount*86400;
      if(n.randomization&&!choices[id]){cells.push({kind:'random',id,at,context});immediate=Math.max(immediate,at)+60;return;}
      if(n.completion_boundary==='ASSESSMENT'){cells.push({kind:'assessment',id,at,context});immediate=Math.max(immediate,at)+60;return;}
      const children=B.children(d,id), selected=choices[id];(selected?selected.map(id=>B.node(d,id)).filter(Boolean):children).forEach(child=>walk(child.id,context));
    };d.root_block_ids.forEach(id=>walk(id));return cells;
  };
  B.randomOutcomes=(config,offset=0,limit=8)=>{
    const pool=config.candidates,n=config.strategy==='RANDOM_ORDER'?pool.length:config.strategy==='RANDOM_SELECT_N'?config.select_n:config.strategy==='WEIGHTED_RANDOM'?config.draw_count:1,permuted=config.strategy==='RANDOM_ORDER'||config.order==='DRAW';let visited=0;const result=[];
    const walk=(chosen,start)=>{if(result.length>limit)return;if(chosen.length===n){if(visited++>=offset)result.push([...chosen]);return;}for(let i=permuted?0:start;i<pool.length;i++){if(chosen.includes(pool[i]))continue;walk([...chosen,pool[i]],i+1);if(result.length>limit)return;}};walk([],0);return {outcomes:result.slice(0,limit),more:result.length>limit};
  };
  B.nativeOutcomes=(r,offset=0)=>{
    const all=r.mode==='all',always=all?[]:r.always_show||[],fixed=r.fixed||[],pool=r.candidates.filter(id=>!always.includes(id)&&(!all||!fixed.includes(id))),cfg={strategy:all?'RANDOM_ORDER':'RANDOM_SELECT_N',candidates:pool,select_n:r.count,order:'DRAW'},batch=B.randomOutcomes(cfg,offset,8),seen=new Set(),outcomes=[];
    for(const drawn of batch.outcomes){const visible=r.candidates.filter(id=>drawn.includes(id)||always.includes(id)||all&&fixed.includes(id)),pins=visible.filter(id=>fixed.includes(id)),order=Array(visible.length).fill(null);let previous=-1;for(let i=0;i<pins.length;i++){const slot=Math.max(previous+1,Math.min(r.candidates.indexOf(pins[i]),visible.length-pins.length+i));order[slot]=pins[i];previous=slot;}const movable=drawn.filter(id=>!pins.includes(id));always.filter(id=>!pins.includes(id)).forEach(id=>movable.push(id));let j=0;for(let i=0;i<order.length;i++)if(order[i]===null)order[i]=movable[j++];const key=order.join('|');if(!seen.has(key)){seen.add(key);outcomes.push(order);}}
    return {outcomes,more:batch.more};
  };
  B.nativeQuestionOrder=(questions,choices,assessment)=>[...questions].sort((a,b)=>{const ap=a.randomization_path||[],bp=b.randomization_path||[];for(let i=0;i<Math.min(ap.length,bp.length);i++){if(ap[i].randomization_id!==bp[i].randomization_id)return 0;const order=choices?.[assessment+'|'+ap[i].randomization_id];if(order&&ap[i].candidate_id!==bp[i].candidate_id)return order.indexOf(ap[i].candidate_id)-order.indexOf(bp[i].candidate_id);}return 0;});
  B.trajectoryViability=(app,lane)=>{
    const controls={};for(const action of lane.actions||[]){if(!action.type.includes('RANDOM'))continue;const key=action.target_id+(action.randomization_id?'|'+action.randomization_id:''),random=B.logicalRandoms(app).find(r=>r.key===key);if(!random)continue;const pool=controls[key]||random.candidates.map(c=>c.id);controls[key]=action.type==='EXCLUDE_RANDOM'?pool.filter(id=>!action.candidate_ids.includes(id)):pool.filter(id=>action.candidate_ids.includes(id));}
    for(const [key,selected] of Object.entries({...lane.choices,...lane.nativeChoices}))if(controls[key]&&selected.some(id=>!controls[key].includes(id)))return {inactive:true,reason:'Resultado excluido por las condiciones de esta trayectoria'};
    return {inactive:!!lane.inactive,reason:lane.reason};
  };
})(window.EIPSIBuilder);
