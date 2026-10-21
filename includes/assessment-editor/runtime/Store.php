<?php
namespace EIPSI\AssessmentEditor\Runtime;
require_once dirname(__DIR__).'/Definition.php';
require_once dirname(__DIR__,2).'/study-engine/persistence.php';
/** Native persistence owner uses the existing no-reconnect SQL driver, separate InnoDB tables. */
final class Store {
    public $db;
    public function __construct(){global $wpdb;$this->db=new \EIPSI\StudyEngine\Persistence\Database($wpdb);}
    public function prepare(string $sql,...$values): string {global $wpdb;return $wpdb->remove_placeholder_escape($this->db->prepare($sql,...$values));}
    public function table(string $name): string {if(!in_array($name,array('versions','executions','commands'),true))throw new \LogicException('Unknown native table');return $this->db->table('native_'.$name);}
    public function install(): void {
        if(!current_user_can('manage_options'))throw new \RuntimeException('Forbidden',403);
        global $wpdb;$collate=$wpdb->get_charset_collate();
        $this->db->query('CREATE TABLE IF NOT EXISTS '.$this->table('versions')." (id varchar(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, assessment_id varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, author_revision bigint unsigned NOT NULL, definition_json longtext NOT NULL, definition_hash char(64) CHARACTER SET ascii NOT NULL, state varchar(20) NOT NULL, actor_id bigint unsigned NOT NULL, created_at bigint unsigned NOT NULL, KEY assessment (assessment_id)) ENGINE=InnoDB $collate");
        $this->db->query('CREATE TABLE IF NOT EXISTS '.$this->table('executions')." (id varchar(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, version_id varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, binding_json text NOT NULL, revision bigint unsigned NOT NULL, state varchar(20) NOT NULL, page_index int unsigned NOT NULL, partial_json longtext NULL, partial_started_at bigint unsigned NULL, expires_at bigint unsigned NULL, final_json longtext NULL, metadata_json longtext NULL, created_at bigint unsigned NOT NULL, completed_at bigint unsigned NULL, KEY version (version_id), KEY expiry (expires_at)) ENGINE=InnoDB $collate");
        $this->db->query('CREATE TABLE IF NOT EXISTS '.$this->table('commands')." (execution_id varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, id varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, request_hash char(64) CHARACTER SET ascii NOT NULL, revision bigint unsigned NOT NULL, result_execution_id varchar(100) CHARACTER SET ascii COLLATE ascii_bin NULL, PRIMARY KEY (execution_id,id)) ENGINE=InnoDB $collate");
        require_once dirname(__DIR__,2).'/study-engine/management/Schema.php';\EIPSI\StudyEngine\Management\Schema::nativeIndex();
        $this->ready();
    }
    public function ready(): void {global $wpdb;$names=array();foreach(array('versions','executions','commands') as $n)$names[]=$this->prepare('%s',$wpdb->prefix.'eipsi_engine_native_'.$n);$rows=$this->db->rows('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.implode(',',$names).')');if(count($rows)!==3)throw new \RuntimeException('Native runtime not installed',409);foreach($rows as $r)if(strcasecmp($r['ENGINE'],'InnoDB')!==0)throw new \RuntimeException('Native InnoDB required',409);}
    /** Retry a complete rolled-back transaction, including MariaDB's concurrent-read 1020. */
    public function transaction(callable $work){
        $this->ready();if((int)$this->db->row('SELECT @@session.in_transaction AS active')['active']!==0)throw new \LogicException('Nested native transaction');
        for($attempt=0;$attempt<3;$attempt++){
            $this->db->query('START TRANSACTION');
            try{$result=$work();$this->db->query('COMMIT');return $result;}
            catch(\Throwable $e){$this->db->query('ROLLBACK');
                if($e instanceof \EIPSI\StudyEngine\Persistence\DatabaseFailure&&in_array((int)$e->getCode(),array(1020,1205,1213),true)){
                    if($attempt<2){usleep(20000*($attempt+1));continue;}
                    throw new \RuntimeException('Concurrent native update. Reload before continuing.',409,$e);
                }throw $e;
            }
        }throw new \LogicException('Unreachable native retry');
    }

    public function insert(string $table,array $row): void {if($table==='executions')unset($row['management_execution'],$row['management_opportunity']);$cols=array();$values=array();foreach($row as $k=>$v){if(!preg_match('/^[a-z_]+$/D',$k))throw new \LogicException('Column');$cols[]='`'.$k.'`';$values[]=$v===null?'NULL':$this->prepare('%s',(string)$v);}$this->db->query('INSERT INTO '.$this->table($table).' ('.implode(',',$cols).') VALUES ('.implode(',',$values).')');}
    public function update(string $table,array $row,string $where): int {$set=array();foreach($row as $k=>$v){if(!preg_match('/^[a-z_]+$/D',$k))throw new \LogicException('Column');$set[]='`'.$k.'`='.($v===null?'NULL':$this->prepare('%s',(string)$v));}return $this->db->query('UPDATE '.$this->table($table).' SET '.implode(',',$set).' WHERE '.$where);}
    public function find(string $table,string $id,bool $lock=false): ?array {\EIPSI\AssessmentEditor\Definition::id($id);return $this->db->row($this->prepare('SELECT * FROM '.$this->table($table).' WHERE id=%s'.($lock?' FOR UPDATE':''),$id));}
}
