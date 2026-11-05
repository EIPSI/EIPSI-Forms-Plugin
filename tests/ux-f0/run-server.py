#!/usr/bin/env python3
"""Only a new Gate-managed disposable Docker project. No main-site connection."""
import importlib.util,json,os,sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
if os.environ.get('EIPSI_UX_F0')!='1':raise SystemExit('Explicit profiling opt-in required')
OUT=Path(os.environ['UX_F0_OUTPUT']);OUT.mkdir(parents=True,exist_ok=True)
spec=importlib.util.spec_from_file_location('gate',ROOT/'tests/ci/gate.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Profile(m.Gate):
 def run(self,stage,command,**kwargs):
  if command[:2]==['docker','compose']:
   command=command[:6]+['-f',str(ROOT/'tests/ux-f0/profile-compose.yml')]+command[6:]
  return super().run(stage,command,**kwargs)
 def php_suites(self,call,php):
  extra=['-e','EIPSI_UX_F0=1']
  call('profile-observer-grant',['exec','-T','db','mariadb','-uroot','-pm0-root-isolated','-e',"GRANT SELECT ON performance_schema.* TO 'm0'@'%'; UPDATE performance_schema.setup_consumers SET ENABLED='YES' WHERE NAME IN ('events_statements_current','global_instrumentation','thread_instrumentation','statements_digest');"])
  php('profile-setup','/opt/eipsi-ci/tests/ux-f0/setup.php',extra)
  php('profile-server','/opt/eipsi-ci/tests/ux-f0/server.php',extra)
  for name in ['records','queries','payloads','assets']:
   raw=call('profile-export-'+name,['exec','-T','wordpress','cat','/tmp/ux-f0-'+name+'.json']);json.loads(raw);(OUT/('server-'+name+'.json')).write_text(raw)
  raw=call('profile-export-http',['exec','-T','wordpress','cat','/tmp/ux-f0-server.jsonl']);(OUT/'server-http.jsonl').write_text(raw)
g=Profile();code=0
try:g.wordpress('php')
except Exception:code=1;raise
finally:g.finish(code);(OUT/'server-run.json').write_text(json.dumps({'exit_code':code,'gate_logs':str(g.logs)},indent=2))
