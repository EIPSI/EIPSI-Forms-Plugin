<?php
namespace EIPSI\StudyEngine\Longitudinal;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Runtime\AccessDenied;

/** Dedicated signed opaque credential. Scope is server-side, never email/answers in URL. */
final class Capability {
    public const PURPOSES=array('VERIFY_PARTICIPANT','ASSESSMENT_AVAILABLE','ASSESSMENT_REMINDER','WITHDRAWAL_OPTIONS');
    private $db;private $keys;private $current;private $clock;
    public function __construct(P\Database $db,array $keys,string $current,?Clock $clock=null){
        foreach($keys as $id=>$key)if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',(string)$id)||!is_string($key)||strlen($key)!==32)throw new P\IntegrityFailure('INVALID_ACCESS_KEYRING');
        if(!isset($keys[$current]))throw new P\IntegrityFailure('ACCESS_KEY_UNAVAILABLE');$this->db=$db;$this->keys=$keys;$this->current=$current;$this->clock=$clock??new Clock();
    }
    public static function configured(P\Database $db,?Clock $clock=null): self {
        $config=defined('EIPSI_STUDY_ENGINE_ACCESS_KEYRING')?EIPSI_STUDY_ENGINE_ACCESS_KEYRING:getenv('EIPSI_STUDY_ENGINE_ACCESS_KEYRING');if(is_string($config))$config=json_decode($config,true);
        if(!is_array($config)||!isset($config['keys'],$config['current']))throw new P\IntegrityFailure('ACCESS_KEYRING_UNAVAILABLE');
        $keys=array();foreach($config['keys'] as $id=>$hex){if(!is_string($hex)||!preg_match('/^[a-f0-9]{64}$/D',$hex))throw new P\IntegrityFailure('INVALID_ACCESS_KEYRING');$keys[$id]=hex2bin($hex);}return new self($db,$keys,$config['current'],$clock);
    }
    public function mint(array $participant,array $study,array $execution,string $purpose,?string $block=null,?string $intent=null,int $hours=48): string {
        $this->db->requireTransaction();if(!in_array($purpose,self::PURPOSES,true)||$hours<1||$hours>48||!(bool)$participant['active']||$participant['privacy_state']!=='ACTIVE')throw new AccessDenied('INVALID_CAPABILITY_SCOPE');
        if($participant['execution_id']!==$execution['id']||$participant['study_id']!==$execution['study_id']||$study['study_id']!==$participant['study_id']||$execution['participant_id']!==$participant['id'])throw new AccessDenied('INVALID_CAPABILITY_SCOPE');
        $revision=(int)$execution['schedule_revision'];if($block!==null){$b=$this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('block_executions').' WHERE id=%s',$block));if(!$b||$b['execution_id']!==$execution['id'])throw new AccessDenied('INVALID_CAPABILITY_SCOPE');$revision=(int)$b['schedule_revision'];}
        $envelope=$this->current.'.'.bin2hex(random_bytes(32));$token=$envelope.'.'.hash_hmac('sha256','EIPSI_ENGINE_CAPABILITY_V1|'.$envelope,$this->keys[$this->current]);
        $this->db->insert('access_capabilities',array('id'=>'CP_'.bin2hex(random_bytes(16)),'participant_id'=>$participant['id'],'public_study_id'=>$study['public_id'],'execution_id'=>$execution['id'],'study_version_id'=>$execution['study_version_id'],'block_execution_id'=>$block,'purpose'=>$purpose,'key_id'=>$this->current,'token_hash'=>hash('sha256',$token),'access_revision'=>$participant['access_revision'],'schedule_revision'=>$revision,'issued_at'=>$this->clock->mysql(),'expires_at'=>$this->clock->later($hours*3600),'consumed_at'=>null,'revoked_at'=>null,'intent_id'=>$intent));return $token;
    }
    public function find(string $public,string $token,bool $lock=false): array {
        if(!preg_match('/^([A-Za-z0-9_-]{1,64})\.([a-f0-9]{64})\.([a-f0-9]{64})$/D',$token,$m)||!isset($this->keys[$m[1]])||!hash_equals(hash_hmac('sha256','EIPSI_ENGINE_CAPABILITY_V1|'.$m[1].'.'.$m[2],$this->keys[$m[1]]),$m[3]))throw new AccessDenied('ACCESS_REQUIRED');
        $row=$this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('access_capabilities').' WHERE public_study_id=%s AND token_hash=%s'.($lock?' FOR UPDATE':''),$public,hash('sha256',$token)));
        if(!$row||$row['key_id']!==$m[1]||$row['revoked_at']!==null||$row['expires_at']<=$this->clock->mysql()||!in_array($row['purpose'],self::PURPOSES,true))throw new AccessDenied('ACCESS_REQUIRED');
        if($row['intent_id']!==null){$intent=$this->db->row($this->db->prepare('SELECT state FROM '.$this->db->table('notification_intents').' WHERE id=%s',$row['intent_id']));if(!$intent||in_array($intent['state'],array('CANCELLED','FAILED_FINAL'),true))throw new AccessDenied('ACCESS_REQUIRED');}
        return $row;
    }
    public function consume(array $row): void {
        if($this->db->update('access_capabilities',array('consumed_at'=>$this->clock->mysql()),$this->db->prepare('id=%s AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at>%s',$row['id'],$this->clock->mysql()))!==1)throw new AccessDenied('ACCESS_REQUIRED');
    }
    public function revokeIntent(string $id): void {$this->db->update('access_capabilities',array('revoked_at'=>$this->clock->mysql()),$this->db->prepare('intent_id=%s AND revoked_at IS NULL',$id));}
    public static function url(string $public,string $token): string {return add_query_arg(array('eipsi_study'=>$public,'eipsi_cap'=>$token),home_url('/'));}
}
