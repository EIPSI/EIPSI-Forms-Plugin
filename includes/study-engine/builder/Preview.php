<?php
namespace EIPSI\StudyEngine\Builder;
use EIPSI\StudyEngine\Contracts as C;
use EIPSI\StudyEngine\Engine as E;
/** Pure replay, deliberately no persistence/runtime/enrollment/sender calls. */
final class Preview {
    public static function run(array $d,array $context,array $commands): array {
        C\Check::fields($context,array('created_at','timezone'),array('seed_hex'));C\Check::timestamp($context['created_at'],'created_at',false);C\Check::timezone($context['timezone'],'timezone');
        C\Check::listOf($commands,'commands');if(count($commands)>500)throw new \DomainException('PREVIEW_COMMAND_LIMIT');
        $g=(new E\DefinitionCompiler())->compile($d);$context+=array('seed_hex'=>str_repeat('01',32),'execution_id'=>'BUILDER_PREVIEW','max_instances'=>10000,'eager_limit'=>10000,'horizon_days'=>366);
        $sim=new E\Simulator($g,$context);
        foreach($commands as $i=>$cmd){C\Check::fields($cmd,array('kind','data'));if(!in_array($cmd['kind'],array('ANSWER','SAVE_PAGE','SUBMIT_ASSESSMENT','NEXT_PAGE','PREVIOUS_PAGE','FIRST_ACCESS','VERIFY_PARTICIPANT','ADVANCE_TIME','CHANGE_TIMEZONE','NATIVE_LOGIC'),true))throw new \DomainException('PREVIEW_COMMAND_DENIED');$sim->dispatch(array('id'=>'PREVIEW_'.$i,'kind'=>$cmd['kind'],'data'=>$cmd['data'],'expected_revision'=>$sim->snapshot()['execution']['revision']));}
        $s=$sim->snapshot();$assessments=0;foreach($s['instances'] as $b)if($g->node($b['definition_id'])['completion_boundary']==='ASSESSMENT')$assessments++;
        return array('simulation_only'=>true,'protocol_hash'=>$g->protocolHash(),'graph_hash'=>$g->hash(),'snapshot'=>$s,'next_action'=>$sim->nextAction(),'assessment_occurrences'=>$assessments,'diagnostics'=>$g->graph()['diagnostics']);
    }
}
