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

