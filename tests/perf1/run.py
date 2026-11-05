#!/usr/bin/env python3
import importlib.util,json,os,sys,gzip
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
if os.environ.get('EIPSI_PERF1')!='1':raise SystemExit('Explicit test opt-in required')
OUT=Path(os.environ['PERF1_OUTPUT']);OUT.mkdir(parents=True,exist_ok=True)
spec=importlib.util.spec_from_file_location('gate',ROOT/'tests/ci/gate.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Profile(m.Gate):
 def run(self,stage,command,**kwargs):
  if command[:2]==['docker','compose']:command=command[:6]+['-f',str(ROOT/'tests/ux-f0/profile-compose.yml')]+command[6:]
  return super().run(stage,command,**kwargs)
 def php_suites(self,call,php):
  call('profile-observer-grant',['exec','-T','db','mariadb','-uroot','-pm0-root-isolated','-e',"GRANT SELECT ON performance_schema.* TO 'm0'@'%'; UPDATE performance_schema.setup_consumers SET ENABLED='YES' WHERE NAME IN ('events_statements_current','global_instrumentation','thread_instrumentation','statements_digest');"])
  extra=['-e','EIPSI_PERF1=1','-e','PERF1_PLAIN='+os.environ.get('PERF1_PLAIN','0')]
  php('perf1-setup','/opt/eipsi-ci/tests/perf1/setup.php',extra)
  php('perf1-profile','/opt/eipsi-ci/tests/perf1/scenarios.php',extra)
  for name in ['results','profiles','crypto','checks','logical-snapshots']:
   raw=call('perf1-export-'+name,['exec','-T','wordpress','cat','/tmp/perf1-'+name+'.json']);json.loads(raw)
   if name=='profiles':
    with gzip.open(OUT/'method-profiles.json.gz','wt') as f:f.write(raw)
   else:(OUT/(name+'.json')).write_text(raw)
  raw=call('perf1-export-http',['exec','-T','wordpress','cat','/tmp/perf1-http.jsonl'])
  with gzip.open(OUT/'http-profiles.jsonl.gz','wt') as f:f.write(raw)
  raw=call('perf1-export-http-totals',['exec','-T','wordpress','cat','/tmp/ux-f0-server.jsonl']);(OUT/'http-totals.jsonl').write_text(raw)
g=Profile();code=0
try:g.wordpress('php',package_root=None if os.environ.get('PERF1_PLAIN')=='1' else ROOT/'.cache/perf1/instrumented-plugin')
except Exception:code=1;raise
finally:g.finish(code);(OUT/'run.json').write_text(json.dumps({'exit_code':code,'logs':str(g.logs)},indent=2))
