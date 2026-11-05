<?php
namespace EIPSI\AssessmentEditor;
/** A voluntary response copy, never an authentication or session update. */
final class Registration {
    public static function present(array $definition): bool {
        $walk=function(array $items)use(&$walk):bool{foreach($items as $item)if($item['type']==='registration'||$walk($item['children']))return true;return false;};
        return $walk($definition['elements']);
    }
    /** Called only after the Native owner's existing authorization checks. Never return bearer tokens. */
    public static function source(\EIPSI\StudyEngine\Persistence\Database $db,array $binding,string $native): string {
        if(($binding['kind']??'')==='STUDY_SESSION'){
            $row=$db->row($db->prepare('SELECT email FROM '.$db->table('participants').' WHERE id=%s AND execution_id=%s',$binding['participant_id'],$binding['execution_id']));
            return (string)($row['email']??'');
        }
        if(($binding['kind']??'')==='STUDY_ANONYMOUS')return (string)$binding['participant_id'];
        return $native;
    }
}
