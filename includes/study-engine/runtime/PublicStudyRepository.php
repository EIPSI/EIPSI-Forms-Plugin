<?php
namespace EIPSI\StudyEngine\Runtime;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Contracts\Check;
require_once __DIR__.'/PublicUrl.php';

/** Deliberate internal enrollment API, unrelated to survey_studies or WP Pages. */
final class PublicStudyRepository {
    private $db;private $joining=false;
    public function __construct(P\Database $db){
        $this->db=$db;global $wpdb;
        $tables=$db->rows($db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s)',$wpdb->prefix.'eipsi_engine_public_studies',$wpdb->prefix.'eipsi_engine_anonymous_access'));
        if(count($tables)!==2)throw new P\IntegrityFailure('PUBLIC_RUNTIME_SCHEMA_UNAVAILABLE');
        foreach($tables as $table)if(strcasecmp($table['ENGINE'],'InnoDB')!==0)throw new P\IntegrityFailure('PUBLIC_RUNTIME_TRANSACTION_REQUIRED');
    }
    public function find(string $public,bool $lock=false): ?array {Check::id($public,'public');return $this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('public_studies').' WHERE public_id=%s'.($lock?' FOR UPDATE':''),$public));}
    public function activate(string $version,string $state='OPEN',bool $allowRestart=false): string {
        if(!in_array($state,array('OPEN','PAUSED','CLOSED'),true))throw new \DomainException('INVALID_ENROLLMENT_STATE');
        return $this->activateOwned($version,$state,$allowRestart,null,null);
    }
    public function activateFromBuilder(string $version,int $revision,string $state,int $actor): string {
        if(!current_user_can('manage_options'))throw new \DomainException('BUILDER_FORBIDDEN');
        if(!in_array($state,array('OPEN','PAUSED','CLOSED'),true))throw new \DomainException('INVALID_ENROLLMENT_STATE');
        return $this->activateOwned($version,$state,false,$revision,$actor);
    }
    public static function finalizedHtml(array $definition=array()): string {return self::availabilityHtml($definition,'FINALIZED');}
    public static function availabilityHtml(array $definition,string $state): string {
        $key=$state==='FINALIZED'?'finalized_body':'paused_body';
        return '<main id="eipsi-study" class="eipsi-study eipsi-study-state" data-state="'.esc_attr($state).'"><p role="status">'.esc_html(PublicMessages::text($definition,$key)).'</p></main>';
    }
    /** Serialize finalization against the external delivery window, including SMTP. */
    public static function deliveryLock(P\Database $db,string $study,callable $work) {
        global $wpdb;$name='eipsi_delivery_'.substr(hash('sha256',DB_NAME.'|'.$wpdb->prefix.'|'.$study),0,40);
        if((int)($db->row($db->prepare('SELECT GET_LOCK(%s,10) AS acquired',$name))['acquired']??0)!==1)throw new P\PersistenceConflict('STUDY_DELIVERY_BUSY');
        try{return $work();}finally{$db->row($db->prepare('SELECT RELEASE_LOCK(%s) AS released',$name));}
    }
    public function activateInTransaction(string $version,string $state): string {
        $this->db->requireTransaction();$this->joining=true;try{return $this->activateOwned($version,$state,false,null,null);}finally{$this->joining=false;}
    }
    private function activateOwned(string $version,string $state,bool $allowRestart,?int $revision,?int $actor): string {
        $mode=null;$work=function()use($version,$state,$allowRestart,$revision,$actor,&$mode){
            $versions=new P\StudyVersionRepository($this->db);$v=$versions->find($version,true);if(!$v||$v['state']!=='PUBLISHED')throw new \DomainException('PUBLISHED_VERSION_REQUIRED');
            $metadata=P\Database::json($v['metadata_json']);if(!empty($metadata['archived']))throw new \DomainException('STUDY_ARCHIVED');if($revision!==null&&($metadata['authoring']['revision']??0)!==$revision)throw new P\PersistenceConflict('DRAFT_REVISION_CONFLICT');
            $d=$versions->compiled($v)->definition();if(($d['protocol_metadata']['content_adapter']??null)!=='P1E_V1'||($d['protocol_metadata']['storage_mode']??null)!=='WORDPRESS_LOCAL')throw new \DomainException('CONTENT_ADAPTER_REQUIRED');
            $mode=$d['protocol_metadata']['enrollment_mode']??'ANONYMOUS';if(($d['protocol_metadata']['study_type']??null)==='TRANSVERSAL')$allowRestart=($d['protocol_metadata']['unique_response']??false)!==true;if(!in_array($mode,array('ANONYMOUS','EMAIL_LONGITUDINAL'),true))throw new \DomainException('INVALID_ENROLLMENT_MODE');
            if($mode==='EMAIL_LONGITUDINAL'){require_once dirname(__DIR__).'/longitudinal.php';\EIPSI\StudyEngine\Longitudinal\Configuration::read($d);if($allowRestart)throw new \DomainException('LONGITUDINAL_RESTART_NOT_SUPPORTED');}
            $public=$d['study']['public_id'];if(PublicUrl::resolve($this->db,$public)!==$public)throw new P\PersistenceConflict('PUBLIC_URL_ALREADY_USED');$prior=$this->find($public,true);
            if(($prior['enrollment_state']??null)==='FINALIZED')throw new \DomainException('STUDY_FINALIZED');
            $row=array('study_id'=>$v['study_id'],'active_version_id'=>$version,'enrollment_mode'=>$mode,'enrollment_state'=>$state,'allow_restart'=>$allowRestart?1:0,'updated_at'=>P\Database::now(),'lifecycle_revision'=>(int)($prior['lifecycle_revision']??0)+1);
            if($prior&&$prior['enrollment_mode']!==$mode)throw new \DomainException('ENROLLMENT_MODE_IMMUTABLE');
            if($prior){if($prior['study_id']!==$v['study_id'])throw new \DomainException('PUBLIC_STUDY_ID_IMMUTABLE');$this->db->update('public_studies',$row,$this->db->prepare('public_id=%s',$public));}
            else{$row+=array('public_id'=>$public,'created_at'=>P\Database::now());$this->db->insert('public_studies',$row);}
            if($actor!==null){$metadata['authoring']['revision']=$revision+1;$metadata['lifecycle'][]=array('event'=>'ENROLLMENT_ACTIVATED','actor'=>$actor,'at'=>P\Database::now(),'previous'=>$prior['active_version_id']??null,'state'=>$state);$this->db->update('study_versions',array('metadata_json'=>\EIPSI\StudyEngine\Contracts\Canonical::json($metadata)),$this->db->prepare('id=%s',$version));}return $public;
        };$public=$this->joining?$work():$this->db->transactional($work);
        if(!$this->joining&&$mode==='EMAIL_LONGITUDINAL'){update_option('eipsi_study_engine_longitudinal_enabled',1,false);require_once dirname(__DIR__).'/longitudinal/bootstrap.php';\EIPSI_Cron_Registry::schedule_study_engine();}
        return $public;
    }
}
