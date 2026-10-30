<?php
namespace EIPSI\AssessmentEditor\Runtime;
/** Uses WordPress logged-in sessions or the existing Study session; never email or URL ownership. */
final class Authorization {
    public static function check(Store $store,array $binding,bool $mutation=false,?string $csrf=null,bool $logicalExit=false,?callable $clock=null): void {
        if(($binding['kind']??'')==='ASSESSMENT_BROWSER'){
            $token=$_COOKIE['eipsi_assessment_session']??'';
            if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token)||!hash_equals($binding['session_hash'],hash('sha256',$token)))throw new \RuntimeException('Access required',403);
            if($mutation&&!hash_equals(self::csrf($binding),$csrf??''))throw new \RuntimeException('Session expired',403);return;
        }
        if(($binding['kind']??'')==='WORDPRESS_USER'){
            if(!is_user_logged_in()||get_current_user_id()!==($binding['user_id']??null))throw new \RuntimeException('Access required',403);
            if(PHP_SAPI!=='cli'||isset($_COOKIE[LOGGED_IN_COOKIE])){global $wpdb;$cookie=$_COOKIE[LOGGED_IN_COOKIE]??'';if((int)wp_validate_auth_cookie($cookie,'logged_in')!==$binding['user_id'])throw new \RuntimeException('Session expired',403);$auth=wp_parse_auth_cookie($cookie,'logged_in');$row=$store->db->row($store->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key='session_tokens' FOR UPDATE",$binding['user_id']));$tokens=$row?maybe_unserialize($row['meta_value']):array();$token=$auth['token']??'';$current=is_array($tokens)?($tokens[hash('sha256',$token)]??null):null;if(!$current||($current['expiration']??0)<=time())throw new \RuntimeException('Session expired',403);}
            if($mutation&&!wp_verify_nonce($csrf??'','eipsi_native_participant'))throw new \RuntimeException('Session expired',403);return;
        }
        if(!in_array($binding['kind']??'',array('STUDY_ANONYMOUS','STUDY_SESSION'),true))throw new \RuntimeException('Invalid ownership',403);
        require_once dirname(__DIR__,2).'/study-engine/content.php';$public=$binding['public_id'];$db=$store->db;
        $parent=$db->row($db->prepare('SELECT * FROM '.$db->table('study_executions').' WHERE id=%s FOR UPDATE',$binding['execution_id']));
        $study=(new \EIPSI\StudyEngine\Runtime\PublicStudyRepository($db))->find($public,true);
        if(!$parent||!$study||$parent['finalized_at']!==null||$study['enrollment_state']==='FINALIZED'||$parent['study_id']!==$study['study_id']||$parent['study_version_id']!==$binding['study_version_id']||$parent['data_policy']!=='USE_ALLOWED')throw new \RuntimeException('Access required',403);
        if($binding['kind']==='STUDY_SESSION'){
            require_once dirname(__DIR__,2).'/study-engine/longitudinal.php';$class='EIPSI\\StudyEngine\\Longitudinal\\Identity';$token=$_COOKIE[$class::cookieName($public)]??'';$session=(new $class($db))->recover($public,$token,true);$expected=$class::csrf($public,$token);
            if(!$session||!in_array($session['kind'],array('AUTHENTICATED','PROVISIONAL'),true)||$session['execution_id']!==$parent['id']||$session['participant_id']!==$binding['participant_id'])throw new \RuntimeException('Access required',403);
        }else{
            $class='EIPSI\\StudyEngine\\Runtime\\AnonymousAccess';$token=$_COOKIE[$class::cookieName($public)]??'';$access=(new $class($db))->recover($public,$token);$expected=$class::csrf($public,$token);
            if(!$access||$access['execution_id']!==$parent['id']||$parent['anonymous_identity_id']!==$access['id']||$access['id']!==$binding['participant_id'])throw new \RuntimeException('Access required',403);
            $locked=$db->row($db->prepare('SELECT * FROM '.$db->table('anonymous_access').' WHERE id=%s FOR UPDATE',$access['id']));if(!$locked||$locked['revoked_at']!==null||!hash_equals($locked['recovery_hash'],hash('sha256',$token)))throw new \RuntimeException('Access required',403);
        }
        if(isset($binding['block_execution_id'])){
            $block=$db->row($db->prepare('SELECT state,definition_id,available_at,due_at FROM '.$db->table('block_executions').' WHERE id=%s AND execution_id=%s FOR UPDATE',$binding['block_execution_id'],$parent['id']));
            $now=$clock===null?(function_exists('apply_filters')?(int)apply_filters('eipsi_runtime_timestamp',time()):time()):(int)$clock();
            if(!$block||!in_array($block['state'],$logicalExit?array('AVAILABLE','IN_PROGRESS','COMPLETED','SKIPPED_BY_RULE'):array('AVAILABLE','IN_PROGRESS','COMPLETED'),true)||(!$logicalExit&&$block['state']!=='COMPLETED'&&(($block['due_at']!==null&&strtotime($block['due_at'].' UTC')<=$now)||strtotime($block['available_at'].' UTC')>$now)))throw new \RuntimeException('Assessment unavailable',403);
        }
        \EIPSI\StudyEngine\Runtime\PausePolicy::native($db,$study,$parent,$binding);
        if($binding['kind']==='STUDY_SESSION'&&($block['state']??null)!=='COMPLETED'&&!$logicalExit){
            $repo=new \EIPSI\StudyEngine\Persistence\StudyVersionRepository($db);$definition=$repo->compiled($repo->find($parent['study_version_id']))->definition();$definition=\EIPSI\StudyEngine\Longitudinal\InitialScreen::effectiveDefinition($store->db,$definition);$config=\EIPSI\StudyEngine\Longitudinal\Configuration::read($definition);$participant=(new \EIPSI\StudyEngine\Longitudinal\Identity($db))->participant($session['participant_id'],true);
            $instances=$db->rows($db->prepare('SELECT definition_id,state,available_at,due_at FROM '.$db->table('block_executions').' WHERE execution_id=%s',$parent['id']));
            if(\EIPSI\StudyEngine\Longitudinal\EmailConfirmation::needs($db,$config,array('instances'=>$instances,'execution'=>array('id'=>$parent['id'])),$definition))throw new \RuntimeException('Confirmá tu email después de la primera evaluación.',403);
            if(($block['state']??null)!=='COMPLETED'&&$participant['verification_state']!=='VERIFIED'&&\EIPSI\StudyEngine\Longitudinal\InitialScreen::required($config,array('instances'=>$instances,'execution'=>array('created_at'=>$parent['created_at'],'first_access_at'=>$parent['first_access_at'])),$definition))throw new \RuntimeException('Confirmá tu email para continuar.',403);
        }
        if(!$logicalExit&&isset($binding['block_execution_id'])&&$block['state']!=='COMPLETED'){
            $repo=new \EIPSI\StudyEngine\Persistence\StudyVersionRepository($db);$v=$repo->find($binding['study_version_id']);$graph=$repo->compiled($v);$node=$graph->node($block['definition_id']);
            if(($node['metadata']['mandatory']??$node['metadata']['scheduled_interaction']['mandatory']??false)!==true){
                $others=$db->rows($db->prepare("SELECT definition_id FROM ".$db->table('block_executions')." WHERE execution_id=%s AND state IN ('AVAILABLE','IN_PROGRESS') AND available_at<=UTC_TIMESTAMP() AND (due_at IS NULL OR due_at>UTC_TIMESTAMP())",$parent['id']));
                foreach($others as $other){$candidate=$graph->node($other['definition_id']);if($candidate['completion_boundary']==='ASSESSMENT'&&($candidate['metadata']['mandatory']??$candidate['metadata']['scheduled_interaction']['mandatory']??false)===true)throw new \RuntimeException('Respondé primero la evaluación obligatoria disponible.',409);}
            }
        }
        if($mutation&&(!is_string($csrf)||!hash_equals($expected,$csrf)))throw new \RuntimeException('Session expired',403);
    }
    public static function csrf(array $binding): string {if($binding['kind']==='ASSESSMENT_BROWSER')return hash_hmac('sha256','EIPSI_ASSESSMENT_BROWSER|'.$binding['session_hash'],$_COOKIE['eipsi_assessment_session']??'');if($binding['kind']==='WORDPRESS_USER')return wp_create_nonce('eipsi_native_participant');$class=$binding['kind']==='STUDY_SESSION'?'EIPSI\\StudyEngine\\Longitudinal\\Identity':'EIPSI\\StudyEngine\\Runtime\\AnonymousAccess';$public=$binding['public_id'];return $class::csrf($public,$_COOKIE[$class::cookieName($public)]??'');}
}
