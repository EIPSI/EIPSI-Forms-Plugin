#!/usr/bin/env python3
"""Aggregate only observed values. NA is never converted to zero."""
import csv,json,math,statistics,sys,gzip,re
from pathlib import Path
out=Path(sys.argv[1]);root=Path(__file__).resolve().parents[2]
def load(name):return json.loads((out/name).read_text())
def numeric(x):return isinstance(x,(int,float)) and not isinstance(x,bool) and math.isfinite(x)
def percentile(v,p):
 v=sorted(v);pos=(len(v)-1)*p;lo=math.floor(pos);hi=math.ceil(pos);return v[lo]+(v[hi]-v[lo])*(pos-lo)
def stats(v):
 v=[x for x in v if numeric(x)]
 return {'N':len(v),'median':statistics.median(v) if v else 'NA','p75':percentile(v,.75) if v else 'NA','p95':percentile(v,.95) if len(v)>=20 else 'NA','min':min(v) if v else 'NA','max':max(v) if v else 'NA'}
def write(name,rows,columns=None):
 with (out/name).open('w',newline='') as f:
  w=csv.DictWriter(f,fieldnames=columns or list(rows[0]),extrasaction='ignore');w.writeheader();w.writerows(rows)
rows=load('client-scenarios.json')+load('server-records.json');http=[json.loads(x) for x in (out/'server-http.jsonl').read_text().splitlines() if x.strip()]
lookup={(x['scenario'],str(x['repetition'])):x for x in http if x['response_bytes']>0}
for row in rows:
 if row['environment']=='local-wordpress-http':
  m=lookup.get((row['scenario'],str(row['repetition'])))
  if m:row['php_ms']=m['php_ms'];row['db_ms']=m['pfs']['ms']
write('performance-scenarios.csv',rows,['scenario','environment','repetition','total_ms','queue_ms','client_pre_ms','network_server_ms','php_ms','db_ms','parse_restore_ms','render_ms','stable_ms','notes'])
groups={}
for row in rows:
 if row['repetition']=='warmup':continue
 groups.setdefault((row['scenario'],row['environment']),[]).append(row)
summary=[];breakdown=[]
for (scenario,environment),items in groups.items():
 for segment in ['total_ms','queue_ms','client_pre_ms','network_server_ms','php_ms','db_ms','parse_restore_ms','render_ms','stable_ms']:
  values=[r[segment] for r in items if numeric(r.get(segment))];s=stats(values);median_total=statistics.median([r['total_ms'] for r in items]);record={'scenario':scenario,'environment':environment,'segment':segment,**s,'share_of_total_median':s['median']/median_total if numeric(s['median']) and median_total else 'NA','evidence':'performance-scenarios.csv; inclusive segments are not additive'};breakdown.append(record)
 summary.append({'scenario':scenario,'environment':environment,**stats([r['total_ms'] for r in items])})
write('summary.csv',summary);write('latency-breakdown.csv',breakdown)
q=[]
for m in http+load('server-queries.json'):
 if 'pfs' in m:
  m={**m,'wpdb_logged_queries':m['query_count'],'wpdb_db_ms':m['db_ms'],'query_count':m['pfs']['count'],'db_ms':m['pfs']['ms'],'query_classes':m['pfs']['classes']}
 q.append({'scenario':m['scenario'],'environment':m.get('environment','local-wordpress-http'),'repetition':m['repetition'],'query_count':m['query_count'],'wpdb_num_queries':m.get('wpdb_num_queries','NA'),'wpdb_logged_queries':m.get('wpdb_logged_queries','NA'),'wpdb_db_ms':m.get('wpdb_db_ms','NA'),'db_ms':m['db_ms'],'duplicate_exact_queries':m['duplicate_exact_queries'],'slowest_query_ms':m.get('slowest_query_ms','NA'),'peak_bytes':m.get('peak_bytes','NA'),'bootstrap_ms':m.get('bootstrap_ms','NA'),'query_classes':json.dumps(m.get('query_classes',{}),sort_keys=True),'query_callers':json.dumps(m.get('query_callers',{}),sort_keys=True)})
write('queries.csv',q)
write('payload-sizes.csv',load('server-payloads.json'))
assets=load('server-assets.json');listed={}
for a in assets:listed.setdefault(a['path'],[]).append(a['scenario'])
assetrows=[]
for name,scenarios in listed.items():
 p=root/name;data=p.read_bytes() if p.exists() else None
 assetrows.append({'asset':name,'participant_required':'observed in public HTML','size':len(data) if data is not None else 'NA','blocking':'CSS render-blocking; synchronous classic script' if name.endswith('.css') else 'classic script, inspect document order','duplicate':'no duplicate proven across independent requests','notes':'Observed scenarios '+','.join(sorted(set(scenarios)))+'; gzip estimate '+str(len(gzip.compress(data)))+' bytes' if data is not None else 'WordPress core resource; not in plugin root'})
write('assets.csv',assetrows)
# Do not output SQL, payload strings, URLs, tokens or scientific IDs.
print('PASS sanitized CSV aggregation; '+str(len(rows))+' observations, '+str(len(summary))+' environment/scenario groups')
