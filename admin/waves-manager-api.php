<?php
/**
 * AJAX API Handlers for Waves Manager
 *
 * @since 1.4.0
 * @updated 1.5.2 - Added participant management and unlimited time support
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register AJAX handlers
 */
add_action('wp_ajax_eipsi_save_wave', 'wp_ajax_eipsi_save_wave_handler');
add_action('wp_ajax_eipsi_delete_wave', 'wp_ajax_eipsi_delete_wave_handler');
add_action('wp_ajax_eipsi_get_wave', 'wp_ajax_eipsi_get_wave_handler');
add_action('wp_ajax_eipsi_get_available_participants', 'wp_ajax_eipsi_get_available_participants_handler');
add_action('wp_ajax_eipsi_get_study_participants', 'wp_ajax_eipsi_get_study_participants_handler');
add_action('wp_ajax_eipsi_assign_participants', 'wp_ajax_eipsi_assign_participants_handler');
add_action('wp_ajax_eipsi_extend_deadline', 'wp_ajax_eipsi_extend_deadline_handler');
add_action('wp_ajax_eipsi_send_reminder', 'wp_ajax_eipsi_send_reminder_handler');
add_action('wp_ajax_eipsi_get_pending_participants', 'wp_ajax_eipsi_get_pending_participants_handler');

// Participant Management Handlers
// Note: wp_ajax_eipsi_add_participant is defined in study-dashboard-api.php to avoid duplication
add_action('wp_ajax_eipsi_edit_participant', 'wp_ajax_eipsi_edit_participant_handler');
// Registration owned by the dashboard API's explicit nonce dispatcher.
add_action('wp_ajax_eipsi_get_participant', 'wp_ajax_eipsi_get_participant_handler');
add_action('wp_ajax_eipsi_validate_wave_dates', 'wp_ajax_eipsi_validate_wave_dates_handler');
add_action('wp_ajax_eipsi_preview_wave_email', 'wp_ajax_eipsi_preview_wave_email_handler');
add_action('wp_ajax_eipsi_get_wave_email_preview', 'wp_ajax_eipsi_get_wave_email_preview_handler');

/**
 * Create or Update Wave
 */
