<?php
namespace EIPSI\StudyEngine\Privacy;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Longitudinal as L;
use EIPSI\StudyEngine\Runtime as R;
/** Authority is checked again under the execution lock; never accept posted subject IDs. */
final class AccessContext {
    private $db;private $public;private $token;private $kind;private $clock;private $source='SESSION';
    private function __construct(P\Database $db,string $public,string $token,string $kind,?L\Clock $clock=null){$this->db=$db;$this->public=$public;$this->token=$token;$this->kind=$kind;$this->clock=$clock??new L\Clock();}
    public static function session(P\Database $db,string $public,string $token,?L\Clock $clock=null): self {return new self($db,$public,$token,'SESSION',$clock);}
    public static function anonymous(P\Database $db,string $public,string $token): self {return new self($db,$public,$token,'ANONYMOUS');}
    public static function email(P\Database $db,string $public,string $email,?L\Clock $clock=null): self {return new self($db,$public,L\Identity::email($email),'EMAIL_ENTRY',$clock);}
    public function source(): string {return $this->source;}
    public function authorize(array $row): void {
        $this->db->requireTransaction();
        if($this->kind==='EMAIL_ENTRY'){
            $study=(new R\PublicStudyRepository($this->db))->find($this->public);
            $p=$study?(new L\Identity($this->db,$this->clock))->byEmail($study['study_id'],$this->token,true):null;
            if(!$study||$study['enrollment_state']==='FINALIZED'||!$p||$p['id']!==$row['participant_id']||$p['execution_id']!==$row['id']||$row['study_id']!==$study['study_id']||$row['finalized_at']!==null)throw new R\AccessDenied('ACCESS_REQUIRED');
            $this->source='EMAIL_ENTRY';return;
        }
        if($this->kind==='ANONYMOUS'){
            $s=(new R\AnonymousAccess($this->db))->recover($this->public,$this->token);
            if(!$s&&preg_match('/^[a-f0-9]{64}$/D',$this->token))$s=$this->db->row($this->db->prepare('SELECT a.* FROM '.$this->db->table('anonymous_access').' a JOIN '.$this->db->table('withdrawal_records').' w ON w.execution_id=a.execution_id WHERE a.public_study_id=%s AND a.recovery_hash=%s AND a.revoked_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 48 HOUR)',$this->public,hash('sha256',$this->token)));
            if(!$s||$s['execution_id']!==$row['id']||$s['id']!==$row['anonymous_identity_id'])throw new R\AccessDenied('ACCESS_REQUIRED');return;
        }
        $s=(new L\Identity($this->db,$this->clock))->recover($this->public,$this->token,true);
        if(!$s||$s['execution_id']!==$row['id']||$s['participant_id']!==$row['participant_id']||!in_array($s['kind'],array('WITHDRAWAL_OPTIONS','AUTHENTICATED','PROVISIONAL'),true))throw new R\AccessDenied('INVALID_SESSION_PURPOSE');
        $this->source=$s['kind']==='WITHDRAWAL_OPTIONS'?'EMAIL_CAPABILITY':'SESSION';
    }
}
