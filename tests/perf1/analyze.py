#!/usr/bin/env python3
"""Aggregate sanitized method/SQL data. No runtime or source mutation."""
import csv,gzip,json,math,statistics,sys,collections,re
from pathlib import Path
OUT=Path(sys.argv[1]);ROOT=Path(__file__).resolve().parents[2]
def read(name,base=OUT):return json.loads((base/name).read_text())
def clean(value):
 if isinstance(value,str):return re.sub(r'@anonymous\x00.*?:[0-9]+\$[0-9a-f]+','@anonymous',value)
 if isinstance(value,list):return [clean(x) for x in value]
 if isinstance(value,dict):return {clean(k):clean(v) for k,v in value.items()}
 return value
def prof(name,lines=False):
 with gzip.open(OUT/name,'rt') as f:return clean([json.loads(x) for x in f] if lines else json.load(f))
def stats(values):
 v=sorted(values);p=lambda x:v[math.floor((len(v)-1)*x)]+(v[math.ceil((len(v)-1)*x)]-v[math.floor((len(v)-1)*x)])*(((len(v)-1)*x)%1)
 return {'N':len(v),'median':statistics.median(v),'p75':p(.75),'p95':p(.95) if len(v)>=20 else 'NA','min':min(v),'max':max(v)}
def write(name,rows,cols=None):
 with (OUT/name).open('w',newline='') as f:w=csv.DictWriter(f,fieldnames=cols or list(rows[0]),extrasaction='ignore');w.writeheader();w.writerows(rows)
def med(v):return statistics.median(v)
def classify(method):
 if 'StudyVersionRepository::compiled' in method:return 'REDUNDANT (repeated immutable reconstruction; not the DB read)'
 if any(k in method for k in ['Authorization::','SessionGuard::','CommandRepository::','SubmissionJournalRepository::','Metadata::','PausePolicy::']):return 'REQUIRED'
 if any(k in method for k in ['::schema','Store::ready','requireDurableTables','NativeBridge::complete']):return 'PROBABLY REQUIRED'
 if 'KeyringResolver::' in method:return 'PROBABLY REQUIRED (source policy); tiny repeated parse'
 return 'UNKNOWN (not a removal authorization)'
def qclass(q):
 if q['lock'] or q['operation'] in ['INSERT','UPDATE','DELETE','START','BEGIN','COMMIT','ROLLBACK']:return 'REQUIRED'
 if any(k in q['caller'] for k in ['Authorization::','Identity::recover','SessionGuard::']):return 'REQUIRED'
 return 'PROBABLY REQUIRED' if 'information_schema' in q['table'] or not q['same_context_repeats'] else 'UNKNOWN'
