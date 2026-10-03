/**
 * Study Dashboard JS
 * 
 * @since 1.5.2
 */

(function($) {
    'use strict';

    const StudyDashboard = {
        currentStudyId: null,
        autoRefreshInterval: null,
        resendDebounceMap: new Map(),

        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            const self = this;

            // Open dashboard modal
            $('.eipsi-view-study').on('click', function() {
                const studyId = $(this).data('study-id');
                self.openDashboard(studyId);
            });

            // Close modal buttons
            $('.eipsi-modal-close').on('click', function() {
                $(this).closest('.eipsi-modal').fadeOut();
                if ($(this).closest('#eipsi-study-dashboard-modal').length) {
                    self.stopAutoRefresh();
                }
            });

            // Close modal on ESC key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    const openModal = $('.eipsi-modal:visible');
                    if (openModal.length) {
                        openModal.fadeOut();
                        if (openModal.is('#eipsi-study-dashboard-modal')) {
                            self.stopAutoRefresh();
                        }
                    }
                }
            });

            // Close modal when clicking outside (on overlay)
            $(document).on('click', '.eipsi-modal', function(e) {
                if (e.target === this) {
                    $(this).fadeOut();
                    if ($(this).is('#eipsi-study-dashboard-modal')) {
                        self.stopAutoRefresh();
                    }
                }
            });

            // Refresh button
            $('#refresh-dashboard').off('click').on('click', function() {
                console.log('[BUTTON] refresh-dashboard clicked, studyId:', self.currentStudyId);
                self.loadStudyData(self.currentStudyId);
            });

            // Email logs
            $('#view-email-logs').off('click').on('click', function() {
                console.log('[BUTTON] view-email-logs clicked');
                self.openEmailLogs();
            });

            // Send global reminder
            $('#send-global-reminder').off('click').on('click', function() {
                console.log('[BUTTON] send-global-reminder clicked');
                self.sendGlobalReminder();
            });

            // Import CSV
            $('#action-import-csv').off('click').on('click', function() {
                console.log('[BUTTON] action-import-csv clicked');
                $('#eipsi-csv-import-modal').fadeIn(200);
            });

            // Event delegation for wave card interactions (replaces inline onclick)
            $('#waves-container').off('click').on('click', '[data-action]', function(e) {
                const $btn = $(this);
                const action = $btn.data('action');
                const waveId = $btn.closest('.wave-card').data('wave-id');
                
                console.log('[WAVE-ACTION]', action, 'waveId:', waveId);
                
                switch(action) {
                    case 'toggle-deadline':
                        self.toggleDeadlineEditor(waveId);
                        break;
                    case 'save-deadline':
                        self.saveDeadline(waveId, $btn.closest('.deadline-editor'));
                        break;
                    case 'remove-deadline':
                        self.removeDeadline(waveId);
                        break;
                    case 'toggle-nudge':
                        self.toggleNudgePanel(waveId);
                        break;
                    case 'save-nudge':
                        self.saveNudgeConfig(waveId);
                        break;
                    case 'cancel-deadline':
                        self.toggleDeadlineEditor(waveId);
                        break;
                    case 'cancel-nudge':
                        self.toggleNudgePanel(waveId);
                        break;
                    case 'send-wave-reminder':
                        self.sendReminder(waveId);
                        break;
                }
            });

            // Redistribuir nudges manualmente
            $('#waves-container').on('click', '.btn-redistribute-nudges', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const waveId = $(this).data('wave-id');
                console.log('[REDISTRIBUTE] Redistributing nudges for wave:', waveId);
                self.redistributeNudges(waveId);
            });

            // Toggle checkbox for nudges - using event delegation
            // v2.5.1 - Manejar click en el checkbox para evitar propagación a la barra
            $('#waves-container').on('click', '.nudge-toggle-input', function(e) {
                e.stopPropagation(); // Evitar que el click llegue a la barra
                const waveId = $(this).closest('.wave-card').data('wave-id');
                const isChecked = $(this).is(':checked');
                const checkboxId = $(this).attr('id');
                
                console.log('[NUDGE-CLICK] ========================================');
                console.log('[NUDGE-CLICK] Checkbox clicked, waveId:', waveId, 'checkboxId:', checkboxId);
                console.log('[NUDGE-CLICK] Current checked state (BEFORE click processed):', !isChecked);
                console.log('[NUDGE-CLICK] Will be checked state (AFTER click processed):', isChecked);
                console.log('[NUDGE-CLICK] Event propagation stopped:', e.isPropagationStopped());
                
                // El change event ya actualizará la UI, solo manejamos el panel aquí
                const $card = $(`#wave-card-${waveId}`);
                const $panel = $card.find('.nudge-panel');
                
                if (isChecked) {
                    // Checkbox encendido -> abrir panel
                    console.log('[NUDGE-CLICK] Opening panel');
                    self.stopAutoRefresh();
                    $panel.slideDown(200);
                } else {
                    // Checkbox apagado -> cerrar panel
                    console.log('[NUDGE-CLICK] Closing panel');
                    $panel.slideUp(200, function() {
                        if (!self.isAnyNudgePanelOpen()) {
                            self.startAutoRefresh();
                        }
                    });
                }
            });
            
            // Toggle checkbox for nudges - using event delegation
            // v2.5.1 - Solo actualizar UI cuando cambia el estado
            $('#waves-container').on('change', '.nudge-toggle-input', function() {
                const waveId = $(this).closest('.wave-card').data('wave-id');
                const isChecked = $(this).is(':checked');
                const $row = $(this).closest('.nudge-toggle-row');
                const $label = $(this).closest('.wave-card').find('.nudge-lbl');
                
                console.log('[NUDGE-CHANGE] ========================================');
                console.log('[NUDGE-CHANGE] waveId:', waveId, 'enabled:', isChecked);
                console.log('[NUDGE-CHANGE] Row found:', $row.length, 'Label found:', $label.length);
                
                // Update ARIA attribute
                $row.attr('aria-checked', isChecked);
                
                // Check if wave has deadline (for redistribute button)
                const $card = $(this).closest('.wave-card');
                const $deadlineVal = $card.find('.deadline-val');
                const hasDeadline = $deadlineVal.length > 0 && !$deadlineVal.hasClass('none');
                
                // Build redistribute button if needed
                const redistributeBtn = hasDeadline 
                    ? ' <button class="btn-redistribute-nudges" data-wave-id="' + waveId + '" style="background:none;border:none;cursor:pointer;font-size:14px;padding:0 4px;vertical-align:middle;" title="Redistribuir nudges a la ventana actual">🔁</button>'
                    : '';
                
                // Update label text preserving redistribute button
                if (isChecked) {
                    $label.addClass('on').html('Recordatorios activados · 4 nudges' + redistributeBtn);
                } else {
                    $label.removeClass('on').text('Recordatorios desactivados');
                }
            });

            // Keyboard support for nudge toggle row (Enter/Space)
            $('#waves-container').on('keydown', '.nudge-toggle-row', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    $(this).find('.nudge-toggle-input').trigger('click');
                }
            });

            // Live update of minutes translation with Zero-Hide
            $('#waves-container').on('input', '.nudge-minutes-input', function() {
                const $input = $(this);
                const minutes = parseInt($input.val()) || 0;
                const waveId = $input.closest('.wave-card').data('wave-id');
                const nudgeIndex = $input.closest('.nudge-row').data('nudge-index');
                const $translation = $('#ntrans-' + waveId + '-' + nudgeIndex);
                
                // Zero-Hide: clear translation if value is 0
                if ($translation.length) {
                    $translation.text(self.formatMinutes(minutes));
                }
                
                // T1-Anchor: Visual warning for nudge >= window
                const $card = $input.closest('.wave-card');
                const windowMinutes = parseInt($card.find('.wave-window-input').val()) || 0;
                const $warning = $input.closest('.nudge-row').find('.nudge-warning');
                
                if (minutes > 0 && windowMinutes > 0 && minutes >= windowMinutes) {
                    if (!$warning.length) {
                        $input.closest('.nudge-row').append('<span class="nudge-warning" style="color:#d32f2f;font-size:11px;margin-left:8px;">⚠️ El nudge debe ser menor que la ventana</span>');
                    }
                } else {
                    $warning.remove();
                }
            });
            
            // T1-Anchor: Window input reactivity (20/40/60/80%)
            $('#waves-container').on('input', '.wave-window-input', function() {
                const $input = $(this);
                const windowMinutes = parseInt($input.val()) || 0;
                const $card = $input.closest('.wave-card');
                const waveId = $card.data('wave-id');
                
                // Remove "Auto" badge when user enters a value
                if ($input.val() && $input.val() !== '') {
                    $input.siblings('.timing-badge').remove();
                }
                
                if (windowMinutes > 0) {
                    // Calculate proportional nudges: 20%, 40%, 60%, 80%
                    const proportions = [0.2, 0.4, 0.6, 0.8];
                    $card.find('.nudge-minutes-input').each(function(index) {
                        if (index < 4) {
                            const nudgeValue = Math.round(windowMinutes * proportions[index]);
                            $(this).val(nudgeValue).trigger('change');
                            // Update translation
                            const nudgeIndex = $(this).closest('.nudge-row').data('nudge-index');
                            $('#ntrans-' + waveId + '-' + nudgeIndex).text(self.formatMinutes(nudgeValue));
                        }
                    });
                }
            });
            
            // T1-Anchor: Offset input translation
            $('#waves-container').on('input', '.wave-offset-input', function() {
                const $input = $(this);
                const minutes = parseInt($input.val()) || 0;
                const waveId = $input.closest('.wave-card').data('wave-id');
                const $translation = $('#offset-trans-' + waveId);
                
                if ($translation.length) {
                    $translation.text(self.formatMinutes(minutes));
                }
            });

            // Download data
            $('#action-download-data').off('click').on('click', function() {
                console.log('[BUTTON] action-download-data clicked, studyId:', self.currentStudyId);
                if (self.currentStudyId) {
                    window.location.href = 'admin.php?page=eipsi-longitudinal-study&tab=export&study_id=' + self.currentStudyId;
                }
            });

            // Copy shortcode
            $('#copy-shortcode').off('click').on('click', function() {
                const $btn = $(this);
                const text = $('#shortcode-display').text();
                console.log('[BUTTON] copy-shortcode clicked, text:', text);
                navigator.clipboard.writeText(text).then(function() {
                    // v2.5.4 - Toast notification + button feedback
                    if (window.StudyDashboard && window.StudyDashboard.showNotification) {
                        window.StudyDashboard.showNotification('Shortcode copiado al portapapeles', 'success');
                    }
                    // Button turns green with checkmark
                    $btn.css({background: '#22c55e', color: '#fff'}).text('✓ Copiado');
                    setTimeout(function() {
                        $btn.css({background: '', color: ''}).text('Copiar');
                    }, 2000);
                });
            });

            // Copy page URL
            $('#copy-page-url').off('click').on('click', function() {
                const $btn = $(this);
                const text = $('#study-page-url').val();
                console.log('[BUTTON] copy-page-url clicked, text:', text);
                navigator.clipboard.writeText(text).then(function() {
                    // v2.5.4 - Toast notification + button feedback
                    if (window.StudyDashboard && window.StudyDashboard.showNotification) {
                        window.StudyDashboard.showNotification('URL copiada al portapapeles', 'success');
                    }
                    // Button turns green with checkmark
                    $btn.css({background: '#22c55e', color: '#fff'}).text('✓ Copiado');
                    setTimeout(function() {
                        $btn.css({background: '', color: ''}).text('Copiar');
                    }, 2000);
                });
            });

            // View study page
            $('#view-study-page').off('click').on('click', function() {
                const url = $('#study-page-url').val();
                console.log('[BUTTON] view-study-page clicked, url:', url);
                if (url) window.open(url, '_blank');
            });

            // Edit study page
            $('#edit-study-page').off('click').on('click', function() {
                console.log('[BUTTON] edit-study-page clicked');
                alert('Función editar página no implementada aún');
            });

            // Manual reminder
            $(document).on('click', '.send-reminder', function() {
                const waveId = $(this).data('wave-id');
                self.sendReminder(waveId);
            });

            // Extend deadline
            $(document).on('click', '.extend-deadline', function() {
                const waveId = $(this).data('wave-id');
                const currentDeadline = $(this).data('deadline');
                self.openExtendDeadline(waveId, currentDeadline);
            });

            // Save extended deadline
            $('#extend-deadline-form').on('submit', function(e) {
                e.preventDefault();
                self.saveExtendedDeadline();
            });

            // Add participant button - .off() prevents duplicate handlers
            $('#action-add-participant').off('click').on('click', function() {
                console.log('[BUTTON] action-add-participant clicked');
                self.openAddParticipant();
            });

            // Delete study button - .off() prevents duplicate handlers
            $('#action-delete-study').off('click').on('click', function() {
                console.log('[BUTTON] action-delete-study clicked, studyId:', self.currentStudyId);
                if (!self.currentStudyId) {
                    console.error('[ERROR] No currentStudyId defined');
                    return;
                }
                if (confirm('⚠️ ESTA ACCIÓN ES IRREVERSIBLE ⚠️\n\n¿Estás seguro de ELIMINAR este estudio?\n\n• Se eliminarán TODOS los participantes\n• Se eliminarán TODAS las respuestas\n• Se eliminarán TODOS los waves\n• Se eliminarán TODOS los emails\n\nEsta acción NO se puede deshacer.\n\nPresiona OK para confirmar.')) {
                    if (confirm('⚠️ ÚLTIMA ADVERTENCIA\n\nEl estudio será eliminado PERMANENTEMENTE.\n\n¿Estás COMPLETAMENTE SEGURO?')) {
                        self.deleteStudy(self.currentStudyId);
                    }
                }
            });

            // Add participant form submit
            $('#add-participant-form').on('submit', function(e) {
                e.preventDefault();
                self.addParticipant();
            });

            // v2.5.1 - Add click handler for submit button (outside form)
            $(document).on('click', '#submit-add-participant', function(e) {
                e.preventDefault();
                console.log('[BUTTON] submit-add-participant clicked');
                self.addParticipant();
            });

            // v2.5.1 - Add click handler for cancel button
            $(document).on('click', '#cancel-add-participant', function(e) {
                e.preventDefault();
                console.log('[BUTTON] cancel-add-participant clicked');
                $('#add-participant-modal').fadeOut(200);
            });

            // View participants
            $('#action-view-participants').off('click').on('click', function() {
                console.log('[BUTTON] action-view-participants clicked');
                self.openParticipantsList();
            });

            // Pause/Resume study buttons - v2.5.3 fix: add missing click handlers
            $('#btn-pause-study').off('click').on('click', function() {
                console.log('[BUTTON] btn-pause-study clicked');
                self.pauseStudy();
            });

            $('#btn-resume-study').off('click').on('click', function() {
                console.log('[BUTTON] btn-resume-study clicked');
                self.resumeStudy();
            });

            let searchTimeout;
            $('#participant-search').on('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    self.loadParticipantsList(1);
                }, 300);
            });

            // Toggle participant status
            $(document).on('click', '.toggle-participant-status', function() {
                const participantId = $(this).data('participant-id');
                const isActive = $(this).data('is-active');
                console.log('[BUTTON] toggle-participant-status clicked, participantId:', participantId, 'isActive:', isActive);
                self.toggleParticipantStatus(participantId, isActive);
            });

            // Delete participant
            $(document).on('click', '.delete-participant-btn', function() {
                const participantId = $(this).data('participant-id');
                console.log('[BUTTON] delete-participant-btn clicked, participantId:', participantId);
                self.deleteParticipant(participantId);
            });

            // Magic Link button click
            $(document).on('click', '.resend-magic-link-btn', function(e) {
                e.stopPropagation();
                const participantId = $(this).data('participant-id');
                const participantEmail = $(this).data('participant-email');
                console.log('[BUTTON] Magic Link clicked, participantId:', participantId, 'email:', participantEmail);
                self.resendEmail(participantId, 'magic_link', participantEmail, null);
            });

            // Reminder button click
            $(document).on('click', '.resend-reminder-btn', function(e) {
                e.stopPropagation();
                const participantId = $(this).data('participant-id');
                const participantEmail = $(this).data('participant-email');
                console.log('[BUTTON] Reminder clicked, participantId:', participantId, 'email:', participantEmail);
                self.resendEmail(participantId, 'reminder', participantEmail, null);
            });
        },

        /**
         * Open dashboard modal and load study data
         */
        openDashboard: function(studyId) {
            console.log('[FUNC] openDashboard called, studyId:', studyId);
            this.currentStudyId = studyId;
            $('#eipsi-study-dashboard-modal').fadeIn(200);
            this.loadStudyData(studyId);
            this.startAutoRefresh();
        },

        /**
         * Load study data via AJAX
         */
        loadStudyData: function(studyId) {
            console.log('[EIPSI DASHBOARD] Iniciando carga del dashboard');
            console.log('[EIPSI DASHBOARD] Study ID:', studyId);
            const self = this;

            if (!studyId) {
                console.error('[ERROR] loadStudyData: studyId is null or undefined');
                return;
            }
            
            // Update currentStudyId
            this.currentStudyId = studyId;

            $.ajax({
                url: eipsiStudyDash.ajaxUrl,
                type: 'GET',
                data: {
                    action: 'eipsi_get_study_overview',
                    study_id: studyId,
                    nonce: eipsiStudyDash.nonce
                },
                timeout: 30000, // 30 seconds timeout
                success: function(response) {
                    console.log('[EIPSI DASHBOARD] API response:', JSON.stringify(response, null, 2));
                    if (response.success) {
                        const data = response.data;
                        console.log('[EIPSI DASHBOARD] Waves recibidas:', data.waves ? data.waves.length : 'none');
                        if (data.waves) {
                            data.waves.forEach(function(w, i) {
                                console.log('[EIPSI DASHBOARD] Wave ' + i + ':', {
                                    id: w.id,
                                    name: w.name,
                                    total: w.total,
                                    completed: w.completed,
                                    due_date: w.due_date || w.deadline || 'none',
                                    nudge_config: w.nudge_config || 'none',
                                    interval_days: w.interval_days,
                                    time_unit: w.time_unit
                                });
                            });
                        }
                        self.renderDashboard(data);
                    } else {
                        console.error('[LOAD] Server error:', response.data);
                        self.showToast('Error al cargar datos del estudio', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[LOAD] AJAX error:', status, error);
                    if (status === 'timeout') {
                        self.showToast('⏱️ El servidor está tardando demasiado. Intentá de nuevo.', 'error');
                    } else if (xhr.status === 504) {
                        self.showToast('⚠️ Error 504: Gateway timeout. El servidor está sobrecargado.', 'error');
                    } else {
                        self.showToast('Error de conexión al cargar datos', 'error');
                    }
                }
            });
        },

        /**
         * Render dashboard with study data
         */
        renderDashboard: function(data) {
            console.log('[FUNC] renderDashboard called, data:', data);
            const general = data.general;
            const participants = data.participants;
            const waves = data.waves;
            const emails = data.emails;
            const page = data.page;

            // Header info
            const studyDisplayId = general.study_code || general.id;
            $('#study-name-display').text(general.study_name || 'Estudio sin nombre');
            $('#study-status-pill').text(general.status === 'active' ? 'Activo' : general.status === 'paused' ? 'Pausado' : 'Cerrado');
            $('#study-status-pill').attr('class', 'pill pill-' + (general.status === 'active' ? 'active' : general.status === 'paused' ? 'paused' : 'closed'));

            const created = new Date(general.created_at);
            const months = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
            $('#study-meta-display').text(
                'Creado ' + created.getDate() + ' ' + months[created.getMonth()] + ' ' + created.getFullYear() +
                ' · ID: ' + general.id +
                ' · ' + waves.length + ' tomas' +
                ' · ' + participants.total + ' participantes'
            );

            // Shortcode
            const shortcode = page && page.shortcode ? page.shortcode : '[eipsi_longitudinal_study study_code="' + studyDisplayId + '"]';
            $('#shortcode-display').text(shortcode);

            // Page URL
            if (page && page.url) {
                $('#study-page-url').val(page.url);
                $('#view-study-page, #edit-study-page').show();
            } else {
                $('#study-page-url').val('');
                $('#view-study-page, #edit-study-page').hide();
            }

            // KPIs
            $('#kpi-total').text(participants.total || 0);
            $('#kpi-active').text(participants.active || 0);
            $('#kpi-completed').text(participants.completed || 0);
            $('#kpi-emails').text(emails.sent_today || 0);

            // Control buttons visibility
            if (general.status === 'active') {
                $('#btn-pause-study').show();
                $('#btn-resume-study').hide();
            } else if (general.status === 'paused') {
                $('#btn-pause-study').hide();
                $('#btn-resume-study').show();
            } else {
                $('#btn-pause-study, #btn-resume-study').hide();
            }

            // Set study_id for recalculate button
            $('#action-recalculate-times').data('study-id', general.id);

            // Emails stats
            $('#emails-today').text(emails.sent_today || 0);
            $('#emails-failed').text(emails.failed || 0);
            $('#emails-pending').text(emails.pending || 0);

            // Render waves
            this.renderWaves(waves);
        },

        /**
         * Get interval text for wave subtitle
         */
        getIntervaloTexto: function(wave, index) {
            if (index === 0) return 'Toma inicial · disponible desde el registro';
            var valor = wave.interval_days || wave.interval_value || 0;
            var unidad = wave.time_unit === 'minutes' ? 'minutos' : 'días';
            if (valor === 0 || !valor) return 'Sin intervalo definido';
            return valor + ' ' + unidad + ' después de completar T' + index;
        },

        /**
         * Get progress bar CSS classes based on percentage
         */
        getProgressClass: function(pct) {
            if (pct === 100) return { fill: 'fill-green', label: 'lbl-green' };
            if (pct === 0)   return { fill: 'fill-gray',  label: 'lbl-muted' };
            return { fill: 'fill-blue', label: '' };
        },

        /**
         * Format minutes into human-readable combination
         * Muestra desglose completo: días, horas y minutos
         * Ej: 4833 min → "3 días 8 h 33 min"
         */
        formatMinutes: function(minutes) {
            minutes = parseInt(minutes) || 0;
            // Zero-Hide: return empty string for 0
            if (minutes === 0) return '';
            
            const days = Math.floor(minutes / 1440);
            const hours = Math.floor((minutes % 1440) / 60);
            const mins = minutes % 60;
            
            const parts = [];
            if (days > 0) parts.push(days + ' día' + (days > 1 ? 's' : ''));
            if (hours > 0) parts.push(hours + ' h');
            if (mins > 0) parts.push(mins + ' min');
            
            return parts.join(' ') || '';
        },

        /**
         * Calculate effective window for a wave when window_minutes is null
         * T1-Anchor: window = offset_next - offset_current
         */
        calculateEffectiveWindow: function(wave, index, waves) {
            if (wave.window_minutes !== null && wave.window_minutes !== undefined && wave.window_minutes !== '') {
                return wave.window_minutes;
            }
            // If null, calculate from next wave's offset
            if (index < waves.length - 1 && waves[index + 1]) {
                const nextOffset = waves[index + 1].offset_minutes || 0;
                const currentOffset = wave.offset_minutes || 0;
                return nextOffset - currentOffset;
            }
            // Last wave: use study_end_offset_minutes if available
            const studyEndOffset = this.currentStudy?.study_end_offset_minutes || 0;
            const currentOffset = wave.offset_minutes || 0;
            if (studyEndOffset > currentOffset) {
                return studyEndOffset - currentOffset;
            }
            return ''; // Fallback
        },

        /**
         * Render waves cards - FULL MOCKUP VERSION
         */
        renderWaves: function(waves) {
            console.log('[EIPSI DASHBOARD] Renderizando wave cards, count:', waves ? waves.length : 0);
            const container = $('#waves-container');
            container.empty();

            if (!waves || waves.length === 0) {
                container.html('<p style="color:#666;padding:20px;">No hay tomas configuradas</p>');
                return;
            }

            // T1-Anchor: Store reference for calculating effective windows
            this.currentWaves = waves;
            
            const self = this;
            waves.forEach(function(wave, index) {
                const waveNum = index + 1;
                const waveIdx = 'T' + waveNum;
                const progress = wave.progress || 0;
                const completed = wave.completed || 0;
                const total = wave.total || 0;
                const isActive = wave.status === 'active';
                // Use pending from API (logically calculated: eligible - completed)
                const pendientes = (typeof wave.pending !== 'undefined') ? wave.pending : Math.max(0, total - completed);
                
                console.log('[EIPSI DASHBOARD] Renderizando wave card:', wave.wave_name || 'Toma ' + waveNum, 
                    '- elegibles:', total, '- completados:', completed, '- pendientes:', pendientes);
                
                // Wave name and subtitle
                const waveName = wave.wave_name || wave.name || 'Toma ' + waveNum;
                const waveSub = self.getIntervaloTexto(wave, index);
                
                // Progress classes
                const progClasses = self.getProgressClass(progress);
                
                // Deadline
                const hasDeadline = wave.due_date || wave.deadline;
                const deadlineDate = hasDeadline ? new Date(hasDeadline) : null;
                const deadlineFormatted = deadlineDate 
                    ? deadlineDate.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' })
                    : 'Sin fecha límite';
                
                // Nudges - v2.5.0: Activados por defecto
                console.log('[RENDER] Wave', wave.id, '- follow_up_reminders_enabled raw:', wave.follow_up_reminders_enabled, 'type:', typeof wave.follow_up_reminders_enabled);
                const nudgesEnabled = wave.follow_up_reminders_enabled !== undefined ? wave.follow_up_reminders_enabled : true;
                console.log('[RENDER] Wave', wave.id, '- nudgesEnabled (final):', nudgesEnabled);
                const nudgeCount = wave.nudge_count || 4;
                const nudgeLblClass = nudgesEnabled ? 'on' : '';
                
                // Botón de redistribución solo si hay deadline (ventana dinámica)
                const waveHasDeadline = wave.has_due_date || wave.deadline;
                const redistributeBtn = waveHasDeadline 
                    ? ' <button class="btn-redistribute-nudges" data-wave-id="' + wave.id + '" style="background:none;border:none;cursor:pointer;font-size:14px;padding:0 4px;vertical-align:middle;" title="Redistribuir nudges a la ventana actual">🔁</button>'
                    : '';
                
                const nudgeLblText = nudgesEnabled 
                    ? 'Recordatorios activados · ' + nudgeCount + ' nudges' + redistributeBtn
                    : 'Recordatorios desactivados';
                
                // Generate nudge rows HTML - ahora en minutos con traducción automática
                const nudgeConfig = wave.nudge_config || wave.nudges || {};
                const windowMinutes = wave.window_minutes || 0;
                
                // Collect all nudge values
                let nudgeValues = [];
                for (let i = 1; i <= 4; i++) {
                    const nudgeKey = 'nudge_' + i;
                    const nudgeData = nudgeConfig[nudgeKey];
                    // Convertir valores legacy a minutos para display
                    let minutes;
                    if (nudgeData) {
                        minutes = Math.round(nudgeData.unit === 'days' ? nudgeData.value * 1440 : nudgeData.value * 60);
                    } else {
                        // Defaults en minutos: 24h=1440, 72h=4320, 168h=10080, 336h=20160
                        minutes = i === 1 ? 1440 : i === 2 ? 4320 : i === 3 ? 10080 : 20160;
                    }
                    nudgeValues.push(minutes);
                }
                
                // Auto-redistribute if nudges exceed window
                if (windowMinutes > 0) {
                    const totalNudges = nudgeValues.reduce((sum, val) => sum + val, 0);
                    if (totalNudges > windowMinutes) {
                        // Redistribute proportionally within 90% of window
                        const usableWindow = windowMinutes * 0.9;
                        const scaleFactor = usableWindow / totalNudges;
                        nudgeValues = nudgeValues.map(val => Math.round(val * scaleFactor));
                    }
                }
                
                // Build HTML with redistributed values
                let nudgeRowsHtml = '';
                for (let i = 0; i < 4; i++) {
                    const minutes = nudgeValues[i];
                    const timeLabel = self.formatMinutes(minutes);
                    nudgeRowsHtml += 
                        '<div class="nudge-row" data-nudge-index="' + (i + 1) + '">' +
                            '<span class="nudge-num">' + (i + 1) + '</span>' +
                            '<input type="number" value="' + minutes + '" id="nudge-' + wave.id + '-' + (i + 1) + '-val" min="1" class="nudge-minutes-input">' +
                            '<span class="nudge-unit-label">minutos</span>' +
                            '<span class="nudge-translation" id="ntrans-' + wave.id + '-' + (i + 1) + '">' + timeLabel + '</span>' +
                            '<span class="nudge-suffix">después del anterior</span>' +
                        '</div>';
                }

                // Accessibility attributes
                const ariaProgress = 'role="progressbar" aria-valuenow="' + progress + '" aria-valuemin="0" aria-valuemax="100"';
                const ariaSwitch = 'role="switch" aria-checked="' + (nudgesEnabled ? 'true' : 'false') + '"';
                
                const html = 
                    '<div class="wave-card" id="wave-card-' + wave.id + '" data-wave-id="' + wave.id + '" role="region" aria-label="Toma ' + waveNum + ': ' + waveName + '">' +
                        '<div class="wave-card-head">' +
                            '<div class="wave-left">' +
                                '<span class="wave-idx">' + waveIdx + '</span>' +
                                '<div>' +
                                    '<div class="wave-name">' + waveName + '</div>' +
                                    '<div class="wave-sub">' + waveSub + '</div>' +
                                '</div>' +
                            '</div>' +
                            '<div class="wave-right">' +
                                '<span class="pill ' + (isActive ? 'pill-active' : 'pill-paused') + '">' + (isActive ? 'Activo' : 'Pausado') + '</span>' +
                            '</div>' +
                        '</div>' +
                        '<div class="wave-body">' +
                            '<div class="prog-row" ' + ariaProgress + '>' +
                                '<div class="prog-track">' +
                                    '<div class="prog-fill ' + progClasses.fill + '" style="width:' + Math.max(progress, 8) + '%"></div>' +
                                '</div>' +
                                '<span class="prog-lbl ' + progClasses.label + '">' + completed + '/' + total + ' · ' + progress + '%</span>' +
                            '</div>' +
                            '<div class="deadline-section">' +
                                '<div class="deadline-row">' +
                                    '<span>Plazo:</span>' +
                                    '<span class="deadline-val ' + (hasDeadline ? '' : 'none') + '" id="dv-' + wave.id + '">' + deadlineFormatted + '</span>' +
                                    '<button class="btn-link" data-action="toggle-deadline" data-wave-id="' + wave.id + '">' + (hasDeadline ? 'Cambiar' : 'Asignar plazo') + '</button>' +
                                    (hasDeadline ? '<button class="btn-link btn-link-red" data-action="remove-deadline" data-wave-id="' + wave.id + '">Quitar</button>' : '') +
                                '</div>' +
                                '<div class="deadline-editor" id="de-' + wave.id + '" style="display:none;">' +
                                    '<div class="de-label">Fecha límite para completar esta toma</div>' +
                                    '<div class="de-row">' +
                                        '<input type="date" class="deadline-date-input" id="de-input-' + wave.id + '" value="' + ((wave.due_date || wave.deadline || '').split(' ')[0]) + '">' +
                                        '<button class="btn-save" data-action="save-deadline" data-wave-id="' + wave.id + '">Guardar</button>' +
                                        '<button class="btn-cancel-sm" data-action="cancel-deadline" data-wave-id="' + wave.id + '">Cancelar</button>' +
                                    '</div>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                        // T1-Anchor: Timing info (read-only, configured in wizard)
                        '<div class="timing-info" style="padding:10px 16px;background:#f8f9fa;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;">' +
                            '<div style="display:flex;gap:20px;flex-wrap:wrap;">' +
                                '<div><strong style="color:#2c3e50;">📅 Disponibilidad:</strong> ' + 
                                (waveNum === 1 
                                    ? 'Inmediata (T1)' 
                                    : (wave.absolute_available_at_formatted 
                                        ? 'Desde ' + wave.absolute_available_at_formatted
                                        : self.formatMinutes(wave.offset_minutes || 0) + ' desde T1')) + 
                                '</div>' +
                                (wave.t1_dynamic_window_days 
                                    ? '<div><strong style="color:#2c3e50;">⏱️ Tiempo para responder:</strong> ' + wave.t1_dynamic_window_days + ' días</div>'
                                    : (wave.window_minutes ? '<div><strong style="color:#2c3e50;">⏱️ Tiempo para responder:</strong> ' + self.formatMinutes(wave.window_minutes) + '</div>' : '<div><strong style="color:#2c3e50;">⏱️ Tiempo para responder:</strong> Sin límite</div>')) +
                            '</div>' +
                        '</div>' +
                        '<div class="nudge-section">' +
                            '<div class="nudge-toggle-row" data-action="toggle-nudge" data-wave-id="' + wave.id + '" ' + ariaSwitch + ' tabindex="0">' +
                                '<span class="nudge-lbl ' + nudgeLblClass + '" id="nl-' + wave.id + '">' + nudgeLblText + '</span>' +
                                '<label class="toggle" onclick="event.stopPropagation()">' +
                                    '<input type="checkbox" class="nudge-toggle-input" id="nt-' + wave.id + '" ' + (nudgesEnabled ? 'checked' : '') + '>' +
                                    '<span class="tslider"></span>' +
                                '</label>' +
                            '</div>' +
                            '<div class="nudge-panel ' + (nudgesEnabled ? 'open' : '') + '" id="np-' + wave.id + '" style="display:' + (nudgesEnabled ? 'block' : 'none') + '">' +
                                '<div class="nudge-rows" id="nr-' + wave.id + '">' + nudgeRowsHtml + '</div>' +
                                '<div class="nudge-footer">' +
                                    '<button class="btn-cancel-sm" data-action="cancel-nudge" data-wave-id="' + wave.id + '">Cancelar</button>' +
                                    '<button class="btn-save" data-action="save-nudge" data-wave-id="' + wave.id + '">Guardar</button>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                        (pendientes > 0 ? 
                        '<div class="wave-reminder-bar">' +
                            '<button class="btn-primary" data-action="send-wave-reminder" data-wave-id="' + wave.id + '">' +
                                'Enviar recordatorio a pendientes (' + pendientes + ')' +
                            '</button>' +
                        '</div>' : '') +
                    '</div>';
                container.append(html);
            });
            
            // Initialize nudge panel visibility based on checkbox state
            console.log('[EIPSI DASHBOARD] Inicializando nudge panels...');
            this.initializeNudgePanels();
        },
        
        /**
         * Initialize nudge panel visibility after rendering
         */
        initializeNudgePanels: function() {
            console.log('[EIPSI DASHBOARD] Inicializando visibilidad de nudge panels...');
            var count = 0;
            document.querySelectorAll('.nudge-toggle-input').forEach(function(cb) {
                var waveId = cb.id ? cb.id.replace('nt-', '') : 'unknown';
                var panel = document.getElementById('np-' + waveId);
                var lbl = document.getElementById('nl-' + waveId);
                
                console.log('[EIPSI INIT] Wave', waveId, '- checked:', cb.checked, 'panel exists:', !!panel);
                
                if (cb.checked && panel) {
                    panel.style.display = 'block';
                    panel.classList.add('open');
                } else if (panel) {
                    panel.style.display = 'none';
                    panel.classList.remove('open');
                }
                
                if (lbl) {
                    lbl.classList.toggle('on', cb.checked);
                }
                count++;
            });
            console.log('[EIPSI DASHBOARD] Nudge panels inicializados:', count);
        },

        /**
         * Toggle deadline editor visibility
         */
        toggleDeadlineEditor: function(waveId) {
            console.log('[FUNC] toggleDeadlineEditor called, waveId:', waveId);
            const $editor = $(`#de-${waveId}`);
            console.log('[DEADLINE] Editor found:', $editor.length > 0);
            console.log('[DEADLINE] Editor current display:', $editor.css('display'));
            const isVisible = $editor.is(':visible');
            
            if (isVisible) {
                $editor.slideUp(200);
            } else {
                // Close all other editors first
                $('.deadline-editor').slideUp(200);
                $editor.slideDown(200);
            }
        },

        /**
         * Save deadline for a wave
         */
        saveDeadline: function(waveId, $editor) {
            console.log('[FUNC] saveDeadline called, waveId:', waveId);
            const date = $editor.find(`#de-input-${waveId}`).val();
            
            console.log('[DEADLINE] Date selected:', date);
            
            if (!date) {
                console.error('[DEADLINE] No date provided');
                this.showToast('Por favor seleccioná una fecha', 'error');
                return;
            }

            const self = this;
            const ajaxData = {
                action: 'eipsi_extend_wave_deadline',
                wave_id: waveId,
                deadline_date: date,
                nonce: eipsiStudyDash.nonce
            };
            
            console.log('[DEADLINE] Sending AJAX with date:', date);
            console.log('[DEADLINE] AJAX URL:', eipsiStudyDash.ajaxUrl);
            console.log('[DEADLINE] Nonce available:', !!eipsiStudyDash.nonce);
            console.log('[DEADLINE] Full AJAX data:', ajaxData);
            
            $.ajax({
                url: eipsiStudyDash.ajaxUrl,
                type: 'POST',
                data: ajaxData,
                timeout: 20000,
                success: function(response) {
                    console.log('[DEADLINE] AJAX response:', response);
                    if (response.success) {
                        console.log('[DEADLINE] Saved successfully');
                        self.showToast('Plazo guardado correctamente', 'success');
                        if (self.currentStudyId) {
                            self.loadStudyData(self.currentStudyId);
                        }
                    } else {
                        console.error('[DEADLINE] Save failed:', response.data);
                        self.showToast('Error: ' + (response.data?.message || response.data || 'No se pudo guardar'), 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[DEADLINE] AJAX error:', status, error);
                    console.error('[DEADLINE] XHR status:', xhr.status);
                    console.error('[DEADLINE] XHR statusText:', xhr.statusText);
                    console.error('[DEADLINE] XHR responseText:', xhr.responseText);
                    if (status === 'timeout' || xhr.status === 504) {
                        self.showToast('⏱️ Timeout al guardar plazo. Intentá de nuevo.', 'error');
                    } else {
                        self.showToast('Error al guardar plazo', 'error');
                    }
                }
            });
        },

        /**
         * Remove deadline from a wave
         */
        removeDeadline: function(waveId) {
            console.log('[FUNC] removeDeadline called, waveId:', waveId);
            if (!confirm('¿Quitar la fecha límite de esta toma?')) {
                console.log('[DEADLINE] User cancelled remove deadline');
                return;
            }
            
            const self = this;
            console.log('[DEADLINE] Sending AJAX to remove deadline');
            console.log('[DEADLINE] AJAX URL:', eipsiStudyDash.ajaxUrl);
            console.log('[DEADLINE] Nonce available:', !!eipsiStudyDash.nonce);
            
            $.ajax({
                url: eipsiStudyDash.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eipsi_remove_wave_deadline',
                    wave_id: waveId,
                    nonce: eipsiStudyDash.nonce
                },
                timeout: 20000,
                success: function(response) {
                    console.log('[DEADLINE] Remove response:', response);
                    if (response.success) {
                        console.log('[DEADLINE] Removed successfully');
                        self.showToast('Plazo quitado correctamente', 'success');
                        if (self.currentStudyId) {
                            self.loadStudyData(self.currentStudyId);
                        }
                    } else {
                        console.error('[DEADLINE] Remove failed:', response.data);
                        self.showToast('Error: ' + (response.data?.message || response.data || 'No se pudo quitar'), 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[DEADLINE] Remove AJAX error:', status, error);
                    if (status === 'timeout' || xhr.status === 504) {
                        self.showToast('⏱️ Timeout al quitar plazo. Intentá de nuevo.', 'error');
                    } else {
                        self.showToast('Error al quitar plazo', 'error');
                    }
                }
            });
        },

        /**
         * Toggle nudge panel visibility
         */
        toggleNudgePanel: function(waveId) {
            console.log('[FUNC] toggleNudgePanel ========================================');
            console.log('[FUNC] toggleNudgePanel called, waveId:', waveId);
            const $card = $(`#wave-card-${waveId}`);
            console.log('[NUDGE] Looking for card #wave-card-' + waveId + ', found:', $card.length);
            
            if ($card.length === 0) {
                console.error('[ERROR] Wave card not found for waveId:', waveId);
                return;
            }
            
            const $toggle = $card.find('.nudge-toggle-input');
            console.log('[NUDGE] Found toggle:', $toggle.length);
            console.log('[NUDGE] Toggle exists:', $toggle.length > 0);
            
            if ($toggle.length === 0) {
                console.error('[ERROR] Toggle not found in wave card');
                return;
            }
            
            console.log('[NUDGE] Toggle ID:', $toggle.attr('id'));
            console.log('[NUDGE] Toggle checked state:', $toggle.is(':checked'));
            const enabled = $toggle.is(':checked');
            console.log('[NUDGE] Toggle state (enabled):', enabled);
            
            const $panel = $card.find('.nudge-panel');
            console.log('[NUDGE] Panel found:', $panel.length);
            console.log('[NUDGE] Panel visible:', $panel.is(':visible'));
            
            const self = this;
            
            // v2.5.1 - Sincronizar toggle y panel recíprocamente
            console.log('[NUDGE] Decision: enabled=' + enabled + ', will toggle to ' + (!enabled));
            if (!enabled) {
                // Toggle OFF -> Encender toggle + Abrir panel
                console.log('[NUDGE] Toggle is OFF, enabling and opening panel');
                $toggle.prop('checked', true).trigger('change');
                
                // Pausar auto-refresh mientras se edita
                console.log('[NUDGE] Panel opening, pausing auto-refresh');
                this.stopAutoRefresh();
                
                $panel.slideDown(200);
            } else {
                // Toggle ON -> Apagar toggle + Cerrar panel
                console.log('[NUDGE] Toggle is ON, disabling and closing panel');
                $toggle.prop('checked', false).trigger('change');
                
                $panel.slideUp(200, function() {
                    if (!self.isAnyNudgePanelOpen()) {
                        console.log('[NUDGE] Panel closed, resuming auto-refresh');
                        self.startAutoRefresh();
                    }
                });
            }
        },

        /**
         * Save nudge configuration for a wave
         */
        saveNudgeConfig: function(waveId) {
            console.log('[FUNC] saveNudgeConfig ========================================');
            console.log('[FUNC] saveNudgeConfig called, waveId:', waveId);
            const $card = $(`#wave-card-${waveId}`);
            console.log('[NUDGE-SAVE] Card found:', $card.length);
            
            if ($card.length === 0) {
                console.error('[ERROR] Card not found for waveId:', waveId);
                alert('Error: No se encontró la wave card');
                return;
            }
            
            const $toggle = $card.find('.nudge-toggle-input');
            console.log('[NUDGE-SAVE] Toggle found:', $toggle.length);
            console.log('[NUDGE-SAVE] Toggle ID:', $toggle.attr('id'));
            console.log('[NUDGE-SAVE] Toggle checked:', $toggle.is(':checked'));
            
            const enabled = $toggle.is(':checked');
            console.log('[NUDGE-SAVE] Final enabled state to save:', enabled);
            
            // Build nudge config array
            const nudges = [];
            const self = this;
            
            $card.find('.nudge-row').each(function(index) {
                const $row = $(this);
                const minutes = parseInt($row.find('.nudge-minutes-input').val()) || 1440;
                
                // Convertir minutos a value+unit (mantener compatibilidad con backend)
                let value, unit;
                if (minutes >= 1440 && minutes % 1440 === 0) {
                    // Es un número entero de días
                    value = minutes / 1440;
                    unit = 'days';
                } else if (minutes % 60 === 0) {
                    // Es un número entero de horas
                    value = minutes / 60;
                    unit = 'hours';
                } else {
                    // Fracción de hora (usar horas con decimales)
                    value = minutes;
                    unit = 'minutes';
                }
                
                console.log(`[NUDGE] Row ${index}: ${minutes} min -> ${value} ${unit}`);
                nudges.push({
                    value: value,
                    unit: unit
                });
            });
            
            console.log('[NUDGE-SAVE] ========================================');
            console.log('[NUDGE-SAVE] Final nudges array:', JSON.stringify(nudges, null, 2));
            console.log('[NUDGE-SAVE] Final enabled value:', enabled);
            console.log('[NUDGE-SAVE] Enabled type:', typeof enabled);
            console.log('[NUDGE-SAVE] AJAX URL:', eipsiStudyDash.ajaxUrl);
            
            const windowMinutes = $card.find('.wave-window-input').val() ? parseInt($card.find('.wave-window-input').val()) : null;
            
            const ajaxData = {
                action: 'eipsi_save_wave_nudges',
                wave_id: waveId,
                nudges: nudges,
                enabled: enabled,
                window_minutes: windowMinutes,
                nonce: eipsiStudyDash.nonce
            };
            console.log('[NUDGE-SAVE] Full AJAX data:', JSON.stringify(ajaxData, null, 2));
            
            // Make AJAX call to save nudge config
            $.ajax({
                url: eipsiStudyDash.ajaxUrl,
                type: 'POST',
                data: ajaxData,
                success: function(response) {
                    console.log('[NUDGE] Save response:', response);
                    if (response.success) {
                        console.log('[NUDGE] Saved successfully');
                        self.showToast('Configuración de nudges guardada', 'success');
                        // Cerrar el panel después de guardar exitosamente
                        self.toggleNudgePanel(waveId);
                        if (self.currentStudyId) {
                            self.loadStudyData(self.currentStudyId);
                        } else {
                            console.warn('[NUDGE] No currentStudyId, skipping reload');
                        }
                    } else {
                        console.error('[NUDGE] Save failed:', response.data);
                        self.showToast('Error: ' + (response.data?.message || 'No se pudo guardar'), 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[NUDGE] AJAX error:', status, error);
                    console.error('[NUDGE] Response text:', xhr.responseText);
                    if (status === 'timeout' || xhr.status === 504) {
                        self.showToast('⏱️ Timeout: El servidor está sobrecargado. Probá en unos segundos.', 'error');
                    } else {
                        self.showToast('Error al guardar configuración de nudges', 'error');
                    }
                }
            });
        },

        /**
         * Redistribute nudges proportionally to current window
         */
        redistributeNudges: function(waveId) {
            const self = this;
            console.log('[REDISTRIBUTE] Starting redistribution for wave:', waveId);
            
            $.ajax({
                url: eipsiStudyDash.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'eipsi_redistribute_nudges',
                    wave_id: waveId,
                    nonce: eipsiStudyDash.nonce
                },
                success: function(response) {
                    console.log('[REDISTRIBUTE] Response:', response);
                    if (response.success) {
                        const windowDays = response.data.window_days || 0;
                        const message = `✓ Nudges redistribuidos a ${windowDays} días`;
                        
                        self.showTemporaryMessage(waveId, message);
                        
                        if (self.currentStudyId) {
                            self.loadStudyData(self.currentStudyId);
                        }
                    } else {
                        console.error('[REDISTRIBUTE] Failed:', response.data);
                        self.showToast('Error: ' + (response.data?.message || 'No se pudo redistribuir'), 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[REDISTRIBUTE] AJAX error:', status, error);
                    self.showToast('Error al redistribuir nudges', 'error');
                }
            });
        },

        /**
         * Show temporary message with fadeout
         */
        showTemporaryMessage: function(waveId, message) {
            const $card = $(`#wave-card-${waveId}`);
            const $nudgeLabel = $card.find('.nudge-toggle-label');
            
            // Guardar texto original
            const originalText = $nudgeLabel.html();
            
            // Mostrar mensaje
            $nudgeLabel.html(`<span style="color:#10b981;font-weight:600;">${message}</span>`);
            
            // Fadeout después de 3 segundos
            setTimeout(function() {
                $nudgeLabel.fadeOut(300, function() {
                    $nudgeLabel.html(originalText);
                    $nudgeLabel.fadeIn(300);
                });
            }, 3000);
        },

        /**
         * Check if any nudge panel is currently open
         */
        isAnyNudgePanelOpen: function() {
            const openPanels = $('.nudge-panel:visible').length;
            console.log('[FUNC] isAnyNudgePanelOpen - open panels:', openPanels);
            return openPanels > 0;
        },

        /**
         * Start auto-refresh interval
         */
        startAutoRefresh: function() {
            console.log('[FUNC] startAutoRefresh called');
            const self = this;
            this.stopAutoRefresh();
            this.autoRefreshInterval = setInterval(function() {
                if (self.currentStudyId) {
                    // v2.5.0 - Skip auto-refresh if user is editing nudges
                    if (self.isAnyNudgePanelOpen()) {
                        console.log('[AUTO] Skipping refresh - nudge panel is open');
                        return;
                    }
                    console.log('[AUTO] Refreshing study data...');
                    self.loadStudyData(self.currentStudyId);
                }
            }, 30000); // 30 seconds
        },

        /**
         * Stop auto-refresh interval
         */
        stopAutoRefresh: function() {
            console.log('[FUNC] stopAutoRefresh called');
            if (this.autoRefreshInterval) {
                clearInterval(this.autoRefreshInterval);
                this.autoRefreshInterval = null;
            }
        },

        /**
         * Generate Magic Link (show in UI, don't send email)
         */
        generateMagicLink: function() {
            const self = this;
            const email = $('#magic-link-email').val().trim();
            const $output = $('#magic-link-output');
            const $error = $('#magic-link-error');
            const $success = $('#magic-link-success');
            const $urlInput = $('#magic-link-url');

            // Hide previous messages
            $error.hide();
            $success.hide();
            $output.hide();

            // Validate email
            if (!email || !this.isValidEmail(email)) {
                $error.text('Por favor, ingresa un email válido').show();
                return;
            }

            // Get study ID from current context
            const studyId = this.currentStudyId;
            if (!studyId) {
                $error.text('No se pudo identificar el estudio').show();
                return;
            }

            $.ajax({
                url: eipsiStudyDash.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eipsi_generate_magic_link',
                    study_id: studyId,
                    email: email,
                    nonce: eipsiStudyDash.nonce
                },
                beforeSend: function() {
                    $('#generate-magic-link').prop('disabled', true).text('Generando...');
                },
                success: function(response) {
                    $('#generate-magic-link').prop('disabled', false).text('🔐 Generar enlace');

                    if (response.success) {
                        // Show the magic link in the UI
                        $urlInput.val(response.data.magic_link || '');
                        $output.show();
                        
                        $success.text(response.data.message || 'Magic Link generado correctamente').show();
                    } else {
                        $error.text(response.data.message || 'Error al generar el Magic Link').show();
                    }
                },
                error: function() {
                    $('#generate-magic-link').prop('disabled', false).text('🔐 Generar enlace');
                    $error.text('Error de conexión').show();
                }
            });
        },

        /**
         * Send Magic Link via Email
         */
        sendMagicLinkEmail: function() {
            const self = this;
            const email = $('#magic-link-email').val().trim();
            const $error = $('#magic-link-error');
            const $success = $('#magic-link-success');
            const $output = $('#magic-link-output');

            // Hide previous messages
            $error.hide();
            $success.hide();
            $output.hide();

            // Validate email
            if (!email || !this.isValidEmail(email)) {
                $error.text('Por favor, ingresa un email válido').show();
                return;
            }

            // Get study ID from current context
            const studyId = this.currentStudyId;
            if (!studyId) {
                $error.text('No se pudo identificar el estudio').show();
                return;
            }

            $.ajax({
                url: eipsiStudyDash.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eipsi_generate_magic_link',
                    study_id: studyId,
                    email: email,
                    nonce: eipsiStudyDash.nonce
                },
                beforeSend: function() {
                    $('#send-magic-link').prop('disabled', true).text('Enviando...');
                },
                success: function(response) {
                    $('#send-magic-link').prop('disabled', false).text('📨 Enviar por email');

                    if (response.success) {
                        // Show success message
                        $success.text(response.data.message || 'Magic Link enviado exitosamente').show();
                        
                        // Also show the link in case they want to copy it
                        if (response.data.magic_link) {
                            $('#magic-link-url').val(response.data.magic_link);
                            $output.show();
                        }
                        
                        // Clear the email input for next use
                        $('#magic-link-email').val('');
                    } else {
                        $error.text(response.data.message || 'Error al enviar el Magic Link').show();
                    }
                },
                error: function() {
                    $('#send-magic-link').prop('disabled', false).text('📨 Enviar por email');
                    $error.text('Error de conexión').show();
                }
            });
        },

        /**
         * Copy Magic Link to clipboard
         */
        copyMagicLink: function() {
            const $urlInput = $('#magic-link-url');
            const link = $urlInput.val();

            if (!link) {
                return;
            }

            // Use Clipboard API
            navigator.clipboard.writeText(link).then(function() {
                $('#copy-magic-link').text('✓ ¡Copiado!').prop('disabled', true);
                setTimeout(function() {
                    $('#copy-magic-link').text('📋 Copiar').prop('disabled', false);
                }, 2000);
            }).catch(function(err) {
                // Fallback for older browsers
                $urlInput.select();
                document.execCommand('copy');
                $('#copy-magic-link').text('✓ ¡Copiado!').prop('disabled', true);
                setTimeout(function() {
                    $('#copy-magic-link').text('📋 Copiar').prop('disabled', false);
                }, 2000);
            });
        },

        /**
         * Validate email format
         */
        isValidEmail: function(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        },

        /**
         * Pause Study
         */
        pauseStudy: function() {
            const self = this;
            if (!confirm('¿Estás seguro de que querés pausar este estudio?\n\nAl pausar:\n• Se detendrán los recordatorios automáticos\n• Los participantes no podrán enviar nuevas respuestas\n• El estudio puede reanudarse en cualquier momento')) {
                return;
            }

            $.ajax({
                url: eipsiStudyDash.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eipsi_pause_study',
                    study_id: this.currentStudyId,
                    nonce: eipsiStudyDash.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.showToast('✅ Estudio pausado correctamente', 'success');
                        self.loadStudyData(self.currentStudyId);
                    } else {
                        self.showToast('❌ Error: ' + (response.data || 'No se pudo pausar el estudio'), 'error');
                    }
                },
                error: function() {
                    self.showToast('❌ Error de conexión', 'error');
                }
            });
        },

        /**
         * Resume Study
         */
        resumeStudy: function() {
            const self = this;
            if (!confirm('¿Reanudar este estudio?\n\nAl reanudar:\n• Los recordatorios automáticos se reactivarán\n• Los participantes podrán continuar con sus evaluaciones')) {
                return;
            }

            $.ajax({
                url: eipsiStudyDash.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eipsi_resume_study',
                    study_id: this.currentStudyId,
                    nonce: eipsiStudyDash.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.showToast('✅ Estudio reanudado correctamente', 'success');
                        self.loadStudyData(self.currentStudyId);
                    } else {
                        self.showToast('❌ Error: ' + (response.data || 'No se pudo reanudar el estudio'), 'error');
                    }
                },
                error: function() {
                    self.showToast('❌ Error de conexión', 'error');
                }
            });
        },

        /**
         * Close Study permanently
         */
        closeStudy: function() {
            const self = this;
            if (!confirm('⚠️ ¿Cerrar permanentemente este estudio?\n\nEsta acción:\n• Detendrá todos los recordatorios\n• Marcará el estudio como completado\n• No se podrán agregar nuevos participantes\n• NO se pueden deshacer los cambios')) {
                return;
            }

            // Double confirmation
            if (!confirm('🔒 CONFIRMACIÓN FINAL\n\nUna vez cerrado, el estudio no podrá reactivarse.\n¿Continuar?')) {
                return;
            }

            $.ajax({
                url: eipsiStudyDash.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eipsi_close_study',
                    study_id: this.currentStudyId,
                    nonce: eipsiStudyDash.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.showToast('✅ Estudio cerrado permanentemente', 'success');
                        self.loadStudyData(self.currentStudyId);
                    } else {
                        self.showToast('❌ Error: ' + (response.data || 'No se pudo cerrar el estudio'), 'error');
                    }
                },
                error: function() {
                    self.showToast('❌ Error de conexión', 'error');
                }
            });
        },

        /**
         * Open Email Logs Modal
         */
        openEmailLogs: function() {
            console.log('[FUNC] openEmailLogs called');
            const self = this;
            this.loadEmailLogs(1);
            $('#eipsi-email-logs-modal').fadeIn(200);
            
            // Bind refresh button
            $('#refresh-email-logs').off('click').on('click', function() {
                console.log('[BUTTON] refresh-email-logs clicked');
                self.loadEmailLogs(1);
            });
            
            // Bind status filter
            $('#email-log-status-filter').off('change').on('change', function() {
                console.log('[FILTER] Status changed:', $(this).val());
                self.loadEmailLogs(1);
            });
            
            // Bind search input (debounced)
            let searchTimeout;
            $('#email-log-search').off('input').on('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    self.loadEmailLogs(1);
                }, 500);
            });
        },

        /**
         * Load Email Logs via AJAX
         */
        loadEmailLogs: function(page) {
            console.log('[FUNC] loadEmailLogs called, page:', page);
            const self = this;
            const studyId = this.currentStudyId;

            if (!studyId) {
                console.error('[ERROR] loadEmailLogs: studyId is null');
                return;
            }

            // Get filter values
            const statusFilter = $('#email-log-status-filter').val() || 'all';
            const searchTerm = $('#email-log-search').val() || '';
            
            $.ajax({
                url: eipsiStudyDash.ajaxUrl,
                type: 'GET',
                data: {
                    action: 'eipsi_get_study_email_logs',
                    study_id: studyId,
                    page: page,
                    status: statusFilter,
                    search: searchTerm,
                    nonce: eipsiStudyDash.nonce
                },
                timeout: 20000,
                success: function(response) {
                    console.log('[EMAIL-LOGS] AJAX success:', response);
                    if (response.success) {
                        self.renderEmailLogs(response.data);
                    } else {
                        self.showToast('Error al cargar logs de email', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[EMAIL-LOGS] AJAX error:', status, error);
                    if (status === 'timeout' || xhr.status === 504) {
                        self.showToast('⏱️ Timeout al cargar logs. El servidor está lento.', 'error');
                    } else {
                        self.showToast('Error de conexión al cargar logs', 'error');
                    }
                }
            });
        },

        /**
         * Render Email Logs in modal
         */
        renderEmailLogs: function(data) {
            console.log('[FUNC] renderEmailLogs called, data:', data);
            const $tbody = $('#email-logs-tbody');
            $tbody.empty();

            if (!data || data.length === 0) {
                $tbody.append('<tr><td colspan="4" style="text-align:center;padding:20px;">No hay emails registrados</td></tr>');
                return;
            }

            data.forEach(function(log) {
                const statusClass = log.status === 'sent' ? 'status-sent' : 'status-failed';
                const statusText = log.status === 'sent' ? 'Enviado' : 'Fallido';
                // v2.1.2 - Usar fecha formateada del servidor (zona horaria de WordPress)
                const date = log.sent_at_formatted || (log.sent_at ? new Date(log.sent_at).toLocaleString() : '-');
                const row = `
                    <tr>
                        <td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;">${date}</td>
                        <td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;">${log.recipient_email || '-'}</td>
                        <td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;">${log.email_type || '-'}</td>
                        <td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;"><span class="email-status ${statusClass}">${statusText}</span></td>
                    </tr>
                `;
                $tbody.append(row);
            });
        },

        /**
         * Send Reminder for a wave
         */
        sendReminder: function(waveId) {
            console.log('[FUNC] sendReminder called, waveId:', waveId);
            const self = this;
            const studyId = this.currentStudyId;

            if (!studyId || !waveId) {
                console.error('[ERROR] sendReminder: missing studyId or waveId');
                return;
            }

            if (!confirm('¿Enviar recordatorio a todos los participantes pendientes de esta toma?')) {
                console.log('[REMINDER] User cancelled');
                return;
            }

            $.ajax({
                url: eipsiStudyDash.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eipsi_send_wave_reminder_manual',
                    study_id: studyId,
                    wave_id: waveId,
                    nonce: eipsiStudyDash.nonce
                },
                timeout: 60000, // 60 seconds for email sending
                beforeSend: function() {
                    console.log('[REMINDER] Sending AJAX request...');
                },
                success: function(response) {
                    console.log('[REMINDER] AJAX success:', response);
                    if (response.success) {
                        self.showToast('✅ Recordatorios enviados: ' + (response.data.sent_count || 0), 'success');
                        self.loadStudyData(studyId);
                    } else {
                        self.showToast('❌ Error: ' + (response.data?.message || 'No se pudieron enviar los recordatorios'), 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[REMINDER] AJAX error:', status, error);
                    if (status === 'timeout' || xhr.status === 504) {
                        self.showToast('⏱️ Timeout: El envío de emails está tardando. Los emails pueden haberse enviado igual.', 'warning');
                    } else {
                        self.showToast('Error de conexión al enviar recordatorios', 'error');
                    }
                }
            });
        },

        /**
         * Send Global Reminder to all participants across all waves
         */
        sendGlobalReminder: function() {
            console.log('[FUNC] sendGlobalReminder called');
            const self = this;
            const studyId = this.currentStudyId;

            if (!studyId) {
                console.error('[ERROR] sendGlobalReminder: studyId is null');
                self.showToast('Error: No se pudo identificar el estudio', 'error');
                return;
            }

            if (!confirm('¿Enviar recordatorio global a TODOS los participantes pendientes de todas las tomas?')) {
                console.log('[GLOBAL-REMINDER] User cancelled');
                return;
            }

            $.ajax({
                url: eipsiStudyDash.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eipsi_send_global_reminder',
                    study_id: studyId,
                    nonce: eipsiStudyDash.nonce
                },
                timeout: 120000, // 2 minutes for global reminders (many emails)
                beforeSend: function() {
                    console.log('[GLOBAL-REMINDER] Sending AJAX request...');
                    $('#send-global-reminder').prop('disabled', true).text('Enviando...');
                },
                success: function(response) {
                    $('#send-global-reminder').prop('disabled', false).text('Enviar recordatorio global');
                    console.log('[GLOBAL-REMINDER] AJAX success:', response);
                    if (response.success) {
                        self.showToast('✅ Recordatorios globales enviados: ' + (response.data?.sent_count || 0), 'success');
                        self.loadStudyData(studyId);
                    } else {
                        self.showToast('❌ Error: ' + (response.data?.message || 'No se pudieron enviar los recordatorios'), 'error');
                    }
                },
                error: function(xhr, status, error) {
                    $('#send-global-reminder').prop('disabled', false).text('Enviar recordatorio global');
                    console.error('[GLOBAL-REMINDER] AJAX error:', status, error);
                    if (status === 'timeout' || xhr.status === 504) {
                        self.showToast('⏱️ Timeout: El envío global está tardando. Los emails pueden haberse enviado igual.', 'warning');
                    } else {
                        self.showToast('Error de conexión al enviar recordatorios globales', 'error');
                    }
                }
            });
        },

        /**
         * Open Add Participant Modal
         */
        openAddParticipant: function() {
            console.log('[FUNC] openAddParticipant called');
            $('#add-participant-study-id').val(this.currentStudyId);
            $('#participant-email').val('');
            $('#add-participant-error').hide();
            $('#add-participant-success').hide();
            $('#eipsi-add-participant-modal').fadeIn(200);
            $('#participant-email').focus();
        },

        /**
         * Add participant to study
         * v2.5.1 - Created missing function
         */
        addParticipant: function() {
            console.log('[FUNC] addParticipant called');
            const self = this;
            
            const studyId = this.currentStudyId;
            const email = $('#participant-email').val().trim();
            
            console.log('[ADD-PARTICIPANT] studyId:', studyId, 'email:', email);
            
            // Validate email
            if (!email || !email.includes('@')) {
                $('#add-participant-error').text('Por favor ingresa un email válido').show();
                $('#add-participant-success').hide();
                return;
            }
            
            // Show loading state
            $('#submit-add-participant').prop('disabled', true).text('Agregando...');
            $('#add-participant-error').hide();
            
            $.ajax({
                url: eipsiStudyDash.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'eipsi_add_participant',
                    study_id: studyId,
                    email: email,
                    nonce: eipsiStudyDash.nonce
                },
                success: function(response) {
                    console.log('[ADD-PARTICIPANT] Response:', response);
                    $('#submit-add-participant').prop('disabled', false).text('Agregar');
                    
                    if (typeof response !== 'object' || response === null) {
                        console.error('[ADD-PARTICIPANT] Invalid response:', response);
                        $('#add-participant-error').text('Error: Respuesta inválida del servidor').show();
                        return;
                    }
                    
                    if (response.success) {
                        $('#add-participant-success').text(response.data?.message || 'Participante agregado correctamente').show();
                        $('#add-participant-error').hide();
                        $('#participant-email').val('');
                        
                        // Refresh participants list if open
                        if ($('#eipsi-participants-modal').is(':visible')) {
                            self.loadParticipantsList(1);
                        }
                        
                        // Close modal after delay
                        setTimeout(function() {
                            $('#eipsi-add-participant-modal').fadeOut(200);
                            $('#add-participant-success').hide();
                        }, 2000);
                    } else {
                        const errorMsg = response.data || 'Error al agregar participante';
                        $('#add-participant-error').text(errorMsg).show();
                        $('#add-participant-success').hide();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[ADD-PARTICIPANT] AJAX error:', status, error);
                    $('#submit-add-participant').prop('disabled', false).text('Agregar');
                    $('#add-participant-error').text('Error de conexión. Intenta nuevamente.').show();
                }
            });
        },

        /**
         * Delete Study permanently
         */
        deleteStudy: function(studyId) {
            const self = this;

            $.ajax({
                url: eipsiStudyDash.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eipsi_delete_study',
                    study_id: studyId,
                    nonce: eipsiStudyDash.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.showToast('✅ Estudio eliminado permanentemente', 'success');
                        // Close modal and reload page to refresh study list
                        $('#eipsi-study-dashboard-modal').fadeOut();
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        self.showToast('❌ Error: ' + (response.data || 'No se pudo eliminar el estudio'), 'error');
                    }
                },
                error: function() {
                    self.showToast('❌ Error de conexión', 'error');
                }
            });
        },

        /**
         * Open participants list modal
         */
        openParticipantsList: function() {
            console.log('[FUNC] openParticipantsList called, studyId:', this.currentStudyId);
            $('#eipsi-participants-modal').fadeIn(200);
            this.loadParticipantsList(1);
        },

        /**
         * Load participants list via AJAX
         */
        loadParticipantsList: function(page) {
            console.log('[FUNC] loadParticipantsList called, page:', page, 'studyId:', this.currentStudyId);
            const self = this;
            const status = $('#participant-status-filter').val();
            const search = $('#participant-search').val();

            $.ajax({
                url: eipsiStudyDash.ajaxUrl,
                type: 'GET',
                data: {
                    action: 'eipsi_get_participants_list',
                    study_id: this.currentStudyId,
                    page: page,
                    status: status,
                    search: search,
                    nonce: eipsiStudyDash.nonce
                },
                timeout: 30000,
                success: function(response) {
                    console.log('[LOAD] Participants response:', response);
                    if (response.success) {
                        self.renderParticipantsList(response.data);
                    } else {
                        console.error('[LOAD] Error loading participants:', response.data);
                        $('#participants-tbody').html('<tr><td colspan="3" style="text-align:center;padding:20px;">Error al cargar participantes</td></tr>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[LOAD] AJAX error:', status, error);
                    if (status === 'timeout' || xhr.status === 504) {
                        $('#participants-tbody').html('<tr><td colspan="3" style="text-align:center;padding:20px;">⏱️ Timeout: El servidor está lento. <button onclick="EIPSI_StudyDashboard.loadParticipantsList(1)" class="btn-link">Reintentar</button></td></tr>');
                    } else {
                        $('#participants-tbody').html('<tr><td colspan="3" style="text-align:center;padding:20px;">Error de conexión</td></tr>');
                    }
                }
            });
        },

        /**
         * Render participants table
         */
        renderParticipantsList: function(data) {
            console.log('[FUNC] renderParticipantsList called, total:', data.total, 'participants:', data.participants?.length);
            const self = this;
            const $tbody = $('#participants-tbody');
            $tbody.empty();

            $('#participants-count').text(`${data.total} participantes`);

            if (data.participants.length === 0) {
                $tbody.append('<tr><td colspan="3" style="text-align:center;padding:20px;">No se encontraron participantes</td></tr>');
                return;
            }

            data.participants.forEach(function(p) {
                // v2.5.2 - Fix: Convert is_active to proper boolean (it comes as string "0" or "1")
                const isActive = p.is_active == true || p.is_active == 1 || p.is_active == '1';
                console.log('[RENDER] Participant:', p.id, 'email:', p.email, 'is_active raw:', p.is_active, 'parsed:', isActive);
                
                const statusBadge = isActive
                    ? '<span class="eipsi-badge badge-active">Activo</span>'
                    : '<span class="eipsi-badge badge-inactive">Inactivo</span>';

                // Toggle button: Activo = 🔒 (cerrado, clic para desactivar), Inactivo = 🔓 (abierto, clic para activar)
                const toggleIcon = isActive ? '🔒' : '🔓';
                const toggleText = isActive ? 'Desactivar' : 'Activar';
                const toggleClass = isActive ? 'deactivate' : 'activate';
                
                // v2.5.2 - Disable email buttons for inactive participants
                const emailButtonsDisabled = !isActive ? 'disabled' : '';
                const emailButtonsTitle = !isActive ? 'Participante inactivo - Actívalo primero' : '';

                const row = `
                    <tr data-participant-id="${p.id}">
                        <td><code>${p.email}</code></td>
                        <td>${statusBadge}</td>
                        <td>
                            <div class="participants-actions-row" style="display:flex;gap:4px;align-items:center;">
                                <button type="button"
                                        class="button button-small toggle-participant-status ${toggleClass}"
                                        data-participant-id="${p.id}"
                                        data-is-active="${isActive ? '1' : '0'}"
                                        title="${toggleText}">
                                    ${toggleIcon}
                                </button>
                                <button type="button" class="button button-small delete-participant-btn" data-participant-id="${p.id}" title="Eliminar">
                                    🗑️
                                </button>
                                <button type="button" 
                                        class="button button-small resend-magic-link-btn" 
                                        data-participant-id="${p.id}" 
                                        data-participant-email="${p.email}" 
                                        title="${isActive ? 'Enviar Magic Link' : 'Participante inactivo - Actívalo primero'}"
                                        ${emailButtonsDisabled}>
                                    ✨
                                </button>
                                <button type="button" 
                                        class="button button-small resend-reminder-btn" 
                                        data-participant-id="${p.id}" 
                                        data-participant-email="${p.email}" 
                                        title="${isActive ? 'Enviar Recordatorio' : 'Participante inactivo - Actívalo primero'}"
                                        ${emailButtonsDisabled}>
                                    ⏰
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
                $tbody.append(row);
            });

            // Render pagination
            this.renderParticipantsPagination(data.page, data.total_pages);
        },

        /**
         * Render pagination for participants
         */
        renderParticipantsPagination: function(currentPage, totalPages) {
            console.log('[FUNC] renderParticipantsPagination called, page:', currentPage, 'totalPages:', totalPages);
            const self = this;
            const $pagination = $('#participants-pagination');
            $pagination.empty();

            // Fallback if totalPages is not provided
            const total = totalPages || 1;
            if (total <= 1) return;

            let html = '<div class="pagination-links">';

            if (currentPage > 1) {
                html += `<button class="button button-small" data-page="${currentPage - 1}">← Anterior</button>`;
            }

            html += `<span style="margin: 0 10px;">Página ${currentPage} de ${total}</span>`;

            if (currentPage < total) {
                html += `<button class="button button-small" data-page="${currentPage + 1}">Siguiente →</button>`;
            }

            html += '</div>';

            $pagination.html(html);

            // Bind pagination clicks
            $pagination.find('button').on('click', function() {
                self.loadParticipantsList($(this).data('page'));
            });
        },

        /**
         * Toggle participant active status
         */
        toggleParticipantStatus: function(participantId, isActive) {
            console.log('[FUNC] toggleParticipantStatus called, participantId:', participantId, 'isActive:', isActive);
            const self = this;
            const newStatus = isActive ? 0 : 1;
            const actionText = isActive ? 'desactivar' : 'activar';

            if (!confirm(`¿Estás seguro que querés ${actionText} este participante?`)) {
                console.log('[TOGGLE] Cancelled by user');
                return;
            }

            $.ajax({
                url: eipsiStudyDash.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eipsi_toggle_participant_status',
                    participant_id: participantId,
                    is_active: newStatus,
                    nonce: eipsiStudyDash.nonce
                },
                success: function(response) {
                    console.log('[TOGGLE] AJAX success, response:', response);
                    if (response.success) {
                        self.showToast(response.data.message || 'Estado actualizado correctamente', 'success');
                        self.loadParticipantsList(1);
                    } else {
                        console.error('[TOGGLE] Server error:', response.data);
                        self.showToast(response.data.message || 'No se pudo actualizar el estado', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[TOGGLE] AJAX error:', status, error);
                    self.showToast('Error de conexión al actualizar el estado', 'error');
                }
            });
        },

        /**
         * Delete participant
         */
        deleteParticipant: function(participantId) {
            console.log('[FUNC] deleteParticipant called, participantId:', participantId);
            const self = this;
            const $row = $(`tr[data-participant-id="${participantId}"]`);
            console.log('[DELETE] Row found:', $row.length > 0);

            if (!confirm('¿Querés eliminar este participante? Se borrarán sus asignaciones, sesiones y magic links. Esta acción no se puede deshacer.')) {
                console.log('[DELETE] Cancelled by user');
                return;
            }

            $row.addClass('loading');

            $.ajax({
                url: eipsiStudyDash.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eipsi_delete_participant',
                    participant_id: participantId,
                    study_id: this.currentStudyId,
                    nonce: eipsiStudyDash.nonce
                },
                success: function(response) {
                    $row.removeClass('loading');
                    console.log('[DELETE] AJAX success, response:', response);
                    if (response.success) {
                        self.showToast(response.data.message || 'Participante eliminado correctamente', 'success');
                        self.loadParticipantsList(1);
                    } else {
                        console.error('[DELETE] Server returned error:', response.data);
                        self.showToast(response.data.message || 'No se pudo eliminar el participante', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    $row.removeClass('loading');
                    console.error('[DELETE] AJAX error:', status, error, xhr.responseText);
                    self.showToast('Error de conexión al eliminar el participante', 'error');
                }
            });
        },

        /**
         * Resend email to participant (with debounce)
         */
        resendEmail: function(participantId, emailType, participantEmail, waveId) {
            console.log('[FUNC] resendEmail called, participantId:', participantId, 'type:', emailType, 'email:', participantEmail, 'waveId:', waveId);
            const self = this;

            // Debounce: prevent multiple rapid clicks
            const debounceKey = `${participantId}-${emailType}`;
            if (this.resendDebounceMap.has(debounceKey)) {
                console.log('[RESEND] Debounced, skipping');
                return;
            }
            this.resendDebounceMap.set(debounceKey, true);
            setTimeout(() => this.resendDebounceMap.delete(debounceKey), 3000);

            const typeLabels = {
                'welcome': 'Email de bienvenida',
                'magic_link': 'Magic Link',
                'reminder': 'Recordatorio de onda',
                'confirmation': 'Email de confirmación',
                'recovery': 'Email de recuperación'
            };

            if (!confirm(`¿Enviar ${typeLabels[emailType] || 'email'} a ${participantEmail}?`)) {
                console.log('[RESEND] Cancelled by user');
                return;
            }

            // Show loading state
            const $row = $(`tr[data-participant-id="${participantId}"]`);
            $row.addClass('loading');

            $.ajax({
                url: eipsiStudyDash.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'eipsi_resend_participant_email',
                    participant_id: participantId,
                    email_type: emailType,
                    study_id: this.currentStudyId,
                    wave_id: waveId,
                    nonce: eipsiStudyDash.nonce
                },
                success: function(response) {
                    $row.removeClass('loading');
                    console.log('[RESEND] AJAX success, response:', response);
                    
                    // v2.5.1 - Validar que la respuesta sea un objeto válido
                    if (typeof response !== 'object' || response === null) {
                        console.error('[RESEND] Invalid response format (expected object):', response);
                        self.showToast('Error: Respuesta inválida del servidor. El handler AJAX puede no estar registrado.', 'error');
                        return;
                    }

                    if (response.success) {
                        self.showToast(response.data?.message || 'Email enviado correctamente', 'success');
                    } else {
                        // Show detailed error message from backend
                        const errorMsg = response.data?.message || 'Error al enviar email';
                        const errorCode = response.data?.error || '';
                        console.error('[RESEND] Server returned error:', errorCode, errorMsg);

                        // Provide helpful guidance based on error type
                        let helpfulMsg = errorMsg;
                        if (errorCode === 'invalid_survey_id' || errorCode === 'survey_not_found') {
                            helpfulMsg = errorMsg + '. Este participante tiene un estudio asociado inválido.';
                        }

                        self.showToast(helpfulMsg, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    $row.removeClass('loading');
                    console.error('[RESEND] AJAX error:', status, error, 'response:', xhr.responseText?.substring(0, 500));

                    // Try to parse error response
                    let errorMessage = 'Error de conexión: ' + error;

                    try {
                        // Check if response is JSON
                        const contentType = xhr.getResponseHeader('content-type');
                        if (contentType && contentType.indexOf('application/json') !== -1) {
                            const response = JSON.parse(xhr.responseText);
                            if (response.data && response.data.message) {
                                errorMessage = response.data.message;

                                // Add extra context for specific errors
                                if (response.data.error === 'invalid_survey_id') {
                                    errorMessage += ' (ID: ' + (response.data.participant_survey_id || '?') + ')';
                                }
                            }
                        } else {
                            // HTML response - likely a PHP error or page
                            errorMessage = 'Error del servidor. Por favor intentá de nuevo.';
                        }
                    } catch (e) {
                        // Not JSON - use default message
                    }

                    self.showToast(errorMessage, 'error');
                }
            });
        },

        /**
         * Show toast notification
         */
        showToast: function(message, type) {
            const $toast = $(`
                <div class="eipsi-toast eipsi-toast-${type}" style="
                    position: fixed;
                    bottom: 20px;
                    right: 20px;
                    padding: 12px 20px;
                    background: ${type === 'success' ? '#46b450' : '#dc3232'};
                    color: #fff;
                    border-radius: 4px;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                    z-index: 100000;
                    font-size: 14px;
                    max-width: 400px;
                ">
                    ${message}
                </div>
            `);

            $('body').append($toast);

            setTimeout(function() {
                $toast.fadeOut(function() {
                    $toast.remove();
                });
            }, 4000);
        }
    };

    $(document).ready(function() {
        StudyDashboard.init();
    });

})(jQuery);
