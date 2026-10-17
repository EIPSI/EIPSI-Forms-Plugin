<?php
namespace EIPSI\StudyEngine\Runtime;
use EIPSI\StudyEngine\Persistence as P;

/** Bearer authority is hashed and study-scoped. Fingerprint never grants access. */
final class AnonymousAccess {
    private $db;
    public function __construct(P\Database $db){$this->db=$db;}
    public static function cookieName(string $public): string {return 'eipsi_study_'.substr(hash('sha256',$public),0,20);}
    public static function token(): string {return bin2hex(random_bytes(32));}
    public static function csrf(string $public,string $token): string {return hash_hmac('sha256','EIPSI_STUDY_CSRF_V1|'.$public,$token);}
    public function recover(string $public,string $token): ?array {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))return null;
        return $this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('anonymous_access').' WHERE recovery_hash=%s AND public_study_id=%s AND revoked_at IS NULL AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 365 DAY)',hash('sha256',$token),$public));
    }
    public function reserve(string $public,string $token): array {
        return $this->db->transactional(function()use($public,$token){
            $prior=$this->recover($public,$token);if($prior)return $prior;
            $study=(new PublicStudyRepository($this->db))->find($public,true);if(!$study||$study['enrollment_state']!=='OPEN')throw new \DomainException('ENROLLMENT_UNAVAILABLE');
            require_once __DIR__.'/ResponsePolicy.php';ResponsePolicy::admission($this->db,$study);
            $id='AI_'.bin2hex(random_bytes(16));$now=P\Database::now();
            $row=array('id'=>$id,'public_study_id'=>$public,'study_version_id'=>$study['active_version_id'],'recovery_hash'=>hash('sha256',$token),'execution_id'=>null,'created_at'=>$now,'last_used_at'=>$now,'revoked_at'=>null);$this->db->insert('anonymous_access',$row);return $row;
        });
    }
    public function link(array $access,string $execution): void {
        $this->db->transactional(function()use($access,$execution){
            $row=$this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('anonymous_access').' WHERE id=%s FOR UPDATE',$access['id']));
            if(!$row||$row['revoked_at']!==null||($row['execution_id']!==null&&$row['execution_id']!==$execution))throw new P\IntegrityFailure('ACCESS_LINK_INVALID');
            $e=(new P\StudyExecutionRepository($this->db))->find($execution);
            if(!$e||$e['anonymous_identity_id']!==$row['id']||$e['study_version_id']!==$row['study_version_id'])throw new P\IntegrityFailure('ACCESS_EXECUTION_BINDING_INVALID');
            $this->db->update('anonymous_access',array('execution_id'=>$execution,'last_used_at'=>P\Database::now()),$this->db->prepare('id=%s',$row['id']));
        });
    }
    public function revoke(string $id): void {$this->db->transactional(function()use($id){$this->db->update('anonymous_access',array('revoked_at'=>P\Database::now()),$this->db->prepare('id=%s',$id));});}
    public function rotate(string $public,string $old): string {
        $new=self::token();$this->db->transactional(function()use($public,$old,$new){$row=$this->recover($public,$old);if(!$row)throw new \DomainException('ACCESS_REQUIRED');$changed=$this->db->update('anonymous_access',array('recovery_hash'=>hash('sha256',$new),'last_used_at'=>P\Database::now()),$this->db->prepare('id=%s AND recovery_hash=%s AND revoked_at IS NULL',$row['id'],hash('sha256',$old)));if($changed!==1)throw new P\PersistenceConflict('RECOVERY_ROTATION_CONFLICT');});return $new;
    }
}
