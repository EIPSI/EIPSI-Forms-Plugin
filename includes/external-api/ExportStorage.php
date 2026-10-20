<?php
namespace EIPSI\ExternalApi;
/** Private DB chunks; no webroot paths, uploads, filenames or public capabilities. */
final class ExportStorage {
    const MAX_BYTES=268435456; // 256 MiB, includes all derived representations.
    const TOTAL_BYTES=1073741824;
    const CHUNK_BYTES=1048576;
    const TTL=86400;
    private $store;
    public function __construct(?Store $store=null){$this->store=$store??new Store();}
    public static function id($id): string {if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new ApiError('eipsi_external_export_unavailable',404);return $id;}
    public function get(string $id,bool $lock=false): ?array {$s=$this->store;return $s->row($s->prepare('SELECT * FROM '.$s->table('exports').' WHERE id=%s'.($lock?' FOR UPDATE':''),self::id($id)));}
    public function update(string $id,array $values): void {
        $allowed=array('state','next_source','source_count','row_count','byte_count','chunk_count','lease_token','lease_until','download_until','failures','error_code','manifest_json','storage_bytes','purged_at','last_progress_at','failure_reason');$set=array();$s=$this->store;
        foreach($values as$k=>$v){if(!in_array($k,$allowed,true))throw new \LogicException('Invalid export column');$set[]='`'.$k.'`='.($v===null?'NULL':$s->prepare('%s',(string)$v));}
        $s->rows($s->prepare('UPDATE '.$s->table('exports').' SET '.implode(',',$set).' WHERE id=%s',$id));
    }
    /** One global bounded worker/allocator step per blog. Connection crash releases this mutex. */
    public function exclusive(callable $fn){
        $s=$this->store;global $wpdb;$name='eipsi-export-'.hash('sha256',DB_NAME.'|'.$wpdb->prefix);
        if((int)($s->row($s->prepare('SELECT GET_LOCK(%s,0) acquired',substr($name,0,64)))['acquired']??0)!==1)throw new ApiError('eipsi_external_export_busy',429);
        try{return $fn();}finally{$s->rows($s->prepare('DO RELEASE_LOCK(%s)',substr($name,0,64)));}
    }
    public function reserve(string $id,int $bytes): void {
        $s=$this->store;$totals=$s->row($s->prepare('SELECT COALESCE(SUM(storage_bytes),0) total,COALESCE(SUM(CASE WHEN id=%s THEN storage_bytes ELSE 0 END),0) own FROM '.$s->table('exports'),$id));
        $export=$this->get($id);$pinned=OperationsSettings::pinned($export??array());$settings=OperationsSettings::get();
        if((int)$totals['own']+$bytes>$pinned['export_bytes'])throw new ApiError('eipsi_external_export_too_large',422);
        if((int)$totals['total']+$bytes>$settings['aggregate_bytes'])throw new ApiError('eipsi_external_export_capacity',503);
        $s->rows($s->prepare('UPDATE '.$s->table('exports').' SET storage_bytes=storage_bytes+%d WHERE id=%s',$bytes,$id));
    }
    public function chunk(string $id,string $kind,int $seq,string $payload,int $rows): array {
        if(strlen($payload)>self::CHUNK_BYTES)throw new ApiError('eipsi_external_export_too_large',422);
        $s=$this->store;$size=strlen($payload);$sha=hash('sha256',$payload);
        $old=$s->row($s->prepare('SELECT sha256 FROM '.$s->table('export_chunks').' WHERE export_id=%s AND kind=%s AND sequence=%d',$id,$kind,$seq));
        if($old){if(!hash_equals($sha,$old['sha256']))throw new \RuntimeException('EXPORT_CHUNK_CONFLICT');return array('sequence'=>$seq,'row_count'=>$rows,'byte_count'=>$size,'sha256'=>$sha);}
        $this->reserve($id,$size);
        $s->rows($s->prepare('INSERT INTO '.$s->table('export_chunks').' (export_id,sequence,kind,row_count,byte_count,sha256,payload) VALUES (%s,%d,%s,%d,%d,%s,%s)',$id,$seq,$kind,$rows,$size,$sha,$payload));
        return array('sequence'=>$seq,'row_count'=>$rows,'byte_count'=>$size,'sha256'=>$sha);
    }
    public function chunks(string $id,string $kind): \Generator {
        $s=$this->store;$after=-1;
        do{$r=$s->row($s->prepare('SELECT * FROM '.$s->table('export_chunks').' WHERE export_id=%s AND kind=%s AND sequence>%d ORDER BY sequence LIMIT 1',$id,$kind,$after));if(!$r)break;
            if((int)$r['byte_count']!==strlen($r['payload'])||!hash_equals($r['sha256'],hash('sha256',$r['payload'])))throw new ApiError('eipsi_external_export_failed',503);
            yield $r;$after=(int)$r['sequence'];
        }while(true);
    }
    public static function invalidateStudy(string $study): void {
        // Called inside the existing scientific owner's transaction; no nested transaction.
        $s=new Store();global $wpdb;
        // An absent infrastructure table cannot contain a deliverable export. In particular,
        // privacy remains usable when an additive upgrade could not create these tables.
        $table=$s->row($s->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->prefix.'eipsi_external_exports'));if(!$table)return;
        if(strcasecmp($table['ENGINE'],'InnoDB')!==0)throw new \RuntimeException('External export transaction required');
        // Acquire exclusive export locks before the audit SELECT; concurrent withdrawals
        // must not both hold shared locks and then attempt an upgrade to UPDATE.
        $s->rows($s->prepare("SELECT id FROM ".$s->table('exports')." WHERE study_id=%s AND state IN ('PREPARING','READY') ORDER BY id FOR UPDATE",$study));
        $now=time();$request=Authenticator::requestId();
        $s->rows($s->prepare("INSERT INTO ".$s->table('audit')." (aggregate_key,event,key_id,actor_id,request_id,created_at,last_at,export_id,details_json) SELECT SHA2(CONCAT('EXPORT_REVOKED|',id),256),'EXPORT_REVOKED',key_id,0,%s,%d,%d,id,JSON_OBJECT('export_id',id,'study_ref',study_id,'returned_count',row_count,'bytes',byte_count,'duration_ms',0,'result','REVOKED') FROM ".$s->table('exports')." WHERE study_id=%s AND state IN ('PREPARING','READY') ON DUPLICATE KEY UPDATE last_at=VALUES(last_at)",$request,$now,$now,$study));
        $s->rows($s->prepare("UPDATE ".$s->table('exports')." SET state='REVOKED',error_code='eipsi_external_snapshot_invalidated',failure_reason='PRIVACY_INVALIDATED',lease_token=NULL,lease_until=0 WHERE study_id=%s AND state IN ('PREPARING','READY')",$study));
    }
    public function cleanup(int $now,int $limit=10): int {
        return $this->exclusive(function()use($now,$limit){$s=$this->store;return $s->transaction(function()use($s,$now,$limit){
            $rows=$s->rows($s->prepare("SELECT * FROM ".$s->table('exports')." WHERE purged_at IS NULL AND (expires_at<=%d OR state IN ('FAILED','REVOKED','EXPIRED')) AND download_until<=%d AND lease_until<=%d ORDER BY expires_at LIMIT %d FOR UPDATE",$now,$now,$now,max(1,min(50,$limit))));$n=0;
            foreach($rows as$r){foreach(array('export_chunks','export_sources')as$t)$s->rows($s->prepare('DELETE FROM '.$s->table($t).' WHERE export_id=%s',$r['id']));$state=$r['expires_at']<=$now?'EXPIRED':$r['state'];$this->update($r['id'],array('state'=>$state,'manifest_json'=>null,'lease_token'=>null,'lease_until'=>0,'storage_bytes'=>0,'purged_at'=>$now));
                if($r['state']!==$state)$s->audit('EXPORT_EXPIRED',$r['key_id'],0,Authenticator::requestId(),$now,array('export_id'=>$r['id'],'study_ref'=>$r['study_id']));
                if((int)$r['storage_bytes']>0||(int)$r['source_count']>0||$r['manifest_json']!==null||$r['state']==='PREPARING')$s->audit('EXPORT_CLEANED',$r['key_id'],0,Authenticator::requestId(),$now,array('export_id'=>$r['id'],'study_ref'=>$r['study_id']));$n++;
            }
            $s->rows($s->prepare("DELETE FROM ".$s->table('exports')." WHERE purged_at IS NOT NULL AND expires_at<%d AND state IN ('EXPIRED','FAILED','REVOKED') AND download_until<=%d AND lease_until<=%d LIMIT %d",$now-7*self::TTL,$now,$now,max(1,min(50,$limit))));return $n;
        });});
    }
}
