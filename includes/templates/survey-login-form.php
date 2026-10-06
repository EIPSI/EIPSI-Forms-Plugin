<?php
/**
 * Template: Survey Login/Registration Form (Enhanced v1.6.0)
 * 
 * Mejoras:
 * - Progress indicators durante operaciones
 * - Magic link como alternativa de acceso
 * - Mejor UX con instrucciones claras
 * - Estados de carga visuales
 * - Validación en tiempo real mejorada
 * 
 * @package EIPSI_Forms
 * @since 1.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$survey_id = isset($atts['survey_id']) ? absint($atts['survey_id']) : 0;
$redirect_url = isset($atts['redirect_url']) ? esc_url($atts['redirect_url']) : '';
$study_code = isset($atts['study_code']) ? sanitize_text_field($atts['study_code']) : '';

// Check for confirmation message from email verification
$confirmation_message = '';
if (isset($_GET['eipsi_msg']) && $_GET['eipsi_msg'] === 'email_confirmed') {
    $confirmed_email = isset($_GET['eipsi_email']) ? sanitize_email(urldecode($_GET['eipsi_email'])) : '';
    $confirmation_message = sprintf(
        __('¡Email confirmado! Ya podés ingresar al estudio con tu email.', 'eipsi-forms'),
        !empty($confirmed_email) ? esc_html($confirmed_email) : __('tu email', 'eipsi-forms')
    );
}

// Obtener información del estudio si existe
$study_name = '';
if ($survey_id) {
    global $wpdb;
    $study = $wpdb->get_row($wpdb->prepare(
        "SELECT study_name FROM {$wpdb->prefix}survey_studies WHERE id = %d",
        $survey_id
    ));
    if ($study) {
        $study_name = $study->study_name;
    }
}
?>

<div class="eipsi-survey-login-container" id="eipsi-survey-login-<?php echo esc_attr($survey_id); ?>"
     data-survey-id="<?php echo esc_attr($survey_id); ?>"
     data-redirect="<?php echo esc_attr($redirect_url); ?>">

    <!-- Email Confirmation Message -->
    <?php if (!empty($confirmation_message)): ?>
    <div class="eipsi-confirmation-message">
        <span class="confirmation-icon">✓</span>
        <p><?php echo wp_kses_post($confirmation_message); ?></p>
    </div>
    <?php endif; ?>

    <!-- Header con información del estudio -->
    <div class="eipsi-login-header">
        <div class="eipsi-login-logo">🔬</div>
        <h2 class="eipsi-login-title">
            <?php echo $study_name ? esc_html($study_name) : esc_html__('Acceso al Estudio', 'eipsi-forms'); ?>
        </h2>
        <p class="eipsi-login-subtitle">
            <?php esc_html_e('Ingresá tus datos para participar', 'eipsi-forms'); ?>
        </p>
    </div>
    
    <!-- Progress Steps -->
    <div class="eipsi-login-steps">
        <div class="eipsi-step active" data-step="1">
            <span class="eipsi-step-number">1</span>
            <span class="eipsi-step-label"><?php esc_html_e('Acceso', 'eipsi-forms'); ?></span>
        </div>
        <div class="eipsi-step-connector"></div>
        <div class="eipsi-step" data-step="2">
            <span class="eipsi-step-number">2</span>
            <span class="eipsi-step-label"><?php esc_html_e('Formulario', 'eipsi-forms'); ?></span>
        </div>
        <div class="eipsi-step-connector"></div>
        <div class="eipsi-step" data-step="3">
            <span class="eipsi-step-number">3</span>
            <span class="eipsi-step-label"><?php esc_html_e('Confirmación', 'eipsi-forms'); ?></span>
        </div>
    </div>
    
    <!-- Tabs -->
    <div class="eipsi-survey-login-tabs">
        <button type="button" class="eipsi-survey-login-tab active" data-tab="login">
            <span class="tab-icon">🔑</span>
            <?php esc_html_e('Ingresar', 'eipsi-forms'); ?>
        </button>
        <button type="button" class="eipsi-survey-login-tab" data-tab="register">
            <span class="tab-icon">✨</span>
            <?php esc_html_e('Crear cuenta', 'eipsi-forms'); ?>
        </button>
    </div>

    <div class="eipsi-survey-login-content">
        <!-- Login Tab -->
        <div class="eipsi-survey-login-pane active" id="eipsi-login-pane">
            <div class="eipsi-pane-header">
                <p class="eipsi-pane-description">
                    <?php esc_html_e('Ingresá tu email para recibir un enlace seguro de acceso.', 'eipsi-forms'); ?>
                </p>
            </div>

            <form id="eipsi-participant-login-form" class="eipsi-participant-login-form eipsi-survey-login-form">
                <?php // Nonce is injected by JS from window.eipsiAuth.nonce (survey-login-enhanced.js) ?>
                <input type="hidden" name="survey_id" value="<?php echo esc_attr($survey_id); ?>">
                <input type="hidden" name="redirect_url" value="<?php echo esc_attr($redirect_url); ?>">
                <?php if (!empty($study_code)): ?>
                <input type="hidden" name="study_code" value="<?php echo esc_attr($study_code); ?>">
                <?php endif; ?>

                <div class="eipsi-form-group">
                    <label for="login-email">
                        <?php esc_html_e('Email', 'eipsi-forms'); ?>
                        <span class="required">*</span>
                    </label>
                    <div class="eipsi-input-wrapper">
                        <span class="input-icon">📧</span>
                        <input type="email"
                               id="login-email"
                               name="email"
                               required
                               placeholder="ejemplo@email.com"
                               aria-label="<?php esc_attr_e('Email', 'eipsi-forms'); ?>"
                               autocomplete="email"
                               value="<?php echo !empty($confirmed_email) ? esc_attr($confirmed_email) : ''; ?>">
                        <span class="eipsi-valid-icon">✓</span>
                    </div>
                </div>

                <button type="submit" class="eipsi-button-primary">
                    <span class="button-text"><?php esc_html_e('Recibir enlace de acceso', 'eipsi-forms'); ?></span>
                    <span class="eipsi-spinner" style="display: none;"></span>
                </button>

                <div class="eipsi-form-footer">
                    <p><?php esc_html_e('¿No tenés cuenta?', 'eipsi-forms'); ?>
                        <a href="#" class="switch-to-register"><?php esc_html_e('Creá una nueva', 'eipsi-forms'); ?></a>
                    </p>
                </div>
            </form>
        </div>

        <!-- Register Tab -->
        <div class="eipsi-survey-login-pane" id="eipsi-register-pane">
            <div class="eipsi-pane-header">
                <p class="eipsi-pane-description">
                    <?php esc_html_e('Completá tus datos para participar en el estudio.', 'eipsi-forms'); ?>
                </p>
            </div>

            <form id="eipsi-participant-register-form" class="eipsi-participant-register-form eipsi-survey-login-form">
                <?php // Nonce is injected by JS from window.eipsiAuth.nonce (survey-login-enhanced.js) ?>
                <input type="hidden" name="survey_id" value="<?php echo esc_attr($survey_id); ?>">
                <input type="hidden" name="redirect_url" value="<?php echo esc_attr($redirect_url); ?>">

                <?php if (empty($survey_id) && empty($study_code)): ?>
                <div class="eipsi-form-group">
                    <label for="register-study-code">
                        <?php esc_html_e('Código del Estudio', 'eipsi-forms'); ?>
                        <span class="required">*</span>
                    </label>
                    <div class="eipsi-input-wrapper">
                        <span class="input-icon">🔬</span>
                        <input type="text"
                               id="register-study-code"
                               name="study_code"
                               required
                               placeholder="Ejemplo: ESTUDIO_2025"
                               aria-label="<?php esc_attr_e('Código del Estudio', 'eipsi-forms'); ?>">
                    </div>
                    <small class="field-hint"><?php esc_html_e('Ingresá el código que te proporcionó el investigador.', 'eipsi-forms'); ?></small>
                </div>
                <?php elseif (!empty($study_code)): ?>
                <input type="hidden" name="study_code" value="<?php echo esc_attr($study_code); ?>">
                <?php endif; ?>

                <div class="eipsi-form-group">
                    <label for="register-email">
                        <?php esc_html_e('Email', 'eipsi-forms'); ?>
                        <span class="required">*</span>
                    </label>
                    <div class="eipsi-input-wrapper">
                        <span class="input-icon">📧</span>
                        <input type="email"
                               id="register-email"
                               name="email"
                               required
                               placeholder="ejemplo@email.com"
                               aria-label="<?php esc_attr_e('Email', 'eipsi-forms'); ?>"
                               autocomplete="email"
                               value="<?php echo !empty($confirmed_email) ? esc_attr($confirmed_email) : ''; ?>">
                        <span class="eipsi-valid-icon">✓</span>
                    </div>
                    <small class="field-hint"><?php esc_html_e('Usaremos este email para enviarte los recordatorios.', 'eipsi-forms'); ?></small>
                </div>

                <div class="eipsi-form-options">
                    <label class="eipsi-checkbox-label">
                        <input type="checkbox" name="accept_terms" value="1" required>
                        <span><?php
                            printf(
                                __('Acepto los %1$stérminos y condiciones%2$s y la %3$spolítica de privacidad%4$s', 'eipsi-forms'),
                                '<a href="#" target="_blank">',
                                '</a>',
                                '<a href="#" target="_blank">',
                                '</a>'
                            );
                        ?></span>
                    </label>
                </div>

                <button type="submit" class="eipsi-button-primary">
                    <span class="button-text"><?php esc_html_e('Crear cuenta y participar', 'eipsi-forms'); ?></span>
                    <span class="eipsi-spinner" style="display: none;"></span>
                </button>

                <div class="eipsi-form-footer">
                    <p><?php esc_html_e('¿Ya tenés cuenta?', 'eipsi-forms'); ?>
                        <a href="#" class="switch-to-login"><?php esc_html_e('Ingresá aquí', 'eipsi-forms'); ?></a>
                    </p>
                </div>
            </form>
        </div>
    </div>

    <!-- Security Notice -->
    <div class="eipsi-security-notice">
        <span class="security-icon">🔒</span>
        <span><?php esc_html_e('Tus datos están protegidos y encriptados.', 'eipsi-forms'); ?></span>
    </div>

    <!-- Principal Investigator Info -->
    <?php
    $principal_investigator_email = '';
    if ($survey_id) {
        global $wpdb;
        $study = $wpdb->get_row($wpdb->prepare(
            "SELECT principal_investigator_id FROM {$wpdb->prefix}survey_studies WHERE id = %d",
            $survey_id
        ));
        if ($study && $study->principal_investigator_id) {
            $investigator = get_userdata($study->principal_investigator_id);
            if ($investigator) {
                $principal_investigator_email = $investigator->user_email;
            }
        }
    }
    ?>
    <?php if ($principal_investigator_email): ?>
    <div class="eipsi-investigator-notice">
        <span class="investigator-icon">🔬</span>
        <span>
            <?php printf(
                __('Investigador Principal: %s', 'eipsi-forms'),
                esc_html($principal_investigator_email)
            ); ?>
        </span>
    </div>
    <?php endif; ?>
</div>
