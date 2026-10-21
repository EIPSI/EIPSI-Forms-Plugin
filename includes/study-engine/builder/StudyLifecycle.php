<?php
namespace EIPSI\StudyEngine\Builder;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Contracts as C;

/** Administrative closure freezes science; it is neither withdrawal nor exclusion from analysis. */
trait StudyLifecycle {
    private function lifecycle(string $study): array {
        $row=$this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('public_studies').' WHERE study_id=%s',$study));
        $version=$row['active_version_id']??null;if($version===null){$v=$this->db->row($this->db->prepare('SELECT id FROM '.$this->db->table('study_versions')." WHERE study_id=%s AND state='PUBLISHED' ORDER BY version_number DESC LIMIT 1",$study));$version=$v['id']??null;}
        return array('published_version_id'=>$version,'state'=>$row?($row['enrollment_state']==='FINALIZED'?'FINALIZED':($row['enrollment_state']==='OPEN'?'ACTIVE':'PAUSED')):'DRAFT','revision'=>(int)($row['lifecycle_revision']??0),'cycle'=>(int)($row['study_cycle']??1),'active_version_id'=>$row['active_version_id']??null,'finalized_at'=>$row['finalized_at']??null);
    }
    public function lifecycleSummary(string $id): array {
        self::authorize();$r=$this->row($id);$study=$r['study_id'];
        $counts=$this->db->row($this->db->prepare('SELECT COUNT(*) AS participants,SUM(finalized_at IS NULL) AS current_participants FROM '.$this->db->table('study_executions').' WHERE study_id=%s',$study));
        $open=$this->db->rows($this->db->prepare('SELECT b.definition_id,e.study_version_id FROM '.$this->db->table('block_executions').' b JOIN '.$this->db->table('study_executions')." e ON e.id=b.execution_id WHERE e.study_id=%s AND e.finalized_at IS NULL AND b.state='IN_PROGRESS'",$study));
        $versions=array();$assessments=0;foreach($open as $block){$version=$block['study_version_id'];if(!isset($versions[$version])){$d=P\Database::json($this->row($version)['protocol_json']);$versions[$version]=array();foreach($d['nodes'] as $node)if(($node['completion_boundary']??null)==='ASSESSMENT')$versions[$version][$node['id']]=true;}if(isset($versions[$version][$block['definition_id']]))$assessments++;}
        return array('lifecycle'=>$this->lifecycle($study),'participants'=>(int)$counts['current_participants'],'assessments_in_progress'=>$assessments);
    }
    public function changeStudyState(string $id,int $revision,string $action,bool $erase,string $request): array {
        self::authorize();C\Check::id($request,'request_id');
        if(!in_array($action,array('ACTIVE','PAUSED','FINALIZED','REOPEN'),true))throw new \DomainException('INVALID_STUDY_STATE');
        $hash=hash('sha256',C\Canonical::json(array($action,$erase)));
        $study=$this->row($id)['study_id'];\EIPSI\StudyEngine\Runtime\PublicStudyRepository::deliveryLock($this->db,$study,function()use($id,$revision,$action,$erase,$request,$hash){return $this->db->transactional(function()use($id,$revision,$action,$erase,$request,$hash){
            $r=$this->row($id,true);$meta=P\Database::json($r['metadata_json']);
            if(!empty($meta['archived']))throw new \DomainException('STUDY_ARCHIVED');
            foreach($meta['lifecycle']??array() as $event)if(($event['request_id']??null)===$request){if(($event['request_hash']??null)!==$hash)throw new P\PersistenceConflict('LIFECYCLE_REQUEST_REUSED');return;}
            $public=$this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('public_studies').' WHERE study_id=%s FOR UPDATE',$r['study_id']));
            if((int)($public['lifecycle_revision']??0)!==$revision)throw new P\PersistenceConflict('STUDY_STATE_CONFLICT');
            $previous=$public?($public['enrollment_state']==='FINALIZED'?'FINALIZED':($public['enrollment_state']==='OPEN'?'ACTIVE':'PAUSED')):'DRAFT';
            if($previous==='FINALIZED'&&$action!=='REOPEN')throw new \DomainException('STUDY_FINALIZED');
            if($action==='REOPEN'&&$previous!=='FINALIZED')throw new \DomainException('STUDY_NOT_FINALIZED');
            if($action===$previous)return;
            $now=P\Database::now();$cycle=(int)($public['study_cycle']??1);
            if($action==='FINALIZED'){
                if(!$public)throw new \DomainException('STUDY_NOT_STARTED');
                global $wpdb;$names=array();foreach(array('eipsi_engine_public_studies','eipsi_engine_participants','eipsi_engine_participant_sessions','eipsi_engine_access_capabilities','eipsi_engine_enrollment_receipts','eipsi_engine_anonymous_access','survey_nudge_jobs') as $table)$names[]=$this->db->prepare('%s',$wpdb->prefix.$table);$engines=$this->db->rows('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.implode(',',$names).')');if(count($engines)!==count($names))throw new P\IntegrityFailure('STUDY_FINALIZE_SCHEMA_REQUIRED');foreach($engines as $engine)if(strcasecmp($engine['ENGINE'],'InnoDB')!==0)throw new P\IntegrityFailure('STUDY_FINALIZE_TRANSACTION_REQUIRED');
                // All versions, including already completed cases. Scientific records remain intact.
                $this->db->rows($this->db->prepare('SELECT id FROM '.$this->db->table('study_executions').' WHERE study_id=%s ORDER BY id FOR UPDATE',$r['study_id']));
                $this->db->query($this->db->prepare("UPDATE ".$this->db->table('management_exports')." SET state='INVALIDATED',payload_json=NULL,plan_json='[]',manifest_json=NULL,error_code='STUDY_FINALIZED' WHERE study_id=%s",$r['study_id']));

                $this->db->update('study_executions',array('finalized_at'=>$now),$this->db->prepare('study_id=%s AND finalized_at IS NULL',$r['study_id']));
                foreach(array('participant_sessions','access_capabilities','anonymous_access') as $table)$this->db->update($table,array('revoked_at'=>$now),$this->db->prepare('public_study_id=%s AND revoked_at IS NULL',$public['public_id']));
                $this->db->query($this->db->prepare('UPDATE '.$this->db->table('notification_intents').' i JOIN '.$this->db->table('study_executions')." e ON e.id=i.execution_id SET i.state='CANCELLED',i.cancel_reason='STUDY_FINALIZED',i.updated_at=%s WHERE e.study_id=%s AND i.state IN ('PENDING','CLAIMED','FAILED_RETRYABLE')",$now,$r['study_id']));
                global $wpdb;
                $this->db->query($this->db->prepare('UPDATE `'.$wpdb->prefix.'survey_nudge_jobs` j JOIN '.$this->db->table('notification_intents').' i ON i.queue_job_id=j.id JOIN '.$this->db->table('study_executions')." e ON e.id=i.execution_id SET j.status='cancelled',j.updated_at=%s WHERE e.study_id=%s AND j.status IN ('pending','failed')",$now,$r['study_id']));
                $native=$this->db->row($this->db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->prefix.'eipsi_engine_native_executions'));
                if($native){if(strcasecmp($native['ENGINE'],'InnoDB')!==0)throw new P\IntegrityFailure('STUDY_FINALIZE_TRANSACTION_REQUIRED');$this->db->query($this->db->prepare('UPDATE '.$this->db->table('native_executions').' n JOIN '.$this->db->table('study_executions')." e ON e.id=JSON_UNQUOTE(JSON_EXTRACT(n.binding_json,'$.execution_id')) SET n.metadata_json=JSON_SET(COALESCE(n.metadata_json,'{}'),'$.study_finalized_at',%s,'$.study_original_expires_at',n.expires_at),n.expires_at=NULL WHERE e.study_id=%s AND n.expires_at IS NOT NULL",$now,$r['study_id']));}
                // Emails retained for history are removed from the live unique identity index too.
                $this->db->query($this->db->prepare('UPDATE '.$this->db->table('participants').' SET archived_email='.($erase?'NULL':'COALESCE(email,archived_email)').",email=NULL,email_tombstone=NULL,email_tombstone_key=NULL,active=0,access_revision=access_revision+1,updated_at=%s WHERE study_id=%s",$now,$r['study_id']));
                // These are identity/correction receipts; scientific command receipts are preserved.
                $this->db->query($this->db->prepare('DELETE FROM '.$this->db->table('enrollment_receipts').' WHERE public_study_id=%s',$public['public_id']));
                $this->db->update('public_studies',array('enrollment_state'=>'FINALIZED','finalized_at'=>$now,'lifecycle_revision'=>$revision+1,'updated_at'=>$now),$this->db->prepare('study_id=%s',$r['study_id']));
            }else{
                $version=$r['state']==='PUBLISHED'?$r['id']:($public['active_version_id']??null);
                if($version===null){$v=$this->db->row($this->db->prepare('SELECT id FROM '.$this->db->table('study_versions')." WHERE study_id=%s AND state='PUBLISHED' ORDER BY version_number DESC LIMIT 1",$r['study_id']));$version=$v['id']??null;}
                if(!$version)throw new \DomainException('PUBLISHED_VERSION_REQUIRED');
                if($action==='REOPEN'){
                    $cycle++;$this->db->update('public_studies',array('enrollment_state'=>'PAUSED','finalized_at'=>null,'study_cycle'=>$cycle),$this->db->prepare('study_id=%s',$r['study_id']));
                }
                (new \EIPSI\StudyEngine\Runtime\PublicStudyRepository($this->db))->activateInTransaction($version,$action==='PAUSED'?'PAUSED':'OPEN');
            }
            \EIPSI\StudyEngine\Management\Audit::record($r['study_id'],'STUDY_STATE_CHANGED',null,$id,array('state'=>$action,'previous'=>$previous,'cycle'=>$cycle,'request_id'=>$request));
            $meta['lifecycle'][]=array('event'=>'STUDY_STATE_CHANGED','state'=>$action==='REOPEN'?'ACTIVE':$action,'previous'=>$previous,'cycle'=>$cycle,'erase_emails'=>$action==='FINALIZED'?$erase:null,'actor'=>get_current_user_id(),'at'=>$now,'request_id'=>$request,'request_hash'=>$hash);
            $this->db->update('study_versions',array('metadata_json'=>C\Canonical::json($meta),'updated_at'=>$now),$this->db->prepare('id=%s',$id));$this->observer->reached('before_study_state_commit');
        });});
        $out=$this->load($id);if(in_array($action,array('ACTIVE','REOPEN'),true)&&($out['definition']['protocol_metadata']['enrollment_mode']??null)==='EMAIL_LONGITUDINAL'){update_option('eipsi_study_engine_longitudinal_enabled',1,false);require_once dirname(__DIR__).'/longitudinal/bootstrap.php';\EIPSI_Cron_Registry::schedule_study_engine();}return $out;
    }
}
