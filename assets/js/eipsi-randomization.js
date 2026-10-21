/**
 * EIPSI Randomization Logic
 *
 * Sistema completo de aleatorización pública y ética
 * - Detecta parámetro ?eipsi_random=true
 * - Randomiza entre formularios configurados
 * - Persiste asignación en metadata
 * - Respeta override manual
 * - Seed reproducible para longitudinal
 */

( function ( window, document ) {
	'use strict';

	// === CONFIGURACIÓN GLOBAL ===
	const EIPSI_RANDOM_CONFIG = {
		debug: false,
		retryAttempts: 3,
		retryDelay: 1000,
	};

	// === UTILIDADES ===

	/**
	 * Logger con niveles
	 */
	const Logger = {
		debug: function ( ...args ) {
			if ( EIPSI_RANDOM_CONFIG.debug ) {
				console.log( '[EIPSI-Random-Debug]', ...args );
			}
		},
		error: function ( ...args ) {
			console.error( '[EIPSI-Random-Error]', ...args );
		},
		info: function ( ...args ) {
			console.info( '[EIPSI-Random-Info]', ...args );
		},
	};

	/**
	 * Detectar ID del participante de múltiples fuentes
	 */
	function getParticipantId() {
		// 1. URL parameter
		const urlParams = new URLSearchParams( window.location.search );
		if ( urlParams.has( 'participant_id' ) ) {
			return urlParams.get( 'participant_id' );
		}

		// 2. localStorage persistente
		const stored = localStorage.getItem( 'eipsi_participant_id' );
		if ( stored ) {
			return stored;
		}

		// 3. Generar nuevo UUID
		const newId = crypto.randomUUID
			? crypto.randomUUID()
			: 'participant_' +
			  Date.now() +
			  '_' +
			  Math.random().toString( 36 ).substr( 2, 9 );
		localStorage.setItem( 'eipsi_participant_id', newId );
		return newId;
	}

	/**
	 * RNG seeded para reproducibilidad
	 * Mismo seed = mismo resultado siempre
	 */
	function seededRandom( seed ) {
		// Crear hash determinístico del seed
		let hash = 0;
		for ( let i = 0; i < seed.length; i++ ) {
			const char = seed.charCodeAt( i );
			hash = ( hash << 5 ) - hash + char;
			hash = hash & hash; // Convertir a 32-bit integer
		}

		// Generador lineal congruente
		return function () {
			hash = ( hash * 9301 + 49297 ) % 233280;
			return hash / 233280;
		};
	}

	/**
	 * Generar o recuperar seed reproducible
	 */
	function generateOrGetSeed( studyId, participantId ) {
		const key = `eipsi_seed_${ studyId }_${ participantId }`;
		let seed = localStorage.getItem( key );

		if ( ! seed ) {
			// Generar UUID v4 como seed
			seed = crypto.randomUUID
				? crypto.randomUUID()
				: 'seed_' +
				  Date.now() +
				  '_' +
				  Math.random().toString( 36 ).substr( 2, 9 );
			localStorage.setItem( key, seed );
			Logger.debug( 'New seed generated:', seed );
		} else {
			Logger.debug( 'Using existing seed:', seed );
		}

		return seed;
	}

	/**
	 * Randomizar entre formularios usando Fisher-Yates + seed
	 */
	function randomAssignForm( formsList, seed ) {
		if ( ! Array.isArray( formsList ) || formsList.length === 0 ) {
			throw new Error( 'Invalid forms list for randomization' );
		}

		// Si solo hay un formulario, devolverlo directamente
		if ( formsList.length === 1 ) {
			Logger.debug(
				'Single form available, returning:',
				formsList[ 0 ].id
			);
			return formsList[ 0 ].id;
		}

		const rng = seededRandom( seed );

		// Algoritmo Fisher-Yates con seed
		const indices = formsList.map( ( _, index ) => index );
		for ( let i = indices.length - 1; i > 0; i-- ) {
			const randomIndex = Math.floor( rng() * ( i + 1 ) );
			[ indices[ i ], indices[ randomIndex ] ] = [
				indices[ randomIndex ],
				indices[ i ],
			];
		}

		const selectedIndex = indices[ 0 ];
		const selectedForm = formsList[ selectedIndex ];

		Logger.debug( 'Randomization result:', {
			seed: seed,
			forms: formsList.map( ( f ) => ( { id: f.id, title: f.title } ) ),
			selected: selectedForm,
		} );

		return selectedForm.id;
	}

	/**
	 * Función retry para requests AJAX
	 */
	async function retryAjax(
		url,
		options,
		attempts = EIPSI_RANDOM_CONFIG.retryAttempts
	) {
		for ( let i = 0; i < attempts; i++ ) {
			try {
				const response = await fetch( url, options );
				if ( ! response.ok ) {
					throw new Error(
						`HTTP ${ response.status }: ${ response.statusText }`
					);
				}
				return await response.json();
			} catch ( error ) {
				console.warn( `Attempt ${ i + 1 } failed:`, error );
				if ( i === attempts - 1 ) throw error;
				await new Promise( ( resolve ) =>
					setTimeout(
						resolve,
						EIPSI_RANDOM_CONFIG.retryDelay * ( i + 1 )
					)
				);
			}
		}
	}

	// === FUNCIONES AJAX ===

	/**
	 * Obtener configuración de aleatorización del formulario
	 */
	async function fetchRandomizationConfig( studyId ) {
		const formData = new FormData();
		formData.append( 'action', 'eipsi_get_randomization_config' );
		formData.append( 'form_id', studyId );
		formData.append( 'nonce', window.eipsiEditorData?.nonce || '' );

		const response = await retryAjax(
			window.ajaxurl || '/wp-admin/admin-ajax.php',
			{
				method: 'POST',
				body: formData,
			}
		);

		if ( ! response.success ) {
			throw new Error(
				response.data || 'Failed to fetch randomization config'
			);
		}

		return response.data;
	}

	/**
	 * Verificar si hay asignación manual para este participante
	 */
	async function checkManualAssignment( studyId, participantId ) {
		const formData = new FormData();
		formData.append( 'action', 'eipsi_check_manual_assignment' );
		formData.append( 'form_id', studyId );
		formData.append( 'participant_id', participantId );
		formData.append( 'nonce', window.eipsiEditorData?.nonce || '' );

		const response = await retryAjax(
			window.ajaxurl || '/wp-admin/admin-ajax.php',
			{
				method: 'POST',
				body: formData,
			}
		);

		if ( ! response.success ) {
			Logger.warn( 'Failed to check manual assignment:', response.data );
			return null;
		}

		return response.data;
	}

	/**
	 * Persistir asignación en metadata
	 */
	async function persistAssignment(
		studyId,
		participantId,
		assignedFormId,
		seed,
		isManual = false
	) {
		const formData = new FormData();
		formData.append( 'action', 'eipsi_persist_assignment' );
		formData.append( 'form_id', studyId );
		formData.append( 'participant_id', participantId );
		formData.append( 'assigned_form_id', assignedFormId );
		formData.append( 'seed', seed );
		formData.append( 'is_manual', isManual ? '1' : '0' );
		formData.append( 'nonce', window.eipsiEditorData?.nonce || '' );

		const response = await retryAjax(
			window.ajaxurl || '/wp-admin/admin-ajax.php',
			{
				method: 'POST',
				body: formData,
			}
		);

		if ( ! response.success ) {
			Logger.warn( 'Failed to persist assignment:', response.data );
		}

		return response;
	}

	/**
	 * Cargar formulario en el contenedor
	 */
	async function loadFormInContainer( formId, container ) {
		const formData = new FormData();
		formData.append( 'action', 'eipsi_load_form' );
		formData.append( 'form_id', formId );
		formData.append( 'nonce', window.eipsiRandomizationFormLoad?.nonce || window.eipsiRandomization?.nonce || '' );

		const response = await retryAjax(
			window.eipsiRandomizationFormLoad?.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php',
			{
				method: 'POST',
				body: formData,
			}
		);

		if ( ! response.success ) {
			throw new Error( response.data || 'Error cargando formulario' );
		}

		container.innerHTML = response.data;
		if ( window.EIPSIForms && typeof window.EIPSIForms.init === 'function' ) window.EIPSIForms.init();
		Logger.info( 'Form loaded successfully:', formId );
	}

	/**
	 * Cargar formulario base sin randomización
	 */
	function loadFormNormal( studyId, container ) {
		Logger.info( 'Loading form normally (no randomization):', studyId );
		return loadFormInContainer( studyId, container );
	}

	// === FUNCIÓN PRINCIPAL ===

	/**
	 * Función principal de aleatorización
	 * Se llama desde el shortcode cuando la página carga
	 */
	window.eipsiRandomizeForm = async function ( studyId, isRandomized ) {
		const container = document.getElementById(
			'randomized-form-container'
		);
		const loading = document.getElementById( 'randomization-loading' );

		if ( ! container ) {
			Logger.error( 'Randomized form container not found' );
			return;
		}

		// Mostrar loading
		if ( loading ) {
			loading.style.display = 'block';
		}

		try {
			// Si NO tiene ?eipsi_random=true → carga formulario base normal
			if ( isRandomized !== 'true' ) {
				Logger.info( 'Not in randomization mode, loading normal form' );
				await loadFormNormal( studyId, container );
				return;
			}

			Logger.info( 'Starting randomization process for study:', studyId );

			// 1. Obtener config de aleatorización del formulario base
			const config = await fetchRandomizationConfig( studyId );

			if (
				! config.enabled ||
				! config.forms ||
				config.forms.length < 2
			) {
				throw new Error(
					'Configuración de aleatorización inválida: ' +
						( config.enabled
							? 'forms insufficient'
							: 'randomization disabled' )
				);
			}

			// 2. Obtener ID del participante
			const participantId = getParticipantId();
			Logger.info( 'Participant ID:', participantId );

			// 3. Verificar si hay override manual
			const manualAssignment = await checkManualAssignment(
				studyId,
				participantId
			);
			let formToLoad;
			let seed;

			// 4. Si hay override manual → usarlo
			if ( manualAssignment && manualAssignment.assigned_form_id ) {
				Logger.info( 'Using manual assignment:', manualAssignment );
				formToLoad = manualAssignment.assigned_form_id;
				seed =
					manualAssignment.seed ||
					generateOrGetSeed( studyId, participantId );
			}
			// 5. Si no hay override → randomizar
			else {
				Logger.info(
					'No manual override, proceeding with randomization'
				);

				// Generar seed reproducible
				seed = generateOrGetSeed( studyId, participantId );

				// Randomizar entre los formularios configurados
				formToLoad = randomAssignForm( config.forms, seed );

				// Guardar asignación en metadata
				await persistAssignment(
					studyId,
					participantId,
					formToLoad,
					seed,
					false
				);
			}

			// 6. Cargar el formulario asignado
			await loadFormInContainer( formToLoad, container );

			// 7. Guardar en metadata global para el formulario
			window.eipsiFormMetadata = window.eipsiFormMetadata || {};
			window.eipsiFormMetadata.randomAssignment = {
				study_id: studyId,
				assigned_form_id: formToLoad,
				participant_id: participantId,
				is_manual_override: !! manualAssignment,
				seed: seed,
				timestamp: new Date().toISOString(),
			};

			Logger.info( 'Randomization completed successfully' );
		} catch ( error ) {
			Logger.error( 'Randomization error:', error );

			// Mostrar error amigable al usuario
			container.innerHTML = `
                <div style="background: #ffebee; border-left: 4px solid #f44336; padding: 1rem; margin: 1rem 0; border-radius: 4px;">
                    <p style="margin: 0; color: #c62828; font-weight: 500;">
                        ⚠️ Error en la asignación del formulario.
                    </p>
                    <p style="margin: 0.5rem 0 0 0; color: #c62828; font-size: 0.9rem;">
                        Por favor, recargá la página. Si el problema persiste, contactá al administrador.
                    </p>
                </div>
            `;
		} finally {
			// Ocultar loading
			if ( loading ) {
				loading.style.display = 'none';
			}
		}
	};

	// === EXPORTAR UTILIDADES GLOBALES ===

	// Para testing y debugging
	window.EIPSI_RANDOM_DEBUG = {
		getParticipantId,
		generateOrGetSeed,
		randomAssignForm,
		seededRandom,
	};

	Logger.info( 'EIPSI Randomization system loaded' );
} )( window, document );
