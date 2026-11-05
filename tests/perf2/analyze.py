from pathlib import Path
import json,csv,statistics,math,hashlib
O=Path('/home/m2u/eipsi/wp-eco/informes/EIPSI-Forms/PERF-2-evidence')
def read(n,base=O):return json.loads((base/n).read_text())
def csvout(name,rows):
 with (O/name).open('w') as f:
  w=csv.DictWriter(f,fieldnames=list(rows[0]));w.writeheader();w.writerows(rows)
allrows=[]
for folder in ['control-original','experiment']:
 base=O/folder;http={}
 for line in (base/'http.jsonl').read_text().splitlines():
  r=json.loads(line);http[(r['scenario'],str(r['repetition']))]=r
 for r in read('measurements.json',base):
  if r['repetition']=='warmup':continue
  ctx=r.get('context',{});ctx=ctx if isinstance(ctx,dict) else {};row={**r};row.pop('context',None)
  if r['scenario']=='P3':
   label='P3-'+({'A1':'alpha','B1':'beta'}.get(r['variant'],r['variant'].lower()));h=http[(label,str(r['repetition']))]
   row.update(php_ms=h['php_ms'],db_ms=h['pfs']['ms'],db_statements=h['pfs']['count'])
  row.update({k:v for k,v in ctx.items() if k not in ['scenario','variant','repetition','request_scenario','db_statements']});row['owner_direct_statements']=ctx.get('db_statements','NA');row['scenario']=r['scenario'];row['variant']=r['variant'];row['repetition']=r['repetition'];allrows.append(row)
A=[];B=[]
for r in allrows:
 A.append({k:r.get(k,'NA') for k in ['scenario','variant','repetition','compiled_requested','compiled_constructed','hits','misses','mismatched_row_guard','repositories','version_reads','locking_reads','transaction_boundaries','total_ms','php_ms','db_ms','db_statements','owner_direct_statements']})
 B.append({k:r.get(k,'NA') for k in ['scenario','variant','repetition','schema_requested','physical_queries','guard_hits','guard_misses','invalidations','transaction_boundaries','total_ms','php_ms','db_ms','db_statements','owner_direct_statements']})
csvout('candidate-a.csv',A);csvout('candidate-b.csv',B)
dist=[]
for scenario in ['P3','P10']:
 for variant in ['original','baseline','A1','B1']:
  rows=[r for r in allrows if r['scenario']==scenario and r['variant']==variant]
  assert len(rows)==20
  for metric in ['total_ms','php_ms','db_ms','db_statements']:
   nums=sorted(r[metric] for r in rows);dist.append(dict(scenario=scenario,variant=variant,metric=metric,N=20,median=statistics.median(nums),p75=nums[math.ceil(.75*20)-1],p95_exploratory=nums[math.ceil(.95*20)-1],minimum=nums[0],maximum=nums[-1]))
csvout('distributions.csv',dist)
pairs=[]
for scenario in ['P3','P10']:
 for variant in ['A1','B1']:
  for rep in range(1,21):
   control=next(r for r in allrows if r['scenario']==scenario and r['variant']=='baseline' and r['repetition']==rep);r=next(r for r in allrows if r['scenario']==scenario and r['variant']==variant and r['repetition']==rep)
   pairs.append(dict(scenario=scenario,variant=variant,repetition=rep,total_ms_saved=control['total_ms']-r['total_ms'],php_minus_db_ms_saved=(control['php_ms']-control['db_ms'])-(r['php_ms']-r['db_ms'])))
csvout('paired-deltas.csv',pairs)
checks=[]
for folder in ['control-original','experiment']:
 for c in read('checks.json',O/folder):checks.append(dict(cohort=folder,**c))
(O/'concurrency.json').write_text(json.dumps({'checks':[c for c in checks if any(k in c['case'] for k in ['concurrent','CAS','enrollment race','same next'])],'tabs':'Different commands with same revision use two independent PHP processes/DB connections. Duplicate uses same command on both. No browser two-tab UI claim.'},indent=2))
(O/'failure-paths.json').write_text(json.dumps({'candidate_a_guard':[read('guard-failures.json',O/f) for f in ['control-original','experiment']],'owner_checks':[c for c in checks if any(k in c['case'] for k in ['rollback','expired','stale revision','reused command'])],'ddl_checks':read('ddl.json',O/'experiment'),'injection':'DatabaseFailure(2013) injected before native/enrollment commit with observer trigger asserted. Physical mysqli closure and server KILL are separate actual connection tests. Not a claim of TCP failure mid-write.'},indent=2))
eq=read('equivalence.json',O/'experiment');eq.update({'candidate_a_failure_equivalence':False,'candidate_b_failure_equivalence':False,'original_source_untouched':True,'decision':'Outcome C — A-REJECT-RISK / B-REJECT-RISK','checks_all_pass':all(c['pass'] for c in checks),'functional_checks_count':len(checks),'explanation':'Happy-path exact equality is necessary, but does not override demonstrated failure/DDL counterexamples. Rejected prototypes never enter product.'})
(O/'equivalence.json').write_text(json.dumps(eq,indent=2))
print(json.dumps({'checks':len(checks),'measurements':len(allrows),'decisions':['A-REJECT-RISK','B-REJECT-RISK']}))
