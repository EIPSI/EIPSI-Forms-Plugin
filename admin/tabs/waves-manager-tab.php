<?php
/**
 * Waves Manager Tab
 * 
 * @since 1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// 1. Fetch all studies
$studies = $wpdb->get_results("SELECT id, study_name, study_code FROM {$wpdb->prefix}survey_studies ORDER BY created_at DESC");

// 2. Determine active study (BEFORE enqueue)
$current_study_id = isset($_GET['study_id']) ? absint($_GET['study_id']) : (isset($studies[0]) ? $studies[0]->id : 0);

// Enqueue styles y scripts para waves manager
wp_enqueue_style('eipsi-waves-manager', EIPSI_FORMS_PLUGIN_URL . 'admin/css/waves-manager.css', array(), EIPSI_FORMS_VERSION);
wp_enqueue_style('eipsi-high-contrast', EIPSI_FORMS_PLUGIN_URL . 'assets/css/eipsi-high-contrast.css', array('eipsi-waves-manager'), EIPSI_FORMS_VERSION);
wp_enqueue_script('eipsi-waves-manager', EIPSI_FORMS_PLUGIN_URL . 'admin/js/waves-manager.js', array('jquery'), EIPSI_FORMS_VERSION, true);

// Pasar datos al JS - Nonces y configuración para AJAX
wp_localize_script('eipsi-waves-manager', 'eipsiWavesManagerData', array(
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'anonymizeNonce' => wp_create_nonce('eipsi_anonymize_survey_nonce'),
    'wavesNonce' => wp_create_nonce('eipsi_waves_nonce'),
    'adminNonce' => wp_create_nonce('eipsi_admin_nonce'),
    'studyId' => $current_study_id,
    'strings' => array(
        'confirmDelete' => __('¿Estás seguro de eliminar esta onda? Esta acción no se puede deshacer.', 'eipsi-forms'),
        'confirmAssign' => __('¿Asignar los participantes seleccionados a esta onda?', 'eipsi-forms'),
        'confirmDeleteParticipant' => __('¿Estás seguro de eliminar este participante? Esta acción no se puede deshacer.', 'eipsi-forms'),
        'saving' => __('Guardando...', 'eipsi-forms'),
        'sending' => __('Enviando...', 'eipsi-forms'),
        'success' => __('✓ Éxito', 'eipsi-forms'),
        'error' => __('✗ Error', 'eipsi-forms'),
        'noParticipants' => __('No hay participantes disponibles para asignar.', 'eipsi-forms'),
        'selectParticipants' => __('Por favor selecciona al menos un participante.', 'eipsi-forms'),
        'waveSaved' => __('Onda guardada exitosamente.', 'eipsi-forms'),
        'waveDeleted' => __('Onda eliminada.', 'eipsi-forms'),
        'participantsAssigned' => __('participantes asignados.', 'eipsi-forms'),
        'remindersSent' => __('recordatorios enviados.', 'eipsi-forms'),
        'deadlineExtended' => __('Plazo extendido.', 'eipsi-forms'),
        'participantAdded' => __('Participante agregado exitosamente.', 'eipsi-forms'),
        'participantUpdated' => __('Participante actualizado exitosamente.', 'eipsi-forms'),
        'participantDeleted' => __('Participante eliminado exitosamente.', 'eipsi-forms'),
        'confirmSendReminders' => __('¿Enviar recordatorios a los participantes seleccionados?', 'eipsi-forms'),
        'remindersSentSuccess' => __('recordatorios enviados exitosamente.', 'eipsi-forms'),
        'noParticipantsSelected' => __('Por favor selecciona al menos un participante.', 'eipsi-forms'),
        'loadingPending' => __('Cargando participantes pendientes...', 'eipsi-forms'),
        'noPendingParticipants' => __('No hay participantes pendientes para esta onda.', 'eipsi-forms'),
    ),
));

// 3. Get waves for active study
$waves = array();
$next_wave_index = 1;
$wave_count = 0;
$wave_columns = 1;
if ($current_study_id) {
    $waves_raw = EIPSI_Wave_Service::get_study_waves($current_study_id);
    $wave_count = count($waves_raw);
    $wave_columns = $wave_count > 0 ? min(3, $wave_count) : 1;
    
    // Calculate dynamic status for each wave
    $waves = array();
    foreach ($waves_raw as $wave) {
        $wave_array = (array) $wave;
        // Calculate dynamic status
        $wave_array['status'] = EIPSI_Wave_Service::calculate_wave_status($wave);
        $waves[] = $wave_array;
    }
    
    if (!empty($waves)) {
        $last_wave = end($waves);
        $next_wave_index = (int)$last_wave['wave_index'] + 1;
    }
}

// 4. Get available forms using the wizard function for consistency
$available_forms = get_posts(array(
    'post_type' => 'page',
    'posts_per_page' => -1,
    'meta_query' => array(
        array(
            'key' => '_eipsi_form_active',
            'value' => '1',
            'compare' => '='
        )
    ),
    'orderby' => 'title',
    'order' => 'ASC'
));

?>

<div class="eipsi-waves-manager-wrap" data-study-id="<?php echo esc_attr($current_study_id); ?>">

    <!-- Header with Study Selector -->
    <div class="eipsi-waves-header">
        <div class="study-selector-wrap">
            <label for="eipsi-study-selector"><strong>📚 <?php esc_html_e('Seleccionar Estudio:', 'eipsi-forms'); ?></strong></label>
            <select id="eipsi-study-selector" onchange="window.location.href='?page=eipsi-results&tab=waves-manager&study_id=' + this.value">
                <?php if (empty($studies)): ?>
                    <option value=""><?php esc_html_e('No hay estudios creados', 'eipsi-forms'); ?></option>
                <?php else: ?>
                    <?php foreach ($studies as $study): ?>
                        <option value="<?php echo esc_attr($study->id); ?>" <?php selected($current_study_id, $study->id); ?>>
                            <?php echo esc_html($study->study_name . ' (' . $study->study_code . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>

        <div class="header-actions">
            <?php if ($current_study_id): ?>
                <button type="button" class="button button-primary" id="eipsi-create-wave-btn" data-next-index="<?php echo esc_attr($next_wave_index); ?>">
                    ➕ <?php esc_html_e('Nueva Onda (Wave)', 'eipsi-forms'); ?>
                </button>
            <?php endif; ?>
        </div>
    </div>


    <?php if (!$current_study_id): ?>
        <div class="notice notice-warning" style="padding: 15px; border-left: 4px solid #f59e0b;">
            <p><?php esc_html_e('Por favor, selecciona o crea un estudio primero para gestionar sus ondas.', 'eipsi-forms'); ?></p>
            <p>
                <a href="?page=eipsi-longitudinal-study&tab=create-study" class="button button-primary">
                    ➕ <?php esc_html_e('Crear Nuevo Estudio', 'eipsi-forms'); ?>
                </a>
            </p>
        </div>
    <?php else: ?>

        <!-- Waves List -->
        <div class="eipsi-waves-list">
            <?php if (empty($waves)): ?>
                <div class="eipsi-waves-empty">
                    <h3>🌊 <?php esc_html_e('Sin Ondas Configuradas', 'eipsi-forms'); ?></h3>
                    <p><?php esc_html_e('No hay ondas configuradas para este estudio. Crea tu primera onda para comenzar.', 'eipsi-forms'); ?></p>
                </div>
            <?php else: ?>
                <div class="eipsi-waves-grid columns-<?php echo esc_attr($wave_columns); ?>" data-wave-count="<?php echo esc_attr($wave_count); ?>">
                    <?php foreach ($waves as $wave): ?>
                        <?php
                        $stats = EIPSI_Wave_Service::get_wave_stats($wave['id']);
                        $progress = ($stats['total'] > 0) ? round(($stats['submitted'] / $stats['total']) * 100) : 0;
                        $form_post = get_post($wave['form_id']);
                        $form_name = $form_post ? $form_post->post_title : __('Formulario no encontrado', 'eipsi-forms');
                        ?>
                        <div class="wave-card wave-<?php echo esc_attr($wave['status']); ?>" data-wave-id="<?php echo esc_attr($wave['id']); ?>">
                            <div class="wave-card-header">
                                <div class="wave-identifier">
                                    <span class="wave-index">T<?php echo esc_html($wave['wave_index']); ?></span>
                                </div>
                                <div class="wave-title-section">
                                    <h3 class="wave-title"><?php echo esc_html($wave['name']); ?></h3>
                                    <span class="wave-badge <?php echo esc_attr($wave['status']); ?>">
                                        <?php
                                        $status_labels = array(
                                            'upcoming' => __('Próxima', 'eipsi-forms'),
                                            'active' => __('Activa', 'eipsi-forms'),
                                            'closed' => __('Cerrada', 'eipsi-forms'),
                                            'overdue' => __('Vencida', 'eipsi-forms'),
                                            'draft' => __('Borrador', 'eipsi-forms'),
                                            'completed' => __('Completada', 'eipsi-forms'),
                                            'paused' => __('Pausada', 'eipsi-forms'),
                                        );
                                        echo esc_html($status_labels[$wave['status']] ?? ucfirst($wave['status']));
                                        ?>
                                    </span>
                                </div>
                            </div>

                            <div class="wave-card-body">
                                <?php if (!empty($wave['description'])): ?>
                                    <p class="wave-description"><?php echo esc_html($wave['description']); ?></p>
                                <?php endif; ?>

                                <div class="wave-info">
                                    <div class="wave-info-row">
                                        <span class="wave-info-label">📋 <?php esc_html_e('Formulario:', 'eipsi-forms'); ?></span>
                                        <span class="wave-info-value"><?php echo esc_html($form_name); ?></span>
                                    </div>
                                    <?php if (!empty($wave['start_date'])): ?>
                                    <div class="wave-info-row">
                                        <span class="wave-info-label">🚀 <?php esc_html_e('Inicio:', 'eipsi-forms'); ?></span>
                                        <span class="wave-info-value">
                                            <?php echo esc_html(date_i18n(get_option('date_format') . ' H:i', strtotime($wave['start_date']))); ?>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="wave-info-row">
                                        <span class="wave-info-label">📅 <?php esc_html_e('Vence:', 'eipsi-forms'); ?></span>
                                        <span class="wave-info-value">
                                            <?php echo esc_html($wave['due_date'] ? date_i18n(get_option('date_format') . ' H:i', strtotime($wave['due_date'])) : __('Sin fecha', 'eipsi-forms')); ?>
                                        </span>
                                    </div>
                                    <div class="wave-info-row">
                                        <span class="wave-info-label">⏱️ <?php esc_html_e('Tiempo Límite:', 'eipsi-forms'); ?></span>
                                        <span class="wave-info-value">
                                            <?php 
                                            if (!empty($wave['has_time_limit']) && !empty($wave['completion_time_limit'])) {
                                                echo esc_html($wave['completion_time_limit'] . ' min');
                                            } else {
                                                echo '<span style="color: #28a745; font-weight: 500;">∞ ' . __('Ilimitado', 'eipsi-forms') . '</span>';
                                            }
                                            ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="wave-stats">
                                    <div class="stat-item">
                                        <span class="stat-value"><?php echo (int)$stats['total']; ?></span>
                                        <span class="stat-label"><?php esc_html_e('Asignados', 'eipsi-forms'); ?></span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="stat-value"><?php echo (int)$stats['submitted']; ?></span>
                                        <span class="stat-label"><?php esc_html_e('Completados', 'eipsi-forms'); ?></span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="stat-value"><?php echo (int)$stats['pending']; ?></span>
                                        <span class="stat-label"><?php esc_html_e('Pendientes', 'eipsi-forms'); ?></span>
                                    </div>
                                </div>

                                <div class="wave-progress">
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo (int)$progress; ?>%"></div>
                                    </div>
                                    <span class="progress-text"><?php echo (int)$progress; ?>%</span>
                                </div>
                            </div>

                            <div class="wave-card-actions">
                                <button type="button" class="button button-secondary eipsi-edit-wave-btn" data-wave-id="<?php echo esc_attr($wave['id']); ?>">
                                    ✏️ <?php esc_html_e('Editar', 'eipsi-forms'); ?>
                                </button>
                                <button type="button" class="button button-secondary eipsi-assign-participants-btn" data-wave-id="<?php echo esc_attr($wave['id']); ?>">
                                    👥 <?php esc_html_e('Asignar', 'eipsi-forms'); ?>
                                </button>
                                <button type="button" class="button button-secondary eipsi-extend-deadline-btn" data-wave-id="<?php echo esc_attr($wave['id']); ?>">
                                    📅 <?php esc_html_e('Extender', 'eipsi-forms'); ?>
                                </button>
                                
                                <!-- Toggle seguimiento automático -->
                                <div class="eipsi-reminder-toggle-wrapper">
                                    <label class="eipsi-toggle-label">
                                        <input type="checkbox" 
                                               class="eipsi-follow-up-toggle" 
                                               data-wave-id="<?php echo esc_attr($wave['id']); ?>"
                                               <?php checked(!empty($wave['follow_up_reminders_enabled'])); ?>>
                                        <span class="eipsi-toggle-slider"></span>
                                    </label>
                                    <span class="eipsi-toggle-text">🔔 <?php esc_html_e('Seguimiento', 'eipsi-forms'); ?></span>
                                    <button type="button" class="button button-link eipsi-config-reminders-btn" data-wave-id="<?php echo esc_attr($wave['id']); ?>" title="<?php esc_attr_e('Configurar recordatorios', 'eipsi-forms'); ?>">
                                        ⚙️
                                    </button>
                                </div>
                                
                                <button type="button" class="button button-link-delete eipsi-delete-wave-btn" data-wave-id="<?php echo esc_attr($wave['id']); ?>">
                                    🗑️ <?php esc_html_e('Eliminar', 'eipsi-forms'); ?>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal: Crear/Editar Wave -->
<div id="eipsi-wave-modal" class="eipsi-modal" style="display:none;">
    <div class="eipsi-modal-content">
        <span class="eipsi-close-modal">&times;</span>
        <h3 id="wave-modal-title">🌊 <?php esc_html_e('Crear Nueva Onda', 'eipsi-forms'); ?></h3>
        <form id="eipsi-wave-form">
            <input type="hidden" name="wave_id" id="wave_id" value="">
            <input type="hidden" name="study_id" value="<?php echo esc_attr($current_study_id); ?>">

            <div class="eipsi-form-row">
                <div class="form-group">
                    <label for="wave_name" class="eipsi-form-label required">
                        📝 <?php esc_html_e('Nombre de la Onda:', 'eipsi-forms'); ?>
                    </label>
                    <input type="text" id="wave_name" name="name" class="eipsi-form-input" required placeholder="Ej: Evaluación Inicial">
                </div>

                <div class="form-group">
                    <label for="wave_index" class="eipsi-form-label required">
                        🔢 <?php esc_html_e('Índice (T1, T2...):', 'eipsi-forms'); ?>
                    </label>
                    <input type="number" id="wave_index" name="wave_index" class="eipsi-form-input" min="1" step="1" required>
                </div>
            </div>

            <div class="form-group">
                <label for="form_id" class="eipsi-form-label required">
                    📋 <?php esc_html_e('Formulario Asociado:', 'eipsi-forms'); ?>
                </label>
                <select id="form_id" name="form_id" class="eipsi-form-select" required>
                    <option value=""><?php esc_html_e('Seleccionar formulario...', 'eipsi-forms'); ?></option>
                    <?php foreach ($available_forms as $form): ?>
                        <option value="<?php echo esc_attr($form->ID); ?>"><?php echo esc_html($form->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="eipsi-form-row">
                <div class="form-group">
                    <label for="start_date" class="eipsi-form-label">
                        🚀 <?php esc_html_e('Fecha de Inicio:', 'eipsi-forms'); ?>
                    </label>
                    <input type="datetime-local" id="start_date" name="start_date" class="eipsi-form-input">
                    <small class="form-help"><?php esc_html_e('Fecha de configuración. La disponibilidad longitudinal se calcula desde T1 y los offsets. Con participantes asignados, las fechas se gestionan en el dashboard.', 'eipsi-forms'); ?></small>
                </div>

                <div class="form-group">
                    <label for="due_date" class="eipsi-form-label">
                        📅 <?php esc_html_e('Fecha de Vencimiento:', 'eipsi-forms'); ?>
                    </label>
                    <input type="datetime-local" id="due_date" name="due_date" class="eipsi-form-input">
                </div>
            </div>

                <div class="form-group">
                    <label for="wave_status" class="eipsi-form-label">
                        📊 <?php esc_html_e('Estado:', 'eipsi-forms'); ?>
                    </label>
                    <select id="wave_status" name="status" class="eipsi-form-select">
                        <option value="draft"><?php esc_html_e('Pendiente', 'eipsi-forms'); ?></option>
                        <option value="active"><?php esc_html_e('Activa', 'eipsi-forms'); ?></option>
                        <option value="paused"><?php esc_html_e('Pausada', 'eipsi-forms'); ?></option>
                        <option value="completed"><?php esc_html_e('Completada', 'eipsi-forms'); ?></option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="wave_description" class="eipsi-form-label">
                    📄 <?php esc_html_e('Descripción:', 'eipsi-forms'); ?>
                </label>
                <textarea id="wave_description" class="eipsi-form-textarea" disabled rows="3" placeholder="Descripción no editable en el modelo actual"></textarea>
            </div>

            <!-- Time Limit Configuration -->
            <div class="form-group time-limit-section">
                <label class="time-limit-header">
                    <input type="checkbox" name="has_time_limit" id="has_time_limit" value="1">
                    ⏱️ <?php esc_html_e('Limitar tiempo para completar el formulario', 'eipsi-forms'); ?>
                </label>
                <div class="time-limit-input" id="time-limit-input-container" style="display: none; margin-top: 10px; padding-left: 20px;">
                    <div class="input-group">
                        <input type="number" id="completion_time_limit" name="completion_time_limit" value="30" min="1" max="180" style="width: 100px;">
                        <span class="input-suffix"><?php esc_html_e('minutos', 'eipsi-forms'); ?></span>
                    </div>
                    <small class="form-help">
                        <?php esc_html_e('El participante debe completar el formulario dentro de este tiempo límite. Si no seleccionas esta opción, el tiempo será ilimitado (∞).', 'eipsi-forms'); ?>
                    </small>
                </div>
            </div>

            <div class="form-group">
                <label>
                    <input type="checkbox" name="is_mandatory" value="1" checked>
                    ⭐ <?php esc_html_e('Esta onda es obligatoria', 'eipsi-forms'); ?>
                </label>
            </div>

            <div class="modal-footer">
                <button type="submit" class="button button-primary" id="save-wave-btn">
                    💾 <?php esc_html_e('Guardar Onda', 'eipsi-forms'); ?>
                </button>
                <button type="button" class="button eipsi-close-modal-btn">
                    ❌ <?php esc_html_e('Cancelar', 'eipsi-forms'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Asignar Participantes -->
<div id="eipsi-assign-modal" class="eipsi-modal" style="display:none;">
    <div class="eipsi-modal-content modal-large">
        <span class="eipsi-close-modal">&times;</span>
        <h3><?php esc_html_e('Asignar Participantes a la Onda', 'eipsi-forms'); ?> <span id="assign-wave-name"></span></h3>
        <input type="hidden" id="assign-wave-id" value="">
        
        <div class="assign-filters">
            <p><?php esc_html_e('Selecciona los participantes que aún no están asignados a esta onda.', 'eipsi-forms'); ?></p>
            <div class="selection-actions">
                <button type="button" class="button button-small" id="select-all-participants"><?php esc_html_e('Seleccionar Todos', 'eipsi-forms'); ?></button>
                <button type="button" class="button button-small" id="deselect-all-participants"><?php esc_html_e('Deseleccionar Todos', 'eipsi-forms'); ?></button>
            </div>
        </div>
        
        <div class="participants-list-container">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="check-column"><input type="checkbox" id="master-participant-check"></th>
                        <th><?php esc_html_e('Nombre Completo', 'eipsi-forms'); ?></th>
                        <th><?php esc_html_e('Email', 'eipsi-forms'); ?></th>
                        <th><?php esc_html_e('ID Participante', 'eipsi-forms'); ?></th>
                    </tr>
                </thead>
                <tbody id="available-participants-tbody">
                    <!-- Loaded via AJAX -->
                    <tr>
                        <td colspan="4" style="text-align:center;"><?php esc_html_e('Cargando participantes...', 'eipsi-forms'); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="modal-footer">
            <button type="button" class="button button-primary" id="confirm-assign-btn"><?php esc_html_e('Asignar Seleccionados', 'eipsi-forms'); ?></button>
            <button type="button" class="button eipsi-close-modal-btn"><?php esc_html_e('Cerrar', 'eipsi-forms'); ?></button>
        </div>
    </div>
</div>

<?php
// Mostrar botón de anonimización solo si se puede anonimizar
if (class_exists('EIPSI_Anonymize_Service') && $current_study_id) {
    $can_anon = EIPSI_Anonymize_Service::can_anonymize_survey($current_study_id);
    if ($can_anon['can_anonymize']) {
        ?>
        <!-- Sección: Cerrar & Anonimizar Estudio -->
        <div class="eipsi-anonymize-section" style="margin-top: 30px; padding: 20px; background: #fff8f0; border: 2px solid #ff6b6b; border-radius: 4px;">
            <h3 style="color: #d63031; margin-top: 0;">⚠️ Cerrar & Anonimizar Estudio</h3>
            <p style="color: #555;">Esta acción es <strong>irreversible</strong>. Una vez anonimizado, los datos PII (emails, contraseñas, nombres) serán eliminados permanentemente.</p>
            <button 
                type="button" 
                class="button button-secondary eipsi-btn-anonymize" 
                id="eipsi-open-anonymize-modal"
                data-survey-id="<?php echo esc_attr($current_study_id); ?>"
            >
                🔐 Close & Anonymize Study
            </button>
        </div>
        <?php
    }
}
?>

<!-- Modal: Close & Anonymize Study -->
<div id="eipsi-anonymize-modal" class="eipsi-modal" style="display: none;">
    <div class="eipsi-modal-overlay"></div>
    
    <div class="eipsi-modal-content">
        <!-- Header -->
        <div class="eipsi-modal-header">
            <h2 id="eipsi-modal-title">Cerrar & Anonimizar Estudio - Paso 1/3</h2>
            <button type="button" class="eipsi-modal-close" id="eipsi-close-modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <!-- Body with Steps -->
        <div class="eipsi-modal-body">
            
            <!-- PASO 1: Confirmar Intención -->
            <div class="eipsi-modal-step" id="step-1" style="display: block;">
                <h3>⚠️ Entiendo que esta acción es IRREVERSIBLE</h3>
                
                <div class="eipsi-checkbox-list">
                    <label>
                        <input type="checkbox" id="eipsi-confirm-1" />
                        <span>Los emails de todos los participantes serán eliminados</span>
                    </label>
                    <label>
                        <input type="checkbox" id="eipsi-confirm-2" />
                        <span>Las contraseñas de todos los participantes serán eliminadas</span>
                    </label>
                    <label>
                        <input type="checkbox" id="eipsi-confirm-3" />
                        <span>Los nombres (first_name, last_name) serán eliminados</span>
                    </label>
                    <label>
                        <input type="checkbox" id="eipsi-confirm-4" />
                        <span>Los participantes NO podrán volver a acceder al estudio</span>
                    </label>
                    <label>
                        <input type="checkbox" id="eipsi-confirm-5" />
                        <span>Los datos de respuestas se mantendrán ANÓNIMOS para investigación</span>
                    </label>
                    <label>
                        <input type="checkbox" id="eipsi-confirm-6" />
                        <span>Esta acción será registrada en audit log para auditoría</span>
                    </label>
                </div>
            </div>
            
            <!-- PASO 2: Razón de Cierre -->
            <div class="eipsi-modal-step" id="step-2" style="display: none;">
                <h3>¿Por qué estás cerrando el estudio?</h3>
                
                <select id="eipsi-close-reason" class="eipsi-form-select">
                    <option value="">-- Seleccionar razón --</option>
                    <option value="completed">Estudio completado exitosamente</option>
                    <option value="participant_decision">Decisión de participantes</option>
                    <option value="technical_issue">Problema técnico</option>
                    <option value="regulatory">Razones regulatorias</option>
                    <option value="other">Otra (especificar abajo)</option>
                </select>
                
                <label style="margin-top: 15px;">
                    <span style="display: block; margin-bottom: 5px;">Notas (opcional):</span>
                    <textarea 
                        id="eipsi-close-notes" 
                        class="eipsi-form-textarea"
                        rows="4"
                        placeholder="Información adicional sobre el cierre..."
                    ></textarea>
                </label>
            </div>
            
            <!-- PASO 3: Confirmación Final -->
            <div class="eipsi-modal-step" id="step-3" style="display: none;">
                <h3>✋ Confirmación Final</h3>
                <p style="font-weight: bold; color: #d63031;">
                    Escribe exactamente "<strong>ANONIMIZAR</strong>" para confirmar:
                </p>
                
                <input 
                    type="text" 
                    id="eipsi-confirm-text" 
                    class="eipsi-form-input"
                    placeholder="Escribe ANONIMIZAR..."
                    autocomplete="off"
                />
                
                <div id="eipsi-step3-message" style="display: none; margin-top: 10px; padding: 10px; border-radius: 4px;"></div>
            </div>
            
            <!-- Success Message -->
            <div class="eipsi-modal-step" id="step-success" style="display: none;">
                <div style="text-align: center;">
                    <h3 style="color: #27ae60;">✅ Proceso Completado</h3>
                    <p id="eipsi-success-message"></p>
                    <div id="eipsi-success-details" style="margin-top: 15px; text-align: left; background: #f0f9ff; padding: 15px; border-radius: 4px;"></div>
                </div>
            </div>
            
        </div>
        
        <!-- Footer with Buttons -->
        <div class="eipsi-modal-footer">
            <button type="button" class="button button-secondary" id="eipsi-modal-prev">
                ← Anterior
            </button>
            <button type="button" class="button button-primary" id="eipsi-modal-next">
                Siguiente →
            </button>
            <button type="button" class="button button-secondary" id="eipsi-modal-cancel">
                Cancelar
            </button>
        </div>
    </div>
</div>

<?php if ($current_study_id): ?>
<!-- Participants Management Section -->
<div class="eipsi-participants-section" style="margin-top: 40px; padding: 20px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e9ecef;">
    <div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h2 style="margin: 0 0 5px 0;">👥 <?php esc_html_e('Gestión de Participantes', 'eipsi-forms'); ?></h2>
            <p style="margin: 0; color: #6c757d; font-size: 0.9rem;">
                <?php esc_html_e('Administra los participantes del estudio. Puedes agregar nuevos, editar información o eliminar participantes.', 'eipsi-forms'); ?>
            </p>
        </div>
        <button type="button" class="button button-primary" id="eipsi-add-participant-btn">
            ➕ <?php esc_html_e('Agregar Participante', 'eipsi-forms'); ?>
        </button>
    </div>

    <!-- Participants Table -->
    <div class="participants-table-container">
        <table class="wp-list-table widefat fixed striped" id="participants-table">
            <thead>
                <tr>
                    <th style="width: 80px;"><?php esc_html_e('ID', 'eipsi-forms'); ?></th>
                    <th><?php esc_html_e('Nombre', 'eipsi-forms'); ?></th>
                    <th><?php esc_html_e('Email', 'eipsi-forms'); ?></th>
                    <th><?php esc_html_e('Estado', 'eipsi-forms'); ?></th>
                    <th><?php esc_html_e('Registrado', 'eipsi-forms'); ?></th>
                    <th style="width: 200px;"><?php esc_html_e('Acciones', 'eipsi-forms'); ?></th>
                </tr>
            </thead>
            <tbody id="participants-tbody">
                <tr>
                    <td colspan="6" style="text-align: center; padding: 40px;">
                        <span class="spinner is-active"></span> <?php esc_html_e('Cargando participantes...', 'eipsi-forms'); ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add/Edit Participant -->
<div id="eipsi-participant-modal" class="eipsi-modal" style="display:none;">
    <div class="eipsi-modal-content">
        <span class="eipsi-close-modal">&times;</span>
        <h3 id="participant-modal-title">👤 <?php esc_html_e('Agregar Participante', 'eipsi-forms'); ?></h3>
        <form id="eipsi-participant-form">
            <input type="hidden" name="participant_id" id="participant_id" value="">
            <input type="hidden" name="study_id" value="<?php echo esc_attr($current_study_id); ?>">

            <div class="form-group">
                <label for="participant_email" class="eipsi-form-label required">
                    📧 <?php esc_html_e('Email:', 'eipsi-forms'); ?>
                </label>
                <input type="email" id="participant_email" name="email" class="eipsi-form-input" required placeholder="participante@ejemplo.com">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="participant_first_name" class="eipsi-form-label">
                        👤 <?php esc_html_e('Nombre:', 'eipsi-forms'); ?>
                    </label>
                    <input type="text" id="participant_first_name" name="first_name" class="eipsi-form-input" placeholder="Juan">
                </div>
                <div class="form-group">
                    <label for="participant_last_name" class="eipsi-form-label">
                        👤 <?php esc_html_e('Apellido:', 'eipsi-forms'); ?>
                    </label>
                    <input type="text" id="participant_last_name" name="last_name" class="eipsi-form-input" placeholder="Pérez">
                </div>
            </div>

            <div class="form-group" id="password-field-container">
                <label for="participant_password" class="eipsi-form-label">
                    🔐 <?php esc_html_e('Contraseña:', 'eipsi-forms'); ?>
                </label>
                <input type="password" id="participant_password" name="password" class="eipsi-form-input" placeholder="<?php esc_attr_e('Dejar en blanco para generar automáticamente', 'eipsi-forms'); ?>">
                <small class="form-help"><?php esc_html_e('Mínimo 8 caracteres. Se generará automáticamente si se deja en blanco.', 'eipsi-forms'); ?></small>
            </div>

            <div class="form-group" id="active-field-container" style="display: none;">
                <label>
                    <input type="checkbox" name="is_active" id="participant_is_active" value="1" checked>
                    ✅ <?php esc_html_e('Participante activo', 'eipsi-forms'); ?>
                </label>
            </div>

            <div class="modal-footer">
                <button type="submit" class="button button-primary" id="save-participant-btn">
                    ✉️ <?php esc_html_e('Crear y Enviar Invitación', 'eipsi-forms'); ?>
                </button>
                <button type="button" class="button eipsi-close-modal-btn">
                    ❌ <?php esc_html_e('Cancelar', 'eipsi-forms'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Agregar Participante (Multi-Method) -->
<div id="eipsi-add-participant-multi-modal" class="eipsi-modal" style="display:none;">
    <div class="eipsi-modal-content modal-large">
        <span class="eipsi-close-modal">&times;</span>
        <h3><?php esc_html_e('➕ Agregar Participantes al Estudio', 'eipsi-forms'); ?></h3>
        <p class="description">
            <?php esc_html_e('Selecciona el método de invitación para agregar participantes a tu estudio.', 'eipsi-forms'); ?>
        </p>
        
        <input type="hidden" id="add-participant-study-id" value="<?php echo esc_attr($current_study_id); ?>">
        
        <!-- Tabs Navigation -->
        <div class="eipsi-tabs-nav" style="display: flex; border-bottom: 2px solid #ddd; margin-bottom: 20px;">
            <button type="button" class="eipsi-tab-btn active" data-tab="magic-link">
                ✉️ <?php esc_html_e('Magic Link Individual', 'eipsi-forms'); ?>
            </button>
            <button type="button" class="eipsi-tab-btn" data-tab="bulk">
                📋 <?php esc_html_e('Lista CSV / Manual', 'eipsi-forms'); ?>
            </button>
            <button type="button" class="eipsi-tab-btn" data-tab="public">
                🌐 <?php esc_html_e('Registro Público', 'eipsi-forms'); ?>
            </button>
        </div>
        
        <!-- Tab 1: Magic Link Individual -->
        <div class="eipsi-tab-content active" id="tab-magic-link">
            <form id="eipsi-form-magic-link">
                <div class="form-group">
                    <label for="ml-email"><?php esc_html_e('Email del Participante:', 'eipsi-forms'); ?> <span class="required">*</span></label>
                    <input type="email" id="ml-email" name="email" required placeholder="participante@ejemplo.com">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="ml-first-name"><?php esc_html_e('Nombre (opcional):', 'eipsi-forms'); ?></label>
                        <input type="text" id="ml-first-name" name="first_name" placeholder="Juan">
                    </div>
                    <div class="form-group">
                        <label for="ml-last-name"><?php esc_html_e('Apellido (opcional):', 'eipsi-forms'); ?></label>
                        <input type="text" id="ml-last-name" name="last_name" placeholder="Pérez">
                    </div>
                </div>
                
                <div class="notice notice-info inline" style="margin-top: 15px;">
                    <p><?php esc_html_e('Se generará un Magic Link único y se enviará automáticamente por email al participante. El enlace será válido por 48 horas.', 'eipsi-forms'); ?></p>
                </div>
                
                <div class="modal-footer">
                    <button type="submit" class="button button-primary" id="btn-send-magic-link">
                        ✉️ <?php esc_html_e('Crear y Enviar Magic Link', 'eipsi-forms'); ?>
                    </button>
                    <button type="button" class="button eipsi-close-modal-btn"><?php esc_html_e('Cancelar', 'eipsi-forms'); ?></button>
                </div>
            </form>
        </div>
        
        <!-- Tab 2: Bulk CSV / Manual -->
        <div class="eipsi-tab-content" id="tab-bulk">
            <form id="eipsi-form-bulk">
                <div class="form-group">
                    <label for="bulk-emails"><?php esc_html_e('Lista de Emails:', 'eipsi-forms'); ?> <span class="required">*</span></label>
                    <textarea id="bulk-emails" name="emails" rows="8" required placeholder="<?php esc_attr_e('Ingresa emails separados por comas, punto y coma o línea nueva:

participante1@ejemplo.com
participante2@ejemplo.com, participante3@ejemplo.com
participante4@ejemplo.com; participante5@ejemplo.com', 'eipsi-forms'); ?>"></textarea>
                    <small class="form-help"><?php esc_html_e('Formatos aceptados: separados por comas, punto y coma o línea nueva. Se eliminarán duplicados automáticamente.', 'eipsi-forms'); ?></small>
                </div>
                
                <div class="notice notice-info inline">
                    <p><?php esc_html_e('Se enviará un Magic Link único a cada email válido. Los emails duplicados o inválidos serán ignorados.', 'eipsi-forms'); ?></p>
                </div>
                
                <div id="bulk-results" class="bulk-results-container" style="display: none; margin-top: 15px; padding: 15px; background: #f0f9ff; border-radius: 4px;">
                    <h4><?php esc_html_e('Resultados del Envío:', 'eipsi-forms'); ?></h4>
                    <div id="bulk-results-content"></div>
                </div>
                
                <div class="modal-footer">
                    <button type="submit" class="button button-primary" id="btn-send-bulk">
                        ✉️ <?php esc_html_e('Enviar Invitaciones Masivas', 'eipsi-forms'); ?>
                    </button>
                    <button type="button" class="button eipsi-close-modal-btn"><?php esc_html_e('Cancelar', 'eipsi-forms'); ?></button>
                </div>
            </form>
        </div>
        
        <!-- Tab 3: Public Registration -->
        <div class="eipsi-tab-content" id="tab-public">
            <div class="public-registration-content">
                <p><?php esc_html_e('Utiliza este enlace público para que los participantes se registren por su cuenta.', 'eipsi-forms'); ?></p>
                
                <div class="form-group">
                    <label><?php esc_html_e('Enlace de Registro Público:', 'eipsi-forms'); ?></label>
                    <div class="input-group" style="display: flex; gap: 10px;">
                        <input type="text" id="public-registration-url" readonly style="flex: 1; background: #f5f5f5;">
                        <button type="button" class="button" id="btn-copy-public-link">
                            📋 <?php esc_html_e('Copiar Enlace', 'eipsi-forms'); ?>
                        </button>
                    </div>
                </div>
                
                <div class="notice notice-warning inline" style="margin-top: 15px;">
                    <p><strong><?php esc_html_e('Nota:', 'eipsi-forms'); ?></strong> <?php esc_html_e('Este enlace es público y cualquier persona puede registrarse. Compártelo solo con tus participantes.', 'eipsi-forms'); ?></p>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="button button-primary" id="btn-load-public-link">
                        🔗 <?php esc_html_e('Generar Enlace Público', 'eipsi-forms'); ?>
                    </button>
                    <button type="button" class="button eipsi-close-modal-btn"><?php esc_html_e('Cerrar', 'eipsi-forms'); ?></button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Email Preview -->
<div id="eipsi-email-preview-modal" class="eipsi-modal" style="display:none;">
    <div class="eipsi-modal-content modal-large">
        <span class="eipsi-close-modal">&times;</span>
        <h3>📧 <?php esc_html_e('Vista Previa del Email', 'eipsi-forms'); ?></h3>
        
        <div class="email-preview-info" style="background: #f8f9fa; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
            <div class="form-group" style="margin-bottom: 10px;">
                <label><strong><?php esc_html_e('Tipo de Email:', 'eipsi-forms'); ?></strong></label>
                <select id="email-preview-type" class="eipsi-form-select" style="max-width: 300px;">
                    <option value="reminder"><?php esc_html_e('Recordatorio de Onda', 'eipsi-forms'); ?></option>
                    <option value="manual"><?php esc_html_e('Recordatorio Manual', 'eipsi-forms'); ?></option>
                    <option value="welcome"><?php esc_html_e('Bienvenida', 'eipsi-forms'); ?></option>
                    <option value="confirmation"><?php esc_html_e('Confirmación', 'eipsi-forms'); ?></option>
                    <option value="recovery"><?php esc_html_e('Recuperación (Dropout)', 'eipsi-forms'); ?></option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label><strong><?php esc_html_e('Participante:', 'eipsi-forms'); ?></strong></label>
                <select id="email-preview-participant" class="eipsi-form-select" style="max-width: 300px;">
                    <option value=""><?php esc_html_e('Vista previa de muestra', 'eipsi-forms'); ?></option>
                </select>
            </div>
        </div>

        <div class="email-preview-content" style="border: 1px solid #ddd; border-radius: 4px; overflow: hidden;">
            <div class="email-preview-header" style="background: #f0f0f0; padding: 10px 15px; border-bottom: 1px solid #ddd;">
                <strong><?php esc_html_e('Asunto:', 'eipsi-forms'); ?></strong>
                <span id="email-preview-subject"></span>
            </div>
            <div class="email-preview-body" id="email-preview-body" style="padding: 20px; min-height: 200px; background: #fff;">
                <!-- Email content loaded via AJAX -->
                <div style="text-align: center; padding: 40px; color: #666;">
                    <span class="spinner is-active"></span>
                    <p><?php esc_html_e('Cargando vista previa...', 'eipsi-forms'); ?></p>
                </div>
            </div>
        </div>

        <div class="email-preview-footer" style="margin-top: 15px; padding: 10px; background: #fff3cd; border-radius: 4px; font-size: 0.9em;">
            <strong>⚠️ <?php esc_html_e('Nota:', 'eipsi-forms'); ?></strong>
            <?php esc_html_e('Este es un ejemplo del email que recibirán los participantes. El enlace mágico (magic link) se generará automáticamente al enviar.', 'eipsi-forms'); ?>
        </div>

        <div class="modal-footer">
            <button type="button" class="button button-secondary" id="email-preview-refresh-btn">
                🔄 <?php esc_html_e('Actualizar Vista Previa', 'eipsi-forms'); ?>
            </button>
            <button type="button" class="button eipsi-close-modal-btn">
                ❌ <?php esc_html_e('Cerrar', 'eipsi-forms'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Validation Warning Modal -->
<div id="eipsi-validation-warning-modal" class="eipsi-modal" style="display:none;">
    <div class="eipsi-modal-content">
        <span class="eipsi-close-modal">&times;</span>
        <h3>⚠️ <?php esc_html_e('Advertencia de Configuración', 'eipsi-forms'); ?></h3>
        
        <div class="validation-warnings-list" id="validation-warnings-list" style="margin: 20px 0;">
            <!-- Warnings loaded dynamically -->
        </div>

        <div class="modal-footer">
            <button type="button" class="button button-primary" id="validation-confirm-save-btn">
                <?php esc_html_e('Guardar de Todos Modos', 'eipsi-forms'); ?>
            </button>
            <button type="button" class="button eipsi-close-modal-btn">
                <?php esc_html_e('Revisar Fechas', 'eipsi-forms'); ?>
            </button>
        </div>
    </div>
</div>
<div id="eipsi-manual-reminder-modal" class="eipsi-modal" style="display:none;">
    <div class="eipsi-modal-content modal-large">
        <span class="eipsi-close-modal">&times;</span>
        <h3><?php esc_html_e('Enviar Recordatorio Manual', 'eipsi-forms'); ?></h3>
        <p class="description">
            <?php esc_html_e('Selecciona los participantes a los que deseas enviar un recordatorio personalizado.', 'eipsi-forms'); ?>
        </p>
        
        <input type="hidden" id="reminder-wave-id" value="">
        <input type="hidden" id="reminder-study-id" value="<?php echo esc_attr($current_study_id); ?>">
        
        <div class="form-group">
            <label for="reminder-custom-message"><?php esc_html_e('Mensaje personalizado (opcional):', 'eipsi-forms'); ?></label>
            <textarea id="reminder-custom-message" rows="3" placeholder="<?php esc_attr_e('Agrega un mensaje personalizado para los participantes...', 'eipsi-forms'); ?>"></textarea>
            <small class="form-help"><?php esc_html_e('Este mensaje se incluirá en el correo electrónico de recordatorio.', 'eipsi-forms'); ?></small>
        </div>
        
        <div class="reminder-participants-section">
            <div class="selection-actions" style="margin-bottom: 10px;">
                <button type="button" class="button button-small" id="select-all-pending-participants"><?php esc_html_e('Seleccionar Todos', 'eipsi-forms'); ?></button>
                <button type="button" class="button button-small" id="deselect-all-pending-participants"><?php esc_html_e('Deseleccionar Todos', 'eipsi-forms'); ?></button>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="check-column"><input type="checkbox" id="master-pending-participant-check"></th>
                        <th><?php esc_html_e('Nombre Completo', 'eipsi-forms'); ?></th>
                        <th><?php esc_html_e('Email', 'eipsi-forms'); ?></th>
                        <th><?php esc_html_e('Estado', 'eipsi-forms'); ?></th>
                    </tr>
                </thead>
                <tbody id="pending-participants-tbody">
                    <tr>
                        <td colspan="4" style="text-align:center;">
                            <span class="spinner is-active"></span> <?php esc_html_e('Cargando participantes pendientes...', 'eipsi-forms'); ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="modal-footer">
            <button type="button" class="button button-primary" id="confirm-send-reminder-btn">
                <?php esc_html_e('Enviar Recordatorios', 'eipsi-forms'); ?>
            </button>
            <button type="button" class="button eipsi-close-modal-btn">
                <?php esc_html_e('Cancelar', 'eipsi-forms'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Reminder Config Modal -->
<div id="eipsi-reminder-config-modal" class="eipsi-modal" style="display: none;">
    <div class="eipsi-modal-content eipsi-modal-large">
        <span class="eipsi-close-modal">&times;</span>
        <h3><?php esc_html_e('Configuración de Recordatorios', 'eipsi-forms'); ?></h3>
        <p class="description">
            <?php esc_html_e('Personaliza el timing y los mensajes de cada recordatorio automático para esta toma.', 'eipsi-forms'); ?>
        </p>
        
        <input type="hidden" id="config-wave-id" value="">
        
        <div class="eipsi-nudge-config-section">
            <h4><?php esc_html_e('Nudge 0 - Disponibilidad inmediata', 'eipsi-forms'); ?></h4>
            <p class="description"><?php esc_html_e('Se envía automáticamente cuando la toma se hace disponible. No se puede desactivar.', 'eipsi-forms'); ?></p>
            <div class="form-group">
                <label><?php esc_html_e('Asunto del email:', 'eipsi-forms'); ?></label>
                <input type="text" id="nudge-0-subject" value="Tu siguiente evaluación está disponible" class="regular-text">
            </div>
        </div>
        
        <div class="eipsi-nudge-config-section">
            <h4><?php esc_html_e('Nudge 1 - Primer recordatorio', 'eipsi-forms'); ?></h4>
            <div class="form-group">
                <label class="eipsi-checkbox-label">
                    <input type="checkbox" id="nudge-1-enabled" checked>
                    <?php esc_html_e('Activar este recordatorio', 'eipsi-forms'); ?>
                </label>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label><?php esc_html_e('Enviar después de:', 'eipsi-forms'); ?></label>
                    <input type="number" id="nudge-1-hours" value="24" min="1" max="10080">
                    <select id="nudge-1-unit">
                        <option value="minutes"><?php esc_html_e('minutos', 'eipsi-forms'); ?></option>
                        <option value="hours" selected><?php esc_html_e('horas', 'eipsi-forms'); ?></option>
                        <option value="days"><?php esc_html_e('días', 'eipsi-forms'); ?></option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label><?php esc_html_e('Asunto personalizado (opcional):', 'eipsi-forms'); ?></label>
                <input type="text" id="nudge-1-subject" value="" placeholder="Recordatorio amable - {{survey_name}}" class="regular-text">
            </div>
        </div>
        
        <div class="eipsi-nudge-config-section">
            <h4><?php esc_html_e('Nudge 2 - Segundo recordatorio', 'eipsi-forms'); ?></h4>
            <div class="form-group">
                <label class="eipsi-checkbox-label">
                    <input type="checkbox" id="nudge-2-enabled" checked>
                    <?php esc_html_e('Activar este recordatorio', 'eipsi-forms'); ?>
                </label>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label><?php esc_html_e('Enviar después de:', 'eipsi-forms'); ?></label>
                    <input type="number" id="nudge-2-hours" value="48" min="1" max="10080">
                    <select id="nudge-2-unit">
                        <option value="minutes"><?php esc_html_e('minutos', 'eipsi-forms'); ?></option>
                        <option value="hours" selected><?php esc_html_e('horas', 'eipsi-forms'); ?></option>
                        <option value="days"><?php esc_html_e('días', 'eipsi-forms'); ?></option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label><?php esc_html_e('Asunto personalizado (opcional):', 'eipsi-forms'); ?></label>
                <input type="text" id="nudge-2-subject" value="" placeholder="Recordatorio importante - {{survey_name}}" class="regular-text">
            </div>
        </div>
        
        <div class="eipsi-nudge-config-section">
            <h4><?php esc_html_e('Nudge 3 - Tercer recordatorio (urgente)', 'eipsi-forms'); ?></h4>
            <div class="form-group">
                <label class="eipsi-checkbox-label">
                    <input type="checkbox" id="nudge-3-enabled" checked>
                    <?php esc_html_e('Activar este recordatorio', 'eipsi-forms'); ?>
                </label>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label><?php esc_html_e('Enviar después de:', 'eipsi-forms'); ?></label>
                    <input type="number" id="nudge-3-hours" value="72" min="1" max="10080">
                    <select id="nudge-3-unit">
                        <option value="minutes"><?php esc_html_e('minutos', 'eipsi-forms'); ?></option>
                        <option value="hours" selected><?php esc_html_e('horas', 'eipsi-forms'); ?></option>
                        <option value="days"><?php esc_html_e('días', 'eipsi-forms'); ?></option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label><?php esc_html_e('Asunto personalizado (opcional):', 'eipsi-forms'); ?></label>
                <input type="text" id="nudge-3-subject" value="" placeholder="Urgente: Evaluación pendiente - {{survey_name}}" class="regular-text">
            </div>
        </div>
        
        <div class="eipsi-nudge-config-section">
            <h4><?php esc_html_e('Nudge 4 - Último recordatorio', 'eipsi-forms'); ?></h4>
            <div class="form-group">
                <label class="eipsi-checkbox-label">
                    <input type="checkbox" id="nudge-4-enabled" checked>
                    <?php esc_html_e('Activar este recordatorio', 'eipsi-forms'); ?>
                </label>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label><?php esc_html_e('Enviar después de:', 'eipsi-forms'); ?></label>
                    <input type="number" id="nudge-4-hours" value="96" min="1" max="10080">
                    <select id="nudge-4-unit">
                        <option value="minutes"><?php esc_html_e('minutos', 'eipsi-forms'); ?></option>
                        <option value="hours" selected><?php esc_html_e('horas', 'eipsi-forms'); ?></option>
                        <option value="days"><?php esc_html_e('días', 'eipsi-forms'); ?></option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label><?php esc_html_e('Asunto personalizado (opcional):', 'eipsi-forms'); ?></label>
                <input type="text" id="nudge-4-subject" value="" placeholder="ÚLTIMO recordatorio - {{survey_name}}" class="regular-text">
            </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="button button-primary" id="save-reminder-config-btn">
                <?php esc_html_e('Guardar Configuración', 'eipsi-forms'); ?>
            </button>
            <button type="button" class="button eipsi-close-modal-btn">
                <?php esc_html_e('Cancelar', 'eipsi-forms'); ?>
            </button>
            <button type="button" class="button button-link" id="reset-reminder-config-btn">
                <?php esc_html_e('Restaurar valores por defecto', 'eipsi-forms'); ?>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
/* Toggle switch para seguimiento de recordatorios */
.eipsi-reminder-toggle-wrapper {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin: 0 5px;
    padding: 5px 10px;
    background: #f0f0f1;
    border-radius: 4px;
}

