<?php
namespace EIPSI\StudyEngine\Persistence;
use EIPSI\StudyEngine\Contracts\Canonical;
use EIPSI\StudyEngine\Contracts\Check;
use EIPSI\StudyEngine\Contracts\StudyVersionDefinition;
use EIPSI\StudyEngine\Engine\DefinitionCompiler;
use EIPSI\StudyEngine\Engine\CompiledStudy;

final class StudyVersionRepository {
    private $db;
    public function __construct(Database $db){$this->db=$db;}
    public function find(string $id,bool $lock=false): ?array {
        Check::id($id,'version_id');if($lock)$this->db->requireTransaction();
        return $this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('study_versions').' WHERE id=%s'.($lock?' FOR UPDATE':''),$id));
    }
    public function studyVersion(string $study,int $number,bool $lock=false): ?array {
        Check::id($study,'study_id');Check::integer($number,'version_number',1);if($lock)$this->db->requireTransaction();
        return $this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('study_versions').' WHERE study_id=%s AND version_number=%d'.($lock?' FOR UPDATE':''),$study,$number));
    }
    public function compiled(array $row): CompiledStudy {
        if($row['compiler_contract_version']!=='P1C_V1')throw new IntegrityFailure('COMPILER_CONTRACT_UNAVAILABLE');
        $definition=Database::json($row['protocol_json']);$graph=Database::json($row['graph_json']);
        try{new StudyVersionDefinition($definition);}catch(\Throwable $e){throw new IntegrityFailure('PUBLISHED_PROTOCOL_INVALID');}

        if(!hash_equals($row['protocol_hash'],Canonical::protocolHash($definition))||!hash_equals($row['graph_hash'],hash('sha256',Canonical::json($graph))))throw new IntegrityFailure('PUBLISHED_HASH_MISMATCH');
        if(($definition['id']??null)!==$row['definition_id']||($definition['study']['logical_id']??null)!==$row['study_id']||(int)$row['version_number']!==($definition['version_number']??null)||($graph['compiler_version']??null)!=='P1C_V1')throw new IntegrityFailure('VERSION_BINDING_MISMATCH');
        foreach(array('nodes','children','assessment_owner','descendants','order','edges','temporal_dependencies','segments','diagnostics') as $key){if(!isset($graph[$key])||!is_array($graph[$key]))throw new IntegrityFailure('COMPILED_GRAPH_INCOMPLETE');}
        $nodes=array();foreach($definition['nodes'] as $n)$nodes[$n['id']]=$n;
        if(Canonical::json($nodes)!==Canonical::json($graph['nodes']))throw new IntegrityFailure('GRAPH_PROTOCOL_MISMATCH');
        // No compile here: the published graph is the pinned execution contract.
        return new CompiledStudy($definition,$graph);
    }
    public function draft(array $definition): string {
        $definition['state']='DRAFT';$d=(new StudyVersionDefinition($definition))->normalize();$graph=(new DefinitionCompiler())->compile($d);
        return $this->db->transactional(function()use($d,$graph){if($this->studyVersion($d['study']['logical_id'],$d['version_number'],true))throw new PersistenceConflict('VERSION_ALREADY_EXISTS');return $this->insert($d,$graph,null);});
    }
    public function replaceDraft(string $id,array $definition): void {
        $definition['state']='DRAFT';$graph=(new DefinitionCompiler())->compile($definition);$d=$graph->definition();
        $this->db->transactional(function()use($id,$d,$graph){$row=$this->find($id,true);if(!$row||$row['state']!=='DRAFT')throw new PersistenceConflict('PUBLISHED_VERSION_IMMUTABLE');if($d['id']!==$row['definition_id']||$d['study']['logical_id']!==$row['study_id']||$d['version_number']!==(int)$row['version_number'])throw new PersistenceConflict('VERSION_IDENTITY_IMMUTABLE');$this->db->update('study_versions',array('protocol_json'=>Canonical::json($d),'protocol_hash'=>$graph->protocolHash(),'graph_json'=>Canonical::json($graph->graph()),'graph_hash'=>$graph->hash(),'updated_at'=>Database::now()),$this->db->prepare('id=%s AND state=%s',$id,'DRAFT'));});
    }
    public function publish(array $definition): string {
        if(($definition['state']??null)==='RETIRED')throw new PersistenceConflict('RETIRED_VERSION_CANNOT_PUBLISH');
        $definition['state']='PUBLISHED';$graph=(new DefinitionCompiler())->compile($definition);$d=$graph->definition();
        return $this->db->transactional(function()use($d,$graph){$row=$this->studyVersion($d['study']['logical_id'],$d['version_number'],true);if($row){
            if($row['definition_id']!==$d['id']||$row['state']==='RETIRED'||!hash_equals($row['protocol_hash'],$graph->protocolHash()))throw new PersistenceConflict('PUBLISHED_VERSION_IMMUTABLE');
            if($row['state']==='PUBLISHED'){$this->compiled($row);return $row['id'];}
            $metadata=Database::json($row['metadata_json']);$metadata['lifecycle'][]=array('event'=>'PUBLISHED','at'=>Database::now());
            $this->db->update('study_versions',array('state'=>'PUBLISHED','protocol_json'=>Canonical::json($d),'graph_json'=>Canonical::json($graph->graph()),'graph_hash'=>$graph->hash(),'published_at'=>Database::now(),'updated_at'=>Database::now(),'metadata_json'=>Canonical::json($metadata)),$this->db->prepare('id=%s AND state=%s',$row['id'],'DRAFT'));
        }else{return $this->insert($d,$graph,Database::now());}return $row['id'];});
    }
    private function insert(array $d,CompiledStudy $graph,?string $published): string {
        $id='SV_'.bin2hex(random_bytes(16));
        $this->db->insert('study_versions',array('id'=>$id,'definition_id'=>$d['id'],'study_id'=>$d['study']['logical_id'],'version_number'=>$d['version_number'],'state'=>$d['state'],'protocol_json'=>Canonical::json($d),'protocol_hash'=>$graph->protocolHash(),'graph_json'=>Canonical::json($graph->graph()),'graph_hash'=>$graph->hash(),'compiler_contract_version'=>'P1C_V1','published_at'=>$published,'retired_at'=>null,'metadata_json'=>Canonical::json(array('lifecycle'=>array(array('event'=>$d['state'],'at'=>Database::now())))),'created_at'=>Database::now(),'updated_at'=>Database::now()));return $id;
    }
    public function retire(string $id): void {
        $this->db->transactional(function()use($id){$row=$this->find($id,true);if(!$row||!in_array($row['state'],array('PUBLISHED','RETIRED'),true))throw new PersistenceConflict('VERSION_NOT_PUBLISHED');$this->compiled($row);if($row['state']==='RETIRED')return;$meta=Database::json($row['metadata_json']);$meta['lifecycle'][]=array('event'=>'RETIRED','at'=>Database::now());$this->db->update('study_versions',array('state'=>'RETIRED','retired_at'=>Database::now(),'updated_at'=>Database::now(),'metadata_json'=>Canonical::json($meta)),$this->db->prepare('id=%s AND state=%s',$id,'PUBLISHED'));});
    }

    /** Administrative authoring only; scientific publication still belongs to this owner. */
    public function publishDraftRevision(string $id,int $revision,array $acknowledgements,int $actor): string {
        return $this->db->transactional(function()use($id,$revision,$acknowledgements,$actor){
            $row=$this->find($id,true);if(!$row)throw new PersistenceConflict('VERSION_NOT_FOUND');
            $meta=Database::json($row['metadata_json']);$a=$meta['authoring']??array();
            if($row['state']==='PUBLISHED'&&($a['published_revision']??null)===$revision)return $id;
            if($row['state']!=='DRAFT')throw new PersistenceConflict('PUBLISHED_VERSION_IMMUTABLE');
            if(($a['revision']??0)!==$revision)throw new PersistenceConflict('DRAFT_REVISION_CONFLICT');
            $d=Database::json($row['protocol_json']);$issues=\EIPSI\StudyEngine\Builder\Draft::diagnostics($d);
            if(array_filter($issues,static function($v){return ($v['severity']??'ERROR')==='ERROR';}))throw new \EIPSI\StudyEngine\Engine\CompilationException($issues);
            $required=array_values(array_unique(array_map(static function($v){return $v['code'].'|'.($v['node_id']??$v['path']??'');},$issues)));sort($required);$acks=$acknowledgements;sort($acks);
            if($required!==$acks)throw new PersistenceConflict('WARNING_ACKNOWLEDGEMENT_REQUIRED');
            $d['state']='PUBLISHED';$g=(new DefinitionCompiler())->compile($d);
            $meta['authoring']['published_revision']=$revision;$meta['authoring']['revision']=$revision+1;
            $meta['lifecycle'][]=array('event'=>'PUBLISHED','actor'=>$actor,'at'=>Database::now(),'protocol_hash'=>$g->protocolHash(),'warning_acknowledgements'=>$required);
            $this->db->update('study_versions',array('state'=>'PUBLISHED','protocol_json'=>Canonical::json($g->definition()),'protocol_hash'=>$g->protocolHash(),'graph_json'=>Canonical::json($g->graph()),'graph_hash'=>$g->hash(),'published_at'=>Database::now(),'updated_at'=>Database::now(),'metadata_json'=>Canonical::json($meta)),$this->db->prepare('id=%s AND state=%s',$id,'DRAFT'));
            require_once dirname(__DIR__).'/management/Audit.php';\EIPSI\StudyEngine\Management\Audit::record($row['study_id'],'VERSION_PUBLISHED',null,$id,array('revision'=>$revision,'hash'=>$g->protocolHash()));
            return $id;
        });
    }
}
