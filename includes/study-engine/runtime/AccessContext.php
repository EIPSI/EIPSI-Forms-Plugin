<?php
namespace EIPSI\StudyEngine\Runtime;
use EIPSI\StudyEngine\Persistence as P;

/** Rechecks and locks recovery inside the owner transaction, before any domain mutation. */
final class AccessContext extends P\TransitionObserver {
    private $db;
    private $access;
    private $hash;
    private $observer;
    public function __construct(P\Database $db,array $access,string $token,?P\TransitionObserver $observer=null){$this->db=$db;$this->access=$access;$this->hash=hash('sha256',$token);$this->observer=$observer;}
    public function reached(string $point): void {
        if($point==='after_command_claim'){
            $this->db->requireTransaction();$row=$this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('anonymous_access').' WHERE id=%s FOR UPDATE',$this->access['id']));
            if(!$row||$row['revoked_at']!==null||!hash_equals($this->hash,$row['recovery_hash'])||$row['execution_id']!==$this->access['execution_id']||$row['public_study_id']!==$this->access['public_study_id']||$row['study_version_id']!==$this->access['study_version_id']||strtotime($row['created_at'].' UTC')<time()-365*86400)throw new AccessDenied('ACCESS_REQUIRED');
        }
        if($this->observer!==null)$this->observer->reached($point);
    }
}
