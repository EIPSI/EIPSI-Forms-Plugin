<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * EIPSI Forms Configuration Page
 * UI for managing external database credentials
 */

function eipsi_display_configuration_page() {
    if (!current_user_can('manage_options')) {
        wp_die(eipsi_admin_translate('You do not have sufficient permissions to access this page.'));
    }

    $language_notice = $GLOBALS['eipsi_admin_language_notice'] ?? '';
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';
    $db_helper = new EIPSI_External_Database();

    $smtp_service = class_exists('EIPSI_SMTP_Service') ? new EIPSI_SMTP_Service() : null;
    $smtp_config = $smtp_service ? $smtp_service->get_config() : null;
    $smtp_host = $smtp_config ? $smtp_config['host'] : '';
    $smtp_port = $smtp_config ? $smtp_config['port'] : 587;
    $smtp_user = $smtp_config ? $smtp_config['user'] : '';
    $smtp_encryption = $smtp_config ? $smtp_config['encryption'] : 'tls';
    $smtp_encryption_labels = array(
        'tls' => eipsi_admin_translate('TLS (recommended)'),
        'ssl' => 'SSL',
        'none' => eipsi_admin_translate('No encryption')
    );

    require_once EIPSI_FORMS_PLUGIN_DIR.'includes/study-engine/runtime/PublicMessages.php';
    $public_message_class='\\EIPSI\\StudyEngine\\Runtime\\PublicMessages';
    $public_message_notice='';
    if(isset($_POST['eipsi_save_public_fallback'])){
        check_admin_referer('eipsi_public_fallback');
        $submitted=wp_unslash($_POST['eipsi_public_fallback']??array());
        try{if(!is_array($submitted)||array_diff(array_keys($submitted),array_column($public_message_class::fallbackFields(),'key')))throw new \DomainException('INVALID_PUBLIC_MESSAGES');$submitted=$public_message_class::validate($submitted);update_option('eipsi_study_public_fallback_messages',$submitted,false);$public_message_notice=eipsi_admin_translate('Fallback messages saved.');}
        catch(\Throwable $error){$public_message_notice=eipsi_admin_translate('Review the text before saving.');}
    }

    // Get current credentials for display (without password)
    $credentials = $db_helper->get_credentials();
    $current_host = $credentials ? $credentials['host'] : '';
    $current_user = $credentials ? $credentials['user'] : '';
    $current_db_name = $credentials ? $credentials['name'] : '';

    // Get current status
    $status = $db_helper->get_status();

    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'database';
    $allowed_tabs = array('database', 'smtp', 'privacy-security', 'schema-status', 'external-api', 'scheduler', 'fallback-messages');
    if (!in_array($active_tab, $allowed_tabs, true)) {
        $active_tab = 'database';
    }

    // Global notifications settings are now managed in Longitudinal Study > Recordatorios

    ?>
    <div class="wrap eipsi-config-wrap" lang="<?php echo esc_attr(eipsi_admin_language()); ?>">
        <h1><?php echo esc_html(eipsi_admin_translate('EIPSI Forms - Configuration')); ?></h1>

        <?php
        // Check SMTP status for tab warning indicator
        $smtp_service_for_tab = class_exists('EIPSI_SMTP_Service') ? new EIPSI_SMTP_Service() : null;
        $smtp_configured_for_tab = $smtp_service_for_tab ? $smtp_service_for_tab->is_configured() : false;
        ?>
        <?php eipsi_admin_language_form($language_notice); ?>
        <h2 class="nav-tab-wrapper">
            <a href="?page=eipsi-configuration&tab=database"
               class="nav-tab <?php echo esc_attr(($active_tab === 'database') ? 'nav-tab-active' : ''); ?>">
                🗄️ <?php echo esc_html(eipsi_admin_translate('Database & Storage')); ?>
            </a>
            <a href="?page=eipsi-configuration&tab=smtp"
               class="nav-tab <?php echo esc_attr(($active_tab === 'smtp') ? 'nav-tab-active' : ''); ?>">
                📧 <?php echo 'SMTP'; ?>
                <?php if (!$smtp_configured_for_tab): ?>
                <span class="dashicons dashicons-warning" style="color: #f0b849; font-size: 14px; margin-left: 5px; vertical-align: middle;" title="<?php echo esc_attr(eipsi_admin_translate('SMTP not configured')); ?>"></span>
                <?php endif; ?>
            </a>
            <a href="?page=eipsi-configuration&tab=privacy-security"
               class="nav-tab <?php echo esc_attr(($active_tab === 'privacy-security') ? 'nav-tab-active' : ''); ?>">
                🔒 <?php echo esc_html(eipsi_admin_translate('Privacy & Security')); ?>
            </a>
            <a href="?page=eipsi-configuration&tab=schema-status"
               class="nav-tab <?php echo esc_attr(($active_tab === 'schema-status') ? 'nav-tab-active' : ''); ?>">
                💾 <?php echo esc_html(eipsi_admin_translate('Schema Status')); ?>
            </a>
            <a href="?page=eipsi-configuration&amp;tab=external-api" class="nav-tab <?php echo esc_attr($active_tab === 'external-api' ? 'nav-tab-active' : ''); ?>">🔌 <?php echo esc_html(eipsi_admin_translate('External API')); ?></a>
            <a href="?page=eipsi-configuration&amp;tab=scheduler" class="nav-tab <?php echo esc_attr($active_tab === 'scheduler' ? 'nav-tab-active' : ''); ?>">🕒 <?php echo esc_html(eipsi_admin_translate('Scheduler')); ?></a>
            <a href="?page=eipsi-configuration&amp;tab=fallback-messages" class="nav-tab <?php echo esc_attr($active_tab === 'fallback-messages' ? 'nav-tab-active' : ''); ?>">💬 <?php echo esc_html(eipsi_admin_translate('Fallback messages')); ?></a>
        </h2>

        <?php if ($active_tab === 'fallback-messages'): ?>
        <section class="eipsi-backup-messages">
            <h2><?php echo esc_html(eipsi_admin_translate('Fallback messages')); ?></h2>
            <p><?php echo esc_html(eipsi_admin_translate('Fallback texts used when the study definition cannot be loaded. These fields also exist in Study Engine; saved study messages take precedence whenever its definition is available.')); ?></p>
            <?php if($public_message_notice!=='')echo '<p role="status">'.esc_html($public_message_notice).'</p>'; ?>
            <form method="post" class="eipsi-fallback-form">
                <?php wp_nonce_field('eipsi_public_fallback');$public_fallback=$public_message_class::all();foreach($public_message_class::fallbackFields() as $field){echo '<p><label><span>'.esc_html(eipsi_admin_field($field['key'])).'</span><input class="large-text" name="eipsi_public_fallback['.esc_attr($field['key']).']" maxlength="4000" value="'.esc_attr($public_fallback[$field['key']]).'"></label></p>';} ?>
                <button class="button button-primary" name="eipsi_save_public_fallback" value="1"><?php echo esc_html(eipsi_admin_translate('Save fallback messages')); ?></button>
            </form>
        </section>
        <?php endif; ?>

        <?php if ($active_tab === 'database'): ?>
            <p class="description">
                <?php echo esc_html(eipsi_admin_translate('Configure an external MySQL database to store form submission data. If no external database is configured, data will be stored in the default WordPress database.')); ?>
            </p>

            <!-- Prominent Database Indicator -->
        <div class="eipsi-db-indicator-banner">
            <div class="eipsi-db-indicator-content">
                <div class="eipsi-db-indicator-icon">
                    <span class="dashicons dashicons-database"></span>
                </div>
                <div class="eipsi-db-indicator-info">
                    <div class="eipsi-db-indicator-label"><?php echo esc_html(eipsi_admin_translate('Current Storage Location:')); ?></div>
                    <div class="eipsi-db-indicator-value">
                        <?php if ($status['connected']): ?>
                            <span class="eipsi-db-badge eipsi-db-badge--external">
                                <span class="dashicons dashicons-admin-site-alt3"></span>
                                <?php echo esc_html(eipsi_admin_translate('External Database')); ?>
                            </span>
                            <span class="eipsi-db-name"><?php echo esc_html($status['db_name']); ?></span>
                        <?php else: ?>
                            <span class="eipsi-db-badge eipsi-db-badge--wordpress">
                                <span class="dashicons dashicons-wordpress"></span>
                                <?php echo esc_html(eipsi_admin_translate('WordPress Database')); ?>
                            </span>
                            <span class="eipsi-db-name"><?php echo esc_html(DB_NAME); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($status['connected']): ?>
                <div class="eipsi-db-indicator-status">
                    <span class="eipsi-status-dot eipsi-status-dot--connected"></span>
                    <span class="eipsi-status-text"><?php echo esc_html(eipsi_admin_translate('Connected')); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="eipsi-config-container">
            <!-- Configuration Form -->
            <div class="eipsi-config-form-section">
                <h2><?php echo esc_html(eipsi_admin_translate('Database Connection Settings')); ?></h2>

                <form id="eipsi-db-config-form" class="eipsi-db-form">
                    <input type="hidden" id="eipsi_db_config_nonce" value="<?php echo esc_attr(wp_create_nonce('eipsi_admin_nonce')); ?>"><?php // Use eipsi_admin_nonce for consistency ?>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="db_host">
                                        <?php echo esc_html(eipsi_admin_translate('Host')); ?>
                                        <span class="required">*</span>
                                    </label>
                                </th>
                                <td>
                                    <input type="text"
                                        id="db_host"
                                        name="db_host"
                                        class="regular-text"
                                        value="<?php echo esc_attr($current_host); ?>"
                                        placeholder="localhost"
                                        aria-describedby="db_host_desc"
                                        required>
                                    <p class="description" id="db_host_desc">
                                        <?php echo esc_html(eipsi_admin_translate('Database server hostname or IP address (e.g., localhost, 192.168.1.100)')); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="db_user">
                                        <?php echo esc_html(eipsi_admin_translate('Username')); ?>
                                        <span class="required">*</span>
                                    </label>
                                </th>
                                <td>
                                    <input type="text"
                                        id="db_user"
                                        name="db_user"
                                        class="regular-text"
                                        value="<?php echo esc_attr($current_user); ?>"
                                        placeholder="<?php echo esc_attr(eipsi_admin_translate('MySQL username')); ?>"
                                        aria-describedby="db_user_desc"
                                        required>
                                    <p class="description" id="db_user_desc">
                                        <?php echo esc_html(eipsi_admin_translate('MySQL user with access to the database')); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="db_password">
                                        <?php echo esc_html(eipsi_admin_translate('Password')); ?>
                                        <span class="required">*</span>
                                    </label>
                                </th>
                                <td>
                                    <input type="password"
                                        id="db_password"
                                        name="db_password"
                                        class="regular-text"
                                        placeholder="<?php echo esc_attr(eipsi_admin_translate('MySQL password')); ?>"
                                        aria-describedby="db_password_desc"
                                        <?php echo $credentials ? '' : 'required'; ?>>
                                    <p class="description" id="db_password_desc">
                                        <?php
                                        if ($credentials) {
                                            echo esc_html(eipsi_admin_translate('Leave blank to keep existing password'));
                                        } else {
                                            echo esc_html(eipsi_admin_translate('MySQL user password (will be encrypted)'));
                                        }
                                        ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="db_name">
                                        <?php echo esc_html(eipsi_admin_translate('Database Name')); ?>
                                        <span class="required">*</span>
                                    </label>
                                </th>
                                <td>
                                    <input type="text"
                                        id="db_name"
                                        name="db_name"
                                        class="regular-text"
                                        value="<?php echo esc_attr($current_db_name); ?>"
                                        placeholder="research_db_custom"
                                        aria-describedby="db_name_desc"
                                        required>
                                    <p class="description" id="db_name_desc">
                                        <?php echo esc_html(eipsi_admin_translate('Name of the database to store form submissions')); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="eipsi-form-actions">
                        <button type="button" id="eipsi-test-connection" class="button button-secondary">
                            <span class="dashicons dashicons-database"></span>
                            <?php echo esc_html(eipsi_admin_translate('Test Connection')); ?>
                        </button>

                        <button type="submit" id="eipsi-save-config" class="button button-primary" disabled>
                            <span class="dashicons dashicons-yes"></span>
                            <?php echo esc_html(eipsi_admin_translate('Save Configuration')); ?>
                        </button>

                        <?php if ($credentials): ?>
                        <button type="button" id="eipsi-disable-external-db" class="button button-link-delete">
                            <?php echo esc_html(eipsi_admin_translate('Disable External Database')); ?>
                        </button>
                        <?php endif; ?>
                    </div>

                    <div id="eipsi-message-container" role="alert" aria-live="polite" style="display: none;"></div>
                </form>
            </div>

            <!-- Status Indicator -->
            <div class="eipsi-status-section">
                <h2><?php echo esc_html(eipsi_admin_translate('Connection Status')); ?></h2>

                <div id="eipsi-status-box" class="eipsi-status-box">
                    <div class="eipsi-status-indicator">
                        <?php if ($status['connected']): ?>
                            <span class="status-icon status-connected"></span>
                            <span class="status-text"><?php echo esc_html(eipsi_admin_translate('Connected')); ?></span>
                        <?php else: ?>
                            <span class="status-icon status-disconnected"></span>
                            <span class="status-text"><?php echo esc_html(eipsi_admin_translate('Disconnected')); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($status['connected']): ?>
                    <div class="eipsi-status-details">
                        <div class="status-detail-row">
                            <span class="detail-label"><?php echo esc_html(eipsi_admin_translate('Current Database:')); ?></span>
                            <span class="detail-value"><?php echo esc_html($status['db_name']); ?></span>
                        </div>
                        <div class="status-detail-row">
                            <span class="detail-label"><?php echo esc_html(eipsi_admin_translate('Records:')); ?></span>
                            <span class="detail-value"><?php echo number_format_i18n($status['record_count']); ?></span>
                        </div>
                        <?php if (!empty($status['last_updated'])): ?>
                        <div class="status-detail-row">
                            <span class="detail-label"><?php echo esc_html(eipsi_admin_translate('Last Updated:')); ?></span>
                            <span class="detail-value"><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $status['last_updated'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <?php
                    // Get local table status for WordPress database
                    $local_table_status = $db_helper->check_table_status();
                    ?>
                    <div class="eipsi-status-details">
                        <div class="status-detail-row">
                            <span class="detail-label"><?php echo esc_html(eipsi_admin_translate('Database:')); ?></span>
                            <span class="detail-value"><?php echo esc_html($local_table_status['db_name']); ?> @ <?php echo esc_html($local_table_status['db_host']); ?></span>
                        </div>
                        <div class="status-detail-row">
                            <span class="detail-label"><?php echo esc_html(eipsi_admin_translate('Total records:')); ?></span>
                            <span class="detail-value"><?php echo number_format_i18n($local_table_status['total_records']); ?></span>
                        </div>
                        <?php if (!empty($local_table_status['last_verified'])): ?>
                        <div class="status-detail-row">
                            <span class="detail-label"><?php echo esc_html(eipsi_admin_translate('Last verification:')); ?></span>
                            <span class="detail-value"><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $local_table_status['last_verified'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Local Data Summary with Schema Status Link -->
                    <div class="eipsi-db-summary" style="margin-top: 20px; padding: 16px; background: #f8f9fa; border-radius: 8px; border-left: 3px solid #2271b1;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <h4 style="margin: 0; font-size: 14px; color: #2c3e50;">
                                <span class="dashicons dashicons-chart-pie" style="font-size: 16px; vertical-align: middle;"></span>
                                <?php echo esc_html(eipsi_admin_translate('EIPSI data summary')); ?>
                            </h4>
                            <a href="?page=eipsi-configuration&tab=schema-status" class="button button-small" style="display: inline-flex; align-items: center; gap: 4px;">
                                <span class="dashicons dashicons-external" style="font-size: 14px;"></span>
                                <?php echo esc_html(eipsi_admin_translate('Full analysis')); ?>
                            </a>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;">
                            <div style="text-align: center; padding: 12px; background: #fff; border-radius: 6px;">
                                <div style="font-size: 24px; font-weight: 600; color: #2271b1;">
                                    <?php echo number_format_i18n($local_table_status['longitudinal_tables']['survey_studies']['row_count'] ?? 0); ?>
                                </div>
                                <div style="font-size: 12px; color: #64748b;"><?php echo esc_html(eipsi_admin_translate('Studies')); ?></div>
                            </div>
                            <div style="text-align: center; padding: 12px; background: #fff; border-radius: 6px;">
                                <div style="font-size: 24px; font-weight: 600; color: #2271b1;">
                                    <?php echo number_format_i18n($local_table_status['longitudinal_tables']['survey_participants']['row_count'] ?? 0); ?>
                                </div>
                                <div style="font-size: 12px; color: #64748b;"><?php echo esc_html(eipsi_admin_translate('Participants')); ?></div>
                            </div>
                            <div style="text-align: center; padding: 12px; background: #fff; border-radius: 6px;">
                                <div style="font-size: 24px; font-weight: 600; color: #2271b1;">
                                    <?php echo number_format_i18n($local_table_status['longitudinal_tables']['survey_magic_links']['row_count'] ?? 0); ?>
                                </div>
                                <div style="font-size: 12px; color: #64748b;"><?php echo esc_html(eipsi_admin_translate('Magic Links')); ?></div>
                            </div>
                        </div>

                        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e8f0; display: flex; gap: 16px; font-size: 12px; color: #64748b; flex-wrap: wrap;">
                            <span>
                                <span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 12px;"></span>
                                <?php echo number_format_i18n($local_table_status['longitudinal_tables']['survey_waves']['row_count'] ?? 0); ?> <?php echo esc_html(eipsi_admin_translate('Waves')); ?>
                            </span>
                            <span>
                                <span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 12px;"></span>
                                <?php echo number_format_i18n($local_table_status['longitudinal_tables']['survey_assignments']['row_count'] ?? 0); ?> <?php echo esc_html(eipsi_admin_translate('Assignments')); ?>
                            </span>
                            <span>
                                <span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 12px;"></span>
                                <?php echo number_format_i18n($local_table_status['longitudinal_tables']['survey_email_log']['row_count'] ?? 0); ?> <?php echo esc_html(eipsi_admin_translate('Emails sent')); ?>
                            </span>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($status['last_error'])): ?>
                    <div class="eipsi-error-box" style="margin-top: 15px; padding: 12px; background: #fff3cd; border-left: 4px solid #ff9800; border-radius: 4px;">
                        <h4 style="margin: 0 0 8px 0; color: #856404;">
                            <span class="dashicons dashicons-warning" style="color: #ff9800;"></span>
                            <?php echo esc_html(eipsi_admin_translate('Fallback Mode Active')); ?>
                        </h4>
                        <p style="margin: 0 0 8px 0; color: #856404;">
                            <?php echo esc_html(eipsi_admin_translate('Recent submissions were saved to the WordPress database because the external database was unavailable.')); ?>
                        </p>
                        <div class="status-detail-row" style="font-size: 13px;">
                            <span class="detail-label"><?php echo esc_html(eipsi_admin_translate('Last Error:')); ?></span>
                            <span class="detail-value" style="color: #d32f2f; font-family: monospace;"><?php echo esc_html($status['last_error']); ?></span>
                        </div>
                        <?php if (!empty($status['last_error_code'])): ?>
                        <div class="status-detail-row" style="font-size: 13px;">
                            <span class="detail-label"><?php echo esc_html(eipsi_admin_translate('Error Code:')); ?></span>
                            <span class="detail-value" style="font-family: monospace;"><?php echo esc_html($status['last_error_code']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($status['last_error_time'])): ?>
                        <div class="status-detail-row" style="font-size: 13px;">
                            <span class="detail-label"><?php echo esc_html(eipsi_admin_translate('Occurred:')); ?></span>
                            <span class="detail-value"><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $status['last_error_time'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    </div>

                    <!-- Help Section -->
                    <div class="eipsi-help-box">
                    <h3><?php echo esc_html(eipsi_admin_translate('Recommendations')); ?></h3>
                    <p><?php echo esc_html(eipsi_admin_translate('For external storage, we recommend a separate database used only by EIPSI Forms, with a dedicated database user. This makes access management, backups, and maintenance easier. Selecting an external database does not move WordPress or the internal records used to manage studies.')); ?></p>

                    <h3><?php echo esc_html(eipsi_admin_translate('Setup Instructions')); ?></h3>
                    <ol>
                        <li><?php echo esc_html(eipsi_admin_translate('Enter your MySQL database credentials above')); ?></li>
                        <li><?php echo esc_html(eipsi_admin_translate('Click "Test Connection" to verify the credentials work')); ?></li>
                        <li><?php echo esc_html(eipsi_admin_translate('If the test is successful, click "Save Configuration"')); ?></li>
                        <li><?php echo esc_html(eipsi_admin_translate('All new form submissions will be stored in the external database')); ?></li>
                    </ol>

                    <h3><?php echo esc_html(eipsi_admin_translate('Important Notes')); ?></h3>
                    <ul>
                        <li><?php echo esc_html(eipsi_admin_translate('The plugin will automatically create the required table and columns in the external database if they are missing')); ?></li>
                        <li><?php echo esc_html(eipsi_admin_translate('Passwords are encrypted before storage using WordPress security functions')); ?></li>
                        <li><?php echo esc_html(eipsi_admin_translate('Test the connection before saving to verify credentials and schema')); ?></li>
                        <li><strong><?php echo esc_html(eipsi_admin_translate('Automatic Fallback:')); ?></strong> <?php echo esc_html(eipsi_admin_translate('If the external database becomes unavailable, submissions will automatically be saved to the WordPress database without blocking the user')); ?></li>
                        <li><?php echo esc_html(eipsi_admin_translate('Admin notifications will alert you when fallback mode is active so you can investigate the issue')); ?></li>
                        <li><?php echo esc_html(eipsi_admin_translate('Enable WP_DEBUG to see detailed error logs for troubleshooting database issues')); ?></li>
                    </ul>
                </div>
            </div>
        </div>

        <?php endif; ?>

        <?php if ($active_tab === 'smtp'): ?>
            <!-- SMTP Configuration Section -->
            <div class="eipsi-config-container" style="margin-top: 30px;">

            <?php
            // Check SMTP configuration status for warning banner
            $smtp_service = class_exists('EIPSI_SMTP_Service') ? new EIPSI_SMTP_Service() : null;
            $smtp_status = $smtp_service ? $smtp_service->get_status() : array('configured' => false, 'errors' => array());

            // Show warning if SMTP is not fully configured
            if (!$smtp_status['configured']):
            ?>
            <!-- SMTP Warning Banner -->
            <div class="notice notice-warning" style="margin: 20px 0; padding: 15px; border-left: 4px solid #f0b849; background: #fff8e5;">
                <h3 style="margin: 0 0 10px 0; color: #946c00;">
                    <span class="dashicons dashicons-warning" style="color: #f0b849; margin-right: 8px;"></span>
                    <?php echo esc_html(eipsi_admin_translate('⚠️ SMTP not configured — email confirmation requires SMTP')); ?>
                </h3>
                <p style="margin: 0 0 10px 0; color: #946c00;">
                    <?php echo esc_html(eipsi_admin_translate('Email confirmation requires configured SMTP. Without SMTP, participants will not receive verification emails.')); ?>
                </p>
                <?php if (!empty($smtp_status['errors'])): ?>
                <ul style="margin: 10px 0; padding-left: 20px; color: #946c00;">
                    <?php foreach ($smtp_status['errors'] as $error): ?>
                    <li><?php echo esc_html($error); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <p style="margin: 10px 0 0 0; font-size: 13px; color: #946c00;">
                    <strong><?php echo esc_html(eipsi_admin_translate('Configure SMTP now:')); ?></strong>
                    <?php echo esc_html(eipsi_admin_translate('Complete the form below and click "Test SMTP" before saving.')); ?>
                </p>
            </div>
            <?php else: ?>
            <!-- SMTP Success Banner -->
            <div class="notice notice-success" style="margin: 20px 0; padding: 15px; border-left: 4px solid #46b450; background: #ecf7ed;">
                <h3 style="margin: 0 0 5px 0; color: #155724;">
                    <span class="dashicons dashicons-yes-alt" style="color: #46b450; margin-right: 8px;"></span>
                    <?php echo esc_html(eipsi_admin_translate('✅ SMTP configured successfully')); ?>
                </h3>
                <p style="margin: 0; color: #155724;">
                    <?php echo esc_html(eipsi_admin_translate('The system can send email confirmation messages.')); ?>
                </p>
            </div>
            <?php endif; ?>

            <div class="eipsi-config-form-section">
                <h2><?php echo esc_html(eipsi_admin_translate('SMTP Configuration')); ?></h2>
                <p class="description">
                    <?php echo esc_html(eipsi_admin_translate('Configure your mail server to send clinical reminders from your account without depending on external plugins.')); ?>
                </p>

                <form id="eipsi-smtp-config-form" class="eipsi-db-form">
                    <input type="hidden" id="eipsi_smtp_config_nonce" value="<?php echo esc_attr(wp_create_nonce('eipsi_admin_nonce')); ?>">

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="smtp_host">
                                        <?php echo esc_html(eipsi_admin_translate('SMTP server')); ?>
                                        <span class="required">*</span>
                                    </label>
                                </th>
                                <td>
                                    <input type="text"
                                        id="smtp_host"
                                        name="smtp_host"
                                        class="regular-text"
                                        value="<?php echo esc_attr($smtp_host); ?>"
                                        placeholder="smtp.gmail.com"
                                        required>
                                    <p class="description">
                                        <?php echo esc_html(eipsi_admin_translate('SMTP server domain or IP address.')); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="smtp_port">
                                        <?php echo esc_html(eipsi_admin_translate('SMTP port')); ?>
                                        <span class="required">*</span>
                                    </label>
                                </th>
                                <td>
                                    <input type="number"
                                        id="smtp_port"
                                        name="smtp_port"
                                        class="regular-text"
                                        value="<?php echo esc_attr($smtp_port); ?>"
                                        placeholder="587"
                                        min="1"
                                        max="65535"
                                        required>
                                    <p class="description">
                                        <?php echo esc_html(eipsi_admin_translate('Typical port: 587 (TLS) or 465 (SSL).')); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="smtp_user">
                                        <?php echo esc_html(eipsi_admin_translate('SMTP username')); ?>
                                        <span class="required">*</span>
                                    </label>
                                </th>
                                <td>
                                    <input type="email"
                                        id="smtp_user"
                                        name="smtp_user"
                                        class="regular-text"
                                        value="<?php echo esc_attr($smtp_user); ?>"
                                        placeholder="tu-correo@dominio.com"
                                        required>
                                    <p class="description">
                                        <?php echo esc_html(eipsi_admin_translate('Full email address used to authenticate with the server.')); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="smtp_password">
                                        <?php echo esc_html(eipsi_admin_translate('SMTP password')); ?>
                                        <span class="required">*</span>
                                    </label>
                                </th>
                                <td>
                                    <input type="password"
                                        id="smtp_password"
                                        name="smtp_password"
                                        class="regular-text"
                                        placeholder="<?php echo esc_attr(eipsi_admin_translate('App or SMTP password')); ?>"
                                        <?php echo $smtp_config ? '' : 'required'; ?>>
                                    <p class="description">
                                        <?php
                                        if ($smtp_config) {
                                            echo esc_html(eipsi_admin_translate('Leave empty to keep the current password.'));
                                        } else {
                                            echo esc_html(eipsi_admin_translate('Use an app password if required by your provider.'));
                                        }
                                        ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="smtp_encryption">
                                        <?php echo esc_html(eipsi_admin_translate('Security')); ?>
                                        <span class="required">*</span>
                                    </label>
                                </th>
                                <td>
                                    <select id="smtp_encryption" name="smtp_encryption" class="regular-text">
                                        <?php foreach ($smtp_encryption_labels as $value => $label) : ?>
                                            <option value="<?php echo esc_attr($value); ?>" <?php selected($smtp_encryption, $value); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        <?php echo esc_html(eipsi_admin_translate('Select the encryption type required by your server.')); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="eipsi-form-actions">
                        <button type="button" id="eipsi-test-smtp" class="button button-secondary">
                            <span class="dashicons dashicons-email"></span>
                            <?php echo esc_html(eipsi_admin_translate('Test SMTP')); ?>
                        </button>

                        <button type="submit" id="eipsi-save-smtp-config" class="button button-primary" disabled>
                            <span class="dashicons dashicons-yes"></span>
                            <?php echo esc_html(eipsi_admin_translate('Save Configuration')); ?>
                        </button>

                        <?php if ($smtp_config): ?>
                        <button type="button" id="eipsi-disable-smtp" class="button button-link-delete">
                            <?php echo esc_html(eipsi_admin_translate('Disable SMTP')); ?>
                        </button>
                        <?php endif; ?>
                    </div>

                    <p class="description" style="margin-top: 12px;">
                        <?php echo esc_html(eipsi_admin_translate('The test email is sent to the researcher or site administrator email address.')); ?>
                    </p>

                    <div id="eipsi-smtp-message-container" role="alert" aria-live="polite" style="display: none;"></div>
                </form>
            </div>

            <div class="eipsi-status-section">
                <h2><?php echo esc_html(eipsi_admin_translate('Email status')); ?></h2>

                <div id="eipsi-smtp-status-box" class="eipsi-status-box">
                    <div class="eipsi-status-indicator">
                        <?php if ($smtp_config): ?>
                            <span class="status-icon status-connected"></span>
                            <span class="status-text"><?php echo esc_html(eipsi_admin_translate('SMTP active')); ?></span>
                        <?php else: ?>
                            <span class="status-icon status-disconnected"></span>
                            <span class="status-text"><?php echo esc_html(eipsi_admin_translate('SMTP inactive')); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($smtp_config): ?>
                    <div class="eipsi-status-details">
                        <div class="status-detail-row">
                            <span class="detail-label"><?php echo esc_html(eipsi_admin_translate('Server:')); ?></span>
                            <span class="detail-value"><?php echo esc_html($smtp_config['host']); ?></span>
                        </div>
                        <div class="status-detail-row">
                            <span class="detail-label"><?php echo esc_html(eipsi_admin_translate('Port:')); ?></span>
                            <span class="detail-value"><?php echo esc_html($smtp_config['port']); ?></span>
                        </div>
                        <div class="status-detail-row">
                            <span class="detail-label"><?php echo esc_html(eipsi_admin_translate('Username:')); ?></span>
                            <span class="detail-value"><?php echo esc_html($smtp_config['user']); ?></span>
                        </div>
                        <div class="status-detail-row">
                            <span class="detail-label"><?php echo esc_html(eipsi_admin_translate('Security:')); ?></span>
                            <span class="detail-value"><?php echo esc_html($smtp_encryption_labels[$smtp_config['encryption']] ?? $smtp_config['encryption']); ?></span>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="eipsi-status-message">
                        <p><?php echo esc_html(eipsi_admin_translate('No active SMTP configuration. Emails will be sent using wp_mail().')); ?></p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Default Email Test Section -->
                <div class="eipsi-email-test-section" style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
                    <h3><?php echo esc_html(eipsi_admin_translate('🧪 Test email system')); ?></h3>
                    <p class="description">
                        <?php echo esc_html(eipsi_admin_translate('Send a test email to verify the system works correctly.')); ?>
                    </p>

                    <div style="margin-bottom: 15px;">
                        <label for="test-email-address">
                            <?php echo esc_html(eipsi_admin_translate('Test email (optional):')); ?>
                        </label>
                        <input type="email"
                               id="test-email-address"
                               class="regular-text"
                               placeholder="<?php echo esc_attr(get_option('eipsi_investigator_email', get_option('admin_email'))); ?>">
                        <p class="description">
                            <?php echo esc_html(eipsi_admin_translate('Leave empty to use the researcher email address.')); ?>
                        </p>
                    </div>

                    <button type="button" id="eipsi-test-default-email" class="button button-secondary">
                        <span class="dashicons dashicons-email"></span>
                        <?php echo esc_html(eipsi_admin_translate('Test default email')); ?>
                    </button>

                    <button type="button" id="eipsi-get-email-diagnostic" class="button button-secondary" style="margin-left: 10px;">
                        <span class="dashicons dashicons-admin-tools"></span>
                        <?php echo esc_html(eipsi_admin_translate('View diagnostics')); ?>
                    </button>

                    <div id="eipsi-email-test-results" style="display: none; margin-top: 15px; padding: 12px; background: #fff; border-radius: 4px; border-left: 4px solid #2271b1;">
                        <p class="message" style="font-weight: bold; margin: 0 0 10px 0;"></p>
                        <p class="details" style="font-size: 13px; color: #666; margin: 0;"></p>
                    </div>

                    <div id="eipsi-email-diagnostic" style="display: none; margin-top: 15px; padding: 15px; background: #fff; border-radius: 4px;">
                        <div class="diagnostic-content"></div>
                        <div class="stats-content" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;"></div>
                    </div>
                </div>
            </div>
        </div>

        <?php endif; ?>

        <?php if ($active_tab === 'privacy-security'): ?>
            <div class="eipsi-config-container" style="margin-top: 30px;">
                <?php
                require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/privacy-dashboard.php';
                render_privacy_dashboard();
                ?>
            </div>

        <?php endif; ?>

        <?php if ($active_tab === 'scheduler') { \EIPSI\Scheduler\Admin::render(); } ?>

        <?php if ($active_tab === 'external-api') { \EIPSI\ExternalApi\Admin::render(); } ?>

        <?php if ($active_tab === 'schema-status'): ?>
            <div class="tab-content" data-tab="schema-status">
                <?php include dirname(__FILE__) . '/tabs/schema-status-tab.php'; ?>
            </div>
        <?php endif; ?>

        <?php
        // Note: Global notifications settings are now managed in Longitudinal Study > Recordatorios
        // This content was moved to provide a more logical grouping with other longitudinal features
        ?>
    </div>
    <?php
}
