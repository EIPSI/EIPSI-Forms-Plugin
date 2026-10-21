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

