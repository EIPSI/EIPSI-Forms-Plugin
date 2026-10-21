<?php
namespace EIPSI\StudyEngine\Runtime;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Contracts\Canonical;

/** Existing local Storage schema/privacy policy, same raw connection and owner transaction. */
final class LocalResponseSink implements P\FinalResponseSink {
    private $metadata;
    public function __construct(array $metadata=array()){$this->metadata=$metadata;}
    public function store(P\Database $db,string $execution,array $instance,array $responses,string $versionId,array $snapshot): string {
        global $wpdb;$db->requireTransaction();$table=$wpdb->prefix.'vas_form_results';
        $engine=$db->row($db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
        if(!$engine||strcasecmp($engine['ENGINE'],'InnoDB')!==0)throw new P\IntegrityFailure('LOCAL_STORAGE_TRANSACTION_REQUIRED');
        $v=(new P\StudyVersionRepository($db))->find($versionId);if(!$v)throw new P\IntegrityFailure('STORAGE_VERSION_REQUIRED');
        $definition=(new P\StudyVersionRepository($db))->compiled($v)->definition();$keys=array();foreach($definition['nodes'] as $n)if($n['kind']==='QUESTION')$keys[$n['id']]=$n['response_key'];
        $answers=array();foreach($responses as $question=>$answer)$answers[$keys[$question]]=$answer['value'];
        $submission='SJ_'.substr(hash('sha256',$execution.'|'.$instance['id']),0,32);$start=$instance['started_at']??$snapshot['execution']['created_at'];$end=$snapshot['clock'];$duration=max(0,strtotime($end)-strtotime($start));
        $metadata=\EIPSI\Dataset\EffectiveMetadataPolicy::legacyMetadata($this->metadata);$metadata['study_engine']=array('study_version_id'=>$versionId,'study_execution_id'=>$execution,'block_execution_id'=>$instance['id'],'occurrence_key'=>$instance['occurrence_key'],'submission_id'=>$submission,'coverage'=>$snapshot['coverage'][$instance['id']]??array(),'storage_mode'=>'WORDPRESS_LOCAL','form_start'=>$start,'form_end'=>$end);
        $subject=(new P\StudyExecutionRepository($db))->find($execution);
        if($subject['participant_id']!==null){$metadata['study_engine']['timezone']=$snapshot['execution']['timezone'];$metadata['study_engine']['timezone_source']=$snapshot['execution']['timezone_source'];}
        $row=array('form_id'=>'SE_'.substr(hash('sha256',$versionId.'|'.$instance['definition_id']),0,17),'participant_id'=>($subject['participant_id']??$subject['anonymous_identity_id']),'session_id'=>$execution,'form_name'=>$v['study_id'],'created_at'=>P\Database::date($start),'submitted_at'=>P\Database::date($end),'device'=>$metadata['device']??null,'browser'=>$metadata['browser']??null,'os'=>$metadata['os']??null,'screen_width'=>$metadata['screen_width']??null,'user_fingerprint'=>$metadata['fingerprint']??null,'duration'=>$duration,'duration_seconds'=>$duration,'start_timestamp_ms'=>strtotime($start)*1000,'end_timestamp_ms'=>strtotime($end)*1000,'metadata'=>Canonical::json($metadata),'status'=>'submitted','form_responses'=>Canonical::json($answers));
        require_once EIPSI_FORMS_PLUGIN_DIR.'admin/privacy-config.php';
        $row=eipsi_filter_capture_data($row,\EIPSI\Dataset\EffectiveMetadataPolicy::legacy(get_privacy_config($row['form_id'])));
        // No legacy external/emergency retry: local-first is an explicit StudyVersion contract.
        $cols=array();$values=array();foreach($row as $key=>$value){if(!preg_match('/^[a-z_]+$/D',$key))throw new P\IntegrityFailure('UNSAFE_STORAGE_COLUMN');$cols[]='`'.$key.'`';$values[]=$value===null?'NULL':$db->prepare('%s',(string)$value);}
        $db->query('INSERT INTO `'.$table.'` ('.implode(',',$cols).') VALUES ('.implode(',',$values).')');
        $id=$db->row('SELECT LAST_INSERT_ID() AS id')['id']??null;if(!$id)throw new P\IntegrityFailure('LOCAL_STORAGE_CONFIRMATION_MISSING');return 'WORDPRESS_DB_'.$id;
    }
}
