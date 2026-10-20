<?php
namespace EIPSI\Dataset;
use EIPSI\StudyEngine\Management\ScientificDatasetOwner as Science;
use EIPSI\StudyEngine\Management\DatasetLimit;
use EIPSI\StudyEngine\Contracts\Canonical as C;
/** Shared adapter over pinned source records; never invokes participant transitions. */
final class Source {
    public static function project(Science $owner,array $refs,array $filters,string $code): array {
        $annexes=$refs['__canonical_annexes']??array();unset($refs['__canonical_annexes']);$owner=clone $owner;$data=$owner->materializeSource($refs,!empty($filters['include_partials']));$attempts=array();
        foreach($data['items']as$r)if($r['native_version_id']!==null&&!isset($attempts[$r['attempt_id']]))$attempts[$r['attempt_id']]=$owner->attempt($r['attempt_id'],false);
        return self::attach(CanonicalStudyDataset::project($data['items'],$data['dictionary'],$attempts,$filters,$code),$annexes);
    }
    public static function capture(Science $owner,string $execution,array $selected,array $cutoff=array()): array {
        $db=$owner->db;$out=array();$scienceCut=isset($cutoff['cutoff_at'])?$db->prepare(' AND server_at<=%s',$cutoff['cutoff_at']):'';$adminCut=isset($cutoff['cutoff_event'])?$db->prepare(' AND id<=%d',$cutoff['cutoff_event']):'';
        if(in_array('randomization',$selected,true)){$rows=$db->rows($db->prepare('SELECT id,block_execution_id,owner_id,scope,algorithm_version,candidates_json,selection_json,created_at FROM '.$db->table('random_decisions').' WHERE execution_id=%s ORDER BY id LIMIT 5001',$execution));if(count($rows)>5000)throw new DatasetLimit('DATASET_ANNEX_LIMIT');foreach($rows as$r)$out['randomization'][]=array('participation_id'=>$execution,'level'=>'STUDY','decision_id'=>$r['id'],'opportunity_id'=>$r['block_execution_id'],'owner_id'=>$r['owner_id'],'algorithm'=>$r['algorithm_version'],'candidates'=>json_decode($r['candidates_json'],true),'selection'=>json_decode($r['selection_json'],true),'recorded_at'=>$r['created_at']);}
        if(in_array('audit',$selected,true)){$rows=$db->rows($db->prepare('SELECT sequence,event_type,server_at,domain_at,block_execution_id,rule_id,decision_id,old_state,new_state,reason FROM '.$db->table('execution_events').' WHERE execution_id=%s'.$scienceCut.' ORDER BY sequence LIMIT 5001',$execution));if(count($rows)>5000)throw new DatasetLimit('DATASET_ANNEX_LIMIT');foreach($rows as$r)$out['audit'][]=array('participation_id'=>$execution,'source'=>'STUDY')+$r;$admin=$db->rows($db->prepare('SELECT id,entity_id,action,actor_id,created_at,details_json FROM '.$db->table('management_events').' WHERE execution_id=%s'.$adminCut.' ORDER BY id LIMIT 5001',$execution));if(count($admin)>5000)throw new DatasetLimit('DATASET_ANNEX_LIMIT');foreach($admin as$r){$r['details']=json_decode($r['details_json'],true);unset($r['details_json']);$out['audit'][]=array('participation_id'=>$execution,'source'=>'ADMIN')+$r;}}
        if(in_array('identities',$selected,true)){if(!current_user_can('manage_options'))throw new Conflict('DATASET_IDENTITIES_FORBIDDEN');$p=$db->row($db->prepare('SELECT p.email,p.archived_email,p.verification_state FROM '.$db->table('participants').' p JOIN '.$db->table('study_executions').' e ON e.participant_id=p.id WHERE e.id=%s AND e.study_id=%s',$execution,$owner->study));if($p)$out['identities'][]=array('participation_id'=>$execution)+$p;}
        return $out;
    }
    public static function attach(array $bundle,array $annexes): array {
        if(!$bundle['data'])return $bundle;
        foreach(array('randomization','audit','identities')as$key)foreach($annexes[$key]??array()as$row)$bundle[$key][]=$row;return $bundle;
    }
    public static function execution(Science $owner,array $e,array $filters,string $code,array $selected=array(),array $cutoff=array()): array {
        $rows=$owner->executionRows($e,$filters);$attempts=array();foreach($rows as$r)if($r['native_version_id']!==null&&!isset($attempts[$r['attempt_id']]))$attempts[$r['attempt_id']]=$owner->attempt($r['attempt_id'],false);
        return self::attach(CanonicalStudyDataset::project($rows,$owner->dictionaryForRows($rows),$attempts,$filters,$code),self::capture($owner,$e['id'],$selected,$cutoff));
    }
    public static function merge(array $bundles): array {
        $result=array_fill_keys(CanonicalStudyDataset::ANNEXES,array());foreach($bundles as$b)foreach(CanonicalStudyDataset::ANNEXES as$key)foreach($b[$key]??array()as$row)$result[$key][]=$row;
        $schema=self::schema($result['dictionary']);$result=array_replace($result,$schema);$result['conflicts']=array();foreach($result['data']as&$row)$row=array_replace(array_fill_keys($schema['columns'],null),$row);unset($row);return $result;
    }
    public static function schema(array $dictionary): array {
        $by=array();$families=array();$warnings=array();foreach($dictionary as$d){$name=$d['variable_name'];$family=$d['value_family'];if(isset($families[$name])&&$families[$name]!==$family)throw new Conflict('VARIABLE_TYPE_CONFLICT',array('variable_name'=>$name));$families[$name]=$family;$by[$name][C::json($d)]=$d;}
        $out=array();foreach($by as$name=>$variants){if(count(array_unique(array_column($variants,'label')))>1)$warnings[]=array('code'=>'HISTORICAL_LABEL_CHANGED','variable_name'=>$name);if(count(array_unique(array_map(static function($d){return C::json($d['options']??array());},array_values($variants))))>1)$warnings[]=array('code'=>'HISTORICAL_OPTIONS_CHANGED','variable_name'=>$name);foreach($variants as$d)$out[]=$d;}
        $names=array_keys($families);sort($names,SORT_STRING);if(count($names)>5000)throw new DatasetLimit('DATASET_VARIABLE_LIMIT');
        return array('columns'=>array_merge(CanonicalStudyDataset::CONTEXT,$names),'dictionary'=>$out,'warnings'=>$warnings);
    }
    public static function bundle(Science $owner,array $filters,string $code): array {
        $result=array_fill_keys(CanonicalStudyDataset::ANNEXES,array());$bytes=0;
        foreach($owner->sourceManifest()as$source){$d=self::project($owner,$source['references'],$filters,$code);foreach(CanonicalStudyDataset::ANNEXES as$key)foreach($d[$key]as$row){$bytes+=strlen(C::json($row));if($bytes>33554432||count($result['data'])>10000)throw new DatasetLimit('EXPORT_LIMIT_EXCEEDED');$result[$key][]=$row;}}
        $schema=self::schema($result['dictionary']);$result=array_replace($result,$schema);$result['conflicts']=array();
        foreach($result['data']as&$row)$row=array_replace(array_fill_keys($schema['columns'],null),$row);unset($row);return $result;
    }
}