.eipsi-toggle-label {
    position: relative;
    display: inline-block;
    width: 40px;
    height: 22px;
    cursor: pointer;
}

.eipsi-toggle-label input {
    opacity: 0;
    width: 0;
    height: 0;
}

.eipsi-toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .3s;
    border-radius: 22px;
}

.eipsi-toggle-slider:before {
    position: absolute;
    content: "";
    height: 16px;
    width: 16px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}

.eipsi-toggle-label input:checked + .eipsi-toggle-slider {
    background-color: #2271b1;
}

.eipsi-toggle-label input:checked + .eipsi-toggle-slider:before {
    transform: translateX(18px);
}

.eipsi-toggle-text {
    font-size: 12px;
    color: #3c434a;
    white-space: nowrap;
}

.eipsi-config-reminders-btn {
    padding: 0 5px !important;
    min-height: auto !important;
    line-height: 1 !important;
}

/* Timeline preview en wave cards */
.eipsi-nudge-timeline {
    margin-top: 15px;
    padding: 10px;
    background: #f6f7f7;
    border-radius: 4px;
    border-left: 3px solid #2271b1;
}

.eipsi-nudge-timeline-title {
    font-size: 11px;
    font-weight: 600;
    color: #3c434a;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.eipsi-nudge-stage {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    margin: 4px 0;
    color: #646970;
}

.eipsi-nudge-stage.active {
    color: #2271b1;
    font-weight: 500;
}

.eipsi-nudge-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #dcdcde;
}

.eipsi-nudge-stage.active .eipsi-nudge-dot {
    background: #2271b1;
}

/* Nudge config modal sections */
.eipsi-nudge-config-section {
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 15px;
    margin-bottom: 15px;
    background: #fff;
}

.eipsi-nudge-config-section h4 {
    margin: 0 0 10px 0;
    font-size: 14px;
    color: #1d2327;
}

.eipsi-nudge-config-section .description {
    font-size: 12px;
    color: #646970;
    margin-bottom: 10px;
}

.eipsi-nudge-config-section .form-row {
    display: flex;
    gap: 10px;
    align-items: center;
}

.eipsi-nudge-config-section input[type="number"] {
    width: 70px;
}

.eipsi-checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-weight: 500;
}

.eipsi-checkbox-label input[type="checkbox"] {
    margin: 0;
}
</style>
