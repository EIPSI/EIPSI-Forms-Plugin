'use strict';
const fs=require('fs'),assert=require('assert'),yaml=require('js-yaml'),Ajv=require('ajv/dist/2020');
const spec=yaml.load(fs.readFileSync('openapi/eipsi-external-v1.yaml','utf8'));let cases=0;
function test(name,fn){fn();cases++;console.log('PASS '+name);}
const ajv=new Ajv({strict:false,allErrors:true});const schema={$id:'https://eipsi.invalid/api-v1',...spec.components.schemas};
for(const [name,value]of Object.entries(spec.components.schemas))ajv.addSchema({...value,$id:'https://eipsi.invalid/'+name,components:spec.components},'https://eipsi.invalid/'+name);
function validate(ref,body){const name=ref.split('/').pop();const v=ajv.getSchema('https://eipsi.invalid/'+name);assert(v(body),name+': '+JSON.stringify(v.errors));}
if(process.argv[2]){
 test('Observed HTTP status error-code headers and JSON schemas match OpenAPI',()=>{
  const samples=JSON.parse(fs.readFileSync(process.argv[2],'utf8'));assert(samples.length>=20);
  for(const s of samples){let path=s.path.replace(/\/studies\/[^/]+/,'/studies/{code}').replace(/\/exports\/[^/]+/,'/exports/{id}');let method=s.status===202?'post':'get';const op=spec.paths[path]?.[method];assert(op,'Undocumented '+path);const r=op.responses[String(s.status)];assert(r,'Undocumented HTTP '+s.status+' '+path);
   if(s.body!==null&&typeof s.body==='object'){const ref=r.content?.['application/json']?.schema?.$ref;if(ref)validate(ref,s.body);if(s.status>=400)assert(r['x-error-codes'].includes(s.body.code),'Undocumented error '+s.body.code+' '+path);}
   for(const h of ['cache-control','vary','x-request-id'])assert(s.headers[h],'Missing '+h);
  }
 });
}else{
 test('OpenAPI 3.1 document has required sections HTTP Bearer and global security',()=>{assert.equal(spec.openapi,'3.1.0');assert(spec.info.title&&spec.info.version);assert.deepEqual(spec.security,[{ApiKeyBearer:[]}]);assert.equal(spec.components.securitySchemes.ApiKeyBearer.scheme,'bearer');});
 test('Exactly eleven operations with unique IDs and no public security override',()=>{const ops=Object.values(spec.paths).flatMap(Object.values);assert.equal(ops.length,11);assert.equal(new Set(ops.map(o=>o.operationId)).size,11);for(const o of ops){assert(!o.security);assert(o.responses&&o.description);}});
 test('Every local schema reference resolves and every JSON schema compiles',()=>{function walk(v){if(!v||typeof v!=='object')return;if(v.$ref){assert(v.$ref.startsWith('#/components/schemas/'));assert(spec.components.schemas[v.$ref.split('/').pop()]);}for(const x of Object.values(v))walk(x);}walk(spec);for(const name of Object.keys(spec.components.schemas))assert(ajv.getSchema('https://eipsi.invalid/'+name));});
 test('Path parameters required and all query names unique with explicit types or enum',()=>{for(const [path,methods]of Object.entries(spec.paths))for(const o of Object.values(methods)){const names=new Set();for(const p of o.parameters){assert(!names.has(p.in+':'+p.name));names.add(p.in+':'+p.name);assert(p.schema.type||p.schema.enum);if(p.in==='path'){assert(p.required);assert(path.includes('{'+p.name+'}'));}}for(const name of [...path.matchAll(/\{([^}]+)\}/g)].map(m=>m[1]))assert(o.parameters.some(p=>p.in==='path'&&p.name===name));}});
 test('Errors describe actual WordPress data envelope and bounded scientific columns',()=>{assert.deepEqual(spec.components.schemas.Error.required,['code','message','data']);assert.equal(spec.components.schemas.Row.required.length,21);assert.equal(spec.components.schemas.Row.additionalProperties,false);assert(spec.paths['/exports/{id}/download'].get.responses['416']);});
 test('Documentation and examples contain no actual credential literals',()=>{for(const f of ['examples/python/eipsi_api.py','examples/R/eipsi_api.R','examples/curl/eipsi_api.sh','docs/external-api/README.md','docs/external-api/Data Contract.md','docs/external-api/Integration Guide.md','docs/external-api/External API Deployment Guide.md']){const s=fs.readFileSync(f,'utf8');assert(!/eipsi_[a-f0-9]{32}_[a-f0-9]{64}/.test(s),f);assert(!/Bearer [a-zA-Z0-9_-]{40,}/.test(s),f);}});
}
console.log(cases+' tests, 0 failures');
