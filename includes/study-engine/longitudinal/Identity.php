<?php
namespace EIPSI\StudyEngine\Longitudinal;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Runtime\AccessDenied;
use EIPSI\StudyEngine\Contracts\Check;

final class Identity {
    private $db;private $clock;
    public function __construct(P\Database $db,?Clock $clock=null){$this->db=$db;$this->clock=$clock??new Clock();$this->schema();}
    private function schema(): void {
        global $wpdb;$names=array();foreach(array('participants','participant_sessions','access_capabilities','enrollment_receipts') as $n)$names[]=$this->db->prepare('%s',$wpdb->prefix.'eipsi_engine_'.$n);
        $rows=$this->db->rows('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.implode(',',$names).')');
        if(count($rows)!==4)throw new P\IntegrityFailure('LONGITUDINAL_SCHEMA_UNAVAILABLE');foreach($rows as $row)if(strcasecmp($row['ENGINE'],'InnoDB')!==0)throw new P\IntegrityFailure('LONGITUDINAL_TRANSACTION_REQUIRED');
    }
    public static function email(string $email): string {
        $email=strtolower(trim($email));$clean=sanitize_email($email);
        if($clean!==$email||strlen($clean)>254||!is_email($clean)||preg_match('/[^\x21-\x7e]/',$clean))throw new \DomainException('INVALID_EMAIL');return $clean;
    }
    public static function cookieName(string $public): string {return 'eipsi_long_'.substr(hash('sha256',$public),0,20);}
    public static function token(): string {return bin2hex(random_bytes(32));}
    public static function csrf(string $public,string $token): string {return hash_hmac('sha256','LONGITUDINAL_CSRF|'.$public,$token);}
    public function participant(string $id,bool $lock=false): ?array {return $this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('participants').' WHERE id=%s'.($lock?' FOR UPDATE':''),$id));}
    public function byEmail(string $study,string $email,bool $lock=false): ?array {
        require_once dirname(__DIR__).'/privacy/Policy.php';$key=\EIPSI\StudyEngine\Privacy\Policy::keyId();
        if($this->db->row($this->db->prepare('SELECT id FROM '.$this->db->table('participants').' WHERE study_id=%s AND email_tombstone IS NOT NULL AND email_tombstone_key<>%s LIMIT 1',$study,$key)))throw new P\IntegrityFailure('PRIVACY_EMAIL_KEY_RECONCILIATION_REQUIRED');
        return $this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('participants').' WHERE study_id=%s AND (email=%s OR email_tombstone=%s)'.($lock?' FOR UPDATE':''),$study,$email,\EIPSI\StudyEngine\Privacy\Policy::emailKey($study,$email)));
    }
    public function create(string $study,string $email): array {
        $this->db->requireTransaction();$p=array('id'=>'PT_'.bin2hex(random_bytes(16)),'study_id'=>$study,'email'=>self::email($email),'verification_state'=>'UNVERIFIED','verified_at'=>null,'active'=>1,'privacy_state'=>'ACTIVE','access_revision'=>0,'execution_id'=>null,'created_at'=>$this->clock->mysql(),'updated_at'=>$this->clock->mysql());$this->db->insert('participants',$p);return $p;
    }
    public function issue(array $p,array $study,string $execution,string $version,array $config,string $token,bool $authenticated=false): array {
        $this->db->requireTransaction();if(!(bool)$p['active']||$p['privacy_state']!=='ACTIVE')throw new AccessDenied('ACCESS_REQUIRED');
        $session=array('id'=>'PS_'.bin2hex(random_bytes(16)),'participant_id'=>$p['id'],'public_study_id'=>$study['public_id'],'execution_id'=>$execution,'study_version_id'=>$version,'recovery_hash'=>hash('sha256',$token),'access_revision'=>$p['access_revision'],'kind'=>$authenticated?'AUTHENTICATED':'PROVISIONAL','expires_at'=>$this->clock->later($authenticated?$config['session_days']*86400:$config['provisional_hours']*3600),'revoked_at'=>null,'created_at'=>$this->clock->mysql());$this->db->insert('participant_sessions',$session);return $session;
    }
    public function recover(string $public,?string $token,bool $lock=false): ?array {
        if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))return null;
        $s=$this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('participant_sessions').' WHERE public_study_id=%s AND recovery_hash=%s AND revoked_at IS NULL AND expires_at>%s'.($lock?' FOR UPDATE':''),$public,hash('sha256',$token),$this->clock->mysql()));
        if(!$s||!in_array($s['kind'],array('AUTHENTICATED','PROVISIONAL','WITHDRAWAL_OPTIONS'),true))return null;$p=$this->participant($s['participant_id'],$lock);$e=(new P\StudyExecutionRepository($this->db))->find($s['execution_id']);$study=(new \EIPSI\StudyEngine\Runtime\PublicStudyRepository($this->db))->find($public);
        if(!$p||!$e||!$study||$e['finalized_at']!==null||$study['enrollment_state']==='FINALIZED'||((!(bool)$p['active']||$p['privacy_state']!=='ACTIVE')&&$s['kind']!=='WITHDRAWAL_OPTIONS')||(int)$p['access_revision']!==(int)$s['access_revision']||$p['execution_id']!==$s['execution_id']||$e['participant_id']!==$p['id']||$e['study_id']!==$p['study_id']||$study['study_id']!==$p['study_id']||$e['study_version_id']!==$s['study_version_id'])return null;return $s;
    }
    public function logout(string $public,string $token): void {$this->db->transactional(function()use($public,$token){$s=$this->recover($public,$token,true);if($s)$this->db->update('participant_sessions',array('revoked_at'=>$this->clock->mysql()),$this->db->prepare('id=%s',$s['id']));});}
    public function revokeParticipant(string $participant): void {$this->db->transactional(function()use($participant){$p=$this->participant($participant,true);if(!$p)throw new AccessDenied('ACCESS_REQUIRED');$this->db->update('participants',array('access_revision'=>(int)$p['access_revision']+1,'updated_at'=>$this->clock->mysql()),$this->db->prepare('id=%s',$participant));});}

}
/** Authorization is rechecked under the scientific transition lock, including receipt replay. */
final class SessionGuard extends P\TransitionObserver {
    private $identity;private $public;private $token;private $session;private $observer;
    public function __construct(Identity $identity,string $public,string $token,array $session,?P\TransitionObserver $observer=null){$this->identity=$identity;$this->public=$public;$this->token=$token;$this->session=$session;$this->observer=$observer;}
    public function reached(string $point): void {
        if(in_array($point,array('before_receipt','after_command_claim','read_authorization'),true)){$s=$this->identity->recover($this->public,$this->token,true);if(!$s||$s['id']!==$this->session['id'])throw new AccessDenied('ACCESS_REQUIRED');}
        if($this->observer)$this->observer->reached($point);
    }
}
