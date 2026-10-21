<?php
namespace EIPSI\AssessmentEditor;
/** Exact companion of randomization.js; execution owner supplies seed and time. */
final class Randomization {
    private static function hash(string $text): int {
        $h=2166136261;for($i=0;$i<strlen($text);$i++)$h=(($h^ord($text[$i]))*16777619)&0xffffffff;
        return $h?:0x6d2b79f5;
    }
    private static function next(int &$state): int {
        $state^=($state<<13)&0xffffffff;$state^=$state>>17;$state^=($state<<5)&0xffffffff;$state&=0xffffffff;return $state;
    }
    private static function shuffle(array $ids,string $seed): array {
        sort($ids,SORT_STRING);$state=self::hash($seed);
        for($i=count($ids)-1;$i>0;$i--){$bound=$i+1;$limit=intdiv(4294967296,$bound)*$bound;
            do{$n=self::next($state);}while($n>=$limit);
            $j=$n%$bound;$tmp=$ids[$i];$ids[$i]=$ids[$j];$ids[$j]=$tmp;
        }return $ids;
    }
    private static function weighted(array $ids,array $weights,int $n,string $seed): array {
        sort($ids,SORT_STRING);$state=self::hash($seed);$selected=array();
        while(count($selected)<$n){$max=max(array_map(static function($id)use($weights){return $weights[$id];},$ids));$total=0.0;foreach($ids as $id)$total+=$weights[$id]/$max;
            $target=(self::next($state)/4294967296)*$total;$sum=0.0;$index=count($ids)-1;
            foreach($ids as $i=>$id){$sum+=$weights[$id]/$max;if($target<$sum){$index=$i;break;}}
            $selected[]=$ids[$index];array_splice($ids,$index,1);
        }return $selected;
    }
    private static function pinned(array $candidates,array $fixed,array $chosen,array $ordered): array {
        $result=array();$index=0;foreach($candidates as $id){if(in_array($id,$fixed,true))$result[]=$id;elseif(in_array($id,$chosen,true))$result[]=$ordered[$index++];}return $result;
    }
    public static function settings(array $child): array {
        $value=array_key_exists('randomization',$child)?$child['randomization']:array();if(!is_array($value)||array_diff(array_keys($value),array('weight','always_show','fixed_position')))Definition::fail('Invalid child randomization settings');$weight=array_key_exists('weight',$value)?$value['weight']:1;$always=array_key_exists('always_show',$value)?$value['always_show']:false;
        if((!is_int($weight)&&!is_float($weight))||!is_finite((float)$weight)||$weight<=0||!is_bool($always))Definition::fail('Peso debe ser positivo y Siempre mostrar booleano.');
        $fixed=$value['fixed_position']??false;if(!is_bool($fixed))Definition::fail('Fixed position must be boolean');return array('weight'=>$weight,'always_show'=>$always,'fixed_position'=>$fixed);
    }
    private static function draw(array $ids,array $weights,int $n,string $seed): array {
        $equal=true;foreach($ids as $id)if((float)$weights[$id] !== (float)$weights[$ids[0]])$equal=false;
        return $equal?array_slice(self::shuffle($ids,$seed),0,$n):self::weighted($ids,$weights,$n,$seed);
    }
    private static function currentBlock(array $e,string $seed): array {
        $candidates=array_column($e['children'],'id');$p=$e['properties'];$weights=array();$always=array();$fixed=array();
        foreach($e['children'] as $c){$v=self::settings($c);$weights[$c['id']]=$v['weight'];if($v['always_show'])$always[]=$c['id'];if($v['fixed_position'])$fixed[]=$c['id'];}
        $pool=$p['mode']==='all'?$candidates:array_values(array_diff($candidates,$always));
        if($p['mode']==='select'&&(!is_int($p['count'])||$p['count']<1||$p['count']>count($pool)-($p['algorithm']==='selection_uniform_order'?1:0)))Definition::fail('N must be between 1 and candidates minus one');
        $count=$p['mode']==='all'?count($pool):$p['count'];$prefix=$seed.':'.$e['id'];
        $chosen=$p['mode']==='all'?$pool:self::draw($pool,$weights,$count,$prefix.':selection');
        $visible=array_values(array_filter($candidates,static function($id)use($chosen,$always,$p){return in_array($id,$chosen,true)||($p['mode']==='select'&&in_array($id,$always,true));}));
        $movable=array_values(array_diff($visible,$fixed));$orderWeights=$weights;if($p['mode']==='select')foreach($always as $id)$orderWeights[$id]=1;
        $uniformOrder=$p['mode']==='select'&&$p['algorithm']==='selection_uniform_order';$ordered=$uniformOrder?self::shuffle($movable,$prefix.':order'):self::draw($movable,$orderWeights,count($movable),$prefix.':order');
        $pins=array_values(array_intersect($candidates,$visible,$fixed));$order=array_fill(0,count($visible),null);$previous=-1;
        foreach($pins as $i=>$id){$slot=max($previous+1,min(array_search($id,$candidates,true),count($visible)-count($pins)+$i));$order[$slot]=$id;$previous=$slot;}
        $index=0;foreach($order as &$id)if($id===null)$id=$ordered[$index++];unset($id);
        return array('block_id'=>$e['id'],'candidates'=>$candidates,'eligible'=>$pool,'selected'=>array_values(array_intersect($candidates,$chosen)),'always_show'=>$p['mode']==='select'?$always:array(),'fixed'=>$fixed,'weights'=>$weights,'order'=>$order,'mode'=>$p['mode'],'count'=>$count,'order_policy'=>$uniformOrder?'uniform':'weighted','fixed_policy'=>'absolute-clamped','selection_method'=>'weighted-sequential','algorithm'=>$p['algorithm']);
    }
    public static function resolve(array $d,string $seed,int $resolvedAt): array {
        if(!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D',$seed))Definition::fail('Invalid randomization seed');
        $blocks=array();$options=array();$advanced=false;
        $visit=function($items)use(&$visit,&$blocks,&$options,&$advanced,$seed){foreach($items as $e){if($e['type']==='randomization'&&in_array($e['properties']['algorithm']??null,array('weighted_positions','selection_uniform_order'),true)){$blocks[$e['id']]=self::currentBlock($e,$seed);$advanced=true;}elseif($e['type']==='randomization'){
            $candidates=array_column($e['children'],'id');$p=$e['properties'];$weights=array();$fixed=array();
            foreach($e['children'] as $child){$value=self::settings($child);$weights[$child['id']]=$value['weight'];if($value['always_show'])$fixed[]=$child['id'];}
            $pool=array_values(array_diff($candidates,$fixed));$policy=$p['fixed_policy']??'original';
            if(in_array('page_break',array_column($e['children'],'type'),true))Definition::fail('Randomization cannot contain PageBreak');
            if(!in_array($p['mode'],array('all','select'),true)||!in_array($p['order'],array('original','random'),true)||!in_array($policy,array('original','mixed'),true))Definition::fail('Invalid randomization configuration');
            if($p['mode']==='select'&&(!is_int($p['count'])||$p['count']<1||$p['count']>count($pool)))Definition::fail('N cuenta sólo elementos aleatorios; los fijos se agregan aparte.');
            $count=$p['mode']==='all'?count($pool):$p['count'];$equal=true;foreach($pool as $id)if((float)$weights[$id]!== (float)$weights[$pool[0]])$equal=false;
            $weighted=$p['mode']==='select'&&!$equal;
            $chosen=$weighted?self::weighted($pool,$weights,$count,$seed.':'.$e['id'].':selection'):array_slice(self::shuffle($pool,$seed.':'.$e['id']),0,$count);
            $selected=array_values(array_intersect($pool,$chosen));$randomOrder=$p['mode']==='all'||$p['order']==='random';
            $ordered=$randomOrder?($weighted?self::shuffle($chosen,$seed.':'.$e['id'].':order'):$chosen):$selected;
            $order=$policy==='mixed'&&$randomOrder?self::shuffle(array_merge($chosen,$fixed),$seed.':'.$e['id'].':final'):self::pinned($candidates,$fixed,$chosen,$ordered);
            $r=array('block_id'=>$e['id'],'candidates'=>$candidates,'selected'=>$selected,'order'=>$order);
            {$r+=array('eligible'=>$pool,'fixed'=>$fixed,'weights'=>$weights,'fixed_policy'=>$policy,'mode'=>$p['mode'],'count'=>$count,'order_policy'=>$p['order'],'selection_method'=>$weighted?'weighted-sequential':'uniform-fisher-yates');$advanced=true;}
            $blocks[$e['id']]=$r;
        }
        if(($e['properties']['randomize_options']??false)===true){
            if(!in_array($e['type'],array('single_choice','multiple_choice','likert'),true))Definition::fail('Question does not support option randomization');
            $candidates=array_column($e['options'],'id');$fixed=array_column(array_filter($e['options'],static function($o){return ($o['fixed']??false)===true;}),'id');$pool=array_values(array_diff($candidates,$fixed));
            $options[$e['id']]=array('question_id'=>$e['id'],'candidates'=>$candidates,'fixed'=>$fixed,'order'=>self::pinned($candidates,$fixed,$pool,self::shuffle($pool,$seed.':'.$e['id'].':options')),'method'=>'uniform-fisher-yates');$advanced=true;
        }
        $visit($e['children']);}};$visit($d['elements']);
        $record=array('contract'=>$advanced?'LOCAL_RANDOMIZATION_V2':'LOCAL_RANDOMIZATION_V1','seed'=>$seed,'resolved_at'=>$resolvedAt,'blocks'=>$blocks);if($options)$record['option_orders']=$options;return $record;
    }
    public static function create(array $d,int $time): ?array {
        $record=self::resolve($d,bin2hex(random_bytes(32)),$time);return $record['blocks']||!empty($record['option_orders'])?$record:null;
    }
    /** Same Native draw algorithm, with a Study-owned seed and opportunity scope. */
    public static function plan(array $config,string $seed,int $time,?array $control=null): array {
        $children=array();foreach($config['candidates'] as $id)$children[]=array('id'=>$id,'type'=>'group','properties'=>array('label'=>$id),'children'=>array(),'randomization'=>array('weight'=>$config['weights'][$id]??1,'always_show'=>in_array($id,$config['always_show']??array(),true),'fixed_position'=>in_array($id,$config['fixed']??array(),true)));
        $element=array('id'=>$config['id'],'type'=>'randomization','properties'=>array('mode'=>$config['mode'],'count'=>$config['count'],'algorithm'=>$config['algorithm']??'weighted_positions','order'=>$config['order']??'random','fixed_policy'=>$config['fixed_policy']??'original'),'children'=>$children);
        $record=array('contract'=>'LOCAL_RANDOMIZATION_LAZY_V3','seed'=>$seed,'created_at'=>$time,'resolved_at'=>null,'blocks'=>array(),'option_orders'=>array());
        return self::reach(array('elements'=>array($element)),$record,0,$time,$control?array($config['id']=>$control):array())['blocks'][$config['id']];
    }
    /** V3 resolves only the visible page and selected ancestry. Historical V1/V2 are unchanged. */
    public static function lazy(int $time): array {return array('contract'=>'LOCAL_RANDOMIZATION_LAZY_V3','seed'=>bin2hex(random_bytes(32)),'created_at'=>$time,'resolved_at'=>null,'blocks'=>array(),'option_orders'=>array());}
    public static function reach(array $d,array $record,int $page,int $time,array $controls=array(),int $minimumPage=0,array $plans=array()): array {
        if(($record['contract']??null)!=='LOCAL_RANDOMIZATION_LAZY_V3')return $record;
        // Study plans exist before page entry. Consume their revisions even after navigating back.
        if($plans){$catalog=array();$collect=function($items)use(&$collect,&$catalog){foreach($items as $item){if($item['type']==='randomization')$catalog[$item['id']]=array_column($item['children'],'id');$collect($item['children']);}};$collect($d['elements']);
            foreach($record['blocks'] as $id=>$block)if(isset($block['plan_revision'])&&!isset($plans[$id]))unset($record['blocks'][$id]);
            foreach($plans as $id=>$planned){if(!isset($catalog[$id])||array_diff($planned['order'],$catalog[$id]))Definition::fail('Invalid planned Native outcome');if(!isset($record['blocks'][$id])||($record['blocks'][$id]['plan_revision']??null)!==($planned['plan_revision']??null))$record['blocks'][$id]=$planned;$record['resolved_at']=$record['resolved_at']??$planned['resolved_at'];}
        }
        $current=0;$seed=$record['seed'];
        $visit=function($items)use(&$visit,&$record,&$current,$page,$time,$seed,$controls,$minimumPage,$plans){foreach($items as $e){if($e['type']==='page_break'){$current++;continue;}if($current>$page||$current<$minimumPage)continue;
            if($e['type']==='randomization'){
                if(isset($plans[$e['id']])&&(!isset($record['blocks'][$e['id']])||($record['blocks'][$e['id']]['plan_revision']??null)!==($plans[$e['id']]['plan_revision']??null))){$planned=$plans[$e['id']];if(array_diff($planned['order'],array_column($e['children'],'id')))Definition::fail('Invalid planned Native outcome');$record['blocks'][$e['id']]=$planned;$record['resolved_at']=$record['resolved_at']??$planned['resolved_at'];}
                if(!isset($record['blocks'][$e['id']])){
                    $original=$e;$control=$controls[$e['id']]??null;if($control){$e['children']=array_values(array_filter($e['children'],static function($c)use($control){return in_array($c['id'],$control['forced']??$control['candidates'],true)||($c['randomization']['always_show']??false);}));$count=count(array_filter($e['children'],static function($c){return !($c['randomization']['always_show']??false);}));if($control['forced']!==null||$e['properties']['mode']==='select'&&$e['properties']['count']===$count)$e['properties']['mode']='all';}

                    if(in_array($e['properties']['algorithm']??null,array('weighted_positions','selection_uniform_order'),true))$draw=self::currentBlock($e,$seed);
                    else{$plain=$e;$strip=function(&$items)use(&$strip){foreach($items as &$child){if($child['type']==='randomization'){$child['type']='group';$child['properties']=array('label'=>'');}unset($child['properties']['randomize_options']);$strip($child['children']);}unset($child);};$strip($plain['children']);$draw=self::resolve(array('elements'=>array($plain)),$seed,$time)['blocks'][$e['id']];}
                    if($control){$draw['candidates']=array_column($original['children'],'id');$draw['eligible']=$control['candidates'];$draw['control']=$control;$draw['mode']=$original['properties']['mode'];$draw['count']=$original['properties']['count'];}$e=$original;
                    $draw['resolved_at']=$time;$record['blocks'][$e['id']]=$draw;$record['resolved_at']=$record['resolved_at']??$time;
                }
                $by=array_column($e['children'],null,'id');$e['children']=array_map(static function($id)use($by){return $by[$id];},$record['blocks'][$e['id']]['order']);
            }
            if(($e['properties']['randomize_options']??false)&&!isset($record['option_orders'][$e['id']])){
                $copy=$e;$copy['children']=array();$draw=self::resolve(array('elements'=>array($copy)),$seed,$time)['option_orders'][$e['id']];$draw['resolved_at']=$time;$record['option_orders'][$e['id']]=$draw;$record['resolved_at']=$record['resolved_at']??$time;
            }
            $visit($e['children']);
        }};$visit($d['elements']);return $record;
    }
    public static function apply(array $d,?array $record): array {
        $visit=function($items)use(&$visit,$record){foreach($items as &$e){if($e['type']==='randomization'){
            $r=$record['blocks'][$e['id']]??null;if(!$r&&($record['contract']??null)==='LOCAL_RANDOMIZATION_LAZY_V3'){$e['type']='group';$e['properties']=array('label'=>$e['properties']['label']);$e['children']=array();continue;}if(!$r)Definition::fail('Missing execution randomization');
            $children=array_column($e['children'],null,'id');$selected=array();foreach($r['order'] as $id){if(!isset($children[$id]))Definition::fail('Invalid randomized identity');$selected[]=$children[$id];}
            $e['children']=$selected;$e['type']='group';$e['properties']=array('label'=>$e['properties']['label']);
        }
        if(($e['properties']['randomize_options']??false)===true){$r=$record['option_orders'][$e['id']]??null;if(!$r&&($record['contract']??null)==='LOCAL_RANDOMIZATION_LAZY_V3'){unset($e['properties']['randomize_options']);$e['children']=$visit($e['children']);continue;}if(!$r)Definition::fail('Missing execution option order');$by=array_column($e['options'],null,'id');$ordered=array();foreach($r['order'] as $id){if(!isset($by[$id]))Definition::fail('Invalid option identity');$ordered[]=$by[$id];}$e['options']=$ordered;if($e['type']==='likert')$e['properties']['reversed']=false;}
        $e['children']=$visit($e['children']);}unset($e);return $items;};$d['elements']=$visit($d['elements']);return $d;
    }
}