profiles={'P10':[x for x in prof('method-profiles.json.gz') if x['scenario']=='P10' and x['repetition']!='warmup'],'P3':[x for x in prof('http-profiles.jsonl.gz',True) if x['scenario']=='P3' and x['repetition']!='warmup']}
summary=[];methods_by_scenario={};queries=[];groupRows=[];txRows=[];contextRows=[]
for scenario,samples in profiles.items():
 assert len(samples)==20 and all(not x['trace_truncated'] for x in samples)
 keys=set().union(*(s['calls'] for s in samples));methods=[]
 for key in keys:
  v=[s['calls'].get(key,{'calls':0,'self_ns':0,'total_ns':0,'db_statements':0,'db_ns':0,'self_db_ns':0,'self_db_statements':0,'first_order':0,'transactions':[],'distinct_objects':0,'distinct_input_contexts':0}) for s in samples];method,site=key.rsplit('@',1)
  methods.append({'order':min(a['first_order'] for a in v),'component':method.rsplit('::',1)[0] if '::' in method else 'global/closure','method':method,'calls':med(a['calls'] for a in v),'self_ms':med(a['self_ns']/1e6 for a in v),'total_ms':med(a['total_ns']/1e6 for a in v),'db_statements':med(a['db_statements'] for a in v),'db_ms':med(a['db_ns']/1e6 for a in v),'transaction':','.join(map(str,sorted(set(t for a in v for t in a['transactions']),key=int))),'classification':classify(method),'self_php_ms':med(max(0,a['self_ns']-a['self_db_ns'])/1e6 for a in v),'source':site,'distinct_objects':med(a.get('distinct_objects',0) for a in v),'distinct_input_contexts':med(a.get('distinct_input_contexts',0) for a in v)})
 methods.sort(key=lambda r:r['order']);methods_by_scenario[scenario]=methods;write(scenario.lower()+'-callgraph.csv',methods)
 # Full edge counts include even tiny recursive helpers omitted only from representative sequence.
 edgekeys=set().union(*(s['edges'] for s in samples));write(scenario.lower()+'-edges.csv',[{'edge':key,'calls_per_request_median':med(s['edges'].get(key,0) for s in samples)} for key in sorted(edgekeys)])
 # Families combine equivalent callsites across transaction ordinals, while retaining lock distinction.
 grouped=collections.defaultdict(list);logicalGroups=collections.defaultdict(list)
 for s in samples:
  bykey={};bygroup={}
  for q in s['queries']:
   key=(q['owner'],q['caller'],q['repository'],q['operation'],q['table'],q['fingerprint'],q['lock']);dest=bykey.setdefault(key,dict(q,count=0,db_ms=0,same_context_repeats=0,post_write_or_tx_repeats=0,transactions=set()))
   for k in ['count','db_ms','same_context_repeats','post_write_or_tx_repeats']:dest[k]+=q[k]
   dest['transactions'].add(q['transaction']);g=(q['caller'],q['table']);v=bygroup.setdefault(g,{'count':0,'db_ms':0,'reads':0,'writes':0,'control':0})
   v['count']+=q['count'];v['db_ms']+=q['db_ms'];v['reads']+=q['count'] if q['operation'] in ['SELECT','SHOW','DESCRIBE','EXPLAIN'] else 0;v['writes']+=q['count'] if q['operation'] in ['INSERT','UPDATE','DELETE','REPLACE'] else 0;v['control']+=q['count'] if q['operation'] in ['START','BEGIN','COMMIT','ROLLBACK','SET'] else 0
  for key,v in bykey.items():grouped[key].append(v)
  for key,v in bygroup.items():logicalGroups[key].append(v)
  txs=collections.defaultdict(list)
  for q in s['queries']:txs[q['transaction']].append(q)
  if str(s['repetition'])=='1':
   for tx,qs in sorted(txs.items()):txRows.append({'scenario':scenario,'transaction':tx,'statements':sum(q['count'] for q in qs),'db_ms':sum(q['db_ms'] for q in qs),'lock_reads':sum(q['count'] for q in qs if q['lock']),'writes':sum(q['count'] for q in qs if q['operation'] in ['INSERT','UPDATE','DELETE','REPLACE']),'begin':sum(q['count'] for q in qs if q['operation'] in ['START','BEGIN']),'commit':sum(q['count'] for q in qs if q['operation']=='COMMIT'),'rollback':sum(q['count'] for q in qs if q['operation']=='ROLLBACK'),'paths':' | '.join(sorted(set(q.get('segment','') for q in qs)))})
   (OUT/(scenario.lower()+'-representative-trace.json')).write_text(json.dumps(sorted(s['sequence'],key=lambda e:e['order']),indent=2))
   for key,c in s['calls'].items():
    if 'StudyVersionRepository::compiled@' in key:contextRows.append({'scenario':scenario,'method':key,'calls':c['calls'],'distinct_full_row_contexts':c['distinct_input_contexts'],'distinct_objects':c.get('distinct_objects',0),'calls_per_object_histogram':c.get('object_call_histogram',[]),'context_call_counts':c.get('context_repetitions',[])})
 for key,vals in grouped.items():
  q=vals[0];queries.append({'scenario':scenario,'owner':q['owner'],'repository':q['repository'],'operation':q['operation'],'table':q['table'],'fingerprint':q['fingerprint'],'count':med([v['count'] for v in vals]+[0]*(len(samples)-len(vals))),'db_ms':med([v['db_ms'] for v in vals]+[0]*(len(samples)-len(vals))),'classification':qclass(q),'caller':q['caller'],'lock':q['lock'],'same_context_repeats':med([v['same_context_repeats'] for v in vals]+[0]*(len(samples)-len(vals))),'post_write_or_tx_repeats':med([v['post_write_or_tx_repeats'] for v in vals]+[0]*(len(samples)-len(vals))),'transactions':','.join(map(str,sorted(set(t for v in vals for t in v['transactions'])))),'N_present':len(vals)})
 for (caller,table),vals in logicalGroups.items():groupRows.append({'scenario':scenario,'caller':caller,'table':table,**{k:med([v[k] for v in vals]+[0]*(len(samples)-len(vals))) for k in ['count','db_ms','reads','writes','control']},'reason':'Safety/schema guard' if 'information_schema' in table else 'Observed persistence/read family; classification in query-families.csv'})
write('query-families.csv',queries);write('query-groups.csv',groupRows);write('transactions.csv',txRows);write('compiled-contexts.csv',contextRows)
# Distribution: source-original vs disabled probes vs enabled tracing. These are NOT optimizations.
original=read('results.json',OUT/'control-original');copy=read('results.json')
for scenario in ['P3','P10']:
 for label,items in [('original',original),('copy-probes-disabled',copy),('copy-trace-enabled',copy)]:
  phase='trace' if label.endswith('enabled') else 'control';a=[x for x in items if x['scenario']==scenario and x['phase']==phase and x['repetition']!='warmup'];summary.append({'scenario':scenario,'variant':label,**stats([x['total_ms'] for x in a])})
