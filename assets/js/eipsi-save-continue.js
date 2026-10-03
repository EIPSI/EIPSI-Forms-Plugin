/**
 * EIPSI Forms - Save & Continue v1
 * Guarda respuestas parciales (server + IndexedDB) y permite reanudar sesiones
 *
 * @since 1.3.0
 */

( function () {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	const AUTOSAVE_INTERVAL = 30000; // 30 segundos
	const INPUT_DEBOUNCE = 800; // ms
	const IDB_NAME = 'eipsi_forms';
	const IDB_VERSION = 1;
	const IDB_STORE = 'partial_responses';
	const EXCLUDED_FIELDS = new Set( [
		'form_id',
		'form_action',
		'ip_address',
		'device',
		'browser',
		'os',
		'screen_width',
		'form_start_time',
		'form_end_time',
		'current_page',
		'nonce',
		'action',
		'participant_id',
		'session_id',
		'eipsi_forms_nonce',
	] );

	/**
	 * Fix 7: Circuit Breaker Pattern
	 * Protege contra fallos de red consecutivos
	 */
	class EipsiCircuitBreaker {
		constructor(threshold = 5, timeout = 60000) {
			this.failures = 0;
			this.threshold = threshold;
			this.timeout = timeout;
			this.state = 'CLOSED';
			this.nextAttempt = null;
		}

		async execute(fn) {
			if (this.state === 'OPEN') {
				if (Date.now() < this.nextAttempt) {
					throw new Error('Circuit OPEN');
				}
				this.state = 'HALF_OPEN';
			}
			try {
				const result = await fn();
				this.onSuccess();
				return result;
			} catch (error) {
				this.onFailure();
				throw error;
			}
		}

		onSuccess() {
			this.failures = 0;
			this.state = 'CLOSED';
		}

		onFailure() {
			this.failures++;
			if (this.failures >= this.threshold) {
				this.state = 'OPEN';
				this.nextAttempt = Date.now() + this.timeout;
				console.warn('[EIPSI] Circuit breaker ABIERTO');
			}
		}

		isOpen() {
			return this.state === 'OPEN';
		}
	}

	class EIPSISaveContinue {
		constructor( form, config ) {
			this.form = form;
			this.config = config || {};
			this.formId = this.getFormId();
			this.participantId = this.getParticipantId();
			this.sessionId = this.getSessionId();
			this.autosaveTimer = null;
			this.db = null;
			this.saveQueue = [];
			this.processingQueue = false;
			this.initialized = false;
			this.completed = false;
			this.hasResponses = false;
			this.beforeUnloadHandler = null;
			this.inputDebounceId = null;
			// Fix 5: Dirty tracking para reducir payload
			this.dirtyFields = new Set();
			this.lastSavedChecksum = null;
			// Fix 7: Circuit breaker
			this.circuitBreaker = new EipsiCircuitBreaker(5, 60000);
			// Fix 9: Indicador visual
			this.saveIndicator = null;
			// Fix 10: Acumulación de respuestas para formularios multipágina
			this.accumulatedResponses = {};

			this.init();
		}

		async init() {
			try {
				this.db = await this.openDB();
				this.initialized = true;

				await this.checkForPartialResponse();
				this.setupAutosave();
				this.setupBeforeUnload();
				this.setupChangeListeners();
			} catch ( error ) {
				if ( window.console && window.console.error ) {
					window.console.error(
						'[EIPSI Save & Continue] Initialization failed:',
						error
					);
				}
			}
		}

		getFormId() {
			return (
				this.form?.dataset?.formId ||
				this.form?.querySelector( 'input[name="form_id"]' )?.value ||
				'default'
			);
		}

		getParticipantId() {
			// v2.5.3 - Prioridad 1: Usar participant_id autenticado si está disponible
			if ( window.eipsiAuth && window.eipsiAuth.participantId ) {
				return String( window.eipsiAuth.participantId );
			}

			// Prioridad 2: Fallback a localStorage (anónimo o modo incógnito)
			const STORAGE_KEY = 'eipsi_participant_id';
			let pid = null;

			try {
				pid = window.localStorage.getItem( STORAGE_KEY );
			} catch ( error ) {
				pid = null;
			}

			if ( ! pid ) {
				pid = this.generateSecureParticipantId();

				try {
					window.localStorage.setItem( STORAGE_KEY, pid );
				} catch ( error ) {
					// Ignore storage errors (Safari private mode, etc.)
				}
			}

			return pid;
		}

		/**
		 * Fix 4: Generación criptográficamente segura de participant_id
		 * Fallback progresivo: crypto.randomUUID → crypto.getRandomValues → Math.random + timestamp
		 */
		generateSecureParticipantId() {
			// Opción 1: crypto.randomUUID (más moderno, disponible en navegadores recientes)
			if ( typeof crypto !== 'undefined' && crypto.randomUUID ) {
				return 'p-' + crypto.randomUUID().replace( /-/g, '' ).substring( 0, 12 );
			}

			// Opción 2: crypto.getRandomValues (CSPRNG seguro, disponible en todos los navegadores modernos)
			if ( typeof crypto !== 'undefined' && crypto.getRandomValues ) {
				const array = new Uint8Array( 8 );
				crypto.getRandomValues( array );
				const hex = Array.from( array, ( b ) =>
					b.toString( 16 ).padStart( 2, '0' )
				).join( '' );
				return 'p-' + hex.substring( 0, 12 );
			}

			// Opción 3: Último fallback - Math.random con timestamp (menos seguro pero funcional)
			const timestamp = Date.now().toString( 36 );
			const random = Math.random().toString( 36 ).substring( 2, 8 );
			return 'p-' + timestamp + random;
		}

		getSessionId() {
			const SESSION_KEY = `eipsi_session_${ this.formId || 'default' }`;
			let sid = null;

			// v2.5.3 - Migración: intentar localStorage primero (nuevo), luego sessionStorage (legacy)
			try {
				sid = window.localStorage.getItem( SESSION_KEY );
			} catch ( error ) {
				sid = null;
			}

			// Compatibilidad: migrar desde sessionStorage si existe y no hay en localStorage
			if ( ! sid ) {
				try {
					const legacySid = window.sessionStorage.getItem( SESSION_KEY );
					if ( legacySid ) {
						sid = legacySid;
						// Migrar a localStorage para persistencia cross-tab
						window.localStorage.setItem( SESSION_KEY, sid );
					}
				} catch ( error ) {
					// Ignore
				}
			}

			if ( ! sid ) {
				const timestamp = Date.now();
				const random = Math.random().toString( 36 ).substring( 2, 8 );
				sid = `sess-${ timestamp }-${ random }`;

				try {
					window.localStorage.setItem( SESSION_KEY, sid );
				} catch ( error ) {
					// Ignore storage errors (Safari private mode, etc.)
				}
			}

			return sid;
		}

		openDB() {
			if ( ! window.indexedDB ) {
				return Promise.resolve( null );
			}

			return new Promise( ( resolve, reject ) => {
				const request = window.indexedDB.open( IDB_NAME, IDB_VERSION );

				request.onerror = () =>
					reject( new Error( 'IndexedDB unavailable' ) );
				request.onsuccess = () => resolve( request.result );
				request.onupgradeneeded = ( event ) => {
					const db = event.target.result;
					if ( ! db.objectStoreNames.contains( IDB_STORE ) ) {
						db.createObjectStore( IDB_STORE, {
							keyPath: [
								'form_id',
								'participant_id',
								'session_id',
							],
						} );
					}
				};
			} );
		}

		async checkForPartialResponse() {
			// Fix 6: Cargar ambos en paralelo para reducir latencia
			const [ serverPartial, localPartial ] = await Promise.all( [
				this.loadFromServer().catch( () => null ),
				this.loadFromIDB().catch( () => null ),
			] );

			const hasServer = serverPartial?.found && serverPartial?.partial;
			const hasLocal = !! localPartial;

			if ( ! hasServer && ! hasLocal ) {
				return;
			} // Nada que recuperar

			let toRestore;

			if ( hasServer && hasLocal ) {
				// Usar el más reciente basándose en timestamp
				const serverDate = new Date( serverPartial.partial.updated_at || 0 );
				const localDate = new Date(
					localPartial.updated_at || localPartial.savedAt || 0
				);

				toRestore =
					localDate > serverDate ? localPartial : serverPartial.partial;

				// Si local es más reciente, sincronizar con servidor en background
				if ( localDate > serverDate ) {
					this.saveToServer(
						localPartial.responses,
						localPartial.page_index
					).catch( ( e ) =>
						console.warn( '[EIPSI] Background sync failed:', e )
					);
				}
			} else {
				toRestore = hasServer ? serverPartial.partial : localPartial;
			}

			this.showRecoveryPopup( toRestore );
		}

		async loadFromServer() {
			if ( ! this.config.ajaxUrl ) {
				return null;
			}

			try {
				const formData = new FormData();
				formData.append( 'action', 'eipsi_load_partial_response' );
				formData.append( 'form_id', this.formId );
				formData.append( 'participant_id', this.participantId );
				formData.append( 'session_id', this.sessionId );

				const response = await fetch( this.config.ajaxUrl, {
					method: 'POST',
					body: formData,
					credentials: 'same-origin',
				} );

				const data = await response.json();
				return data.success ? data.data : null;
			} catch ( error ) {
				return null;
			}
		}

		async loadFromIDB() {
			if ( ! this.db ) {
				return null;
			}

			return new Promise( ( resolve ) => {
				const transaction = this.db.transaction(
					[ IDB_STORE ],
					'readonly'
				);
				const store = transaction.objectStore( IDB_STORE );
				const key = [ this.formId, this.participantId, this.sessionId ];
				const request = store.get( key );

				request.onsuccess = () => resolve( request.result || null );
				request.onerror = () => resolve( null );
			} );
		}

		showRecoveryPopup( partial ) {
			if ( document.querySelector( '.eipsi-recovery-popup' ) ) {
				return;
			}

			const rawUpdatedAt = partial.updated_at || new Date().toISOString();
			const normalizedDate = new Date(
				typeof rawUpdatedAt === 'string'
					? rawUpdatedAt.replace( ' ', 'T' )
					: rawUpdatedAt
			);
			const dateStr = Number.isNaN( normalizedDate.getTime() )
				? 'tu última sesión'
				: normalizedDate.toLocaleString( 'es', {
						year: 'numeric',
						month: 'long',
						day: 'numeric',
						hour: '2-digit',
						minute: '2-digit',
				  } );

			const popup = document.createElement( 'div' );
			popup.className = 'eipsi-recovery-popup';
			popup.setAttribute( 'data-modal-id', Date.now() );
			popup.innerHTML = `
                <div class="eipsi-recovery-overlay" aria-hidden="true"></div>
                <div class="eipsi-recovery-modal" role="dialog" aria-live="polite">
                    <h3>Continuar donde quedaste</h3>
                    <p>Tenés respuestas guardadas del <strong>${ dateStr }</strong>.</p>
                    <p>¿Querés continuar donde quedaste?</p>
                    <div class="eipsi-recovery-buttons">
                        <button type="button" class="eipsi-btn eipsi-btn-primary" data-action="continue">
                            Continuar
                        </button>
                        <button type="button" class="eipsi-btn eipsi-btn-secondary" data-action="restart">
                            Empezar de nuevo
                        </button>
                    </div>
                </div>
            `;

			document.body.appendChild( popup );

			const continueButton = popup.querySelector(
				'[data-action="continue"]'
			);
			const restartButton = popup.querySelector(
				'[data-action="restart"]'
			);

			if ( continueButton ) {
				continueButton.addEventListener( 'click', async () => {
					// Deshabilitar botón para evitar múltiples clics
					continueButton.disabled = true;
					continueButton.textContent = 'Cargando...';

					try {
						// Paso 1: Esperar a que el formulario esté listo
						await this.waitForFormReady();

						// Paso 2: Restaurar datos (async)
						await this.restorePartial( partial );

						// Paso 3: Asegurar que el formulario sea visible
						await this.ensureFormVisible();

						// Paso 4: Esperar un frame extra para que todo se renderice
						await new Promise( ( resolve ) => {
							requestAnimationFrame( () => {
								requestAnimationFrame( resolve );
							} );
						} );

						// Paso 5: Remover el modal completamente
						this.closeRecoveryPopup( popup );
					} catch ( error ) {
						// Si algo falla, mostrar error en consola pero cerrar modal de todas formas
						if (
							this.config?.settings?.debug &&
							window.console?.error
						) {
							window.console.error(
								'[EIPSI Save & Continue] Error al restaurar sesión:',
								error
							);
						}

						// Forzar visibilidad del formulario
						this.forceFormVisible();

						// Cerrar modal de todas formas
						this.closeRecoveryPopup( popup );
					}
				} );
			}

			if ( restartButton ) {
				restartButton.addEventListener( 'click', async () => {
					restartButton.disabled = true;
					restartButton.textContent = 'Borrando...';

					try {
						// PASO 1: Cerrar sesión de aleatorización (si existe)
						await this.closeRandomizationSession();

						// PASO 2: Descartar respuestas parciales
						await this.discardPartial();

						// PASO 3: Forzar visibilidad del formulario
						this.forceFormVisible();

						// PASO 4: Cerrar modal de recuperación
						this.closeRecoveryPopup( popup );
					} catch ( error ) {
						if (
							this.config?.settings?.debug &&
							window.console?.error
						) {
							window.console.error(
								'[EIPSI Save & Continue] Error al descartar sesión:',
								error
							);
						}
						// Cerrar modal de todas formas
						this.closeRecoveryPopup( popup );
					}
				} );
			}
		}

		closeRecoveryPopup( popup ) {
			if ( ! popup || ! popup.parentNode ) {
				return;
			}

			// Remover overlay primero para evitar capturar clics
			const overlay = popup.querySelector( '.eipsi-recovery-overlay' );
			if ( overlay ) {
				overlay.style.pointerEvents = 'none';
				overlay.style.opacity = '0';
			}

			// Remover modal con transición suave
			const modal = popup.querySelector( '.eipsi-recovery-modal' );
			if ( modal ) {
				modal.style.opacity = '0';
			}

			// Remover del DOM después de la transición
			setTimeout( () => {
				if ( popup.parentNode ) {
					popup.parentNode.removeChild( popup );
				}
			}, 200 );
		}

		waitForFormReady() {
			const TIMEOUT_MS = 10000;
			const CHECK_INTERVAL_MS = 100;

			return new Promise( ( resolve ) => {
				const startTime = Date.now();

				const checkReady = () => {
					if ( Date.now() - startTime > TIMEOUT_MS ) {
						// Timeout: resolver de todas formas (mejor que quedar colgado)
						if (
							this.config?.settings?.debug &&
							window.console?.warn
						) {
							window.console.warn(
								'[EIPSI Save & Continue] Timeout esperando formulario, continuando...'
							);
						}
						resolve();
						return;
					}

					// Verificar que el formulario existe
					if ( ! this.form || ! this.form.parentNode ) {
						setTimeout( checkReady, CHECK_INTERVAL_MS );
						return;
					}

					// Verificar que el formulario sea visible
					const hasDimensions =
						this.form.offsetHeight > 0 && this.form.offsetWidth > 0;
					const isVisible =
						this.form.style.display !== 'none' &&
						this.form.style.visibility !== 'hidden';

					if ( hasDimensions || isVisible ) {
						resolve();
					} else {
						setTimeout( checkReady, CHECK_INTERVAL_MS );
					}
				};

				checkReady();
			} );
		}

		ensureFormVisible() {
			return new Promise( ( resolve ) => {
				if ( ! this.form ) {
					resolve();
					return;
				}

				// Remover clases de ocultamiento
				this.form.classList.remove(
					'hidden',
					'eipsi-hidden',
					'eipsi-form-hidden'
				);

				// Aplicar estilos inline para asegurar visibilidad
				this.form.style.display = 'block';
				this.form.style.visibility = 'visible';
				this.form.style.opacity = '1';

				// También asegurarse de que el contenedor padre sea visible
				const formContainer = this.form.closest( '.eipsi-form' );
				if ( formContainer ) {
					formContainer.classList.remove(
						'hidden',
						'eipsi-hidden',
						'eipsi-form-hidden'
					);
					formContainer.style.display = 'block';
					formContainer.style.visibility = 'visible';
					formContainer.style.opacity = '1';
				}

				// Esperar a que los estilos se apliquen usando requestAnimationFrame
				requestAnimationFrame( () => {
					// Segundo frame para asegurar que el navegador procesó los cambios
					requestAnimationFrame( () => {
						resolve();
					} );
				} );
			} );
		}

		forceFormVisible() {
			if ( ! this.form ) {
				return;
			}

			// Forzar visibilidad sin importar el estado actual
			this.form.style.setProperty( 'display', 'block', 'important' );
			this.form.style.setProperty( 'visibility', 'visible', 'important' );
			this.form.style.setProperty( 'opacity', '1', 'important' );
			this.form.style.setProperty(
				'pointer-events',
				'auto',
				'important'
			);

			const formContainer = this.form.closest( '.eipsi-form' );
			if ( formContainer ) {
				formContainer.style.setProperty(
					'display',
					'block',
					'important'
				);
				formContainer.style.setProperty(
					'visibility',
					'visible',
					'important'
				);
				formContainer.style.setProperty( 'opacity', '1', 'important' );
				formContainer.style.setProperty(
					'pointer-events',
					'auto',
					'important'
				);
			}
		}

		async restorePartial( partial ) {
			const responses = partial.responses || {};
			const pageIndex = partial.page_index || 1;

			console.log( `[S&C] restorePartial: restoring ${Object.keys(responses).length} responses to page ${pageIndex}` );

			// Fix 10: Restaurar respuestas acumuladas
			this.accumulatedResponses = { ...responses };

			console.log( `[S&C] accumulatedResponses initialized with ${Object.keys(this.accumulatedResponses).length} fields` );

			// Restaurar los valores de los campos
			let restoredCount = 0;
			Object.keys( responses ).forEach( ( fieldName ) => {
				const restored = this.setFieldValue( fieldName, responses[ fieldName ] );
				if ( restored ) restoredCount++;
			} );

			console.log( `[S&C] Restored ${restoredCount}/${Object.keys(responses).length} field values to DOM` );

			this.hasResponses = Object.keys( responses ).length > 0;

			// Esperar un frame para que los campos se actualicen en el DOM
			await new Promise( ( resolve ) => {
				requestAnimationFrame( () => {
					requestAnimationFrame( resolve );
				} );
			} );

			// Configurar la página actual si EIPSIForms está disponible
			if (
				window.EIPSIForms &&
				typeof window.EIPSIForms.setCurrentPage === 'function'
			) {
				try {
					window.EIPSIForms.setCurrentPage( this.form, pageIndex, {
						trackChange: false,
					} );

					// Esperar a que la página se renderice
					await new Promise( ( resolve ) => {
						requestAnimationFrame( () => {
							requestAnimationFrame( resolve );
						} );
					} );

					// Actualizar el navegador de historial
					const navigator = window.EIPSIForms.getNavigator(
						this.form
					);
					if ( navigator && navigator.reset ) {
						navigator.reset();
						navigator.pushHistory( pageIndex );
					}
				} catch ( error ) {
					// Si setCurrentPage falla, continuar sin error crítico
					if (
						this.config?.settings?.debug &&
						window.console?.warn
					) {
						window.console.warn(
							'[EIPSI Save & Continue] Error al establecer página:',
							error
						);
					}
				}
			}
		}

		/**
		 * Obtener fingerprint del usuario desde localStorage/cookie
		 *
		 * @return {string|null} Fingerprint del usuario o null
		 */
		getUserFingerprint() {
			// OPCIÓN 1: Intentar desde localStorage (usado por fingerprinting)
			try {
				const fpData = window.localStorage.getItem(
					'eipsi_user_fingerprint'
				);
				if ( fpData ) {
					const parsed = JSON.parse( fpData );
					if ( parsed && parsed.fingerprint ) {
						return parsed.fingerprint;
					}
				}
			} catch ( error ) {
				// Ignore localStorage errors
			}

			// OPCIÓN 2: Intentar desde cookie (fallback para navegadores con localStorage deshabilitado)
			if ( document.cookie ) {
				const cookies = document.cookie.split( ';' );
				for ( let i = 0; i < cookies.length; i++ ) {
					const cookie = cookies[ i ].trim();
					if ( cookie.startsWith( 'eipsi_fingerprint=' ) ) {
						return cookie.substring( 'eipsi_fingerprint='.length );
					}
				}
			}

			// OPCIÓN 3: Intentar desde sessionStorage (usado en algunos flujos)
			try {
				const sessionFp =
					window.sessionStorage.getItem( 'eipsi_fingerprint' );
				if ( sessionFp ) {
					return sessionFp;
				}
			} catch ( error ) {
				// Ignore sessionStorage errors
			}

			return null;
		}

		/**
		 * Obtener randomization_id desde el contenedor de aleatorización
		 *
		 * @return {string|null} Randomization ID o null
		 */
		getRandomizationId() {
			// Buscar el contenedor de aleatorización en el árbol del formulario
			const container = this.form.closest(
				'.eipsi-randomization-container'
			);
			if ( container ) {
				return container.getAttribute( 'data-randomization-id' );
			}
			return null;
		}

		/**
		 * Cerrar sesión de aleatorización (persistent_mode=OFF)
		 *
		 * Elimina la asignación del usuario de la DB y borra la cookie de rotación.
		 * Si no hay randomization_id o fingerprint, no hace nada (sin errores).
		 *
		 * @return {Promise<boolean>} True si se cerró correctamente o no hay aleatorización
		 */
		async closeRandomizationSession() {
			const randomizationId = this.getRandomizationId();
			const userFingerprint = this.getUserFingerprint();

			// Si no hay aleatorización activa, no hacer nada (no es un error)
			if ( ! randomizationId || ! userFingerprint ) {
				if ( this.config?.settings?.debug && window.console?.debug ) {
					window.console.debug(
						'[EIPSI Save & Continue] No hay sesión de aleatorización activa'
					);
				}
				return true;
			}

			// Si no hay ajaxUrl, no se puede hacer la petición
			if ( ! this.config.ajaxUrl ) {
				if ( this.config?.settings?.debug && window.console?.warn ) {
					window.console.warn(
						'[EIPSI Save & Continue] No hay ajaxUrl para cerrar sesión de aleatorización'
					);
				}
				return false;
			}

			try {
				const formData = new URLSearchParams();
				formData.append(
					'action',
					'eipsi_close_randomization_session'
				);
				formData.append( 'randomization_id', randomizationId );
				formData.append( 'user_fingerprint', userFingerprint );

				const response = await fetch( this.config.ajaxUrl, {
					method: 'POST',
					body: formData,
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					credentials: 'same-origin',
				} );

				if ( ! response.ok ) {
					if (
						this.config?.settings?.debug &&
						window.console?.warn
					) {
						window.console.warn(
							'[EIPSI Save & Continue] Error al cerrar sesión de aleatorización:',
							response.status
						);
					}
					return false;
				}

				const data = await response.json();

				if ( this.config?.settings?.debug && window.console?.debug ) {
					window.console.debug(
						'[EIPSI Save & Continue] Sesión de aleatorización cerrada:',
						data
					);
				}

				return data.success || false;
			} catch ( error ) {
				if ( this.config?.settings?.debug && window.console?.error ) {
					window.console.error(
						'[EIPSI Save & Continue] Error al cerrar sesión de aleatorización:',
						error
					);
				}
				// No lanzar error (permitir que el flujo continúe)
				return false;
			}
		}

		setFieldValue( fieldName, value ) {
			if ( value === undefined || value === null ) {
				return;
			}

			const fields = this.form.querySelectorAll(
				`[name="${ fieldName }"]`
			);

			if ( fields.length > 1 ) {
				fields.forEach( ( field ) => {
					if ( field.type === 'radio' ) {
						field.checked = field.value === value;
					} else if ( field.type === 'checkbox' ) {
						if ( Array.isArray( value ) ) {
							field.checked = value.includes( field.value );
						} else {
							field.checked =
								value === true ||
								value === 'true' ||
								field.value === value;
						}
					}
				} );
				return;
			}

			const safeFieldName =
				window.CSS && window.CSS.escape
					? CSS.escape( fieldName )
					: fieldName.replace(
							/([ #.;?*+~'"^$\[\]()=>|/@])/g,
							'\\$1'
					  );

			const field =
				fields[ 0 ] ||
				this.form.querySelector( `[id="${ safeFieldName }"]` );

			if ( ! field ) {
				return;
			}

			if ( field.type === 'checkbox' ) {
				if ( Array.isArray( value ) ) {
					field.checked = value.includes( field.value );
				} else {
					field.checked =
						value === true ||
						value === 'true' ||
						field.value === value;
				}
				return;
			}

			if (
				field.tagName === 'SELECT' &&
				field.multiple &&
				Array.isArray( value )
			) {
				Array.from( field.options ).forEach( ( option ) => {
					option.selected = value.includes( option.value );
				} );
				return;
			}

			const normalized = Array.isArray( value ) ? value[ 0 ] : value;
			field.value = normalized;

			if ( field.type === 'range' ) {
				field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
		}

		async discardPartial() {
			await this.clearFromIDB();
			await this.discardFromServer();

			// Limpiar almacenamiento local/sesión
			// v2.5.3 - Limpiar tanto localStorage (nuevo) como sessionStorage (legacy)
			try {
				const sessionKey = `eipsi_session_${
					this.formId || 'default'
				}`;
				window.localStorage.removeItem( sessionKey );
				window.sessionStorage.removeItem( sessionKey );

				const storageKey = `eipsi_form_responses_${
					this.formId || 'default'
				}`;
				window.localStorage.removeItem( storageKey );
			} catch ( error ) {
				// Ignore
			}

			this.hasResponses = false;
			this.completed = false;
		}

		async clearFromIDB() {
			if ( ! this.db ) {
				this.hasResponses = false;
				return false;
			}

			return new Promise( ( resolve ) => {
				const transaction = this.db.transaction(
					[ IDB_STORE ],
					'readwrite'
				);
				const store = transaction.objectStore( IDB_STORE );
				const key = [ this.formId, this.participantId, this.sessionId ];
				const request = store.delete( key );

				request.onsuccess = () => {
					this.hasResponses = false;
					resolve( true );
				};
				request.onerror = () => resolve( false );
			} );
		}

		async discardFromServer() {
			if ( ! this.config.ajaxUrl ) {
				return;
			}

			try {
				const formData = new URLSearchParams();
				formData.append( 'action', 'eipsi_discard_partial_response' );
				formData.append( 'form_id', this.formId );
				formData.append( 'participant_id', this.participantId );
				formData.append( 'session_id', this.sessionId );

				await fetch( this.config.ajaxUrl, {
					method: 'POST',
					body: formData,
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					keepalive: true,
					credentials: 'same-origin',
				} );
			} catch ( error ) {
				// Silencioso: mientras IndexedDB se limpie, el usuario puede continuar
			}
		}

		setupAutosave() {
			if ( this.autosaveTimer ) {
				clearInterval( this.autosaveTimer );
			}

			this.autosaveTimer = window.setInterval( () => {
				this.queueSave( 'auto' );
			}, AUTOSAVE_INTERVAL );
		}

		setupBeforeUnload() {
			if ( this.beforeUnloadHandler ) {
				return;
			}

			this.beforeUnloadHandler = ( event ) => {
				if ( this.completed ) {
					return;
				}

				this.savePartialSync();

				if ( this.hasResponses ) {
					const message =
						'Tus respuestas se están guardando. Podés volver cuando quieras.';
					event.preventDefault();
					event.returnValue = message;
					return message;
				}

				return undefined;
			};

			window.addEventListener( 'beforeunload', this.beforeUnloadHandler );
		}

		removeBeforeUnload() {
			if ( this.beforeUnloadHandler ) {
				window.removeEventListener(
					'beforeunload',
					this.beforeUnloadHandler
				);
				this.beforeUnloadHandler = null;
			}
		}

		setupChangeListeners() {
			const fields = this.form.querySelectorAll(
				'input, textarea, select'
			);

			fields.forEach( ( field ) => {
				field.addEventListener( 'input', () =>
					this.handleFieldInput()
				);
				field.addEventListener( 'change', () =>
					this.handleFieldChange( field )
				);
			} );
		}

		handleFieldChange( field ) {
			if ( this.completed ) {
				return;
			}
			this.dirtyFields.add( field.name || field.id );
			this.queueSave( 'field-change' );
		}

		handleFieldInput() {
			if ( this.completed ) {
				return;
			}

			if ( this.inputDebounceId ) {
				clearTimeout( this.inputDebounceId );
			}

			this.inputDebounceId = window.setTimeout( () => {
				this.saveToIDB();
			}, INPUT_DEBOUNCE );
		}

		async queueSave( trigger = 'manual' ) {
			if ( this.completed ) {
				return;
			}

			// Descartar saves duplicados en cola — solo mantener el último por trigger
			this.saveQueue = this.saveQueue.filter( s => s.trigger !== trigger );
			this.saveQueue.push( { trigger, timestamp: Date.now() } );

			if ( ! this.processingQueue ) {
				await this.processSaveQueue();
			}
		}

		async processSaveQueue() {
			this.processingQueue = true;
			while ( this.saveQueue.length > 0 ) {
				const save = this.saveQueue.shift();
				await this.executeSave( save.trigger );
			}
			this.processingQueue = false;
		}

		async executeSave( trigger ) {
			try {
				console.log( `[S&C] executeSave triggered by: ${trigger}` );

				// Fix 5: fullScan solo para beforeunload o primer guardado
				const fullScan = trigger === 'beforeunload' || this.dirtyFields.size === 0;
				const responses = this.collectResponses( fullScan );
				const currentPage = this.getCurrentPage();
				this.hasResponses = Object.keys( responses ).length > 0;

				console.log( `[S&C] executeSave: collected ${Object.keys(responses).length} responses, hasResponses=${this.hasResponses}` );

				if (
					this.config?.settings?.debug &&
					window.console &&
					typeof window.console.debug === 'function'
				) {
					window.console.debug(
						'[EIPSI Save & Continue] Guardando borrador',
						{
							trigger,
							page: currentPage,
							hasResponses: this.hasResponses,
						}
					);
				}

				const idbResult = await this.saveToIDB( responses, currentPage );
				console.log( `[S&C] saveToIDB result: ${idbResult}` );

				const serverResult = await this.saveToServer( responses, currentPage );
				console.log( `[S&C] saveToServer result: ${serverResult}` );

				// Fix 5: Limpiar dirty tracking después de save exitoso
				this.dirtyFields.clear();
				console.log( `[S&C] executeSave completed successfully for trigger: ${trigger}` );
			} catch ( error ) {
				if ( window.console && window.console.warn ) {
					window.console.warn(
						'[EIPSI Save & Continue] Save failed:',
						error
					);
				}
			}
		}

		savePartialSync() {
			if ( this.completed || ! this.config.ajaxUrl ) {
				return;
			}

			const responses = this.collectResponses( true ); // Force full scan before unload
			const currentPage = this.getCurrentPage();

			const payload = new URLSearchParams();
			payload.append( 'action', 'eipsi_save_partial_response' );
			payload.append( 'form_id', this.formId );
			payload.append( 'participant_id', this.participantId );
			payload.append( 'session_id', this.sessionId );
			payload.append( 'page_index', currentPage );
			payload.append( 'responses', JSON.stringify( responses ) );
			payload.append(
				'nonce',
				this.config?.savePartialNonce || this.config?.nonce || ''
			);

			const bodyString = payload.toString();

			if ( navigator.sendBeacon ) {
				const blob = new Blob( [ bodyString ], {
					type: 'application/x-www-form-urlencoded',
				} );
				navigator.sendBeacon( this.config.ajaxUrl, blob );
			} else {
				fetch( this.config.ajaxUrl, {
					method: 'POST',
					body: bodyString,
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					keepalive: true,
					credentials: 'same-origin',
				} ).catch( () => {} );
			}

			this.saveToIDB( responses, currentPage );
		}

		collectResponses( fullScan = false ) {
			const currentPageResponses = {};
			const currentPage = this.getCurrentPage();
		
			console.log( `[S&C] collectResponses called: fullScan=${fullScan}, dirtyFields=${this.dirtyFields.size}, page=${currentPage}` );
		
			if ( fullScan || this.dirtyFields.size === 0 ) {
				// Scan completo — para beforeunload y primer guardado
				// Fix: Habilitar temporalmente campos deshabilitados para que FormData los capture
				const disabledFields = [];
				this.form.querySelectorAll( '[disabled]' ).forEach( ( field ) => {
					disabledFields.push( field );
					field.disabled = false;
				} );
			
				console.log( `[S&C] Temporarily enabled ${disabledFields.length} disabled fields for FormData capture` );
			
				const formData = new FormData( this.form );
			
				// Restaurar estado disabled
				disabledFields.forEach( ( field ) => {
					field.disabled = true;
				} );
			
				formData.forEach( ( value, key ) => {
					if ( EXCLUDED_FIELDS.has( key ) ) {
						return;
					}
					if ( value instanceof File ) {
						return;
					}
					currentPageResponses[ key ] = value;
				} );
			
				console.log( `[S&C] Full scan captured ${Object.keys(currentPageResponses).length} fields from page ${currentPage}` );
			} else {
				// Solo campos modificados (Fix 5: dirty tracking)
				this.dirtyFields.forEach( ( fieldName ) => {
					const field = this.form.querySelector( `[name="${ fieldName }"]` );
					if ( field ) {
						currentPageResponses[ fieldName ] = field.value;
					}
				} );
			
				console.log( `[S&C] Dirty scan captured ${Object.keys(currentPageResponses).length} modified fields` );
			}
		
			// CAPTURAR ESTADO DEL CONSENTIMIENTO EXPLÍCITAMENTE
			this.captureConsentStatus( currentPageResponses );
		
			const previousAccumulatedCount = Object.keys( this.accumulatedResponses ).length;
		
			// Fix 10: Acumular respuestas de la página actual con las anteriores
			Object.assign( this.accumulatedResponses, currentPageResponses );
		
			const totalAccumulatedCount = Object.keys( this.accumulatedResponses ).length;
		
			console.log( `[S&C] Accumulated responses: ${previousAccumulatedCount} → ${totalAccumulatedCount} (added ${totalAccumulatedCount - previousAccumulatedCount} new)` );
		
			// Retornar TODAS las respuestas acumuladas, no solo la página actual
			return { ...this.accumulatedResponses };
		}

		/**
		 * Captura el estado del checkbox de consentimiento informado
		 * Incluye tanto checked como unchecked para garantizar consistencia
		 *
		 * @param {Object} responses - Objeto de respuestas a modificar
		 */
		captureConsentStatus( responses ) {
			// PASO 1: Capturar la decisión del consentimiento (accepted/declined)
			const decisionField = this.form.querySelector( '#eipsi-consent-decision, input[name="eipsi_consent_decision"]' );
			if ( decisionField && decisionField.value ) {
				responses[ 'eipsi_consent_decision' ] = decisionField.value;
				console.log( `[S&C] Captured consent decision: ${decisionField.value}` );
			}

			// PASO 2: Capturar el checkbox de confirmación de lectura
			const readingCheckbox = this.form.querySelector( '#eipsi-consent-confirm-reading, input[name="eipsi_consent_confirm_reading"]' );
			if ( readingCheckbox ) {
				responses[ 'eipsi_consent_confirm_reading' ] = readingCheckbox.checked ? 'true' : 'false';
				console.log( `[S&C] Captured reading confirmation: ${readingCheckbox.checked}` );
			}

			// PASO 3: Buscar otros campos de consentimiento (legacy/custom)
			const consentSelectors = [
				'input[name="consent_informed"]',
				'input[name="consent"]',
				'input[name="eipsi_consent"]',
				'input[data-consent-field]',
				'#eipsi-consent-checkbox',
				'.eipsi-consent-checkbox input[type="checkbox"]',
			];

			let consentField = null;
			for ( const selector of consentSelectors ) {
				consentField = this.form.querySelector( selector );
				if ( consentField ) {
					break;
				}
			}

			// Si encontramos el campo, registrar su estado explícitamente
			if ( consentField ) {
				const fieldName = consentField.name || 'consent_informed';
				const isChecked = consentField.checked;

				// Guardar el estado booleano explícito
				responses[ fieldName ] = isChecked ? 'true' : 'false';

				// Metadata adicional para auditoría
				responses[ `${ fieldName }_timestamp` ] = new Date().toISOString();
				responses[ `${ fieldName }_page` ] = this.getCurrentPage();

				if ( this.config?.settings?.debug && window.console?.debug ) {
					window.console.debug(
						'[EIPSI Save & Continue] Consentimiento capturado:',
						{
							field: fieldName,
							checked: isChecked,
							page: this.getCurrentPage(),
						}
					);
				}
			}
		}

		getCurrentPage() {
			const pageInput = this.form.querySelector( '.eipsi-current-page' );
			return pageInput ? parseInt( pageInput.value, 10 ) || 1 : 1;
		}

		async saveToIDB( responses = null, pageIndex = null ) {
			if ( ! this.db ) {
				return false;
			}

			const payload = {
				form_id: this.formId,
				participant_id: this.participantId,
				session_id: this.sessionId,
				page_index: pageIndex || this.getCurrentPage(),
				responses: responses || this.collectResponses(),
				updated_at: new Date().toISOString(),
			};

			return new Promise( ( resolve ) => {
				const transaction = this.db.transaction(
					[ IDB_STORE ],
					'readwrite'
				);
				const store = transaction.objectStore( IDB_STORE );
				const request = store.put( payload );

				request.onsuccess = () => resolve( true );
				request.onerror = () => resolve( false );
			} );
		}

		async saveToServer( responses = null, pageIndex = null ) {
			// Fix 7: Circuit breaker check
			if ( this.circuitBreaker.isOpen() ) {
				console.warn( '[EIPSI] Circuit breaker abierto — solo guardando local' );
				return false;
			}
		
			return this.circuitBreaker.execute( async () => {
				if ( ! this.config.ajaxUrl ) {
					return false;
				}
		
				try {
					const formData = new FormData();
					formData.append( 'action', 'eipsi_save_partial_response' );
					formData.append( 'form_id', this.formId );
					formData.append( 'participant_id', this.participantId );
					formData.append( 'session_id', this.sessionId );
					formData.append(
						'page_index',
						pageIndex || this.getCurrentPage()
					);
					formData.append(
						'responses',
						JSON.stringify( responses || this.collectResponses() )
					);
					formData.append(
						'nonce',
						this.config?.savePartialNonce || this.config?.nonce || ''
					);
		
					const response = await fetch( this.config.ajaxUrl, {
						method: 'POST',
						body: formData,
						keepalive: true,
						credentials: 'same-origin',
					} );
		
					const data = await response.json();
					return !! data.success;
				} catch ( error ) {
					return false;
				}
			} );
		}

		handleFormCompleted() {
			this.completed = true;
			this.clearFromIDB();
			this.discardFromServer();
			this.removeBeforeUnload();

			// Limpiar almacenamiento local/sesión
			// v2.5.3 - Limpiar tanto localStorage (nuevo) como sessionStorage (legacy)
			try {
				const sessionKey = `eipsi_session_${
					this.formId || 'default'
				}`;
				window.localStorage.removeItem( sessionKey );
				window.sessionStorage.removeItem( sessionKey );

				// Limpiar cualquier respaldo en localStorage si existiera (por compatibilidad)
				const storageKey = `eipsi_form_responses_${
					this.formId || 'default'
				}`;
				window.localStorage.removeItem( storageKey );
			} catch ( error ) {
				// Ignore storage errors
			}

			if ( this.autosaveTimer ) {
				clearInterval( this.autosaveTimer );
				this.autosaveTimer = null;
			}

			if ( this.inputDebounceId ) {
				clearTimeout( this.inputDebounceId );
				this.inputDebounceId = null;
			}
		}

		destroy() {
			this.handleFormCompleted();
			if ( this.db ) {
				this.db.close();
				this.db = null;
			}
		}
	}

	document.addEventListener( 'DOMContentLoaded', () => {
		console.log( '[S&C] DOMContentLoaded - Initializing Save & Continue' );
		
		const forms = document.querySelectorAll(
			'.eipsi-form form'
		);

		console.log( `[S&C] Found ${forms.length} forms to initialize` );

		forms.forEach( ( form, index ) => {
			if ( ! window.eipsiFormsConfig ) {
				console.warn( `[S&C] Form ${index}: window.eipsiFormsConfig not found, skipping` );
				return;
			}
			
			if ( form.eipsiSaveContinue ) {
				console.log( `[S&C] Form ${index}: already initialized, skipping` );
				return;
			}

			console.log( `[S&C] Form ${index}: initializing...` );
			const instance = new EIPSISaveContinue(
				form,
				window.eipsiFormsConfig
			);
			form.eipsiSaveContinue = instance;
			console.log( `[S&C] Form ${index}: initialized successfully` );
		} );
		
		console.log( '[S&C] Initialization complete' );
	} );

	window.EIPSISaveContinue = EIPSISaveContinue;

	/**
	 * Helper global para diagnóstico de Save & Continue
	 * Uso desde consola: window.eipsiDebugSaveState()
	 */
	window.eipsiDebugSaveState = async function() {
		if (!window.eipsiPartialDebugConfig?.nonce) {
			console.error('[S&C DEBUG] El diagnóstico requiere una sesión de administrador.');
			return;
		}
		const form = document.querySelector('.eipsi-form form');
		if (!form) {
			console.error('[S&C DEBUG] No se encontró el formulario');
			return;
		}

		const instance = form.eipsiSaveContinue;
		if (!instance) {
			console.error('[S&C DEBUG] Save & Continue no está inicializado');
			return;
		}

		console.log('[S&C DEBUG] ========== DIAGNÓSTICO SAVE & CONTINUE ==========');
		
		// Estado del frontend
		console.log('[S&C DEBUG] Frontend State:');
		console.log('  - Inicializado:', !!instance);
		console.log('  - Página actual:', instance.getCurrentPage());
		console.log('  - Respuestas acumuladas:', Object.keys(instance.accumulatedResponses || {}).length);
		console.log('  - Campos modificados (dirty):', instance.dirtyFields ? instance.dirtyFields.size : 0);
		console.log('  - Has responses:', instance.hasResponses);
		console.table(instance.accumulatedResponses || {});

		// Consultar backend
		console.log('[S&C DEBUG] Consultando backend...');
		try {
			const formData = new FormData();
			formData.append('action', 'eipsi_debug_partial_response');
			formData.append('nonce', window.eipsiPartialDebugConfig.nonce);
			formData.append('form_id', instance.formId);
			formData.append('participant_id', instance.participantId);
			formData.append('session_id', instance.sessionId);

			const response = await fetch(window.eipsiFormsConfig?.ajaxUrl || '/wp-admin/admin-ajax.php', {
				method: 'POST',
				body: formData
			});

			const result = await response.json();
			
			if (result.success) {
				const data = result.data;
				console.log('[S&C DEBUG] Backend State:');
				console.log('  - Datos encontrados:', data.found);
				if (data.found) {
					console.log('  - Total respuestas:', data.summary.total_responses);
					console.log('  - Página guardada:', data.summary.page_index);
					console.log('  - Última actualización:', data.summary.last_updated);
					console.log('  - Campos de consentimiento:', data.summary.consent_fields_count);
					console.log('  - Campos demográficos:', data.summary.demographic_fields_count);
					console.log('  - Campos de escalas:', data.summary.scale_fields_count);
					console.log('  - Otros campos:', data.summary.other_fields_count);
					
					console.log('[S&C DEBUG] Análisis de campos:');
					if (data.field_analysis.consent_fields.length > 0) {
						console.log('  Consentimiento:', data.field_analysis.consent_fields);
					}
					if (data.field_analysis.demographic_fields.length > 0) {
						console.log('  Demográficos:', data.field_analysis.demographic_fields);
					}
					if (data.field_analysis.scale_fields.length > 0) {
						console.log('  Escalas (primeros 5):', data.field_analysis.scale_fields.slice(0, 5));
					}
					
					console.log('[S&C DEBUG] Respuestas completas (raw):');
					console.table(data.raw_responses);
				} else {
					console.warn('[S&C DEBUG] No hay datos guardados en backend para esta sesión');
				}
			} else {
				console.error('[S&C DEBUG] Error al consultar backend:', result);
			}
		} catch (error) {
			console.error('[S&C DEBUG] Error en la consulta:', error);
		}

		console.log('[S&C DEBUG] ========== FIN DIAGNÓSTICO ==========');
		console.log('[S&C DEBUG] Tip: Podés copiar los logs con: copy(console.history)');
	};

	/**
	 * Handler global para botones "Comenzar de nuevo" en thank-you page
	 *
	 * Detecta si el formulario está dentro de un contenedor de aleatorización
	 * y cierra la sesión antes de recargar la página.
	 */
	document.addEventListener( 'DOMContentLoaded', () => {
		const restartButtons = document.querySelectorAll(
			'.eipsi-randomization-container [data-action="restart"]'
		);

		restartButtons.forEach( ( button ) => {
			button.addEventListener( 'click', async ( event ) => {
				event.preventDefault();
				button.disabled = true;
				button.textContent = 'Reiniciando...';

				try {
					// Obtener randomization_id desde el contenedor
					const container = button.closest(
						'.eipsi-randomization-container'
					);
					const randomizationId = container
						? container.getAttribute( 'data-randomization-id' )
						: null;

					// Obtener fingerprint del usuario
					let userFingerprint = null;

					// OPCIÓN 1: Desde localStorage (usado por fingerprinting)
					try {
						const fpData = window.localStorage.getItem(
							'eipsi_user_fingerprint'
						);
						if ( fpData ) {
							const parsed = JSON.parse( fpData );
							if ( parsed && parsed.fingerprint ) {
								userFingerprint = parsed.fingerprint;
							}
						}
					} catch ( error ) {
						// Ignore
					}

					// OPCIÓN 2: Desde cookie
					if ( ! userFingerprint && document.cookie ) {
						const cookies = document.cookie.split( ';' );
						for ( let i = 0; i < cookies.length; i++ ) {
							const cookie = cookies[ i ].trim();
							if ( cookie.startsWith( 'eipsi_fingerprint=' ) ) {
								userFingerprint = cookie.substring(
									'eipsi_fingerprint='.length
								);
								break;
							}
						}
					}

					// OPCIÓN 3: Desde sessionStorage
					if ( ! userFingerprint ) {
						try {
							userFingerprint =
								window.sessionStorage.getItem(
									'eipsi_fingerprint'
								);
						} catch ( error ) {
							// Ignore
						}
					}

					// Si hay randomization_id y fingerprint, cerrar sesión
					if (
						randomizationId &&
						userFingerprint &&
						window.eipsiFormsConfig &&
						window.eipsiFormsConfig.ajaxUrl
					) {
						const formData = new URLSearchParams();
						formData.append(
							'action',
							'eipsi_close_randomization_session'
						);
						formData.append( 'randomization_id', randomizationId );
						formData.append( 'user_fingerprint', userFingerprint );

						await fetch( window.eipsiFormsConfig.ajaxUrl, {
							method: 'POST',
							body: formData,
							headers: {
								'Content-Type':
									'application/x-www-form-urlencoded',
							},
							credentials: 'same-origin',
						} );
					}
				} catch ( error ) {
					// Ignorar errores (continuar de todas formas)
					if ( window.console && window.console.warn ) {
						window.console.warn(
							'[EIPSI Save & Continue] Error al cerrar sesión de aleatorización:',
							error
						);
					}
				}

				// Recargar la página (esto asignará un nuevo formulario en rotación)
				window.location.reload();
			} );
		} );
	} );
} )();
