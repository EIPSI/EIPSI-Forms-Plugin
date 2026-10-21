import importlib.util,pathlib,sys
root=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('ci_gate',root/'tests/ci/gate.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Target(m.Gate):
 def run(self,stage,args,*a,**kw):
  try:return super().run(stage,args,*a,**kw)
  except m.Failure:
   if stage.startswith('php-') and 'exec' in args:
    prefix=args[:args.index('exec')+1]+['-T','wordpress']
    try:super().run('failure-debug',prefix+['sh','-c','test ! -f /var/www/html/wp-content/debug.log || tail -n 80 /var/www/html/wp-content/debug.log'],*a,**kw)
    except Exception:pass
   raise
 def php_suites(self,call,php):
  self.count('longitudinal',php('longitudinal',m.PLUGIN+'/tests/run-study-engine-longitudinal.php'),181)
  self.count('study-engine-experience',php('study-engine-experience',m.PLUGIN+'/tests/run-study-engine-experience.php'),41)
  self.count('i18n1c',php('i18n1c',m.PLUGIN+'/tests/run-i18n1c.php'),22)
  for name in ['i18n1b','i18n1b-content','study-public-messages','data1','api4']:
   self.count(name,php(name,m.PLUGIN+'/tests/run-'+name+'.php'),m.CONFIG['php'][name])
g=Target();code=0
try:
 g.run('docker',['docker','info','--format','{{.ServerVersion}}']);g.dependencies();g.count('i18n1c-dom',g.node('i18n1c-dom',['node','tests/i18n1c/content-dom.js']),43);g.pure_php();
 for script in ['tests/i18n1d/runtime-dom.js','tests/i18n1b/catalog.js','tests/i18n1b/interface-dom.js','tests/assessment-editor/product-dom.js','tests/study-engine/builder/experience-dom.js','tests/study-engine/builder/email-templates-dom.js']:
  g.count(script,g.node(script.replace('/','-'),['node',script]),m.CONFIG['js'][script])
 g.wordpress('php')
except m.Failure as e:code=e.code
except Exception as e:print(type(e).__name__,str(e));code=1
finally:g.finish(code)
sys.exit(code)
