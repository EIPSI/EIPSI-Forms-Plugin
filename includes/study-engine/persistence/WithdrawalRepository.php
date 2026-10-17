<?php
namespace EIPSI\StudyEngine\Persistence;
use EIPSI\StudyEngine\Contracts\Canonical;
use EIPSI\StudyEngine\Contracts\WithdrawalRecord;

final class WithdrawalRepository {
    private $db;
    public function __construct(Database $db){$this->db=$db;}
    public function find(string $execution): ?array {return $this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('withdrawal_records').' WHERE execution_id=%s',$execution));}
    public function persist(string $execution,array $subject,?array $old,?array $new): void {
        if($old!==null){
            if(Canonical::json($old)===Canonical::json($new))return;
            if($old['mode']!=='RETAIN_FOR_ANALYSIS'||$new['mode']!=='DO_NOT_USE'||$old['id']!==$new['id'])throw new IntegrityFailure('WITHDRAWAL_IMMUTABLE');
            $row=$this->find($execution);$d=Database::json($row['snapshot_json']);$d['mode']='DO_NOT_USE';$d['data_policy']='DO_NOT_USE';$d['cleanup_state']='PENDING';new WithdrawalRecord($d);
            $this->db->update('withdrawal_records',array('mode'=>'DO_NOT_USE','data_policy'=>'DO_NOT_USE','cleanup_state'=>'PENDING','snapshot_json'=>Canonical::json($d)), $this->db->prepare('execution_id=%s',$execution));return;
        }
        if($new===null)return;
        $d=$new;$d['study_execution_id']=$execution;$d['subject']=$subject;$d['source']=$new['source']??'ADMIN_REQUEST';$d['coverage']=array('source'=>'DURABLE_FOUNDATION','complete'=>false,'not_covered'=>array('PRIVACY_CLEANUP_P1G','LEGACY_STORAGE','EXTERNAL_SYSTEMS','BACKUPS','DELIVERED_EMAIL'));
        new WithdrawalRecord($d);
        $this->db->insert('withdrawal_records',array('id'=>$d['id'],'execution_id'=>$execution,'participant_id'=>$subject['kind']==='PARTICIPANT'?$subject['id']:null,'anonymous_identity_id'=>$subject['kind']==='ANONYMOUS'?$subject['id']:null,'mode'=>$d['mode'],'data_policy'=>$d['data_policy'],'decided_at'=>Database::date($d['decided_at']),'source'=>$d['source'],'cleanup_state'=>$d['cleanup_state'],'coverage_json'=>Canonical::json($d['coverage']),'snapshot_json'=>Canonical::json($d),'created_at'=>Database::now()));
    }
}
