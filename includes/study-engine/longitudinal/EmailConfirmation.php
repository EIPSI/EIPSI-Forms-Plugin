<?php
namespace EIPSI\StudyEngine\Longitudinal;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Contracts\Canonical;
/** Identity-only commands use namespaced private admission receipts; scientific checkpoints stay intact. */
final class EmailConfirmation {
    private static function key(string $execution): string {return hash('sha256','EMAIL_CONFIRMATION_V1|'.$execution);}
    public static function read(P\Database $db,string $execution,bool $lock=false): ?array {
        $r=$db->row($db->prepare('SELECT * FROM '.$db->table('enrollment_receipts').' WHERE id=%s'.($lock?' FOR UPDATE':''),self::key($execution)));
        if(!$r)return null;$state=P\Database::json($r['response_json']);if(($state['contract']??null)!=='EMAIL_CONFIRMATION_V1'||$r['execution_id']!==$execution)throw new P\IntegrityFailure('EMAIL_CONFIRMATION_BINDING');return $state;
    }
    public static function needs(P\Database $db,array $config,array $snapshot,array $definition): bool {
        if(InitialScreen::v2($config)){
            $execution=(new P\StudyExecutionRepository($db))->find($snapshot['execution']['id']);$participant=$execution?$db->row($db->prepare('SELECT verification_state FROM '.$db->table('participants').' WHERE id=%s',$execution['participant_id'])):null;
            $access=$snapshot['first_access_at']??$execution['first_access_at']??null;
            if(!$participant||$participant['verification_state']==='VERIFIED'||$access===null||!InitialScreen::available($snapshot,$definition,$access))return false;
        }
        return InitialScreen::reviewRequired($config)&&InitialScreen::firstCompleted($snapshot,$definition)&&(self::read($db,$snapshot['execution']['id'])['state']??null)!=='CONFIRMED';
    }
    public static function request(P\Database $db,array $study,array $participant,array $snapshot,string $email,string $command,Clock $clock,?array $reviewSession=null): void {
        $db->requireTransaction();$execution=$snapshot['execution']['id'];$email=Identity::email($email);$key=hash('sha256','EMAIL_CONFIRM_COMMAND_V1|'.$execution.'|'.$command);$hash=hash('sha256',Canonical::json(array('email'=>$email,'participant'=>$participant['id'])));
        $prior=$db->row($db->prepare('SELECT * FROM '.$db->table('enrollment_receipts').' WHERE id=%s FOR UPDATE',$key));if($prior){if(!hash_equals($prior['request_hash'],$hash))throw new P\PersistenceConflict('EMAIL_CONFIRM_COMMAND_REUSED');return;}
        $old=self::read($db,$execution,true);if(($old['state']??null)==='CONFIRMED')throw new \DomainException('EMAIL_CONFIRMATION_ALREADY_COMPLETE');
        $other=(new Identity($db,$clock))->byEmail($study['study_id'],$email,true);if($other&&$other['id']!==$participant['id'])throw new \DomainException('EMAIL_ALREADY_ENROLLED');
        if($reviewSession!==null){
            // Direct first access: the current session may correct its own address, without claiming ownership of the new email.
            $changed=$email!==$participant['email'];
            if($changed){
                $participant['email']=$email;$participant['access_revision']=(int)$participant['access_revision']+1;
                $db->update('participants',array('email'=>$email,'access_revision'=>$participant['access_revision'],'verification_state'=>'UNVERIFIED','verified_at'=>null,'updated_at'=>$clock->mysql()),$db->prepare('id=%s',$participant['id']));
                $db->update('access_capabilities',array('revoked_at'=>$clock->mysql()),$db->prepare('participant_id=%s AND revoked_at IS NULL',$participant['id']));
                $db->update('participant_sessions',array('revoked_at'=>$clock->mysql()),$db->prepare('participant_id=%s AND id<>%s AND revoked_at IS NULL',$participant['id'],$reviewSession['id']));
                $db->update('participant_sessions',array('access_revision'=>$participant['access_revision'],'kind'=>'PROVISIONAL'),$db->prepare('id=%s',$reviewSession['id']));
            }
            $state=array('contract'=>'EMAIL_CONFIRMATION_V1','state'=>'CONFIRMED','intent_id'=>null,'email'=>$email,'previous_email'=>$email,'access_revision'=>(int)$participant['access_revision'],'requested_at'=>$clock->now(),'confirmed_at'=>$clock->now());
            self::write($db,$study,$participant,self::key($execution),$hash,$state,$clock,$old!==null);
            self::write($db,$study,$participant,$key,$hash,array('contract'=>'EMAIL_CONFIRM_COMMAND_V1','accepted'=>true),$clock,false);return;
        }
        // Invalidate links to the previous address or an earlier correction. Keep the current browser usable.
        $db->update('access_capabilities',array('revoked_at'=>$clock->mysql()),$db->prepare('participant_id=%s AND consumed_at IS NULL AND revoked_at IS NULL',$participant['id']));
        $db->update('notification_intents',array('state'=>'CANCELLED','cancel_reason'=>'EMAIL_CONFIRMATION_SUPERSEDED','updated_at'=>$clock->mysql()),$db->prepare("execution_id=%s AND block_execution_id IS NULL AND purpose IN ('ACCESS','VERIFY_PARTICIPANT') AND state IN ('PENDING','CLAIMED','FAILED_RETRYABLE')",$execution));
        $intent=(new NotificationScheduler($db))->authentication($execution,'VERIFY_PARTICIPANT','EMAIL_CONFIRM_'.substr(hash('sha256',$command),0,32),$snapshot);
        $state=array('contract'=>'EMAIL_CONFIRMATION_V1','state'=>'PENDING','intent_id'=>$intent,'email'=>$email,'previous_email'=>$participant['email'],'access_revision'=>(int)$participant['access_revision'],'requested_at'=>$clock->now(),'confirmed_at'=>null);
        self::write($db,$study,$participant,self::key($execution),$hash,$state,$clock,$old!==null);
        self::write($db,$study,$participant,$key,$hash,array('contract'=>'EMAIL_CONFIRM_COMMAND_V1','accepted'=>true),$clock,false);
    }
    /** Called after capability consumption, inside the same execution/identity transaction. */
    public static function accept(P\Database $db,array $cap,array $study,array $participant,Clock $clock): array {
        if($cap['purpose']!=='VERIFY_PARTICIPANT'||$cap['intent_id']===null)return $participant;
        $state=self::read($db,$cap['execution_id'],true);if(!$state||$state['intent_id']!==$cap['intent_id'])return $participant;
        if($state['state']!=='PENDING'||$state['previous_email']!==$participant['email']||$state['access_revision']!==(int)$participant['access_revision'])throw new \EIPSI\StudyEngine\Runtime\AccessDenied('ACCESS_REQUIRED');
        $other=(new Identity($db,$clock))->byEmail($study['study_id'],$state['email'],true);if($other&&$other['id']!==$participant['id'])throw new \DomainException('EMAIL_ALREADY_ENROLLED');
        if($participant['email']!==$state['email']){
            $participant['email']=$state['email'];$participant['access_revision']=(int)$participant['access_revision']+1;$participant['verification_state']='UNVERIFIED';$participant['verified_at']=null;
            $db->update('participants',array('email'=>$participant['email'],'access_revision'=>$participant['access_revision'],'verification_state'=>'UNVERIFIED','verified_at'=>null,'updated_at'=>$clock->mysql()),$db->prepare('id=%s',$participant['id']));
            $db->update('participant_sessions',array('revoked_at'=>$clock->mysql()),$db->prepare('participant_id=%s AND revoked_at IS NULL',$participant['id']));
            $db->update('access_capabilities',array('revoked_at'=>$clock->mysql()),$db->prepare('participant_id=%s AND id<>%s AND revoked_at IS NULL',$participant['id'],$cap['id']));
        }
        $state['state']='CONFIRMED';$state['confirmed_at']=$clock->now();self::write($db,$study,$participant,self::key($cap['execution_id']),hash('sha256',Canonical::json($state)),$state,$clock,true);return $participant;
    }
    private static function write(P\Database $db,array $study,array $p,string $id,string $hash,array $response,Clock $clock,bool $update): void {
        $row=array('request_hash'=>$hash,'response_json'=>Canonical::json($response));
        if($update)$db->update('enrollment_receipts',$row,$db->prepare('id=%s',$id));
        else $db->insert('enrollment_receipts',array_merge($row,array('id'=>$id,'public_study_id'=>$study['public_id'],'participant_id'=>$p['id'],'execution_id'=>$p['execution_id'],'created_at'=>$clock->mysql())));
    }
}
