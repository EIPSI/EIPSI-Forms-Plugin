<?php
/** EXPERIMENT ONLY. Explicit owner-carried context; never copied to the real source. */
final class Perf2Context {
 private $trace=array(),$boundaries=array(); private $mode,$rows=array(),$repos,$schema=array(),$transaction=0,$generation=0,$connection=null,$closed=false;
 public $counts=array('compiled_requested'=>0,'compiled_constructed'=>0,'hits'=>0,'misses'=>0,'mismatched_row_guard'=>0,'schema_requested'=>0,'physical_queries'=>0,'guard_hits'=>0,'guard_misses'=>0,'invalidations'=>0,'transaction_boundaries'=>0,'db_statements'=>0,'version_reads'=>0,'locking_reads'=>0);
 public function __construct(string $mode){$this->mode=in_array($mode,array('A1','B1'),true)?$mode:'baseline';$this->repos=new SplObjectStorage();}
 public function compiled(array $row,$repo,string $domain,callable $construct){
  if($this->closed)throw new LogicException('CONTEXT_CLOSED');$this->counts['compiled_requested']++;$this->repos->attach($repo);
  // Complete strict PHP row equality remains the authority; digest is only an index.
  $key=hash('sha256',serialize(array('PERF2_A1_V1','P1C_V1',$domain,$row)));
  if($this->mode==='A1'&&isset($this->rows[$key])){
   $e=$this->rows[$key];if($e['row']===$row){$this->counts['hits']++;return $e['compiled'];}$this->counts['mismatched_row_guard']++;
  }
  $this->counts['misses']++;$this->counts['compiled_constructed']++;$compiled=$construct();
  if($this->mode==='A1')$this->rows[$key]=array('row'=>$row,'compiled'=>$compiled);return $compiled;
 }
 public function invalidate():void{$this->schema=array();$this->transaction=0;$this->generation++;$this->counts['invalidations']++;}
 private function metadata(string $sql):bool{return strpos($sql,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME')===0;}
 public function beforeSql($connection,string $sql):?array{
  $id=spl_object_id($connection).':'.mysqli_thread_id($connection);if($this->connection!==$id){$this->invalidate();$this->connection=$id;}
  if($this->metadata($sql)){$this->counts['schema_requested']++;if($this->mode==='B1'&&$this->transaction&&isset($this->schema[$sql])){$this->counts['guard_hits']++;return $this->schema[$sql];}$this->counts['guard_misses']++;$this->counts['physical_queries']++;}
  if(class_exists('Perf2Entropy',false)&&Perf2Entropy::$active){$this->trace[]=$sql;if(preg_match('/^(START TRANSACTION|COMMIT|ROLLBACK)\b/i',$sql))$this->boundaries[]=$sql;}$this->counts['db_statements']++;if(strpos($sql,'study_versions` WHERE')!==false&&strpos($sql,'SELECT')===0)$this->counts['version_reads']++;if(stripos($sql,'FOR UPDATE')!==false)$this->counts['locking_reads']++;return null;
 }
 public function afterSql($connection,string $sql,array $rows):void{
  if(preg_match('/^START TRANSACTION\b/i',$sql)){$this->invalidate();$this->transaction=++$this->generation;$this->counts['transaction_boundaries']++;}
  elseif(preg_match('/^(COMMIT|ROLLBACK)\b/i',$sql)){$this->invalidate();$this->counts['transaction_boundaries']++;}
  elseif(preg_match('/^(ALTER|DROP|RENAME|CREATE|TRUNCATE|SET|LOCK|UNLOCK)\b/i',$sql))$this->invalidate();
  elseif($this->mode==='B1'&&$this->transaction&&$this->metadata($sql))$this->schema[$sql]=$rows;
 }
 public function stats():array{return $this->counts+array('repositories'=>count($this->repos),'variant'=>$this->mode);}
 public function close(string $scenario):void{if($this->closed)return;$this->closed=true;if($this->trace){file_put_contents('/tmp/perf2/sql-digest.json',json_encode(array('sql'=>hash('sha256',serialize($this->trace)),'boundaries'=>hash('sha256',serialize($this->boundaries)))));}$this->trace=$this->boundaries=array();if(getenv('WORDPRESS_DB_HOST')==='eipsi-m0-db:3306')file_put_contents('/tmp/perf2/context.jsonl',json_encode(array('scenario'=>$scenario,'request_scenario'=>$_SERVER['HTTP_X_UX_F0_SCENARIO']??'', 'repetition'=>$_SERVER['HTTP_X_UX_F0_REPETITION']??'')+$this->stats())."\n",FILE_APPEND|LOCK_EX);$this->rows=$this->schema=array();$this->repos=new SplObjectStorage();}
}
