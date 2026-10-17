<?php
namespace EIPSI\AssessmentEditor;
/** Analytical names, deliberately independent of row/response identities. */
final class Variables {
    const POLICY='MATRIX_ORDINAL_V1';
    const PREFIXES=array('text','date','single','multiple','select','likert','vas','matrix');
    public static function prefix(string $type): string {return array('textarea'=>'text','email'=>'text','number'=>'text','tel'=>'text','url'=>'text','single_choice'=>'single','multiple_choice'=>'multiple')[$type]??$type;}
    public static function normalize(array $d): array {
        $legacy=!array_key_exists('authoring',$d);
        if($legacy)$d['authoring']=array('variable_policy'=>self::POLICY,'variable_next'=>array_fill_keys(self::PREFIXES,1),'legacy_row_variables'=>array());
        self::metadata($d['authoring']);$names=array();
        $scan=function($items)use(&$scan,&$d,&$names){foreach($items as $e){if($e['response_key']&&isset($e['properties']['variable_name'])){$v=$e['properties']['variable_name'];if(is_string($v)){$names[$v]=true;foreach(self::PREFIXES as $prefix)if(preg_match('/^'.$prefix.'_([1-9][0-9]{0,8})$/D',$v,$m))$d['authoring']['variable_next'][$prefix]=max($d['authoring']['variable_next'][$prefix],(int)$m[1]+1);if($e['type']==='matrix')foreach($e['properties']['matrix_rows'] as $i=>$r)$names[$v.'_'.($i+1)]=true;}}$scan($e['children']);}};$scan($d['elements']);
        $visit=function(&$items)use(&$visit,&$d,&$names,$legacy){foreach($items as &$e){if($e['response_key']){if(!in_array($e['type'],Definition::QUESTIONS,true))Definition::fail('Invalid response element');if(!array_key_exists('variable_name',$e['properties'])){$prefix=self::prefix($e['type']);do{$n=$d['authoring']['variable_next'][$prefix]++;if($n>=1000000000)Definition::fail('Variable allocator exhausted');$v=$prefix.'_'.$n;$conflict=isset($names[$v]);if($e['type']==='matrix')foreach($e['properties']['matrix_rows'] as $i=>$r)$conflict=$conflict||isset($names[$v.'_'.($i+1)]);}while($conflict);$e['properties']['variable_name']=$v;$names[$v]=true;}
            if(!is_string($e['properties']['variable_name']))Definition::fail('Invalid variable name');if($e['type']==='matrix')foreach($e['properties']['matrix_rows'] as $i=>&$r){$derived=$e['properties']['variable_name'].'_'.($i+1);if($legacy&&isset($r['variable_name'])&&$r['variable_name']!==$derived)$d['authoring']['legacy_row_variables'][]=array('row_id'=>$r['id'],'variable_name'=>$r['variable_name']);if(!$legacy&&isset($r['variable_name'])&&$r['variable_name']!==$derived)Definition::fail('Matrix row variable is derived, not editable');$r['variable_name']=$derived;$names[$derived]=true;}unset($r);}$visit($e['children']);}unset($e);};$visit($d['elements']);self::metadata($d['authoring']);return $d;
    }
    public static function metadata($a): void {
        if(!is_array($a))Definition::fail('Invalid authoring metadata');Definition::fields($a,array('variable_policy','variable_next','legacy_row_variables'));if($a['variable_policy']!==self::POLICY||!is_array($a['variable_next']))Definition::fail('Invalid variable policy');Definition::fields($a['variable_next'],self::PREFIXES);foreach($a['variable_next'] as $n)if(!is_int($n)||$n<1||$n>1000000000)Definition::fail('Invalid variable allocator');
        if(!is_array($a['legacy_row_variables'])||($a['legacy_row_variables']&&array_keys($a['legacy_row_variables'])!==range(0,count($a['legacy_row_variables'])-1)))Definition::fail('Invalid legacy row names');foreach($a['legacy_row_variables'] as $r){if(!is_array($r))Definition::fail('Invalid legacy row name');Definition::fields($r,array('row_id','variable_name'));Definition::id($r['row_id']);if(!is_string($r['variable_name'])||strlen($r['variable_name'])>128)Definition::fail('Invalid legacy row name');}
    }
    public static function validate(array $elements): void {
        $outputs=array();$bases=array();$check=static function($v,$id,$base=false,$row=false)use(&$outputs,&$bases){if(!is_string($v)||!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$v)||strlen($v)>($row?132:128))Definition::fail('Invalid variable name');if($base){if(isset($bases[$v]))Definition::fail('Duplicate Matrix base');$bases[$v]=$id;}else{if(isset($outputs[$v]))Definition::fail('Duplicate variable name: '.$v);$outputs[$v]=$id;}};
        $visit=function($items)use(&$visit,$check){foreach($items as $e){if($e['response_key']){$check($e['properties']['variable_name'],$e['id'],$e['type']==='matrix');if($e['type']==='matrix')foreach($e['properties']['matrix_rows'] as $i=>$r){if($r['variable_name']!==$e['properties']['variable_name'].'_'.($i+1))Definition::fail('Invalid derived row variable');$check($r['variable_name'],$r['id'],false,true);}}$visit($e['children']);}};$visit($elements);
    }
}
