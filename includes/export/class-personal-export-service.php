<?php
/** M6 owner; existing public adapters retain their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Personal_Export_Service {

public static function process_export_request($request) {
        try{$data=EIPSI_Personal_Export_Query_Service::dataset($request);}catch(Throwable $e){return array('success'=>false,'message'=>$e->getMessage());}
        $json=wp_json_encode($data,JSON_PRETTY_PRINT);
        if($json===false){return array('success'=>false,'message'=>'No se pudo generar JSON.');}
        $directory=get_temp_dir();
        $real=realpath($directory);$web=realpath(ABSPATH);
        if(!$real || !is_dir($real) || !is_writable($real) || ($web && ($real===$web || strpos($real,$web.DIRECTORY_SEPARATOR)===0))) { return array('success'=>false,'message'=>'No hay directorio privado disponible.'); }
        $path=@tempnam($real,'eipsi-personal-');
        if(!$path || dirname($path)!==$real || !@chmod($path,0600) || @file_put_contents($path,$json,LOCK_EX)!==strlen($json)) {
            if($path && is_file($path)){unlink($path);}
            return array('success'=>false,'message'=>'No se pudo crear el archivo privado.');
        }
        return array('success'=>true,'message'=>'Export local generado; DB externa no incluida.',
            'data'=>array('file_path'=>$path,'filename'=>basename($path).'.json','coverage'=>$data['coverage'],
                'download_url'=>add_query_arg(array('action'=>'eipsi_download_personal_data','request_id'=>$request->id,'nonce'=>wp_create_nonce('eipsi_data_download')),admin_url('admin-ajax.php')),
                'record_count'=>array('responses'=>count($data['responses']),'assignments'=>count($data['assignments']))));
    }



public static function remove_secrets($data) {
        if(is_object($data)){$data=(array)$data;}
        if(!is_array($data)){return $data;}
        foreach($data as $key=>$item){
            if(preg_match('/password|token|secret|credential|nonce|session|fingerprint/i',(string)$key)){unset($data[$key]);continue;}
            if(is_string($item) && $key==='form_responses'){$decoded=json_decode($item,true);$data[$key]=wp_json_encode(self::remove_secrets($decoded?:array()));}
            else{$data[$key]=self::remove_secrets($item);}
        }
        return $data;
    }
}