function wp_ajax_eipsi_save_wave_handler() {
    check_ajax_referer('eipsi_waves_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => __('No tienes permisos para realizar esta acción.', 'eipsi-forms')));
    }

    $wave_id = isset($_POST['wave_id']) ? absint($_POST['wave_id']) : 0;
    $study_id = isset($_POST['study_id']) ? absint($_POST['study_id']) : 0;

    if (!$study_id) {
        wp_send_json_error(array('message' => __('ID de estudio no proporcionado. Por favor, selecciona un estudio válido.', 'eipsi-forms')));
    }

    // Validate required fields with detailed error messages
    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
    $wave_index = isset($_POST['wave_index']) ? absint($_POST['wave_index']) : 0;

    if (empty($name)) {
        wp_send_json_error(array('message' => __('El nombre de la onda es obligatorio. Por favor, ingresa un nombre descriptivo.', 'eipsi-forms')));
    }

    if (!$form_id) {
        wp_send_json_error(array('message' => __('Debes seleccionar un formulario asociado para esta onda.', 'eipsi-forms')));
    }

    if ($wave_index < 1) {
        wp_send_json_error(array('message' => __('El índice de la onda debe ser un número positivo (1, 2, 3, etc.).', 'eipsi-forms')));
    }

    // Handle unlimited time option
    $has_time_limit = filter_var($_POST['has_time_limit'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    $completion_time_limit = isset($_POST['completion_time_limit']) ? absint($_POST['completion_time_limit']) : 0;

    // Validate time limit if enabled
    if ($has_time_limit && ($completion_time_limit < 1 || $completion_time_limit > 180)) {
        wp_send_json_error(array('message' => __('El tiempo límite debe estar entre 1 y 180 minutos.', 'eipsi-forms')));
    }

    $wave_data = array(
        'name' => $name,
        'wave_index' => $wave_index,
        'form_id' => $form_id,
        'due_date' => sanitize_text_field($_POST['due_date'] ?? ''),
        'start_date' => sanitize_text_field($_POST['start_date'] ?? ''),
        'is_mandatory' => filter_var($_POST['is_mandatory'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        'has_time_limit' => $has_time_limit,
        'completion_time_limit' => $has_time_limit ? $completion_time_limit : null,
        'status' => sanitize_text_field($_POST['status'] ?? 'active')
    );

    foreach (array('start_date', 'due_date') as $field) {
        if (!array_key_exists($field, $_POST)) { unset($wave_data[$field]); }
    }
    if (!in_array($wave_data['status'], array('draft', 'active', 'completed', 'paused'), true)) {
        wp_send_json_error(array('message' => 'Estado de onda inválido.'));
    }
    if (!empty($_POST['description'])) {
        wp_send_json_error(array('message' => 'La descripción no es editable en el modelo actual.'));
    }
    foreach (array('offset_minutes', 'window_minutes') as $field) {
        if (array_key_exists($field, $_POST)) {
            wp_send_json_error(array('message' => 'Este campo no se edita en Waves Manager: ' . $field));
        }
    }
    if ($wave_id) {
        $existing = EIPSI_Wave_Service::get_wave($wave_id);
        if (!$existing || (int) $existing->study_id !== $study_id) {
            wp_send_json_error(array('message' => 'La onda no pertenece al estudio.'));
        }
        $result = EIPSI_Wave_Service::update_wave($wave_id, $wave_data);
    } else {
        $result = EIPSI_Wave_Service::create_wave($study_id, $wave_data);
    }

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    $success_message = $wave_id 
        ? __('Onda actualizada exitosamente.', 'eipsi-forms')
        : __('Onda creada exitosamente.', 'eipsi-forms');

    // Add context about mandatory/optional status
    if ($wave_data['is_mandatory']) {
        $success_message .= ' ' . __('Esta onda es obligatoria para los participantes.', 'eipsi-forms');
    } else {
        $success_message .= ' ' . __('Esta onda es opcional para los participantes.', 'eipsi-forms');
    }

    wp_send_json_success(array(
        'message' => $success_message,
        'wave_id' => $wave_id ? $wave_id : $result
    ));
}

/**
 * Delete Wave
 */
function wp_ajax_eipsi_delete_wave_handler() {
    check_ajax_referer('eipsi_waves_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $wave_id = isset($_POST['wave_id']) ? absint($_POST['wave_id']) : 0;
    if (!$wave_id) {
        wp_send_json_error('Missing wave_id');
    }

    $result = EIPSI_Wave_Service::delete_wave($wave_id);

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    wp_send_json_success('Onda eliminada');
}

/**
 * Get Single Wave
 */
function wp_ajax_eipsi_get_wave_handler() {
    check_ajax_referer('eipsi_waves_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $wave_id = isset($_GET['wave_id']) ? absint($_GET['wave_id']) : 0;
    if (!$wave_id) {
        wp_send_json_error('Missing wave_id');
    }

    $wave = EIPSI_Wave_Service::get_wave($wave_id);

    if (!$wave) {
        wp_send_json_error('Wave not found');
    }

    global $wpdb;
    $wave->has_assignments = (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}survey_assignments WHERE wave_id = %d", $wave_id
    ));
    wp_send_json_success($wave);
}

/**
 * Get participants of a study that are NOT assigned to a specific wave
 */
function wp_ajax_eipsi_get_available_participants_handler() {
    check_ajax_referer('eipsi_waves_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $study_id = isset($_GET['study_id']) ? absint($_GET['study_id']) : 0;
    $wave_id = isset($_GET['wave_id']) ? absint($_GET['wave_id']) : 0;

    if (!$study_id || !$wave_id) {
        wp_send_json_error('Missing parameters');
    }

    global $wpdb;

    // survey_participants uses survey_id (numeric study ID), first_name + last_name, is_active
    $participants = $wpdb->get_results($wpdb->prepare(
        "SELECT p.id,
                p.id AS participant_id,
                p.email,
                p.first_name,
                p.last_name,
                CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.last_name,'')) AS full_name
         FROM {$wpdb->prefix}survey_participants p
         WHERE p.survey_id = %d
         AND p.is_active = 1
         AND p.id NOT IN (
             SELECT participant_id FROM {$wpdb->prefix}survey_assignments WHERE wave_id = %d
         )
         ORDER BY p.first_name ASC, p.last_name ASC",
        $study_id, $wave_id
    ));

    wp_send_json_success($participants);
}

/**
 * Assign multiple participants to a wave
 */
function wp_ajax_eipsi_assign_participants_handler() {
    check_ajax_referer('eipsi_waves_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $study_id = isset($_POST['study_id']) ? absint($_POST['study_id']) : 0;
    $wave_id = isset($_POST['wave_id']) ? absint($_POST['wave_id']) : 0;
    $participant_ids = isset($_POST['participant_ids']) ? array_map('absint', $_POST['participant_ids']) : array();

    if (!$study_id || !$wave_id || empty($participant_ids)) {
        wp_send_json_error('Missing parameters');
    }

    $count = 0;
    foreach ($participant_ids as $p_id) {
        $result = EIPSI_Assignment_Service::create_assignment($study_id, $wave_id, $p_id);
        if (!is_wp_error($result)) {
            $count++;
        }
    }

    wp_send_json_success(array(
        'message' => sprintf('%d participantes asignados exitosamente.', $count),
        'assigned_count' => $count
    ));
}

/**
 * Extend Wave Deadline
 */
function wp_ajax_eipsi_extend_deadline_handler() {
    check_ajax_referer('eipsi_waves_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $wave_id = isset($_POST['wave_id']) ? absint($_POST['wave_id']) : 0;
    $new_deadline = isset($_POST['new_deadline']) ? sanitize_text_field($_POST['new_deadline']) : '';

    if (!$wave_id || empty($new_deadline)) {
        wp_send_json_error('Missing parameters');
    }

    $result = EIPSI_Wave_Service::update_wave($wave_id, array('due_date' => $new_deadline));

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    wp_send_json_success('Fecha de vencimiento extendida');
}

/**
 * Send Manual Reminders for Wave
 */
function wp_ajax_eipsi_send_reminder_handler() {
    check_ajax_referer('eipsi_waves_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $wave_id = isset($_POST['wave_id']) ? absint($_POST['wave_id']) : 0;
    $participant_ids = isset($_POST['participant_ids']) ? array_map('absint', $_POST['participant_ids']) : array();
    $custom_message = isset($_POST['custom_message']) ? sanitize_textarea_field($_POST['custom_message']) : null;
    $study_id = isset($_POST['study_id']) ? absint($_POST['study_id']) : 0;

    if (!$wave_id) {
        wp_send_json_error('Missing wave_id');
    }

    // If no participants specified, get pending participants for this wave
    if (empty($participant_ids)) {
        if (!$study_id) {
            // Get study_id from wave
            global $wpdb;
            $wave = $wpdb->get_row($wpdb->prepare(
                "SELECT study_id FROM {$wpdb->prefix}survey_waves WHERE id = %d",
                $wave_id
            ));
            $study_id = $wave ? absint($wave->study_id) : 0;
        }

        if ($study_id && class_exists('EIPSI_Email_Service')) {
            $pending = EIPSI_Email_Service::get_pending_participants($study_id, $wave_id);
            $candidate_ids = array_map(function($p) { return $p->id; }, $pending);
            
            // Filter by eligibility: only those who completed previous wave
            require_once dirname(__FILE__) . '/services/class-wave-eligibility-service.php';
            $participant_ids = EIPSI_Wave_Eligibility_Service::filter_pending_by_eligibility(
                $wave_id,
                $study_id,
                $candidate_ids
            );
        }
    }

    if (empty($participant_ids)) {
        wp_send_json_error('No eligible participants to send reminders to (previous wave not completed)');
    }

    $result = array(
        'sent_count' => 0,
        'failed_count' => 0,
        'total_count' => 0,
        'errors' => array()
    );

    if (class_exists('EIPSI_Email_Service') && method_exists('EIPSI_Email_Service', 'send_manual_reminders')) {
        $result = EIPSI_Email_Service::send_manual_reminders($study_id, $participant_ids, $wave_id, $custom_message);
    }

    $message = '';
    if ($result['sent_count'] > 0) {
        $message = sprintf('✅ Se enviaron %d de %d recordatorios.', $result['sent_count'], $result['total_count']);
    } else {
        $message = 'No se pudieron enviar los recordatorios.';
    }

    if (!empty($result['errors'])) {
        $message .= ' Errores: ' . implode(', ', $result['errors']);
    }

    wp_send_json_success(array(
        'message' => $message,
        'sent' => $result['sent_count'],
        'failed' => $result['failed_count'],
        'total' => $result['total_count']
    ));
}

/**
 * Get pending participants for a wave (AJAX)
 */
function wp_ajax_eipsi_get_pending_participants_handler() {
    check_ajax_referer('eipsi_waves_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $wave_id = isset($_GET['wave_id']) ? absint($_GET['wave_id']) : 0;
    $study_id = isset($_GET['study_id']) ? absint($_GET['study_id']) : 0;

    if (!$wave_id) {
        wp_send_json_error('Missing wave_id');
    }

    if (!$study_id) {
        global $wpdb;
        $wave = $wpdb->get_row($wpdb->prepare(
            "SELECT study_id FROM {$wpdb->prefix}survey_waves WHERE id = %d",
            $wave_id
        ));
        $study_id = $wave ? absint($wave->study_id) : 0;
    }

    $participants = array();
    if ($study_id && class_exists('EIPSI_Email_Service') && method_exists('EIPSI_Email_Service', 'get_pending_participants')) {
        $participants = EIPSI_Email_Service::get_pending_participants($study_id, $wave_id);
    }

    wp_send_json_success($participants);
}

/**
 * Get all participants for a study
 */
function wp_ajax_eipsi_get_study_participants_handler() {
    check_ajax_referer('eipsi_waves_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $study_id = isset($_GET['study_id']) ? absint($_GET['study_id']) : 0;
    if (!$study_id) {
        wp_send_json_error('Missing study_id');
    }

    global $wpdb;

    $participants = $wpdb->get_results($wpdb->prepare(
        "SELECT p.id, p.email, p.first_name, p.last_name, p.created_at, p.is_active,
                CONCAT(p.first_name, ' ', p.last_name) as full_name
         FROM {$wpdb->prefix}survey_participants p
         WHERE p.survey_id = %d
         ORDER BY p.created_at DESC",
        $study_id
    ));

    wp_send_json_success($participants);
}

/**
 * Get single participant details
 */
function wp_ajax_eipsi_get_participant_handler() {
    check_ajax_referer('eipsi_waves_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $participant_id = isset($_GET['participant_id']) ? absint($_GET['participant_id']) : 0;
    if (!$participant_id) {
        wp_send_json_error('Missing participant_id');
    }

    $participant = EIPSI_Participant_Service::get_by_id($participant_id);

    if (!$participant) {
        wp_send_json_error('Participant not found');
    }

    // Remove sensitive data
    unset($participant->password_hash);

    wp_send_json_success($participant);
}

/**
 * Edit participant
 */
function wp_ajax_eipsi_edit_participant_handler() {
    check_ajax_referer('eipsi_waves_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $participant_id = isset($_POST['participant_id']) ? absint($_POST['participant_id']) : 0;
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
    $last_name = isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : '';
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if (!$participant_id) {
        wp_send_json_error('Missing participant_id');
    }

    if (!empty($email) && !is_email($email)) {
        wp_send_json_error('Invalid email address');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'survey_participants';

    $data = array();
    $formats = array();

    if (!empty($email)) {
        // Check for duplicate email
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE email = %s AND id != %d",
            $email,
            $participant_id
        ));

        if ($existing) {
            wp_send_json_error('This email is already registered to another participant');
        }

        $data['email'] = $email;
        $formats[] = '%s';
    }

    $data['first_name'] = $first_name;
    $formats[] = '%s';

    $data['last_name'] = $last_name;
    $formats[] = '%s';

    $data['is_active'] = $is_active;
    $formats[] = '%d';

    $result = $wpdb->update(
        $table_name,
        $data,
        array('id' => $participant_id),
        $formats,
        array('%d')
    );

    if ($result === false) {
        wp_send_json_error('Failed to update participant');
    }

    wp_send_json_success(array(
        'message' => 'Participant updated successfully'
    ));
}

/**
 * Delete participant
 */
function wp_ajax_eipsi_delete_participant_waves_handler() {
    check_ajax_referer('eipsi_waves_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $participant_id = isset($_POST['participant_id']) ? absint($_POST['participant_id']) : 0;
    $delete_data = isset($_POST['delete_data']) ? true : false;

    if (!$participant_id) {
        wp_send_json_error('Missing participant_id');
    }

    global $wpdb;

    // Check if participant has submissions
    $has_submissions = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}survey_assignments WHERE participant_id = %d AND status = 'submitted'",
        $participant_id
    ));

    if ($has_submissions > 0 && !$delete_data) {
        // Just deactivate instead of delete
        $result = EIPSI_Participant_Service::set_active($participant_id, false);
        if ($result) {
            wp_send_json_success(array(
                'message' => 'Participant has submissions and was deactivated instead of deleted',
                'deactivated' => true
            ));
        } else {
            wp_send_json_error('Failed to deactivate participant');
        }
    }

    // Delete participant's assignments first
    $wpdb->delete(
        $wpdb->prefix . 'survey_assignments',
        array('participant_id' => $participant_id),
        array('%d')
    );

    // Delete participant's magic links
    $wpdb->delete(
        $wpdb->prefix . 'survey_magic_links',
        array('participant_id' => $participant_id),
        array('%d')
    );

    // Delete participant's sessions
    $wpdb->delete(
        $wpdb->prefix . 'survey_sessions',
        array('participant_id' => $participant_id),
        array('%d')
    );

    // Finally delete participant
    $result = $wpdb->delete(
        $wpdb->prefix . 'survey_participants',
        array('id' => $participant_id),
        array('%d')
    );

    if ($result === false) {
        wp_send_json_error('Failed to delete participant');
    }

    wp_send_json_success(array(
        'message' => 'Participant deleted successfully'
    ));
}

/**
 * Validate wave dates before saving
 */
function wp_ajax_eipsi_validate_wave_dates_handler() {
    check_ajax_referer('eipsi_waves_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => __('No tienes permisos para realizar esta acción.', 'eipsi-forms')));
    }

    $study_id = isset($_POST['study_id']) ? absint($_POST['study_id']) : 0;
    $wave_id = isset($_POST['wave_id']) ? absint($_POST['wave_id']) : 0;

    if (!$study_id) {
        wp_send_json_error(array('message' => __('ID de estudio no proporcionado.', 'eipsi-forms')));
    }

    $wave_data = array(
        'start_date' => isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : null,
        'due_date' => isset($_POST['due_date']) ? sanitize_text_field($_POST['due_date']) : null,
        'wave_index' => isset($_POST['wave_index']) ? absint($_POST['wave_index']) : 1,
    );

    $validation = EIPSI_Wave_Service::validate_wave_dates($wave_data, $study_id, $wave_id);

    wp_send_json_success($validation);
}

/**
 * Preview wave email before sending
 */
function wp_ajax_eipsi_preview_wave_email_handler() {
    check_ajax_referer('eipsi_waves_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => __('No tienes permisos para realizar esta acción.', 'eipsi-forms')));
    }

    $wave_id = isset($_POST['wave_id']) ? absint($_POST['wave_id']) : 0;
    $email_type = isset($_POST['email_type']) ? sanitize_text_field($_POST['email_type']) : 'reminder';
    $participant_id = isset($_POST['participant_id']) ? absint($_POST['participant_id']) : 0;

    if (!$wave_id) {
        wp_send_json_error(array('message' => __('ID de onda no proporcionado.', 'eipsi-forms')));
    }

    // Get wave and study
    $wave = EIPSI_Wave_Service::get_wave($wave_id);
    if (!$wave) {
        wp_send_json_error(array('message' => __('Onda no encontrada.', 'eipsi-forms')));
    }

    $study_id = absint($wave->study_id);

    // If no participant specified, get first pending participant
    if (!$participant_id) {
        if (class_exists('EIPSI_Email_Service') && method_exists('EIPSI_Email_Service', 'get_pending_participants')) {
            $pending = EIPSI_Email_Service::get_pending_participants($study_id, $wave_id);
            if (!empty($pending) && isset($pending[0])) {
                $participant_id = $pending[0]->id;
            }
        }
    }

    // If still no participant, use sample data for preview
    if (!$participant_id) {
        // Generate sample preview with placeholder data
        $preview = generate_sample_email_preview($wave, $email_type);
        wp_send_json_success($preview);
    }

    // Get actual preview with participant data
    if (class_exists('EIPSI_Email_Service')) {
        $preview = EIPSI_Email_Service::get_wave_email_preview($study_id, $wave_id, $participant_id, $email_type);
        wp_send_json_success($preview);
    }

    wp_send_json_error(array('message' => __('No se pudo generar la vista previa.', 'eipsi-forms')));
}

/**
 * Get wave email preview for a specific participant
 */
function wp_ajax_eipsi_get_wave_email_preview_handler() {
    check_ajax_referer('eipsi_waves_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => __('No tienes permisos para realizar esta acción.', 'eipsi-forms')));
    }

    $wave_id = isset($_GET['wave_id']) ? absint($_GET['wave_id']) : 0;
    $participant_id = isset($_GET['participant_id']) ? absint($_GET['participant_id']) : 0;
    $email_type = isset($_GET['email_type']) ? sanitize_text_field($_GET['email_type']) : 'reminder';

    if (!$wave_id || !$participant_id) {
        wp_send_json_error(array('message' => __('Parámetros incompletos.', 'eipsi-forms')));
    }

    $wave = EIPSI_Wave_Service::get_wave($wave_id);
    if (!$wave) {
        wp_send_json_error(array('message' => __('Onda no encontrada.', 'eipsi-forms')));
    }

    $study_id = absint($wave->study_id);

    if (class_exists('EIPSI_Email_Service')) {
        $preview = EIPSI_Email_Service::get_wave_email_preview($study_id, $wave_id, $participant_id, $email_type);
        wp_send_json_success($preview);
    }

    wp_send_json_error(array('message' => __('Servicio de email no disponible.', 'eipsi-forms')));
}

/**
 * Generate sample email preview with placeholder data
 */
function generate_sample_email_preview($wave, $email_type) {
    $survey_name = get_the_title($wave->study_id) ?: 'Nombre del Estudio';
    $investigator_name = get_option('eipsi_investigator_name', 'Equipo de Investigación');
    $investigator_email = get_option('eipsi_investigator_email', get_option('admin_email'));

    $placeholders = array(
        'first_name' => '[Nombre del Participante]',
        'survey_name' => $survey_name,
        'wave_index' => 'T' . $wave->wave_index,
        'due_date' => !empty($wave->due_date) ? date_i18n(get_option('date_format'), strtotime($wave->due_date)) : '[Fecha de vencimiento]',
        'due_at' => !empty($wave->due_date) ? date_i18n(get_option('date_format'), strtotime($wave->due_date)) : '[Fecha de vencimiento]',
        'magic_link' => site_url('/?eipsi_magic=PREVIEW_TOKEN'),
        'estimated_time' => '10-15',
        'investigator_name' => $investigator_name,
        'investigator_email' => $investigator_email,
    );

    $subject = '';
    $template_name = '';

    switch ($email_type) {
        case 'reminder':
            $subject = "Recordatorio: Tu próxima toma en {$survey_name}";
            $template_name = 'wave-reminder';
            break;
        case 'welcome':
            $subject = "Bienvenido a {$survey_name}";
            $template_name = 'welcome';
            break;
        case 'confirmation':
            $subject = "Recibimos tu respuesta";
            $template_name = 'wave-confirmation';
            break;
        case 'recovery':
            $subject = "Te extrañamos - {$survey_name}";
            $template_name = 'dropout-recovery';
            break;
        case 'manual':
        default:
            $subject = "Recordatorio: Toma {$wave->wave_index} en {$survey_name}";
            $template_name = 'manual-reminder';
            break;
    }

    // Get email content using reflection or direct call
    if (class_exists('EIPSI_Email_Service')) {
        $reflection = new ReflectionClass('EIPSI_Email_Service');
        $method = $reflection->getMethod('render_template');
        $method->setAccessible(true);
        $content = $method->invoke(null, $template_name, $placeholders);
    } else {
        $content = "<p>Vista previa del email de tipo: {$email_type}</p>";
        $content .= "<p><strong>Nota:</strong> Esta es una vista previa de muestra. Los datos reales del participante se sustituirán al enviar.</p>";
    }

    return array(
        'success' => true,
        'is_sample' => true,
        'subject' => $subject,
        'content' => $content,
        'magic_link' => $placeholders['magic_link'],
        'email' => '[email@participante.com]',
        'wave' => array(
            'id' => $wave->id,
            'name' => $wave->name,
            'wave_index' => $wave->wave_index,
            'due_date' => $wave->due_date,
        ),
        'participant_sample' => array(
            'first_name' => $placeholders['first_name'],
            'email' => $placeholders['email'],
        )
    );
}

/**
 * Toggle ON/OFF for follow-up reminders (Nudges 1-4)
 * Nudge 0 always sends, this controls follow-ups only
 * 
 * @since 2.3.0
 * @return void
 */
function eipsi_toggle_follow_up_reminders_handler() {
    check_ajax_referer('eipsi_waves_nonce', 'nonce');
    
    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => __('Permisos insuficientes', 'eipsi-forms')));
    }
    
    $wave_id = isset($_POST['wave_id']) ? intval($_POST['wave_id']) : 0;
    $enabled = isset($_POST['enabled']) ? filter_var($_POST['enabled'], FILTER_VALIDATE_BOOLEAN) : false;
    
    if ($wave_id <= 0) {
        wp_send_json_error(array('message' => __('ID de wave inválido', 'eipsi-forms')));
    }
    
    global $wpdb;
    
    $wave = EIPSI_Wave_Service::get_wave($wave_id);
    if (!$wave) { wp_send_json_error(array('message' => 'Wave no encontrada.')); }
    // Update the wave record
    $updated = $wpdb->update(
        $wpdb->prefix . 'survey_waves',
        array('follow_up_reminders_enabled' => $enabled ? 1 : 0),
        array('id' => $wave_id),
        array('%d'),
        array('%d')
    );
    
    if ($updated === false) {
        wp_send_json_error(array('message' => __('Error al actualizar configuración', 'eipsi-forms')));
    }
    
    require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/services/class-nudge-event-scheduler.php';
    $scheduled = EIPSI_Nudge_Event_Scheduler::refresh_wave_follow_ups($wave_id);
    if (is_wp_error($scheduled)) { wp_send_json_error(array('message' => $scheduled->get_error_message())); }
    wp_send_json_success(array(
        'message' => $enabled 
            ? __('Recordatorios de seguimiento activados', 'eipsi-forms')
            : __('Recordatorios de seguimiento desactivados', 'eipsi-forms'),
        'enabled' => $enabled
    ));
}
add_action('wp_ajax_eipsi_toggle_follow_up_reminders', 'eipsi_toggle_follow_up_reminders_handler');

/**
 * Extend deadline for a wave (emergency use)
 * 
 * @since 2.3.0
 * @return void
 */
function eipsi_extend_wave_deadline_handler() {
    check_ajax_referer('eipsi_waves_nonce', 'nonce');
    
    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => __('Permisos insuficientes', 'eipsi-forms')));
    }
    
    $wave_id = isset($_POST['wave_id']) ? intval($_POST['wave_id']) : 0;
    $days_to_add = isset($_POST['days']) ? intval($_POST['days']) : 7;
    $new_date = isset($_POST['new_date']) ? sanitize_text_field($_POST['new_date']) : null;
    
    if ($wave_id <= 0) {
        wp_send_json_error(array('message' => __('ID de wave inválido', 'eipsi-forms')));
    }
    
    global $wpdb;
    
    // Get current wave
    $wave = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}survey_waves WHERE id = %d",
        $wave_id
    ));
    
    if (!$wave) {
        wp_send_json_error(array('message' => __('Wave no encontrada', 'eipsi-forms')));
    }
    
    // Calculate new deadline
    if ($new_date) {
        // Use provided date
        $new_deadline = $new_date;
    } else {
        // Add days to existing or create new
        $current_deadline = !empty($wave->due_date) ? $wave->due_date : current_time('Y-m-d');
        $new_deadline = date('Y-m-d', strtotime($current_deadline . ' + ' . $days_to_add . ' days'));
    }
    
    // Update wave
    $updated = $wpdb->update(
        $wpdb->prefix . 'survey_waves',
        array('due_date' => $new_deadline),
        array('id' => $wave_id),
        array('%s'),
        array('%d')
    );
    
    if ($updated === false) {
        wp_send_json_error(array('message' => __('Error al extender fecha límite', 'eipsi-forms')));
    }
    
    // Format for display
    $formatted_date = date_i18n(get_option('date_format'), strtotime($new_deadline));
    
    wp_send_json_success(array(
        'message' => sprintf(__('Fecha límite extendida al %s', 'eipsi-forms'), $formatted_date),
        'new_deadline' => $new_deadline,
        'formatted_date' => $formatted_date
    ));
}
// DEPRECATED: Conflicto con study-dashboard-api.php - Comentado temporalmente
// add_action('wp_ajax_eipsi_extend_wave_deadline', 'eipsi_extend_wave_deadline_handler');

