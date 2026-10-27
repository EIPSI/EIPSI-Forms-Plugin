<?php
namespace EIPSI\StudyEngine\Builder;
require_once dirname(__DIR__).'/runtime/PublicMessages.php';
use EIPSI\StudyEngine\Contracts as C;
use EIPSI\StudyEngine\Engine as E;
/** Authoring contracts use the scientific definition verbatim; incomplete closure is publish-only. */
final class Draft {
    public static function create(string $study,string $public,int $number=1,?string $language=null): array {
        $d=array('contract_version'=>'P1B_V1','study'=>array('logical_id'=>$study,'public_id'=>$public),'id'=>'VERSION_'.$number,'version_number'=>$number,'state'=>'DRAFT','timezone'=>array('policy'=>'STUDY_TIMEZONE','default'=>'UTC'),'root_block_ids'=>array('M_ROOT'),'nodes'=>array(self::block('M_ROOT','VERSION_'.$number,'VERSION_'.$number,'MACRO',0)),'condition_rules'=>array(),'compiler_contract_version'=>'P1C_V1','protocol_metadata'=>array('execution_planning'=>'DEPENDENCY_PLAN_V1','content_adapter'=>'P1E_V1','storage_mode'=>'WORDPRESS_LOCAL','enrollment_mode'=>'ANONYMOUS','allow_back'=>true,'completion_title'=>'Gracias por participar','completion_message'=>'Tus respuestas quedaron guardadas.'),'closure'=>array('kind'=>'ALL_SELECTED_OPPORTUNITIES_TERMINAL'));
        if($language!==null){\EIPSI\I18n\ContentDefaults::valid($language);$d['protocol_metadata']=array_replace($d['protocol_metadata'],\EIPSI\I18n\ContentDefaults::get('study',$language),array('content_language'=>$language,'public_messages'=>\EIPSI\I18n\ContentDefaults::get('public_messages',$language)));}return $d;
    }
    /** Upgrade authoring drafts only; published definitions and running checkpoints stay frozen. */
    public static function planning(array $d): array {
        $d['protocol_metadata']['execution_planning']='DEPENDENCY_PLAN_V1';
        if(($d['protocol_metadata']['enrollment_mode']??null)==='EMAIL_LONGITUDINAL'&&!isset($d['protocol_metadata']['longitudinal']['initial_screen']))$d['protocol_metadata']['longitudinal']['initial_screen']=\EIPSI\StudyEngine\Longitudinal\InitialScreen::defaults($d['protocol_metadata']['content_language']??null);
        if(isset($d['protocol_metadata']['longitudinal']['initial_screen']))$d['protocol_metadata']['longitudinal']['initial_screen']['contract']='INITIAL_SCREEN_V2';
        foreach($d['protocol_metadata']['trajectory_conditions']??array() as $i=>$r){
            $d['protocol_metadata']['trajectory_conditions'][$i]['reference_scope']='TRACK_PAST_V1';
            $d['protocol_metadata']['trajectory_conditions'][$i]['action_scope']='TRACK_FUTURE_V1';
            if(in_array($r['event'],array('SCHEDULE','BLOCK_STATE'),true)&&!isset($r['trigger'])){$d['protocol_metadata']['trajectory_conditions'][$i]['event']='BLOCK_STATE';$d['protocol_metadata']['trajectory_conditions'][$i]['trigger']=array('node_id'=>$r['source']['assessment_id'],'state'=>'AVAILABLE');}
        }return $d;
    }
    public static function schedule(string $anchor='EXECUTION_CREATED',?string $ref=null): array {
        return array('anchor'=>$anchor,'anchor_ref'=>$ref,'occurrence_selector'=>$ref===null?null:array('kind'=>'SINGLE'),'temporal_rule'=>array('kind'=>'IMMEDIATE'),'timezone_policy'=>'STUDY_TIMEZONE','window'=>array('kind'=>'NO_DEADLINE'),'recurrence'=>null,'dst_policy'=>array('gap'=>'SHIFT_FORWARD_BY_GAP','fold'=>'EARLIER_INSTANT'));
    }
    public static function block(string $id,string $version,string $parent,string $scope,int $position,bool $internal=false): array {
        return array('kind'=>'BLOCK','id'=>$id,'logical_id'=>$id,'version_id'=>$version,'parent_id'=>$parent,'scope'=>$scope,'position'=>$position,'children_strategy'=>'SEQUENTIAL','randomization'=>null,'schedule'=>$internal?null:self::schedule($parent===$version?'EXECUTION_CREATED':'BLOCK_AVAILABLE',$parent===$version?null:$parent),'completion_boundary'=>$scope==='MACRO'?'NONE':($internal?'GROUP':'ASSESSMENT'),'metadata'=>array('label'=>$scope==='MACRO'?'Macro':'Evaluación'));
    }
    public static function validate(array $d): array {
        if(($d['state']??null)==='DRAFT'&&($d['protocol_metadata']['study_type']??null)==='LONGITUDINAL'&&is_array($d['nodes']??null)){
            foreach($d['nodes'] as &$node)if(is_array($node)&&($node['metadata']['role']??null)==='LONGITUDINAL_BLOCK'){
                $node['schedule']=self::schedule();$node['schedule']['timezone_policy']=$d['timezone']['policy']??'STUDY_TIMEZONE';unset($node['metadata']['duration_days']);
            }unset($node);
        }
        if(array_key_exists('content_language',$d['protocol_metadata']??array()))\EIPSI\I18n\ContentDefaults::valid($d['protocol_metadata']['content_language']);
        if(strlen(C\Canonical::json($d))>2097152||count($d['nodes']??array())>2000)throw new \DomainException('DRAFT_SIZE_LIMIT');
        if(($d['state']??null)!=='DRAFT')throw new \DomainException('DRAFT_REQUIRED');
        $check=$d;if(($d['nodes']??null)===array()&&($d['root_block_ids']??null)===array()){$check['nodes']=array(self::block('EMPTY_ROOT',$d['id'],$d['id'],'MACRO',0));$check['root_block_ids']=array('EMPTY_ROOT');}
        $issues=(new \EIPSI\StudyEngine\Validation\DefinitionValidator())->validate($check);
        if($issues)throw new C\ContractViolation($issues);
        \EIPSI\StudyEngine\Runtime\DateFormat::read($d);
        if(isset($d['protocol_metadata']['public_messages']))\EIPSI\StudyEngine\Runtime\PublicMessages::validate($d['protocol_metadata']['public_messages']);
        $mode=$d['protocol_metadata']['enrollment_mode']??'ANONYMOUS';if(!in_array($mode,array('ANONYMOUS','EMAIL_LONGITUDINAL'),true))throw new \DomainException('INVALID_ENROLLMENT_MODE');
        if($mode==='EMAIL_LONGITUDINAL')$d['protocol_metadata']['longitudinal']=\EIPSI\StudyEngine\Longitudinal\Configuration::read($d);
        return C\Canonical::definition($d);
    }
    public static function diagnostics(array $d): array {
        try{$g=(new E\DefinitionCompiler())->compile($d);$issues=$g->graph()['diagnostics'];
            $multiplicity=array();$estimate=0;foreach($g->order() as $id){$n=$g->node($id);if($n['kind']!=='BLOCK')continue;$count=1;$r=$n['schedule']['recurrence']??null;
                if($r!==null){if($r['kind']!=='DAILY_LOCAL'&&!isset($r['count'])){$count=0;}else{try{$count=0;foreach((new E\RecurrenceMaterializer(10000))->slots($r,null,$d['timezone']['default']) as $unused)$count++;}catch(\DomainException $e){$issues[]=array('code'=>'AUTHORING_OCCURRENCE_CAP','node_id'=>$id,'severity'=>'ERROR');$count=10001;}}}
                $multiplicity[$id]=$count*($multiplicity[$n['parent_id']]??1);$estimate+=$multiplicity[$id];if($estimate>10000){$issues[]=array('code'=>'AUTHORING_OCCURRENCE_CAP','node_id'=>$id,'severity'=>'ERROR');break;}}

            foreach($d['nodes'] as $n)if($n['kind']==='CONTENT'&&$n['type']==='MEDIA'){$s=json_decode($n['content']['snapshot'],true);$m=$s['media']??array();if(empty($m['snapshot_path'])||!is_file($m['snapshot_path'])||!hash_equals($n['durable_reference']['sha256'],hash_file('sha256',$m['snapshot_path'])))$issues[]=array('code'=>'PINNED_MEDIA_UNAVAILABLE','node_id'=>$n['id'],'severity'=>'ERROR');}
            if(($d['protocol_metadata']['enrollment_mode']??null)==='EMAIL_LONGITUDINAL'){
                $c=\EIPSI\StudyEngine\Longitudinal\Configuration::read($d);
                foreach($d['nodes'] as $n)if(!isset($n['metadata']['scheduled_interaction'])&&!isset($d['protocol_metadata']['study_type'])&&($n['completion_boundary']??null)==='ASSESSMENT'&&($n['schedule']['window']['kind']??null)==='DURATION'&&$n['schedule']['window']['duration']['kind']==='ELAPSED')foreach($c['reminder_offsets'] as $m)if($m*60>=$n['schedule']['window']['duration']['seconds'])$issues[]=array('code'=>'REMINDER_AFTER_DEADLINE','node_id'=>$n['id'],'severity'=>'ERROR');
            }
            return $issues;
        }catch(C\ContractViolation $e){return array_map(static function($i){$i['severity']='ERROR';return $i;},$e->issues());}catch(E\CompilationException $e){return $e->diagnostics();}catch(\DomainException $e){return array(array('code'=>$e->getMessage(),'severity'=>'ERROR'));}
    }
    public static function version(array $d,int $number): array {
        $old=$d['id'];$d['id']='VERSION_'.$number;$d['version_number']=$number;$d['state']='DRAFT';foreach($d['nodes'] as &$n){$n['version_id']=$d['id'];if($n['parent_id']===$old)$n['parent_id']=$d['id'];}unset($n);foreach($d['condition_rules'] as &$r)$r['version_id']=$d['id'];unset($r);return self::validate(self::planning($d));
    }
    public static function diff(array $a,array $b): array {
        $out=array();$pick=static function($d,$keys){$v=array();foreach($d['nodes'] as $n){$row=array('id'=>$n['id']);foreach($keys as $k)if(array_key_exists($k,$n))$row[$k]=$k==='parent_id'&&$n[$k]===$d['id']?'VERSION_ROOT':$n[$k];$v[]=$row;}return $v;};
        foreach(array('structure'=>array('kind','parent_id','position','scope','completion_boundary'),'content'=>array('content'),'questions/options'=>array('response_key','options','required','type','validation','semantic_role'),'scheduling'=>array('schedule'),'randomization'=>array('children_strategy','randomization')) as $label=>$keys)if(C\Canonical::json($pick(C\Canonical::definition($a),$keys))!==C\Canonical::json($pick(C\Canonical::definition($b),$keys)))$out[]=$label;
        $rules=static function($d){$r=$d['condition_rules'];foreach($r as &$v)unset($v['version_id']);unset($v);usort($r,static function($x,$y){return strcmp($x['id'],$y['id']);});return $r;};if(C\Canonical::json($a['protocol_metadata']['trajectory_conditions']??array())!==C\Canonical::json($b['protocol_metadata']['trajectory_conditions']??array()))$out[]='logical trajectories';if(C\Canonical::json($rules($a))!==C\Canonical::json($rules($b)))$out[]='conditions';if(C\Canonical::json($a['timezone'])!==C\Canonical::json($b['timezone']))$out[]='timezone';
        if(C\Canonical::json($a['protocol_metadata'])!==C\Canonical::json($b['protocol_metadata']))$out[]='reminders/enrollment/presentation';return $out;
    }
}
