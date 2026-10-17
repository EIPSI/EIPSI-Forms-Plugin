<?php
namespace EIPSI\StudyEngine\Persistence;
use EIPSI\StudyEngine\Contracts\Canonical;

final class DatabaseFailure extends \RuntimeException {
    public function __construct(int $errno) { parent::__construct('STUDY_ENGINE_DATABASE_ERROR',$errno); }
}
final class IntegrityFailure extends \RuntimeException {}
final class PersistenceConflict extends \DomainException {}

/** One wpdb connection; repositories join this transaction and cannot commit it. */
final class Database {
    private $wpdb;
    private $active=false;
    private $lastRows=array();
    public function __construct($wpdb) {
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$wpdb->prefix))throw new \InvalidArgumentException('UNSAFE_TABLE_PREFIX');
        $this->wpdb=$wpdb;
    }
    public function table(string $name): string { return '`'.$this->wpdb->prefix.'eipsi_engine_'.$name.'`'; }
    public function prepare(string $sql,...$values): string { return $this->wpdb->prepare($sql,$values); }
    public function query(string $sql): int {
        if($this->active&&preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE|START|BEGIN)\b/i',$sql))throw new \LogicException('IMPLICIT_COMMIT_REJECTED');
        // wpdb::query can reconnect/retry after 2006. That is unsafe midway through
        // a transition: use its connection directly, with no automatic reconnect.
        $this->lastRows=array();$this->wpdb->num_queries++;
        try {
            $result=mysqli_query($this->wpdb->dbh,$sql);
            if($result===false)throw new DatabaseFailure(mysqli_errno($this->wpdb->dbh));
            if($result instanceof \mysqli_result){$this->lastRows=$result->fetch_all(MYSQLI_ASSOC);$n=count($this->lastRows);$result->free();return $n;}
            return mysqli_affected_rows($this->wpdb->dbh);
        }catch(\mysqli_sql_exception $e){throw new DatabaseFailure((int)$e->getCode());}
    }
    public function rows(string $sql): array { $this->query($sql);return $this->lastRows; }
    public function row(string $sql): ?array { $rows=$this->rows($sql);return $rows[0]??null; }
    public function insert(string $table,array $row): void {
        $this->requireTransaction();$cols=array();$values=array();
        foreach($row as $key=>$value){if(!preg_match('/^[a-z_]+$/D',$key))throw new \InvalidArgumentException('UNSAFE_COLUMN');$cols[]='`'.$key.'`';$values[]=$value===null?'NULL':$this->prepare('%s',(string)$value);}
        $this->query('INSERT INTO '.$this->table($table).' ('.implode(',',$cols).') VALUES ('.implode(',',$values).')');
    }
    public function update(string $table,array $row,string $where): int {
        $this->requireTransaction();$set=array();foreach($row as $key=>$value){if(!preg_match('/^[a-z_]+$/D',$key))throw new \InvalidArgumentException('UNSAFE_COLUMN');$set[]='`'.$key.'`='.($value===null?'NULL':$this->prepare('%s',(string)$value));}
        return $this->query('UPDATE '.$this->table($table).' SET '.implode(',',$set).' WHERE '.$where);
    }
    public function requireTransaction(): void { if(!$this->active)throw new \LogicException('OWNER_TRANSACTION_REQUIRED'); }
    /** Trusted Native owner composition: join, never commit, an already-open SQL transaction. */
    public function joinNativeTransaction(callable $work) {
        if($this->active||(int)($this->row('SELECT @@session.in_transaction AS active')['active']??0)!==1)throw new \LogicException('NATIVE_TRANSACTION_REQUIRED');
        $this->requireDurableTables();$this->active=true;try{return $work();}finally{$this->active=false;}
    }
    public function transactional(callable $work) {
        if($this->active||(int)($this->row('SELECT @@session.in_transaction AS active')['active']??1)!==0)throw new \LogicException('NESTED_TRANSACTION_REJECTED');
        $this->requireDurableTables();
        for($attempt=0;$attempt<3;$attempt++) {
            $this->query('START TRANSACTION');$this->active=true;
            try{$result=$work();$this->query('COMMIT');$this->active=false;return $result;}
            catch(\Throwable $e){try{$this->query('ROLLBACK');}finally{$this->active=false;}
                if($e instanceof DatabaseFailure&&in_array($e->getCode(),array(1205,1213),true)&&$attempt<2){usleep(20000*($attempt+1));continue;}throw $e;}
        }
        throw new \LogicException('UNREACHABLE_RETRY');
    }
    private function requireDurableTables(): void {
        $names=array();foreach(array('study_versions','study_executions','block_executions','execution_events','random_decisions','execution_commands','withdrawal_records','notification_intents','submission_journal') as $name)$names[]=$this->prepare('%s',$this->wpdb->prefix.'eipsi_engine_'.$name);
        $tables=$this->rows('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.implode(',',$names).')');
        if(count($tables)!==9)throw new IntegrityFailure('DURABLE_SCHEMA_UNAVAILABLE');
        foreach($tables as $table)if(strcasecmp($table['ENGINE'],'InnoDB')!==0)throw new IntegrityFailure('TRANSACTIONAL_ENGINE_REQUIRED');
    }
    public static function json(string $json): array {
        try{$data=json_decode($json,true,512,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new IntegrityFailure('INVALID_DURABLE_JSON');}
        if(!is_array($data)||Canonical::json($data)!==$json)throw new IntegrityFailure('NONCANONICAL_DURABLE_JSON');return $data;
    }
    public static function date(?string $utc): ?string {return $utc===null?null:str_replace(array('T','Z'),array(' ',''),$utc);}
    public static function now(): string {return gmdate('Y-m-d H:i:s');}
}
/** No flags/options. Tests provide an observer subclass; default has no side effects. */
class TransitionObserver { public function reached(string $point): void {} }

/** Optional same-transaction content storage adapter; no network or transaction ownership. */
interface FinalResponseSink {
    public function store(Database $db,string $execution,array $instance,array $responses,string $versionId,array $snapshot): string;
}
