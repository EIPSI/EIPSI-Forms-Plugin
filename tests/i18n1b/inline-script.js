'use strict';
/** DOM fixtures render only literal PHP translation expressions; unknown PHP fails loudly. */
module.exports = source => {
    const rendered=source.replace(/<\?php\s+echo\s+wp_json_encode\(eipsi_admin_translate\(('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")\)\);\s*\?>/g,(_,literal)=>{
        const english=literal[0]==='"'?JSON.parse(literal):literal.slice(1,-1).replace(/\\(['\\])/g,'$1');
        return 'window.EIPSIAdminI18n.t('+JSON.stringify(english)+')';
    });
    if(rendered.includes('<?'))throw new Error('Unrendered PHP in administrative DOM fixture');
    return rendered;
};
