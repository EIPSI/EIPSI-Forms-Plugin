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
  self.count('i18n1c',php('i18n1c',m.PLUGIN+'/tests/run-i18n1c.php'),22)
g=Target();code=0
try:
 g.run('docker',['docker','info','--format','{{.ServerVersion}}']);g.dependencies();g.count('i18n1c-parity',g.run('i18n1c-parity',['docker','run','--rm','--network','none','--entrypoint','php','-v',str(root)+':/app:ro','-w','/app',m.CONFIG['images']['wordpress'],'tests/i18n1c/parity.php']),26);g.count('i18n1c-dom',g.node('i18n1c-dom',['node','tests/i18n1c/content-dom.js']),43);g.wordpress('php')
except m.Failure as e:code=e.code
except Exception as e:print(type(e).__name__,str(e));code=1
finally:g.finish(code)
sys.exit(code)
