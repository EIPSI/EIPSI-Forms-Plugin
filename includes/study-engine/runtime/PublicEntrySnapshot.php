<?php
namespace EIPSI\StudyEngine\Runtime;
use EIPSI\StudyEngine\Persistence as P;
/** Read-only preflight of the already-published contract; never compile, repair or redraw. */
final class PublicEntrySnapshot {
    public static function definition(P\Database $db,array $study): array {
        if(!is_string($study['active_version_id']??null))throw new PublicRuntimeFailure('PUBLIC_RECORD_INCONSISTENT');
        $versions=new P\StudyVersionRepository($db);
        try{$row=$versions->find($study['active_version_id']);}
        catch(\DomainException $error){throw new PublicRuntimeFailure('PUBLIC_RECORD_INCONSISTENT',0,$error);}
        if(!$row)throw new PublicRuntimeFailure('PUBLISHED_VERSION_MISSING');
        if(!in_array($row['state'],array('PUBLISHED','RETIRED'),true))throw new PublicRuntimeFailure('STUDY_NOT_PUBLISHED');
        if($row['study_id']!==$study['study_id'])throw new PublicRuntimeFailure('PUBLIC_RECORD_INCONSISTENT');
        try{$definition=$versions->compiled($row)->definition();}
        catch(P\DatabaseFailure $error){throw $error;}
        catch(\Throwable $error){throw new PublicRuntimeFailure('SNAPSHOT_INVALID',0,$error);}
        $seen=array();foreach($definition['nodes'] as $node){
            $id=$node['metadata']['native_version_id']??null;if($id===null)continue;
            if(!is_string($id)||!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D',$id))throw new PublicRuntimeFailure('ASSESSMENT_REFERENCE_MISSING');
            if(!isset($seen[$id])){
                $native=$db->row($db->prepare('SELECT definition_json,definition_hash,state FROM '.$db->table('native_versions').' WHERE id=%s',$id));
                if(!$native)throw new PublicRuntimeFailure('ASSESSMENT_REFERENCE_MISSING');
                if(!in_array($native['state'],array('PUBLISHED','RETIRED'),true)||!hash_equals($native['definition_hash'],hash('sha256',$native['definition_json'])))throw new PublicRuntimeFailure('ASSESSMENT_SNAPSHOT_INVALID');
                require_once dirname(__DIR__,2).'/assessment-editor/Definition.php';
                try{\EIPSI\AssessmentEditor\Definition::validate(json_decode($native['definition_json'],true,512,JSON_THROW_ON_ERROR));}
                catch(\Throwable $error){throw new PublicRuntimeFailure('ASSESSMENT_SNAPSHOT_INVALID',0,$error);}
                $seen[$id]=$native['definition_hash'];
            }
            if(!hash_equals($seen[$id],$node['metadata']['native_definition_hash']??''))throw new PublicRuntimeFailure('ASSESSMENT_SNAPSHOT_INVALID');
        }
        return $definition;
    }
}
