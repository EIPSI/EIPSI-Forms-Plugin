<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Pool_Completion_Adapter {

public static function eipsi_check_pool_completion_on_submit($data) {
    $survey_id      = absint($data['survey_id'] ?? 0);
    $participant_id = sanitize_text_field($data['participant_id'] ?? '');
    $form_id        = sanitize_text_field($data['form_id'] ?? '');

    if (!$survey_id || !$participant_id) {
        return;
    }

    global $wpdb;

    // Buscar si este estudio está en algún pool activo con asignación pendiente
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $pool_assignments = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT pa.*, p.config, p.pool_name as pool_name
             FROM {$wpdb->prefix}eipsi_pool_assignments pa
             JOIN {$wpdb->prefix}eipsi_longitudinal_pools p ON p.id = pa.pool_id
             WHERE pa.study_id = %d
             AND pa.participant_id = %s
             AND pa.completed = 0
             AND p.status = 'active'",
            $survey_id,
            $participant_id
        )
    );

    if (empty($pool_assignments)) {
        return;
    }

    // Cargar el servicio de asignación si no está cargado
    if (!class_exists('EIPSI_Pool_Assignment_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-pool-assignment-service.php';
    }

    $service = new EIPSI_Pool_Assignment_Service();

    foreach ($pool_assignments as $assignment) {
        // Verificar si el estudio está completado (todas las waves activas con status 'submitted')
        if ($service->is_study_completed($survey_id, $participant_id)) {
            // Marcar asignación como completada (con analytics)
            $marked = $service->mark_completed($assignment->pool_id, $participant_id, $form_id);

            if ($marked) {
                error_log(sprintf(
                    '[EIPSI-POOL-COMPLETION] Pool %d: Participante %d completó estudio %d',
                    $assignment->pool_id,
                    $participant_id,
                    $survey_id
                ));

                // Disparar hook de completitud para extensibilidad futura

            }
        }
    }
}

public static function eipsi_notify_researcher_on_pool_completion($data) {
    $pool_id        = absint($data['pool_id'] ?? 0);
    $participant_id = sanitize_text_field($data['participant_id'] ?? '');
    $study_id       = absint($data['study_id'] ?? 0);

    if (!$pool_id || !$participant_id || !$study_id) {
        return;
    }

    global $wpdb;

    // Obtener config del pool
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $pool = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}eipsi_longitudinal_pools WHERE id = %d",
            $pool_id
        )
    );

    if (!$pool) {
        return;
    }

    $config = json_decode($pool->config ?? '{}', true);

    // Solo notificar si está habilitado en la config
    if (empty($config['notify_on_completion'])) {
        return;
    }

    // Obtener email del investigador (admin del sitio)
    $admin_email = get_option('admin_email');

    // Obtener datos del participante
    // Si el participant_id es numérico, buscar por ID. Si no, buscar por email (si aplica) o usarlo directamente.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $participant = null;
    if (is_numeric($participant_id)) {
        $participant = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT email, first_name, last_name FROM {$wpdb->prefix}survey_participants WHERE id = %d",
                $participant_id
            )
        );
    }

    // Obtener nombre del estudio
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $study = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT study_name FROM {$wpdb->prefix}survey_studies WHERE id = %d",
            $study_id
        )
    );

    $participant_name = $participant
        ? trim(($participant->first_name ?? '') . ' ' . ($participant->last_name ?? ''))
        : "ID: {$participant_id}";

    $participant_email = $participant ? $participant->email : 'N/A';
    $study_name = $study ? $study->study_name : "Estudio #{$study_id}";

    $subject = sprintf(
        '[EIPSI Forms] Participante completó estudio en pool "%s"',
        $pool->pool_name
    );

    $message = sprintf(
        "Hola,\n\n" .
        "Un participante ha completado un estudio asignado por pool.\n\n" .
        "📊 Pool: %s\n" .
        "👤 Participante: %s (%s)\n" .
        "📚 Estudio: %s\n" .
        "🕐 Fecha: %s\n" .
        "🆔 Pool ID: %d\n" .
        "🆔 Estudio ID: %d\n" .
        "📝 Form ID: %s\n\n" .
        "---\n" .
        "Este es un mensaje automático del sistema EIPSI Forms.",
        $pool->pool_name,
        $participant_name,
        $participant_email,
        $study_name,
        current_time('d/m/Y H:i:s'),
        $pool_id,
        $study_id,
        sanitize_text_field($data['form_id'] ?? 'N/A')
    );

    $headers = array('Content-Type: text/plain; charset=UTF-8');
    $sent = wp_mail($admin_email, $subject, $message, $headers);

    // Log en survey_email_log si existe la tabla
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $table_exists = $wpdb->get_var(
        "SHOW TABLES LIKE '{$wpdb->prefix}survey_email_log'"
    );

    if ($table_exists) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert(
            $wpdb->prefix . 'survey_email_log',
            array(
                'recipient'  => $admin_email,
                'subject'    => $subject,
                'status'     => $sent ? 'sent' : 'failed',
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s')
        );
    }

    if ($sent) {
        error_log(sprintf(
            '[EIPSI-POOL-NOTIFICATION] Email enviado a %s por completitud en pool %d',
            $admin_email,
            $pool_id
        ));
    }
}
}