/**
 * GET reminder/nudge configuration for a wave
 * 
 * @since 2.4.0
 */
add_action('wp_ajax_eipsi_get_reminder_config', 'wp_ajax_eipsi_get_reminder_config_handler');
function wp_ajax_eipsi_get_reminder_config_handler() {
    check_ajax_referer('eipsi_waves_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => __('Unauthorized', 'eipsi-forms')));
    }

    $wave_id = isset($_GET['wave_id']) ? intval($_GET['wave_id']) : 0;
    
    if (!$wave_id) {
        wp_send_json_error(array('message' => __('Missing wave ID', 'eipsi-forms')));
    }

    global $wpdb;
    
    // Get wave nudge config from database
    $wave = $wpdb->get_row($wpdb->prepare(
        "SELECT nudge_config, follow_up_reminders_enabled FROM {$wpdb->prefix}survey_waves WHERE id = %d",
        $wave_id
    ));

    if (!$wave) {
        wp_send_json_error(array('message' => __('Wave not found', 'eipsi-forms')));
    }

    // Parse nudge config
    $nudge_config = array();
    if (!empty($wave->nudge_config)) {
        $nudge_config = json_decode($wave->nudge_config, true);
        if (!is_array($nudge_config)) {
            $nudge_config = array();
        }
    }

    // Build response config for stages 1-4 (JS expects 1-indexed)
    $config = array();
    for ($stage = 1; $stage <= 4; $stage++) {
        $nudge_key = "nudge_{$stage}";
        if (isset($nudge_config[$nudge_key])) {
            $nudge = $nudge_config[$nudge_key];
            // Convert to hours if needed for backwards compatibility
            $value = isset($nudge['value']) ? intval($nudge['value']) : 24 * $stage;
            $unit = isset($nudge['unit']) ? $nudge['unit'] : 'hours';
            
            // If unit is days, convert to hours for the form
            if ($unit === 'days') {
                $value = $value * 24;
                $unit = 'hours';
            }
            
            $config[$stage] = array(
                'enabled' => !empty($nudge['enabled']),
                'hours' => $value,
                'unit' => $unit,
                'subject' => isset($nudge['subject']) ? $nudge['subject'] : ''
            );
        } else {
            // Default values
            $defaults = array(
                1 => array('hours' => 24, 'unit' => 'hours'),
                2 => array('hours' => 48, 'unit' => 'hours'),
                3 => array('hours' => 72, 'unit' => 'hours'),
                4 => array('hours' => 168, 'unit' => 'hours')
            );
            $config[$stage] = array(
                'enabled' => false,
                'hours' => $defaults[$stage]['hours'],
                'unit' => $defaults[$stage]['unit'],
                'subject' => ''
            );
        }
    }

    wp_send_json_success(array(
        'config' => $config,
        'follow_up_reminders_enabled' => !empty($wave->follow_up_reminders_enabled)
    ));
}

