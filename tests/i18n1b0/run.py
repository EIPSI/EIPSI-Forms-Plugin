#!/usr/bin/env python3
"""I18N-1B0 targeted owner regressions in disposable CI WordPress."""
import importlib.util,pathlib,sys
root=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('ci_gate',root/'tests/ci/gate.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Target(m.Gate):
 def php_suites(self,call,php):
  for suite in ['study-engine-content-unit','study-engine-builder','assessment-functional','email-templates','study-engine-longitudinal','study-engine-privacy']:
   self.count(suite,php(suite,m.PLUGIN+'/tests/run-'+suite+'.php'),m.CONFIG['php'][suite])
g=Target();code=0
try:
 g.run('docker',['docker','info','--format','{{.ServerVersion}}']);g.dependencies()
 for script in ['tests/i18n1b0/presentation-dom.js','tests/study-engine/builder/redesign-dom.js','tests/study-engine/builder/email-templates-dom.js','tests/study-engine/builder/trajectory-dom.js','tests/study-engine/builder/experience-dom.js','tests/assessment-editor/product-dom.js']:
  g.count(script,g.node(pathlib.Path(script).stem,['node',script]),m.CONFIG['js'][script])
 g.build();g.wordpress('php')
except m.Failure as e:code=e.code
except Exception as e:print(type(e).__name__,str(e));code=1
finally:g.finish(code)
sys.exit(code)
