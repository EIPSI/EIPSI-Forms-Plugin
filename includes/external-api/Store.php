<?php
namespace EIPSI\ExternalApi;

final class ApiError extends \RuntimeException {
    public $apiCode;public $responseHeaders;
    public function __construct(string $code,int $status,array $responseHeaders=array()){$this->responseHeaders=$responseHeaders;$this->apiCode=$code;parent::__construct($code,$status);}
}

/** No scientific owner, WordPress identity, or raw credentials cross this boundary. */
final class Store {
    const ENABLED='eipsi_external_api_enabled';
    private $db;private $readViewAt;
    public function __construct($db=null){global $wpdb;$this->db=$db??$wpdb;if(!preg_match('/^[A-Za-z0-9_]+$/D',$this->db->prefix))throw new \LogicException('Invalid prefix');}
    public function table(string $name): string {
        if(!in_array($name,array('keys','grants','audit','exports','export_sources','export_chunks','metrics'),true))throw new \LogicException('Invalid table');
        return '`'.$this->db->prefix.'eipsi_external_'.$name.'`';
    }
    public function prepare(string $sql,...$values): string {return $this->db->remove_placeholder_escape($this->db->prepare($sql,$values));}
    public function rows(string $sql): array {
        // No wpdb reconnection/retry halfway through an authorization transaction.
        try{$this->db->num_queries++;$r=mysqli_query($this->db->dbh,$sql);if($r===false)throw new \RuntimeException();
            if($r instanceof \mysqli_result){$rows=$r->fetch_all(MYSQLI_ASSOC);$r->free();return $rows;}return array();
        }catch(\Throwable $e){throw new ApiError('eipsi_external_unavailable',503);}
    }
    public function row(string $sql): ?array {return $this->rows($sql)[0]??null;}
    public function transaction(callable $work){
        if((int)($this->row('SELECT @@session.in_transaction active')['active']??1)!==0)throw new \LogicException('Nested external transaction');
        $this->rows('START TRANSACTION');
        try{$r=$work();$this->rows('COMMIT');return $r;}catch(\Throwable $e){$this->rows('ROLLBACK');throw $e;}
    }
    public function enabled(bool $sharedLock=false): bool {
        $row=$this->row($this->prepare("SELECT option_value FROM `{$this->db->options}` WHERE option_name=%s".($sharedLock?' LOCK IN SHARE MODE':''),self::ENABLED));
        return $row&&$row['option_value']==='1'; // Read DB, never a positive object-cache authorization.
    }
    public function setEnabled(bool $enabled,int $actor,string $request,int $now): void {
        add_option(self::ENABLED,'0','',false);
        $this->transaction(function()use($enabled,$actor,$request,$now){
            $old=$this->row($this->prepare("SELECT option_value FROM `{$this->db->options}` WHERE option_name=%s FOR UPDATE",self::ENABLED));
            if(($old['option_value']??'0')===($enabled?'1':'0'))return;
            $this->rows($this->prepare("UPDATE `{$this->db->options}` SET option_value=%s WHERE option_name=%s",$enabled?'1':'0',self::ENABLED));
            $this->audit($enabled?'ENABLED':'DISABLED','',$actor,$request,$now);
        });wp_cache_delete(self::ENABLED,'options');wp_cache_delete('alloptions','options');
    }
    public function key(string $id,bool $lock=false): ?array {return $this->row($this->prepare('SELECT * FROM '.$this->table('keys').' WHERE id=%s'.($lock?' FOR UPDATE':''),$id));}
    public function grants(string $id): array {
        // This first nonlocking InnoDB read after the current key lock fixes the
        // policy transaction's REPEATABLE READ view, even when its grant set is empty.
        $r=$this->row($this->prepare('SELECT UTC_TIMESTAMP(6) read_view_at,(SELECT JSON_ARRAYAGG(study_id) FROM '.$this->table('grants').' WHERE key_id=%s) grants_json',$id));
        $this->readViewAt=str_replace(' ','T',$r['read_view_at']).'Z';$ids=json_decode($r['grants_json']??'[]',true)??array();sort($ids,SORT_STRING);return $ids;
    }
    public function readViewAt(): string {if(!$this->readViewAt)throw new \LogicException('Read view not established');return $this->readViewAt;}

    public function audit(string $event,string $id,int $actor,string $request,int $now,array $details=array(),bool $aggregate=false): void {
        $events=array('CREATED','REVOKED','NAME_CHANGED','SCOPES_CHANGED','GRANTS_CHANGED','EXPIRATION_CHANGED','ENABLED','DISABLED','AUTH_OK','AUTH_FAILED','STUDIES_LIST_OK','STUDIES_LIST_FAILED','STUDIES_DETAIL_OK','STUDIES_DETAIL_FAILED','DATA_JSON_OK','DATA_JSON_FAILED','DATA_CSV_OK','DATA_CSV_FAILED','DICTIONARY_OK','DICTIONARY_FAILED','EXPORT_CREATED','EXPORT_STARTED','EXPORT_READY','EXPORT_FAILED','EXPORT_EXPIRED','EXPORT_REVOKED','EXPORT_DOWNLOAD','EXPORT_DOWNLOAD_FAILED','EXPORT_CLEANED','OPERATIONS_SETTINGS');
        if(!in_array($event,$events,true))throw new \LogicException('Invalid audit event');
        // All values originate in validated domain objects, never HTTP/request bodies.
        $allowed=array_intersect_key($details,array_flip(array('scopes','grant_mode','studies','expires_at','revision','returned_count','duration_ms','result','study_ref','bytes','export_id')));
        $key=$aggregate?hash('sha256',$event.'|'.$id.'|'.($allowed['export_id']??'').'|'.intdiv($now,300)):bin2hex(random_bytes(32));
        $this->rows($this->prepare('INSERT INTO '.$this->table('audit').' (aggregate_key,event,key_id,actor_id,request_id,created_at,last_at,details_json,export_id) VALUES (%s,%s,%s,%d,%s,%d,%d,%s,%s) ON DUPLICATE KEY UPDATE event_count=event_count+1,last_at=VALUES(last_at)'.(preg_match('/^(STUDIES_|DATA_|DICTIONARY_|EXPORT_)/',$event)?',request_id=VALUES(request_id),details_json=VALUES(details_json)':''),
            $key,$event,$id,$actor,$request,$now,$now,json_encode($allowed,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(string)($allowed['export_id']??'')));
    }
    public function studies(): array {
        $table='`'.$this->db->prefix.'eipsi_engine_study_versions`';
        $rows=$this->rows('SELECT study_id,protocol_json,metadata_json,state,version_number FROM '.$table.' ORDER BY study_id,version_number DESC');$out=array();
        foreach($rows as $r){if(isset($out[$r['study_id']]))continue;$m=json_decode($r['metadata_json'],true);$d=json_decode($r['protocol_json'],true);$out[$r['study_id']]=array('id'=>$r['study_id'],'name'=>$m['study_admin']['name']??$d['protocol_metadata']['title']??$r['study_id'],'code'=>$d['study']['public_id']??'','status'=>!empty($m['archived'])?'ARCHIVED':$r['state']);}
        return array_values($out);
    }
    public function studyExists(string $id): bool {return $this->row($this->prepare('SELECT study_id FROM `'.$this->db->prefix.'eipsi_engine_study_versions` WHERE study_id=%s LIMIT 1',$id))!==null;}
}
