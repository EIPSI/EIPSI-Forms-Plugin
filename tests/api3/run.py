#!/usr/bin/env python3
"""API-3 targeted gate using the permanent disposable CI infrastructure."""
import importlib.util,pathlib,sys
root=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('ci_gate',root/'tests/ci/gate.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Target(m.Gate):
 def php_suites(self,call,php):
  self.count('management',php('management',m.PLUGIN+'/tests/run-study-management.php'),m.CONFIG['php']['study-management'])
  self.count('api3',php('api3',m.PLUGIN+'/tests/run-api3.php'),m.CONFIG['php']['api3'])
  call('api3-performance',['exec','-T','wordpress','cat','/tmp/eipsi-api3-performance.json'])
g=Target();code=0
try:
 g.run('docker',['docker','info','--format','{{.ServerVersion}}']);g.dependencies();g.build();g.wordpress('php')
except m.Failure as e:code=e.code
except Exception as e:print(type(e).__name__,str(e));code=1
finally:g.finish(code)
sys.exit(code)
