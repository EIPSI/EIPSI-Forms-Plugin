<?php
/** M4 longitudinal owner. Existing public adapters preserve their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Longitudinal_Study_Config_Service {
public static function wp_ajax_eipsi_get_study_cron_config_handler($study_id,$request=array(),$query=array()) {    // Load the cron jobs tab content
    ob_start();
    include EIPSI_FORMS_PLUGIN_DIR . 'admin/tabs/study-cron-jobs-tab.php';
    $html = ob_get_clean();

    return EIPSI_Form_Response::success(array(
        'html' => $html
    ));
}

public static function wp_ajax_eipsi_save_study_cron_config_handler($study_id,$request=array(),$query=array()) {    // Validar y sanitizar datos
    $cron_enabled = isset($request['cron_enabled']) ? filter_var($request['cron_enabled'], FILTER_VALIDATE_BOOLEAN) : false;
    $cron_frequency = isset($request['cron_frequency']) ? sanitize_text_field($request['cron_frequency']) : '';
    $cron_actions = isset($request['cron_actions']) ? array_map('sanitize_text_field', (array)$request['cron_actions']) : array();

    // Validaciones
    $errors = array();

    if ($cron_enabled) {
        if (empty($cron_frequency)) {
            $errors[] = 'La frecuencia es requerida cuando los cron jobs están activados.';
        } elseif (!in_array($cron_frequency, array('daily', 'weekly', 'monthly'))) {
            $errors[] = 'Frecuencia inválida.';
        }

        if (empty($cron_actions)) {
            $errors[] = 'Debes seleccionar al menos una acción.';
        }
    }

    if (!empty($errors)) {
        return EIPSI_Form_Response::error(array(
            'message' => implode(' ', $errors)
        ));
    }

    // Guardar configuración
    update_post_meta($study_id, '_eipsi_study_cron_enabled', $cron_enabled);
    update_post_meta($study_id, '_eipsi_study_cron_frequency', $cron_frequency);
    update_post_meta($study_id, '_eipsi_study_cron_actions', $cron_actions);

    // Programar cron job si está activado
    if ($cron_enabled) {
        // Desprogramar cualquier cron job existente
        wp_clear_scheduled_hook('eipsi_study_cron_job', array($study_id));

        // Programar nuevo cron job según la frecuencia
        $timestamp = current_time('timestamp');

        switch ($cron_frequency) {
            case 'daily':
                $next_run = strtotime('tomorrow', $timestamp);
                break;
            case 'weekly':
                $next_run = strtotime('next monday', $timestamp);
                break;
            case 'monthly':
                $next_run = strtotime('first day of next month', $timestamp);
                break;
            default:
                $next_run = strtotime('tomorrow', $timestamp);
        }

        // Programar el evento
        wp_schedule_event($next_run, 'eipsi_' . $cron_frequency, 'eipsi_study_cron_job', array($study_id));

        // Guardar información de ejecución
        update_post_meta($study_id, '_eipsi_study_cron_next_run', date('Y-m-d H:i:s', $next_run));
    } else {
        // Desprogramar cron job si se desactiva
        wp_clear_scheduled_hook('eipsi_study_cron_job', array($study_id));
        delete_post_meta($study_id, '_eipsi_study_cron_next_run');
    }

    // Obtener información actualizada
    $last_run = get_post_meta($study_id, '_eipsi_study_cron_last_run', true);
    $next_run = get_post_meta($study_id, '_eipsi_study_cron_next_run', true);

    return EIPSI_Form_Response::success(array(
        'message' => 'Configuración de cron jobs guardada exitosamente.',
        'last_run' => $last_run ? date('Y-m-d H:i:s', strtotime($last_run)) : 'Nunca',
        'next_run' => $next_run ? date('Y-m-d H:i:s', strtotime($next_run)) : 'No programada'
    ));
}

public static function wp_ajax_eipsi_save_study_settings_handler($study_id,$request=array(),$query=array()) {    global $wpdb;

    // Verify study exists and is in draft status
    $study = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}survey_studies WHERE id = %d",
        $study_id
    ));

    if (!$study) {
        return EIPSI_Form_Response::error('Study not found');
    }

    if ($study->status !== 'draft') {
        return EIPSI_Form_Response::error('Only draft studies can be edited');
    }

    // Sanitize and validate input
    $study_name = isset($request['study_name']) ? sanitize_text_field($request['study_name']) : '';
    $study_description = isset($request['study_description']) ? sanitize_textarea_field($request['study_description']) : '';
    $time_config = isset($request['time_config']) ? sanitize_text_field($request['time_config']) : 'limited';
    $start_date = isset($request['start_date']) ? sanitize_text_field($request['start_date']) : '';
    $end_date = isset($request['end_date']) ? sanitize_text_field($request['end_date']) : '';

    // Validations
    $errors = array();

    if (empty($study_name)) {
        $errors[] = 'El nombre del estudio es requerido.';
    }

    if ($time_config === 'limited') {
        if (empty($start_date)) {
            $errors[] = 'La fecha de inicio es requerida cuando el tiempo es limitado.';
        }

        if (empty($end_date)) {
            $errors[] = 'La fecha de finalización es requerida cuando el tiempo es limitado.';
        }

        if (!empty($start_date) && !empty($end_date) && strtotime($end_date) <= strtotime($start_date)) {
            $errors[] = 'La fecha de finalización debe ser posterior a la fecha de inicio.';
        }
    }

    if (!empty($errors)) {
        return EIPSI_Form_Response::error(array(
            'message' => implode(' ', $errors)
        ));
    }

    // Prepare update data
    $update_data = array(
        'name' => $study_name,
        'description' => $study_description,
        'status' => 'draft'
    );

    if ($time_config === 'limited') {
        $update_data['start_date'] = $start_date;
        $update_data['end_date'] = $end_date;
    } else {
        $update_data['start_date'] = null;
        $update_data['end_date'] = null;
    }

    // Update study in database
    $updated = $wpdb->update(
        "{$wpdb->prefix}survey_studies",
        $update_data,
        array('id' => $study_id),
        array('%s', '%s', '%s'),
        array('%d')
    );

    if ($updated === false) {
        return EIPSI_Form_Response::error('Failed to update study settings');
    }

    return EIPSI_Form_Response::success(array(
        'message' => 'Configuración del estudio guardada exitosamente.',
        'study_id' => $study_id
    ));
}
}