write('distributions.csv',summary)
crypto=read('crypto.json',OUT/'control-original');write('microbenchmarks.csv',[{'operation':kind,**stats([x['ms'] for x in crypto if x['operation']==kind and x['repetition']!='warmup'])} for kind in sorted(set(x['operation'] for x in crypto))])
# Guarded candidate estimates use uninstrumented isolated costs, never trace-inclusive time as a promised gain.
lookup={x['operation']:x for x in list(csv.DictReader((OUT/'microbenchmarks.csv').open()))}
base={s:next(x['median'] for x in summary if x['scenario']==s and x['variant']=='original') for s in ['P3','P10']}
candidates=[]
for s,allcalls,objects in [('P10',28,27),('P3',21,18)]:
 per=float(lookup['compiled-'+s]['median']);local=(allcalls-objects)*per;shared=(allcalls-1)*per
 candidates.append({'id':'C1-'+s,'scenario':s,'description':'Memo only on one repository instance','classification':'O4 — REJECT','baseline_cost':str(per)+' ms/reconstruction; '+str(allcalls)+' calls/'+str(objects)+' objects','expected_gain':f'upper bound {local:.3f} ms ({100*local/base[s]:.2f}%); 0 SQL avoided','scientific_risk':'Exact-row guard and bounded lifetime still required','concurrency_risk':'Object may outlive logical request in workers; no established request boundary','implemented':'no — low local benefit'})
 candidates.append({'id':'C2-'+s,'scenario':s,'description':'Shared explicit request-scoped immutable compiled graph','classification':'O3 — NEEDS ARCHITECTURAL CHANGE','baseline_cost':str(per)+' ms/reconstruction','expected_gain':f'optimistic CPU ceiling {shared:.3f} ms ({100*shared/base[s]:.2f}%); 0 SQL avoided; not an observed optimization','scientific_risk':'Need exact-row/contract guards, clone/immutability proof and response/DB equivalence of a concrete implementation','concurrency_risk':'Must preserve every repository read/lock, active screen overlay, retirement and cleanup on all failures','implemented':'no'})
for id,desc,cls,risk in [('C4','Remove second NativeBridge.complete','O4 — REJECT','Different post-commit context, current guards and idempotent recovery'),('C5','Merge notification synchronizations','O4 — REJECT','Different snapshots/revisions and first-access suppression/intents'),('C6','Reuse mutable execution/session/progress','O3 — NEEDS ARCHITECTURAL CHANGE','State changes, authorization revisions, conditions and transactional snapshots'),('C7','Memo keyring/crypto','O4 — REJECT','Measured constant-source parse/crypto tiny; do not weaken protections'),('C8','Reuse Runtime entryDefinition across calls','O4 — REJECT','Effective initial-screen overlay reads latest published UI; small local benefit, no general freshness proof'),('C9','Remove locks/receipts/journal or add index by count','O4 — REJECT','Necessary integrity protections; no specific plan/selectivity evidence')]:candidates.append({'id':id,'scenario':'P3/P10','description':desc,'classification':cls,'baseline_cost':'See method/query attribution','expected_gain':'No safe observed gain established','scientific_risk':risk,'concurrency_risk':risk,'implemented':'no'})
for scenario,samples in profiles.items():
 repeats=[sum(q['same_context_repeats'] for q in r['queries'] if q['transaction']>0 and 'information_schema' in q['table']) for r in samples]
 proxy=[sum(q['db_ms']*q['same_context_repeats']/q['count'] for q in r['queries'] if q['transaction']>0 and 'information_schema' in q['table']) for r in samples]
 n=med(repeats);db=med(proxy)
 candidates.append({'id':'C3-'+scenario,'scenario':scenario,'description':'Cache repeated readiness queries only with a proven transaction/DDL guard','classification':'O2 — SAFE WITH GUARD (guard not established)','baseline_cost':f'{n:.0f} exact metadata repetitions in same transaction/no own write, N=20','expected_gain':f'conditional ceiling {n:.0f} SQL; driver DB proxy {db:.3f} ms (~{100*db/base[scenario]:.2f}% original total); extra PHP CPU NA; estimated mean-per-family allocation, NOT measured speedup','scientific_risk':'Guard must cover every inspected table, connection, schema/engine change and integrity failure','concurrency_risk':'DDL and new transaction boundaries may invalidate a reused readiness result','implemented':'no — invariant not proven'})
write('candidates.csv',candidates)
print('PASS decomposition CSVs: all observed method edges + SQL families + transaction attribution')
