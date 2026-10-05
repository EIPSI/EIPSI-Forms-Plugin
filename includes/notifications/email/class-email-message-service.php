<?php
/** Notifications owner; public WordPress adapters retain existing contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Notification_Email_Message_Service {

public static function generate_magic_link_url($survey_id, $participant_id) {
        // Validate survey_id before proceeding
        $survey_id = intval($survey_id);
        if ($survey_id <= 0) {
            error_log("[EIPSI Email] Invalid survey_id in generate_magic_link_url: $survey_id");
            return false;
        }

        if (!class_exists('EIPSI_MagicLinksService')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-magic-links-service.php';
        }

        $token = EIPSI_MagicLinksService::generate_magic_link($survey_id, $participant_id);

        if (!$token) {
            return false;
        }
        // Get study code for the survey
        $study_code = self::get_study_code($survey_id);

        // Try to get the study page URL
        $base_url = null;
        if ($study_code && function_exists('eipsi_get_study_page_url')) {
            $base_url = eipsi_get_study_page_url($survey_id, $study_code);
        }

        // v2.5.4 - Fallback to Pool page if study page is not defined
        if (empty($base_url)) {
            $base_url = self::get_pool_page_url_fallback($survey_id);
        }

        // Fallback to site_url if no study page exists
        if (empty($base_url)) {
            $base_url = site_url('/');
        }

        // Get participant email for pre-filling the login form
        $participant = self::get_participant($participant_id);
        $email_pre = $participant ? urlencode($participant->email) : '';

        $magic_link = add_query_arg([
            'eipsi_magic' => $token,
            'survey_id' => $survey_id
        ], $base_url);

        // Add email pre-fill parameter if we have the participant's email
        if ($email_pre) {
            $magic_link = add_query_arg('email_pre', $email_pre, $magic_link);
        }

        return $magic_link;
    }

private static function get_study_code($survey_id) {
        global $wpdb;

        $survey_id = intval($survey_id);
        if ($survey_id <= 0) {
            return null;
        }

        return $wpdb->get_var($wpdb->prepare(
            "SELECT study_code FROM {$wpdb->prefix}survey_studies WHERE id = %d LIMIT 1",
            $survey_id
        ));
    }

public static function send_magic_link_email($survey_id, $participant_id, $custom_message = '') {
        // Validate survey_id before proceeding - prevent FK errors
        $survey_id = intval($survey_id);
        if ($survey_id <= 0) {
            error_log("[EIPSI Email] Invalid survey_id: $survey_id for participant $participant_id");
            return array('success' => false, 'magic_link' => null, 'error' => 'ID de estudio inválido: ' . $survey_id);
        }

        $participant = self::get_participant($participant_id);
        if (!$participant) {
            return array('success' => false, 'magic_link' => null, 'error' => 'Participante no encontrado');
        }

        // Verificar que el participante esté activo
        if (!$participant->is_active) {
            error_log("[EIPSI Email] Cannot send magic link to inactive participant: $participant_id");
            return array('success' => false, 'magic_link' => null, 'error' => 'El participante está inactivo');
        }

        $survey_name = self::get_study_name($survey_id);
        $magic_link = self::generate_magic_link_url($survey_id, $participant_id);

        if (!$magic_link) {
            EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, 'magic_link', 'failed', 'Could not generate magic link');
            return array('success' => false, 'magic_link' => null, 'error' => 'No se pudo generar el Magic Link');
        }

        $placeholders = array(
            'first_name' => $participant->first_name,
            'last_name' => $participant->last_name,
            'survey_name' => $survey_name,
            'magic_link' => $magic_link,
            'custom_message' => $custom_message,
            'investigator_name' => get_option('eipsi_investigator_name', 'Equipo de Investigación'),
            'investigator_email' => get_option('eipsi_investigator_email', get_option('admin_email')),
        );

        $subject = "Acceso rápido a {$survey_name}";
        $content = EIPSI_Notification_Email_Template_Service::render_template('magic-link', $placeholders);

        $sent = EIPSI_Notification_Email_Delivery_Service::send_email($survey_id, $participant_id, $participant->email, 'magic_link', $subject, $content);

        if (!$sent) {
            return array('success' => false, 'magic_link' => $magic_link, 'error' => 'No se pudo enviar el email');
        }

        return array('success' => true, 'magic_link' => $magic_link, 'error' => null);
    }

public static function get_magic_link_preview($survey_id, $participant_id) {
        $participant = self::get_participant($participant_id);
        if (!$participant) {
            return array('success' => false, 'message' => 'Participante no encontrado');
        }

        $survey_name = self::get_study_name($survey_id);
        $magic_link = self::get_latest_magic_link_url($survey_id, $participant_id);

        if (empty($magic_link)) {
            $magic_link = add_query_arg('eipsi_magic', 'PREVIEW', site_url('/'));
        }

        $placeholders = array(
            'first_name' => $participant->first_name,
            'last_name' => $participant->last_name,
            'survey_name' => $survey_name,
            'magic_link' => $magic_link,
            'custom_message' => '',
            'investigator_name' => get_option('eipsi_investigator_name', 'Equipo de Investigación'),
            'investigator_email' => get_option('eipsi_investigator_email', get_option('admin_email')),
        );

        $subject = "Acceso rápido a {$survey_name}";
        $content = EIPSI_Notification_Email_Template_Service::render_template('magic-link', $placeholders);

        return array(
            'success' => true,
            'subject' => $subject,
            'content' => $content,
            'magic_link' => $magic_link,
            'email' => $participant->email
        );
    }

public static function extend_magic_link_expiry($survey_id, $participant_id, $hours = 48) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'survey_magic_links';
        $magic_link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE survey_id = %d AND participant_id = %d AND used_at IS NULL ORDER BY created_at DESC LIMIT 1",
            $survey_id,
            $participant_id
        ));

        if (!$magic_link) {
            return array('success' => false, 'message' => 'No hay Magic Links activos para extender.');
        }

        $now_ts = current_time('timestamp', true);
        $expires_ts = strtotime($magic_link->expires_at);
        if ($expires_ts < $now_ts) {
            $expires_ts = $now_ts;
        }

        $new_expires_ts = $expires_ts + ($hours * HOUR_IN_SECONDS);
        $new_expires_at = gmdate('Y-m-d H:i:s', $new_expires_ts);

        $updated = $wpdb->update(
            $table_name,
            array('expires_at' => $new_expires_at),
            array('id' => $magic_link->id),
            array('%s'),
            array('%d')
        );

        if ($updated === false) {
            return array('success' => false, 'message' => 'No se pudo extender el Magic Link.');
        }

        return array('success' => true, 'expires_at' => $new_expires_at);
    }

public static function send_welcome_email($survey_id, $participant_id) {
        // Validate survey_id before proceeding
        $survey_id = intval($survey_id);
        if ($survey_id <= 0) {
            error_log("[EIPSI Email] Invalid survey_id in send_welcome_email: $survey_id");
            return false;
        }

        $participant = self::get_participant($participant_id);
        if (!$participant) return false;

        // Verificar que el participante esté activo
        if (!$participant->is_active) {
            error_log("[EIPSI Email] Cannot send welcome email to inactive participant: $participant_id");
            return false;
        }

        $survey_name = self::get_study_name($survey_id);
        $magic_link = self::generate_magic_link_url($survey_id, $participant_id);

        if (!$magic_link) {
            EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, 'welcome', 'failed', 'Could not generate magic link');
            return false;
        }

        $placeholders = array(
            'first_name' => $participant->first_name,
            'last_name' => $participant->last_name,
            'survey_name' => $survey_name,
            'magic_link' => $magic_link,
            'investigator_name' => get_option('eipsi_investigator_name', 'Equipo de Investigación'),
            'investigator_email' => get_option('eipsi_investigator_email', get_option('admin_email')),
        );

        $subject = "Bienvenido a {$survey_name}";
        $content = EIPSI_Notification_Email_Template_Service::render_template('welcome', $placeholders);

        return EIPSI_Notification_Email_Delivery_Service::send_email($survey_id, $participant_id, $participant->email, 'welcome', $subject, $content);
    }

public static function send_confirmation_email($survey_id, $participant_id, $confirmation_token) {
        $participant = self::get_participant($participant_id);
        if (!$participant) {
            error_log("[EIPSI Email] Cannot send confirmation: participant not found: $participant_id");
            return false;
        }

        // Don't send confirmation to already active participants
        if ($participant->is_active) {
            error_log("[EIPSI Email] Skipping confirmation: participant already active: $participant_id");
            return false;
        }

        $survey_name = self::get_study_name($survey_id);
        $confirmation_link = EIPSI_Email_Confirmation_Service::generate_confirmation_url($confirmation_token, $participant->email);

        $placeholders = array(
            'first_name' => !empty($participant->first_name) ? $participant->first_name : '',
            'survey_name' => $survey_name,
            'confirmation_link' => $confirmation_link,
            'expiry_hours' => defined('EIPSI_CONFIRMATION_TOKEN_EXPIRY_HOURS') ? EIPSI_CONFIRMATION_TOKEN_EXPIRY_HOURS : 48,
            'investigator_name' => get_option('eipsi_investigator_name', 'Equipo de Investigación'),
            'investigator_email' => get_option('eipsi_investigator_email', get_option('admin_email')),
        );

        $subject = "Confirma tu email para participar en {$survey_name}";
        $content = EIPSI_Notification_Email_Template_Service::render_template('email-confirmation', $placeholders);

        return EIPSI_Notification_Email_Delivery_Service::send_email($survey_id, $participant_id, $participant->email, 'confirmation_request', $subject, $content);
    }

public static function get_confirmation_email_preview($survey_id, $participant_id) {
        $participant = self::get_participant($participant_id);
        if (!$participant) {
            return array('success' => false, 'message' => 'Participante no encontrado');
        }

        $survey_name = self::get_study_name($survey_id);
        $preview_token = 'PREVIEW_CONFIRMATION_TOKEN_' . $participant_id;
        $confirmation_link = site_url('/?eipsi_confirm=' . $preview_token . '&email=' . urlencode($participant->email));

        $placeholders = array(
            'first_name' => !empty($participant->first_name) ? $participant->first_name : 'Participante',
            'survey_name' => $survey_name,
            'confirmation_link' => $confirmation_link,
            'expiry_hours' => defined('EIPSI_CONFIRMATION_TOKEN_EXPIRY_HOURS') ? EIPSI_CONFIRMATION_TOKEN_EXPIRY_HOURS : 48,
            'investigator_name' => get_option('eipsi_investigator_name', 'Equipo de Investigación'),
            'investigator_email' => get_option('eipsi_investigator_email', get_option('admin_email')),
        );

        $subject = "Confirma tu email para participar en {$survey_name}";
        $content = EIPSI_Notification_Email_Template_Service::render_template('email-confirmation', $placeholders);

        return array(
            'success' => true,
            'subject' => $subject,
            'content' => $content,
            'confirmation_link' => $confirmation_link,
            'email' => $participant->email
        );
    }

public static function send_welcome_after_confirmation($survey_id, $participant_id) {
        $participant = self::get_participant($participant_id);
        if (!$participant) {
            error_log("[EIPSI Email] Cannot send welcome after confirmation: participant not found: $participant_id");
            return false;
        }

        // v2.5.4 - Verificar que el participante esté activo (enforce double opt-in logic)
        if (!$participant->is_active) {
            error_log("[EIPSI Email] Cannot send welcome after confirmation to inactive participant: $participant_id");
            return false;
        }

        $survey_name = self::get_study_name($survey_id);
        $magic_link = self::generate_magic_link_url($survey_id, $participant_id);

        if (!$magic_link) {
            EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, 'welcome_after_confirmation', 'failed', 'Could not generate magic link');
            return false;
        }

        $placeholders = array(
            'first_name' => $participant->first_name,
            'last_name' => $participant->last_name,
            'survey_name' => $survey_name,
            'magic_link' => $magic_link,
            'investigator_name' => get_option('eipsi_investigator_name', 'Equipo de Investigación'),
            'investigator_email' => get_option('eipsi_investigator_email', get_option('admin_email')),
        );

        $subject = "Email confirmado - Acceso a {$survey_name}";
        $content = EIPSI_Notification_Email_Template_Service::render_template('welcome', $placeholders);

        return EIPSI_Notification_Email_Delivery_Service::send_email($survey_id, $participant_id, $participant->email, 'welcome_after_confirmation', $subject, $content);
    }

public static function send_wave_reminder_email($survey_id, $participant_id, $wave, $nudge_stage = 0) {
        $participant = self::get_participant($participant_id);
        if (!$participant) return false;

        // Verificar que el participante esté activo
        if (!$participant->is_active) {
            error_log("[EIPSI Email] Cannot send reminder email to inactive participant: $participant_id");
            return false;
        }

        // Ensure we have the wave object
        if (is_numeric($wave)) {
            if (class_exists('EIPSI_Wave_Service')) {
                $wave = EIPSI_Wave_Service::get_wave($wave);
            } else {
                // Fallback direct DB
                global $wpdb;
                $wave = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}survey_waves WHERE id = %d", $wave));
            }
        }

        if (!$wave) {
            EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, 'reminder', 'failed', 'Invalid wave ID');
            return false;
        }

        // === PHASE 2 T1-ANCHOR: Pre-send validation (Double Check) ===
        // Prevent sending emails for expired waves
        // This is the safety net mentioned in the roadmap
        if (class_exists('EIPSI_Wave_Expiration_Service')) {
            global $wpdb;
            $assignment = $wpdb->get_row($wpdb->prepare(
                "SELECT status, due_at FROM {$wpdb->prefix}survey_assignments
                 WHERE participant_id = %d AND wave_id = %d",
                $participant_id,
                $wave->id
            ));

            if ($assignment && EIPSI_Wave_Expiration_Service::is_assignment_expired($assignment)) {
                error_log(sprintf(
                    '[EIPSI Email] Blocked reminder email for expired wave (Wave %d, Participant %d, Due: %s)',
                    $wave->id,
                    $participant_id,
                    $assignment->due_at ?? 'N/A'
                ));
                EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, 'reminder', 'blocked', 'Wave expired');
                return false;
            }
        }

        $survey_name = self::get_study_name($survey_id);
        $magic_link = self::generate_magic_link_url($survey_id, $participant_id);

        if (!$magic_link) {
            EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, 'reminder', 'failed', 'Could not generate magic link');
            return false;
        }

        // Calculate due date formatted
        $due_date = !empty($wave->due_date) ? date_i18n(get_option('date_format'), strtotime($wave->due_date)) : 'Pronto';

        // Build due date HTML block for templates
        if (!empty($wave->due_date)) {
            $due_date_html = '<div class="due-box">';
            $due_date_html .= '<p style="margin: 0;"><strong>Fecha límite: ' . $due_date . '</strong></p>';
            $due_date_html .= '<p style="margin: 5px 0 0; font-size: 13px;">Por favor completa la evaluación antes de esta fecha.</p>';
            $due_date_html .= '</div>';
        } else {
            $due_date_html = '';
        }

        $placeholders = array(
            'first_name' => $participant->first_name,
            'survey_name' => $survey_name,
            'wave_index' => isset($wave->wave_index) ? "Toma " . $wave->wave_index : $wave->name,
            'due_at' => $due_date,
            'due_date_html' => $due_date_html,
            'magic_link' => $magic_link,
            'estimated_time' => isset($wave->estimated_time) ? $wave->estimated_time : '10-15',
            'investigator_name' => get_option('eipsi_investigator_name', 'Equipo de Investigación'),
            'investigator_email' => get_option('eipsi_investigator_email', get_option('admin_email')),
        );

        // Select template based on nudge stage
        $stage = intval($nudge_stage);
        $template_name = 'wave-nudge-' . $stage;

        // Fallback to wave-reminder if nudge template doesn't exist
        $template_file = EIPSI_FORMS_PLUGIN_DIR . 'includes/emails/' . $template_name . '.php';
        if (!file_exists($template_file)) {
            $template_name = 'wave-reminder';
        }

        // Subject based on stage
        $subjects = array(
            0 => "Tu siguiente evaluación está disponible - {$survey_name}",
            1 => "Recordatorio amable - {$survey_name}",
            2 => "Recordatorio importante - {$survey_name}",
            3 => "Urgente: Evaluación pendiente - {$survey_name}",
            4 => "ÚLTIMO recordatorio - {$survey_name}",
        );
        $subject = isset($subjects[$stage]) ? $subjects[$stage] : "Recordatorio - {$survey_name}";

        $content = EIPSI_Notification_Email_Template_Service::render_template($template_name, $placeholders);

        // v2.6.1 - Incluir wave_index en email_type para mejor tracking
        // Formato: wave_availability_T1, nudge_1_T2, etc.
        $wave_index = isset($wave->wave_index) ? $wave->wave_index : 1;
        $base_type = ($stage === 0) ? 'wave_availability' : 'nudge_' . $stage;
        $email_type = $base_type . '_T' . $wave_index;

        // Metadata con info de la wave
        $metadata = array(
            'wave_id' => $wave->id,
            'wave_index' => $wave_index,
            'wave_name' => isset($wave->name) ? $wave->name : '',
            'nudge_stage' => $stage,
            'base_type' => $base_type
        );

        return EIPSI_Notification_Email_Delivery_Service::send_email($survey_id, $participant_id, $participant->email, $email_type, $subject, $content, $metadata);
    }

public static function send_wave_confirmation_email($survey_id, $participant_id, $wave, $next_wave = null) {
        $participant = self::get_participant($participant_id);
        if (!$participant) return false;

        // Verificar que el participante esté activo
        if (!$participant->is_active) {
            error_log("[EIPSI Email] Cannot send confirmation email to inactive participant: $participant_id");
            return false;
        }

        $survey_name = self::get_study_name($survey_id);

        // Handle Wave Object/ID
        if (is_numeric($wave) && class_exists('EIPSI_Wave_Service')) {
            $wave = EIPSI_Wave_Service::get_wave($wave);
        } else if (is_numeric($wave)) {
            global $wpdb;
            $wave = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}survey_waves WHERE id = %d", $wave));
        }

        $wave_name = $wave ? (isset($wave->wave_index) ? "Toma " . $wave->wave_index : $wave->name) : 'Toma reciente';

        // Next wave info
        $next_wave_text = "Te avisaremos pronto";
        $next_due_text = "Por definir";

        if ($next_wave) {
             if (is_numeric($next_wave) && class_exists('EIPSI_Wave_Service')) {
                $next_wave = EIPSI_Wave_Service::get_wave($next_wave);
            }
            if ($next_wave) {
                $next_wave_text = isset($next_wave->wave_index) ? "Toma " . $next_wave->wave_index : $next_wave->name;
                $next_due_text = !empty($next_wave->start_date) ? date_i18n(get_option('date_format'), strtotime($next_wave->start_date)) : 'Pronto';
            }
        }

        $placeholders = array(
            'first_name' => $participant->first_name,
            'survey_name' => $survey_name,
            'wave_index' => $wave_name,
            'submitted_at' => date_i18n(get_option('date_format') . ' ' . get_option('time_format')),
            'next_wave_index' => $next_wave_text,
            'next_due_at' => $next_due_text,
            'investigator_name' => get_option('eipsi_investigator_name', 'Equipo de Investigación'),
            'investigator_email' => get_option('eipsi_investigator_email', get_option('admin_email')),
        );

        $subject = "Recibimos tu respuesta";
        $content = EIPSI_Notification_Email_Template_Service::render_template('wave-confirmation', $placeholders);

        return EIPSI_Notification_Email_Delivery_Service::send_email($survey_id, $participant_id, $participant->email, 'confirmation', $subject, $content);
    }

public static function send_dropout_recovery_email($survey_id, $participant_id, $wave) {
        $participant = self::get_participant($participant_id);
        if (!$participant) return false;

        // Verificar que el participante esté activo
        if (!$participant->is_active) {
            error_log("[EIPSI Email] Cannot send recovery email to inactive participant: $participant_id");
            return false;
        }

        $survey_name = self::get_study_name($survey_id);

        if (is_numeric($wave) && class_exists('EIPSI_Wave_Service')) {
            $wave = EIPSI_Wave_Service::get_wave($wave);
        } else if (is_numeric($wave)) {
            global $wpdb;
            $wave = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}survey_waves WHERE id = %d", $wave));
        }

        if (!$wave) {
            EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, 'recovery', 'failed', 'Invalid wave ID');
            return false;
        }

        $magic_link = self::generate_magic_link_url($survey_id, $participant_id);

        if (!$magic_link) {
            EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, 'recovery', 'failed', 'Could not generate magic link');
            return false;
        }

        $placeholders = array(
            'first_name' => $participant->first_name,
            'survey_name' => $survey_name,
            'wave_index' => isset($wave->wave_index) ? "Toma " . $wave->wave_index : $wave->name,
            'magic_link' => $magic_link,
            'investigator_name' => get_option('eipsi_investigator_name', 'Equipo de Investigación'),
            'investigator_email' => get_option('eipsi_investigator_email', get_option('admin_email')),
        );

        $subject = "Te extrañamos - {$survey_name}";
        $content = EIPSI_Notification_Email_Template_Service::render_template('dropout-recovery', $placeholders);

        return EIPSI_Notification_Email_Delivery_Service::send_email($survey_id, $participant_id, $participant->email, 'recovery', $subject, $content);
    }

private static function get_participant($participant_id) {
        global $wpdb;
        $participant = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_participants WHERE id = %d",
            $participant_id
        ));

        if (!$participant) {
            error_log("[EIPSI Email] Participant not found: $participant_id");
            return null;
        }

        return $participant;
    }

private static function get_study_name($survey_id) {
        global $wpdb;

        $survey_id = intval($survey_id);
        if ($survey_id <= 0) {
            return __('Estudio de Investigación', 'eipsi-forms');
        }

        // Try wp_survey_studies first (longitudinal studies)
        $name = $wpdb->get_var($wpdb->prepare(
            "SELECT study_name FROM {$wpdb->prefix}survey_studies WHERE id = %d LIMIT 1",
            $survey_id
        ));

        if (!empty($name)) {
            return sanitize_text_field($name);
        }

        // Fallback: try as a WordPress post title (non-longitudinal surveys)
        $post_title = get_the_title($survey_id);
        if (!empty($post_title) && $post_title !== __('Auto Draft', 'default')) {
            return $post_title;
        }

        error_log("[EIPSI Email] Study name not found for survey_id: $survey_id");
        return __('Estudio de Investigación', 'eipsi-forms');
    }

private static function get_pool_page_url_fallback($study_id) {
        global $wpdb;
        $pools_table = $wpdb->prefix . 'eipsi_longitudinal_pools';

        // Check if the table exists first
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$pools_table'") === $pools_table;
        if (!$table_exists) {
            return null;
        }

        $pools = $wpdb->get_results("SELECT id, config FROM $pools_table");

        foreach ($pools as $pool) {
            $config = json_decode($pool->config, true);
            if (isset($config['studies']) && is_array($config['studies'])) {
                foreach ($config['studies'] as $study) {
                    $s_id = is_array($study['study_id']) ? ($study['study_id']['id'] ?? 0) : $study['study_id'];
                    if (intval($s_id) === intval($study_id)) {
                        // Found a pool! Now get its page.
                        $pages = get_posts(array(
                            'post_type' => 'page',
                            'meta_key' => 'eipsi_pool_id',
                            'meta_value' => $pool->id,
                            'posts_per_page' => 1
                        ));
                        if (!empty($pages)) {
                            return get_permalink($pages[0]->ID);
                        }
                    }
                }
            }
        }

        // Final fallback: Any page that looks like a pool
        $pages = get_posts(array(
            'post_type' => 'page',
            'meta_key' => 'eipsi_pool_id',
            'posts_per_page' => 1
        ));
        if (!empty($pages)) {
            return get_permalink($pages[0]->ID);
        }

        return null;
    }

private static function get_latest_magic_link_url($survey_id, $participant_id) {
        $magic_link = self::get_latest_magic_link_record($survey_id, $participant_id);

        if (!$magic_link || empty($magic_link->token_plain)) {
            return '';
        }

        return add_query_arg('eipsi_magic', $magic_link->token_plain, site_url('/'));
    }

private static function get_latest_magic_link_record($survey_id, $participant_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_magic_links WHERE survey_id = %d AND participant_id = %d ORDER BY created_at DESC LIMIT 1",
            $survey_id,
            $participant_id
        ));
    }

public static function resend_email($email_log_id) {
        global $wpdb;

        // Get original email log
        $original_log = EIPSI_Notification_Email_Log_Service::get_email_details($email_log_id);

        if (!$original_log) {
            return array(
                'success' => false,
                'message' => 'Email log not found'
            );
        }

        if ($original_log->status === 'sent') {
            return array(
                'success' => false,
                'message' => 'Email already sent successfully'
            );
        }

        // Get participant and wave info if needed
        $participant = self::get_participant($original_log->participant_id);
        if (!$participant) {
            return array(
                'success' => false,
                'message' => 'Participant not found'
            );
        }

        // Get wave if needed (for reminder/recovery types)
        $wave = null;
        $wave_dependent_types = array('reminder', 'recovery', 'wave_availability', 'manual_reminder', 'nudge_1', 'nudge_2', 'nudge_3', 'nudge_4');
        if (in_array($original_log->email_type, $wave_dependent_types)) {
            $wave_id = 0;
            if (!empty($original_log->metadata)) {
                $metadata = json_decode($original_log->metadata, true);
                $wave_id = $metadata['wave_id'] ?? 0;
            }

            // Fallback: try to find current wave if metadata is missing
            if (!$wave_id) {
                $current_wave = self::get_current_wave_for_participant($original_log->survey_id, $original_log->participant_id);
                $wave_id = $current_wave ? ($current_wave->wave_id ?? 0) : 0;
            }

            if ($wave_id) {
                $wave = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}survey_waves WHERE id = %d",
                    $wave_id
                ));
            }
        }

        // v2.5.4 - Resend based on type (Bypasses automatic limits for administrators)
        $result = false;
        switch ($original_log->email_type) {
            case 'welcome':
                $result = self::send_welcome_email($original_log->survey_id, $original_log->participant_id);
                break;
            case 'reminder':
            case 'nudge_1':
            case 'nudge_2':
            case 'nudge_3':
            case 'nudge_4':
            case 'wave_availability':
                if ($original_log->email_type === 'wave_availability' && class_exists('EIPSI_Wave_Availability_Email_Service')) {
                    // Special case for Nudge 0: Use force send to bypass 3-attempt limit
                    $assignment = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}survey_assignments WHERE participant_id = %d AND study_id = %d AND status = 'pending' LIMIT 1",
                        $original_log->participant_id,
                        $original_log->survey_id
                    ));
                    if ($assignment && $wave && $participant) {
                        $force_res = EIPSI_Wave_Availability_Email_Service::force_send_nudge_zero($assignment, $wave, $participant, $original_log->survey_id);
                        $result = $force_res['success'];
                    } else {
                        $result = $wave ? self::send_wave_reminder_email($original_log->survey_id, $original_log->participant_id, $wave, 0) : false;
                    }
                } else {
                    $stage = (strpos($original_log->email_type, 'nudge_') === 0) ? intval(substr($original_log->email_type, 6)) : 0;
                    $result = $wave ? self::send_wave_reminder_email($original_log->survey_id, $original_log->participant_id, $wave, $stage) : false;
                }
                break;
            case 'confirmation':
                $result = self::send_wave_confirmation_email($original_log->survey_id, $original_log->participant_id, $wave);
                break;
            case 'recovery':
                $result = $wave ? self::send_dropout_recovery_email($original_log->survey_id, $original_log->participant_id, $wave) : false;
                break;
            case 'manual_reminder':
                $result = self::send_manual_reminder_email($original_log->survey_id, $original_log->participant_id, $wave);
                break;
            case 'magic_link':
                $ml_res = self::send_magic_link_email($original_log->survey_id, $original_log->participant_id);
                $result = $ml_res['success'];
                break;
        }

        if ($result) {
            return array(
                'success' => true,
                'message' => 'Email resent successfully',
                'new_log_id' => $wpdb->insert_id
            );
        } else {
            return array(
                'success' => false,
                'message' => 'Failed to resend email'
            );
        }
    }

public static function send_manual_reminders($survey_id, $participant_ids, $wave_id = null, $custom_message = null) {
        global $wpdb;

        if (empty($participant_ids)) {
            return array(
                'sent_count' => 0,
                'failed_count' => 0,
                'total_count' => 0,
                'errors' => array('No participants specified')
            );
        }

        $survey_id = absint($survey_id);
        $wave_obj = null;
        $errors = array();
        $sent_count = 0;
        $failed_count = 0;

        // Get wave info if provided
        if ($wave_id) {
            if (class_exists('EIPSI_Wave_Service')) {
                $wave_obj = EIPSI_Wave_Service::get_wave($wave_id);
            } else {
                $wave_obj = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}survey_waves WHERE id = %d",
                    $wave_id
                ));
            }
        }

        foreach ($participant_ids as $participant_id) {
            $participant_id = absint($participant_id);
            $result = self::send_manual_reminder_email($survey_id, $participant_id, $wave_obj, $custom_message);

            if ($result) {
                $sent_count++;
            } else {
                $failed_count++;
                $errors[] = "Failed to send reminder to participant ID: $participant_id";
            }
        }

        return array(
            'sent_count' => $sent_count,
            'failed_count' => $failed_count,
            'total_count' => count($participant_ids),
            'errors' => $errors
        );
    }

private static function send_manual_reminder_email($survey_id, $participant_id, $wave = null, $custom_message = null) {
        $participant = self::get_participant($participant_id);
        if (!$participant) {
            EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, 'manual_reminder', 'failed', 'Participant not found');
            return false;
        }

        // v2.5.4 - Verificar que el participante esté activo
        if (!$participant->is_active) {
            error_log("[EIPSI Email] Cannot send manual reminder to inactive participant: $participant_id");
            EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, 'manual_reminder', 'failed', 'Participante inactivo');
            return false;
        }

        $survey_name = self::get_study_name($survey_id);
        $magic_link = self::generate_magic_link_url($survey_id, $participant_id);

        if (!$magic_link) {
            EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, 'manual_reminder', 'failed', 'Could not generate magic link');
            return false;
        }

        $placeholders = array(
            'first_name' => $participant->first_name,
            'survey_name' => $survey_name,
            'wave_index' => $wave ? (isset($wave->wave_index) ? "Toma " . $wave->wave_index : $wave->name) : 'Tu próxima toma',
            'due_date' => $wave && !empty($wave->due_date) ? date_i18n(get_option('date_format'), strtotime($wave->due_date)) : 'Pronto',
            'custom_message' => !empty($custom_message) ? $custom_message : '',
            'magic_link' => $magic_link,
            'investigator_name' => get_option('eipsi_investigator_name', 'Equipo de Investigación'),
            'investigator_email' => get_option('eipsi_investigator_email', get_option('admin_email')),
        );

        $subject = $wave ? "Recordatorio: Toma {$wave->wave_index} en {$survey_name}" : "Recordatorio: {$survey_name}";
        $content = EIPSI_Notification_Email_Template_Service::render_template('manual-reminder', $placeholders);

        return EIPSI_Notification_Email_Delivery_Service::send_email($survey_id, $participant_id, $participant->email, 'manual_reminder', $subject, $content);
    }

public static function resend_participant_email($participant_id, $email_type, $survey_id = 0, $wave_id = null) {
        // Validate email type
        $valid_types = array('welcome', 'reminder', 'magic_link', 'confirmation', 'recovery');
        if (!in_array($email_type, $valid_types)) {
            return array(
                'success' => false,
                'message' => 'Tipo de email inválido',
                'error' => 'invalid_email_type'
            );
        }

        // Get participant
        $participant = self::get_participant($participant_id);
        if (!$participant) {
            return array(
                'success' => false,
                'message' => 'Participante no encontrado',
                'error' => 'participant_not_found'
            );
        }

        // Check if participant is active
        if (!$participant->is_active) {
            return array(
                'success' => false,
                'message' => 'El participante está inactivo. Actívalo primero para enviar emails.',
                'error' => 'participant_inactive'
            );
        }

        // Get survey_id from participant if not provided
        if (empty($survey_id)) {
            $survey_id = $participant->survey_id;
        }

        // Validate survey exists
        $survey_name = self::get_study_name($survey_id);
        if (empty($survey_name)) {
            return array(
                'success' => false,
                'message' => 'Estudio no encontrado',
                'error' => 'survey_not_found'
            );
        }

        // Get wave object if needed
        $wave = null;
        if (in_array($email_type, array('reminder', 'confirmation', 'recovery'))) {
            if (empty($wave_id)) {
                // Try to get current active wave for participant
                $wave = self::get_current_wave_for_participant($survey_id, $participant_id);
            } else {
                $wave = self::get_wave($wave_id);
            }

            if (!$wave) {
                return array(
                    'success' => false,
                    'message' => 'No se encontró una onda activa para este participante',
                    'error' => 'wave_not_found'
                );
            }
        }

        // Send the appropriate email type
        $result = false;
        $error_msg = '';

        try {
            switch ($email_type) {
                case 'welcome':
                    $result = self::send_welcome_email($survey_id, $participant_id);
                    $error_msg = 'No se pudo enviar el email de bienvenida';
                    break;

                case 'magic_link':
                    $send_result = self::send_magic_link_email($survey_id, $participant_id);
                    $result = !empty($send_result['success']);
                    $error_msg = $send_result['error'] ?? 'No se pudo enviar el Magic Link';
                    break;

                case 'reminder':
                    $result = self::send_wave_reminder_email($survey_id, $participant_id, $wave);
                    $error_msg = 'No se pudo enviar el recordatorio de onda';
                    break;

                case 'confirmation':
                    $result = self::send_wave_confirmation_email($survey_id, $participant_id, $wave);
                    $error_msg = 'No se pudo enviar el email de confirmación';
                    break;

                case 'recovery':
                    $result = self::send_dropout_recovery_email($survey_id, $participant_id, $wave);
                    $error_msg = 'No se pudo enviar el email de recuperación';
                    break;
            }
        } catch (Exception $e) {
            error_log("[EIPSI Email] Exception in resend_participant_email: " . $e->getMessage());
            return array(
                'success' => false,
                'message' => 'Error al enviar email: ' . $e->getMessage(),
                'error' => 'exception'
            );
        }

        if ($result) {
            $type_labels = array(
                'welcome' => 'Email de bienvenida',
                'magic_link' => 'Magic Link',
                'reminder' => 'Recordatorio de onda',
                'confirmation' => 'Email de confirmación',
                'recovery' => 'Email de recuperación'
            );

            return array(
                'success' => true,
                'message' => sprintf(
                    '%s enviado exitosamente a %s',
                    $type_labels[$email_type],
                    $participant->email
                ),
                'error' => null
            );
        }

        return array(
            'success' => false,
            'message' => $error_msg,
            'error' => 'send_failed'
        );
    }

private static function get_wave($wave_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_waves WHERE id = %d",
            $wave_id
        ));
    }

private static function get_current_wave_for_participant($survey_id, $participant_id) {
        global $wpdb;

        // Get the most recent incomplete assignment for this participant
        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, w.*
             FROM {$wpdb->prefix}survey_assignments a
             JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
             WHERE a.participant_id = %d
               AND a.study_id = %d
               AND a.status != 'submitted'
             ORDER BY w.wave_index ASC
             LIMIT 1",
            $participant_id,
            $survey_id
        ));

        return $assignment;
    }

public static function get_pending_participants($survey_id, $wave_id) {
        global $wpdb;

        $participant_table = $wpdb->prefix . 'survey_participants';
        $assignments_table = $wpdb->prefix . 'survey_assignments';
        $results_table = $wpdb->prefix . 'vas_form_results';

        // Get participants assigned to wave but haven't submitted
        $query = "SELECT DISTINCT p.id, p.first_name, p.last_name, p.email
                  FROM {$participant_table} p
                  INNER JOIN {$assignments_table} a ON p.id = a.participant_id
                  LEFT JOIN {$results_table} r ON p.id = r.participant_id AND a.wave_id = r.wave_index
                  WHERE a.wave_id = %d
                    AND p.is_active = 1
                    AND (r.id IS NULL OR r.status != 'submitted')
                  ORDER BY p.last_name, p.first_name";

        return $wpdb->get_results($wpdb->prepare($query, $wave_id));
    }

public static function send_test_email($to = null) {
        // Sanitize and validate email
        $recipient = sanitize_email($to);

        // Use provided email or default to investigator/admin email
        if (empty($recipient)) {
            $recipient = get_option('eipsi_investigator_email', get_option('admin_email', ''));
        }

        if (empty($recipient) || !is_email($recipient)) {
            return array(
                'success' => false,
                'message' => __('Email de destino inválido', 'eipsi-forms'),
                'details' => __('Por favor proporciona un email válido para el test o configura el email del investigador/administrador.', 'eipsi-forms')
            );
        }

        $smtp_service = class_exists('EIPSI_SMTP_Service') ? new EIPSI_SMTP_Service() : null;
        $smtp_config = $smtp_service ? $smtp_service->get_config() : null;
        $use_smtp = !empty($smtp_config);

        $subject = sprintf(
            __('🧪 Test de Email - %s', 'eipsi-forms'),
            get_bloginfo('name')
        );

        $site_name = get_bloginfo('name');
        $site_url = home_url();
        $date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'));
        $investigator_name = get_option('eipsi_investigator_name', '');

        // Build email content
        $content = sprintf(
            "<h2>🧪 Test de Sistema de Email - EIPSI Forms</h2>
            <p>Este es un email de prueba generado automáticamente.</p>
            <hr>
            <p><strong>Sitio:</strong> %s</p>
            <p><strong>URL:</strong> %s</p>
            <p><strong>Fecha/Hora:</strong> %s</p>
            <p><strong>Destinatario:</strong> %s</p>
            <p><strong>Configuración SMTP:</strong> %s</p>",
            esc_html($site_name),
            esc_url($site_url),
            esc_html($date),
            esc_html($recipient),
            $use_smtp ? '✅ ' . esc_html($smtp_config['host'] . ':' . $smtp_config['port']) : '❌ ' . __('Inactivo (usando wp_mail)', 'eipsi-forms')
        );

        if ($use_smtp && isset($smtp_config['host'])) {
            $content .= sprintf(
                '<p><strong>Servidor SMTP:</strong> %s</p>',
                esc_html($smtp_config['host'] . ':' . $smtp_config['port'])
            );
        }

        if (!empty($investigator_name)) {
            $content .= sprintf(
                '<p><strong>Investigador:</strong> %s</p>',
                esc_html($investigator_name)
            );
        }

        $content .= '<hr>';
        $content .= sprintf(
            '<p>%s</p>',
            __('✅ Si estás viendo este mensaje, el sistema de email está funcionando correctamente.', 'eipsi-forms')
        );
        $content .= sprintf(
            '<p><small>%s %s</small></p>',
            __('Este email fue enviado usando', 'eipsi-forms'),
            self::get_email_method_label()
        );

        try {
            // Use the send_email method for consistency and logging
            $result = EIPSI_Notification_Email_Delivery_Service::send_email(0, 0, $recipient, 'test', $subject, $content);

            if ($result) {
                return array(
                    'success' => true,
                    'message' => __('Email de prueba enviado exitosamente', 'eipsi-forms'),
                    'details' => sprintf(
                        __('Método: %s | Destinatario: %s | Fecha: %s', 'eipsi-forms'),
                        self::get_email_method_label(),
                        $recipient,
                        $date
                    )
                );
            } else {
                return array(
                    'success' => false,
                    'message' => __('Error al enviar el email de prueba', 'eipsi-forms'),
                    'details' => __('El sistema no pudo enviar el email de prueba. Revisa los logs de error.', 'eipsi-forms')
                );
            }
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => __('Excepción durante el envío', 'eipsi-forms'),
                'details' => __('Error: ', 'eipsi-forms') . $e->getMessage()
            );
        }
    }

public static function diagnose_email_system() {
        $issues = array();
        $recommendations = array();

        // Check SMTP configuration
        $smtp_service = class_exists('EIPSI_SMTP_Service') ? new EIPSI_SMTP_Service() : null;
        $smtp_config = $smtp_service ? $smtp_service->get_config() : null;

        if (empty($smtp_config)) {
            $issues[] = 'SMTP no configurado - usando wp_mail()';
            $recommendations[] = 'Para mejor entregabilidad, configura SMTP en Configuración > SMTP';
        }

        // Check investigator email
        $investigator_email = get_option('eipsi_investigator_email', '');
        if (empty($investigator_email)) {
            $issues[] = 'Email del investigador no configurado';
            $recommendations[] = 'Configura el email del investigador en Configuración > SMTP';
        } elseif (!is_email($investigator_email)) {
            $issues[] = 'Email del investigador inválido';
            $recommendations[] = 'Corrige el formato del email del investigador';
        }

        // Check admin email
        $admin_email = get_option('admin_email', '');
        if (!is_email($admin_email)) {
            $issues[] = 'Email de administrador inválido';
            $recommendations[] = 'Corrige el email de administrador en WordPress';
        }

        // Check WordPress mail settings
        if (!function_exists('wp_mail')) {
            $issues[] = 'wp_mail() no disponible';
            $recommendations[] = 'Verifica que WordPress esté correctamente instalado';
        }

        $status = empty($issues) ? 'okay' : 'warning';

        return array(
            'status' => $status,
            'issues' => $issues,
            'recommendations' => $recommendations,
            'smtp_configured' => !empty($smtp_config),
            'investigator_email' => $investigator_email,
            'admin_email' => $admin_email
        );
    }

private static function get_email_method_label() {
        $smtp_service = class_exists('EIPSI_SMTP_Service') ? new EIPSI_SMTP_Service() : null;
        $smtp_config = $smtp_service ? $smtp_service->get_config() : null;

        if ($smtp_config) {
            return sprintf('SMTP (%s:%d)', $smtp_config['host'], $smtp_config['port']);
        }

        return 'wp_mail() (WordPress default)';
    }

public static function get_wave_email_preview($survey_id, $wave_id, $participant_id, $email_type = 'reminder') {
        $participant = self::get_participant($participant_id);

        // If no participant, return sample data
        if (!$participant) {
            return self::get_sample_email_preview($survey_id, $wave_id, $email_type);
        }

        $survey_name = self::get_study_name($survey_id);
        $wave = EIPSI_Wave_Service::get_wave($wave_id);

        if (!$wave) {
            return array('success' => false, 'message' => 'Wave not found');
        }

        // Get existing magic link or generate preview token
        $magic_link = self::get_latest_magic_link_url($survey_id, $participant_id);

        if (empty($magic_link)) {
            $magic_link = add_query_arg('eipsi_magic', 'PREVIEW_TOKEN_' . $participant_id, site_url('/'));
        }

        $investigator_name = get_option('eipsi_investigator_name', 'Equipo de Investigación');
        $investigator_email = get_option('eipsi_investigator_email', get_option('admin_email'));

        $placeholders = array(
            'first_name' => $participant->first_name ?: 'Participante',
            'last_name' => $participant->last_name ?: '',
            'survey_name' => $survey_name,
            'wave_index' => isset($wave->wave_index) ? "Toma " . $wave->wave_index : $wave->name,
            'due_date' => !empty($wave->due_date) ? date_i18n(get_option('date_format'), strtotime($wave->due_date)) : 'Por definir',
            'due_at' => !empty($wave->due_date) ? date_i18n(get_option('date_format'), strtotime($wave->due_date)) : 'Por definir',
            'magic_link' => $magic_link,
            'estimated_time' => isset($wave->estimated_time) ? $wave->estimated_time : '10-15',
            'investigator_name' => $investigator_name,
            'investigator_email' => $investigator_email,
            'custom_message' => '',
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
                $placeholders['submitted_at'] = date_i18n(get_option('date_format') . ' ' . get_option('time_format'));
                $placeholders['next_wave_index'] = 'Próxima toma';
                $placeholders['next_due_at'] = 'Por definir';
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

        $content = EIPSI_Notification_Email_Template_Service::render_template($template_name, $placeholders);

        return array(
            'success' => true,
            'is_sample' => false,
            'subject' => $subject,
            'content' => $content,
            'magic_link' => $magic_link,
            'email' => $participant->email,
            'wave' => array(
                'id' => $wave->id,
                'name' => $wave->name,
                'wave_index' => $wave->wave_index,
                'due_date' => $wave->due_date,
            ),
            'participant' => array(
                'id' => $participant->id,
                'first_name' => $participant->first_name,
                'last_name' => $participant->last_name,
                'email' => $participant->email,
            )
        );
    }

public static function get_sample_email_preview($survey_id, $wave_id, $email_type = 'reminder') {
        $survey_name = self::get_study_name($survey_id) ?: 'Nombre del Estudio';
        $wave = EIPSI_Wave_Service::get_wave($wave_id);

        $investigator_name = get_option('eipsi_investigator_name', 'Equipo de Investigación');
        $investigator_email = get_option('eipsi_investigator_email', get_option('admin_email'));

        $placeholders = array(
            'first_name' => '[Nombre del Participante]',
            'last_name' => '',
            'survey_name' => $survey_name,
            'wave_index' => $wave ? ("Toma " . $wave->wave_index) : 'T1',
            'due_date' => $wave && !empty($wave->due_date) ? date_i18n(get_option('date_format'), strtotime($wave->due_date)) : '[Fecha de vencimiento]',
            'due_at' => $wave && !empty($wave->due_date) ? date_i18n(get_option('date_format'), strtotime($wave->due_date)) : '[Fecha de vencimiento]',
            'magic_link' => site_url('/?eipsi_magic=PREVIEW_TOKEN'),
            'estimated_time' => '10-15',
            'investigator_name' => $investigator_name,
            'investigator_email' => $investigator_email,
            'custom_message' => '',
            'submitted_at' => date_i18n(get_option('date_format') . ' ' . get_option('time_format')),
            'next_wave_index' => 'Próxima toma',
            'next_due_at' => 'Por definir',
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
                $subject = $wave ? "Recordatorio: Toma {$wave->wave_index} en {$survey_name}" : "Recordatorio: {$survey_name}";
                $template_name = 'manual-reminder';
                break;
        }

        $content = EIPSI_Notification_Email_Template_Service::render_template($template_name, $placeholders);

        return array(
            'success' => true,
            'is_sample' => true,
            'subject' => $subject,
            'content' => $content,
            'magic_link' => $placeholders['magic_link'],
            'email' => '[email@participante.com]',
            'wave' => $wave ? array(
                'id' => $wave->id,
                'name' => $wave->name,
                'wave_index' => $wave->wave_index,
                'due_date' => $wave->due_date,
            ) : null,
            'participant_sample' => array(
                'first_name' => $placeholders['first_name'],
                'email' => $placeholders['email'],
            )
        );
    }
}
