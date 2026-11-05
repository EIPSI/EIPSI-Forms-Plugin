from pathlib import Path
import importlib.util,shutil,json,hashlib
R=Path(__file__).resolve().parents[2];D=R/'.cache/perf2/plugin'
if D.exists():shutil.rmtree(D)
D.mkdir(parents=True)
s=importlib.util.spec_from_file_location('pack',R/'scripts/package-plugin.py');p=importlib.util.module_from_spec(s);s.loader.exec_module(p)
for n in p.RUNTIME_DIRS:shutil.copytree(R/n,D/n,dirs_exist_ok=True)
for n in p.ROOT_FILES+p.EXTRA_FILES:(D/n).parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(R/n,D/n)
for n in p.EXCLUDED_UNUSED:
 if (D/n).exists():(D/n).unlink()
def edit(n,f):
 q=D/n;q.write_text(f(q.read_text()))
shutil.copyfile(R/'tests/perf2/Context.php',D/'includes/study-engine/persistence/Perf2Context.php')
def database(s):
 s=s.replace('private $wpdb;','public $perf2; private $wpdb;')
 s=s.replace('public function __construct($wpdb) {','public function __construct($wpdb,?\\Perf2Context $context=null) { $this->perf2=$context??new \\Perf2Context("baseline");')
 s=s.replace('$this->wpdb=$wpdb;','$this->wpdb=$wpdb;')
 s=s.replace('public function table(string', 'public function perf2Domain(): string {return spl_object_id($this->wpdb->dbh).":".mysqli_thread_id($this->wpdb->dbh).":".$this->wpdb->prefix;}\n    public function perf2Fork(\\Perf2Context $context): self { return new self($this->wpdb,$context); }\n    public function table(string')
 # Count all direct physical SQL; bounded exact-SQL B1 prototype only.
 s=s.replace('$this->lastRows=array();$this->wpdb->num_queries++;','$cached=$this->perf2->beforeSql($this->wpdb->dbh,$sql);if($cached!==null){$this->lastRows=$cached;return count($cached);}$this->lastRows=array();$this->wpdb->num_queries++;')
 s=s.replace('$result->free();return $n;','$result->free();$this->perf2->afterSql($this->wpdb->dbh,$sql,$this->lastRows);return $n;')
 s=s.replace('return mysqli_affected_rows($this->wpdb->dbh);','$this->perf2->afterSql($this->wpdb->dbh,$sql,array());return mysqli_affected_rows($this->wpdb->dbh);')
 s=s.replace('}catch(\\mysqli_sql_exception $e){throw new DatabaseFailure', '}catch(\\mysqli_sql_exception $e){$this->perf2->invalidate();throw new DatabaseFailure')
 return s.replace('namespace EIPSI\\StudyEngine\\Persistence;','namespace EIPSI\\StudyEngine\\Persistence;\nrequire_once __DIR__."/Perf2Context.php";')
edit('includes/study-engine/persistence/Database.php',database)
def repo(s):
 s=s.replace('public function compiled(array $row): CompiledStudy {','public function compiled(array $row): CompiledStudy { return $this->db->perf2->compiled($row,$this,$this->db->perf2Domain().$this->db->table("study_versions"),function()use($row){return $this->perf2Construct($row);}); }\n    private function perf2Construct(array $row): CompiledStudy {')
 return s
edit('includes/study-engine/persistence/StudyVersionRepository.php',repo)
edit('includes/assessment-editor/runtime/Store.php',lambda s:s.replace('public function __construct(){global $wpdb;$this->db=new \\EIPSI\\StudyEngine\\Persistence\\Database($wpdb);}','public function __construct(?\\EIPSI\\StudyEngine\\Persistence\\Database $db=null){global $wpdb;$this->db=$db??new \\EIPSI\\StudyEngine\\Persistence\\Database($wpdb);}'))
def owner(s):
 old='public function command(string $id,array $request,bool $participant=false): array {'
 new='''public function command(string $id,array $request,bool $participant=false): array {
        $previous=$this->store;$context=new \\Perf2Context(getenv('PERF2_VARIANT')?:'baseline');$this->store=new Store($previous->db->perf2Fork($context));
        try{return $this->perf2Command($id,$request,$participant);}finally{$this->store=$previous;$context->close('P10');}
    }
    private function perf2Command(string $id,array $request,bool $participant=false): array {'''
 s=s.replace(old,new)
 import re
 s=re.sub(r'NativeBridge::(complete|continuation|progress)\((\$[^;]*?)\)',r'NativeBridge::\1(\2,$this->store->db->perf2)',s)
 return s