/**
 * POST save reminder/nudge configuration for a wave
 * 
 * @since 2.4.0
 */
add_action('wp_ajax_eipsi_save_reminder_config', 'wp_ajax_eipsi_save_reminder_config_handler');
function wp_ajax_eipsi_save_reminder_config_handler() {
    check_ajax_referer('eipsi_waves_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => __('Unauthorized', 'eipsi-forms')));
    }

    $wave_id = isset($_POST['wave_id']) ? intval($_POST['wave_id']) : 0;
    $config_json = isset($_POST['config']) ? stripslashes($_POST['config']) : '{}';
    
    if (!$wave_id) {
        wp_send_json_error(array('message' => __('Missing wave ID', 'eipsi-forms')));
    }

    $config = json_decode($config_json, true);
    if (!is_array($config)) {
        wp_send_json_error(array('message' => __('Invalid config format', 'eipsi-forms')));
    }

    global $wpdb;

    // Build nudge config for database (nudge_1 to nudge_4)
    $nudge_config = array();
    $any_enabled = false;
    
    for ($stage = 1; $stage <= 4; $stage++) {
        if (isset($config[$stage])) {
            $stage_config = $config[$stage];
            $enabled = !empty($stage_config['enabled']);
            $value = isset($stage_config['hours']) ? $stage_config['hours'] : 24 * $stage;
            $unit = isset($stage_config['unit']) ? sanitize_text_field($stage_config['unit']) : 'hours';
            $subject = isset($stage_config['subject']) ? sanitize_text_field($stage_config['subject']) : '';
            
            // Store the value and unit exactly as sent from frontend
            // No conversion needed - the scheduler will handle unit conversion
            $nudge_config["nudge_{$stage}"] = array(
                'enabled' => $enabled,
                'value' => $value,
                'unit' => $unit,
                'subject' => $subject
            );
            
            if ($enabled) {
                $any_enabled = true;
            }
        }
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/services/class-nudge-service.php';
    $result = EIPSI_Nudge_Service::save_wave_configuration($wave_id, $nudge_config);
    if (is_wp_error($result)) { wp_send_json_error(array('message' => $result->get_error_message())); }
    wp_send_json_success(array_merge(array('message' => __('Configuración guardada correctamente', 'eipsi-forms')), $result));
}
