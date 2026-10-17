(function(B){
    'use strict';
    B.longitudinalErrorMessages=new Set(['conflict','change_unconfirmed','email_collision','invalid_fields']);
    B.publicMessageField=(app,key)=>{
        const field=B.publicMessageCatalog.find(f=>f.key===key),stored=app.draft.protocol_metadata.public_messages||{};
        return B.field(field.label,stored[key]??(key==='finished_title'?app.draft.protocol_metadata.completion_title:key==='finished_body'?app.draft.protocol_metadata.completion_message:undefined)??field.default,value=>app.mutate(d=>{d.protocol_metadata.public_messages={...d.protocol_metadata.public_messages,[key]:value};return d;}),'text',{'data-public-message':key,maxlength:4000});
    };
    B.publicMessagesEditor=(app,groups,visible=()=>true)=>{
        const host=B.el('div',{class:'sb-public-messages'});
        for(const group of groups){const fields=B.publicMessageCatalog.filter(f=>f.group===group&&!f.retired&&visible(f));if(!fields.length)continue;const box=B.propertyGroup(fields[0].section);
            for(const field of fields)box.append(B.publicMessageField(app,field.key));host.append(box);
        }return host;
    };
})(window.EIPSIBuilder);
