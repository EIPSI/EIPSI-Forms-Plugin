<?php
namespace EIPSI\StudyEngine\Builder;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Contracts as C;

/** Study-wide commands share the version lock used by admission and publication. */
trait StudyManagement {
    public static function publicId(string $name,string $request): string {
        $clean=trim(preg_replace('/[^A-Za-z0-9 ]+/', ' ', remove_accents($name)));
        $words=preg_split('/\s+/', $clean,-1,PREG_SPLIT_NO_EMPTY);
        $prefix=count($words)>1?implode('',array_map(static function($w){return strtoupper($w[0]);},$words)):($words[0]??'Study');
        if(!preg_match('/^[A-Za-z]/',$prefix))$prefix='S'.$prefix;
        return substr($prefix,0,64).'_'.substr(hash('sha256','STUDY_PUBLIC|'.$request),0,32);
    }
    public function editStudy(string $id): array {
        self::authorize();$row=$this->row($id);
        $draft=$this->db->row($this->db->prepare('SELECT id FROM '.$this->db->table('study_versions')." WHERE study_id=%s AND state='DRAFT' ORDER BY version_number DESC LIMIT 1",$row['study_id']));
        if($draft)return $this->load($draft['id']);
        $m=P\Database::json($row['metadata_json']);try{return $this->cloneVersion($id,$m['authoring']['revision']??0);}catch(P\PersistenceConflict $error){if($error->getMessage()!=='DRAFT_ALREADY_EXISTS')throw $error;$draft=$this->db->row($this->db->prepare('SELECT id FROM '.$this->db->table('study_versions')." WHERE study_id=%s AND state='DRAFT' ORDER BY version_number DESC LIMIT 1",$row['study_id']));if(!$draft)throw $error;return $this->load($draft['id']);}
    }
    public function duplicateStudy(string $id,int $revision,string $request): array {
        self::authorize();C\Check::id($request,'request_id');
        $study='STUDY_'.substr(hash('sha256','STUDY_DUPLICATE|'.$request),0,32);
        $new=$this->db->transactional(function()use($id,$revision,$study){
            $r=$this->row($id,true);$m=P\Database::json($r['metadata_json']);if(($m['authoring']['revision']??0)!==$revision)throw new P\PersistenceConflict('DRAFT_REVISION_CONFLICT');
            $prior=$this->db->row($this->db->prepare('SELECT id,metadata_json FROM '.$this->db->table('study_versions').' WHERE study_id=%s FOR UPDATE',$study));
            if($prior){$pm=P\Database::json($prior['metadata_json']);if(($pm['duplicate_source']??null)!==$id)throw new P\PersistenceConflict('CREATE_KEY_REUSED');return $prior['id'];}
            $d=Draft::version(P\Database::json($r['protocol_json']),1);$map=array();foreach($d['nodes'] as $n)$map[$n['id']]='N_'.bin2hex(random_bytes(16));foreach($d['condition_rules'] as $rule)$map[$rule['id']]='RULE_'.bin2hex(random_bytes(16));foreach($d['protocol_metadata']['trajectory_conditions']??array() as $rule)$map[$rule['id']]='LC_'.bin2hex(random_bytes(16));
            $retarget=function($v)use(&$retarget,$map){if(is_array($v)){$out=array();foreach($v as $k=>$x)$out[$map[$k]??$k]=$retarget($x);return $out;}return is_string($v)?($map[$v]??$v):$v;};
            // Snapshot content and native response keys belong to the shared Assessment version;
            // only Study graph identities/references are retargeted.
            foreach($d['nodes'] as &$n){$content=$n['content']??null;$response=$n['response_key']??null;$n=$retarget($n);if($content!==null)$n['content']=$content;if($response!==null)$n['response_key']=$response;if(isset($n['logical_id']))$n['logical_id']=$n['id'];}unset($n);
            $d['condition_rules']=$retarget($d['condition_rules']);if(isset($d['protocol_metadata']['trajectory_conditions']))$d['protocol_metadata']['trajectory_conditions']=$retarget($d['protocol_metadata']['trajectory_conditions']);$d['root_block_ids']=$retarget($d['root_block_ids']);
            $name=mb_substr($m['study_admin']['name']??'Study',0,180).' — copia';$public=self::publicId($name,$study);$d['study']=array('logical_id'=>$study,'public_id'=>$public);unset($d['protocol_metadata']['public_slug']);if(isset($d['protocol_metadata']['study_name']))$d['protocol_metadata']['study_name']=$name;
            return $this->insert($d,array('study_admin'=>array('name'=>$name,'public_id'=>$public),'duplicate_source'=>$id,'authoring'=>array('revision'=>0,'saves'=>array()),'lifecycle'=>array(array('event'=>'STUDY_DUPLICATED','source'=>$id,'actor'=>get_current_user_id(),'at'=>P\Database::now()))));
        });return $this->load($new);
    }
    public function archiveStudy(string $id,int $revision): array {
        self::authorize();$this->db->transactional(function()use($id,$revision){$r=$this->row($id,true);$m=P\Database::json($r['metadata_json']);if(($m['authoring']['revision']??0)!==$revision)throw new P\PersistenceConflict('DRAFT_REVISION_CONFLICT');
            $rows=$this->db->rows($this->db->prepare('SELECT id,metadata_json FROM '.$this->db->table('study_versions').' WHERE study_id=%s FOR UPDATE',$r['study_id']));
            foreach($rows as $row){$meta=P\Database::json($row['metadata_json']);$meta['archived']=true;$meta['lifecycle'][]=array('event'=>'STUDY_ARCHIVED','actor'=>get_current_user_id(),'at'=>P\Database::now());$this->db->update('study_versions',array('metadata_json'=>C\Canonical::json($meta),'updated_at'=>P\Database::now()),$this->db->prepare('id=%s',$row['id']));}
            $this->db->update('public_studies',array('enrollment_state'=>'CLOSED','updated_at'=>P\Database::now()),$this->db->prepare("study_id=%s AND enrollment_state<>'FINALIZED'",$r['study_id']));
        });return $this->load($id);
    }
    public function deleteStudy(string $study,string $confirmation): array {
        self::authorize();C\Check::id($study,'study_id');if($confirmation!=='ELIMINAR '.$study)throw new \DomainException('STUDY_DELETE_CONFIRMATION_REQUIRED');
        $this->db->transactional(function()use($study){
            global $wpdb;$versions=$this->db->rows($this->db->prepare('SELECT id FROM '.$this->db->table('study_versions').' WHERE study_id=%s FOR UPDATE',$study));
            if(!$versions)return; // Idempotent retry after a lost response.
            \EIPSI\ExternalApi\ExportStorage::invalidateStudy($study);
            $names=array();foreach(array('study_versions','study_executions','block_executions','execution_events','random_decisions','execution_commands','withdrawal_records','notification_intents','submission_journal','participants','public_studies','participant_sessions','access_capabilities','enrollment_receipts','anonymous_access') as $table)$names[]=$this->db->prepare('%s',$wpdb->prefix.'eipsi_engine_'.$table);
            $engines=$this->db->rows('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.implode(',',$names).')');if(count($engines)!==count($names))throw new P\IntegrityFailure('STUDY_DELETE_SCHEMA_REQUIRED');foreach($engines as $engine)if(strcasecmp($engine['ENGINE'],'InnoDB')!==0)throw new P\IntegrityFailure('STUDY_DELETE_TRANSACTION_REQUIRED');
            $executions=$this->db->rows($this->db->prepare('SELECT id FROM '.$this->db->table('study_executions').' WHERE study_id=%s FOR UPDATE',$study));
            $local=function($table,$where)use($wpdb){$schema=$this->db->row($this->db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->prefix.$table));if(!$schema)return;if(strcasecmp($schema['ENGINE'],'InnoDB')!==0)throw new P\IntegrityFailure('STUDY_DELETE_TRANSACTION_REQUIRED');$this->db->query('DELETE FROM `'.$wpdb->prefix.$table.'` WHERE '.$where);};
            foreach($executions as $e){$id=$e['id'];$where=$this->db->prepare('execution_id=%s',$id);
                $results=$this->db->rows($this->db->prepare('SELECT id FROM `'.$wpdb->prefix.'vas_form_results` WHERE session_id=%s',$id));
                foreach($results as $result)$local('eipsi_device_data',$this->db->prepare('submission_id=%d',$result['id']));
                $local('vas_form_results',$this->db->prepare('session_id=%s',$id));
                foreach(array('vas_form_events','eipsi_partial_responses','eipsi_emergency_submissions') as $table){$cols=$this->db->rows($this->db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->prefix.$table));if(in_array('session_id',array_column($cols,'COLUMN_NAME'),true))$local($table,$this->db->prepare('session_id=%s',$id));}
                $native=$this->db->row($this->db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->prefix.'eipsi_engine_native_executions'));
                if($native){$scope=$this->db->prepare("JSON_UNQUOTE(JSON_EXTRACT(binding_json,'$.execution_id'))=%s AND JSON_UNQUOTE(JSON_EXTRACT(binding_json,'$.kind')) IN ('STUDY_SESSION','STUDY_ANONYMOUS')",$id);$nativeRows=$this->db->rows('SELECT id,expires_at FROM '.$this->db->table('native_executions').' WHERE '.$scope.' FOR UPDATE');foreach($nativeRows as $n){$local('eipsi_engine_native_commands',$this->db->prepare('execution_id=%s',$n['id']));if($n['expires_at']!==null){$optionsEngine=$this->db->row($this->db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->options));if(!$optionsEngine||strcasecmp($optionsEngine['ENGINE'],'InnoDB')!==0)throw new P\IntegrityFailure('STUDY_DELETE_TRANSACTION_REQUIRED');}if($n['expires_at']!==null)wp_clear_scheduled_hook('eipsi_cleanup_partial_responses',array($n['id'],(string)$n['expires_at']));}$local('eipsi_engine_native_executions',$scope);}
                $jobs=$this->db->rows('SELECT queue_job_id FROM '.$this->db->table('notification_intents').' WHERE '.$where);foreach($jobs as $job)if($job['queue_job_id']!==null)$local('survey_nudge_jobs',$this->db->prepare('id=%d',$job['queue_job_id']));
                foreach(array('access_capabilities','participant_sessions','enrollment_receipts','anonymous_access','submission_journal','notification_intents','withdrawal_records','execution_commands','random_decisions','execution_events','block_executions') as $table)$this->db->query('DELETE FROM '.$this->db->table($table).' WHERE '.$where);
            }
            // Also remove admission credentials that have not yet acquired an execution.
            foreach($versions as $version)foreach(array('access_capabilities','participant_sessions','anonymous_access') as $table)$this->db->query($this->db->prepare('DELETE FROM '.$this->db->table($table).' WHERE study_version_id=%s',$version['id']));
            $participants=$this->db->rows($this->db->prepare('SELECT id FROM '.$this->db->table('participants').' WHERE study_id=%s FOR UPDATE',$study));foreach($participants as $p)$this->db->query($this->db->prepare('DELETE FROM '.$this->db->table('enrollment_receipts').' WHERE participant_id=%s',$p['id']));
            \EIPSI\StudyEngine\Runtime\PublicUrl::deleteStudy($this->db,$study);require_once dirname(__DIR__).'/runtime/ResponsePolicy.php';\EIPSI\StudyEngine\Runtime\ResponsePolicy::deleteStudy($this->db,$study);
            // Participants are scoped by study_id; email never determines deletion scope.
            foreach(array('management_exports','management_events','participants','study_executions','public_studies','study_versions') as $table)$this->db->query($this->db->prepare('DELETE FROM '.$this->db->table($table).' WHERE study_id=%s',$study));
            \EIPSI\StudyEngine\Management\Audit::record($study,'STUDY_DELETED',null,null,array('result'=>'DELETED'));
        });return array('deleted'=>true,'study_id'=>$study);
    }
}
