<?php
/** M6 owner; existing public adapters retain their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Submission_Verification_Service {

public static function eipsi_safety_verify_submission($insert_id, $storage_type, $data) {
    global $wpdb;
    
    if ($storage_type === 'emergency_table') {
        // En modo emergencia, ya verificamos con el insert_id
        return true;
    }
    
    // Verificar que el registro existe
    if ($storage_type === 'wordpress_db') {
        $table = $wpdb->prefix . 'vas_form_results';
        $record = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE id = %d",
            $insert_id
        ));
        
        if ($record) {
            // Verificación adicional: comprobar que form_responses no está vacío
            $responses = $wpdb->get_var($wpdb->prepare(
                "SELECT form_responses FROM {$table} WHERE id = %d",
                $insert_id
            ));
            
            if (empty($responses) || $responses === '[]' || $responses === '{}') {
                error_log(sprintf(
                    '[EIPSI SAFETY] WARNING: Submission %d saved but form_responses is empty!',
                    $insert_id
                ));
                return false;
            }
            
            return true;
        }
    }
    
    return false;
}
}
