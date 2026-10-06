<?php
if (!defined('ABSPATH')) { exit; }
class EIPSI_Personal_Export_Query_Service {
public static function dataset($request){
        global $wpdb;
        $id=absint($request->participant_id);
        $participant=$wpdb->get_row($wpdb->prepare("SELECT id,survey_id,email,first_name,last_name,created_at,consent_decision FROM {$wpdb->prefix}survey_participants WHERE id=%d",$id), ARRAY_A);
        if(!$participant){throw new RuntimeException('Participante no encontrado.');}
        $data=array('participant_info'=>$participant,'access_logs'=>EIPSI_Personal_Export_Query_Service::checked_rows($wpdb->prepare(
            "SELECT action_type,created_at FROM {$wpdb->prefix}survey_participant_access_log WHERE participant_id=%d",$id),ARRAY_A),
            'assignments'=>EIPSI_Personal_Export_Query_Service::checked_rows($wpdb->prepare("SELECT w.wave_index,w.name AS wave_name,a.status,a.assigned_at,a.submitted_at FROM {$wpdb->prefix}survey_assignments a JOIN {$wpdb->prefix}survey_waves w ON w.id=a.wave_id WHERE a.participant_id=%d",$id),ARRAY_A),
            'email_history'=>EIPSI_Personal_Export_Query_Service::checked_rows($wpdb->prepare("SELECT email_type,subject,sent_at,status FROM {$wpdb->prefix}survey_email_log WHERE participant_id=%d",$id),ARRAY_A),
            'responses'=>EIPSI_Personal_Export_Query_Service::checked_rows($wpdb->prepare("SELECT form_name,wave_index,submitted_at,form_responses FROM {$wpdb->prefix}vas_form_results WHERE participant_id=%s AND survey_id=%d",(string)$id,$request->survey_id),ARRAY_A),
            'coverage'=>EIPSI_Privacy_Coverage_Report::for_operation('personal_export'));
        if($wpdb->last_error){throw new RuntimeException('No se pudieron consultar los datos.');}
        // Nested answer keys must not reintroduce credentials/tokens supplied by a client.
        $data=EIPSI_Personal_Export_Service::remove_secrets($data);

return $data;
}
public static function checked_rows($query,$output=ARRAY_A) {
        global $wpdb;
        $rows=$wpdb->get_results($query,$output);
        if($wpdb->last_error){throw new RuntimeException('No se pudieron consultar todos los datos personales.');}
        return $rows;
    }
}
