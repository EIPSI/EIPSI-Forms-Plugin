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

