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

