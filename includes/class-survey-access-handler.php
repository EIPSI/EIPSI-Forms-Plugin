<?php
/**
 * Survey Access Handler
 *
 * Handles Magic Link validation and participant authentication.
 * Endpoint: /survey-access/?ml=TOKEN
 *
 * Also handles email confirmation:
 * Endpoint: /?eipsi_confirm=TOKEN
 *
 * FIX (v2.1.0):
 * - Removed email parameter from confirmation URL.
 *   Emails with '+' characters were decoded as spaces by PHP, causing
 *   sanitize_email() to strip the space and produce a wrong email address,
 *   which made every confirmation fail.  The token alone is sufficient to
 *   identify the participant — the email is looked up from the DB.
 * - setup_session() now uses EIPSI_Auth_Service::create_session() instead
 *   of raw PHP $_SESSION, keeping the session layer consistent.
 * - get_target_wave() corrected to use participant_id + survey_id (not study_id)
 *   to match the actual column names in wp_survey_assignments.
 *
 * @package EIPSI_Forms
 * @since 1.4.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EIPSI_Survey_Access_Handler {

    /**
     * Initialize the handler.
     */
    public function init() {
        add_action( 'template_redirect', array( $this, 'handle_request' ) );
        add_action( 'init',              array( $this, 'add_rewrite_rules' ) );
        add_action( 'template_redirect', array( $this, 'handle_confirmation_request' ) );
    }

    // =========================================================================
    // EMAIL CONFIRMATION
    // =========================================================================

    /**
     * Handle email confirmation request.
     *
     * FIX (v2.1.0): Only the token is required in the URL now.
     * Previously the URL also carried `&email=…`, which broke for addresses
     * containing '+' because PHP decodes '+' as a space before sanitize_email()
     * sees it, producing a wrong (or empty) email and an always-failing check.
     *
     * Old URL format: /?eipsi_confirm=TOKEN&email=user%40example.com
     * New URL format: /?eipsi_confirm=TOKEN
     *
     * The confirmation service looks up the email from the stored token record,
     * so no information is lost.
     *
     * @since 1.5.0
     */
    public function handle_confirmation_request() {
        $confirm_token = isset( $_GET['eipsi_confirm'] ) ? sanitize_text_field( $_GET['eipsi_confirm'] ) : '';

        if ( empty( $confirm_token ) ) {
            return; // Not a confirmation request.
        }

        nocache_headers();

        // Load confirmation service.
        if ( ! class_exists( 'EIPSI_Email_Confirmation_Service' ) ) {
            $path = EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-email-confirmation-service.php';
            if ( file_exists( $path ) ) {
                require_once $path;
            } else {
                $this->render_confirmation_error( 'Error del Sistema', 'El servicio de confirmación no está disponible.' );
                return;
            }
        }

        // FIX: validate by token only — no email parameter needed.
        $validation_result = EIPSI_Email_Confirmation_Service::validate_confirmation_token( $confirm_token );

        if ( ! $validation_result['success'] ) {
            $this->handle_confirmation_error( $validation_result['error'] );
            return;
        }

        // Mark confirmation as completed.
        $mark_result = EIPSI_Email_Confirmation_Service::mark_confirmed( $confirm_token );
        if ( ! $mark_result['success'] ) {
            $this->render_confirmation_error( 'Error', 'No se pudo completar la confirmación. Por favor, contactá al administrador.' );
            return;
        }

        // Activate the participant.
        global $wpdb;
        $participant_id = $validation_result['participant_id'];
        $survey_id      = $validation_result['survey_id'];
        $email          = $validation_result['email']; // comes from the DB record, not the URL

        EIPSI_Participant_State_Service::confirm_email($participant_id);

        // v2.1.5 - Send welcome email FIRST, then create assignments (to ensure correct order)
        // Load email service
        if ( ! class_exists( 'EIPSI_Email_Service' ) ) {
            $email_service_path = EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-email-service.php';
            if ( file_exists( $email_service_path ) ) {
                require_once $email_service_path;
            }
        }

        // Send welcome email with magic link FIRST
        if ( class_exists( 'EIPSI_Email_Service' ) ) {
            EIPSI_Email_Service::send_welcome_after_confirmation( $survey_id, $participant_id );
            error_log(sprintf('[EIPSI-DIAG-EMAIL] Welcome email sent FIRST for participant_id=%d', $participant_id));
        }

        // v1.5.7 - Create wave assignments for the participant after email confirmation
        // Load assignment service if not already loaded
        // ✅ FIX: Verificar función global, no la clase (ambos están en el mismo archivo)
        
        // ✅ DIAGNÓSTICO: Loguear inicio del proceso
        error_log(sprintf('[EIPSI-DIAG-EMAIL] Iniciando creación de assignments para participant_id=%d, survey_id=%d', 
            $participant_id, $survey_id));
        
        $assignment_service_loaded = false;
        if ( ! function_exists( 'eipsi_create_assignments_for_participant' ) ) {
            $assignment_service_path = EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-assignment-service.php';
            $file_exists = file_exists( $assignment_service_path );
            error_log('[EIPSI-DIAG-EMAIL] Archivo class-assignment-service.php existe: ' . ($file_exists ? 'SÍ' : 'NO'));
            
            if ( $file_exists ) {
                require_once $assignment_service_path;
                $assignment_service_loaded = true;
                error_log('[EIPSI-DIAG-EMAIL] Archivo cargado. Función existe: ' . (function_exists('eipsi_create_assignments_for_participant') ? 'SÍ' : 'NO'));
            } else {
                error_log( '[EIPSI-DIAG-EMAIL] ERROR: Assignment service file not found at: ' . $assignment_service_path );
            }
        } else {
            $assignment_service_loaded = true;
            error_log('[EIPSI-DIAG-EMAIL] Función ya existía previamente');
        }

        // Create assignments for all active waves of the study (AFTER welcome email)
        if ( function_exists( 'eipsi_create_assignments_for_participant' ) ) {
            $assignment_result = eipsi_create_assignments_for_participant( $participant_id, $survey_id );
            
            // ✅ DEBUG: Siempre loguear el resultado
            error_log( sprintf(
                '[EIPSI-DIAG-EMAIL] Resultado: created=%d, skipped=%d, errors=%d',
                $assignment_result['created'],
                $assignment_result['skipped'],
                count( $assignment_result['errors'] )
            ) );
            
            if ( ! empty( $assignment_result['errors'] ) ) {
                error_log( '[EIPSI-DIAG-EMAIL] Errores: ' . implode( ', ', $assignment_result['errors'] ) );
            }
        } else {
            error_log( '[EIPSI-DIAG-EMAIL] ERROR: eipsi_create_assignments_for_participant function not available!' );
        }

        // Redirect to login with confirmation message.
        $this->render_confirmation_success( $email, $survey_id );
    }

    /**
     * Handle confirmation errors.
     */
    private function handle_confirmation_error( $error ) {
        $title   = 'Confirmación fallida';
        $message = 'El enlace de confirmación no es válido.';

        switch ( $error ) {
            case 'invalid_token':
                $message = 'El enlace de confirmación no es válido o ya ha sido utilizado.';
                break;
            case 'token_expired':
                $title   = 'Enlace expirado';
                $message = 'Este enlace de confirmación ha expirado. Por favor, solicitá un nuevo enlace desde el panel del estudio.';
                break;
            case 'invalid_parameters':
            default:
                $message = 'El enlace de confirmación está incompleto o es inválido.';
                break;
        }

        $this->render_confirmation_error( $title, $message );
    }

    /**
     * Redirect to login page after successful confirmation.
     *
     * @param string $email     Participant email (from DB, not URL).
     * @param int    $survey_id Survey/Study ID.
     */
    private function render_confirmation_success( $email, $survey_id = 0 ) {
    $login_url = $this->find_study_login_page( $survey_id );

    // Find the study page URL to pass as redirect_to
    $study_url = '';
    if ( $survey_id > 0 ) {
        // Level 1: Exact match by eipsi_study_id meta (Immune to text collisions)
        $study_pages = get_posts( array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => array(
                'relation' => 'OR',
                array(
                    'key'   => 'eipsi_study_id',
                    'value' => $survey_id,
                ),
                array(
                    'key'   => '_eipsi_survey_id',
                    'value' => $survey_id,
                ),
                array(
                    'key'   => '_eipsi_study_id',
                    'value' => $survey_id,
                ),
            ),
        ) );

        if ( ! empty( $study_pages ) ) {
            $study_url = get_permalink( $study_pages[0]->ID );
        } 
        // Level 2: Official helper fallback
        elseif ( function_exists( 'eipsi_get_study_page_url' ) ) {
            global $wpdb;
            $study_code = $wpdb->get_var( $wpdb->prepare(
                "SELECT study_code FROM {$wpdb->prefix}survey_studies WHERE id = %d",
                $survey_id
            ) );
            $study_url = eipsi_get_study_page_url( $survey_id, $study_code );
        }
    }

    // Level 3: Security fallback to Home
    if ( empty( $study_url ) ) {
        $study_url = home_url( '/' );
    }

    $args = array(
        'eipsi_msg'       => 'email_confirmed',
        'eipsi_email'     => rawurlencode( $email ),
        'eipsi_survey_id' => absint( $survey_id ),
    );

    if ( ! empty( $study_code ) ) {
        $args['eipsi_study_code'] = rawurlencode( $study_code );
    }

    if ( ! empty( $study_url ) ) {
        $args['redirect_to'] = $study_url;
    }

    wp_redirect( add_query_arg( $args, $login_url ) );
    exit;
}

    /**
     * Find the login page URL for a study.
     *
     * @param int $survey_id Survey/Study ID.
     * @return string Login page URL.
     */
    private function find_study_login_page( $survey_id = 0 ) {
        // Method 1: page tagged with study eipsi_study_id or legacy _eipsi_survey_id meta.
        if ( $survey_id > 0 ) {
            $pages = get_posts( array(
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'meta_query'     => array(
                    'relation' => 'OR',
                    array(
                        'key'   => 'eipsi_study_id',
                        'value' => $survey_id,
                    ),
                    array(
                        'key'   => '_eipsi_survey_id',
                        'value' => $survey_id,
                    ),
                    array(
                        'key'   => '_eipsi_study_id',
                        'value' => $survey_id,
                    ),
                ),
            ) );

            if ( ! empty( $pages ) ) {
                return get_permalink( $pages[0]->ID );
            }
        }

        // Method 2: any page containing the login shortcode.
        $pages = get_posts( array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            's'              => 'eipsi_survey_login',
        ) );

        if ( ! empty( $pages ) ) {
            return get_permalink( $pages[0]->ID );
        }

        // Method 3: page flagged as having the dashboard shortcode.
        $pages = get_posts( array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => array(
                array(
                    'key'   => '_eipsi_has_dashboard',
                    'value' => '1',
                ),
            ),
        ) );

        if ( ! empty( $pages ) ) {
            return get_permalink( $pages[0]->ID );
        }

        // Method 4: page with slug 'login'.
        $login_page = get_page_by_path( 'login' );
        if ( $login_page ) {
            return get_permalink( $login_page->ID );
        }

        return home_url( '/' );
    }

    /**
     * Render a confirmation error page.
     *
     * @param string $title   Page title.
     * @param string $message Error message.
     */
    private function render_confirmation_error( $title, $message ) {
        status_header( 403 );
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html( $title ); ?> - EIPSI Forms</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; color: #333; background: #f5f5f5; margin: 0; padding: 20px; }
                .container { max-width: 500px; margin: 40px auto; background: #fff; border-radius: 8px; padding: 40px 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; }
                .error-icon { font-size: 60px; color: #dc3545; margin-bottom: 20px; }
                h1 { color: #dc3545; margin: 0 0 20px; font-size: 24px; }
                p { margin: 0 0 15px; color: #555; }
                .footer { margin-top: 30px; font-size: 13px; color: #888; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="error-icon">⚠</div>
                <h1><?php echo esc_html( $title ); ?></h1>
                <p><?php echo esc_html( $message ); ?></p>
                <div class="footer">
                    <p>© <?php echo date( 'Y' ); ?> EIPSI Forms</p>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    // =========================================================================
    // MAGIC LINK ACCESS
    // =========================================================================

    /**
     * Add rewrite rules.
     */
    public function add_rewrite_rules() {
        add_rewrite_rule( '^survey-access/?$', 'index.php?eipsi_route=survey_access', 'top' );
        add_rewrite_tag( '%eipsi_route%', '([^&]+)' );
    }

    /**
     * Handle magic link access request.
     */
    public function handle_request() {
        // Mailed shortcode links must establish the HttpOnly cookie before template output.
        // Retain the existing token format and 7-day shortcode TTL.
        $shortcode_token = isset($_GET['eipsi_magic']) && is_string($_GET['eipsi_magic']) ? sanitize_text_field($_GET['eipsi_magic']) : '';
        $post = get_post();
        if ($shortcode_token !== '' && is_singular() && $post && has_shortcode($post->post_content, 'eipsi_longitudinal_study')) {
            $validation = EIPSI_MagicLinksService::validate_magic_link($shortcode_token);
            if ($validation['valid'] && EIPSI_MagicLinksService::mark_magic_link_used($validation['ml_id'])) {
                nocache_headers();
                $session = EIPSI_Auth_Service::create_session($validation['participant_id'], $validation['survey_id']);
                if (empty($session['success'])) {
                    $this->render_error('Acceso no autorizado', 'Solicitá un nuevo enlace para volver a intentar.');
                    return;
                }
                wp_safe_redirect(remove_query_arg('eipsi_magic'));
                exit;
            }
            return; // Invalid/used links may render the public login, never create a session.
        }

        // Method 1: Rewrite rule.
        if ( get_query_var( 'eipsi_route' ) === 'survey_access' ) {
            $is_endpoint = true;
        } else {
            // Method 2: Direct path check (fallback if flush_rewrite_rules hasn't run).
            $path        = wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
            $is_endpoint = ( rtrim( $path, '/' ) === '/survey-access' );
        }

        if ( ! $is_endpoint ) {
            return;
        }

        nocache_headers();

        $token = isset( $_GET['ml'] ) ? sanitize_text_field( $_GET['ml'] ) : '';

        if ( empty( $token ) ) {
            $this->render_error( 'Token no válido', 'El enlace no contiene un token de acceso válido.' );
            return;
        }

        // Load MagicLinksService if needed.
        if ( ! class_exists( 'EIPSI_MagicLinksService' ) ) {
            $path = EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-magic-links-service.php';
            if ( file_exists( $path ) ) {
                require_once $path;
            } else {
                $this->render_error( 'Error del Sistema', 'El servicio de autenticación no está disponible.' );
                return;
            }
        }

        $result = EIPSI_MagicLinksService::validate_magic_link( $token );

        if ( ! $result['valid'] ) {
            $this->handle_validation_error( $result );
            return;
        }

        // Find the next pending wave.
        $wave_info = $this->get_target_wave( $result['survey_id'], $result['participant_id'] );

        if ( ! $wave_info ) {
            $this->render_error( 'Sin encuestas pendientes', '¡Hola! No tenés encuestas pendientes en este momento. Gracias por tu participación.', false );
            return;
        }

        if (!EIPSI_MagicLinksService::mark_magic_link_used($result['ml_id'])) {
            $this->render_error('Acceso no autorizado', 'El enlace ya fue utilizado o no se pudo validar.');
            return;
        }

        // FIX (v2.1.0): create session via EIPSI_Auth_Service, not raw $_SESSION.
        $session_result = $this->setup_session( $result, $wave_info );
        if ( empty( $session_result['success'] ) ) {
            $this->render_error( 'Acceso no autorizado', 'No se pudo crear una sesión válida para este estudio.' );
            return;
        }


        // Redirect to participant portal.
        $participant_portal_url = apply_filters(
            'eipsi_participant_portal_url',
            home_url( '/estudio/' ),
            $result['survey_id'],
            $result['participant_id']
        );

        $redirect_url = add_query_arg(
            array(
                'form_id' => $wave_info->form_id,
                'wave_id' => $wave_info->wave_id,
            ),
            $participant_portal_url
        );

        wp_redirect( $redirect_url );
        exit;
    }

    /**
     * Find the next pending wave for a participant.
     *
     * FIX (v1.5.6): Uses a.study_id which is the correct column name
     * in wp_survey_assignments table (NOT survey_id).
     *
     * @param int $study_id    Study ID.
     * @param int $participant_id Participant ID.
     * @return object|null Row with wave_id and form_id, or null if none pending.
     */
    private function get_target_wave( $study_id, $participant_id ) {
        global $wpdb;

        $sql = "SELECT a.wave_id, w.form_id
                FROM {$wpdb->prefix}survey_assignments a
                JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
                WHERE a.participant_id = %d
                  AND a.study_id = %d
                  AND a.status IN ('pending', 'in_progress')
                ORDER BY w.wave_index ASC
                LIMIT 1";

        return $wpdb->get_row( $wpdb->prepare( $sql, $participant_id, $study_id ) );
    }

    /**
     * Set up an authenticated session after magic link validation.
     *
     * FIX (v2.1.0): Uses EIPSI_Auth_Service::create_session() instead of raw
     * PHP $_SESSION, keeping the session system consistent across the plugin.
     * If the service is unavailable, falls back to $_SESSION as a safety net.
     *
     * @param array  $auth_result Magic link validation result.
     * @param object $wave_info   Target wave row.
     */
    private function setup_session( $auth_result, $wave_info ) {
        if ( class_exists( 'EIPSI_Auth_Service' ) ) {
            return EIPSI_Auth_Service::create_session(
                $auth_result['participant_id'],
                $auth_result['survey_id'],
                1 // 1 hour TTL for magic link sessions
            );
        } else {
            // Fallback: raw session (last resort only).
            if ( ! session_id() ) {
                session_start();
            }
            $_SESSION['eipsi_participant_id']      = $auth_result['participant_id'];
            $_SESSION['eipsi_survey_id']            = $auth_result['survey_id'];
            $_SESSION['eipsi_wave_id']              = $wave_info->wave_id;
            $_SESSION['eipsi_authenticated']        = true;
            $_SESSION['eipsi_magic_link_session']   = true;
            $_SESSION['eipsi_auth_ttl']             = time() + 3600;
        }
    }

    /**
     * Handle magic link validation failures.
     *
     * @param array $result Validation result with 'reason' key.
     */
    private function handle_validation_error( $result ) {
        $title        = 'Enlace no válido';
        $message      = 'El enlace que usaste no es válido.';
        $show_button  = true;

        switch ( $result['reason'] ) {
            case 'expired':
                $title   = 'Enlace expirado';
                $message = 'Este enlace de seguridad ha expirado (válido por 48 horas). Por favor, solicitá uno nuevo.';
                break;
            case 'already_used':
                $title   = 'Enlace ya utilizado';
                $message = 'Este enlace ya fue utilizado. Por seguridad, los enlaces de acceso solo funcionan una vez.';
                break;
            case 'not_found':
            case 'empty_token':
            default:
                $message = 'El enlace no es válido o está incompleto. Asegurate de copiar la dirección completa.';
                break;
        }

        $this->render_error( $title, $message, $show_button );
    }

    /**
     * Render a generic error page.
     *
     * @param string $title       Page title.
     * @param string $message     Error message.
     * @param bool   $show_button Whether to show the contact button.
     */
    private function render_error( $title, $message, $show_button = false ) {
        status_header( 403 );
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html( $title ); ?> - EIPSI Forms</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; background-color: #f0f2f5; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; color: #1d2327; }
                .eipsi-error-card { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); max-width: 450px; width: 90%; text-align: center; }
                h1 { margin-top: 0; color: #d63638; font-size: 24px; }
                p { line-height: 1.5; color: #646970; margin-bottom: 25px; }
                .eipsi-btn { display: inline-block; background: #2271b1; color: white; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: 500; transition: background 0.2s; }
                .eipsi-btn:hover { background: #135e96; }
                .eipsi-logo { margin-bottom: 20px; font-weight: bold; color: #2271b1; font-size: 1.2em; }
            </style>
        </head>
        <body>
            <div class="eipsi-error-card">
                <div class="eipsi-logo">EIPSI Forms</div>
                <h1><?php echo esc_html( $title ); ?></h1>
                <p><?php echo esc_html( $message ); ?></p>
                <?php if ( $show_button ) : ?>
                    <a href="mailto:?subject=Solicitud de nuevo enlace de acceso&body=Hola, intenté acceder a la encuesta pero mi enlace no funcionó. Por favor envíenme uno nuevo." class="eipsi-btn">
                        Pedir nuevo enlace
                    </a>
                <?php endif; ?>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}
