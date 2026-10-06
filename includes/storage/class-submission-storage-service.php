<?php
/** M6 owner; existing public adapters retain their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Submission_Storage_Service {

public static function eipsi_safety_validate_submission($data) {
    $errors = array();
    $warnings = array();
    
    // Verificar campos críticos de identificación
    if (empty($data['form_id'])) {
        $errors[] = 'Missing critical: form_id';
    }
    
    if (empty($data['participant_id'])) {
        $warnings[] = 'Missing participant_id, will generate fingerprint';
    }
    
    // Verificar que form_responses no esté vacío si hay campos enviados
    if (isset($data['form_responses']) && empty($data['form_responses'])) {
        $warnings[] = 'Empty form_responses - possible field name issue';
    }
    
    // Log para auditoría
    if (!empty($warnings)) {
        error_log('[EIPSI SAFETY] Submission warnings: ' . implode(' | ', $warnings));
    }
    
    return array(
        'valid' => empty($errors),
        'errors' => $errors,
        'warnings' => $warnings,
        'timestamp' => current_time('mysql'),
    );
}

public static function eipsi_safety_save_with_retry($data, $max_retries = 3) {
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/privacy-config.php';
    $data = eipsi_filter_capture_data($data, get_privacy_config($data['form_id'] ?? null));

    $attempt = 0;
    $last_error = null;
    
    while ($attempt < $max_retries) {
        $attempt++;
        
        try {
            $result = eipsi_safety_attempt_save($data);
            
            if ($result['success']) {
                // Log éxito
                error_log(sprintf(
                    '[EIPSI SAFETY] Submission saved successfully on attempt %d. ID: %s',
                    $attempt,
                    $result['insert_id'] ?? 'unknown'
                ));
                
                // ✅ AUTO-SYNC: Sincronizar campos del formulario a survey_participants
                eipsi_auto_sync_participant_fields($data, $result['insert_id']);
                
                return $result;
            }
            
            $last_error = $result['error'] ?? 'Unknown error';
            error_log(sprintf('[EIPSI SAFETY] Save attempt %d failed: %s', $attempt, $last_error));
            
            // Esperar antes de reintentar (backoff exponencial)
            if ($attempt < $max_retries) {
                usleep($attempt * 500000); // 0.5s, 1s, 1.5s
            }
            
        } catch (Exception $e) {
            $last_error = $e->getMessage();
            error_log('[EIPSI SAFETY] Exception on attempt ' . $attempt . ': ' . $last_error);
        }
    }
    
    // Todos los intentos fallaron - activar modo emergencia
    return eipsi_safety_emergency_save($data, $last_error);
}

public static function eipsi_safety_get_health_status() {
    global $wpdb;
    
    $status = array(
        'healthy' => true,
        'issues' => array(),
        'stats' => array(),
    );
    
    // Verificar si hay emergencias sin resolver
    $emergency_table = $wpdb->prefix . 'eipsi_emergency_submissions';
    $unresolved = $wpdb->get_var("SELECT COUNT(*) FROM {$emergency_table} WHERE resolved = 0");
    
    if ($unresolved > 0) {
        $status['healthy'] = false;
        $status['issues'][] = sprintf(
            '%d submission(s) en modo emergencia sin resolver',
            $unresolved
        );
    }
    
    // Verificar submissions recientes con respuestas vacías
    $recent_empty = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}vas_form_results 
         WHERE submitted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
         AND (form_responses IS NULL OR form_responses = '[]' OR form_responses = '{}')"
    );
    
    if ($recent_empty > 0) {
        $status['healthy'] = false;
        $status['issues'][] = sprintf(
            '%d submission(s) recientes con respuestas vacías',
            $recent_empty
        );
    }
    
    $status['stats']['unresolved_emergencies'] = $unresolved;
    $status['stats']['recent_empty_responses'] = $recent_empty;
    
    // Verificar sincronizaciones recientes (silencioso - solo para diagnóstico)
    $last_sync_check = get_transient('eipsi_last_sync_check');
    if ($last_sync_check === false) {
        // Contar participantes con datos sincronizados recientemente
        $synced_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}survey_participants 
             WHERE updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        set_transient('eipsi_last_sync_check', $synced_count, 300); // Cache 5 min
        $status['stats']['recent_syncs'] = intval($synced_count);
    } else {
        $status['stats']['recent_syncs'] = intval($last_sync_check);
    }
    
    return $status;
}
public static function attempt($data) {
 require_once EIPSI_FORMS_PLUGIN_DIR.'admin/privacy-config.php';
 require_once EIPSI_FORMS_PLUGIN_DIR.'admin/database.php';
 $data=eipsi_filter_capture_data($data,get_privacy_config($data['form_id']??null));
 $external=new EIPSI_External_Database();$fallback=false;$primary=null;
 if($external->is_enabled()){
  try{$primary=$external->insert_form_submission($data);}catch(Throwable $e){$primary=array('success'=>false,'error'=>$e->getMessage(),'error_code'=>'EXTERNAL_DB_UNAVAILABLE');}
  if(!empty($primary['success'])){return EIPSI_Storage_Result::normalize(array('success'=>true,'insert_id'=>$primary['insert_id'],'storage'=>'external_db','timestamp'=>current_time('mysql')));}
  $fallback=true;
 }
 $result=EIPSI_Local_Submission_Store::insert($data);
 $result['fallback_used']=$fallback;
 if($fallback){$result['primary_error_code']=$primary['error_code']??'EXTERNAL_DB_UNAVAILABLE';$result['primary_error']=$primary['error']??'External write unavailable';}
 return EIPSI_Storage_Result::normalize($result);
}

}
