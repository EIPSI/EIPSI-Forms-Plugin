<?php
namespace EIPSI\ExternalApi;
final class Operations {
    const STATE_OPTION='eipsi_external_maintenance_v1';
    public static function admin(): void {if(!\EIPSI_Admin_Authorization_Policy::allows('api.manage')&&!(defined('WP_CLI')&&WP_CLI))throw new ApiError('eipsi_external_forbidden',403);}
    public static function failureReason(\Throwable $e,int $attempts): string {
        if($e instanceof \EIPSI\StudyEngine\Persistence\IntegrityFailure)return 'SOURCE_CHANGED';
        if($e instanceof \EIPSI\StudyEngine\Management\DatasetLimit)return 'SIZE_LIMIT';
        if($e instanceof ApiError){if($e->apiCode==='eipsi_external_export_too_large')return 'SIZE_LIMIT';if($e->apiCode==='eipsi_external_export_capacity')return 'STORAGE_CAPACITY';if($e->apiCode==='eipsi_external_export_failed')return 'INTEGRITY';if(in_array($e->getCode(),array(401,403,404),true))return 'AUTHORIZATION';}
        return $attempts>=3?'WORKER_RETRY_EXHAUSTED':'INTERNAL';
    }
    private static function page($page): int {if(!is_int($page)||$page<1||$page>10000)throw new ApiError('eipsi_external_invalid_input',400);return $page;}
    public function exports(int $page=1): array {
        self::admin();$s=new Store();$offset=(self::page($page)-1)*25;
        $rows=$s->rows($s->prepare('SELECT e.id,e.key_id,k.name key_name,e.study_id,e.study_code,e.state,e.format,e.created_at,e.as_of,e.expires_at,e.source_count,e.next_source,e.row_count,e.byte_count,e.storage_bytes,e.last_progress_at,e.error_code,e.failure_reason,e.purged_at FROM '.$s->table('exports').' e LEFT JOIN '.$s->table('keys').' k ON k.id=e.key_id ORDER BY e.created_at DESC,e.id DESC LIMIT 26 OFFSET %d',$offset));$more=count($rows)>25;return array('items'=>array_slice($rows,0,25),'page'=>$page,'has_more'=>$more);
    }
    public function keys(int $page=1): array {
        self::admin();$s=new Store();$offset=(self::page($page)-1)*25;
        $rows=$s->rows($s->prepare('SELECT id,name,display_suffix,created_at,created_by,last_used_at,revoked_at,expires_at,scopes_json,grant_mode,revision FROM '.$s->table('keys').' ORDER BY created_at DESC,id DESC LIMIT 26 OFFSET %d',$offset));$more=count($rows)>25;$rows=array_slice($rows,0,25);$grants=array();
        if($rows){$ids=implode(',',array_map(function($r)use($s){return $s->prepare('%s',$r['id']);},$rows));foreach($s->rows('SELECT key_id,study_id FROM '.$s->table('grants').' WHERE key_id IN ('.$ids.') ORDER BY key_id,study_id')as$r)$grants[$r['key_id']][]=$r['study_id'];}
        $out=array();foreach($rows as$r){$v=Keys::publicRow($r,$grants[$r['id']]??array(),time());$v['warnings']=array();if($r['last_used_at']===null)$v['warnings'][]='Nunca utilizada';if($r['expires_at']!==null&&(int)$r['expires_at']>time()&&(int)$r['expires_at']<time()+7*86400)$v['warnings'][]='Vence dentro de siete días';if($r['expires_at']===null&&(int)$r['created_at']<time()-365*86400)$v['warnings'][]='Clave antigua sin vencimiento (informativo)';$out[]=$v;}
        return array('items'=>$out,'page'=>$page,'has_more'=>$more);
    }
    public function audit(array $filters=array(),int $page=1): array {
        self::admin();$s=new Store();$w=array('1=1');if(array_diff(array_keys($filters),array('key','event','from','to','export')))throw new ApiError('eipsi_external_invalid_input',400);
        foreach($filters as$k=>$v){if($v==='')continue;if(!is_string($v))throw new ApiError('eipsi_external_invalid_input',400);
            if(in_array($k,array('key','export'),true)){if(!preg_match('/^[a-f0-9]{32}$/D',$v))throw new ApiError('eipsi_external_invalid_input',400);$w[]=$s->prepare(($k==='key'?'key_id':'export_id').'=%s',$v);}
            elseif($k==='event'){if(!preg_match('/^[A-Z_]{2,40}$/D',$v))throw new ApiError('eipsi_external_invalid_input',400);$w[]=$s->prepare('event=%s',$v);}
            else{if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v)||!checkdate((int)substr($v,5,2),(int)substr($v,8,2),(int)substr($v,0,4)))throw new ApiError('eipsi_external_invalid_input',400);$w[]=$s->prepare('created_at'.($k==='from'?'>=':'<=').'%d',strtotime($v.'T00:00:00Z')+($k==='to'?86399:0));}
        }
        $rows=$s->rows($s->prepare('SELECT id,event,key_id,export_id,actor_id,request_id,created_at,last_at,event_count,details_json FROM '.$s->table('audit').' WHERE '.implode(' AND ',$w).' ORDER BY created_at DESC,id DESC LIMIT 26 OFFSET %d',(self::page($page)-1)*25));$more=count($rows)>25;$out=array();
        foreach(array_slice($rows,0,25)as$r){$d=json_decode($r['details_json'],true)??array();$safe=array();foreach(array('returned_count','duration_ms','bytes','revision','expires_at')as$k)if(isset($d[$k])&&is_numeric($d[$k]))$safe[$k]=(int)$d[$k];foreach(array('study_ref','export_id','result','grant_mode')as$k)if(isset($d[$k])&&is_string($d[$k])&&preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$d[$k]))$safe[$k]=$d[$k];unset($r['details_json']);$r['details']=$safe;$out[]=$r;}
        return array('items'=>$out,'page'=>$page,'has_more'=>$more);
    }
    public function revoke(string $id,string $confirmation): void {
        self::admin();ExportStorage::id($id);if($confirmation!=='REVOCAR '.$id)throw new ApiError('eipsi_external_confirmation_required',400);$s=new Store();$st=new ExportStorage($s);
        $s->transaction(function()use($s,$st,$id){$r=$st->get($id,true);if(!$r)throw new ApiError('eipsi_external_export_unavailable',404);if(!in_array($r['state'],array('READY','PREPARING'),true))return;$st->update($id,array('state'=>'REVOKED','failure_reason'=>'ADMIN_REVOKED','error_code'=>'eipsi_external_snapshot_invalidated','lease_token'=>null,'lease_until'=>0));$s->audit('EXPORT_REVOKED',$r['key_id'],get_current_user_id(),Authenticator::requestId(),time(),array('export_id'=>$id,'study_ref'=>$r['study_id'],'result'=>'ADMIN_REVOKED'));});
    }
    public function purge(string $id,string $confirmation): void {
        self::admin();ExportStorage::id($id);if($confirmation!=='PURGAR '.$id)throw new ApiError('eipsi_external_confirmation_required',400);$s=new Store();$st=new ExportStorage($s);
        $st->exclusive(function()use($s,$st,$id){$s->transaction(function()use($s,$st,$id){$r=$st->get($id,true);if(!$r)throw new ApiError('eipsi_external_export_unavailable',404);if(!in_array($r['state'],array('REVOKED','FAILED','EXPIRED'),true)||(int)$r['lease_until']>time()||(int)$r['download_until']>time())throw new ApiError('eipsi_external_export_busy',409);
            foreach(array('export_chunks','export_sources')as$t)$s->rows($s->prepare('DELETE FROM '.$s->table($t).' WHERE export_id=%s',$id));$st->update($id,array('manifest_json'=>null,'storage_bytes'=>0,'purged_at'=>time()));$s->audit('EXPORT_CLEANED',$r['key_id'],get_current_user_id(),Authenticator::requestId(),time(),array('export_id'=>$id,'study_ref'=>$r['study_id'],'result'=>'ADMIN_PURGE'));
        });});
    }
    public function cleanupAudit(int $now,int $limit=200): int {
        $s=new Store();$c=OperationsSettings::get();$limit=max(1,min(500,$limit));$events="'CREATED','REVOKED','NAME_CHANGED','SCOPES_CHANGED','GRANTS_CHANGED','EXPIRATION_CHANGED','ENABLED','DISABLED','OPERATIONS_SETTINGS'";
        $ids=$s->rows($s->prepare('SELECT id FROM '.$s->table('audit')." WHERE created_at<%d AND ((event IN ($events) AND created_at<%d) OR event NOT IN ($events)) ORDER BY created_at,id LIMIT %d",$now-$c['audit_days']*86400,$now-$c['key_audit_days']*86400,$limit));
        if($ids)$s->rows('DELETE FROM '.$s->table('audit').' WHERE id IN ('.implode(',',array_map('intval',array_column($ids,'id'))).')');
        $s->rows($s->prepare('DELETE FROM '.$s->table('metrics').' WHERE day<%s ORDER BY day LIMIT 1000',gmdate('Y-m-d',$now-30*86400)));
        // Bounded fill of the new audit index for already stored API-2B metadata.
        $s->rows("UPDATE ".$s->table('audit')." SET export_id=JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.export_id')) WHERE export_id='' AND JSON_VALID(details_json) AND JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.export_id')) REGEXP '^[a-f0-9]{32}$' LIMIT 500");return count($ids);
    }
    private function retireKeys(int $now): void {
        $s=new Store();$st=new ExportStorage($s);$rows=$s->rows($s->prepare("SELECT e.id,e.key_id FROM ".$s->table('exports')." e LEFT JOIN ".$s->table('keys')." k ON k.id=e.key_id WHERE e.state IN ('PREPARING','READY') AND (k.id IS NULL OR k.revoked_at IS NOT NULL OR k.expires_at<=%d) ORDER BY e.id LIMIT 25",$now));
        foreach($rows as$row)$s->transaction(function()use($s,$st,$row,$now){$key=$s->key($row['key_id'],true);if($key&&Keys::status($key,time())==='ACTIVE')return;$r=$st->get($row['id'],true);if(!$r||!in_array($r['state'],array('PREPARING','READY'),true))return;$st->update($r['id'],array('state'=>'REVOKED','failure_reason'=>'AUTHORIZATION','error_code'=>'eipsi_external_snapshot_invalidated','lease_token'=>null,'lease_until'=>0));$s->audit('EXPORT_REVOKED',$r['key_id'],0,Authenticator::requestId(),$now,array('export_id'=>$r['id'],'study_ref'=>$r['study_id'],'result'=>'KEY_UNAVAILABLE'));});
    }
    public static function state(): array {
        $raw=get_option(self::STATE_OPTION,array());if(!is_array($raw))return array();$out=array();foreach(array('last_tick','last_success','last_cleanup','last_worker_activity','last_audit_cleanup')as$k)if(isset($raw[$k])&&is_int($raw[$k])&&$raw[$k]>=0)$out[$k]=$raw[$k];$out['errors']=array_values(array_intersect(is_array($raw['errors']??null)?$raw['errors']:array(),array('WORKER_UNAVAILABLE','CLEANUP_UNAVAILABLE','AUDIT_CLEANUP_UNAVAILABLE')));return $out;
    }
    public function maintenance(bool $cleanupOnly=false): array {
        $now=time();$state=self::state();$state['last_tick']=$now;$c=OperationsSettings::get();$errors=array();$steps=0;$cleaned=0;
        try{$this->retireKeys($now);}catch(\Throwable $e){$errors[]='WORKER_UNAVAILABLE';}
        if(!$cleanupOnly)try{$steps=(new DatasetSnapshots())->processPending($c['worker_seconds'],$c['worker_steps']);$state['last_worker_activity']=$now;}catch(\Throwable $e){$errors[]='WORKER_UNAVAILABLE';}
        try{$cleaned=(new ExportStorage())->cleanup($now,$c['cleanup_batch']);$state['last_cleanup']=$now;}catch(\Throwable $e){$errors[]='CLEANUP_UNAVAILABLE';}
        try{$this->cleanupAudit($now);$state['last_audit_cleanup']=$now;}catch(\Throwable $e){$errors[]='AUDIT_CLEANUP_UNAVAILABLE';}
        if(!$errors)$state['last_success']=$now;else \EIPSI_Security_Log::event('API_MAINTENANCE_FAILED',array('code'=>$errors[0]));$state['errors']=$errors;update_option(self::STATE_OPTION,$state,false);OperationsMetrics::record('maintenance',array('cleanup_count'=>$cleaned),$now);
        return array('state'=>$state,'worker_steps'=>$steps,'cleaned_exports'=>$cleaned);
    }
}
