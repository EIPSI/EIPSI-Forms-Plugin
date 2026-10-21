/**
 * EIPSI Forms Frontend JavaScript
 * Handles form validation, submission, and user interactions
 * Works with both shortcode and block-rendered forms
 */

( function () {
    'use strict';

    /* global navigator, localStorage */

    /**
     * ✅ v1.4.0 - Append fingerprint to all forms on page load
     * This ensures user fingerprint is captured for ALL form submissions
     */
    function initFingerprintFields() {
        const forms = document.querySelectorAll( '.vas-form.eipsi-form-element' );
        
        if ( ! forms.length ) {
            return;
        }

        // Get fingerprint from localStorage or cookie
        const fingerprint = localStorage.getItem( 'eipsi_fingerprint' ) || 
            document.cookie.split( '; ' ).find( row => row.startsWith( 'eipsi_fingerprint=' ) )?.split( '=' )[ 1 ];

        if ( ! fingerprint ) {
            if ( window.console && window.console.debug ) {
                window.console.debug( '[EIPSI] No fingerprint found in localStorage or cookies' );
            }
            return;
        }

        if ( window.console && window.console.debug ) {
            window.console.debug( '[EIPSI] Fingerprint found:', fingerprint.substring( 0, 20 ) + '...' );
        }

        // Add hidden field to each form
        forms.forEach( form => {
            let fingerprintInput = form.querySelector( 'input[name="eipsi_user_fingerprint"]' );
            
            if ( ! fingerprintInput ) {
                fingerprintInput = document.createElement( 'input' );
                fingerprintInput.type = 'hidden';
                fingerprintInput.name = 'eipsi_user_fingerprint';
                fingerprintInput.className = 'eipsi-fingerprint-field';
                form.appendChild( fingerprintInput );
            }
            
            fingerprintInput.value = fingerprint;
        } );
    }

    // Initialize fingerprint fields when DOM is ready
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', initFingerprintFields );
    } else {
        initFingerprintFields();
    }

    /**
     * Obtiene o genera un Participant ID único y persistente
     * - Mismo ID para todos los formularios en la sesión
     * - Persiste en localStorage (sobrevive recargas)
     * - Completamente anónimo (UUID v4)
     *
     * @return {string} "p-a1b2c3d4e5f6" (p- + 12 caracteres)
     */
    function getUniversalParticipantId() {
        const STORAGE_KEY = 'eipsi_participant_id';

        let pid = localStorage.getItem( STORAGE_KEY );
        if ( ! pid ) {
            // Generar UUID v4 truncado a 12 caracteres
            pid =
                'p-' +
                crypto.randomUUID().replace( /-/g, '' ).substring( 0, 12 );
            localStorage.setItem( STORAGE_KEY, pid );
        }

        return pid;
    }

    /**
     * Valida que el consentimiento esté aceptado
     * @param {HTMLFormElement} form - El formulario específico a validar
     * @returns {boolean} true si está validado, false si no
     */
    function validateConsentAccepted( form ) {
        // ✅ FIX v1.4.3: Buscar solo DENTRO del formulario específico
        // Evita validar checkboxes de otros formularios en la misma página
        const consentCheckbox = form.querySelector(
            'input[name="eipsi_consent_accepted"]'
        );
        if ( ! consentCheckbox ) return true; // Si no hay bloque de consent, pasar

        if ( ! consentCheckbox.checked ) {
            // Crear mensaje de error clínico
            const errorMsg = document.createElement( 'div' );
            errorMsg.className = 'eipsi-consent-error';
            errorMsg.textContent =
                '⛔ Debes aceptar los términos de consentimiento';
            errorMsg.style.cssText =
                'background:#fee; color:#c33; padding:12px; margin:12px 0; border-left:4px solid #c33;';

            // Remover error previo si existe
            const consentBlock = consentCheckbox.closest( '.eipsi-consent-block' );
            if ( consentBlock ) {
                const existingError = consentBlock.querySelector( '.eipsi-consent-error' );
                if ( existingError ) existingError.remove();
                consentBlock.appendChild( errorMsg );
            }
            
            consentCheckbox.scrollIntoView( {
                behavior: 'smooth',
                block: 'center',
            } );

            return false;
        }

        // Limpiar mensaje de error si existe
        const consentBlock = consentCheckbox.closest( '.eipsi-consent-block' );
        if ( consentBlock ) {
            const existingError = consentBlock.querySelector( '.eipsi-consent-error' );
            if ( existingError ) existingError.remove();
        }

        return true;
    }

    /**
     * Obtiene o genera Session ID único para esta sesión de formulario
     * Persiste durante toda la sesión (sessionStorage) para Save & Continue
     * @param {string} formId - ID del formulario
     * @return {string} "sess-[timestamp]-[random]"
     */
    function getSessionId( formId ) {
        const SESSION_KEY = `eipsi_session_${ formId || 'default' }`;
        let sid = null;
        try {
            sid = sessionStorage.getItem( SESSION_KEY );
        } catch ( error ) {
            sid = null;
        }

        if ( ! sid ) {
            const timestamp = Date.now();
            const random = Math.random().toString( 36 ).substring( 2, 8 );
            sid = `sess-${ timestamp }-${ random }`;
            try {
                sessionStorage.setItem( SESSION_KEY, sid );
            } catch ( error ) {
                // Ignore storage errors (private mode)
            }
        }
        return sid;
    }

    class ConditionalNavigator {
        constructor( form ) {
            this.form = form;
            this.fieldCache = new Map();
            this.history = [];
            this.visitedPages = new Set();
            this.skippedPages = new Set(); // v2.1.3 Fix: missing initialization
        }

        parseConditionalLogic( jsonString ) {
            if ( ! jsonString || jsonString === 'true' ) {
                return null;
            }

            try {
                return JSON.parse( jsonString );
            } catch ( error ) {
                if ( window.console && window.console.warn ) {
                    window.console.warn(
                        '[EIPSI Forms] Invalid conditional logic JSON:',
                        jsonString,
                        error
                    );
                }
                return null;
            }
        }

        normalizeConditionalLogic( logic ) {
            if ( ! logic ) {
                return null;
            }

            if ( Array.isArray( logic ) ) {
                return {
                    enabled: logic.length > 0,
                    rules: logic.map( ( rule ) => ( {
                        id: rule.id || `rule-${ Date.now() }`,
                        matchValue: rule.value || rule.matchValue || '',
                        action: rule.action || 'nextPage',
                        targetPage: rule.targetPage || null,
                    } ) ),
                    defaultAction: 'nextPage',
                    defaultTargetPage: null,
                };
            }

            if ( typeof logic === 'object' && logic.enabled !== undefined ) {
                return logic;
            }

            return null;
        }

        getFieldValue( field ) {
            const fieldType = field.dataset.fieldType;

            switch ( fieldType ) {
                case 'select':
                    const select = field.querySelector( 'select' );
                    return select ? select.value : '';

                case 'radio':
                    const checkedRadio = field.querySelector(
                        'input[type="radio"]:checked'
                    );
                    return checkedRadio ? checkedRadio.value : '';

                case 'likert':
                    const checkedLikert = field.querySelector(
                        'input[type="radio"]:checked'
                    );
                    return checkedLikert ? checkedLikert.value : '';

                case 'checkbox':
                    const checkedBoxes = field.querySelectorAll(
                        'input[type="checkbox"]:checked'
                    );
                    return Array.from( checkedBoxes ).map( ( cb ) => cb.value );

                case 'vas-slider':
                    const slider = field.querySelector( 'input[type="range"]' );
                    if ( slider ) {
                        // If untouched, treat as null (no answer)
                        if ( slider.dataset.touched === 'false' ) {
                            return null;
                        }
                        const value = parseFloat( slider.value );
                        return ! Number.isNaN( value ) ? value : null;
                    }
                    return null;

                default:
                    return '';
            }
        }

        /**
         * Evalúa una condición individual (nueva estructura con conditions[])
         * @param {Object} condition - Condición individual con fieldId, operator, value/threshold
         * @param {HTMLElement} pageElement - Elemento de la página actual
         * @return {boolean} true si la condición se cumple
         */
        evaluateCondition( condition, pageElement ) {
            if ( ! condition || ! pageElement ) {
                return false;
            }

            // Buscar el campo por fieldId o fieldName
            const fieldId = condition.fieldId;
            if ( ! fieldId ) {
                return false;
            }

            const field = pageElement.querySelector(
                `[data-field-name="${ fieldId }"]`
            );

            if ( ! field ) {
                return false;
            }

            const fieldValue = this.getFieldValue( field );

            if (
                ! fieldValue &&
                fieldValue !== 0 &&
                ( ! Array.isArray( fieldValue ) || fieldValue.length === 0 )
            ) {
                return false;
            }

            // Condición numérica (VAS, Likert con operadores numéricos)
            if (
                condition.operator &&
                condition.threshold !== undefined &&
                condition.fieldType === 'numeric'
            ) {
                const numValue =
                    typeof fieldValue === 'number'
                        ? fieldValue
                        : parseFloat( fieldValue );

                if ( Number.isNaN( numValue ) ) {
                    return false;
                }

                const threshold = parseFloat( condition.threshold );
                if ( Number.isNaN( threshold ) ) {
                    return false;
                }

                switch ( condition.operator ) {
                    case '>=':
                        return numValue >= threshold;
                    case '<=':
                        return numValue <= threshold;
                    case '>':
                        return numValue > threshold;
                    case '<':
                        return numValue < threshold;
                    case '==':
                        return numValue === threshold;
                    default:
                        return false;
                }
            }

            // Condición discreta (RADIO, CHECKBOX, SELECT)
            if ( condition.value !== undefined ) {
                // CHECKBOX (array de valores)
                if ( Array.isArray( fieldValue ) ) {
                    return fieldValue.includes( condition.value );
                }

                // RADIO, SELECT, LIKERT (valor único)
                return String( fieldValue ) === String( condition.value );
            }

            return false;
        }

        /**
         * Evalúa una regla completa con múltiples condiciones AND/OR
         * @param {Object} rule - Regla con conditions[] y logicalOperator
         * @param {HTMLElement} pageElement - Elemento de la página actual
         * @return {boolean} true si la regla se cumple
         */
        evaluateRule( rule, pageElement ) {
            if ( ! rule || ! pageElement ) {
                return false;
            }

            // Nueva estructura: rule.conditions[]
            if (
                Array.isArray( rule.conditions ) &&
                rule.conditions.length > 0
            ) {
                // Evaluar cada condición
                const results = rule.conditions.map( ( cond ) =>
                    this.evaluateCondition( cond, pageElement )
                );

                // Si solo hay una condición, devolver su resultado
                if ( results.length === 1 ) {
                    return results[ 0 ];
                }

                // Múltiples condiciones: aplicar AND/OR
                // La primera condición no tiene logicalOperator, las siguientes sí
                let finalResult = results[ 0 ];

                for ( let i = 1; i < results.length; i++ ) {
                    const operator =
                        rule.conditions[ i ].logicalOperator || 'AND';

                    if ( operator === 'OR' ) {
                        finalResult = finalResult || results[ i ];
                    } else {
                        // AND por defecto
                        finalResult = finalResult && results[ i ];
                    }
                }

                return finalResult;
            }

            // Estructura legacy: rule.operator + rule.threshold (sin conditions[])
            // Mantener compatibilidad hacia atrás
            if ( rule.operator && rule.threshold !== undefined ) {
                // Buscar campo por rule.fieldId si existe
                const fieldId = rule.fieldId;
                if ( ! fieldId ) {
                    return false;
                }

                const field = pageElement.querySelector(
                    `[data-field-name="${ fieldId }"]`
                );

                if ( ! field ) {
                    return false;
                }

                const fieldValue = this.getFieldValue( field );

                if ( typeof fieldValue === 'number' ) {
                    const threshold = parseFloat( rule.threshold );

                    if ( Number.isNaN( threshold ) ) {
                        return false;
                    }

                    switch ( rule.operator ) {
                        case '>=':
                            return fieldValue >= threshold;
                        case '<=':
                            return fieldValue <= threshold;
                        case '>':
                            return fieldValue > threshold;
                        case '<':
                            return fieldValue < threshold;
                        case '==':
                            return fieldValue === threshold;
                    }
                }
            }

            // Estructura legacy: rule.matchValue o rule.value (sin conditions[])
            if ( rule.matchValue !== undefined || rule.value !== undefined ) {
                const fieldId = rule.fieldId;
                if ( ! fieldId ) {
                    return false;
                }

                const field = pageElement.querySelector(
                    `[data-field-name="${ fieldId }"]`
                );

                if ( ! field ) {
                    return false;
                }

                const fieldValue = this.getFieldValue( field );

                if ( Array.isArray( fieldValue ) ) {
                    for ( const value of fieldValue ) {
                        if (
                            rule.matchValue === value ||
                            rule.value === value
                        ) {
                            return true;
                        }
                    }
                    return false;
                }

                return (
                    rule.matchValue === fieldValue || rule.value === fieldValue
                );
            }

            return false;
        }

        findMatchingRule( rules, fieldValue ) {
            if ( ! Array.isArray( rules ) ) {
                return null;
            }

            // DEPRECATED: Este método se mantiene solo para compatibilidad legacy
            // El nuevo sistema usa evaluateRule() que soporta AND/OR
            // Este método solo se usa si la regla NO tiene conditions[]

            for ( const rule of rules ) {
                if ( rule.operator && rule.threshold !== undefined ) {
                    if ( typeof fieldValue === 'number' ) {
                        const threshold = parseFloat( rule.threshold );

                        if ( Number.isNaN( threshold ) ) {
                            continue;
                        }

                        let matches = false;
                        switch ( rule.operator ) {
                            case '>=':
                                matches = fieldValue >= threshold;
                                break;
                            case '<=':
                                matches = fieldValue <= threshold;
                                break;
                            case '>':
                                matches = fieldValue > threshold;
                                break;
                            case '<':
                                matches = fieldValue < threshold;
                                break;
                            case '==':
                                matches = fieldValue === threshold;
                                break;
                        }

                        if ( matches ) {
                            return rule;
                        }
                    }
                } else if (
                    rule.matchValue !== undefined ||
                    rule.value !== undefined
                ) {
                    if ( Array.isArray( fieldValue ) ) {
                        for ( const value of fieldValue ) {
                            if (
                                rule.matchValue === value ||
                                rule.value === value
                            ) {
                                return rule;
                            }
                        }
                    } else if (
                        rule.matchValue === fieldValue ||
                        rule.value === fieldValue
                    ) {
                        return rule;
                    }
                }
            }

            return null;
        }

        getNextPage( currentPage ) {
            const currentPageElement = EIPSIForms.getPageElement(
                this.form,
                currentPage
            );

            if ( ! currentPageElement ) {
                return { action: 'nextPage', targetPage: currentPage + 1 };
            }

            const conditionalFields = currentPageElement.querySelectorAll(
                '[data-conditional-logic]'
            );

            for ( const field of conditionalFields ) {
                const jsonString = field.dataset.conditionalLogic;
                const parsedLogic = this.parseConditionalLogic( jsonString );
                const conditionalLogic =
                    this.normalizeConditionalLogic( parsedLogic );

                if ( ! conditionalLogic || ! conditionalLogic.enabled ) {
                    continue;
                }

                // Nuevo sistema: evaluar reglas con conditions[] usando evaluateRule()
                if ( ! Array.isArray( conditionalLogic.rules ) ) {
                    continue;
                }

                // Evaluar cada regla en orden
                for ( const rule of conditionalLogic.rules ) {
                    // Usar evaluateRule() que soporta AND/OR
                    const ruleMatches = this.evaluateRule(
                        rule,
                        currentPageElement
                    );

                    if ( ruleMatches ) {
                        if ( rule.action === 'submit' ) {
                            return { action: 'submit' };
                        }

                        if ( rule.action === 'goToPage' && rule.targetPage ) {
                            const targetPage = parseInt( rule.targetPage, 10 );

                            if ( ! Number.isNaN( targetPage ) ) {
                                const totalPages = EIPSIForms.getTotalPages(
                                    this.form
                                );
                                const boundedTarget = Math.min(
                                    Math.max( targetPage, 1 ),
                                    totalPages
                                );
                                const targetElement = EIPSIForms.getPageElement(
                                    this.form,
                                    boundedTarget
                                );

                                if (
                                    targetElement &&
                                    EIPSIForms.isThankYouPageElement(
                                        targetElement
                                    )
                                ) {
                                    return { action: 'submit' };
                                }

                                return {
                                    action: 'goToPage',
                                    targetPage: boundedTarget,
                                    fieldId:
                                        field.id || field.dataset.fieldName,
                                    rule,
                                };
                            }
                        }

                        if ( rule.action === 'nextPage' ) {
                            return {
                                action: 'nextPage',
                                targetPage: currentPage + 1,
                            };
                        }
                    }
                }

                // Si ninguna regla coincidió, usar defaultAction
                if ( conditionalLogic.defaultAction ) {
                    if ( conditionalLogic.defaultAction === 'submit' ) {
                        return { action: 'submit' };
                    }

                    if (
                        conditionalLogic.defaultAction === 'goToPage' &&
                        conditionalLogic.defaultTargetPage
                    ) {
                        const targetPage = parseInt(
                            conditionalLogic.defaultTargetPage,
                            10
                        );

                        if ( ! Number.isNaN( targetPage ) ) {
                            const totalPages = EIPSIForms.getTotalPages(
                                this.form
                            );
                            const boundedTarget = Math.min(
                                Math.max( targetPage, 1 ),
                                totalPages
                            );
                            const targetElement = EIPSIForms.getPageElement(
                                this.form,
                                boundedTarget
                            );

                            if (
                                targetElement &&
                                EIPSIForms.isThankYouPageElement(
                                    targetElement
                                )
                            ) {
                                return { action: 'submit' };
                            }

                            return {
                                action: 'goToPage',
                                targetPage: boundedTarget,
                                isDefault: true,
                            };
                        }
                    }
                }
            }

            return { action: 'nextPage', targetPage: currentPage + 1 };
        }

        shouldSubmit( currentPage ) {
            const result = this.getNextPage( currentPage );
            return result.action === 'submit';
        }

        pushHistory( pageNumber ) {
            if (
                this.history.length === 0 ||
                this.history[ this.history.length - 1 ] !== pageNumber
            ) {
                this.history.push( pageNumber );
                this.visitedPages.add( pageNumber );
            }
        }

        popHistory() {
            if ( this.history.length > 1 ) {
                this.history.pop();
                return this.history[ this.history.length - 1 ];
            }
            return null;
        }

        markSkippedPages( fromPage, toPage ) {
            if ( fromPage === toPage || Math.abs( toPage - fromPage ) === 1 ) {
                return;
            }

            const start = Math.min( fromPage, toPage );
            const end = Math.max( fromPage, toPage );

            for ( let i = start + 1; i < end; i++ ) {
                if ( ! this.visitedPages.has( i ) ) {
                    this.skippedPages.add( i );
                }
            }
        }

        getActivePath() {
            return Array.from( this.visitedPages ).sort( ( a, b ) => a - b );
        }

        isPageSkipped( pageNumber ) {
            return this.skippedPages.has( pageNumber );
        }

        reset() {
            this.history = [];
            this.visitedPages.clear();
            this.skippedPages.clear();
        }
    }

    /**
     * Detecta el tipo de dispositivo basado en user agent
     * @return {string} 'mobile', 'tablet', o 'desktop'
     */
    function detectDeviceType() {
        const ua = typeof navigator !== 'undefined' ? navigator.userAgent : '';

        if ( /(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i.test( ua ) ) {
            return 'tablet';
        }
        if (
            /Mobile|Android|iP(hone|od)|IEMobile|BlackBerry|Kindle|Silk-Accelerated|(hpw|web)OS|Opera M(obi|ini)/.test(
                ua
            )
        ) {
            return 'mobile';
        }
        return 'desktop';
    }

    /**
     * Inicializa el metadata del formulario incluyendo page_transitions
     * @param {string} formId - ID del formulario
     */
    function initFormMetadata( formId ) {
        window.eipsiMetadata = window.eipsiMetadata || {};

        window.eipsiMetadata.form_start_time = Date.now();
        window.eipsiMetadata.device_type = detectDeviceType();
        window.eipsiMetadata.page_transitions = [];
        window.eipsiMetadata.field_interactions = [];

        // Registrar entrada a la primera página
        addPageTransition( 1 );
    }

    /**
     * Sistema de captura de tiempo por página, campo e inactividad
     * Implementación completa para análisis clínico (fatiga, evasión, calidad de respuesta)
     */
    window.eipsiTimers = {
        pageStartTime: null,
        currentPageIndex: 0,
        pageTimers: {},
        fieldTimers: {},
        lastActivityTime: null,
        inactiveStartTime: null,
        totalInactiveTime: 0,
        activityListeners: null,
        settings: {
            capturePageTiming: true,
            captureFieldTiming: false,
            captureInactivityTime: false,
            inactivityThreshold: 30000, // 30 segundos
        },
    };

    /**
     * Inicializa el sistema de captura de tiempo
     * @param {Object} settings - Configuración del formulario
     */
    function initTimingSystem( settings ) {
        // Guardar configuración
        window.eipsiTimers.settings = {
            capturePageTiming: settings.capturePageTiming !== false,
            captureFieldTiming: settings.captureFieldTiming === true,
            captureInactivityTime: settings.captureInactivityTime === true,
            inactivityThreshold: 30000,
        };

        // Inicializar timer de primera página si está habilitado
        if ( window.eipsiTimers.settings.capturePageTiming ) {
            window.eipsiTimers.pageStartTime = Date.now();
            window.eipsiTimers.currentPageIndex = 0;
            window.eipsiTimers.lastActivityTime = Date.now();

            // Iniciar detección de inactividad si está habilitado
            if ( window.eipsiTimers.settings.captureInactivityTime ) {
                startActivityDetection();
            }

            // Si está habilitado, iniciar tracking de campos
            if ( window.eipsiTimers.settings.captureFieldTiming ) {
                initFieldTracking();
            }
        }

        if ( window.eipsiFormsConfig?.settings?.debug && window.console?.log ) {
            window.console.log(
                '⏱️ Timing system initialized:',
                window.eipsiTimers.settings
            );
        }
    }

    /**
     * Registra actividad del usuario (mousemove, keydown, click, touch)
     */
    function trackActivity() {
        window.eipsiTimers.lastActivityTime = Date.now();

        // Si estaba inactivo, marcar el fin del período de inactividad
        if ( window.eipsiTimers.inactiveStartTime !== null ) {
            const inactiveDuration =
                Date.now() - window.eipsiTimers.inactiveStartTime;
            window.eipsiTimers.totalInactiveTime += inactiveDuration;
            window.eipsiTimers.inactiveStartTime = null;

            if (
                window.eipsiFormsConfig?.settings?.debug &&
                window.console?.log
            ) {
                window.console.log(
                    '💤 Inactivity period ended:',
                    inactiveDuration + 'ms',
                    'Total inactive:',
                    window.eipsiTimers.totalInactiveTime + 'ms'
                );
            }
        }
    }

    /**
     * Inicia los listeners para detección de inactividad
     */
    function startActivityDetection() {
        if ( window.eipsiTimers.activityListeners ) {
            return; // Ya inicializado
        }

        const events = [ 'mousemove', 'keydown', 'click', 'touchstart' ];
        window.eipsiTimers.activityListeners = events;

        events.forEach( ( eventType ) => {
            document.addEventListener( eventType, trackActivity, {
                passive: true,
            } );
        } );

        // Chequear inactividad cada 5 segundos
        window.eipsiTimers.inactivityCheckInterval = setInterval( () => {
            const now = Date.now();
            const timeSinceActivity = now - window.eipsiTimers.lastActivityTime;

            if (
                timeSinceActivity >=
                window.eipsiTimers.settings.inactivityThreshold
            ) {
                if ( window.eipsiTimers.inactiveStartTime === null ) {
                    window.eipsiTimers.inactiveStartTime = now;

                    if (
                        window.eipsiFormsConfig?.settings?.debug &&
                        window.console?.log
                    ) {
                        window.console.log( '💤 User went inactive' );
                    }
                }
            }
        }, 5000 );
    }

    /**
     * Detiene los listeners de actividad
     */
    function stopActivityDetection() {
        if ( window.eipsiTimers.activityListeners ) {
            window.eipsiTimers.activityListeners.forEach( ( eventType ) => {
                document.removeEventListener( eventType, trackActivity );
            } );
            window.eipsiTimers.activityListeners = null;
        }

        if ( window.eipsiTimers.inactivityCheckInterval ) {
            clearInterval( window.eipsiTimers.inactivityCheckInterval );
            window.eipsiTimers.inactivityCheckInterval = null;
        }
    }

    /**
     * Inicia el tracking de campos individuales (focus/blur)
     */
    function initFieldTracking() {
        // Buscar todos los campos interactivos
        const fields = document.querySelectorAll(
            'input[type="text"], input[type="email"], input[type="number"], textarea, select, input[type="radio"], input[type="checkbox"], input[type="range"]'
        );

        fields.forEach( ( field ) => {
            // Solo trackear campos que están dentro de un formulario EIPSI
            if ( ! field.closest( '.eipsi-form, .eipsi-form' ) ) {
                return;
            }

            // Encontrar el fieldName (puede estar en data-field-name o en el name del input)
            const fieldWrapper = field.closest( '[data-field-name]' );
            if ( ! fieldWrapper ) {
                return;
            }

            const fieldName = fieldWrapper.dataset.fieldName;
            if ( ! fieldName ) {
                return;
            }

            // Inicializar datos del campo si no existen
            if ( ! window.eipsiTimers.fieldTimers[ fieldName ] ) {
                window.eipsiTimers.fieldTimers[ fieldName ] = {
                    time_focused: 0,
                    time_active: 0,
                    interaction_count: 0,
                    focus_count: 0,
                };
            }

            // Trackear focus
            field.addEventListener( 'focus', () => {
                if ( window.eipsiTimers.settings.captureFieldTiming ) {
                    window.eipsiTimers.fieldTimers[ fieldName ].lastFocusTime =
                        Date.now();
                    window.eipsiTimers.fieldTimers[ fieldName ].focus_count++;
                }
            } );

            // Trackear blur (campo perdió el foco)
            field.addEventListener( 'blur', () => {
                if (
                    window.eipsiTimers.settings.captureFieldTiming &&
                    window.eipsiTimers.fieldTimers[ fieldName ].lastFocusTime
                ) {
                    const focusDuration =
                        Date.now() -
                        window.eipsiTimers.fieldTimers[ fieldName ]
                            .lastFocusTime;
                    window.eipsiTimers.fieldTimers[ fieldName ].time_focused +=
                        focusDuration;
                    window.eipsiTimers.fieldTimers[ fieldName ].lastFocusTime =
                        null;
                }
            } );

            // Trackear interacciones (cambios de valor)
            field.addEventListener( 'change', () => {
                if ( window.eipsiTimers.settings.captureFieldTiming ) {
                    window.eipsiTimers.fieldTimers[ fieldName ]
                        .interaction_count++;
                    window.eipsiTimers.fieldTimers[ fieldName ].time_active =
                        window.eipsiTimers.fieldTimers[
                            fieldName
                        ].time_focused;
                }
            } );

            // Para sliders y radio buttons, también trackear clicks
            field.addEventListener( 'input', () => {
                if (
                    window.eipsiTimers.settings.captureFieldTiming &&
                    field.type === 'range'
                ) {
                    window.eipsiTimers.fieldTimers[ fieldName ]
                        .interaction_count++;
                }
            } );
        } );
    }

    /**
     * Inicia el timer de una página específica
     * @param {number} pageIndex - Índice de la página (0-based)
     */
    function startPageTimer( pageIndex ) {
        if ( ! window.eipsiTimers.settings.capturePageTiming ) {
            return;
        }

        // Finalizar la página anterior si existe
        endPageTimer();

        window.eipsiTimers.pageStartTime = Date.now();
        window.eipsiTimers.currentPageIndex = pageIndex;

        if ( window.eipsiFormsConfig?.settings?.debug && window.console?.log ) {
            window.console.log( '⏱️ Page timer started:', pageIndex );
        }
    }

    /**
     * Finaliza el timer de la página actual y guarda el resultado
     */
    function endPageTimer() {
        if (
            ! window.eipsiTimers.settings.capturePageTiming ||
            window.eipsiTimers.pageStartTime === null
        ) {
            return;
        }

        const duration =
            ( Date.now() - window.eipsiTimers.pageStartTime ) / 1000; // Convertir a segundos

        // Guardar timer
        const now = new Date();
        const formattedTime =
            now.getHours().toString().padStart( 2, '0' ) +
            ':' +
            now.getMinutes().toString().padStart( 2, '0' ) +
            ':' +
            now.getSeconds().toString().padStart( 2, '0' );

        window.eipsiTimers.pageTimers[ window.eipsiTimers.currentPageIndex ] = {
            duration: duration.toFixed( 1 ) + ' s',
            timestamp: formattedTime,
            rawDuration: parseFloat( duration.toFixed( 1 ) ),
        };

        if ( window.eipsiFormsConfig?.settings?.debug && window.console?.log ) {
            window.console.log( '⏱️ Page timer ended:', {
                page: window.eipsiTimers.currentPageIndex,
                duration: duration + 's',
            } );
        }

        window.eipsiTimers.pageStartTime = null;
    }

    /**
     * Calcula el total_duration sumando todas las páginas
     * @return {number} Duración total en segundos
     */
    function calculateTotalDuration() {
        let total = 0;
        for ( const pageIndex in window.eipsiTimers.pageTimers ) {
            const timer = window.eipsiTimers.pageTimers[ pageIndex ];
            total += timer.rawDuration || parseFloat( timer.duration ) || 0;
        }
        return parseFloat( total.toFixed( 1 ) );
    }

    /**
     * Obtiene el metadata de timing para enviar al servidor
     * @return {Object} Objeto con page_timings, field_timings y activity_metrics
     */
    function getTimingMetadata() {
        const metadata = {};

        // Page timings
        if ( window.eipsiTimers.settings.capturePageTiming ) {
            const pageTimings = {};
            for ( const pageIndex in window.eipsiTimers.pageTimers ) {
                pageTimings[ 'page_' + pageIndex ] =
                    window.eipsiTimers.pageTimers[ pageIndex ];
            }

            pageTimings.total_duration = calculateTotalDuration();
            metadata.page_timings = pageTimings;
        }

        // Field timings
        if (
            window.eipsiTimers.settings.captureFieldTiming &&
            Object.keys( window.eipsiTimers.fieldTimers ).length > 0
        ) {
            metadata.field_timings = {};
            for ( const fieldName in window.eipsiTimers.fieldTimers ) {
                metadata.field_timings[ fieldName ] = {
                    time_focused: parseFloat(
                        (
                            window.eipsiTimers.fieldTimers[ fieldName ]
                                .time_focused / 1000
                        ).toFixed( 1 )
                    ),
                    interaction_count:
                        window.eipsiTimers.fieldTimers[ fieldName ]
                            .interaction_count,
                    focus_count:
                        window.eipsiTimers.fieldTimers[ fieldName ].focus_count,
                };
            }
        }

        // Activity metrics (inactividad)
        if ( window.eipsiTimers.settings.captureInactivityTime ) {
            // Calcular tiempo activo = total - inactivo
            const totalDurationMs = calculateTotalDuration() * 1000;
            const activeTime = Math.max(
                0,
                totalDurationMs - window.eipsiTimers.totalInactiveTime
            );
            const activityRatio =
                totalDurationMs > 0 ? activeTime / totalDurationMs : 0;

            metadata.activity_metrics = {
                active_time: parseFloat( ( activeTime / 1000 ).toFixed( 1 ) ),
                inactive_time: parseFloat(
                    ( window.eipsiTimers.totalInactiveTime / 1000 ).toFixed( 1 )
                ),
                activity_ratio: parseFloat( activityRatio.toFixed( 3 ) ),
            };
        }

        return metadata;
    }

    /**
     * Registra entrada/salida de páginas en page_transitions
     * @param {number} pageNumber - Número de página (1, 2, 3, ...)
     */
    function addPageTransition( pageNumber ) {
        if (
            ! window.eipsiMetadata ||
            ! Array.isArray( window.eipsiMetadata.page_transitions )
        ) {
            return;
        }

        // Completar la página anterior (si existe)
        const transitions = window.eipsiMetadata.page_transitions;
        if ( transitions.length > 0 ) {
            const lastPage = transitions[ transitions.length - 1 ];
            if ( ! lastPage.page_end_time ) {
                lastPage.page_end_time = Date.now();
                lastPage.page_duration =
                    lastPage.page_end_time - lastPage.page_start_time;
            }
        }

        // Registrar nueva página
        transitions.push( {
            page: pageNumber,
            page_start_time: Date.now(),
            page_end_time: null,
            page_duration: null,
        } );

        // Debug logging si está habilitado
        if ( window.eipsiFormsConfig?.settings?.debug && window.console?.log ) {
            window.console.log( '📊 Page transition added:', {
                page: pageNumber,
                total_transitions: transitions.length,
                current_transition: transitions[ transitions.length - 1 ],
            } );
        }
    }

    const EIPSIForms = {
        forms: [],
        navigators: new Map(),
        config: window.eipsiFormsConfig || {},

        init() {
            document.addEventListener( 'DOMContentLoaded', () => {
                this.initForms();
            } );
        },

        initForms() {
            const forms = document.querySelectorAll(
                '.eipsi-form form, .eipsi-form form'
            );

            forms.forEach( ( form ) => {
                // Guard clínico: si el estudio está cerrado, no inicializamos ni dejamos enviar
                if ( this.applyStudyStatusGuard( form ) ) {
                    return;
                }

                this.initForm( form );
            } );
        },

        initForm( form ) {
            if ( form.dataset.initialized ) {
                return;
            }

            form.dataset.initialized = 'true';
            this.forms.push( form );

            const formId = this.getFormId( form );

            // Inicializar metadata del formulario incluyendo page_transitions
            initFormMetadata( formId );

            // Inicializar sistema de captura de tiempo
            const container = form.closest( '.eipsi-form, .eipsi-form' );
            if ( container ) {
                const timingSettings = {
                    capturePageTiming:
                        container.dataset.capturePageTiming !== 'false',
                    captureFieldTiming:
                        container.dataset.captureFieldTiming === 'true',
                    captureInactivityTime:
                        container.dataset.captureInactivityTime === 'true',
                };
                initTimingSystem( timingSettings );
            }

            const navigator = new ConditionalNavigator( form );
            this.navigators.set( formId || form, navigator );

            const initialPage = this.getCurrentPage( form );
            navigator.pushHistory( initialPage );

            this.applyTestSelectors( form );

            // Apply dark mode based on system preference
            this.applyDarkMode( form );

            this.populateDeviceInfo( form );
            this.initPagination( form );
            this.initVasSliders( form );
            this.initLikertFields( form );
            this.initRadioFields( form );
            this.initConditionalFieldListeners( form );
            this.initConsentBlocks( form );
            this.attachTracking( form );

            form.addEventListener( 'submit', ( e ) =>
                this.handleSubmit( e, form )
            );

            if ( this.config.settings?.validateOnBlur ) {
                this.setupFieldValidation( form );
            }
        },

        getFormId( form ) {
            if ( ! form ) {
                return '';
            }

            if ( form.dataset.formId ) {
                return form.dataset.formId;
            }

            const hiddenField = form.querySelector( 'input[name="form_id"]' );
            return hiddenField ? hiddenField.value : '';
        },

        getStudyStatus( form ) {
            if ( ! form ) {
                return 'open';
            }

            const container = form.closest( '.eipsi-form, .eipsi-form' );
            const rawStatus =
                ( container && container.dataset.studyStatus ) ||
                form.dataset.studyStatus ||
                '';

            return rawStatus === 'closed' ? 'closed' : 'open';
        },

        applyStudyStatusGuard( form ) {
            if ( this.getStudyStatus( form ) !== 'closed' ) {
                return false;
            }

            const container = form.closest( '.eipsi-form, .eipsi-form' );
            const strings = this.config.strings || {};
            const message =
                strings.studyClosedMessage ||
                'Este estudio está cerrado y no acepta más respuestas. Contacta al investigador si tienes dudas.';

            if ( container ) {
                container.classList.add( 'eipsi-study-is-closed' );
                container.dataset.studyStatus = 'closed';

                // Ensure notice exists (for backwards compatibility)
                let notice = container.querySelector(
                    '.eipsi-study-closed-notice'
                );

                if ( ! notice ) {
                    notice = document.createElement( 'div' );
                    notice.className = 'eipsi-study-closed-notice';
                    notice.setAttribute( 'role', 'alert' );
                    notice.textContent = message;

                    container.insertBefore( notice, container.firstChild );
                }
            }

            // Hide and disable the form
            form.style.display = 'none';
            form.setAttribute( 'aria-hidden', 'true' );
            form.querySelectorAll( 'input, select, textarea, button' ).forEach(
                ( el ) => {
                    el.disabled = true;
                }
            );

            return true;
        },

        applyDarkMode( form ) {
            const container = form.closest( '.eipsi-form, .vas-dinamico-form' );
            if ( ! container ) {
                return;
            }

            const prefersDark =
                window.matchMedia &&
                window.matchMedia( '(prefers-color-scheme: dark)' ).matches;

            if ( prefersDark ) {
                container.setAttribute( 'data-theme', 'dark' );
            } else {
                container.removeAttribute( 'data-theme' );
            }

            // Listen for changes in system theme preference
            window
                .matchMedia( '(prefers-color-scheme: dark)' )
                .addEventListener( 'change', ( e ) => {
                    if ( e.matches ) {
                        container.setAttribute( 'data-theme', 'dark' );
                    } else {
                        container.removeAttribute( 'data-theme' );
                    }
                } );
        },

        getTrackingFormId( form ) {
            if ( ! form ) {
                return '';
            }

            return form.dataset.trackingFormId || this.getFormId( form );
        },

        getNavigator( form ) {
            if ( ! form ) {
                return null;
            }

            const formId = this.getFormId( form );
            return this.navigators.get( formId || form );
        },

        initConditionalFieldListeners( form ) {
            const conditionalFields = form.querySelectorAll(
                '[data-conditional-logic]'
            );

            conditionalFields.forEach( ( field ) => {
                const inputs = field.querySelectorAll(
                    'input[type="radio"], input[type="checkbox"], input[type="range"], select'
                );

                inputs.forEach( ( input ) => {
                    input.addEventListener( 'change', () => {
                        const navigator = this.getNavigator( form );
                        if ( navigator ) {
                            const currentPage = this.getCurrentPage( form );
                            const nextPageResult =
                                navigator.getNextPage( currentPage );

                            const totalPages = this.getTotalPages( form );
                            this.updatePaginationDisplay(
                                form,
                                currentPage,
                                totalPages
                            );

                            if ( window.EIPSITracking ) {
                                const trackingFormId =
                                    this.getTrackingFormId( form );
                                if (
                                    trackingFormId &&
                                    nextPageResult.action === 'goToPage' &&
                                    nextPageResult.targetPage !==
                                        currentPage + 1
                                ) {
                                    this.recordBranchingPreview(
                                        trackingFormId,
                                        currentPage,
                                        nextPageResult
                                    );
                                }
                            }
                        }
                    } );
                } );
            } );
        },

        recordBranchingPreview( formId, currentPage, result ) {
            if ( ! window.EIPSITracking?.trackEvent ) {
                return;
            }

            const nextPage =
                result.action === 'goToPage'
                    ? result.targetPage
                    : currentPage + 1;

            // Solo trackeamos si NO es el flujo lineal normal
            if ( nextPage !== currentPage + 1 ) {
                window.EIPSITracking.trackEvent( 'branch_jump', formId, {
                    from_page: currentPage,
                    to_page: nextPage,
                    field_id:
                        result.triggeringField?.dataset?.fieldName || null,
                    matched_value: result.fieldValue ?? null,
                    timestamp: Date.now(),
                } );
            }

            // Mantener logs de debug si ya existen
            if ( this.config.settings?.debug && window.console?.log ) {
                window.console.log( '[EIPSI Forms] Branching route updated:', {
                    formId,
                    currentPage,
                    nextPage,
                    result,
                } );
            }
        },

        applyTestSelectors( form ) {
            if ( ! form ) {
                return;
            }

            const formId = this.getFormId( form ) || 'default';

            if ( ! form.dataset.testid ) {
                form.setAttribute( 'data-testid', `eipsi-form-${ formId }` );
            }

            const navigation = form.querySelector( '.form-navigation' );
            if ( navigation && ! navigation.dataset.testid ) {
                navigation.setAttribute(
                    'data-testid',
                    `form-navigation-${ formId }`
                );
            }

            const progress = form.querySelector( '.form-progress' );
            if ( progress && ! progress.dataset.testid ) {
                progress.setAttribute(
                    'data-testid',
                    `form-progress-${ formId }`
                );
            }

            const prevButton = form.querySelector( '.eipsi-prev-button' );
            if ( prevButton && ! prevButton.dataset.testid ) {
                prevButton.setAttribute( 'data-testid', 'prev-button' );
            }

            const nextButton = form.querySelector( '.eipsi-next-button' );
            if ( nextButton && ! nextButton.dataset.testid ) {
                nextButton.setAttribute( 'data-testid', 'next-button' );
            }

            const submitButton = form.querySelector( '.eipsi-submit-button' );
            if ( submitButton && ! submitButton.dataset.testid ) {
                submitButton.setAttribute( 'data-testid', 'submit-button' );
            }

            const pages = form.querySelectorAll( '.eipsi-page' );
            pages.forEach( ( page, index ) => {
                if ( ! page.dataset.testid ) {
                    page.setAttribute(
                        'data-testid',
                        `form-page-${ index + 1 }`
                    );
                }
            } );

            const groups = form.querySelectorAll( '.form-group' );
            groups.forEach( ( group ) => {
                const fieldName = group.dataset.fieldName || group.id || '';
                if ( fieldName && ! group.dataset.testid ) {
                    const sanitized = fieldName.replace(
                        /[^a-zA-Z0-9_-]/g,
                        '-'
                    );
                    group.setAttribute( 'data-testid', `field-${ sanitized }` );
                }

                const inputs = group.querySelectorAll(
                    'input, textarea, select'
                );
                inputs.forEach( ( input ) => {
                    if ( ! input.dataset.testid ) {
                        let key =
                            input.name || input.id || fieldName || 'input';
                        key = key.replace( /[^a-zA-Z0-9_-]/g, '-' );
                        input.setAttribute( 'data-testid', `input-${ key }` );
                    }
                } );
            } );
        },

        attachTracking( form ) {
            if ( ! window.EIPSITracking || ! form ) {
                return;
            }

            const formId = this.getFormId( form ) || 'default';

            window.EIPSITracking.registerForm( form, formId );
            form.dataset.trackingFormId = formId;

            const totalPages =
                parseInt( form.dataset.totalPages || '1', 10 ) || 1;
            const currentPageField = form.querySelector(
                '.eipsi-current-page'
            );
            const currentPage =
                parseInt(
                    currentPageField?.value || form.dataset.currentPage || '1',
                    10
                ) || 1;

            window.EIPSITracking.setTotalPages( formId, totalPages );
            window.EIPSITracking.setCurrentPage( formId, currentPage, {
                trackChange: false,
            } );
        },

        populateDeviceInfo( form ) {
            const deviceField = form.querySelector(
                '.eipsi-device-placeholder'
            );
            const browserField = form.querySelector(
                '.eipsi-browser-placeholder'
            );
            const osField = form.querySelector( '.eipsi-os-placeholder' );
            const screenField = form.querySelector(
                '.eipsi-screen-placeholder'
            );
            const startTimeField = form.querySelector( '.eipsi-start-time' );

            // Device type (mobile/tablet/desktop) - siempre se captura si el toggle device_type está ON
            if ( deviceField ) {
                deviceField.value = this.getDeviceType();
            }

            // Browser (nombre + versión) - solo si toggle browser está ON
            if ( browserField ) {
                browserField.value = this.getBrowser();
            }

            // OS (nombre + versión) - solo si toggle os está ON
            if ( osField ) {
                osField.value = this.getOS();
            }

            // Screen size (ancho x alto) - solo si toggle screen_width está ON
            if ( screenField && window.screen ) {
                const width = window.screen.width || '';
                const height = window.screen.height || '';
                screenField.value =
                    width && height ? `${ width }x${ height }` : width;
            }

            // Start timestamp - siempre se captura
            if ( startTimeField ) {
                startTimeField.value = Date.now();
            }
        },

        getDeviceType() {
            const ua =
                typeof navigator !== 'undefined' ? navigator.userAgent : '';
            if (
                /(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i.test( ua )
            ) {
                return 'tablet';
            }
            if (
                /Mobile|Android|iP(hone|od)|IEMobile|BlackBerry|Kindle|Silk-Accelerated|(hpw|web)OS|Opera M(obi|ini)/.test(
                    ua
                )
            ) {
                return 'mobile';
            }
            return 'desktop';
        },

        getBrowser() {
            const ua =
                typeof navigator !== 'undefined' ? navigator.userAgent : '';
            let browser = 'Unknown';
            let version = '';

            // Detectar navegador y extraer versión mayor
            if ( ua.indexOf( 'Firefox' ) > -1 ) {
                browser = 'Firefox';
                const match = ua.match( /Firefox\/(\d+)/ );
                version = match ? match[ 1 ] : '';
            } else if ( ua.indexOf( 'SamsungBrowser' ) > -1 ) {
                browser = 'Samsung Browser';
                const match = ua.match( /SamsungBrowser\/(\d+)/ );
                version = match ? match[ 1 ] : '';
            } else if ( ua.indexOf( 'OPR' ) > -1 ) {
                browser = 'Opera';
                const match = ua.match( /OPR\/(\d+)/ );
                version = match ? match[ 1 ] : '';
            } else if ( ua.indexOf( 'Opera' ) > -1 ) {
                browser = 'Opera';
                const match = ua.match( /Opera\/(\d+)/ );
                version = match ? match[ 1 ] : '';
            } else if ( ua.indexOf( 'Edg/' ) > -1 ) {
                browser = 'Edge';
                const match = ua.match( /Edg\/(\d+)/ );
                version = match ? match[ 1 ] : '';
            } else if ( ua.indexOf( 'Edge' ) > -1 ) {
                browser = 'Edge';
                const match = ua.match( /Edge\/(\d+)/ );
                version = match ? match[ 1 ] : '';
            } else if ( ua.indexOf( 'Trident' ) > -1 ) {
                browser = 'Internet Explorer';
                const match = ua.match( /rv:(\d+)/ );
                version = match ? match[ 1 ] : '';
            } else if ( ua.indexOf( 'Chrome' ) > -1 ) {
                browser = 'Chrome';
                const match = ua.match( /Chrome\/(\d+)/ );
                version = match ? match[ 1 ] : '';
            } else if ( ua.indexOf( 'Safari' ) > -1 ) {
                browser = 'Safari';
                const match = ua.match( /Version\/(\d+)/ );
                version = match ? match[ 1 ] : '';
            }

            return version ? `${ browser } ${ version }` : browser;
        },

        getOS() {
            const ua =
                typeof navigator !== 'undefined' ? navigator.userAgent : '';
            let os = 'Unknown';
            let version = '';

            if ( ua.indexOf( 'Win' ) > -1 ) {
                os = 'Windows';
                const match = ua.match( /Windows NT (\d+\.\d+)/ );
                version = match ? match[ 1 ] : '';
            } else if ( ua.indexOf( 'Mac OS X' ) > -1 ) {
                os = 'macOS';
                const match = ua.match( /Mac OS X (\d+[_.]\d+)/ );
                version = match ? match[ 1 ].replace( '_', '.' ) : '';
            } else if ( ua.indexOf( 'X11' ) > -1 ) {
                os = 'UNIX';
            } else if ( ua.indexOf( 'Linux' ) > -1 ) {
                os = 'Linux';
            } else if ( /Android/.test( ua ) ) {
                os = 'Android';
                const match = ua.match( /Android (\d+(?:\.\d+)?)/ );
                version = match ? match[ 1 ] : '';
            } else if ( /iPhone|iPad|iPod/.test( ua ) ) {
                os = 'iOS';
                const match = ua.match( /OS (\d+_\d+)/ );
                version = match ? match[ 1 ].replace( '_', '.' ) : '';
            }

            return version ? `${ os } ${ version }` : os;
        },

        initConsentBlocks( form ) {
            const consentBlocks = form.querySelectorAll(
                '[data-consent-block="true"]'
            );
            if ( ! consentBlocks.length ) {
                return;
            }

            consentBlocks.forEach( ( block ) => {
                const checkbox = block.querySelector(
                    'input[type="checkbox"]'
                );
                if ( ! checkbox ) {
                    return;
                }

                const isRequired = block.dataset.required === 'true';
                if ( ! isRequired ) {
                    return;
                }

                // Initial state check
                this.updateNavigationForConsent( form );

                checkbox.addEventListener( 'change', () => {
                    this.updateNavigationForConsent( form );
                    this.validateField( checkbox );
                } );
            } );
        },

        updateNavigationForConsent( form ) {
            const currentPage = this.getCurrentPage( form );
            const pageElement = this.getPageElement( form, currentPage );
            if ( ! pageElement ) {
                return;
            }

            const consentBlocks = pageElement.querySelectorAll(
                '[data-consent-block="true"][data-required="true"]'
            );
            let allAccepted = true;

            consentBlocks.forEach( ( block ) => {
                const checkbox = block.querySelector(
                    'input[type="checkbox"]'
                );
                if ( checkbox && ! checkbox.checked ) {
                    allAccepted = false;
                }
            } );

            const nextButton = form.querySelector( '.eipsi-next-button' );
            const submitButton = form.querySelector( '.eipsi-submit-button' );

            if ( nextButton ) {
                if ( ! allAccepted ) {
                    nextButton.setAttribute( 'disabled', 'true' );
                    nextButton.classList.add( 'is-disabled' );
                } else {
                    nextButton.removeAttribute( 'disabled' );
                    nextButton.classList.remove( 'is-disabled' );
                }
            }

            if ( submitButton ) {
                if ( ! allAccepted ) {
                    submitButton.setAttribute( 'disabled', 'true' );
                    submitButton.classList.add( 'is-disabled' );
                } else {
                    submitButton.removeAttribute( 'disabled' );
                    submitButton.classList.remove( 'is-disabled' );
                }
            }
        },

        initPagination( form ) {
            const allPages = Array.from(
                form.querySelectorAll( '.eipsi-page' )
            );
            const regularPages = [];
            let thankYouPage = null;

            if ( allPages.length > 0 ) {
                allPages.forEach( ( page ) => {
                    const type =
                        page.dataset.pageType ||
                        ( page.dataset.page === 'thank-you'
                            ? 'thank_you'
                            : 'standard' );

                    if ( type === 'thank_you' ) {
                        thankYouPage = page;
                        page.dataset.page = 'thank-you';
                        page.dataset.pageType = 'thank_you';
                        page.style.display = 'none';
                        page.setAttribute( 'aria-hidden', 'true' );
                        page.classList.add( 'eipsi-thank-you-page-block' );
                        return;
                    }

                    regularPages.push( page );
                    const assignedIndex = regularPages.length;
                    page.dataset.page = String( assignedIndex );

                    if ( assignedIndex === 1 ) {
                        page.style.display = '';
                        page.removeAttribute( 'aria-hidden' );
                    } else {
                        page.style.display = 'none';
                        page.setAttribute( 'aria-hidden', 'true' );
                    }
                } );

                const totalPages = regularPages.length || 1;
                form.dataset.totalPages = totalPages;
                form.dataset.hasThankYouPage = thankYouPage ? 'true' : 'false';

                const rawShowProgressPref = form.dataset.showProgressBar;
                const showProgressBar =
                    rawShowProgressPref === undefined ||
                    rawShowProgressPref === '' ||
                    rawShowProgressPref === 'true' ||
                    rawShowProgressPref === '1';

                const totalPagesField =
                    form.querySelector( '.form-progress .total-pages' ) ||
                    form.querySelector( '.total-pages' );
                if ( totalPagesField ) {
                    totalPagesField.textContent = totalPages;
                }

                const progressContainer =
                    form.querySelector( '.form-progress' );
                if ( progressContainer ) {
                    if ( ! showProgressBar ) {
                        progressContainer.style.display = 'none';
                    } else {
                        progressContainer.style.display =
                            totalPages > 1 ? '' : 'none';
                    }
                }

                const normalizedPage = this.getCurrentPage( form );
                this.setCurrentPage( form, normalizedPage, {
                    trackChange: false,
                } );
            }

            const prevButton = form.querySelector( '.eipsi-prev-button' );
            const nextButton = form.querySelector( '.eipsi-next-button' );
            const submitButton = form.querySelector( '.eipsi-submit-button' );

            if ( prevButton ) {
                prevButton.removeAttribute( 'disabled' );
                prevButton.addEventListener( 'click', ( e ) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (
                        form.dataset.submitting === 'true' ||
                        prevButton.disabled
                    ) {
                        return;
                    }
                    this.handlePagination( form, 'prev' );
                } );
            }

            if ( nextButton ) {
                nextButton.removeAttribute( 'disabled' );
                nextButton.addEventListener( 'click', ( e ) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (
                        form.dataset.submitting === 'true' ||
                        nextButton.disabled
                    ) {
                        return;
                    }
                    this.handlePagination( form, 'next' );
                } );
            }

            if ( submitButton ) {
                submitButton.removeAttribute( 'disabled' );
            }

            const currentPage = this.getCurrentPage( form );
            const totalPages = this.getTotalPages( form );
            this.updatePaginationDisplay( form, currentPage, totalPages );
        },

        initVasSliders( form ) {
            const sliders = form.querySelectorAll( '.vas-slider' );

            sliders.forEach( ( slider ) => {
                if ( ! slider.hasAttribute( 'data-touched' ) ) {
                    slider.setAttribute( 'data-touched', 'false' );
                }

                const showValue = slider.dataset.showValue === 'true';
                let updateTimer = null;
                let rafId = null;

                const markAsTouched = () => {
                    if ( slider.dataset.touched === 'false' ) {
                        slider.dataset.touched = 'true';
                        this.validateField( slider );
                    }
                };

                // ONLY updates display value, NOT --vas-label-alignment (stays fixed per designer config)
                const throttledUpdate = ( value ) => {
                    if ( rafId ) {
                        return;
                    }

                    rafId = window.requestAnimationFrame( () => {
                        const valueDisplayId = slider.getAttribute( 'aria-labelledby' );
                            let valueDisplay = valueDisplayId ? document.getElementById( valueDisplayId ) : null;

                            if ( ! valueDisplay ) {
                                const container = slider.closest( '.vas-slider-container' );
                                if ( container ) {
                                    valueDisplay = container.querySelector( '.vas-current-value-solo' );
                                }
                            }

                            if ( valueDisplay ) {
                            valueDisplay.textContent = value;
                        }

                        slider.setAttribute( 'aria-valuenow', value );
                        rafId = null;
                    } );
                };

                slider.addEventListener( 'pointerdown', markAsTouched, {
                    once: true,
                } );

                slider.addEventListener( 'input', markAsTouched );

                slider.addEventListener( 'keydown', ( e ) => {
                    if (
                        e.key === 'ArrowLeft' ||
                        e.key === 'ArrowRight' ||
                        e.key === 'ArrowUp' ||
                        e.key === 'ArrowDown' ||
                        e.key === 'Home' ||
                        e.key === 'End'
                    ) {
                        markAsTouched();
                    }
                } );

                if ( showValue ) {
                    slider.addEventListener( 'input', ( e ) => {
                        const value = e.target.value;

                        if ( updateTimer ) {
                            clearTimeout( updateTimer );
                        }

                        updateTimer = setTimeout( () => {
                            throttledUpdate( value );
                        }, 80 );
                    } );
                } else {
                    slider.addEventListener( 'input', ( e ) => {
                        if ( updateTimer ) {
                            clearTimeout( updateTimer );
                        }

                        updateTimer = setTimeout( () => {
                            slider.setAttribute(
                                'aria-valuenow',
                                e.target.value
                            );
                        }, 80 );
                    } );
                }
            } );

            // ✅ MOBILE FIX: Create mobile labels below slider for small screens
            this.createMobileVasLabels( form );
        },

        /**
         * Create mobile-friendly labels below VAS sliders on small screens
         * Only creates if not already present and on mobile viewport
         */
        createMobileVasLabels( form ) {
            // Only proceed if we're on mobile (< 480px)
            if ( window.innerWidth > 480 ) {
                return;
            }

            const sliderContainers = form.querySelectorAll( '.vas-slider-container' );

            sliderContainers.forEach( ( container ) => {
                // Skip if already has mobile labels
                if ( container.querySelector( '.vas-labels-mobile' ) ) {
                    return;
                }

                const labelsEl = container.querySelector( '.vas-multi-labels' );
                if ( ! labelsEl ) {
                    return;
                }

                // Extract labels text from existing floating labels
                const labels = Array.from(
                    labelsEl.querySelectorAll( '.vas-multi-label' )
                ).map( ( el ) => el.textContent.trim() );

                if ( labels.length === 0 ) {
                    return;
                }

                // Create mobile labels container
                const mobileLabels = document.createElement( 'div' );
                mobileLabels.className = 'vas-labels-mobile';

                labels.forEach( ( text ) => {
                    const span = document.createElement( 'span' );
                    span.textContent = text;
                    mobileLabels.appendChild( span );
                } );

                container.appendChild( mobileLabels );
            } );
        },

        initLikertFields( form ) {
            const likertFields = form.querySelectorAll( '.eipsi-likert-field' );

            likertFields.forEach( ( field ) => {
                const radioInputs = field.querySelectorAll(
                    'input[type="radio"]'
                );

                radioInputs.forEach( ( radio ) => {
                    radio.addEventListener( 'change', () => {
                        this.validateField( radio );
                    } );
                } );

                const likertItems = field.querySelectorAll( '.likert-item' );
                likertItems.forEach( ( item ) => {
                    item.addEventListener( 'click', ( e ) => {
                        if (
                            e.target.tagName === 'INPUT' ||
                            e.target.tagName === 'LABEL'
                        ) {
                            return;
                        }

                        const radio = item.querySelector(
                            'input[type="radio"]'
                        );
                        if ( radio && ! radio.disabled ) {
                            radio.checked = true;
                            radio.dispatchEvent(
                                new Event( 'change', { bubbles: true } )
                            );
                        }
                    } );

                    item.style.cursor = 'pointer';
                } );
            } );
        },

        initRadioFields( form ) {
            const radioFields = form.querySelectorAll( '.eipsi-radio-field' );
            const formIdBaseRaw = this.getFormId( form ) || 'eipsi-form';
            const formIdBase =
                formIdBaseRaw
                    .toString()
                    .replace( /[^a-zA-Z0-9_-]/g, '' )
                    .toLowerCase()
                    .trim() || 'eipsi-form';

            radioFields.forEach( ( field, index ) => {
                const radioInputs = field.querySelectorAll(
                    'input[type="radio"]'
                );

                if ( radioInputs.length === 0 ) {
                    return;
                }

                let groupName =
                    ( field.dataset.fieldName &&
                    field.dataset.fieldName !== 'undefined'
                        ? field.dataset.fieldName
                        : '' ) || radioInputs[ 0 ].name;

                if ( ! groupName ) {
                    groupName = `${ formIdBase }_radio_${ index + 1 }`;
                    field.dataset.fieldName = groupName;
                }

                radioInputs.forEach( ( radio ) => {
                    if ( ! radio.name ) {
                        radio.name = groupName;
                    }
                } );

                radioInputs.forEach( ( radio ) => {
                    radio.addEventListener( 'change', () => {
                        radioInputs.forEach( ( other ) => {
                            if ( other !== radio ) {
                                other.checked = false;
                            }
                        } );
                        this.validateField( radio );
                    } );
                } );
            } );
        },

        getTotalPages( form ) {
            if ( ! form ) {
                return 1;
            }

            const datasetValue = parseInt( form.dataset.totalPages || '', 10 );

            if ( ! Number.isNaN( datasetValue ) && datasetValue > 0 ) {
                return datasetValue;
            }

            const pages = form.querySelectorAll( '.eipsi-page' );
            // Filter out thank-you pages from total count
            const regularPages = Array.from( pages ).filter(
                ( page ) =>
                    page.dataset.pageType !== 'thank_you' &&
                    page.dataset.page !== 'thank-you' &&
                    ! page.classList.contains( 'eipsi-thank-you-page-block' )
            );
            const totalPages = regularPages.length || 1;

            return totalPages;
        },

        getCurrentPage( form ) {
            if ( ! form ) {
                return 1;
            }

            const currentPageField = form.querySelector(
                '.eipsi-current-page'
            );
            const fieldValue = currentPageField
                ? parseInt( currentPageField.value || '', 10 )
                : NaN;
            const datasetValue = parseInt( form.dataset.currentPage || '', 10 );
            const totalPages = this.getTotalPages( form );

            let currentPage = ! Number.isNaN( fieldValue )
                ? fieldValue
                : datasetValue;

            if ( Number.isNaN( currentPage ) ) {
                currentPage = 1;
            }

            if ( currentPage < 1 ) {
                currentPage = 1;
            } else if ( currentPage > totalPages ) {
                currentPage = totalPages;
            }

            if (
                currentPageField &&
                currentPageField.value !== `${ currentPage }`
            ) {
                currentPageField.value = currentPage;
            }

            form.dataset.currentPage = currentPage;

            return currentPage;
        },

        setCurrentPage( form, pageNumber, options = {} ) {
            if ( ! form ) {
                return;
            }

            const { trackChange = true } = options;
            const totalPages = this.getTotalPages( form );
            const previousPage = this.getCurrentPage( form );
            let sanitizedPage = parseInt( pageNumber, 10 );

            if ( Number.isNaN( sanitizedPage ) ) {
                sanitizedPage = 1;
            }

            if ( sanitizedPage < 1 ) {
                sanitizedPage = 1;
            } else if ( sanitizedPage > totalPages ) {
                sanitizedPage = totalPages;
            }

            const currentPageField = form.querySelector(
                '.eipsi-current-page'
            );

            if ( currentPageField ) {
                currentPageField.value = sanitizedPage;
            }

            form.dataset.currentPage = sanitizedPage;

            this.updatePaginationDisplay( form, sanitizedPage, totalPages );

            // ✅ MOBILE FIX: Create mobile VAS labels when page becomes visible
            // This ensures labels are created after page visibility change
            this.createMobileVasLabels( form );

            if (
                trackChange &&
                sanitizedPage !== previousPage &&
                window.EIPSITracking
            ) {
                const trackingFormId = this.getTrackingFormId( form );
                if ( trackingFormId ) {
                    window.EIPSITracking.recordPageChange(
                        trackingFormId,
                        sanitizedPage
                    );
                }
            }

            this.updateNavigationForConsent( form );
        },

        handlePagination( form, direction ) {
            if ( ! form ) {
                return;
            }

            const currentPage = this.getCurrentPage( form );
            let targetPage = currentPage;
            let isBranchJump = false;
            let branchDetails = null;

            if ( direction === 'next' ) {
                if ( ! this.validateCurrentPage( form ) ) {
                    return;
                }

                const navigator = this.getNavigator( form );
                if ( navigator ) {
                    const result = navigator.getNextPage( currentPage );

                    if ( result.action === 'submit' ) {
                        this.handleSubmit( { preventDefault: () => {} }, form );
                        return;
                    }

                    if ( result.action === 'goToPage' && result.targetPage ) {
                        targetPage = result.targetPage;
                        isBranchJump = targetPage !== currentPage + 1;
                        branchDetails = result;
                    } else {
                        const totalPages = this.getTotalPages( form );
                        if ( currentPage < totalPages ) {
                            targetPage = currentPage + 1;
                        }
                    }

                    if ( isBranchJump ) {
                        navigator.markSkippedPages( currentPage, targetPage );
                    }

                    navigator.pushHistory( targetPage );
                } else {
                    const conditionalTarget = this.handleConditionalNavigation(
                        form,
                        currentPage
                    );

                    if ( conditionalTarget === 'submit' ) {
                        this.handleSubmit( { preventDefault: () => {} }, form );
                        return;
                    }

                    if (
                        typeof conditionalTarget === 'number' &&
                        ! Number.isNaN( conditionalTarget )
                    ) {
                        targetPage = conditionalTarget;
                    } else {
                        const totalPages = this.getTotalPages( form );
                        if ( currentPage < totalPages ) {
                            targetPage = currentPage + 1;
                        }
                    }
                }
            } else if ( direction === 'prev' ) {
                const navigator = this.getNavigator( form );
                if ( navigator ) {
                    const previousPage = navigator.popHistory();
                    if ( previousPage !== null ) {
                        targetPage = previousPage;
                    } else if ( currentPage > 1 ) {
                        targetPage = currentPage - 1;
                    }
                } else if ( currentPage > 1 ) {
                    targetPage = currentPage - 1;
                }
            }

            if ( targetPage !== currentPage ) {
                // Registrar cambio de página en page_transitions
                addPageTransition( targetPage );

                this.setCurrentPage( form, targetPage );

                if (
                    form.eipsiSaveContinue &&
                    form.eipsiSaveContinue.savePartial
                ) {
                    form.eipsiSaveContinue.savePartial( 'page-change' );
                }

                if ( isBranchJump && branchDetails && window.EIPSITracking ) {
                    this.recordBranchJump(
                        form,
                        currentPage,
                        targetPage,
                        branchDetails
                    );
                }
            }
        },

        recordBranchJump( form, fromPage, toPage, details ) {
            const trackingFormId = this.getTrackingFormId( form );
            if ( ! trackingFormId ) {
                return;
            }

            if (
                this.config.settings?.debug &&
                window.console &&
                window.console.log
            ) {
                window.console.log(
                    '[EIPSI Forms] Branch jump executed:',
                    `Page ${ fromPage } → Page ${ toPage }`,
                    {
                        fieldId: details.fieldId,
                        matchedValue: details.matchedValue,
                        isDefault: details.isDefault,
                    }
                );
            }

            if (
                window.EIPSITracking &&
                typeof window.EIPSITracking.trackEvent === 'function'
            ) {
                window.EIPSITracking.trackEvent(
                    'branch_jump',
                    trackingFormId,
                    {
                        from_page: fromPage,
                        to_page: toPage,
                        field_id: details.fieldId,
                        matched_value: details.matchedValue,
                    }
                );
            }
        },

        /**
         * CENTRAL NAVIGATION BUTTON VISIBILITY LOGIC
         * Single source of truth for what buttons appear on each page
         *
         * Clinical rules (immutable):
         * - First page (1/n, n>1): Only "Next"
         * - Single-page form (1/1): Only "Submit"
         * - Intermediate pages (2..n-1): "Prev" (if allowBackwardsNav) + "Next"
         * - Last page (n/n, n>1): "Prev" (if allowBackwardsNav) + "Submit"
         * - Thank-you page: No navigation buttons
         *
         * SACRED RULE: NEVER show "Next" + "Submit" simultaneously
         */
        updateNavigationButtons( form, currentPage, totalPages ) {
            if ( ! form ) {
                return;
            }

            const prevButton = form.querySelector( '.eipsi-prev-button' );
            const nextButton = form.querySelector( '.eipsi-next-button' );
            const submitButton = form.querySelector( '.eipsi-submit-button' );
            const isSubmitting = form.dataset.submitting === 'true';

            // Helper: Show/hide button (no disabled ghosts)
            const toggleVisibility = ( button, isVisible ) => {
                if ( ! button ) {
                    return;
                }

                if ( isVisible ) {
                    button.classList.remove( 'is-hidden' );
                    button.removeAttribute( 'aria-hidden' );
                    button.style.display = '';
                } else {
                    button.classList.add( 'is-hidden' );
                    button.setAttribute( 'aria-hidden', 'true' );
                    button.style.display = 'none';
                }
            };

            // Helper: Enable/disable button while keeping accessibility attrs aligned
            const setDisabledState = ( button, disabled ) => {
                if ( ! button ) {
                    return;
                }
                button.disabled = !! disabled;
                if ( disabled ) {
                    button.classList.add( 'is-disabled' );
                    button.setAttribute( 'aria-disabled', 'true' );
                } else {
                    button.classList.remove( 'is-disabled' );
                    button.removeAttribute( 'aria-disabled' );
                }
            };

            const currentPageElement = this.getPageElement( form, currentPage );

            // Thank-you page: hide everything and bail
            const isThankYouPage =
                form.dataset.formStatus === 'completed' ||
                this.isThankYouPageElement( currentPageElement );

            const hideAllButtons = () => {
                toggleVisibility( prevButton, false );
                setDisabledState( prevButton, true );
                toggleVisibility( nextButton, false );
                setDisabledState( nextButton, true );
                toggleVisibility( submitButton, false );
                setDisabledState( submitButton, true );
            };

            hideAllButtons();

            if ( isThankYouPage ) {
                return;
            }

            // Get allowBackwardsNav setting
            const rawAllowBackwards = form.dataset.allowBackwardsNav;
            const allowBackwardsNav =
                rawAllowBackwards === 'false' || rawAllowBackwards === '0'
                    ? false
                    : true;

            const isSinglePageForm = totalPages <= 1;
            const isFirstPage = currentPage <= 1;
            const isLastPage = currentPage >= totalPages;
            const strings = this.config.strings || {};

            // Rule 1: Single-page form (1/1)
            if ( isSinglePageForm ) {
                toggleVisibility( submitButton, true );
                setDisabledState( submitButton, isSubmitting );
                if ( submitButton ) {
                    submitButton.setAttribute(
                        'aria-label',
                        'Enviar el formulario (página única)'
                    );
                    if ( strings.submit ) {
                        submitButton.textContent = strings.submit;
                    }
                }
                return;
            }

            // Rule 2: First page of multi-page form (1/n, n>1)
            if ( isFirstPage ) {
                toggleVisibility( nextButton, true );
                setDisabledState( nextButton, isSubmitting );
                if ( nextButton ) {
                    nextButton.setAttribute(
                        'aria-label',
                        `Ir a la siguiente página (página ${
                            currentPage + 1
                        } de ${ totalPages })`
                    );
                }
                return;
            }

            // Rule 3: Last page (n/n, n>1)
            if ( isLastPage ) {
                if ( allowBackwardsNav && prevButton ) {
                    toggleVisibility( prevButton, true );
                    setDisabledState( prevButton, isSubmitting );
                    prevButton.setAttribute(
                        'aria-label',
                        `Ir a la página anterior (página ${
                            currentPage - 1
                        } de ${ totalPages })`
                    );
                }

                toggleVisibility( submitButton, true );
                setDisabledState( submitButton, isSubmitting );
                if ( submitButton ) {
                    submitButton.setAttribute(
                        'aria-label',
                        `Enviar el formulario (página ${ currentPage } de ${ totalPages })`
                    );
                    if ( strings.submit ) {
                        submitButton.textContent = strings.submit;
                    }
                }
                return;
            }

            // Rule 4: Intermediate pages (2..n-1)
            if ( allowBackwardsNav && prevButton ) {
                toggleVisibility( prevButton, true );
                setDisabledState( prevButton, isSubmitting );
                prevButton.setAttribute(
                    'aria-label',
                    `Ir a la página anterior (página ${
                        currentPage - 1
                    } de ${ totalPages })`
                );
            }

            toggleVisibility( nextButton, true );
            setDisabledState( nextButton, isSubmitting );
            if ( nextButton ) {
                nextButton.setAttribute(
                    'aria-label',
                    `Ir a la siguiente página (página ${
                        currentPage + 1
                    } de ${ totalPages })`
                );
            }
        },

        updatePaginationDisplay( form, currentPage, totalPages ) {
            // Defense against currentPage > totalPages (should never happen, but prevents UI glitches)
            if ( currentPage > totalPages ) {
                if ( window.console && window.console.error ) {
                    window.console.error(
                        '[EIPSI] CURRENT PAGE OUT OF BOUNDS',
                        { currentPage, totalPages }
                    );
                }
                currentPage = totalPages;
            }

            const progressText = form.querySelector(
                '.form-progress .current-page'
            );
            const totalPagesText = form.querySelector(
                '.form-progress .total-pages'
            );

            // Update progress text
            if ( progressText ) {
                progressText.textContent = currentPage;
            }

            if ( totalPagesText ) {
                totalPagesText.textContent = totalPages;
                totalPagesText.title = '';
            }

            // CALL CENTRAL NAVIGATION BUTTON LOGIC
            this.updateNavigationButtons( form, currentPage, totalPages );

            // Update page visibility and aria attributes
            this.updatePageVisibility( form, currentPage );
            this.updatePageAriaAttributes( form, currentPage );

            // Iniciar timer de la nueva página (0-based index)
            if ( window.eipsiTimers && typeof startPageTimer === 'function' ) {
                startPageTimer( currentPage - 1 );
            }

            // Update tracking
            if ( window.EIPSITracking ) {
                const trackingFormId = this.getTrackingFormId( form );
                if ( trackingFormId ) {
                    window.EIPSITracking.setCurrentPage(
                        trackingFormId,
                        currentPage,
                        { trackChange: false }
                    );
                }
            }
        },

        updatePageAriaAttributes( form, currentPage ) {
            const pages = form.querySelectorAll( '.eipsi-page' );

            pages.forEach( ( page, index ) => {
                const pageNumber = parseInt( page.dataset.page || index + 1 );

                if ( pageNumber === currentPage ) {
                    page.setAttribute( 'aria-hidden', 'false' );
                    page.removeAttribute( 'inert' );
                } else {
                    page.setAttribute( 'aria-hidden', 'true' );
                    if ( 'inert' in page ) {
                        page.inert = true;
                    }
                }
            } );
        },

        updatePageVisibility( form, currentPage ) {
            const pages = form.querySelectorAll( '.eipsi-page' );

            pages.forEach( ( page, index ) => {
                const pageNumber = parseInt( page.dataset.page || index + 1 );

                if ( pageNumber === currentPage ) {
                    page.style.display = '';
                    // v2.1.3 Fix: Enable fields in visible page
                    page.querySelectorAll( 'input, select, textarea, button' ).forEach( ( el ) => {
                        el.disabled = false;
                    } );
                } else {
                    page.style.display = 'none';
                    // v2.1.3 Fix: Disable fields in hidden pages to prevent validation errors
                    page.querySelectorAll( 'input, select, textarea, button' ).forEach( ( el ) => {
                        // Don't disable hidden inputs that are needed for form logic
                        if ( el.type !== 'hidden' ) {
                            el.disabled = true;
                        }
                    } );
                }
            } );

            if ( this.config.settings?.enableAutoScroll ) {
                const formContainer = form.closest(
                    '.eipsi-form, .eipsi-form'
                );
                if ( formContainer ) {
                    this.scrollToElement( formContainer );
                }
            }
        },

        setupFieldValidation( form ) {
            const fields = form.querySelectorAll( 'input, textarea, select' );

            fields.forEach( ( field ) => {
                field.addEventListener( 'blur', () => {
                    this.validateField( field );
                } );

                field.addEventListener( 'input', () => {
                    if ( field.classList.contains( 'error' ) ) {
                        this.validateField( field );
                    }
                } );
            } );
        },

        clearFieldError( formGroup, field, options = {} ) {
            if ( formGroup ) {
                formGroup.classList.remove( 'has-error' );
                const errorElement = formGroup.querySelector( '.form-error' );
                if ( errorElement ) {
                    errorElement.style.display = 'none';
                    errorElement.textContent = '';
                }
            }

            if ( field ) {
                field.classList.remove( 'error' );
                field.removeAttribute( 'aria-invalid' );
            }

            const { groupSelector } = options;

            if ( groupSelector && formGroup ) {
                const groupedInputs =
                    formGroup.querySelectorAll( groupSelector );
                groupedInputs.forEach( ( input ) => {
                    input.classList.remove( 'error' );
                    input.removeAttribute( 'aria-invalid' );
                } );
            }
        },

        validateField( field ) {
            if ( ! field ) {
                return true;
            }

            const formGroup = field.closest( '.form-group' );
            if ( ! formGroup ) {
                return true;
            }

            if ( field.disabled ) {
                this.clearFieldError( formGroup, field );
                return true;
            }

            const isRadio = field.type === 'radio';
            const isCheckbox = field.type === 'checkbox';
            const isSelect = field.tagName === 'SELECT';
            const isRange = field.type === 'range';
            const groupSelector =
                isRadio || isCheckbox
                    ? `input[type="${ field.type }"][name="${ field.name }"]`
                    : null;
            const isRequired =
                formGroup.dataset.required === 'true' ||
                field.hasAttribute( 'required' );
            let isValid = true;
            let errorMessage = '';

            const pageElement = field.closest( '.eipsi-page' );
            if (
                ( isSelect || isRadio ) &&
                pageElement &&
                ! this.isElementVisible( pageElement )
            ) {
                this.clearFieldError( formGroup, field, {
                    groupSelector,
                } );
                return true;
            }

            const errorElement = formGroup.querySelector( '.form-error' );
            const strings = this.config.strings || {};

            if ( isRange ) {
                if ( isRequired && field.dataset.touched === 'false' ) {
                    isValid = false;
                    errorMessage =
                        strings.sliderRequired ||
                        'Por favor desliza el slider para responder';
                }
            } else if ( isSelect ) {
                if ( isRequired && ( ! field.value || field.value === '' ) ) {
                    isValid = false;
                    errorMessage =
                        strings.requiredField || 'Este campo es obligatorio.';
                }
            } else if ( isRadio ) {
                const radioGroup = formGroup.querySelectorAll(
                    `input[type="radio"][name="${ field.name }"]`
                );
                const isChecked = Array.from( radioGroup ).some(
                    ( radio ) => radio.checked
                );

                if ( isRequired && ! isChecked ) {
                    isValid = false;
                    errorMessage =
                        strings.requiredField || 'Este campo es obligatorio.';
                }
            } else if ( isCheckbox ) {
                const isConsent = formGroup.dataset.consentBlock === 'true';
                const checkboxGroup = formGroup.querySelectorAll(
                    `input[type="checkbox"][name="${ field.name }"]`
                );
                const isChecked = Array.from( checkboxGroup ).some(
                    ( checkbox ) => checkbox.checked
                );

                if ( isRequired && ! isChecked ) {
                    isValid = false;
                    errorMessage = isConsent
                        ? strings.consentRequired ||
                          'Debe aceptar el consentimiento para continuar'
                        : strings.requiredField || 'Este campo es obligatorio.';
                }
            } else if ( isRequired && ! field.value.trim() ) {
                isValid = false;
                errorMessage =
                    strings.requiredField || 'Este campo es obligatorio.';
            } else if ( field.type === 'email' && field.value ) {
                const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if ( ! emailPattern.test( field.value ) ) {
                    isValid = false;
                    errorMessage =
                        strings.invalidEmail ||
                        'Por favor, introduzca una dirección de correo electrónico válida.';
                }
            }

            if ( isValid ) {
                this.clearFieldError( formGroup, field, {
                    groupSelector,
                } );
            } else {
                formGroup.classList.add( 'has-error' );

                if ( errorElement ) {
                    errorElement.style.display = 'block';
                    errorElement.textContent = errorMessage;
                }

                if ( isRadio || isCheckbox ) {
                    const groupedInputs = groupSelector
                        ? formGroup.querySelectorAll( groupSelector )
                        : [];
                    groupedInputs.forEach( ( input ) => {
                        input.classList.add( 'error' );
                        input.setAttribute( 'aria-invalid', 'true' );
                    } );
                } else {
                    field.classList.add( 'error' );
                    field.setAttribute( 'aria-invalid', 'true' );
                }
            }

            return isValid;
        },

        validateCurrentPage( form ) {
            if ( ! form ) {
                return true;
            }

            const currentPage = this.getCurrentPage( form );
            const pageElement = this.getPageElement( form, currentPage );

            if ( ! pageElement ) {
                return true;
            }

            const fields = pageElement.querySelectorAll(
                'input, textarea, select'
            );
            let isValid = true;
            const validatedGroups = new Set();

            fields.forEach( ( field ) => {
                const formGroup = field.closest( '.form-group' );
                const groupKey = formGroup
                    ? formGroup.dataset.fieldName || formGroup.id || ''
                    : '';

                if (
                    ( field.type === 'radio' || field.type === 'checkbox' ) &&
                    groupKey
                ) {
                    if ( validatedGroups.has( groupKey ) ) {
                        return;
                    }
                    validatedGroups.add( groupKey );
                }

                if ( ! this.validateField( field ) ) {
                    isValid = false;
                }
            } );

            if ( ! isValid ) {
                this.focusFirstInvalidField( form, pageElement );
            }

            return isValid;
        },

        resetValidationState( form ) {
            if ( ! form ) {
                return;
            }

            form.querySelectorAll( '.form-group.has-error' ).forEach(
                ( el ) => {
                    el.classList.remove( 'has-error' );
                }
            );

            form.querySelectorAll(
                'input.error, textarea.error, select.error'
            ).forEach( ( field ) => {
                field.classList.remove( 'error' );
            } );

            form.querySelectorAll( '[aria-invalid="true"]' ).forEach(
                ( el ) => {
                    el.removeAttribute( 'aria-invalid' );
                }
            );

            form.querySelectorAll( '.form-error' ).forEach( ( el ) => {
                el.style.display = 'none';
                el.textContent = '';
            } );
        },

        validateForm( form ) {
            if ( ! form ) {
                return true;
            }

            const navigator = this.getNavigator( form );
            let fieldsToValidate;

            if ( navigator && navigator.visitedPages.size > 0 ) {
                fieldsToValidate = [];
                const visitedPageNumbers = Array.from( navigator.visitedPages );

                visitedPageNumbers.forEach( ( pageNum ) => {
                    const pageElement = this.getPageElement( form, pageNum );
                    if ( pageElement ) {
                        const pageFields = pageElement.querySelectorAll(
                            'input, textarea, select'
                        );
                        fieldsToValidate.push( ...Array.from( pageFields ) );
                    }
                } );
            } else {
                fieldsToValidate = Array.from(
                    form.querySelectorAll( 'input, textarea, select' )
                );
            }

            let isValid = true;

            this.resetValidationState( form );

            const validatedGroups = new Set();

            fieldsToValidate.forEach( ( field ) => {
                const formGroup = field.closest( '.form-group' );
                const groupKey = formGroup
                    ? formGroup.dataset.fieldName || formGroup.id || ''
                    : '';

                if (
                    ( field.type === 'radio' || field.type === 'checkbox' ) &&
                    groupKey
                ) {
                    if ( validatedGroups.has( groupKey ) ) {
                        return;
                    }
                    validatedGroups.add( groupKey );
                }

                if ( ! this.validateField( field ) ) {
                    isValid = false;
                }
            } );

            if ( ! isValid ) {
                this.focusFirstInvalidField( form );
            }

            return isValid;
        },

        handleSubmit( e, form ) {
            e.preventDefault();

            // Ética clínica: si el estudio está cerrado, no validamos ni enviamos nada
            if ( this.applyStudyStatusGuard( form ) ) {
                return;
            }

            // ✅ v1.4.3 - VALIDACIÓN DE CONSENTIMIENTO (FIX)
            // Se valida ANTES de cualquier otra validación
            if ( ! validateConsentAccepted( form ) ) {
                this.showMessage(
                    form,
                    'error',
                    '⛔ Debes aceptar los términos de consentimiento para continuar.'
                );
                return;
            }

            this.getCurrentPage( form );

            if ( ! this.validateForm( form ) ) {
                this.showMessage(
                    form,
                    'error',
                    'Por favor, completa todos los campos requeridos.'
                );
                this.focusFirstInvalidField( form );
                return;
            }

            this.submitForm( form );
        },

        submitForm( form ) {
            if ( this.applyStudyStatusGuard( form ) ) {
                return;
            }

            // ✅ FIX: Enable all disabled inputs before submission
            // This ensures data from all form pages (including inactive ones) is sent
            form.querySelectorAll( 'input[disabled], select[disabled], textarea[disabled]' ).forEach( ( el ) => {
                el.removeAttribute( 'disabled' );
            } );

            const submitButton = form.querySelector( 'button[type="submit"]' );
            const prevButton = form.querySelector( '.eipsi-prev-button' );
            const nextButton = form.querySelector( '.eipsi-next-button' );
            const formData = new FormData( form );

            // Incluir wave_id desde URL si está presente (flujo longitudinal)
            const urlParams = new URLSearchParams( window.location.search );
            const waveId = urlParams.get( 'wave_id' );
            if ( waveId ) {
                formData.append( 'wave_id', waveId );
            }

            // Sanitize untouched VAS sliders (prevent "50" from being sent)
            form.querySelectorAll( '.vas-slider' ).forEach( ( slider ) => {
                if (
                    slider.dataset.touched === 'false' &&
                    formData.has( slider.name )
                ) {
                    formData.set( slider.name, '' );
                }
            } );

            // Completar la última página y calcular duración total del formulario
            this.finalizePageTracking();

            // Obtener IDs antes de enviar
            const formId = this.getFormId( form ) || '';
            const participantId = getUniversalParticipantId();
            const sessionId = getSessionId( formId );

            formData.append( 'action', 'eipsi_forms_submit_form' );
            formData.append( 'nonce', this.config.nonce );
            const end_timestamp_ms = Date.now();
            formData.append( 'form_end_time', end_timestamp_ms );
            formData.append( 'end_timestamp_ms', end_timestamp_ms );
            formData.append( 'participant_id', participantId );
            formData.append( 'session_id', sessionId );

            // Enviar metadata incluyendo page_transitions y timing data
            if ( window.eipsiMetadata ) {
                // Fusionar metadata existente con metadata de timing
                const timingMetadata = getTimingMetadata();

                // ✅ FIX: Agregar device_data desde window (ya recolectado por eipsi-fingerprint.js) o input oculto
                let deviceData = null;
                // Primero intentar obtener de window (datos ya procesados por eipsi-fingerprint.js)
                if ( window.eipsiDeviceData && Object.keys( window.eipsiDeviceData ).length > 0 ) {
                    deviceData = window.eipsiDeviceData;
                    console.log( '[EIPSI Forms] Using deviceData from window.eipsiDeviceData' );
                } else {
                    // Fallback: buscar en el input oculto
                    const deviceDataInput = document.querySelector( 'input[name="eipsi_device_data"]' );
                    if ( deviceDataInput && deviceDataInput.value ) {
                        try {
                            deviceData = JSON.parse( deviceDataInput.value );
                            console.log( '[EIPSI Forms] Using deviceData from input field' );
                        } catch ( e ) {
                            console.error( '[EIPSI Forms] Error parsing device_data:', e );
                        }
                    }
                }

                const finalMetadata = {
                    ...window.eipsiMetadata,
                    ...timingMetadata,
                    ...(deviceData && Object.keys(deviceData).length > 0 ? { device_data: deviceData } : {}),
                };

                console.log( '[EIPSI Forms] Final metadata device_data keys:', deviceData ? Object.keys( deviceData ).join( ',' ) : 'NULL' );
                console.log( '[EIPSI Forms] Metadata being sent:', JSON.stringify( finalMetadata ).substring( 0, 500 ) + '...' );

                formData.append( 'metadata', JSON.stringify( finalMetadata ) );
            }

            // Registrar en console para debugging
            if ( window.console && window.console.log ) {
                window.console.log( '📊 Form Submission:', {
                    formId,
                    participantId,
                    sessionId,
                    timestamp: new Date().toISOString(),
                    metadata: window.eipsiMetadata,
                } );
            }

            form.dataset.submitting = 'true';
            this.setFormLoading( form, true );

            // Disable all navigation buttons during submission
            if ( submitButton ) {
                submitButton.disabled = true;
                submitButton.classList.add( 'is-disabled' );
                submitButton.setAttribute( 'aria-disabled', 'true' );
                submitButton.dataset.originalText = submitButton.textContent;
                submitButton.textContent = 'Enviando...';
            }

            if ( prevButton ) {
                prevButton.disabled = true;
                prevButton.classList.add( 'is-disabled' );
                prevButton.setAttribute( 'aria-disabled', 'true' );
            }

            if ( nextButton ) {
                nextButton.disabled = true;
                nextButton.classList.add( 'is-disabled' );
                nextButton.setAttribute( 'aria-disabled', 'true' );
            }

            fetch( this.config.ajaxUrl, {
                method: 'POST',
                body: formData,
            } )
                .then( ( response ) => response.json() )
                .then( ( data ) => {
                    if ( data.success ) {
                        this.showMessage(
                            form,
                            'success',
                            '✓ Respuesta guardada correctamente'
                        );

                        // Clean up Save & Continue data
                        if (
                            form.eipsiSaveContinue &&
                            form.eipsiSaveContinue.handleFormCompleted
                        ) {
                            form.eipsiSaveContinue.handleFormCompleted();
                        }

                        if ( window.EIPSITracking ) {
                            const trackingFormId =
                                this.getTrackingFormId( form );
                            if ( trackingFormId ) {
                                window.EIPSITracking.recordSubmit(
                                    trackingFormId
                                );
                            }
                        }

                        // Task 2.4B: Pasar datos de próxima toma al thank-you page
                        const nextWaveData = data.data || {};
                        
                        // ✅ DIAGNÓSTICO: Loguear datos recibidos
                        console.log('[EIPSI-DIAG] Datos recibidos del servidor:', nextWaveData);
                        
                        // Show integrated thank-you page after 1.5 seconds
                        setTimeout( () => {
                            this.showIntegratedThankYouPage( form, nextWaveData );
                        }, 1500 );
                    } else {
                        const strings = this.config.strings || {};
                        const serverMessage =
                            data && data.data && data.data.message
                                ? data.data.message
                                : '';

                        this.showMessage(
                            form,
                            'error',
                            serverMessage ||
                                strings.error ||
                                'Ocurrió un error. Por favor, inténtelo de nuevo.'
                        );
                    }
                } )
                .catch( () => {
                    this.showMessage(
                        form,
                        'error',
                        'Ocurrió un error. Por favor, inténtelo de nuevo.'
                    );
                } )
                .finally( () => {
                    this.setFormLoading( form, false );
                    delete form.dataset.submitting;

                    if ( submitButton ) {
                        submitButton.disabled = false;
                        submitButton.classList.remove( 'is-disabled' );
                        submitButton.removeAttribute( 'aria-disabled' );
                        submitButton.textContent =
                            submitButton.dataset.originalText || 'Enviar';
                    }

                    if ( prevButton ) {
                        prevButton.disabled = false;
                        prevButton.classList.remove( 'is-disabled' );
                        prevButton.removeAttribute( 'aria-disabled' );
                    }

                    if ( nextButton ) {
                        nextButton.disabled = false;
                        nextButton.classList.remove( 'is-disabled' );
                        nextButton.removeAttribute( 'aria-disabled' );
                    }
                } );
        },

        /**
         * Finaliza el tracking de páginas cuando se envía el formulario
         * Completa la última página y calcula la duración total del formulario
         */
        finalizePageTracking() {
            if (
                ! window.eipsiMetadata ||
                ! window.eipsiMetadata.page_transitions
            ) {
                return;
            }

            // Completar la última página si no está completada
            const transitions = window.eipsiMetadata.page_transitions;
            if ( transitions.length > 0 ) {
                const lastPage = transitions[ transitions.length - 1 ];
                if ( ! lastPage.page_end_time ) {
                    lastPage.page_end_time = Date.now();
                    lastPage.page_duration =
                        lastPage.page_end_time - lastPage.page_start_time;
                }
            }

            // Calcular duración total del formulario
            if ( window.eipsiMetadata.form_start_time ) {
                window.eipsiMetadata.form_end_time = Date.now();
                window.eipsiMetadata.form_total_duration =
                    window.eipsiMetadata.form_end_time -
                    window.eipsiMetadata.form_start_time;
            }

            // Debug logging
            if (
                window.eipsiFormsConfig?.settings?.debug &&
                window.console?.log
            ) {
                window.console.log( '📊 Final page tracking:', {
                    total_pages: transitions.length,
                    total_duration_ms: window.eipsiMetadata.form_total_duration,
                    page_transitions: transitions,
                } );
            }
        },

        setFormLoading( form, isLoading ) {
            const formContainer = form.closest( '.eipsi-form, .eipsi-form' );
            if ( formContainer ) {
                if ( isLoading ) {
                    formContainer.classList.add( 'form-loading' );
                } else {
                    formContainer.classList.remove( 'form-loading' );
                }
            }
        },

        showMessage( form, type, message ) {
            this.clearMessages( form );

            const messageElement = document.createElement( 'div' );
            messageElement.className = `form-message form-message--${ type }`;
            messageElement.setAttribute(
                'role',
                type === 'error' ? 'alert' : 'status'
            );
            messageElement.setAttribute( 'aria-live', 'polite' );
            messageElement.dataset.messageState = 'visible';

            const prefersReducedMotion =
                window.matchMedia &&
                window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

            if ( prefersReducedMotion ) {
                messageElement.classList.add( 'no-motion' );
            }

            if ( type === 'success' ) {
                messageElement.innerHTML = `
                    <div class="form-message__icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle cx="12" cy="12" r="10" fill="currentColor" opacity="0.15"/>
                            <path d="M7 12L10.5 15.5L17 9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="form-message__content">
                        <div class="form-message__title">${ message }</div>
                    </div>
                    <div class="form-message__confetti" aria-hidden="true"></div>
                `;

                if ( ! prefersReducedMotion ) {
                    this.createConfetti( messageElement );
                }

                const submitButton = form.querySelector(
                    'button[type="submit"]'
                );
                if ( submitButton ) {
                    submitButton.disabled = true;
                    setTimeout( () => {
                        submitButton.disabled = false;
                    }, 4000 );
                }

                setTimeout( () => {
                    if ( messageElement.parentNode ) {
                        messageElement.classList.add( 'form-message--fadeout' );
                        messageElement.dataset.messageState = 'fading';
                        setTimeout( () => {
                            if ( messageElement.parentNode ) {
                                messageElement.dataset.messageState = 'removed';
                            }
                        }, 500 );
                    }
                }, 8000 );
            } else if ( type === 'error' ) {
                messageElement.innerHTML = `
                    <div class="form-message__icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle cx="12" cy="12" r="10" fill="currentColor" opacity="0.2"/>
                            <path d="M12 8V12M12 16H12.01" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <div class="form-message__content">
                        <div class="form-message__title">${ message }</div>
                    </div>
                `;
            } else {
                messageElement.textContent = message;
            }

            const formContainer = form.closest( '.eipsi-form, .eipsi-form' );
            if ( formContainer ) {
                formContainer.insertBefore( messageElement, form );
            } else {
                form.parentNode.insertBefore( messageElement, form );
            }

            if ( this.config.settings?.enableAutoScroll ) {
                this.scrollToElement( messageElement );
            }
        },

        createConfetti( messageElement ) {
            const confettiContainer = messageElement.querySelector(
                '.form-message__confetti'
            );

            if ( ! confettiContainer ) {
                return;
            }

            const colors = [
                'rgba(59, 108, 170, 0.8)',
                'rgba(25, 135, 84, 0.8)',
                'rgba(227, 242, 253, 0.9)',
                'rgba(255, 255, 255, 0.9)',
            ];

            const confettiCount = 20;

            for ( let i = 0; i < confettiCount; i++ ) {
                const confetti = document.createElement( 'div' );
                confetti.className = 'confetti-particle';
                confetti.style.setProperty(
                    '--confetti-color',
                    colors[ Math.floor( Math.random() * colors.length ) ]
                );
                confetti.style.setProperty(
                    '--confetti-x',
                    Math.random() * 100 + '%'
                );
                confetti.style.setProperty(
                    '--confetti-delay',
                    Math.random() * 0.5 + 's'
                );
                confetti.style.setProperty(
                    '--confetti-duration',
                    Math.random() * 1 + 2 + 's'
                );
                confetti.style.setProperty(
                    '--confetti-rotation',
                    Math.random() * 360 + 'deg'
                );
                confetti.style.setProperty(
                    '--confetti-scale',
                    Math.random() * 0.5 + 0.5
                );

                confettiContainer.appendChild( confetti );
            }
        },

        clearMessages( form ) {
            const formContainer = form.closest( '.eipsi-form, .eipsi-form' );
            if ( formContainer ) {
                const messages =
                    formContainer.querySelectorAll( '.form-message' );
                messages.forEach( ( msg ) => msg.remove() );
            } else {
                const messages = form.querySelectorAll( '.form-message' );
                messages.forEach( ( msg ) => msg.remove() );
            }
        },

        focusFirstInvalidField( form, scope ) {
            if ( ! form ) {
                return;
            }

            let searchRoot = scope;

            if ( ! searchRoot ) {
                const currentPageElement = this.getPageElement(
                    form,
                    this.getCurrentPage( form )
                );
                searchRoot = currentPageElement || form;
            }

            let invalidGroups = Array.from(
                searchRoot.querySelectorAll( '.has-error' )
            ).filter( ( group ) => this.isElementVisible( group ) );

            if ( invalidGroups.length === 0 && searchRoot !== form ) {
                invalidGroups = Array.from(
                    form.querySelectorAll( '.has-error' )
                ).filter( ( group ) => this.isElementVisible( group ) );
            }

            const targetGroup = invalidGroups[ 0 ];
            if ( ! targetGroup ) {
                return;
            }

            const focusableSelectors =
                'input:not([type="hidden"]), select, textarea, button, [tabindex]';
            const candidates = Array.from(
                targetGroup.querySelectorAll( focusableSelectors )
            ).filter(
                ( element ) =>
                    ! element.disabled &&
                    this.isElementVisible( element ) &&
                    element.getAttribute( 'tabindex' ) !== '-1'
            );

            const focusTarget = candidates[ 0 ] || null;
            const scrollTarget = focusTarget || targetGroup;

            if (
                this.config.settings?.enableAutoScroll &&
                this.isElementVisible( scrollTarget )
            ) {
                this.scrollToElement( scrollTarget );
            }

            if ( focusTarget && typeof focusTarget.focus === 'function' ) {
                try {
                    focusTarget.focus( { preventScroll: true } );
                } catch ( error ) {
                    focusTarget.focus();
                }
            }
        },

        scrollToElement( element ) {
            if ( ! this.isElementVisible( element ) ) {
                return;
            }

            const offset = this.config.settings?.scrollOffset || 20;
            const elementRect = element.getBoundingClientRect();
            const elementPosition = elementRect.top + window.pageYOffset;
            const offsetPosition = elementPosition - offset;

            if ( this.config.settings?.smoothScroll ) {
                window.scrollTo( {
                    top: offsetPosition,
                    behavior: 'smooth',
                } );
            } else {
                window.scrollTo( 0, offsetPosition );
            }
        },

        isElementVisible( element ) {
            if ( ! element ) {
                return false;
            }

            if ( element.hidden ) {
                return false;
            }

            if ( element.offsetParent !== null ) {
                return true;
            }

            const style = window.getComputedStyle( element );

            return (
                style.position === 'fixed' &&
                style.visibility !== 'hidden' &&
                style.display !== 'none' &&
                parseFloat( style.opacity || '1' ) > 0
            );
        },

        handleConditionalNavigation( form, currentPage ) {
            const currentPageElement = this.getPageElement( form, currentPage );
            if ( ! currentPageElement ) {
                return null;
            }

            const conditionalFields = currentPageElement.querySelectorAll(
                '[data-conditional-logic]'
            );

            for ( const field of conditionalFields ) {
                let conditionalLogic = {};

                try {
                    conditionalLogic = JSON.parse(
                        field.dataset.conditionalLogic || '{}'
                    );
                } catch ( error ) {
                    continue;
                }

                if (
                    ! conditionalLogic.enabled ||
                    ! Array.isArray( conditionalLogic.rules )
                ) {
                    continue;
                }

                const fieldValue = this.getFieldValue( field );
                const matchingRule = this.findMatchingRule(
                    conditionalLogic.rules,
                    fieldValue
                );

                if ( ! matchingRule ) {
                    continue;
                }

                if ( matchingRule.action === 'submit' ) {
                    return 'submit';
                }

                if (
                    matchingRule.action === 'goToPage' &&
                    matchingRule.targetPage
                ) {
                    const parsedTarget = parseInt(
                        matchingRule.targetPage,
                        10
                    );

                    if ( Number.isNaN( parsedTarget ) ) {
                        continue;
                    }

                    const totalPages = this.getTotalPages( form );
                    const boundedTarget = Math.min(
                        Math.max( parsedTarget, 1 ),
                        totalPages
                    );
                    const targetElement = this.getPageElement(
                        form,
                        boundedTarget
                    );

                    if ( this.isThankYouPageElement( targetElement ) ) {
                        return 'submit';
                    }

                    return boundedTarget;
                }
            }

            return null;
        },

        findMatchingRule( rules, fieldValue ) {
            if ( ! Array.isArray( rules ) ) {
                return null;
            }

            // For checkboxes, fieldValue is an array
            if ( Array.isArray( fieldValue ) ) {
                // Check if any selected value matches a rule
                for ( const value of fieldValue ) {
                    const rule = rules.find( ( r ) => r.value === value );
                    if ( rule ) {
                        return rule;
                    }
                }
            } else {
                // For select and radio
                return rules.find( ( rule ) => rule.value === fieldValue );
            }

            return null;
        },

        getFieldValue( field ) {
            const fieldType = field.dataset.fieldType;

            switch ( fieldType ) {
                case 'select':
                    const select = field.querySelector( 'select' );
                    return select ? select.value : '';

                case 'radio':
                    const checkedRadio = field.querySelector(
                        'input[type="radio"]:checked'
                    );
                    return checkedRadio ? checkedRadio.value : '';

                case 'checkbox':
                    const checkedBoxes = field.querySelectorAll(
                        'input[type="checkbox"]:checked'
                    );
                    return Array.from( checkedBoxes ).map( ( cb ) => cb.value );

                default:
                    return '';
            }
        },

        getPageElement( form, pageNumber ) {
            const pages = form.querySelectorAll( '.eipsi-page' );

            for ( let index = 0; index < pages.length; index++ ) {
                const page = pages[ index ];
                const pageNum =
                    parseInt( page.dataset.page || '', 10 ) || index + 1;

                if ( pageNum === pageNumber ) {
                    return page;
                }
            }

            return null;
        },

        isThankYouPageElement( page ) {
            if ( ! page ) {
                return false;
            }

            return (
                page.dataset.pageType === 'thank_you' ||
                page.dataset.page === 'thank-you' ||
                page.classList.contains( 'eipsi-thank-you-page-block' )
            );
        },

        goToPage( form, pageNumber ) {
            if ( ! form ) {
                return;
            }

            if ( pageNumber === 'submit' ) {
                this.handleSubmit( { preventDefault: () => {} }, form );
                return;
            }

            const targetPage = parseInt( pageNumber, 10 );

            if ( Number.isNaN( targetPage ) ) {
                return;
            }

            this.setCurrentPage( form, targetPage );
        },

        markFormCompleted( form ) {
            if ( ! form ) {
                return;
            }

            form.dataset.formStatus = 'completed';

            if ( window.EIPSITracking ) {
                const trackingFormId =
                    this.getTrackingFormId( form ) || this.getFormId( form );

                if ( trackingFormId ) {
                    window.EIPSITracking.setCurrentPage(
                        trackingFormId,
                        'completed',
                        { trackChange: true }
                    );
                }
            }
        },

        showIntegratedThankYouPage( form, nextWaveData = {} ) {
            // Check if a thank-you page block already exists
            const existingThankYouPage = form.querySelector(
                '.eipsi-page[data-page="thank-you"], .eipsi-thank-you-page-block'
            );

            if ( existingThankYouPage ) {
                // Use existing Gutenberg-created thank-you page
                this.showExistingThankYouPage( form, existingThankYouPage );
            } else {
                // Try to get completion config from Form Container data attributes
                const formContainer = form.closest(
                    '.eipsi-form, .eipsi-form'
                );
                const completionConfig =
                    this.getCompletionConfigFromContainer( formContainer );

                if ( completionConfig ) {
                    // Use config from Form Container block
                    this.createThankYouPage( form, completionConfig, nextWaveData );
                } else {
                    // Fallback: try to fetch from backend or use default
                    this.fetchCompletionConfigFromBackend( form, nextWaveData );
                }
            }
        },

        getCompletionConfigFromContainer( formContainer ) {
            if ( ! formContainer ) {
                return null;
            }

            const title = formContainer.dataset.completionTitle;
            const message = formContainer.dataset.completionMessage;
            const logoUrl = formContainer.dataset.completionLogo;
            const buttonLabel = formContainer.dataset.completionButtonLabel;

            // If title exists, we consider it configured (even with defaults)
            if ( title ) {
                return {
                    title: title || '¡Gracias por completar el cuestionario!',
                    message:
                        message ||
                        'Sus respuestas han sido registradas correctamente.',
                    logo_url: logoUrl || '',
                    show_logo: !! logoUrl,
                    show_home_button: true,
                    button_text: buttonLabel || 'Comenzar de nuevo',
                    button_action: 'reload',
                    show_animation: false,
                };
            }

            return null;
        },

        fetchCompletionConfigFromBackend( form, nextWaveData = {} ) {
            fetch( this.config.ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams( {
                    action: 'eipsi_get_completion_config',
                } ),
            } )
                .then( ( response ) => response.json() )
                .then( ( data ) => {
                    if ( ! data.success ) {
                        // Fallback to default message if AJAX fails
                        this.createThankYouPage( form, {
                            title: '¡Gracias por completar el cuestionario!',
                            message:
                                'Sus respuestas han sido registradas correctamente.',
                            show_logo: false,
                            show_home_button: true,
                            button_text: 'Comenzar de nuevo',
                            button_action: 'reload',
                            show_animation: false,
                        }, nextWaveData );
                        return;
                    }

                    this.createThankYouPage( form, data.data.config, nextWaveData );
                } )
                .catch( () => {
                    // Fallback to default message if network error
                    this.createThankYouPage( form, {
                        title: '¡Gracias por completar el cuestionario!',
                        message:
                            'Sus respuestas han sido registradas correctamente.',
                        show_logo: false,
                        show_home_button: true,
                        button_text: 'Comenzar de nuevo',
                        button_action: 'reload',
                        show_animation: false,
                    }, nextWaveData );
                } );
        },

        showExistingThankYouPage( form, thankYouPageElement ) {
            // Mark form as completed (sets state + tracking)
            this.markFormCompleted( form );

            // Hide all regular pages
            const pages = form.querySelectorAll(
                '.eipsi-page:not([data-page="thank-you"]):not(.eipsi-thank-you-page-block)'
            );
            pages.forEach( ( page ) => {
                page.style.display = 'none';
                page.setAttribute( 'aria-hidden', 'true' );
                if ( 'inert' in page ) {
                    page.inert = true;
                }
            } );
// ✅ FIX v1.5.2: Hide entire form content for non-paginated forms
// If there are no regular pages, this is a single-page form without pagination
if ( pages.length === 0 ) {
    const formContent = form.querySelector( '.eipsi-form-content' );
    if ( formContent ) {
        // Hide all direct children except thank-you page
        Array.from( formContent.children ).forEach( ( child ) => {
            if ( child !== thankYouPageElement && !child.classList.contains( 'eipsi-thank-you-page-block' ) ) {
                child.style.display = 'none';
                child.setAttribute( 'aria-hidden', 'true' );
                if ( 'inert' in child ) {
                    child.inert = true;
                }
            }
        } );
    } else {
        // Fallback: hide form content using direct child elements
        Array.from( form.children ).forEach( ( child ) => {
            if ( child !== thankYouPageElement && !child.classList.contains( 'eipsi-thank-you-page-block' ) ) {
                child.style.display = 'none';
                child.setAttribute( 'aria-hidden', 'true' );
                if ( 'inert' in child ) {
                    child.inert = true;
                }
            }
        } );
    }
}

            // Hide navigation buttons
            const navigation = form.querySelector( '.form-navigation' );
            if ( navigation ) {
                navigation.style.display = 'none';
            }

            // Hide progress indicator
            const progress = form.querySelector( '.form-progress' );
            if ( progress ) {
                progress.style.display = 'none';
            }

            // Clear any existing messages
            this.clearMessages( form );

            // Show the thank-you page
            thankYouPageElement.style.display = 'block';
            thankYouPageElement.removeAttribute( 'aria-hidden' );
            if ( 'inert' in thankYouPageElement ) {
                thankYouPageElement.inert = false;
            }

            // Set up restart button if present
            const restartButtons = thankYouPageElement.querySelectorAll(
                '.eipsi-restart-button, [data-action="restart"]'
            );
            restartButtons.forEach( ( btn ) => {
                btn.addEventListener(
                    'click',
                    () => {
                        window.location.reload();
                    },
                    { once: true }
                );
            } );

            // Scroll to top of form
            if ( this.config.settings?.enableAutoScroll ) {
                this.scrollToElement( form );
            }
        },

        createThankYouPage( form, config, nextWaveData = {} ) {
            // Mark form as completed (sets state + tracking)
            this.markFormCompleted( form );

            // Hide all existing pages
            const pages = form.querySelectorAll( '.eipsi-page' );
            pages.forEach( ( page ) => {
                page.style.display = 'none';
            } );

            // Hide navigation buttons
// ✅ FIX v1.5.2: Hide entire form content for non-paginated forms
// If there are no regular pages, hide all form content except newly created thank-you page will be added
if ( pages.length === 0 ) {
    const formContent = form.querySelector( '.eipsi-form-content' );
    if ( formContent ) {
        // Hide all direct children - thank-you page will be appended later
        Array.from( formContent.children ).forEach( ( child ) => {
            child.style.display = 'none';
            child.setAttribute( 'aria-hidden', 'true' );
            if ( 'inert' in child ) {
                child.inert = true;
            }
        } );
    } else {
        // Fallback: hide all direct children of form
        Array.from( form.children ).forEach( ( child ) => {
            // Keep essential elements like navigation/progress
            if ( !child.classList.contains( 'form-navigation' ) && 
                 !child.classList.contains( 'form-progress' ) ) {
                child.style.display = 'none';
                child.setAttribute( 'aria-hidden', 'true' );
                if ( 'inert' in child ) {
                    child.inert = true;
                }
            }
        } );
    }
}
            const navigation = form.querySelector( '.form-navigation' );
            if ( navigation ) {
                navigation.style.display = 'none';
            }

            // Hide progress indicator
            const progress = form.querySelector( '.form-progress' );
            if ( progress ) {
                progress.style.display = 'none';
            }

            // Clear any existing messages
            this.clearMessages( form );

            // Create thank-you page
            const thankYouPage = document.createElement( 'div' );
            thankYouPage.className = 'eipsi-page eipsi-thank-you-page';
            thankYouPage.dataset.page = 'thank-you';
            thankYouPage.style.display = 'block';

            // Build page content - start with empty logo
            let logoHtml = '';
            if ( config.logo_url ) {
                // Use the logo URL from config (from Form Container block)
                logoHtml = `<div class="eipsi-thank-you-logo">
                    <img src="${ this.escapeHtml(
                        config.logo_url
                    ) }" alt="Logo" class="eipsi-logo-image">
                </div>`;
            } else if ( config.show_logo ) {
                // Fetch logo from WordPress customizer via AJAX
                this.fetchAndRenderLogo( thankYouPage );
            }

            // Task 2.4B: Generar HTML de próximas tomas
            let nextWaveHtml = '';
            if ( nextWaveData && nextWaveData.has_next && nextWaveData.next_wave ) {
                const nextWave = nextWaveData.next_wave;
                const waveIndex = nextWave.wave_index || '';
                const waveName = nextWave.wave_name || `Toma ${waveIndex}`;
                const availableAt = nextWave.available_at || null;
                
                // Format date and calculate countdown
                let dateDisplay = '';
                let countdownDisplay = '';
                
                if ( availableAt ) {
                    const availableDate = new Date(availableAt);
                    const now = new Date();
                    const diffMs = availableDate - now;
                    
                    // Format date: "8 de abril, 18:00" (no year if same year)
                    const year = availableDate.getFullYear();
                    const currentYear = now.getFullYear();
                    const dateOptions = { 
                        month: 'long', 
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    };
                    if ( year !== currentYear ) {
                        dateOptions.year = 'numeric';
                    }
                    const formattedDate = availableDate.toLocaleDateString('es-ES', dateOptions);
                    
                    // Calculate countdown
                    if ( diffMs > 0 ) {
                        const days = Math.floor(diffMs / (1000 * 60 * 60 * 24));
                        const hours = Math.floor((diffMs % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                        const minutes = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
                        
                        const parts = [];
                        if ( days > 0 ) parts.push(`${days}d`);
                        if ( hours > 0 || days > 0 ) parts.push(`${hours}h`);
                        if ( minutes > 0 || (days === 0 && hours === 0) ) parts.push(`${minutes}m`);
                        
                        countdownDisplay = parts.join(' ');
                    } else {
                        countdownDisplay = 'disponible ahora';
                    }
                    
                    dateDisplay = `📅 Disponible el ${formattedDate}`;
                } else {
                    // Fallback to interval message if no exact timestamp
                    const intervalDays = nextWave.interval_days || 7;
                    const timeUnit = nextWave.time_unit || 'days';
                    
                    if (timeUnit === 'minutes' && intervalDays < 60) {
                        dateDisplay = `⏱️ Disponible en ${intervalDays} minutos`;
                        countdownDisplay = `${intervalDays}m`;
                    } else if (timeUnit === 'minutes' && intervalDays < 1440) {
                        const hours = Math.floor(intervalDays / 60);
                        const mins = intervalDays % 60;
                        dateDisplay = `⏱️ Disponible en ${hours}h ${mins}m`;
                        countdownDisplay = `${hours}h ${mins}m`;
                    } else {
                        dateDisplay = `📅 Disponible en ${intervalDays} ${timeUnit === 'days' ? 'días' : 'horas'}`;
                        countdownDisplay = `${intervalDays}${timeUnit === 'days' ? 'd' : 'h'}`;
                    }
                }
                
                // Mensaje de recordatorio por email
                const emailReminderMessage = '📧 Recibirás un email cuando la próxima toma esté disponible.';

                nextWaveHtml = `
                    <div class="eipsi-next-wave-info" style="margin: 30px 0; padding: 20px; background: #f0f9ff; border-left: 4px solid #0ea5e9; border-radius: 4px;">
                        <h3 style="margin: 0 0 12px 0; font-size: 18px; color: #0369a1; display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 20px;">📋</span>
                            Próximas tomas
                        </h3>
                        <p style="margin: 0 0 8px 0; font-size: 15px; color: #374151; line-height: 1.5;">
                            <strong>${this.escapeHtml(waveName)}</strong>
                        </p>
                        <div style="margin: 12px 0; padding: 12px; background: #ffffff; border-radius: 6px; border: 1px solid #e0f2fe;">
                            <p style="margin: 0 0 4px 0; font-size: 15px; color: #0c4a6e; font-weight: 500;">
                                ${dateDisplay}
                            </p>
                            ${countdownDisplay ? `<p style="margin: 0; font-size: 13px; color: #0284c7;">
                                ⏳ Quedan ${countdownDisplay}
                            </p>` : ''}
                        </div>
                        <p style="margin: 0; font-size: 13px; color: #9ca3af; display: flex; align-items: center; gap: 8px;">
                            ${emailReminderMessage}
                        </p>
                    </div>
                `;
            } else if ( nextWaveData && !nextWaveData.has_next && nextWaveData.completion_message ) {
                nextWaveHtml = `
                    <div class="eipsi-next-wave-info" style="margin: 30px 0; padding: 20px; background: #f0fdf4; border-left: 4px solid #10b981; border-radius: 4px;">
                        <p style="margin: 0; font-size: 16px; color: #065f46; display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 20px;">✅</span>
                            ${this.escapeHtml(nextWaveData.completion_message)}
                        </p>
                    </div>
                `;
            }

            let buttonHtml = '';
            if ( config.show_home_button ) {
                // v1.5.7 - If wave_id is in URL (longitudinal context), redirect to study dashboard (base URL)
                // instead of reloading the form or going to site home
                const urlParams = new URLSearchParams( window.location.search );
                const hasWaveId = urlParams.has( 'wave_id' );
                const studyDashboardUrl = window.location.origin + window.location.pathname;
                
                let buttonAction;
                if ( hasWaveId ) {
                    // Longitudinal context: go back to study dashboard (remove form_id and wave_id params)
                    buttonAction = `onclick="window.location.href = '${ studyDashboardUrl }';"`;
                } else if ( config.button_action === 'close' ) {
                    buttonAction = 'onclick="window.close();"';
                } else if ( config.button_action === 'reload' ) {
                    buttonAction = 'onclick="window.location.reload();"';
                } else {
                    buttonAction = 'onclick="window.location.href = \'/\';"';
                }

                const buttonLabel = nextWaveData && !nextWaveData.has_next 
                    ? 'Volver a inicio'
                    : config.button_text;

                buttonHtml = `
                    <div class="eipsi-thank-you-actions">
                        <button type="button" 
                                class="eipsi-thank-you-button" 
                                ${ buttonAction }>
                            ${ this.escapeHtml( buttonLabel ) }
                        </button>
                    </div>
                `;
            }

            const titleText =
                config.title || '¡Gracias por completar el cuestionario!';
            const messageHtml = this.formatCompletionMessage(
                config.message ||
                    'Sus respuestas han sido registradas correctamente.'
            );

            thankYouPage.innerHTML = `
                <div class="eipsi-thank-you-content">
                    ${ logoHtml }
                    <h2 class="eipsi-thank-you-title">${ this.escapeHtml(
                        titleText
                    ) }</h2>
                    <div class="eipsi-thank-you-message">${ messageHtml }</div>
                    ${ nextWaveHtml }
                    ${ buttonHtml }
                </div>
            `;

            // Add animation if enabled
            if ( config.show_animation ) {
                const prefersReducedMotion =
                    window.matchMedia &&
                    window.matchMedia( '(prefers-reduced-motion: reduce)' )
                        .matches;

                if ( ! prefersReducedMotion ) {
                    thankYouPage.classList.add( 'eipsi-thank-you-animated' );
                }
            }

            // Append to form
            const formContent = form.querySelector( '.eipsi-form-content' );
            if ( formContent ) {
                formContent.appendChild( thankYouPage );
            } else {
                form.appendChild( thankYouPage );
            }

            // Scroll to top of form
            if ( this.config.settings?.enableAutoScroll ) {
                this.scrollToElement( form );
            }

            // Update progress to 100%
            const currentPageSpan = form.querySelector(
                '.form-progress .current-page'
            );
            const totalPagesSpan = form.querySelector(
                '.form-progress .total-pages'
            );

            if ( currentPageSpan && totalPagesSpan ) {
                const totalPages = pages.length;
                currentPageSpan.textContent = totalPages;
                totalPagesSpan.textContent = totalPages;
            }
        },

        /**
         * Fetch site logo from WordPress customizer and render to thank-you page
         * This ensures logo works on ANY page context (Elementor, custom headers, etc.)
         *
         * @param {HTMLElement} thankYouPage The thank-you page element
         */
        fetchAndRenderLogo( thankYouPage ) {
            // Get ajax URL from config or construct it
            const ajaxUrl = this.config.ajaxUrl || '/wp-admin/admin-ajax.php';

            fetch( ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams( {
                    action: 'eipsi_get_site_logo',
                } ),
            } )
                .then( ( response ) => response.json() )
                .then( ( data ) => {
                    if ( data.success && data.data.logo_url ) {
                        // Insert logo at the beginning of content
                        const thankYouContent = thankYouPage.querySelector(
                            '.eipsi-thank-you-content'
                        );
                        if ( thankYouContent ) {
                            const logoDiv = document.createElement( 'div' );
                            logoDiv.className = 'eipsi-thank-you-logo';
                            const img = document.createElement( 'img' );
                            img.src = data.data.logo_url;
                            img.alt = 'Site Logo';
                            img.className = 'eipsi-logo-image';
                            logoDiv.appendChild( img );
                            thankYouContent.insertBefore(
                                logoDiv,
                                thankYouContent.firstChild
                            );
                        }
                    }
                } )
                .catch( ( error ) => {
                    // Silent fail - logo is optional
                    if ( window.console && window.console.debug ) {
                        window.console.debug(
                            '[EIPSI] Logo fetch failed:',
                            error
                        );
                    }
                } );
        },

        escapeHtml( text ) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;',
            };
            return text.replace( /[&<>"']/g, ( m ) => map[ m ] );
        },

        formatCompletionMessage( message ) {
            if ( ! message ) {
                return '';
            }

            const containsHtml = /<\/?[a-z][\s\S]*>/i.test( message );

            if ( containsHtml ) {
                return message;
            }

            // Convert double line breaks to paragraphs and single breaks to <br>
            const escaped = this.escapeHtml( message );
            const formatted = escaped
                .split( '\n\n' )
                .map( ( paragraph ) => {
                    const trimmed = paragraph.trim();
                    if ( ! trimmed ) {
                        return '';
                    }
                    const withBreaks = trimmed.replace( /\n/g, '<br>' );
                    return `<p>${ withBreaks }</p>`;
                } )
                .filter( Boolean )
                .join( '' );

            return formatted || `<p>${ escaped }</p>`;
        },
    };

    /**
     * v2.5: Consentimiento Informado - Validación de botones explícitos
     * 
     * Gate: Checkbox de confirmación de lectura habilita el botón "Aceptar"
     * Botón "Rechazar" siempre disponible - guarda estado y redirige
     * Botón "Aceptar" - guarda estado y permite continuar
     */
    function initConsentV2() {

    /**
     * Muestra un modal de confirmación con diseño EIPSI
     * Reutiliza tokens de diseño EIPSI.
     */
    function showConfirmationModal(title, message, confirmText = 'Confirmar', cancelText = 'Cancelar') {
        return new Promise((resolve) => {
            const modalId = 'eipsi-confirmation-modal';
            let modal = document.getElementById(modalId);
            
            if (!modal) {
                modal = document.createElement('div');
                modal.id = modalId;
                
                // Estilos inyectados para el modal
                const style = document.createElement('style');
                style.textContent = `
                    .eipsi-modal-overlay {
                        position: fixed;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 100%;
                        background: rgba(0, 0, 0, 0.5);
                        display: none;
                        justify-content: center;
                        align-items: center;
                        z-index: 10000;
                        backdrop-filter: blur(2px);
                    }
                    .eipsi-modal-card {
                        background: var(--eipsi-bg, #ffffff);
                        padding: var(--eipsi-space-lg, 1.5rem);
                        border-radius: var(--eipsi-radius, 8px);
                        box-shadow: var(--eipsi-shadow-lg, 0 4px 16px rgba(0, 0, 0, 0.1));
                        max-width: 450px;
                        width: 90%;
                        animation: eipsi-modal-in 0.3s ease-out;
                    }
                    @keyframes eipsi-modal-in {
                        from { transform: translateY(20px); opacity: 0; }
                        to { transform: translateY(0); opacity: 1; }
                    }
                    .eipsi-modal-content h3 {
                        margin-top: 0;
                        color: var(--eipsi-text, #2c3e50);
                        font-size: var(--eipsi-font-xl, 1.25rem);
                    }
                    .eipsi-modal-content p {
                        color: var(--eipsi-text-muted, #64748b);
                        line-height: 1.5;
                        margin-bottom: var(--eipsi-space-lg, 1.5rem);
                    }
                    .eipsi-modal-actions {
                        display: flex;
                        justify-content: flex-end;
                        gap: var(--eipsi-space-md, 1rem);
                    }
                    .eipsi-modal-actions .eipsi-btn {
                        padding: var(--eipsi-space-sm, 0.5rem) var(--eipsi-space-lg, 1.5rem);
                        border-radius: var(--eipsi-radius-sm, 4px);
                        font-weight: 600;
                        cursor: pointer;
                        border: none;
                        transition: var(--eipsi-transition, all 0.2s ease);
                    }
                    .eipsi-modal-actions .eipsi-btn-secondary {
                        background: var(--eipsi-bg-muted, #f1f5f9);
                        color: var(--eipsi-text, #2c3e50);
                    }
                    .eipsi-modal-actions .eipsi-btn-secondary:hover {
                        background: var(--eipsi-border, #e2e8f0);
                    }
                    .eipsi-modal-actions .eipsi-btn-danger {
                        background: var(--eipsi-error, #800000);
                        color: white;
                    }
                    .eipsi-modal-actions .eipsi-btn-danger:hover {
                        background: var(--eipsi-error-text, #660000);
                    }
                `;
                document.head.appendChild(style);
                
                modal.className = 'eipsi-modal-overlay';
                document.body.appendChild(modal);
            }
            
            modal.innerHTML = `
                <div class="eipsi-modal-card">
                    <div class="eipsi-modal-content">
                        <h3>${title}</h3>
                        <p>${message}</p>
                    </div>
                    <div class="eipsi-modal-actions">
                        <button class="eipsi-btn eipsi-btn-secondary" id="eipsi-modal-cancel">${cancelText}</button>
                        <button class="eipsi-btn eipsi-btn-danger" id="eipsi-modal-confirm">${confirmText}</button>
                    </div>
                </div>
            `;
            
            modal.style.display = 'flex';
            
            const handleConfirm = () => {
                modal.style.display = 'none';
                resolve(true);
            };
            
            const handleCancel = () => {
                modal.style.display = 'none';
                resolve(false);
            };
            
            modal.querySelector('#eipsi-modal-confirm').onclick = handleConfirm;
            modal.querySelector('#eipsi-modal-cancel').onclick = handleCancel;
        });
    }

        const consentBlocks = document.querySelectorAll('.eipsi-consent-block');
        
        consentBlocks.forEach((block) => { if (block.dataset.consentInitialized) return; block.dataset.consentInitialized = "true";
            const readingCheckbox = block.querySelector('#eipsi-consent-confirm-reading');
            const acceptBtn = block.querySelector('.eipsi-btn-accept');
            const rejectBtn = block.querySelector('.eipsi-btn-reject');
            const decisionInput = block.querySelector('#eipsi-consent-decision');
            const errorMsg = block.querySelector('.eipsi-consent-error');
            const originalRejectText = rejectBtn ? rejectBtn.textContent : 'No deseo participar';
            const originalAcceptText = acceptBtn ? acceptBtn.textContent : 'Acepto participar';
            
            if (!readingCheckbox || !acceptBtn || !rejectBtn) {
                return;
            }
            
            // Gate: Habilitar "Aceptar" solo cuando se marca el checkbox de lectura
            readingCheckbox.addEventListener('change', () => {
                const isChecked = readingCheckbox.checked;
                acceptBtn.disabled = !isChecked;
                acceptBtn.setAttribute('aria-disabled', !isChecked);
                
                if (errorMsg) {
                    errorMsg.style.display = 'none';
                }
                
            });
            
            // Click en "No deseo participar" (Rechazo T1)
            rejectBtn.addEventListener('click', async () => {
                const confirmed = await showConfirmationModal(
                    "¿Estás seguro?",
                    "¿Estás seguro de que no deseas participar? Esta decisión es definitiva y no podrás acceder a las encuestas.",
                    "Rechazar",
                    "Cancelar"
                );

                if (!confirmed) {
                    return;
                }
                rejectBtn.disabled = true;
                rejectBtn.textContent = 'Procesando...';
                
                // Guardar decisión en campo oculto
                if (decisionInput) {
                    decisionInput.value = 'declined';
                }
                
                // Guardar en backend (consent_declined) y redirigir
                try {
                    const result = await saveConsentDecision(block, 'declined');
                    if (!result || !result.success) {
                        throw new Error(result?.data?.message || 'Error saving declined consent');
                    }
                    // Usar URL del response o fallback
                    const redirectUrl = result?.data?.redirect || '/';
                    window.location.href = redirectUrl;
                } catch (e) {
                    rejectBtn.disabled = false;
                    rejectBtn.textContent = originalRejectText;
                    alert(e?.message || 'No se pudo registrar tu decisión. Intentá nuevamente.');
                }
            });
            
            // Click en "Acepto participar"
            acceptBtn.addEventListener('click', async () => { const form = block.closest("form"); if (form && form.dataset.submitting === "true") return;
                if (!readingCheckbox.checked) {
                    if (errorMsg) {
                        errorMsg.style.display = 'block';
                        errorMsg.textContent = 'Debes confirmar que leíste el consentimiento informado para continuar.';
                    }
                    return;
                }
                
                if (form) form.dataset.submitting = "true"; acceptBtn.disabled = true;
                acceptBtn.textContent = 'Procesando...';
                
                // Guardar decisión en campo oculto
                if (decisionInput) {
                    decisionInput.value = 'accepted';
                }
                
                // Guardar en backend
                try {
                    const result = await saveConsentDecision(block, 'accepted');
                    if (!result || !result.success) {
                        throw new Error(result?.data?.message || 'Error saving accepted consent');
                    }
                } catch (e) {
                    acceptBtn.disabled = false;
                    acceptBtn.textContent = originalAcceptText;
                    if (form) delete form.dataset.submitting; alert(e?.message || "No se pudo registrar tu consentimiento. Intentá nuevamente.");
                    return;
                }
                
                // Marcar bloque como aceptado
                block.classList.add('consent-accepted');
                
                // Guardar estado actual con Save & Continue antes de avanzar
                // Esperar hasta 2 segundos a que Save & Continue se inicialice
                if (form) {
                    let attempts = 0;
                    const maxAttempts = 20; // 20 * 100ms = 2 segundos
                    
                    while (!form.eipsiSaveContinue && attempts < maxAttempts) {
                        await new Promise(resolve => setTimeout(resolve, 100));
                        attempts++;
                    }
                    
                    if (form.eipsiSaveContinue && typeof form.eipsiSaveContinue.queueSave === 'function') {
                        try {
                            await form.eipsiSaveContinue.queueSave('consent-accepted');
                            console.log('[S&C] Consent decision saved before page advance');
                        } catch (e) {
                            console.warn('[S&C] Failed to save consent decision:', e);
                        }
                    } else {
                        console.warn('[S&C] Save & Continue not initialized after 2s, proceeding without save');
                    }
                }
                
                // Avanzar a la siguiente página (como el botón "Siguiente")
                
                if (form && window.EIPSIForms && window.EIPSIForms.handlePagination) {
                    if (form) delete form.dataset.submitting; window.EIPSIForms.handlePagination(form, "next");
                } else {
                    acceptBtn.disabled = false;
                    acceptBtn.textContent = originalAcceptText;
                }
            });
        });
    }
    
    /**
     * Guardar decisión de consentimiento en backend
     */
    async function saveConsentDecision(block, decision) {
        const formId = block.closest('form')?.dataset?.formId || 'default';
        const participantId = window.eipsiAuth?.participantId || '';
        const payload = new URLSearchParams({
            action: 'eipsi_save_consent_decision',
            nonce: window.eipsiFormsConfig?.nonce || '',
            decision: decision,
            form_id: formId,
            participant_id: participantId,
            timestamp: new Date().toISOString()
        });
        
        const ajaxUrl = window.eipsiFormsConfig?.ajaxUrl || '/wp-admin/admin-ajax.php';
        
        const response = await fetch(ajaxUrl, {
            method: 'POST',
            body: payload,
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            credentials: 'same-origin'
        });
        
        return response.json();
    }
    
    // Inicializar consentimiento v2.5 al cargar DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initConsentV2);
    } else {
        initConsentV2();
    }

    EIPSIForms.init();

    window.EIPSIForms = EIPSIForms;
    window.EIPSIForms.conditionalNavigators = EIPSIForms.navigators;
} )();
