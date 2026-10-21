#!/usr/bin/env python3
"""I18N-1B targeted preference, interface and persisted-content regressions."""
import importlib.util,pathlib,sys
root=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('ci_gate',root/'tests/ci/gate.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Target(m.Gate):
 def php_suites(self,call,php):
  self.count('i18n1b-foundation',php('i18n1b-foundation',m.PLUGIN+'/tests/run-i18n1b.php'),11)
  self.count('i18n1b-content',php('i18n1b-content',m.PLUGIN+'/tests/run-i18n1b-content.php'),13)
g=Target();code=0
try:
 g.run('docker',['docker','info','--format','{{.ServerVersion}}']);g.dependencies()
 g.count('i18n1b-catalog',g.node('i18n1b-catalog',['node','tests/i18n1b/catalog.js']),32)
 g.count('i18n1b-interface',g.node('i18n1b-interface',['node','tests/i18n1b/interface-dom.js']),37)
 g.wordpress('php')
except m.Failure as e:code=e.code
except Exception as e:print(type(e).__name__,str(e));code=1
finally:g.finish(code)
sys.exit(code)
