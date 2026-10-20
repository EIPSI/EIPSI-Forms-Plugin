<?php
namespace EIPSI\StudyEngine\Runtime;
use EIPSI\StudyEngine\Persistence as P;
/** A response limit restricts admission/submission; it never grants participant authority. */
final class ResponsePolicy {
    const PREFIX='eipsi_engine_response_ip_';
    private static function key(string $study): string {
        $ip=$_SERVER['REMOTE_ADDR']??'';if(!filter_var($ip,FILTER_VALIDATE_IP))throw new \DomainException('CLIENT_IP_REQUIRED');
        return self::PREFIX.hash_hmac('sha256',$study.'|'.inet_ntop(inet_pton($ip)),wp_salt('auth'));
    }
    public static function admission(P\Database $db,array $study): void {
        $v=(new P\StudyVersionRepository($db))->find($study['active_version_id']);$d=P\Database::json($v['protocol_json']);if(($d['protocol_metadata']['unique_response']??false)!==true)return;global $wpdb;
        if($db->row($db->prepare('SELECT option_value FROM `'.$wpdb->options.'` WHERE option_name=%s',self::key($study['study_id'].((int)($study['study_cycle']??1)>1?'|cycle:'.$study['study_cycle']:'')))))throw new \DomainException('RESPONSE_ALREADY_RECORDED');
    }
    public static function complete(P\Database $db,array $binding,string $native): void {
        if(!isset($binding['block_execution_id']))return;
        $parent=$db->row($db->prepare('SELECT * FROM '.$db->table('study_executions').' WHERE id=%s FOR UPDATE',$binding['execution_id']));$v=(new P\StudyVersionRepository($db))->find($binding['study_version_id']);$d=P\Database::json($v['protocol_json']);
        if(($binding['kind']??null)!=='STUDY_ANONYMOUS'||($d['protocol_metadata']['unique_response']??false)!==true)return;
        if((int)($db->row('SELECT @@session.in_transaction AS active')['active']??0)!==1)throw new \LogicException('RESPONSE_POLICY_TRANSACTION_REQUIRED');
        global $wpdb;$engine=$db->row($db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->options));if(!$engine||strcasecmp($engine['ENGINE'],'InnoDB')!==0)throw new P\IntegrityFailure('RESPONSE_POLICY_TRANSACTION_REQUIRED');
        // Authorization already locks the logical public Study, serializing concurrent submissions.
        $key=self::key($parent['study_id'].((int)($parent['study_cycle']??1)>1?'|cycle:'.$parent['study_cycle']:''));$value=wp_json_encode(array('study_id'=>$parent['study_id'],'execution_id'=>$parent['id'],'native_id'=>$native));
        $db->query($db->prepare('INSERT IGNORE INTO `'.$wpdb->options.'` (option_name,option_value,autoload) VALUES (%s,%s,%s)',$key,$value,'off'));
        $row=$db->row($db->prepare('SELECT option_value FROM `'.$wpdb->options.'` WHERE option_name=%s FOR UPDATE',$key));if(!$row||$row['option_value']!==$value)throw new \RuntimeException('Ya se registró una respuesta desde esta IP para este estudio.',409);
    }
    public static function repeatAllowed(P\Database $db,array $binding): bool {if(isset($binding['public_id'])&&PausePolicy::paused((new PublicStudyRepository($db))->find($binding['public_id'])))return false;
        if(($binding['kind']??null)!=='STUDY_SESSION'||!isset($binding['block_execution_id']))return false;
        $repo=new P\StudyVersionRepository($db);$v=$repo->find($binding['study_version_id']);if(!$v)return false;
        $block=$db->row($db->prepare('SELECT * FROM '.$db->table('block_executions').' WHERE id=%s AND execution_id=%s',$binding['block_execution_id'],$binding['execution_id']));if(!$block||$block['state']!=='COMPLETED')return false;
        $graph=$repo->compiled($v);$node=$graph->node($block['definition_id']);$others=$db->rows($db->prepare("SELECT definition_id FROM ".$db->table('block_executions')." WHERE execution_id=%s AND state IN ('AVAILABLE','IN_PROGRESS') AND available_at<=UTC_TIMESTAMP() AND (due_at IS NULL OR due_at>UTC_TIMESTAMP())",$binding['execution_id']));foreach($others as $other){$candidate=$graph->node($other['definition_id']);if($candidate['completion_boundary']==='ASSESSMENT'&&($candidate['metadata']['mandatory']??$candidate['metadata']['scheduled_interaction']['mandatory']??false)===true)return false;}return self::repeatOpportunity($node,$block,false,time());
    }
    /** Same eligibility for persisted opportunities and the virtual clock replay. */
    public static function repeatOpportunity(array $node,array $block,bool $mandatoryOpen,int $now): bool {
        $due=$block['due_at']??null;
        if($mandatoryOpen||($block['state']??null)!=='COMPLETED'||$due===null)return false;
        $deadline=strtotime($due.(strpos($due,'T')===false?' UTC':''));
        return $deadline!==false&&$deadline>$now&&($node['metadata']['unique_response']??$node['metadata']['scheduled_interaction']['unique_response']??true)===false;
    }
    public static function deleteStudy(P\Database $db,string $study): void {global $wpdb;$db->query($db->prepare('DELETE FROM `'.$wpdb->options.'` WHERE LEFT(option_name,%d)=%s AND JSON_UNQUOTE(JSON_EXTRACT(option_value,%s))=%s',strlen(self::PREFIX),self::PREFIX,'$.study_id',$study));}
}
