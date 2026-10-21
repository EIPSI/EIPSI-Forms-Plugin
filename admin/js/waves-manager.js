/**
 * EIPSI Forms - Waves Manager
 * Handles Waves CRUD, Assignments, Reminders, and Close & Anonymize Study modal
 *
 * @param {jQuery} $ - jQuery instance.
 * @package
 * @since 1.4.3
 */

( function ( $ ) {
	'use strict';

	// ===========================
	// STATE
	// ===========================

	let currentStep = 1;
	const totalSteps = 3;
	let modalNonce = '';
	let surveyId = 0;
	let currentWaveId = 0;

	// ===========================
	// INITIALIZATION
	// ===========================

	$( document ).ready( function () {
		initAnonymizeModal();
		initWavesManager();
		initEmailPreviewModal();
		initValidationModal();
	} );

	// ===========================
	// WAVES MANAGER FUNCTIONALITY
	// ===========================

	function initWavesManager() {
		// Initialize participants management
		initParticipantsManagement();

		// Time limit toggle
		$( document ).on( 'change', '#has_time_limit', function () {
			$( '#time-limit-input-container' ).toggle(
				$( this ).is( ':checked' )
			);
		} );

		// Validate dates on change
		$( document ).on(
			'change',
			'#start_date, #due_date, #wave_index',
			function () {
				validateWaveDatesOnChange();
			}
		);

		// Open Create Wave Modal
		$( document ).on( 'click', '#eipsi-create-wave-btn', function () {
			resetWaveForm();
			const nextIndex = $( this ).data( 'next-index' );
			$( '#wave_index' ).val( nextIndex );
			$( '#wave-modal-title' ).text( 'Crear Nueva Onda' );
			$( '#wave_id' ).val( '' );
			$( '#eipsi-wave-modal' ).fadeIn( 200 );
		} );

		// Open Edit Wave Modal
		$( document ).on( 'click', '.eipsi-edit-wave-btn', function () {
			const waveId = $( this ).data( 'wave-id' );
			currentWaveId = waveId;
			loadWaveData( waveId );
		} );

		// Close Modal
		$( document ).on(
			'click',
			'.eipsi-close-modal, .eipsi-close-modal-btn',
			function () {
				$( '.eipsi-modal' ).fadeOut( 200 );
			}
		);

		// Close on overlay click
		$( document ).on( 'click', '.eipsi-modal', function ( e ) {
			if ( e.target === this ) {
				$( this ).fadeOut( 200 );
			}
		} );

		// Save Wave Form
		$( document ).on( 'submit', '#eipsi-wave-form', function ( e ) {
			e.preventDefault();
			saveWave();
		} );

		// Delete Wave
		$( document ).on( 'click', '.eipsi-delete-wave-btn', function () {
			const waveId = $( this ).data( 'wave-id' );
			showConfirmationDialog( {
				title: 'Eliminar onda',
				message:
					eipsiWavesManagerData.strings.confirmDelete ||
					'¿Estás seguro de eliminar esta onda?',
				confirmText: 'Sí, eliminar',
				onConfirm() {
					deleteWave( waveId );
				},
			} );
		} );

		// Open Assign Participants Modal
		$( document ).on(
			'click',
			'.eipsi-assign-participants-btn',
			function () {
				const waveId = $( this ).data( 'wave-id' );
				currentWaveId = waveId;
				$( '#assign-wave-id' ).val( waveId );
				loadAvailableParticipants( waveId );
				$( '#eipsi-assign-modal' ).fadeIn( 200 );
			}
		);

		// Master checkbox for select all
		$( document ).on( 'change', '#master-participant-check', function () {
			$( '.participant-checkbox' ).prop(
				'checked',
				$( this ).is( ':checked' )
			);
		} );

		// Select/Deselect all buttons
		$( document ).on( 'click', '#select-all-participants', function () {
			$( '.participant-checkbox' ).prop( 'checked', true );
		} );

		$( document ).on( 'click', '#deselect-all-participants', function () {
			$( '.participant-checkbox' ).prop( 'checked', false );
		} );

		// Confirm Assign Participants
		$( document ).on( 'click', '#confirm-assign-btn', function () {
			const selectedIds = [];
			$( '.participant-checkbox:checked' ).each( function () {
				selectedIds.push( $( this ).val() );
			} );

			if ( selectedIds.length === 0 ) {
				showAlertDialog( {
					title: 'Selección requerida',
					message:
						eipsiWavesManagerData.strings.selectParticipants ||
						'Por favor selecciona al menos un participante.',
				} );
				return;
			}

			assignParticipants( currentWaveId, selectedIds );
		} );

		// Extend Deadline
		$( document ).on( 'click', '.eipsi-extend-deadline-btn', function () {
			const waveId = $( this ).data( 'wave-id' );
			currentWaveId = waveId;
			const defaultDeadline = new Date()
				.toISOString()
				.slice( 0, 16 )
				.replace( 'T', ' ' );

			showPromptDialog( {
				title: 'Extender vencimiento',
				message:
					'Ingresa la nueva fecha de vencimiento (YYYY-MM-DD HH:MM):',
				inputValue: defaultDeadline,
				inputPlaceholder: 'YYYY-MM-DD HH:MM',
				confirmText: 'Guardar fecha',
				onConfirm( newDeadline ) {
					if ( newDeadline ) {
						extendDeadline( waveId, newDeadline );
					}
				},
			} );
		} );

		// Toggle follow-up reminders
		$( document ).on( 'change', '.eipsi-follow-up-toggle', function () {
			const waveId = $( this ).data( 'wave-id' );
			const enabled = $( this ).is( ':checked' ) ? 1 : 0;
			const $toggle = $( this );

			$.ajax( {
				url:
					eipsiWavesManagerData.ajaxUrl ||
					typeof ajaxurl !== 'undefined'
						? ajaxurl
						: '/wp-admin/admin-ajax.php',
				type: 'POST',
				data: {
					action: 'eipsi_toggle_follow_up_reminders',
					wave_id: waveId,
					enabled: enabled,
					nonce: eipsiWavesManagerData.wavesNonce,
				},
				success: function ( response ) {
					if ( response.success ) {
						showNotification( response.data.message, 'success' );
					} else {
						showNotification(
							response.data.message || 'Error al actualizar',
							'error'
						);
						$toggle.prop( 'checked', ! enabled ); // Revert on error
					}
				},
				error: function () {
					showNotification( 'Error de conexión', 'error' );
					$toggle.prop( 'checked', ! enabled ); // Revert on error
				},
			} );
		} );

		// Config reminders button
		$( document ).on( 'click', '.eipsi-config-reminders-btn', function () {
			const waveId = $( this ).data( 'wave-id' );
			openReminderConfigModal( waveId );
		} );
	}

	// ===========================
	// WAVE CRUD OPERATIONS
	// ===========================

	function loadWaveData( waveId ) {
		const $btn = $( '.eipsi-edit-wave-btn[data-wave-id="' + waveId + '"]' );
		const originalText = $btn.text();
		$btn.text( 'Cargando...' ).prop( 'disabled', true );

		$.ajax( {
			url:
				eipsiWavesManagerData.ajaxUrl || typeof ajaxurl !== 'undefined'
					? ajaxurl
					: '/wp-admin/admin-ajax.php',
			type: 'GET',
			data: {
				action: 'eipsi_get_wave',
				wave_id: waveId,
				nonce: eipsiWavesManagerData.wavesNonce,
			},
			success( response ) {
				if ( response.success && response.data ) {
					populateWaveForm( response.data );
					$( '#wave-modal-title' ).text( 'Editar Onda' );
					$( '#eipsi-wave-modal' ).fadeIn( 200 );
				} else {
					showNotification(
						response.data || 'Error al cargar los datos de la onda',
						'error'
					);
				}
			},
			error() {
				showNotification(
					'Error de conexión al cargar los datos',
					'error'
				);
			},
			complete() {
				$btn.text( originalText ).prop( 'disabled', false );
			},
		} );
	}

	function populateWaveForm( waveData ) {
		$( '#wave_id' ).val( waveData.id );
		$( '#wave_name' ).val( waveData.name );
		$( '#wave_index' ).val( waveData.wave_index ).prop( 'readonly', true );
		$( '#form_id' ).prop( 'disabled', !!waveData.has_assignments );
		$( '#start_date, #due_date' ).prop( 'disabled', !!waveData.has_assignments );
		$( '#wave_status' ).val( waveData.status );
		$( '#form_id' ).val( waveData.form_id );
		$( '#start_date' ).val(
			waveData.start_date ? waveData.start_date.replace( ' ', 'T' ).slice( 0, 16 ) : ''
		);
		$( '#due_date' ).val(
			waveData.due_date ? waveData.due_date.replace( ' ', 'T' ).slice( 0, 16 ) : ''
		);
		$( '#wave_description' ).val( waveData.description || '' );
		$( 'input[name="is_mandatory"]' ).prop(
			'checked',
			parseInt( waveData.is_mandatory ) === 1
		);

		// Handle time limit fields
		const hasTimeLimit = parseInt( waveData.has_time_limit ) === 1;
		$( '#has_time_limit' ).prop( 'checked', hasTimeLimit );
		$( '#time-limit-input-container' ).toggle( hasTimeLimit );
		if ( hasTimeLimit && waveData.completion_time_limit ) {
			$( '#completion_time_limit' ).val( waveData.completion_time_limit );
		}
	}

	function resetWaveForm() {
		$( '#eipsi-wave-form' )[ 0 ].reset();
		$( '#wave_id' ).val( '' );
		$( '#wave_index' ).val( 1 ).prop( 'readonly', false );
		$( '#form_id' ).prop( 'disabled', false );
		$( '#start_date, #due_date' ).prop( 'disabled', false );
		$( 'input[name="is_mandatory"]' ).prop( 'checked', true );
	}

	function saveWave() {
		const $btn = $( '#save-wave-btn' );
		const originalText = $btn.text();
		$btn.text(
			eipsiWavesManagerData.strings.saving || 'Guardando...'
		).prop( 'disabled', true );

		$.ajax( {
			url:
				eipsiWavesManagerData.ajaxUrl ||
				( typeof ajaxurl !== 'undefined'
					? ajaxurl
					: '/wp-admin/admin-ajax.php' ),
			type: 'POST',
			data: {
				action: 'eipsi_save_wave',
				nonce: eipsiWavesManagerData.wavesNonce,
				wave_id: $( '#wave_id' ).val(),
				study_id: $( 'input[name="study_id"]' ).val(),
				name: $( '#wave_name' ).val(),
				wave_index: $( '#wave_index' ).val(),
				form_id: $( '#form_id' ).val(),
				start_date: $( '#start_date' ).prop( 'disabled' ) ? undefined : $( '#start_date' ).val(),
				due_date: $( '#due_date' ).prop( 'disabled' ) ? undefined : $( '#due_date' ).val(),
				status: $( '#wave_status' ).val(),
				is_mandatory:
					$( 'input[name="is_mandatory"]:checked' ).val() || 0,
				has_time_limit: $( '#has_time_limit' ).is( ':checked' ) ? 1 : 0,
				completion_time_limit: $( '#has_time_limit' ).is( ':checked' )
					? $( '#completion_time_limit' ).val()
					: null,
			},
			success( response ) {
				if ( response.success ) {
					showNotification(
						eipsiWavesManagerData.strings.waveSaved ||
							'Onda guardada exitosamente',
						'success'
					);
					$( '#eipsi-wave-modal' ).fadeOut( 200 );
					// Reload page after short delay
					setTimeout( function () {
						window.location.reload();
					}, 500 );
				} else {
					showNotification(
						response.data || 'Error al guardar la onda',
						'error'
					);
				}
			},
			error() {
				showNotification( 'Error de conexión', 'error' );
			},
			complete() {
				$btn.text( originalText ).prop( 'disabled', false );
			},
		} );
	}

	function deleteWave( waveId ) {
		const $btn = $(
			'.eipsi-delete-wave-btn[data-wave-id="' + waveId + '"]'
		);
		const originalText = $btn.text();
		$btn.text( 'Eliminando...' ).prop( 'disabled', true );

		$.ajax( {
			url:
				eipsiWavesManagerData.ajaxUrl ||
				( typeof ajaxurl !== 'undefined'
					? ajaxurl
					: '/wp-admin/admin-ajax.php' ),
			type: 'POST',
			data: {
				action: 'eipsi_delete_wave',
				nonce: eipsiWavesManagerData.wavesNonce,
				wave_id: waveId,
			},
			success( response ) {
				if ( response.success ) {
					showNotification(
						eipsiWavesManagerData.strings.waveDeleted ||
							'Onda eliminada',
						'success'
					);
					// Reload page after short delay
					setTimeout( function () {
						window.location.reload();
					}, 500 );
				} else {
					showNotification(
						response.data || 'Error al eliminar la onda',
						'error'
					);
					$btn.text( originalText ).prop( 'disabled', false );
				}
			},
			error() {
				showNotification( 'Error de conexión', 'error' );
				$btn.text( originalText ).prop( 'disabled', false );
			},
		} );
	}

	// ===========================
	// PARTICIPANT ASSIGNMENT
	// ===========================

	function loadAvailableParticipants( waveId ) {
		const $tbody = $( '#available-participants-tbody' );
		$tbody.html(
			'<tr><td colspan="4" style="text-align:center;padding:20px;"><span class="spinner is-active"></span> Cargando participantes...</td></tr>'
		);

		$.ajax( {
			url:
				eipsiWavesManagerData.ajaxUrl ||
				( typeof ajaxurl !== 'undefined'
					? ajaxurl
					: '/wp-admin/admin-ajax.php' ),
			type: 'GET',
			data: {
				action: 'eipsi_get_available_participants',
				nonce: eipsiWavesManagerData.wavesNonce,
				study_id: eipsiWavesManagerData.studyId,
				wave_id: waveId,
			},
			success( response ) {
				if ( response.success && response.data ) {
					renderParticipantsList( response.data );
				} else {
					$tbody.html(
						'<tr><td colspan="4" style="text-align:center;padding:20px;color:#666;">' +
							( eipsiWavesManagerData.strings.noParticipants ||
								'No hay participantes disponibles' ) +
							'</td></tr>'
					);
				}
			},
			error() {
				$tbody.html(
					'<tr><td colspan="4" style="text-align:center;padding:20px;color:#d63638;">Error al cargar participantes</td></tr>'
				);
			},
		} );
	}

	function renderParticipantsList( participants ) {
		const $tbody = $( '#available-participants-tbody' );

		if ( participants.length === 0 ) {
			$tbody.html(
				'<tr><td colspan="4" style="text-align:center;padding:20px;color:#666;">' +
					( eipsiWavesManagerData.strings.noParticipants ||
						'No hay participantes disponibles' ) +
					'</td></tr>'
			);
			return;
		}

		let html = '';
		participants.forEach( function ( p ) {
			html +=
				'<tr>' +
				'<td class="check-column"><input type="checkbox" class="participant-checkbox" value="' +
				p.id +
				'"></td>' +
				'<td>' +
				escapeHtml( p.full_name || p.first_name + ' ' + p.last_name ) +
				'</td>' +
				'<td>' +
				escapeHtml( p.email ) +
				'</td>' +
				'<td><code>' +
				escapeHtml( p.participant_id ) +
				'</code></td>' +
				'</tr>';
		} );
		$tbody.html( html );
	}

	function assignParticipants( waveId, participantIds ) {
		const $btn = $( '#confirm-assign-btn' );
		const originalText = $btn.text();
		$btn.text( 'Asignando...' ).prop( 'disabled', true );

		$.ajax( {
			url:
				eipsiWavesManagerData.ajaxUrl ||
				( typeof ajaxurl !== 'undefined'
					? ajaxurl
					: '/wp-admin/admin-ajax.php' ),
			type: 'POST',
			data: {
				action: 'eipsi_assign_participants',
				nonce: eipsiWavesManagerData.wavesNonce,
				study_id: eipsiWavesManagerData.studyId,
				wave_id: waveId,
				participant_ids: participantIds,
			},
			success( response ) {
				if ( response.success ) {
					const msg =
						response.data.assigned_count +
						' ' +
						( eipsiWavesManagerData.strings.participantsAssigned ||
							'participantes asignados' );
					showNotification( msg, 'success' );
					$( '#eipsi-assign-modal' ).fadeOut( 200 );
					// Reload page after short delay
					setTimeout( function () {
						window.location.reload();
					}, 500 );
				} else {
					showNotification(
						response.data || 'Error al asignar participantes',
						'error'
					);
				}
			},
			error() {
				showNotification( 'Error de conexión', 'error' );
			},
			complete() {
				$btn.text( originalText ).prop( 'disabled', false );
			},
		} );
	}

	// ===========================
	// DEADLINE EXTENSION
	// ===========================

	function extendDeadline( waveId, newDeadline ) {
		const $btn = $(
			'.eipsi-extend-deadline-btn[data-wave-id="' + waveId + '"]'
		);
		const originalText = $btn.text();
		$btn.text( 'Extendiendo...' ).prop( 'disabled', true );

		$.ajax( {
			url:
				eipsiWavesManagerData.ajaxUrl ||
				( typeof ajaxurl !== 'undefined'
					? ajaxurl
					: '/wp-admin/admin-ajax.php' ),
			type: 'POST',
			data: {
				action: 'eipsi_extend_deadline',
				nonce: eipsiWavesManagerData.wavesNonce,
				wave_id: waveId,
				new_deadline: newDeadline,
			},
			success( response ) {
				if ( response.success ) {
					showNotification(
						eipsiWavesManagerData.strings.deadlineExtended ||
							'Plazo extendido',
						'success'
					);
					// Reload page after short delay
					setTimeout( function () {
						window.location.reload();
					}, 500 );
				} else {
					showNotification(
						response.data || 'Error al extender plazo',
						'error'
					);
				}
			},
			error() {
				showNotification( 'Error de conexión', 'error' );
			},
			complete() {
				$btn.text( originalText ).prop( 'disabled', false );
			},
		} );
	}

	// ===========================
	// SEND REMINDERS
	// ===========================

	function sendReminders( waveId ) {
		const $btn = $(
			'.eipsi-send-reminder-btn[data-wave-id="' + waveId + '"]'
		);
		const originalText = $btn.text();
		$btn.text(
			eipsiWavesManagerData.strings.sending || 'Enviando...'
		).prop( 'disabled', true );

		$.ajax( {
			url:
				eipsiWavesManagerData.ajaxUrl ||
				( typeof ajaxurl !== 'undefined'
					? ajaxurl
					: '/wp-admin/admin-ajax.php' ),
			type: 'POST',
			data: {
				action: 'eipsi_send_reminder',
				nonce: eipsiWavesManagerData.wavesNonce,
				wave_id: waveId,
			},
			success( response ) {
				if ( response.success ) {
					const msg =
						response.data.sent +
						' ' +
						( eipsiWavesManagerData.strings.remindersSent ||
							'recordatorios enviados' );
					showNotification( msg, 'success' );
				} else {
					showNotification(
						response.data || 'Error al enviar recordatorios',
						'error'
					);
				}
			},
			error() {
				showNotification( 'Error de conexión', 'error' );
			},
			complete() {
				$btn.text( originalText ).prop( 'disabled', false );
			},
		} );
	}

	// ===========================
	// NOTIFICATION HELPER
	// ===========================

	function showNotification( message, type ) {
		// Remove existing notifications
		$( '.eipsi-notification' ).remove();

		const cssClass = type === 'success' ? 'notice-success' : 'notice-error';
		const icon = type === 'success' ? '✓' : '✗';

		const $notification = $(
			'<div class="eipsi-notification ' +
				cssClass +
				'" style="position:fixed;top:50px;right:20px;z-index:99999;padding:12px 20px;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,0.15);">' +
				'<strong>' +
				icon +
				'</strong> ' +
				message +
				'</div>'
		);

		$( 'body' ).append( $notification );

		// Auto-remove after 4 seconds
		setTimeout( function () {
			$notification.fadeOut( function () {
				$( this ).remove();
			} );
		}, 4000 );
	}

	function showAlertDialog( options ) {
		const settings = $.extend(
			{
				title: 'Aviso',
				message: '',
				confirmText: 'Aceptar',
				onConfirm: null,
			},
			options
		);

		createDialog( {
			title: settings.title,
			message: settings.message,
			confirmText: settings.confirmText,
			showCancel: false,
			onConfirm: settings.onConfirm,
		} );
	}

	function showConfirmationDialog( options ) {
		const settings = $.extend(
			{
				title: 'Confirmar acción',
				message: '',
				confirmText: 'Confirmar',
				cancelText: 'Cancelar',
				onConfirm: null,
				onCancel: null,
			},
			options
		);

		createDialog( {
			title: settings.title,
			message: settings.message,
			confirmText: settings.confirmText,
			cancelText: settings.cancelText,
			showCancel: true,
			onConfirm: settings.onConfirm,
			onCancel: settings.onCancel,
		} );
	}

	function showPromptDialog( options ) {
		const settings = $.extend(
			{
				title: 'Ingresar datos',
				message: '',
				confirmText: 'Guardar',
				cancelText: 'Cancelar',
				inputValue: '',
				inputPlaceholder: '',
				required: true,
				requiredMessage: 'Por favor completa este campo.',
				onConfirm: null,
				onCancel: null,
			},
			options
		);

		createDialog( {
			title: settings.title,
			message: settings.message,
			confirmText: settings.confirmText,
			cancelText: settings.cancelText,
			showCancel: true,
			input: true,
			inputValue: settings.inputValue,
			inputPlaceholder: settings.inputPlaceholder,
			required: settings.required,
			requiredMessage: settings.requiredMessage,
			onConfirm: settings.onConfirm,
			onCancel: settings.onCancel,
		} );
	}

	function createDialog( settings ) {
		$( '.eipsi-dialog-overlay' ).remove();

		const dialogId = 'eipsi-dialog-' + Date.now();
		const safeTitle = escapeHtml( settings.title || '' );
		const safeMessage = formatDialogMessage( settings.message );
		const safeConfirmText = escapeHtml( settings.confirmText || 'Aceptar' );
		const safeCancelText = escapeHtml( settings.cancelText || 'Cancelar' );

		const $overlay = $(
			'<div class="eipsi-dialog-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.55);display:flex;align-items:center;justify-content:center;z-index:1000000;"></div>'
		);

		const inputHtml = settings.input
			? '<input type="text" class="eipsi-dialog-input" style="width:100%;padding:8px 10px;border:1px solid #ccd0d4;border-radius:4px;margin-bottom:12px;" value="' +
			  escapeHtml( settings.inputValue || '' ) +
			  '" placeholder="' +
			  escapeHtml( settings.inputPlaceholder || '' ) +
			  '">'
			: '';

		const errorHtml =
			'<div class="eipsi-dialog-error" style="display:none;color:#d63638;margin-bottom:12px;font-size:13px;"></div>';

		const cancelButtonHtml = settings.showCancel
			? '<button type="button" class="button eipsi-dialog-cancel">' +
			  safeCancelText +
			  '</button>'
			: '';

		const $dialog = $(
			'<div class="eipsi-dialog" role="dialog" aria-modal="true" aria-labelledby="' +
				dialogId +
				'-title" style="background:#fff;border-radius:8px;max-width:480px;width:90%;box-shadow:0 10px 30px rgba(0,0,0,0.25);padding:20px;">' +
				'<h3 id="' +
				dialogId +
				'-title" style="margin:0 0 12px;font-size:18px;">' +
				safeTitle +
				'</h3>' +
				'<div class="eipsi-dialog-message" style="margin-bottom:16px;color:#333;line-height:1.5;">' +
				safeMessage +
				'</div>' +
				errorHtml +
				inputHtml +
				'<div class="eipsi-dialog-actions" style="display:flex;gap:10px;justify-content:flex-end;">' +
				cancelButtonHtml +
				'<button type="button" class="button button-primary eipsi-dialog-confirm">' +
				safeConfirmText +
				'</button>' +
				'</div>' +
				'</div>'
		);

		$overlay.append( $dialog );
		$( 'body' ).append( $overlay );

		const $confirmButton = $dialog.find( '.eipsi-dialog-confirm' );
		const $cancelButton = $dialog.find( '.eipsi-dialog-cancel' );
		const $input = $dialog.find( '.eipsi-dialog-input' );
		const $error = $dialog.find( '.eipsi-dialog-error' );

		function closeDialog() {
			$overlay.remove();
			$( document ).off( 'keydown', handleKeydown );
		}

		function handleConfirm() {
			let value = null;
			if ( settings.input ) {
				value = $input.val().trim();
				if ( settings.required && ! value ) {
					$error.text( settings.requiredMessage ).show();
					$input.trigger( 'focus' );
					return;
				}
			}

			if ( typeof settings.onConfirm === 'function' ) {
				settings.onConfirm( value );
			}
			closeDialog();
		}

		function handleCancel() {
			if ( typeof settings.onCancel === 'function' ) {
				settings.onCancel();
			}
			closeDialog();
		}

		function handleKeydown( event ) {
			if ( event.key === 'Escape' ) {
				handleCancel();
			} else if ( event.key === 'Enter' ) {
				handleConfirm();
			}
		}

		$confirmButton.on( 'click', handleConfirm );
		if ( $cancelButton.length ) {
			$cancelButton.on( 'click', handleCancel );
		}

		$overlay.on( 'click', function ( event ) {
			if ( event.target === this ) {
				handleCancel();
			}
		} );

		if ( $input.length ) {
			$input.trigger( 'focus' );
			$input.on( 'input', function () {
				$error.hide();
			} );
		} else {
			$confirmButton.trigger( 'focus' );
		}

		$( document ).on( 'keydown', handleKeydown );
	}

	function formatDialogMessage( message ) {
		const safeMessage = escapeHtml( message || '' );
		return safeMessage.replace( /\n/g, '<br>' );
	}

	// ===========================
	// ANONYMIZE MODAL (EXISTING)
	// ===========================

	function initAnonymizeModal() {
		// Get nonce from localized data (WordPress global)
		modalNonce =
			typeof eipsiWavesManagerData !== 'undefined'
				? eipsiWavesManagerData.anonymizeNonce
				: '';

		// Event: Open modal
		$( document ).on( 'click', '#eipsi-open-anonymize-modal', function () {
			surveyId = $( this ).data( 'survey-id' );
			currentStep = 1;
			resetModal();
			$( '#eipsi-anonymize-modal' ).fadeIn( 200 );
			updateModalView();
		} );

		// Event: Close modal (X button)
		$( document ).on( 'click', '#eipsi-close-modal', function () {
			closeModal();
		} );

		// Event: Close modal (Cancel button)
		$( document ).on( 'click', '#eipsi-modal-cancel', function () {
			closeModal();
		} );

		// Event: Close modal on overlay click
		$( document ).on(
			'click',
			'#eipsi-anonymize-modal .eipsi-modal-overlay',
			function () {
				closeModal();
			}
		);

		// Event: Next button
		$( document ).on( 'click', '#eipsi-modal-next', function () {
			if ( validateStep( currentStep ) ) {
				if ( currentStep === totalSteps ) {
					submitAnonymization();
				} else {
					currentStep++;
					updateModalView();
				}
			}
		} );

		// Event: Previous button
		$( document ).on( 'click', '#eipsi-modal-prev', function () {
			if ( currentStep > 1 ) {
				currentStep--;
				updateModalView();
			}
		} );

		// Event: Real-time validation for step 3 (text input)
		$( document ).on( 'keyup', '#eipsi-confirm-text', function () {
			validateStep3();
		} );
	}

	// ===========================
	// ANONYMIZE MODAL VIEW UPDATE
	// ===========================

	function updateModalView() {
		// Hide all steps
		$( '.eipsi-modal-step' ).hide();

		// Show current step
		$( '#step-' + currentStep ).show();

		// Update title
		const title =
			'Cerrar & Anonimizar Estudio - Paso ' +
			currentStep +
			'/' +
			totalSteps;
		$( '#eipsi-modal-title' ).text( title );

		// Update "Next" button
		const nextText =
			currentStep === totalSteps ? '✅ Anonimizar Ahora' : 'Siguiente →';
		const nextBtn = $( '#eipsi-modal-next' );
		nextBtn.text( nextText );

		// Disable next button on step 3 if validation fails
		if ( currentStep === totalSteps ) {
			nextBtn.prop( 'disabled', ! isStep3Valid() );
			validateStep3(); // Show feedback
		} else {
			nextBtn.prop( 'disabled', false );
		}

		// Toggle previous button visibility
		$( '#eipsi-modal-prev' ).toggle( currentStep > 1 );
	}

	// ===========================
	// ANONYMIZE VALIDATIONS
	// ===========================

	function validateStep( step ) {
		if ( step === 1 ) {
			return validateStep1();
		} else if ( step === 2 ) {
			return validateStep2();
		} else if ( step === 3 ) {
			return isStep3Valid();
		}
		return false;
	}

	function validateStep1() {
		// All 6 checkboxes must be checked
		const allChecked =
			$( '#eipsi-confirm-1' ).is( ':checked' ) &&
			$( '#eipsi-confirm-2' ).is( ':checked' ) &&
			$( '#eipsi-confirm-3' ).is( ':checked' ) &&
			$( '#eipsi-confirm-4' ).is( ':checked' ) &&
			$( '#eipsi-confirm-5' ).is( ':checked' ) &&
			$( '#eipsi-confirm-6' ).is( ':checked' );

		if ( ! allChecked ) {
			showErrorMessage(
				'Por favor, confirma que entiendes todas las consecuencias de anonimizar.'
			);
			return false;
		}
		return true;
	}

	function validateStep2() {
		// Close reason is required
		const reason = $( '#eipsi-close-reason' ).val();
		if ( ! reason || reason.trim() === '' ) {
			showErrorMessage(
				'Por favor, selecciona una razón para cerrar el estudio.'
			);
			return false;
		}
		return true;
	}

	function validateStep3() {
		const confirmText = $( '#eipsi-confirm-text' )
			.val()
			.toUpperCase()
			.trim();
		const isValid = confirmText === 'ANONIMIZAR';

		// Update button state
		$( '#eipsi-modal-next' ).prop( 'disabled', ! isValid );

		// Show feedback message
		const msgDiv = $( '#eipsi-step3-message' );
		if ( confirmText.length > 0 && ! isValid ) {
			msgDiv
				.show()
				.attr(
					'style',
					'background: #ffe6e6; color: #d63031; display: block;'
				)
				.text(
					'❌ El texto no coincide. Escribe exactamente: ANONIMIZAR'
				);
		} else if ( isValid ) {
			msgDiv
				.show()
				.attr(
					'style',
					'background: #e6ffe6; color: #27ae60; display: block;'
				)
				.text(
					'✅ Correcto. Haz clic en "Anonimizar Ahora" para confirmar.'
				);
		} else {
			msgDiv.hide();
		}
	}

	function isStep3Valid() {
		const confirmText = $( '#eipsi-confirm-text' )
			.val()
			.toUpperCase()
			.trim();
		return confirmText === 'ANONIMIZAR';
	}

	// ===========================
	// ANONYMIZE SUBMISSION
	// ===========================

	function submitAnonymization() {
		const button = $( '#eipsi-modal-next' );
		button.prop( 'disabled', true );
		button.text( '⏳ Procesando...' );

		const closeReason = $( '#eipsi-close-reason' ).val();
		const closeNotes = $( '#eipsi-close-notes' ).val();

		// Make AJAX request
		$.ajax( {
			url:
				typeof ajaxurl !== 'undefined'
					? ajaxurl
					: '/wp-admin/admin-ajax.php',
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'eipsi_anonymize_survey',
				survey_id: surveyId,
				nonce: modalNonce,
				close_reason: closeReason,
				close_notes: closeNotes,
			},
			success( response ) {
				if ( response.success ) {
					showSuccess( response.data );
				} else {
					showErrorMessage(
						response.data.message || 'Error desconocido'
					);
					button.prop( 'disabled', false );
					button.text( '✅ Anonimizar Ahora' );
				}
			},
			error( xhr, status, error ) {
				showErrorMessage( 'Error en la solicitud: ' + error );
				button.prop( 'disabled', false );
				button.text( '✅ Anonimizar Ahora' );
			},
		} );
	}

	function showSuccess( data ) {
		// Hide all steps
		$( '.eipsi-modal-step' ).hide();
		$( '#step-success' ).show();

		// Update title
		$( '#eipsi-modal-title' ).text( '✅ Anonimización Completada' );

		// Update success message
		const successMsg =
			'<strong>' +
			escapeHtml( data.survey_title ) +
			'</strong><br>' +
			data.anonymized_count +
			' participante(s) anonimizado(s) exitosamente';
		$( '#eipsi-success-message' ).html( successMsg );

		// Show details
		$( '#eipsi-success-details' ).html(
			'<strong>Acciones realizadas:</strong><br>' +
				'✅ Emails eliminados<br>' +
				'✅ Contraseñas eliminadas<br>' +
				'✅ Nombres eliminados<br>' +
				'✅ Magic links invalidados<br>' +
				'✅ Audit log registrado<br><br>' +
				'<strong>Próximos pasos:</strong><br>' +
				'1. La página se recargará en 3 segundos<br>' +
				'2. Descarga un backup de la encuesta si es necesario<br>' +
				'3. Notifica a los participantes si aplica'
		);

		// Update footer (replace buttons)
		$( '.eipsi-modal-footer' ).html(
			'<button type="button" class="button button-primary" id="eipsi-modal-reload">Recargar Página</button>'
		);

		// Event: Reload button
		$( document ).on( 'click', '#eipsi-modal-reload', function () {
			window.location.reload();
		} );

		// Auto-reload after 3 seconds
		setTimeout( function () {
			window.location.reload();
		}, 3000 );
	}

	function showErrorMessage( message ) {
		// Create a custom modal for errors instead of using alert
		const errorModal = $(
			'<div class="notice notice-error" style="padding: 10px; margin: 10px 0; border-left: 4px solid #dc3232;"><p>❌ Error: ' +
				escapeHtml( message ) +
				'</p></div>'
		);
		$( '.eipsi-modal-content' ).prepend( errorModal );

		// Auto-remove after 5 seconds
		setTimeout( function () {
			errorModal.fadeOut( 500, function () {
				errorModal.remove();
			} );
		}, 5000 );
	}

	// ===========================
	// ANONYMIZE MODAL MANAGEMENT
	// ===========================

	function resetModal() {
		// Reset all inputs
		$(
			'#eipsi-confirm-1, #eipsi-confirm-2, #eipsi-confirm-3, #eipsi-confirm-4, #eipsi-confirm-5, #eipsi-confirm-6'
		).prop( 'checked', false );
		$( '#eipsi-close-reason' ).val( '' );
		$( '#eipsi-close-notes' ).val( '' );
		$( '#eipsi-confirm-text' ).val( '' );
		$( '#eipsi-step3-message' ).hide();

		// Reset modal view
		currentStep = 1;
		$( '.eipsi-modal-step' ).show();
		$( '#step-success' ).hide();
		$( '.eipsi-modal-footer' ).show();

		// Reset buttons
		$( '#eipsi-modal-next' )
			.text( 'Siguiente →' )
			.prop( 'disabled', false );
		$( '#eipsi-modal-prev' ).show();

		// Remove any error messages
		$( '.notice-error' ).remove();
	}

	function closeModal() {
		resetModal();
		$( '#eipsi-anonymize-modal' ).fadeOut( 200 );
	}

	// ===========================
	// PARTICIPANTS MANAGEMENT
	// ===========================

	function initParticipantsManagement() {
		// Only initialize if participants section exists
		if ( $( '#participants-table' ).length === 0 ) {
			return;
		}

		// Load participants on page load
		loadParticipants();

		// Open Multi-Method Add Participant Modal (unified handler)
		$( document ).on( 'click', '#eipsi-add-participant-btn', function () {
			$( '#eipsi-add-participant-multi-modal' ).fadeIn( 200 );
			// Reset forms
			$( '#eipsi-form-magic-link' )[ 0 ].reset();
			$( '#eipsi-form-bulk' )[ 0 ].reset();
			$( '#bulk-results' ).hide();
			$( '#public-registration-url' ).val( '' );
			// Show first tab by default
			$( '.eipsi-tab-btn' ).removeClass( 'active' );
			$( '.eipsi-tab-content' ).removeClass( 'active' );
			$( '.eipsi-tab-btn[data-tab="magic-link"]' ).addClass( 'active' );
			$( '#tab-magic-link' ).addClass( 'active' );
		} );

		// Open Edit Participant Modal
		$( document ).on( 'click', '.eipsi-edit-participant-btn', function () {
			const participantId = $( this ).data( 'participant-id' );
			loadParticipantData( participantId );
		} );

		// Delete Participant
		$( document ).on(
			'click',
			'.eipsi-delete-participant-btn',
			function () {
				const participantId = $( this ).data( 'participant-id' );
				const hasSubmissions = $( this ).data( 'has-submissions' );

				let confirmMessage =
					eipsiWavesManagerData.strings.confirmDeleteParticipant ||
					'¿Estás seguro de eliminar este participante?';

				if ( hasSubmissions ) {
					confirmMessage +=
						'\n\n⚠️ Este participante tiene respuestas registradas. Se desactivará en lugar de eliminarse.';
				}

				showConfirmationDialog( {
					title: 'Eliminar participante',
					message: confirmMessage,
					confirmText: hasSubmissions
						? 'Desactivar participante'
						: 'Sí, eliminar',
					onConfirm() {
						deleteParticipant( participantId, ! hasSubmissions );
					},
				} );
			}
		);

		// Save Participant Form
		$( document ).on( 'submit', '#eipsi-participant-form', function ( e ) {
			e.preventDefault();
			saveParticipant();
		} );
	}

	function loadParticipants() {
		const $tbody = $( '#participants-tbody' );

		$.ajax( {
			url:
				eipsiWavesManagerData.ajaxUrl ||
				( typeof ajaxurl !== 'undefined'
					? ajaxurl
					: '/wp-admin/admin-ajax.php' ),
			type: 'GET',
			data: {
				action: 'eipsi_get_study_participants',
				nonce: eipsiWavesManagerData.wavesNonce,
				study_id: eipsiWavesManagerData.studyId,
			},
			success( response ) {
				if ( response.success && response.data ) {
					renderParticipantsTable( response.data );
				} else {
					$tbody.html(
						'<tr><td colspan="6" style="text-align:center;padding:20px;color:#666;">' +
							'No hay participantes registrados en este estudio.' +
							'</td></tr>'
					);
				}
			},
			error() {
				$tbody.html(
					'<tr><td colspan="6" style="text-align:center;padding:20px;color:#d63638;">' +
						'Error al cargar participantes.' +
						'</td></tr>'
				);
			},
		} );
	}

	function renderParticipantsTable( participants ) {
		const $tbody = $( '#participants-tbody' );

		if ( participants.length === 0 ) {
			$tbody.html(
				'<tr><td colspan="6" style="text-align:center;padding:20px;color:#666;">' +
					'No hay participantes registrados en este estudio.' +
					'</td></tr>'
			);
			return;
		}

		let html = '';
		participants.forEach( function ( p ) {
			const statusClass = p.is_active
				? 'status-active'
				: 'status-inactive';
			const statusText = p.is_active ? 'Activo' : 'Inactivo';
			const fullName =
				escapeHtml(
					( p.first_name || '' ) + ' ' + ( p.last_name || '' )
				).trim() || '—';
			const registeredDate = p.created_at
				? new Date( p.created_at ).toLocaleDateString()
				: '—';

			html +=
				'<tr>' +
				'<td><code>' +
				p.id +
				'</code></td>' +
				'<td>' +
				fullName +
				'</td>' +
				'<td>' +
				escapeHtml( p.email ) +
				'</td>' +
				'<td><span class="participant-status ' +
				statusClass +
				'">' +
				statusText +
				'</span></td>' +
				'<td>' +
				registeredDate +
				'</td>' +
				'<td>' +
				'<button type="button" class="button button-small eipsi-edit-participant-btn" data-participant-id="' +
				p.id +
				'">Editar</button> ' +
				'<button type="button" class="button button-small button-link-delete eipsi-delete-participant-btn" data-participant-id="' +
				p.id +
				'">Eliminar</button>' +
				'</td>' +
				'</tr>';
		} );
		$tbody.html( html );
	}

	function loadParticipantData( participantId ) {
		const $btn = $(
			'.eipsi-edit-participant-btn[data-participant-id="' +
				participantId +
				'"]'
		);
		const originalText = $btn.text();
		$btn.text( 'Cargando...' ).prop( 'disabled', true );

		$.ajax( {
			url:
				eipsiWavesManagerData.ajaxUrl ||
				( typeof ajaxurl !== 'undefined'
					? ajaxurl
					: '/wp-admin/admin-ajax.php' ),
			type: 'GET',
			data: {
				action: 'eipsi_get_participant',
				nonce: eipsiWavesManagerData.wavesNonce,
				participant_id: participantId,
			},
			success( response ) {
				if ( response.success && response.data ) {
					populateParticipantForm( response.data );
					$( '#participant-modal-title' ).text(
						'Editar Participante'
					);
					$( '#password-field-container' ).hide();
					$( '#active-field-container' ).show();
					$( '#save-participant-btn' ).text( 'Guardar Cambios' );
					$( '#eipsi-participant-modal' ).fadeIn( 200 );
				} else {
					showNotification(
						response.data || 'Error al cargar participante',
						'error'
					);
				}
			},
			error() {
				showNotification( 'Error de conexión', 'error' );
			},
			complete() {
				$btn.text( originalText ).prop( 'disabled', false );
			},
		} );
	}

	function populateParticipantForm( participantData ) {
		$( '#participant_id' ).val( participantData.id );
		$( '#participant_email' ).val( participantData.email );
		$( '#participant_first_name' ).val( participantData.first_name || '' );
		$( '#participant_last_name' ).val( participantData.last_name || '' );
		$( '#participant_is_active' ).prop(
			'checked',
			parseInt( participantData.is_active ) === 1
		);
	}

	function resetParticipantForm() {
		$( '#eipsi-participant-form' )[ 0 ].reset();
		$( '#participant_id' ).val( '' );
		$( '#participant_password' ).val( '' );
	}

	function saveParticipant() {
		const $btn = $( '#save-participant-btn' );
		const originalText = $btn.text();
		$btn.text( 'Guardando...' ).prop( 'disabled', true );

		const participantId = $( '#participant_id' ).val();
		const isEdit = participantId !== '';

		const formData = {
			action: isEdit ? 'eipsi_edit_participant' : 'eipsi_add_participant',
			nonce: eipsiWavesManagerData.wavesNonce,
			participant_id: participantId,
			study_id: eipsiWavesManagerData.studyId,
			email: $( '#participant_email' ).val(),
			first_name: $( '#participant_first_name' ).val(),
			last_name: $( '#participant_last_name' ).val(),
		};

		if ( ! isEdit ) {
			formData.password = $( '#participant_password' ).val();
		} else {
			formData.is_active = $( '#participant_is_active' ).is( ':checked' )
				? 1
				: 0;
		}

		$.ajax( {
			url:
				eipsiWavesManagerData.ajaxUrl ||
				( typeof ajaxurl !== 'undefined'
					? ajaxurl
					: '/wp-admin/admin-ajax.php' ),
			type: 'POST',
			data: formData,
			success( response ) {
				if ( response.success ) {
					let message = isEdit
						? eipsiWavesManagerData.strings.participantUpdated ||
						  'Participante actualizado'
						: 'Participante creado e invitación enviada.';

					if (
						! isEdit &&
						response.data &&
						response.data.email_sent === false
					) {
						message =
							'Participante creado, pero la invitación no pudo enviarse.';
					}

					showNotification( message, 'success' );
					$( '#eipsi-participant-modal' ).fadeOut( 200, function () {
						resetParticipantForm(); // Clean form for next open
					} );
					loadParticipants(); // Refresh list

					if (
						! isEdit &&
						response.data &&
						response.data.temporary_password &&
						response.data.email_sent === false
					) {
						setTimeout( function () {
							showAlertDialog( {
								title: 'Contraseña temporal',
								message:
									'Contraseña temporal generada: ' +
									response.data.temporary_password +
									'\n\nGuarda esta contraseña, solo se mostrará una vez.',
								confirmText: 'Entendido',
							} );
						}, 300 );
					}
				} else {
					showNotification(
						response.data || 'Error al guardar participante',
						'error'
					);
				}
			},
			error() {
				showNotification( 'Error de conexión', 'error' );
			},
			complete() {
				$btn.text( originalText ).prop( 'disabled', false );
			},
		} );
	}

	function deleteParticipant( participantId, deleteData ) {
		const $btn = $(
			'.eipsi-delete-participant-btn[data-participant-id="' +
				participantId +
				'"]'
		);
		const originalText = $btn.text();
		$btn.text( 'Eliminando...' ).prop( 'disabled', true );

		$.ajax( {
			url:
				eipsiWavesManagerData.ajaxUrl ||
				( typeof ajaxurl !== 'undefined'
					? ajaxurl
					: '/wp-admin/admin-ajax.php' ),
			type: 'POST',
			data: {
				action: 'eipsi_delete_participant',
				nonce: eipsiWavesManagerData.wavesNonce,
				participant_id: participantId,
				delete_data: deleteData ? 1 : 0,
			},
			success( response ) {
				if ( response.success ) {
					showNotification(
						eipsiWavesManagerData.strings.participantDeleted ||
							'Participante eliminado',
						'success'
					);
					loadParticipants(); // Refresh list
				} else {
					showNotification(
						response.data || 'Error al eliminar participante',
						'error'
					);
				}
			},
			error() {
				showNotification( 'Error de conexión', 'error' );
			},
			complete() {
				$btn.text( originalText ).prop( 'disabled', false );
			},
		} );
	}

	// ===========================
	// UTILITIES
	// ===========================

	/**
	 * Escape HTML to prevent XSS
	 * @param {string} unsafe Unsafe string
	 * @return {string} Escaped string
	 */
	function escapeHtml( unsafe ) {
		if ( ! unsafe ) {
			return '';
		}
		return unsafe
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	// ===========================
	// MANUAL REMINDER MODAL
	// ===========================

	function openManualReminderModal( waveId ) {
		$( '#reminder-wave-id' ).val( waveId );
		$( '#reminder-custom-message' ).val( '' );
		$( '#eipsi-manual-reminder-modal' ).fadeIn( 200 );
		loadPendingParticipants( waveId );
	}

	function loadPendingParticipants( waveId ) {
		const $tbody = $( '#pending-participants-tbody' );
		$tbody.html(
			'<tr><td colspan="4" style="text-align:center;padding:20px;"><span class="spinner is-active"></span> ' +
				( eipsiWavesManagerData.strings.loadingPending ||
					'Cargando participantes pendientes...' ) +
				'</td></tr>'
		);

		$.ajax( {
			url:
				eipsiWavesManagerData.ajaxUrl ||
				( typeof ajaxurl !== 'undefined'
					? ajaxurl
					: '/wp-admin/admin-ajax.php' ),
			type: 'GET',
			data: {
				action: 'eipsi_get_pending_participants',
				nonce: eipsiWavesManagerData.wavesNonce,
				study_id: eipsiWavesManagerData.studyId,
				wave_id: waveId,
			},
			success( response ) {
				if ( response.success && response.data ) {
					renderPendingParticipantsList( response.data );
				} else {
					$tbody.html(
						'<tr><td colspan="4" style="text-align:center;padding:20px;color:#666;">' +
							( eipsiWavesManagerData.strings
								.noPendingParticipants ||
								'No hay participantes pendientes para esta onda' ) +
							'</td></tr>'
					);
				}
			},
			error() {
				$tbody.html(
					'<tr><td colspan="4" style="text-align:center;padding:20px;color:#d63638;">Error al cargar participantes</td></tr>'
				);
			},
		} );
	}

	function renderPendingParticipantsList( participants ) {
		const $tbody = $( '#pending-participants-tbody' );

		if ( participants.length === 0 ) {
			$tbody.html(
				'<tr><td colspan="4" style="text-align:center;padding:20px;color:#666;">' +
					( eipsiWavesManagerData.strings.noPendingParticipants ||
						'No hay participantes pendientes para esta onda' ) +
					'</td></tr>'
			);
			return;
		}

		let html = '';
		participants.forEach( function ( p ) {
			html +=
				'<tr>' +
				'<td class="check-column"><input type="checkbox" class="pending-participant-checkbox" value="' +
				p.id +
				'"></td>' +
				'<td>' +
				escapeHtml(
					( p.first_name || '' ) + ' ' + ( p.last_name || '' )
				).trim() +
				'</td>' +
				'<td>' +
				escapeHtml( p.email ) +
				'</td>' +
				'<td><span class="status-pending">Pendiente</span></td>' +
				'</tr>';
		} );
		$tbody.html( html );
	}

	// Manual Reminder Modal Events
	$( document ).on(
		'change',
		'#master-pending-participant-check',
		function () {
			$( '.pending-participant-checkbox' ).prop(
				'checked',
				$( this ).is( ':checked' )
			);
		}
	);

	$( document ).on( 'click', '#select-all-pending-participants', function () {
		$( '.pending-participant-checkbox' ).prop( 'checked', true );
	} );

	$( document ).on(
		'click',
		'#deselect-all-pending-participants',
		function () {
			$( '.pending-participant-checkbox' ).prop( 'checked', false );
		}
	);

	$( document ).on( 'click', '#confirm-send-reminder-btn', function () {
		const selectedIds = [];
		$( '.pending-participant-checkbox:checked' ).each( function () {
			selectedIds.push( $( this ).val() );
		} );

		if ( selectedIds.length === 0 ) {
			showAlertDialog( {
				title: 'Selección requerida',
				message:
					eipsiWavesManagerData.strings.noParticipantsSelected ||
					'Por favor selecciona al menos un participante.',
			} );
			return;
		}

		showConfirmationDialog( {
			title: 'Enviar recordatorios',
			message:
				eipsiWavesManagerData.strings.confirmSendReminders ||
				'¿Enviar recordatorios a los participantes seleccionados?',
			confirmText: 'Sí, enviar',
			onConfirm() {
				sendManualReminders( selectedIds );
			},
		} );
	} );

	function sendManualReminders( participantIds ) {
		const waveId = $( '#reminder-wave-id' ).val();
		const studyId = $( '#reminder-study-id' ).val();
		const customMessage = $( '#reminder-custom-message' ).val();

		const $btn = $( '#confirm-send-reminder-btn' );
		const originalText = $btn.text();
		$btn.text( 'Enviando...' ).prop( 'disabled', true );

		$.ajax( {
			url:
				eipsiWavesManagerData.ajaxUrl ||
				( typeof ajaxurl !== 'undefined'
					? ajaxurl
					: '/wp-admin/admin-ajax.php' ),
			type: 'POST',
			data: {
				action: 'eipsi_send_reminder',
				nonce: eipsiWavesManagerData.wavesNonce,
				wave_id: waveId,
				study_id: studyId,
				participant_ids: participantIds,
				custom_message: customMessage,
			},
			success( response ) {
				if ( response.success ) {
					const message =
						response.data.message || 'Recordatorios enviados';
					showNotification( message, 'success' );
					$( '#eipsi-manual-reminder-modal' ).fadeOut( 200 );
				} else {
					showNotification(
						response.data || 'Error al enviar recordatorios',
						'error'
					);
				}
			},
			error() {
				showNotification( 'Error de conexión', 'error' );
			},
			complete() {
				$btn.text( originalText ).prop( 'disabled', false );
			},
		} );
	}

	// ===========================
	// TAB SWITCHING (Multi-Method Modal)
	// ===========================

	// Tab Switching
	$( document ).on( 'click', '.eipsi-tab-btn', function () {
		const targetTab = $( this ).data( 'tab' );

		// Update tab buttons
		$( '.eipsi-tab-btn' ).removeClass( 'active' );
		$( this ).addClass( 'active' );

		// Update tab content
		$( '.eipsi-tab-content' ).removeClass( 'active' );
		$( '#tab-' + targetTab ).addClass( 'active' );
	} );

	// Form Submit: Magic Link Individual
	$( document ).on( 'submit', '#eipsi-form-magic-link', function ( e ) {
		e.preventDefault();

		const $btn = $( '#btn-send-magic-link' );
		const originalText = $btn.html();
		$btn.html( '⏳ Enviando...' ).prop( 'disabled', true );

		$.ajax( {
			url: eipsiWavesManagerData.ajaxUrl || ajaxurl,
			type: 'POST',
			data: {
				action: 'eipsi_add_participant_magic_link',
				nonce:
					eipsiWavesManagerData.anonymizeNonce ||
					eipsiWavesManagerData.wavesNonce,
				study_id: $( '#add-participant-study-id' ).val(),
				email: $( '#ml-email' ).val(),
				first_name: $( '#ml-first-name' ).val(),
				last_name: $( '#ml-last-name' ).val(),
			},
			success( response ) {
				if ( response.success ) {
					showNotification(
						response.data.message ||
							'Participante agregado y email enviado',
						'success'
					);
					$( '#eipsi-form-magic-link' )[ 0 ].reset();
					// Reload participants table if visible
					if ( typeof loadParticipants === 'function' ) {
						loadParticipants();
					}
					// Close modal after 1.5s
					setTimeout( function () {
						$( '#eipsi-add-participant-multi-modal' ).fadeOut(
							200
						);
					}, 1500 );
				} else {
					showNotification(
						response.data.message ||
							'Error al agregar participante',
						'error'
					);
				}
			},
			error() {
				showNotification( 'Error de conexión', 'error' );
			},
			complete() {
				$btn.html( originalText ).prop( 'disabled', false );
			},
		} );
	} );

	// Form Submit: Bulk Add
	$( document ).on( 'submit', '#eipsi-form-bulk', function ( e ) {
		e.preventDefault();

		const $btn = $( '#btn-send-bulk' );
		const originalText = $btn.html();
		$btn.html( '⏳ Procesando...' ).prop( 'disabled', true );

		$( '#bulk-results' ).hide();

		$.ajax( {
			url: eipsiWavesManagerData.ajaxUrl || ajaxurl,
			type: 'POST',
			data: {
				action: 'eipsi_add_participants_bulk',
				nonce:
					eipsiWavesManagerData.anonymizeNonce ||
					eipsiWavesManagerData.wavesNonce,
				study_id: $( '#add-participant-study-id' ).val(),
				emails: $( '#bulk-emails' ).val(),
			},
			success( response ) {
				if ( response.success ) {
					const data = response.data;
					let resultsHtml = '<p><strong>Resumen:</strong></p>';
					resultsHtml += '<ul>';
					resultsHtml +=
						'<li class="success">✓ ' +
						data.success_count +
						' participantes agregados exitosamente</li>';
					if ( data.failed_count > 0 ) {
						resultsHtml +=
							'<li class="error">✗ ' +
							data.failed_count +
							' fallaron</li>';
					}
					resultsHtml += '</ul>';

					if ( data.errors && data.errors.length > 0 ) {
						resultsHtml +=
							'<p><strong>Detalles de errores:</strong></p><ul>';
						data.errors.forEach( function ( error ) {
							resultsHtml +=
								'<li class="error">' +
								escapeHtml( error ) +
								'</li>';
						} );
						resultsHtml += '</ul>';
					}

					$( '#bulk-results-content' ).html( resultsHtml );
					$( '#bulk-results' ).fadeIn( 300 );

					showNotification(
						data.message,
						data.failed_count > 0 ? 'warning' : 'success'
					);

					// Reload participants table if visible
					if ( typeof loadParticipants === 'function' ) {
						loadParticipants();
					}
				} else {
					showNotification(
						response.data.message || 'Error al procesar emails',
						'error'
					);
				}
			},
			error() {
				showNotification( 'Error de conexión', 'error' );
			},
			complete() {
				$btn.html( originalText ).prop( 'disabled', false );
			},
		} );
	} );

	// Load Public Registration Link
	$( document ).on( 'click', '#btn-load-public-link', function () {
		const $btn = $( this );
		const originalText = $btn.html();
		$btn.html( '⏳ Generando...' ).prop( 'disabled', true );

		$.ajax( {
			url: eipsiWavesManagerData.ajaxUrl || ajaxurl,
			type: 'POST',
			data: {
				action: 'eipsi_get_public_registration_link',
				nonce:
					eipsiWavesManagerData.anonymizeNonce ||
					eipsiWavesManagerData.wavesNonce,
				study_id: $( '#add-participant-study-id' ).val(),
			},
			success( response ) {
				if ( response.success ) {
					$( '#public-registration-url' ).val(
						response.data.registration_url
					);
					showNotification(
						'Enlace generado exitosamente',
						'success'
					);
				} else {
					showNotification(
						response.data.message || 'Error al generar enlace',
						'error'
					);
				}
			},
			error() {
				showNotification( 'Error de conexión', 'error' );
			},
			complete() {
				$btn.html( originalText ).prop( 'disabled', false );
			},
		} );
	} );

	// Copy Public Link to Clipboard
	$( document ).on( 'click', '#btn-copy-public-link', function () {
		const $input = $( '#public-registration-url' );
		const url = $input.val();

		if ( ! url ) {
			showNotification( 'Primero genera el enlace público', 'warning' );
			return;
		}

		// Copy to clipboard
		$input.select();
		document.execCommand( 'copy' );

		// Visual feedback
		const $btn = $( this );
		const originalText = $btn.html();
		$btn.html( '✓ Copiado' ).css( 'background', '#10b981' );

		setTimeout( function () {
			$btn.html( originalText ).css( 'background', '' );
		}, 2000 );

		showNotification( 'Enlace copiado al portapapeles', 'success' );
	} );

	// ===========================
	// EMAIL PREVIEW MODAL
	// ===========================

	let emailPreviewWaveId = 0;
	let pendingValidationWarnings = null;

	function initEmailPreviewModal() {
		$( document ).on( 'click', '.eipsi-send-reminder-btn', function () {
			const waveId = $( this ).data( 'wave-id' );
			openEmailPreviewModal( waveId, 'reminder' );
		} );

		$( document ).on(
			'click',
			'.eipsi-send-manual-reminder-btn',
			function () {
				const waveId = $( this ).data( 'wave-id' );
				openEmailPreviewModal( waveId, 'manual' );
			}
		);

		$( document ).on(
			'change',
			'#email-preview-type, #email-preview-participant',
			function () {
				loadEmailPreview();
			}
		);

		$( document ).on( 'click', '#email-preview-refresh-btn', function () {
			loadEmailPreview();
		} );
	}

	function openEmailPreviewModal( waveId, emailType ) {
		emailPreviewWaveId = waveId;
		$( '#email-preview-wave-id' ).val( waveId );
		$( '#email-preview-type' ).val( emailType || 'reminder' );
		$( '#email-preview-participant' ).val( '' );
		$( '#email-preview-subject' ).text( '' );
		$( '#email-preview-body' ).html(
			'<div style="text-align: center; padding: 40px; color: #666;"><span class="spinner is-active"></span><p>Cargando vista previa...</p></div>'
		);
		loadParticipantsForPreview();
		$( '#eipsi-email-preview-modal' ).fadeIn( 200 );
	}

	function loadParticipantsForPreview() {
		$.ajax( {
			url: eipsiWavesManagerData.ajaxUrl || ajaxurl,
			type: 'GET',
			data: {
				action: 'eipsi_get_pending_participants',
				nonce: eipsiWavesManagerData.wavesNonce,
				study_id: eipsiWavesManagerData.studyId,
				wave_id: emailPreviewWaveId,
			},
			success( response ) {
				if ( response.success && response.data ) {
					const $select = $( '#email-preview-participant' );
					$select.find( 'option:not(:first)' ).remove();
					response.data.forEach( function ( p ) {
						const fullName =
							( p.first_name || '' ) +
							' ' +
							( p.last_name || '' );
						$select.append(
							'<option value="' +
								p.id +
								'">' +
								escapeHtml( fullName.trim() || p.email ) +
								' (' +
								escapeHtml( p.email ) +
								')</option>'
						);
					} );
				}
			},
		} );
	}

	function loadEmailPreview() {
		const waveId = emailPreviewWaveId;
		const emailType = $( '#email-preview-type' ).val();
		const participantId = $( '#email-preview-participant' ).val();

		$( '#email-preview-body' ).html(
			'<div style="text-align: center; padding: 40px; color: #666;"><span class="spinner is-active"></span><p>Cargando vista previa...</p></div>'
		);

		$.ajax( {
			url: eipsiWavesManagerData.ajaxUrl || ajaxurl,
			type: 'GET',
			data: {
				action: 'eipsi_get_wave_email_preview',
				nonce: eipsiWavesManagerData.wavesNonce,
				wave_id: waveId,
				type: emailType,
				participant_id: participantId,
			},
			success( response ) {
				if ( response.success && response.data ) {
					const data = response.data;
					$( '#email-preview-subject' ).text( data.subject || '' );
					$( '#email-preview-body' ).html( data.content || '' );
					if ( data.is_sample ) {
						$( '#email-preview-body' ).prepend(
							'<div style="background: #e8f4f8; padding: 8px 12px; margin-bottom: 15px; border-radius: 4px; font-size: 0.85em; color: #0066cc;"><strong>Vista previa de muestra</strong> - Los datos del participante se sustituiran al enviar.</div>'
						);
					}
				} else {
					$( '#email-preview-body' ).html(
						'<div style="text-align: center; padding: 40px; color: #d63638;">Error al cargar la vista previa</div>'
					);
				}
			},
			error() {
				$( '#email-preview-body' ).html(
					'<div style="text-align: center; padding: 40px; color: #d63638;">Error de conexion</div>'
				);
			},
		} );
	}

	// ===========================
	// VALIDATION MODAL
	// ===========================

	function initValidationModal() {
		$( document ).on( 'click', '#validation-confirm-save-btn', function () {
			$( '#eipsi-validation-warning-modal' ).fadeOut( 200 );
			pendingValidationWarnings = null;
			$( '#eipsi-wave-form' ).data( 'skip-validation', true );
			$( '#eipsi-wave-form' ).submit();
		} );
	}

	function validateWaveDatesOnChange() {
		const studyId = eipsiWavesManagerData.studyId;
		const waveId = $( '#wave_id' ).val();
		const waveIndex = $( '#wave_index' ).val();
		const startDate = $( '#start_date' ).val();
		const dueDate = $( '#due_date' ).val();

		if ( ! studyId ) {
			return;
		}
		$.ajax( {
			url: eipsiWavesManagerData.ajaxUrl || ajaxurl,
			type: 'POST',
			data: {
				action: 'eipsi_validate_wave_dates',
				nonce: eipsiWavesManagerData.wavesNonce,
				study_id: studyId,
				wave_id: waveId,
				wave_index: waveIndex,
				start_date: startDate,
				due_date: dueDate,
			},
			success( response ) {
				if ( response.success && response.data ) {
					const validation = response.data;
					if ( validation.errors && validation.errors.length > 0 ) {
						showNotification( validation.errors[ 0 ], 'error' );
					}
					if (
						validation.warnings &&
						validation.warnings.length > 0
					) {
						pendingValidationWarnings = validation.warnings;
					} else {
						pendingValidationWarnings = null;
					}
				}
			},
		} );
	}

	function checkAndShowValidationWarnings() {
		if (
			pendingValidationWarnings &&
			pendingValidationWarnings.length > 0
		) {
			showValidationWarningModal( pendingValidationWarnings );
			return true;
		}
		return false;
	}

	function showValidationWarningModal( warnings ) {
		const $list = $( '#validation-warnings-list' );
		$list.html(
			'<div class="notice notice-warning" style="margin: 0;"><p><strong>Se detectaron las siguientes advertencias:</strong></p><ul style="margin: 10px 0;">' +
				warnings
					.map( function ( w ) {
						return '<li>' + escapeHtml( w ) + '</li>';
					} )
					.join( '' ) +
				'</ul><p>Deseas guardar de todos modos o revisar las fechas?</p></div>'
		);
		$( '#eipsi-validation-warning-modal' ).fadeIn( 200 );
	}

	// ===========================
	// REMINDER CONFIG MODAL
	// ===========================

	let currentConfigWaveId = null;
	let defaultNudgeConfig = {
		1: { enabled: true, hours: 24, unit: 'hours', subject: '' },
		2: { enabled: true, hours: 48, unit: 'hours', subject: '' },
		3: { enabled: true, hours: 72, unit: 'hours', subject: '' },
		4: { enabled: true, hours: 96, unit: 'hours', subject: '' }
	};

	// Translate units for display
	function translateUnit(unit) {
		const translations = {
			'minutes': 'minutos',
			'hours': 'horas',
			'days': 'días'
		};
		return translations[unit] || unit;
	}

	/**
	 * Open reminder configuration modal
	 * @param {number} waveId
	 */
	function openReminderConfigModal( waveId ) {
		currentConfigWaveId = waveId;
		$( '#config-wave-id' ).val( waveId );
		
		// Load existing configuration
		loadNudgeConfig( waveId );
		
		$( '#eipsi-reminder-config-modal' ).fadeIn( 200 );
	}

	/**
	 * Load nudge configuration for a wave
	 * @param {number} waveId
	 */
	function loadNudgeConfig( waveId ) {
		$.ajax( {
			url:
				eipsiWavesManagerData.ajaxUrl ||
				( typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php' ),
				type: 'POST',
				data: {
					action: 'eipsi_get_reminder_config',
					wave_id: waveId,
					nonce: eipsiWavesManagerData.wavesNonce,
				},
				success: function ( response ) {
					if ( response.success && response.data.config ) {
						populateNudgeForm( response.data.config );
					} else {
						// Use defaults
						populateNudgeForm( defaultNudgeConfig );
					}
				},
				error: function () {
					populateNudgeForm( defaultNudgeConfig );
				},
			} );
	}

	/**
	 * Populate form with nudge configuration
	 * @param {Object} config
	 */
	function populateNudgeForm( config ) {
		for ( let stage = 1; stage <= 4; stage++ ) {
			const stageConfig = config[ stage ] || defaultNudgeConfig[ stage ];
			$( '#nudge-' + stage + '-enabled' ).prop( 'checked', stageConfig.enabled );
			$( '#nudge-' + stage + '-hours' ).val( stageConfig.hours );
			$( '#nudge-' + stage + '-unit' ).val( stageConfig.unit );
			$( '#nudge-' + stage + '-subject' ).val( stageConfig.subject || '' );
		}
	}

	/**
	 * Save nudge configuration
	 */
	function saveNudgeConfig() {
		if ( ! currentConfigWaveId ) return;

		const config = {};
		for ( let stage = 1; stage <= 4; stage++ ) {
			config[ stage ] = {
				enabled: $( '#nudge-' + stage + '-enabled' ).is( ':checked' ),
				hours: parseInt( $( '#nudge-' + stage + '-hours' ).val() ) || 24,
				unit: $( '#nudge-' + stage + '-unit' ).val(),
				subject: $( '#nudge-' + stage + '-subject' ).val().trim(),
			};
		}

		$.ajax( {
			url:
				eipsiWavesManagerData.ajaxUrl ||
				( typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php' ),
			type: 'POST',
			data: {
				action: 'eipsi_save_reminder_config',
				wave_id: currentConfigWaveId,
				config: JSON.stringify( config ),
				nonce: eipsiWavesManagerData.wavesNonce,
			},
			success: function ( response ) {
				if ( response.success ) {
					showNotification( response.data.message, 'success' );
					$( '#eipsi-reminder-config-modal' ).fadeOut( 200 );
				} else {
					showNotification(
						response.data.message || 'Error al guardar',
						'error'
					);
				}
			},
			error: function () {
				showNotification( 'Error de conexión', 'error' );
			},
		} );
	}

	/**
	 * Reset nudge configuration to defaults
	 */
	function resetNudgeConfig() {
		populateNudgeForm( defaultNudgeConfig );
		showNotification( 'Valores restaurados a por defecto', 'info' );
	}

	// Event handlers for reminder config modal
	$( document ).on( 'click', '#save-reminder-config-btn', saveNudgeConfig );
	$( document ).on( 'click', '#reset-reminder-config-btn', resetNudgeConfig );
	$( document ).on(
		'click',
		'#eipsi-reminder-config-modal .eipsi-close-modal, #eipsi-reminder-config-modal .eipsi-close-modal-btn',
		function () {
			$( '#eipsi-reminder-config-modal' ).fadeOut( 200 );
		}
	);

	// Close modal on escape key
	$( document ).on( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			$( '#eipsi-reminder-config-modal' ).fadeOut( 200 );
		}
	} );
} )( window.jQuery );
