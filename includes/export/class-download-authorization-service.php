<?php
/** M6 owner; existing public adapters retain their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Download_Authorization_Service {

public static function get_download($request_id, $nonce) {
        global $wpdb;
        if(!wp_verify_nonce($nonce,'eipsi_data_download')){return array('success'=>false,'message'=>'Token inválido.');}
        $request=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}survey_data_requests WHERE id=%d",absint($request_id)));
        if(!$request || $request->status!==EIPSI_Privacy_Data_Request_Service::STATUS_COMPLETED || $request->request_type!=='export'){return array('success'=>false,'message'=>'Export no disponible.');}
        if(!current_user_can('manage_options')){
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-auth-service.php';
            $access=EIPSI_Auth_Service::authorize_session_context(array('participant_id'=>$request->participant_id,'study_id'=>$request->survey_id));
            if(!$access['success']){return array('success'=>false,'message'=>'No autorizado.');}
        }
        $data=json_decode($request->result_data,true);$path=$data['file_path']??'';
        $real=realpath($path);$private=realpath(get_temp_dir());
        if(!$real || !$private || strpos($real,$private.DIRECTORY_SEPARATOR)!==0 || strpos(basename($real),'eipsi-personal-')!==0 || !is_readable($real)){
            return array('success'=>false,'message'=>'Archivo no disponible.');
        }
        return array('success'=>true,'file_path'=>$real,'filename'=>$data['filename']);
    }
public static function admin_url($filename) {
        return add_query_arg(array('action'=>'eipsi_download_admin_export','filename'=>$filename,'nonce'=>wp_create_nonce('eipsi_export_download')), admin_url('admin-ajax.php'));
    }

    public static function get_admin_download($filename, $nonce) {
        if (!current_user_can('manage_options') || !wp_verify_nonce($nonce, 'eipsi_export_download')) { return array('success'=>false); }
        return self::resolve_admin_file($filename);
    }

    /** Resolve files only; entry points retain their own capability/nonce contracts. */
    public static function resolve_admin_file($filename) {
        if (!is_string($filename) || !preg_match('/^[a-zA-Z0-9_-]+\.(csv|xlsx)$/D', $filename)) { return array('success'=>false); }
        $directory = realpath(EIPSI_FORMS_PLUGIN_DIR . 'exports');
        $path = realpath(EIPSI_FORMS_PLUGIN_DIR . 'exports/' . $filename);
        if (!$directory || !$path || dirname($path) !== $directory || !is_file($path) || !is_readable($path)) { return array('success'=>false); }
        return array('success'=>true,'file_path'=>$path,'filename'=>$filename);
    }
}
