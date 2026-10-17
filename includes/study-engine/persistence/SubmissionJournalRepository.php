<?php
namespace EIPSI\StudyEngine\Persistence;
use EIPSI\StudyEngine\Contracts\Canonical;

/** Local checkpoint confirms responses; this journal links them without copying answers. */
final class SubmissionJournalRepository {
    private $db;
    public function __construct(Database $db){$this->db=$db;}
    public function list(string $execution): array {return $this->db->rows($this->db->prepare('SELECT * FROM '.$this->db->table('submission_journal').' WHERE execution_id=%s',$execution));}
    public function confirmLocal(string $execution,array $instance,array $responses,string $versionId,?string $externalDestination=null,?string $localStorageRef=null): string {
        $id='SJ_'.substr(hash('sha256',$execution.'|'.$instance['id']),0,32);$hash=hash('sha256',Canonical::json($responses));
        $prior=$this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('submission_journal').' WHERE submission_id=%s',$id));
        if($prior){if(!hash_equals($prior['response_hash'],$hash)||$prior['destination_ref']!==$externalDestination||$prior['local_storage_ref']!==$localStorageRef)throw new IntegrityFailure('FINAL_RESPONSE_IMMUTABLE');return $id;}
        $this->db->insert('submission_journal',array('submission_id'=>$id,'execution_id'=>$execution,'block_execution_id'=>$instance['id'],'study_version_id'=>$versionId,'occurrence_json'=>Canonical::json($instance['occurrence_key']),'response_hash'=>$hash,'local_storage_ref'=>$localStorageRef,'local_state'=>'CONFIRMED','replication_state'=>$externalDestination===null?'NOT_REQUESTED':'PENDING','destination_ref'=>$externalDestination,'external_confirmation'=>null,'attempts'=>0,'created_at'=>Database::now(),'updated_at'=>Database::now()));return $id;
    }
    /** Foundation only: caller owns external IO. Repeat failures preserve stable ID and local commit. */
    public function replicationResult(string $submission,?string $confirmation): void {
        $this->db->requireTransaction();$row=$this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('submission_journal').' WHERE submission_id=%s FOR UPDATE',$submission));
        if(!$row||$row['destination_ref']===null)throw new \DomainException('REPLICATION_NOT_REQUESTED');
        if($row['replication_state']==='CONFIRMED'){if($confirmation!==$row['external_confirmation'])throw new PersistenceConflict('EXTERNAL_CONFIRMATION_IMMUTABLE');return;}
        $this->db->update('submission_journal',array('replication_state'=>$confirmation===null?'FAILED_RETRYABLE':'CONFIRMED','external_confirmation'=>$confirmation,'attempts'=>(int)$row['attempts']+1,'updated_at'=>Database::now()),$this->db->prepare('submission_id=%s',$submission));
    }
}
