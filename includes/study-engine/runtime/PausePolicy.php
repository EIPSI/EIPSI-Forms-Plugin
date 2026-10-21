<?php
namespace EIPSI\StudyEngine\Runtime;
use EIPSI\StudyEngine\Persistence as P;
/** Operational admission fence. Identity, scientific deadlines and ownership remain separate. */
final class StudyPaused extends \DomainException {
    public function __construct(){parent::__construct('STUDY_PAUSED',409);}
}
final class PausePolicy {
    public static function paused(?array $study): bool {return in_array($study['enrollment_state']??null,array('PAUSED','CLOSED'),true);}
    public static function study(P\Database $db,string $id,bool $lock=false): ?array {return $db->row($db->prepare('SELECT * FROM '.$db->table('public_studies').' WHERE study_id=%s'.($lock?' FOR UPDATE':''),$id));}
    public static function admission(?array $study): void {if(self::paused($study)){self::denied($study);throw new StudyPaused();}}
    /** Indexed Native bindings, with the pinned definition deciding which blocks require Native proof. */
    public static function openBlocks(P\Database $db,array $execution,array $definition,bool $closing=false): array {
        $nativeNodes=array();foreach($definition['nodes']as$n)if(isset($n['metadata']['native_version_id']))$nativeNodes[$n['id']]=$n['metadata']['native_version_id'];
        $proof=array();if($nativeNodes){$states=$closing?"('ACTIVE','SESSION_EXPIRED','COMPLETED','LOGICAL_EXIT')":"('ACTIVE','SESSION_EXPIRED')";
            foreach($db->rows($db->prepare('SELECT version_id,binding_json FROM '.$db->table('native_executions').' WHERE management_execution=%s AND state IN '.$states,$execution['id']))as$row){$b=P\Database::json($row['binding_json']);if(($b['execution_id']??null)===$execution['id']&&($b['study_version_id']??null)===$execution['study_version_id']&&($b['participant_id']??null)===($execution['participant_id']??$execution['anonymous_identity_id']))$proof[$b['block_execution_id']]=$row['version_id'];}
        }
        $out=array();foreach($db->rows($db->prepare("SELECT id,definition_id,state FROM ".$db->table('block_executions')." WHERE execution_id=%s AND state IN ('AVAILABLE','IN_PROGRESS')",$execution['id']))as$b){$native=$nativeNodes[$b['definition_id']]??null;if($native!==null?($proof[$b['id']]??null)===$native:$b['state']==='IN_PROGRESS')$out[$b['id']]=true;}return$out;
    }
    public static function continuation(P\Database $db,array $execution,array $definition): bool {return (bool)self::openBlocks($db,$execution,$definition);}
    public static function fence(P\Database $db,array $execution,\EIPSI\StudyEngine\Engine\Simulator $sim): void {
        $allowed=self::openBlocks($db,$execution,$sim->compiled()->definition(),true);$instances=$sim->snapshot()['instances'];$scope=$allowed;
        foreach($instances as$id=>$i){$cursor=$i;while($cursor['parent_execution_id']!==null){if(isset($allowed[$cursor['parent_execution_id']])){$scope[$id]=true;break;}$cursor=$instances[$cursor['parent_execution_id']];}}
        foreach(array_keys($allowed)as$id){$cursor=$instances[$id];while($cursor['parent_execution_id']!==null){$scope[$cursor['parent_execution_id']]=true;$cursor=$instances[$cursor['parent_execution_id']];}}
        $sim->restrictActivation($scope);
    }
    public static function native(P\Database $db,array $study,array $parent,array $binding): void {
        if(!self::paused($study))return;
        $row=$db->row($db->prepare("SELECT id FROM ".$db->table('native_executions')." WHERE management_execution=%s AND COALESCE(management_opportunity,'')=%s AND state IN ('ACTIVE','SESSION_EXPIRED','COMPLETED','LOGICAL_EXIT') LIMIT 1",$parent['id'],$binding['block_execution_id']??''));
        if(!$row){self::denied($study);throw new StudyPaused();}
    }
    public static function portal(P\Database $db,array $study,?array $access): bool {
        if(!self::paused($study))return true;if(!$access||empty($access['execution_id']))return false;
        $e=(new P\StudyExecutionRepository($db))->find($access['execution_id']);
        if(!$e||$e['study_id']!==$study['study_id']||$e['study_version_id']!==$access['study_version_id']||$e['finalized_at']!==null||$e['data_policy']!=='USE_ALLOWED')return false;
        if(($e['participant_id']??$e['anonymous_identity_id'])!==($access['participant_id']??$access['id']))return false;
        $repo=new P\StudyVersionRepository($db);return self::continuation($db,$e,$repo->compiled($repo->find($e['study_version_id']))->definition());
    }
    public static function denied(array $study): void {
        // Bounded event, no visitor/email/answers; audit lifecycle transitions is persisted separately.
        if(class_exists('EIPSI_Rate_Limiter')){try{\EIPSI_Rate_Limiter::consume('pause.denial.audit',$study['study_id'].'|'.($study['lifecycle_revision']??0),1,300);}catch(\EIPSI_Rate_Limit_Exception $e){return;}}
        if(class_exists('EIPSI_Security_Log'))\EIPSI_Security_Log::event('STUDY_PAUSED_ENTRY_DENIED',array('study_id'=>$study['study_id'],'status'=>409));
    }
}
