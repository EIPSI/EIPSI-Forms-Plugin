// Deterministic authored fixture; run explicitly when changing the native contract.
const fs=require('fs'),vm=require('vm'),{webcrypto}=require('crypto');const context={crypto:webcrypto,URL,console};context.globalThis=context;
vm.createContext(context);for(const f of ['variables.js','controls.js','model.js'])vm.runInContext(fs.readFileSync('src/admin/assessment-editor/'+f,'utf8'),context);
const A=context.EIPSIAssessment,d=A.assessment();d.id='AE_PARITY';d.title='Revisión de preguntas y controles';
A.questions.forEach(type=>{const e=A.element(type);e.id='EL_'+type;e.response_key='R_'+type;e.options.forEach((o,i)=>o.id='O_'+type+'_'+i);Object.assign(e.properties,{label:'Pregunta '+type,helper:'Primera línea\nSegunda línea',required:true});
if(e.properties.placeholder!==undefined)e.properties.placeholder='Ejemplo '+type;
if(e.properties.max_length!==undefined)Object.assign(e.properties,{min_length:2,max_length:type==='email'?254:type==='url'?2048:100});
if(type==='textarea')e.properties.rows=8;
if(type==='number')Object.assign(e.properties,{min:-2.5,max:7.5,step:0.25});
if(A.controls.choiceTypes.includes(type)){e.options=['Sí, a veces','Dijo "no"; después','Dos\nlíneas','Duplicada','Duplicada'].map((label,i)=>({id:'O_'+type+'_'+i,label}));}
if(type==='multiple_choice')Object.assign(e.properties,{min_selected:2,max_selected:3,presentation:'horizontal'});
if(type==='likert')Object.assign(e.properties,{min:-3,preset:'custom',reversed:true,gradient:'improvement-left',gradient_start:'#112233',gradient_end:'#ddeeff'});
if(type==='vas')Object.assign(e.properties,{min:-2.5,max:7.5,step:0.25,initial:2.5,labels:['Nada','Un\npoco','Moderado','Mucho','Extremo'],value_position:'above',value_font_size:72,label_font_size:36,bold_labels:false,label_boxes:true,label_alignment:35,gradient:'custom',gradient_start:'#112233',gradient_end:'#ddeeff'});
d.elements.push(e);});
fs.writeFileSync('tests/assessment-editor/parity-fixture.json',JSON.stringify(d,null,2)+'\n');
