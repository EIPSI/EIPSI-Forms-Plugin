/**
 * EIPSI Forms - Waves Manager JS
 * 
 * @since 1.4.0
 */

jQuery(document).ready(function($) {
    const api = eipsiWavesManager;
    const $wrap = $('.eipsi-waves-manager-wrap');
    const studyId = $wrap.data('study-id');

    // Modal elements
    const $waveModal = $('#eipsi-wave-modal');
    const $assignModal = $('#eipsi-assign-modal');
    const $waveForm = $('#eipsi-wave-form');

    // Debounce map for resend email
    const resendDebounceMap = new Map();

    /**
     * Helper to show messages
     */
    function showMessage(msg, isError = false) {
        const $container = $('#eipsi-message-container');
        const type = isError ? 'error' : 'success';
        $container.html(`<div class="notice notice-${type} is-dismissible"><p>${msg}</p></div>`);
        
        // Auto-dismiss after 5s
        setTimeout(() => $container.empty(), 5000);
    }

    /**
     * Helper to show toast notifications
     */
    function showToast(message, type = 'success') {
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

    /**
     * Helper to resend email with debounce
     */
    function resendEmail(participantId, emailType, participantEmail, waveId) {
        // Debounce: prevent multiple rapid clicks
        const debounceKey = `${participantId}-${emailType}`;
        if (resendDebounceMap.has(debounceKey)) {
            return;
        }
        resendDebounceMap.set(debounceKey, true);
        setTimeout(() => resendDebounceMap.delete(debounceKey), 3000);

        const typeLabels = {
            'welcome': 'Email de bienvenida',
            'magic_link': 'Magic Link',
            'reminder': 'Recordatorio de onda',
            'confirmation': 'Email de confirmación',
            'recovery': 'Email de recuperación'
        };

        if (!confirm(`¿Enviar ${typeLabels[emailType] || 'email'} a ${participantEmail}?`)) {
            return;
        }

        // Show loading state on the row
        const $row = $(`tr[data-participant-id="${participantId}"]`);
        $row.addClass('loading');

        $.ajax({
            url: api.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'eipsi_resend_participant_email',
                participant_id: participantId,
                email_type: emailType,
                study_id: studyId,
                wave_id: waveId,
                nonce: api.nonce
            },
            success: function(response) {
                $row.removeClass('loading');
                
                if (response.success) {
                    showToast(response.data.message || 'Email enviado correctamente', 'success');
                } else {
                    // Show detailed error message from backend
                    const errorMsg = response.data.message || 'Error al enviar email';
                    const errorCode = response.data.error || '';
                    
                    // Provide helpful guidance based on error type
                    let helpfulMsg = errorMsg;
                    if (errorCode === 'invalid_survey_id' || errorCode === 'survey_not_found') {
                        helpfulMsg = errorMsg + '. Este participante tiene un estudio asociado inválido.';
                    }
                    
                    showToast(helpfulMsg, 'error');
                }
            },
            error: function(xhr, status, error) {
                $row.removeClass('loading');
                
                // Try to parse error response
                let errorMessage = 'Error de conexión: ' + error;
                
                try {
                    const contentType = xhr.getResponseHeader('content-type');
                    if (contentType && contentType.indexOf('application/json') !== -1) {
                        const response = JSON.parse(xhr.responseText);
                        if (response.data && response.data.message) {
                            errorMessage = response.data.message;
                            
                            if (response.data.error === 'invalid_survey_id') {
                                errorMessage += ' (ID: ' + (response.data.participant_survey_id || '?') + ')';
                            }
                        }
                    } else {
                        errorMessage = 'Error del servidor. Por favor, revisa los logs.';
                    }
                } catch (e) {
                    errorMessage = 'Error de conexión. Verifica tu conexión a internet.';
                }
                
                showToast(errorMessage, 'error');
            }
        });
    }

    /**
     * Open Wave Modal (Create/Edit)
     */
    $('#eipsi-create-wave-btn').on('click', function() {
        const nextIndex = $(this).data('next-index');
        $waveForm[0].reset();
        $('#wave_id').val('');
        $('#wave_index').val(nextIndex).prop('readonly', false);
        $('#form_id').prop('disabled', false);
        $('#start_date, #due_date').prop('disabled', false);
        $('#wave-modal-title').text('Crear Nueva Onda');
        $waveModal.css('display', 'flex').fadeIn();
    });

    $('.eipsi-edit-wave-btn').on('click', function() {
        const wave = $(this).data('wave');
        $waveForm[0].reset();
        
        $('#wave_id').val(wave.id);
        $('#wave_name').val(wave.name);
        $('#wave_index').val(wave.wave_index).prop('readonly', true);
        $('#form_id').prop('disabled', !!wave.has_assignments);
        $('#start_date, #due_date').prop('disabled', !!wave.has_assignments);
        $('#form_id').val(wave.form_id);
        $('#wave_description').val(wave.description);
        $('#start_date').val(wave.start_date ? wave.start_date.replace(' ', 'T').slice(0, 16) : '');
        $('#wave_status').val(wave.status);
        
        if (wave.due_date) {
            // Convert to datetime-local format: YYYY-MM-DDTHH:mm
            const formattedDate = wave.due_date.replace(' ', 'T').slice(0, 16);
            $('#due_date').val(formattedDate);
        }

        $('input[name="is_mandatory"]').prop('checked', parseInt(wave.is_mandatory) === 1);
        
        $('#wave-modal-title').text('Editar Onda: ' + wave.name);
        $waveModal.css('display', 'flex').fadeIn();
    });

    /**
     * Save Wave
     */
    $waveForm.on('submit', function(e) {
        e.preventDefault();
        const $btn = $('#save-wave-btn');
        const originalText = $btn.text();
        
        $btn.text(api.strings.loading).prop('disabled', true);

        const formData = new FormData(this);
        formData.append('action', 'eipsi_save_wave');
        formData.append('nonce', api.nonce);
        if (!formData.has('form_id')) formData.append('form_id', $('#form_id').val());

        $.ajax({
            url: api.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    showMessage(api.strings.success);
                    location.reload();
                } else {
                    showMessage(response.data || api.strings.error, true);
                    $btn.text(originalText).prop('disabled', false);
                }
            },
            error: function() {
                showMessage(api.strings.error, true);
                $btn.text(originalText).prop('disabled', false);
            }
        });
    });

    /**
     * Delete Wave
     */
    $('.eipsi-delete-wave-btn').on('click', function() {
        if (!confirm(api.strings.confirmDelete)) return;

        const $card = $(this).closest('.eipsi-wave-card');
        const waveId = $card.data('wave-id');

        $.post(api.ajaxUrl, {
            action: 'eipsi_delete_wave',
            wave_id: waveId,
            nonce: api.nonce
        }, function(response) {
            if (response.success) {
                showMessage(api.strings.success);
                $card.fadeOut(() => $card.remove());
            } else {
                showMessage(response.data || api.strings.error, true);
            }
        });
    });

    /**
     * Assign Participants Modal
     */
    let activeWaveId = null;

    $('.eipsi-assign-participants-btn').on('click', function() {
        const $card = $(this).closest('.eipsi-wave-card');
        activeWaveId = $card.data('wave-id');
        const waveName = $card.find('.wave-name').text();

        $('#assign-wave-name').text('(' + waveName + ')');
        $('#available-participants-tbody').html('<tr><td colspan="4" style="text-align:center;">' + api.strings.loading + '</td></tr>');
        $assignModal.css('display', 'flex').fadeIn();

        // Load available participants
        $.get(api.ajaxUrl, {
            action: 'eipsi_get_available_participants',
            study_id: studyId,
            wave_id: activeWaveId,
            nonce: api.nonce
        }, function(response) {
            if (response.success) {
                renderParticipants(response.data);
            } else {
                $('#available-participants-tbody').html('<tr><td colspan="4" style="text-align:center;color:red;">' + (response.data || api.strings.error) + '</td></tr>');
            }
        });
    });

    function renderParticipants(participants) {
        const $tbody = $('#available-participants-tbody');
        $tbody.empty();

        if (participants.length === 0) {
            $tbody.append('<tr><td colspan="4" style="text-align:center;">Todos los participantes activos ya están asignados a esta onda.</td></tr>');
            return;
        }

        participants.forEach(p => {
            $tbody.append(`
                <tr>
                    <td class="check-column"><input type="checkbox" name="participant_ids[]" value="${p.id}"></td>
                    <td>${p.full_name}</td>
                    <td>${p.email}</td>
                    <td><code>${p.participant_id}</code></td>
                </tr>
            `);
        });
    }

    // Master checkbox
    $('#master-participant-check').on('change', function() {
        $('#available-participants-tbody input[type="checkbox"]').prop('checked', this.checked);
    });

    $('#select-all-participants').on('click', () => {
        $('#available-participants-tbody input[type="checkbox"]').prop('checked', true);
        $('#master-participant-check').prop('checked', true);
    });

    $('#deselect-all-participants').on('click', () => {
        $('#available-participants-tbody input[type="checkbox"]').prop('checked', false);
        $('#master-participant-check').prop('checked', false);
    });

    /**
     * Confirm Assignment
     */
    $('#confirm-assign-btn').on('click', function() {
        const selectedIds = $('#available-participants-tbody input[name="participant_ids[]"]:checked').map(function() {
            return this.value;
        }).get();

        if (selectedIds.length === 0) {
            alert(api.strings.noParticipants);
            return;
        }

        if (!confirm(api.strings.confirmAssign)) return;

        const $btn = $(this);
        $btn.text(api.strings.loading).prop('disabled', true);

        $.post(api.ajaxUrl, {
            action: 'eipsi_assign_participants',
            study_id: studyId,
            wave_id: activeWaveId,
            participant_ids: selectedIds,
            nonce: api.nonce
        }, function(response) {
            if (response.success) {
                showMessage(response.data.message || api.strings.success);
                $assignModal.fadeOut();
                location.reload(); // Reload to update stats on cards
            } else {
                showMessage(response.data || api.strings.error, true);
                $btn.text('Asignar Seleccionados').prop('disabled', false);
            }
        });
    });

    /**
     * Send Reminders
     */
    $('.eipsi-send-reminder-btn').on('click', function() {
        const $card = $(this).closest('.eipsi-wave-card');
        const waveId = $card.data('wave-id');

        if (!confirm('¿Enviar recordatorios manuales a todos los participantes pendientes de esta onda?')) return;

        $(this).text('⏳...').prop('disabled', true);

        $.post(api.ajaxUrl, {
            action: 'eipsi_send_reminder',
            wave_id: waveId,
            nonce: api.nonce
        }, (response) => {
            if (response.success) {
                showMessage(response.data.message || api.strings.success);
            } else {
                showMessage(response.data || api.strings.error, true);
            }
            $(this).text('Recordatorio').prop('disabled', false);
        });
    });

    /**
     * Extend Deadline
     */
    $('.eipsi-extend-deadline-btn').on('click', function() {
        const $card = $(this).closest('.eipsi-wave-card');
        const waveId = $card.data('wave-id');
        const newDeadline = prompt('Nueva fecha de vencimiento (YYYY-MM-DD HH:MM):');

        if (!newDeadline) return;

        $.post(api.ajaxUrl, {
            action: 'eipsi_extend_deadline',
            wave_id: waveId,
            new_deadline: newDeadline,
            nonce: api.nonce
        }, (response) => {
            if (response.success) {
                showMessage(api.strings.success);
                location.reload();
            } else {
                showMessage(response.data || api.strings.error, true);
            }
        });
    });

    /**
     * Close Modals
     */
    $('.eipsi-close-modal, .eipsi-close-modal-btn').on('click', function() {
        $(this).closest('.eipsi-modal').fadeOut();
    });

    $(window).on('click', function(e) {
        if ($(e.target).hasClass('eipsi-modal')) {
            $('.eipsi-modal').fadeOut();
        }
    });

    // ESC key support
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            $('.eipsi-modal').fadeOut();
        }
    });

    /**
     * Resend email dropdown toggle
     */
    $(document).on('click', '.resend-email-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const $dropdown = $(this).siblings('.resend-email-dropdown');
        $('.resend-email-dropdown').not($dropdown).hide();
        $dropdown.toggle();
    });

    // Close dropdowns on click outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.resend-email-wrapper').length) {
            $('.resend-email-dropdown').hide();
        }
    });

    /**
     * Resend email type selection
     */
    $(document).on('click', '.resend-email-option', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const participantId = $(this).data('participant-id');
        const emailType = $(this).data('email-type');
        const participantEmail = $(this).data('participant-email');
        const waveId = $(this).data('wave-id') || activeWaveId;
        
        $(this).closest('.resend-email-dropdown').hide();
        
        resendEmail(participantId, emailType, participantEmail, waveId);
    });

    /**
     * Render participants with resend email actions (for wave assignment modal)
     */
    function renderParticipantsWithEmailActions(participants, waveId) {
        const $tbody = $('#available-participants-tbody');
        $tbody.empty();

        if (participants.length === 0) {
            $tbody.append('<tr><td colspan="5" style="text-align:center;">Todos los participantes activos ya están asignados a esta onda.</td></tr>');
            return;
        }

        participants.forEach(p => {
            $tbody.append(`
                <tr data-participant-id="${p.id}">
                    <td class="check-column"><input type="checkbox" name="participant_ids[]" value="${p.id}"></td>
                    <td>${p.full_name}</td>
                    <td>${p.email}</td>
                    <td><code>${p.participant_id}</code></td>
                    <td>
                        <div class="resend-email-wrapper" style="position: relative; display: inline-block;">
                            <button type="button" class="button button-small resend-email-btn" title="Reenviar email">
                                📧
                            </button>
                            <div class="resend-email-dropdown" style="display:none; position:absolute; right:0; top:100%; background:#fff; border:1px solid #ccc; border-radius:4px; box-shadow:0 2px 5px rgba(0,0,0,0.15); z-index:1000; min-width:180px;">
                                <a href="#" class="resend-email-option" data-participant-id="${p.id}" data-email-type="magic_link" data-participant-email="${p.email}" data-wave-id="${waveId}" style="display:block; padding:8px 12px; text-decoration:none; color:#333;">
                                    ✨ Enviar Magic Link
                                </a>
                                <a href="#" class="resend-email-option" data-participant-id="${p.id}" data-email-type="reminder" data-participant-email="${p.email}" data-wave-id="${waveId}" style="display:block; padding:8px 12px; text-decoration:none; color:#333;">
                                    🔔 Recordatorio de Onda
                                </a>
                            </div>
                        </div>
                    </td>
                </tr>
            `);
        });
    }
});
