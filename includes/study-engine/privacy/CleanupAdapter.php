<?php
namespace EIPSI\StudyEngine\Privacy;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Contracts\Canonical;
/** Relational adapter of Privacy_Data_Cleanup_Service. One execution per bounded transaction. */
final class CleanupAdapter {
    private $db;private $observer;
    public function __construct(P\Database $db,?P\TransitionObserver $observer=null){$this->db=$db;$this->observer=$observer??new P\TransitionObserver();}
    private function delete(string $table,string $where): void {$this->db->query('DELETE FROM '.$this->db->table($table).' WHERE '.$where);}
    private function local(string $table,string $where): void {
        global $wpdb;$name=$wpdb->prefix.$table;
        $schema=$this->db->row($this->db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$name));
        if(!$schema)return;if(strcasecmp($schema['ENGINE'],'InnoDB')!==0)throw new P\IntegrityFailure('PRIVACY_LOCAL_TRANSACTION_REQUIRED');
        $this->db->query('DELETE FROM `'.$name.'` WHERE '.$where);
    }
    public function run(string $id): array {
        try{$result=$this->db->transactional(function()use($id){
            $e=(new P\StudyExecutionRepository($this->db))->find($id,true);$w=(new P\WithdrawalRepository($this->db))->find($id);
            if(!$e||!$w||$w['data_policy']!=='DO_NOT_USE'||$e['data_policy']!=='DO_NOT_USE')throw new \DomainException('B2_SCOPE_REQUIRED');
            if(in_array($w['cleanup_state'],array('COMPLETE_LOCAL','PARTIAL'),true)&&!empty($e['privacy_tombstone']))return Policy::outcome($this->db,$id);
            $this->observer->reached('cleanup_locked');$unsupported=array();$where=$this->db->prepare('execution_id=%s',$id);$categories=array();
            $journals=(new P\SubmissionJournalRepository($this->db))->list($id);
            foreach($journals as $j){
                if($j['destination_ref']!==null)$unsupported[]='external_storage_reconciliation';
                if($j['local_storage_ref']===null)continue;
                if(!preg_match('/^WORDPRESS_DB_([1-9][0-9]*)$/D',$j['local_storage_ref'],$m)){$unsupported[]='unsupported_local_storage_ref';continue;}
                global $wpdb;$r=$this->db->row($this->db->prepare('SELECT * FROM `'.$wpdb->prefix.'vas_form_results` WHERE id=%d FOR UPDATE',(int)$m[1]));
                if(!$r)continue;$meta=json_decode($r['metadata']??'',true)['study_engine']??array();
                if(($meta['study_execution_id']??null)!==$id||($meta['submission_id']??null)!==$j['submission_id']||($meta['block_execution_id']??null)!==$j['block_execution_id']||($meta['study_version_id']??null)!==$j['study_version_id'])throw new P\IntegrityFailure('PRIVACY_STORAGE_BINDING_MISMATCH');
                $this->local('eipsi_device_data',$this->db->prepare('submission_id=%d',(int)$m[1]));
                $this->local('vas_form_results',$this->db->prepare('id=%d',(int)$m[1]));
            }
            require_once dirname(__DIR__,2).'/assessment-editor/runtime/Privacy.php';
            \EIPSI\AssessmentEditor\Runtime\Privacy::eraseStudy($this->db,$id);
            $this->observer->reached('cleanup_after_storage');
            $this->db->query($this->db->prepare('DELETE FROM '.$this->db->table('management_events').' WHERE execution_id=%s',$id));
            $this->db->query($this->db->prepare("UPDATE ".$this->db->table('management_exports')." SET state='INVALIDATED',payload_json=NULL,plan_json='[]',manifest_json=NULL,error_code='DATA_POLICY_CHANGED' WHERE study_id=%s",$e['study_id']));
            // Only exact engine execution IDs; shared fingerprint/IP/email never select rows.
            foreach(array('vas_form_events','eipsi_partial_responses','eipsi_emergency_submissions') as $table){
                global $wpdb;$cols=$this->db->rows($this->db->prepare('SELECT COLUMN_NAME AS Field FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->prefix.$table));$names=array_column($cols,'Field');
                if(in_array('session_id',$names,true))$this->local($table,$this->db->prepare('session_id=%s',$id));
                elseif($cols)$unsupported[]=$table.'_unlinked';
            }
            $categories=array_fill_keys(array('participant','sessions','capabilities','enrollment_receipts','study_execution','block_executions','checkpoints','responses','partials','events','device_data','submission_journal','random_decisions','notification_intents_jobs','email_logs','withdrawal_record'),'CLEANED_LOCAL');
            // No answer/arm/timestamp history is retained for B2. Preserve only opaque structural tombstones.
            foreach($this->db->rows($this->db->prepare('SELECT id,state FROM '.$this->db->table('block_executions').' WHERE execution_id=%s',$id)) as $b){
                $t=array('contract'=>'P1G_BLOCK_TOMBSTONE_V1','id'=>$b['id'],'execution_id'=>$id,'state'=>$b['state']);$occurrence=Canonical::json(array('opaque_block_id'=>$b['id']));
                $this->db->update('block_executions',array('definition_id'=>'REDACTED','parent_execution_id'=>null,'occurrence_json'=>$occurrence,'occurrence_hash'=>hash('sha256',$occurrence),'terminal_reason'=>null,'available_at'=>null,'due_at'=>null,'started_at'=>null,'completed_at'=>null,'provenance_json'=>null,'snapshot_json'=>Canonical::json($t),'created_at'=>$w['decided_at'],'updated_at'=>P\Database::now()),$this->db->prepare('id=%s',$b['id']));
            }
            foreach(array('execution_events','random_decisions','submission_journal','enrollment_receipts') as $table)$this->delete($table,$where);
            $this->delete('execution_commands',$where." AND command_type<>'WITHDRAW_EXECUTION'");
            global $wpdb;$this->db->query($this->db->prepare('DELETE j FROM `'.$wpdb->prefix.'survey_nudge_jobs` j JOIN '.$this->db->table('notification_intents').' i ON i.queue_job_id=j.id WHERE i.execution_id=%s',$id));
            $this->db->update('notification_intents',array('capability_scope_json'=>null,'request_hash'=>str_repeat('0',64),'trigger_at'=>$w['decided_at'],'created_at'=>$w['decided_at'],'updated_at'=>P\Database::now()),$where);
            $now=P\Database::now();$this->delete('participant_sessions',$where.$this->db->prepare(" AND (kind<>'WITHDRAWAL_OPTIONS' OR revoked_at IS NOT NULL OR expires_at<=%s)",$now));
            $this->delete('access_capabilities',$where.$this->db->prepare(" AND (purpose<>'WITHDRAWAL_OPTIONS' OR consumed_at IS NULL OR revoked_at IS NOT NULL OR expires_at<=%s)",$now));
            $categories['sessions']='RESTRICTED_OUTCOME_HASH_UNTIL_TTL';$categories['capabilities']='CONSUMED_WITHDRAWAL_PROOF_UNTIL_TTL';$categories['email_logs']='NO_LINKED_BODY_OR_PII_CREATED_BY_ENGINE';
            if($e['participant_id']!==null){$p=(new \EIPSI\StudyEngine\Longitudinal\Identity($this->db))->participant($e['participant_id'],true);
                $this->db->update('participants',array('email'=>null,'archived_email'=>null,'email_tombstone'=>$p['email']===null?$p['email_tombstone']:Policy::emailKey($e['study_id'],$p['email']),'email_tombstone_key'=>$p['email']===null?$p['email_tombstone_key']:Policy::keyId(),'verification_state'=>'UNVERIFIED','verified_at'=>null,'privacy_state'=>'ANONYMIZED','active'=>0,'created_at'=>$w['decided_at'],'updated_at'=>$now),$this->db->prepare('id=%s',$p['id']));
            }else $categories['sessions']='ANONYMOUS_REVOKED_OUTCOME_HASH_UNTIL_TTL';
            $this->observer->reached('cleanup_after_identity');
            $t=array('contract'=>'P1G_TOMBSTONE_V1','execution_id'=>$id,'study_version_id'=>$e['study_version_id'],'state'=>$e['state'],'revision'=>(int)$e['revision'],'withdrawal_id'=>$w['id'],'data_policy'=>'DO_NOT_USE');
            $this->db->update('study_executions',array('privacy_tombstone'=>1,'checkpoint_json'=>Canonical::json($t),'checkpoint_hash'=>hash('sha256',Canonical::json($t)),'seed_key_id'=>'REDACTED','seed_ciphertext'=>'','create_result_json'=>Canonical::json(array('privacy_tombstone'=>true)),'created_at'=>$w['decided_at'],'started_at'=>null,'first_access_at'=>null,'event_sequence'=>0,'wait_json'=>null,'cursor_json'=>null,'outcome_json'=>Canonical::json(array('data_policy'=>'DO_NOT_USE')),'timezone'=>'UTC','updated_at'=>$now),$this->db->prepare('id=%s',$id));
            $coverage=Coverage::report($categories,array_values(array_unique($unsupported)));$state=$unsupported?'PARTIAL':'COMPLETE_LOCAL';$snapshot=P\Database::json($w['snapshot_json']);$snapshot['cleanup_state']=$state;$snapshot['coverage']=array('source'=>$coverage['source'],'complete'=>false,'not_covered'=>$coverage['not_covered']);
            $this->db->update('withdrawal_records',array('cleanup_state'=>$state,'coverage_json'=>Canonical::json($coverage),'snapshot_json'=>Canonical::json($snapshot),'cleanup_attempts'=>(int)$w['cleanup_attempts']+1,'cleanup_revision'=>(int)$w['cleanup_revision']+1,'cleanup_error'=>null),$where);
            $this->observer->reached('cleanup_before_commit');return Policy::outcome($this->db,$id);
        });error_log('[EIPSI PRIVACY] withdrawal='.($result['id']??'unavailable').' execution='.$id.' state='.($result['cleanup_state']??'UNKNOWN').' attempt='.($result['cleanup_attempts']??0));return $result;}catch(\Throwable $error){
            // Separate transaction: the initial withdrawal/policy/revocation never roll back here.
            $this->db->transactional(function()use($id){$e=(new P\StudyExecutionRepository($this->db))->find($id,true);$w=(new P\WithdrawalRepository($this->db))->find($id);if(!$e||!$w||$w['data_policy']!=='DO_NOT_USE')return;
                $snapshot=P\Database::json($w['snapshot_json']);$snapshot['cleanup_state']='FAILED';
                $this->db->update('withdrawal_records',array('snapshot_json'=>Canonical::json($snapshot),'cleanup_state'=>'FAILED','cleanup_attempts'=>(int)$w['cleanup_attempts']+1,'cleanup_revision'=>(int)$w['cleanup_revision']+1,'cleanup_error'=>'LOCAL_CLEANUP_RETRY_REQUIRED'),$this->db->prepare('execution_id=%s',$id));});
            return array('success'=>false,'cleanup_state'=>'FAILED','error'=>'LOCAL_CLEANUP_RETRY_REQUIRED');
        }
    }
    public function batch(int $limit=5): int {
        $this->db->transactional(function(){
            foreach(array('participant_sessions','access_capabilities') as $table)$this->db->query('DELETE c FROM '.$this->db->table($table).' c JOIN '.$this->db->table('participants')." p ON p.id=c.participant_id WHERE p.privacy_state<>'ACTIVE' AND c.expires_at<=UTC_TIMESTAMP()");
            $this->db->query('DELETE a FROM '.$this->db->table('anonymous_access').' a JOIN '.$this->db->table('study_executions')." e ON e.id=a.execution_id WHERE e.privacy_tombstone=1 AND a.revoked_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 48 HOUR)");
        });
        $rows=$this->db->rows($this->db->prepare('SELECT execution_id FROM '.$this->db->table('withdrawal_records')." WHERE data_policy='DO_NOT_USE' AND cleanup_state IN ('PENDING','FAILED') ORDER BY cleanup_attempts,decided_at LIMIT %d",max(1,min(25,$limit))));
        foreach($rows as $r)$this->run($r['execution_id']);return count($rows);
    }
}
