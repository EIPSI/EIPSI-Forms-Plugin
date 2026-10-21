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