edit('includes/assessment-editor/runtime/Owner.php',owner)
def bridge(s):
 s=s.replace('completedOffer(array $frame):','completedOffer(array $frame,?\\Perf2Context $context=null):')
 s=s.replace('offer(array $frame):','offer(array $frame,?\\Perf2Context $context=null):')
 s=s.replace('continuation(string $native):','continuation(string $native,?\\Perf2Context $context=null):')
 s=s.replace('progress(string $native): void {self::complete($native,true);}','progress(string $native,?\\Perf2Context $context=null): void {self::complete($native,true,$context);}')
 # complete's optional bool must be preserved at callsites, see owner adjustment below
 s=s.replace('complete(string $native,bool $startOnly=false):','complete(string $native,bool $startOnly=false,?\\Perf2Context $context=null):')
 s=s.replace('$db=new P\\Database($wpdb);','$db=new P\\Database($wpdb,$context??($frame["_perf2_context"]??null));')
 s=s.replace('new N\\Store()','new N\\Store((function()use($context){global $wpdb;return new P\\Database($wpdb,$context);})())')
 s=s.replace('(new N\\Owner())->studyAssignment','(new N\\Owner(new N\\Store($db)))->studyAssignment')
 s=s.replace('self::offer(array(', 'self::offer(array(').replace("'view'=>$view));if($next", "'view'=>$view),$context);if($next")
 # observer captures its explicit context, never a global
 s=s.replace('new class($binding)', 'new class($binding,$context)').replace('private $binding;public function __construct(array $binding){$this->binding=$binding;}', 'private $binding;private $context;public function __construct(array $binding,$context){$this->binding=$binding;$this->context=$context;}').replace('function()use($context){global $wpdb;', 'function()use($context){global $wpdb;')
 # anonymous observer method has no local context
 s=s.replace("N\\Authorization::check(new N\\Store((function()use($context){global $wpdb;return new P\\Database($wpdb,$context);})()),$this->binding)","N\\Authorization::check(new N\\Store((function(){global $wpdb;return new P\\Database($wpdb,$this->context);})()),$this->binding)")
 return s
edit('includes/study-engine/runtime/NativeBridge.php',bridge)
edit('includes/assessment-editor/runtime/Owner.php',lambda s:s.replace('NativeBridge::complete($id,$this->store->db->perf2)','NativeBridge::complete($id,false,$this->store->db->perf2)').replace("NativeBridge::complete($frame['execution_id'],$this->store->db->perf2)","NativeBridge::complete($frame['execution_id'],false,$this->store->db->perf2)"))
def route(s):
 s=s.replace('$db=new \\EIPSI\\StudyEngine\\Persistence\\Database($wpdb);','$perf2=new \\Perf2Context($_SERVER["HTTP_X_PERF2_VARIANT"]??"baseline");register_shutdown_function(static function()use($perf2){$perf2->close("P3");});$db=new \\EIPSI\\StudyEngine\\Persistence\\Database($wpdb,$perf2);',1)
 s=s.replace('try{$html=$frame!==null?', 'if($frame!==null)$frame["_perf2_context"]=$perf2??null;try{$html=$frame!==null?')
 return s
edit('includes/study-engine/runtime/route.php',route)
edit('includes/study-engine/content/AssessmentRenderer.php',lambda s:s.replace('NativeBridge::offer($frame)','NativeBridge::offer($frame,$frame["_perf2_context"]??null)'))
edit('includes/study-engine/longitudinal/EmailTemplate.php',lambda s:s.replace('new \\EIPSI\\StudyEngine\\Persistence\\Database($wpdb),$this->definition','new \\EIPSI\\StudyEngine\\Persistence\\Database($wpdb,$frame["_perf2_context"]??null),$this->definition'))
print(D)
