<?php
namespace EIPSI\AssessmentEditor;

/** Assessment authoring only: never responses, sessions or published executions. */
final class Portable {
    const FORMAT='EIPSI_ASSESSMENT_JSON';
    const VERSION=1;
    const MAX_BYTES=1048576;
    public static function export(string $id,int $revision): array {
        $record=Repository::load($id);
        if($record['revision']!==$revision)throw new \RuntimeException('La evaluación cambió. Volvé a abrirla antes de exportar.',409);
        $document=array('format'=>self::FORMAT,'version'=>self::VERSION,'definition'=>$record['definition']);
        if(strlen(Definition::json($document))>self::MAX_BYTES)Definition::fail('El archivo supera el máximo de 1 MB.');
        return $document;
    }
    public static function import(string $json): array {
        if(strlen($json)>self::MAX_BYTES)Definition::fail('El archivo supera el máximo de 1 MB.');
        $data=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($data))Definition::fail('El archivo no contiene una evaluación.');
        Definition::fields($data,array('format','version','definition'));
        if($data['format']!==self::FORMAT||$data['version']!==self::VERSION||!is_array($data['definition']))Definition::fail('Formato de evaluación no admitido.');
        return Repository::save(Repository::freshDefinition($data['definition']),0);
    }
    const QUESTION_FORMAT='EIPSI_QUESTION_JSON';
    public static function exportQuestion(string $id,int $revision,string $questionId): array {
        $record=Repository::load($id);
        if($record['revision']!==$revision)throw new \RuntimeException('La evaluación cambió. Volvé a abrirla antes de exportar.',409);
        $document=array('definition'=>$record['definition']);
        $find=function(array $items)use(&$find,$questionId){foreach($items as $item){if($item['id']===$questionId)return $item;$found=$find($item['children']);if($found!==null)return $found;}return null;};
        $question=$find($document['definition']['elements']);
        if(!$question||!in_array($question['type'],Definition::QUESTIONS,true))Definition::fail('Seleccioná una pregunta.');
        $document=array('format'=>self::QUESTION_FORMAT,'version'=>self::VERSION,'question'=>$question);
        if(strlen(Definition::json($document))>self::MAX_BYTES)Definition::fail('El archivo supera el máximo de 1 MB.');
        return $document;
    }
    public static function importQuestion(string $json): array {
        if(!\EIPSI_Admin_Authorization_Policy::allows('assessments.edit'))throw new \RuntimeException('Forbidden',403);
        if(strlen($json)>self::MAX_BYTES)Definition::fail('El archivo supera el máximo de 1 MB.');
        $data=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($data))Definition::fail('El archivo no contiene una pregunta.');
        Definition::fields($data,array('format','version','question'));
        if($data['format']!==self::QUESTION_FORMAT||$data['version']!==self::VERSION||!is_array($data['question'])||!in_array($data['question']['type']??null,Definition::QUESTIONS,true))Definition::fail('Formato de pregunta no admitido.');
        $definition=array('contract'=>Definition::CONTRACT,'id'=>'AE_PORTABLE_QUESTION','title'=>'Pregunta','elements'=>array($data['question']));
        $fresh=Repository::freshDefinition($definition);
        return $fresh['elements'][0];
    }

}
