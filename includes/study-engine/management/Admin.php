<?php
namespace EIPSI\StudyEngine\Management;
final class Admin {
    public static function handle(array $p): array {
        \EIPSI_Admin_Authorization_Policy::demand(\EIPSI_Admin_Authorization_Policy::managementOperation($p));
        if(($p['operation']??null)==='manage'){global $wpdb;$db=new \EIPSI\StudyEngine\Persistence\Database($wpdb);$db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');return $db->transactional(function()use($p){return self::dispatch($p);});}
        if(in_array($p['operation']??null,array('manage_export','manage_export_download'),true))\EIPSI_Rate_Limiter::consume('management.'.($p['operation']??''),'user:'.get_current_user_id(),$p['operation']==='manage_export'?20:60,$p['operation']==='manage_export'?3600:60);
        return self::dispatch($p);
    }
    private static function dispatch(array $p): array {
        $allowed=array('operation','study_id','section','filters','entity','kind','request_id','format');
        if(($p['operation']??null)==='manage_export_download')$allowed[]='annex';
        if(array_diff(array_keys($p),$allowed))throw new \DomainException('MANAGEMENT_REQUEST_INVALID');
        if(isset($p['annex'])&&(!is_string($p['annex'])||!in_array($p['annex'],\EIPSI\Dataset\CanonicalStudyDataset::ANNEXES,true)))throw new \DomainException('MANAGEMENT_REQUEST_INVALID');
        if(!isset($p['study_id'])||!is_string($p['study_id']))throw new \DomainException('MANAGEMENT_STUDY_REQUIRED');$q=new Query($p['study_id']);if(isset($p['filters'])&&!is_array($p['filters']))throw new \DomainException('MANAGEMENT_FILTER_INVALID');$f=Query::filters($p['filters']??array());
        if(($p['operation']??null)==='manage'){
            switch($p['section']??'summary'){
                case 'summary':return $q->summary($f);case 'participants':return $q->participants($f);case 'opportunities':return $q->opportunities($f);case 'attempts':return $q->attempts($f);case 'identity':return $q->identity($p['entity']??'');case 'attempt':return $q->attempt($p['entity']??'');case 'communications':return $q->communications($f);case 'audit':return $q->audit($f);case 'diagnosis':return $q->diagnosis($f);case 'jobs':return array('items'=>(new Exports($q))->jobs());default:throw new \DomainException('MANAGEMENT_SECTION_INVALID');
            }
        }
        $x=new Exports($q);switch($p['operation']){case 'manage_export':return $x->create($f,$p['kind']??'responses',$p['request_id']??'');case 'manage_export_step':return $x->step($p['entity']??'');case 'manage_export_download':return $x->download($p['entity']??'',$p['format']??'csv',$p['annex']??'data');}throw new \DomainException('MANAGEMENT_OPERATION_INVALID');
    }
}
