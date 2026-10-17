<?php
namespace EIPSI\AssessmentEditor;
require_once __DIR__.'/Answers.php';
/** Tidy materialization from an authoritative native snapshot. No transport or writes. */
final class AnalyticalExport {
    public static function rows(array $definition,array $answers,array $touched=array(),array $context=array()): array {
        $d=Definition::validate($definition);if(Answers::errors($d,$answers,$touched))Definition::fail('Invalid analytical responses');$rows=array();$hash=hash('sha256',Definition::json($d));
        $visit=function($items)use(&$visit,&$rows,$answers,$d,$hash,$context){foreach($items as $e){$list=$e['type']==='matrix'?$e['properties']['matrix_rows']:($e['response_key']?array($e):array());foreach($list as $i=>$item){$key=$item['response_key'];$answer=$answers[$key]??null;$scale=$e;if($e['type']==='matrix')$scale['type']='likert';$value=$answer===null?null:Answers::value($scale,$answer);$rows[]=array('assessment_id'=>$d['id'],'study_version_id'=>$context['study_version_id']??null,'block_execution_id'=>$context['block_execution_id']??null,'timepoint'=>$context['timepoint']??null,'definition_hash'=>$hash,'item_id'=>$item['id'],'response_key'=>$key,'variable_name'=>$e['type']==='matrix'?$e['properties']['variable_name'].'_'.($i+1):$e['properties']['variable_name'],'selected_option_id'=>in_array($e['type'],array('likert','matrix'),true)?$answer:null,'value'=>$value);} $visit($e['children']);}};$visit($d['elements']);return $rows;
    }
    public static function csv(array $rows): string {$stream=fopen('php://temp','w+');if($rows){fputcsv($stream,array_keys($rows[0]),',','"','');foreach($rows as $r){$cells=array();foreach($r as $v){if(is_array($v))$v=Definition::json($v);if(is_string($v)&&preg_match('/^[\s]*[=+@-]/u',$v))$v="'".$v;$cells[]=$v;}fputcsv($stream,$cells,',','"','');}}rewind($stream);$csv=stream_get_contents($stream);fclose($stream);return $csv;}
}
