<?php
namespace EIPSI\StudyEngine\Management;
use EIPSI\StudyEngine\Persistence as P;
final class Audit {
    /** Allowlisted structural metadata only; never answers, addresses, bearers or message bodies. */
    public static function record(string $study,string $action,?string $execution=null,?string $entity=null,array $details=array()): void {
        global $wpdb;$allowed=array('state','previous','revision','cycle','version','count','result','kind','job_id','hash','request_id','reason','decisions');
        $safe=array_intersect_key($details,array_flip($allowed));
        foreach($safe as $k=>$v)if($k!=='decisions'&&!is_scalar($v)&&$v!==null)unset($safe[$k]);
        $db=new P\Database($wpdb);if($execution!==null){$context=$db->row($db->prepare('SELECT study_cycle,study_version_id FROM '.$db->table('study_executions').' WHERE id=%s AND study_id=%s',$execution,$study));if($context){$safe['cycle']=(int)$context['study_cycle'];$safe['version']=$context['study_version_id'];}}$row=array('study_id'=>$study,'execution_id'=>$execution,'entity_id'=>$entity,'action'=>$action,'actor_id'=>get_current_user_id(),'created_at'=>gmdate('Y-m-d H:i:s'),'details_json'=>\EIPSI\StudyEngine\Contracts\Canonical::json($safe));
        $cols=array();$values=array();foreach($row as $key=>$value){$cols[]='`'.$key.'`';$values[]=$value===null?'NULL':$db->prepare('%s',(string)$value);}
        $db->query('INSERT INTO '.$db->table('management_events').' ('.implode(',',$cols).') VALUES ('.implode(',',$values).')');
    }
    public static function native(array $binding,string $id,string $action,int $revision,?array $randomization=null): void {
        if(!isset($binding['execution_id']))return;global $wpdb;
        $db=new P\Database($wpdb);$study=$db->row($db->prepare('SELECT study_id FROM '.$db->table('study_executions').' WHERE id=%s',$binding['execution_id']))['study_id']??null;
        if($study&&$action==='discard')$db->query($db->prepare("UPDATE ".$db->table('management_exports')." SET state='INVALIDATED',payload_json=NULL,plan_json='[]',manifest_json=NULL,error_code='RESPONSE_DISCARDED' WHERE study_id=%s",$study));
        $details=array('revision'=>$revision);if($randomization){$r=array('contract'=>$randomization['contract']??null,'resolved_at'=>$randomization['resolved_at']??null,'blocks'=>array(),'option_orders'=>array());$keys=array_flip(array('block_id','question_id','candidates','eligible','selected','order','fixed','weights','fixed_policy','mode','count','order_policy','selection_method','method','plan_revision','resolved_at'));foreach(array('blocks','option_orders') as $kind)foreach($randomization[$kind]??array() as $key=>$value)$r[$kind][$key]=array_intersect_key($value,$keys);$details['decisions']=$r;}
        if($study)self::record($study,'NATIVE_'.strtoupper($action),$binding['execution_id'],$id,$details);
    }
    public static function mail(string $intent,string $result,array $details=array()): void {
        global $wpdb;$db=new P\Database($wpdb);$row=$db->row($db->prepare('SELECT e.study_id,i.execution_id FROM `'.$wpdb->prefix.'eipsi_engine_notification_intents` i JOIN `'.$wpdb->prefix.'eipsi_engine_study_executions` e ON e.id=i.execution_id WHERE i.id=%s',$intent));
        if($row)self::record($row['study_id'],'MAIL_'.$result,$row['execution_id'],$intent,$details+array('result'=>$result));
    }
}
