(function(root){
  'use strict';
  const copy=x=>JSON.parse(JSON.stringify(x));
  const valid=language=>{if(!['en','es'].includes(language))throw Error('INVALID_CONTENT_LANGUAGE');return language;};
  const language=object=>{const c=object?.protocol_metadata||object||{};return Object.hasOwn(c,'content_language')?(['en','es'].includes(c.content_language)?c.content_language:'en'):'es';};
  const get=(section,lang='es')=>copy(root.EIPSIContentCatalog[valid(lang)][section]);
  const question=(type,lang)=>{
    const c=root.EIPSIContentCatalog[valid(lang)],m=c.question_messages,p=copy(c.question_defaults[type]||{});
    if(c.question_defaults[type]){
      p.required_message=m.required_message;
      if(['text','textarea','email','tel','url'].includes(type))p.length_message=m.length_message;
      if(['text','email','tel','url','number'].includes(type))p.validation_message=c.validation[type==='tel'?'text':type];
      if(type==='number')p.number_range_message=m.number_range_message;
      if(type==='multiple_choice')for(const key of ['selection_min_message','selection_max_message'])p[key]=m[key];
      if(['date','vas','single_choice','multiple_choice','select','likert','matrix'].includes(type))p.invalid_value_message=c.validation[type]??m.invalid_value_message;
      if(type==='date')for(const key in m)if(key.startsWith('date_'))p[key]=m[key];
      if(type==='matrix')p.matrix_heading=m.matrix_heading;
      if(type==='vas')p.unanswered_value_message=m.unanswered_value_message;
    }
    if(type==='image')p.empty_image_message=m.empty_image_message;return p;
  };
  root.EIPSIContent={copy,valid,language,get,question};
})(typeof window!=='undefined'?window:globalThis);
