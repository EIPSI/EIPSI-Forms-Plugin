<?php
namespace EIPSI\StudyEngine\Tests;
use EIPSI\StudyEngine\Contracts\Canonical;
use EIPSI\StudyEngine\Contracts\ExpectedTrace;
use EIPSI\StudyEngine\Contracts\StudyVersionDefinition;
use EIPSI\StudyEngine\Engine\DefinitionCompiler;
use EIPSI\StudyEngine\Engine\Simulator;

/** CLI adapter and declarative assertion interpreter, outside the reusable engine. */
final class TraceOracle {
    public static function scenario(array $definition,array $trace,array $scenario): Simulator {
        ExpectedTrace::validate($trace,new StudyVersionDefinition($definition));
        $sim=new Simulator((new DefinitionCompiler())->compile($definition),$scenario['inputs']);
        foreach($scenario['steps'] as $index=>$step) {
            $before=$sim->snapshot();$event=$step['event'];$kind=$event['kind'];$data=$event['data'];
            $map=array('EXECUTION_CREATED'=>'CREATE_EXECUTION','RELOAD'=>'RELOAD','RESPONSE_SAVED'=>'ANSWER','ASSESSMENT_OPENED'=>'OPEN_ASSESSMENT','ASSESSMENT_COMPLETED'=>'SUBMIT_ASSESSMENT','CLOCK_ADVANCED'=>'ADVANCE_TIME','TIMEZONE_CHANGED'=>'CHANGE_TIMEZONE','WITHDRAWAL_CONFIRMED'=>'WITHDRAW_SIMULATION','CAPABILITY_OPENED'=>'CAPABILITY_OPENED');
            try {
                $sim->dispatch(array('id'=>'STEP_'.$index,'kind'=>$map[$kind],'data'=>$data));
                foreach($step['expected'] as $assertion)self::assertion($sim,$assertion,$before);
            }catch(\Throwable $e){throw new \RuntimeException($trace['fixture_id'].'/'.$scenario['id'].' step '.($index+1).' '.$kind.': '.$e->getMessage(),0,$e);}
        }return $sim;
    }
    public static function same($expected,$actual,string $label): void {
        if(Canonical::json($expected)!==Canonical::json($actual))throw new \RuntimeException($label.' expected='.Canonical::json($expected).' actual='.Canonical::json($actual));
    }
    private static function history(array $snapshot,string $field) {
        if($field==='execution_id')return $snapshot['execution']['id'];
        if($field==='occurrence_ids'){$ids=array_keys($snapshot['instances']);sort($ids);return $ids;}
        return $snapshot[$field];
    }
    public static function assertion(Simulator $sim,array $a,array $before): void {
        $s=$sim->snapshot();$kind=$a['kind'];
        if($kind==='BLOCK_STATE'){self::same($a['state'],$sim->instance($a['node_id'],$a['slot'])['state'],$kind.' '.$a['node_id']);}
        elseif($kind==='EXECUTION_STATE'){self::same($a['state'],$s['execution']['state'],$kind);}
        elseif($kind==='WINDOW'){$i=$sim->instance($a['node_id'],$a['slot']);foreach(array('available_at','due_at') as $field)self::same($a[$field],$i[$field],$kind.' '.$field);}
        elseif($kind==='CURSOR'){self::same($a['node_id'],$s['cursor']['node_id']??null,$kind);}
        elseif($kind==='ASSESSMENT_COUNT'){self::same($a['count'],count(array_filter($s['instances'],static function($i)use($a){return $i['definition_id']===$a['node_id'];})),$kind);}
        elseif($kind==='RANDOM_SELECTION'){$matches=array_values(array_filter($s['random_decisions'],static function($d)use($a){return $d['owner_id']===$a['owner_id']&&$d['scope']===$a['scope']&&$d['segment_id']===($a['segment_id']??'default');}));if(count($matches)!==1)throw new \RuntimeException('Missing/ambiguous random decision');self::same($a['selection'],$matches[0]['selection'],$kind);}
        elseif($kind==='RESPONSE_COVERAGE'){foreach($a['question_ids'] as $question){$found=false;foreach($s['coverage'] as $coverage){if(isset($coverage[$question])){self::same($a['state'],$coverage[$question]['state'],$kind.' '.$question);$found=true;}}if(!$found)throw new \RuntimeException('Missing coverage '.$question);}}
        elseif($kind==='WAIT_BARRIER'){foreach(array('rule_id','target','resume_target') as $field)self::same($a[$field],$s['barrier'][$field]??null,$kind.' '.$field);}
        elseif($kind==='STABLE_HISTORY'){foreach($a['fields'] as $field)self::same(self::history($before,$field),self::history($s,$field),$kind.' '.$field);}
        elseif($kind==='FROZEN_STATES'){foreach($before['instances'] as $id=>$i){if(in_array($i['state'],$a['states'],true)){foreach(array('state','available_at','due_at','started_at','completed_at','occurrence_key') as $field)self::same($i[$field],$s['instances'][$id][$field],$kind.' '.$id.' '.$field);}}}
        elseif($kind==='NO_WITHDRAWAL_RECORD'){self::same(null,$s['withdrawal'],$kind);}
        elseif($kind==='DATA_POLICY'){self::same($a['mode'],$s['withdrawal']['data_policy']??null,$kind);}
        elseif($kind==='NOTIFICATIONS_CANCELLED'){self::same(true,$s['notifications_cancelled'],$kind);}
        else{throw new \RuntimeException('Unimplemented trace assertion '.$kind);}
    }
}
