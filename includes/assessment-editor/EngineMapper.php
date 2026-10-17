<?php
namespace EIPSI\AssessmentEditor;
require_once __DIR__.'/Definition.php';
require_once dirname(__DIR__).'/study-engine/autoload.php';

/** Explicit prototype export boundary. No publication, runtime writes or block parsing. */
final class EngineMapper {
    public static function map(array $native): \EIPSI\StudyEngine\Contracts\StudyVersionDefinition {
        $native=Definition::validate($native);
        $version='V_'.$native['id']; $root='M_'.$native['id']; $assessment='A_'.$native['id'];
        $nodes=array(self::block($root,$version,$version,0,'MACRO','NONE',self::schedule(null)),self::block($assessment,$version,$root,0,'MICRO','ASSESSMENT',self::schedule($root)));
        self::elements($native['elements'],$assessment,$version,$nodes);
        return new \EIPSI\StudyEngine\Contracts\StudyVersionDefinition(array('contract_version'=>'P1B_V1','compiler_contract_version'=>'P1C_V1','study'=>array('logical_id'=>'S_'.$native['id'],'public_id'=>'P_'.$native['id']),'id'=>$version,'version_number'=>1,'state'=>'DRAFT','timezone'=>array('policy'=>'PARTICIPANT_LOCAL','default'=>'UTC'),'root_block_ids'=>array($root),'nodes'=>$nodes,'condition_rules'=>array(),'closure'=>array('kind'=>'ALL_SELECTED_OPPORTUNITIES_TERMINAL'),'protocol_metadata'=>array('native_contract'=>Definition::CONTRACT,'native_definition'=>$native,'mapping_status'=>'PROTOTYPE_NOT_PUBLISHED','mapping_warnings'=>array('Native widget presentation/validation preserved as metadata; existing runtime does not consume every property. No production response integration.'))));
    }
    private static function schedule($parent): array {
        return array('anchor'=>$parent?'BLOCK_AVAILABLE':'EXECUTION_CREATED','anchor_ref'=>$parent,'occurrence_selector'=>$parent?array('kind'=>'SINGLE'):null,'temporal_rule'=>array('kind'=>'IMMEDIATE'),'timezone_policy'=>'PARTICIPANT_LOCAL','window'=>array('kind'=>'NO_DEADLINE'),'recurrence'=>null,'dst_policy'=>array('gap'=>'SHIFT_FORWARD_BY_GAP','fold'=>'EARLIER_INSTANT'));
    }
    private static function block($id,$v,$parent,$position,$scope,$boundary,$schedule): array {return array('kind'=>'BLOCK','id'=>$id,'logical_id'=>$id,'version_id'=>$v,'parent_id'=>$parent,'position'=>$position,'scope'=>$scope,'children_strategy'=>'SEQUENTIAL','randomization'=>null,'schedule'=>$schedule,'completion_boundary'=>$boundary,'metadata'=>array('native_structure_only'=>true));}
    private static function elements(array $elements,string $parent,string $version,array &$nodes): void {
        foreach($elements as $position=>$e) {
            $base=array('id'=>$e['id'],'version_id'=>$version,'parent_id'=>$parent,'position'=>$position);$p=$e['properties'];
            if(in_array($e['type'],array('multiple_choice','date','url','matrix','randomization'),true)||($p['randomize_options']??false)===true||($p['content_type']??null)==='url') throw new \DomainException('Tipo '.$e['type'].' conservado nativamente; Engine sin contrato completo. No se exporta parcialmente.');
            if($e['type']==='group') { $group=self::block($e['id'],$version,$parent,$position,'MICRO','GROUP',null);$group['metadata']['native_element']=$e;$nodes[]=$group;self::elements($e['children'],$e['id'],$version,$nodes);continue; }
            if($e['type']==='page_break') { $nodes[]=array_merge($base,array('kind'=>'DIRECTIVE','directive_kind'=>'PAGE_BREAK','target'=>null,'resume_target'=>null,'on_target_unavailable'=>null));continue; }
            $presentation=array('label'=>$p['label'],'helper'=>$p['helper']??'','native_element'=>$e);
            if(in_array($e['type'],array('text','textarea','email','tel'),true)) { $presentation['format']=in_array($p['content_type']??null,array('email','tel'),true)?$p['content_type']:($e['type']==='email'?'email':($e['type']==='tel'?'tel':'text')); $presentation['min_length']=$p['min_length']; }
            if($e['type']==='textarea')$presentation['widget']='eipsi/campo-textarea'; // Existing content-renderer discriminator, not block serialization.
            if(in_array($e['type'],array('number','vas'),true))$presentation['step']=$p['step'];
            if($e['type']==='likert')$presentation['scale_labels']=array_column($e['options'],'label');
            $snapshot=Definition::json($presentation);
            $content=array('content_id'=>'C_'.$e['id'],'version'=>1,'snapshot'=>$snapshot,'sha256'=>hash('sha256',$snapshot));
            if(!in_array($e['type'],Definition::QUESTIONS,true)) { $nodes[]=array_merge($base,array('kind'=>'CONTENT','type'=>'TEXT','content'=>$content,'durable_reference'=>null));continue; }
            $validation=array();$type='TEXT';$options=array();
            if(isset($p['max_length']))$validation['max_length']=$p['max_length'];
            if(in_array($e['type'],array('single_choice','select'),true)){$type='CHOICE';$options=$e['options'];}
            if($e['type']==='likert'){foreach($e['options'] as $i=>$o)if($o['value']===null||(float)$o['value']!==(float)($p['min']+$i))throw new \DomainException('LIKERT_VALUES_NOT_REPRESENTABLE: Engine requires consecutive integers');$type='INTEGER';$validation=array('min'=>$p['min'],'max'=>$p['min']+count($e['options'])-1);}
            if($e['type']==='number'){$type='NUMBER';$validation=array();foreach(array('min','max') as $bound)if($p[$bound]!==null)$validation[$bound]=$p[$bound];}
            if($e['type']==='vas'){$type='NUMBER';$validation=array('min'=>$p['min'],'max'=>$p['max']);}
            $nodes[]=array_merge($base,array('kind'=>'QUESTION','response_key'=>$e['response_key'],'type'=>$type,'options'=>$options,'validation'=>$validation,'required'=>$p['required'],'semantic_role'=>null,'content'=>$content));
        }
    }
}
