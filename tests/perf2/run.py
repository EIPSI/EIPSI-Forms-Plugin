import importlib.util,os,json
from pathlib import Path
R=Path(__file__).resolve().parents[2];O=Path(os.environ['PERF2_OUTPUT']);O.mkdir(parents=True,exist_ok=True)
if os.environ.get('EIPSI_PERF2')!='1':raise SystemExit('Explicit opt-in required')
s=importlib.util.spec_from_file_location('gate',R/'tests/ci/gate.py');m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
class Experiment(m.Gate):
 def run(self,stage,command,**kw):
  if command[:2]==['docker','compose']:command=command[:6]+['-f',str(R/'tests/ux-f0/profile-compose.yml')]+command[6:]
  return super().run(stage,command,**kw)
 def php_suites(self,call,php):
  call('observer',['exec','-T','db','mariadb','-uroot','-pm0-root-isolated','-e',"GRANT SELECT ON performance_schema.* TO 'm0'@'%'; UPDATE performance_schema.setup_consumers SET ENABLED='YES' WHERE NAME IN ('events_statements_current','global_instrumentation','thread_instrumentation','statements_digest');"])
  extra=['-e','EIPSI_PERF2=1','-e','PERF2_PLAIN='+os.environ.get('PERF2_PLAIN','0')]
  php('perf2-setup','/opt/eipsi-ci/tests/perf2/setup.php',extra)
  for test in (['equivalence'] if os.environ.get('PERF2_EQ_ONLY')=='1' else ['context-tests','ddl','guard-failures','equivalence','scenarios']):
   if test in ['context-tests','equivalence'] and os.environ.get('PERF2_PLAIN')=='1':continue
   php('perf2-'+test,'/opt/eipsi-ci/tests/perf2/'+test+'.php',extra)
  for name in ([] if os.environ.get('PERF2_EQ_ONLY')=='1' else ['measurements','checks','ddl','guard-failures','contexts'])+([] if os.environ.get('PERF2_PLAIN')=='1' else (['equivalence'] if os.environ.get('PERF2_EQ_ONLY')=='1' else ['equivalence','strategies'])):
   path='/tmp/perf2/context.jsonl' if name=='contexts' else '/tmp/perf2-'+name+'.json'
   raw=call('export-'+name,['exec','-T','wordpress','cat',path]);(O/(name+('.jsonl' if name=='contexts' else '.json'))).write_text(raw)
  raw=call('export-http',['exec','-T','wordpress','cat','/tmp/ux-f0-server.jsonl']);(O/'http.jsonl').write_text(raw)
g=Experiment();code=0
try:g.wordpress('php',package_root=None if os.environ.get('PERF2_PLAIN')=='1' else R/'.cache/perf2/plugin')
except Exception:code=1;raise
finally:g.finish(code);(O/'run.json').write_text(json.dumps({'exit_code':code,'logs':str(g.logs)},indent=2))
