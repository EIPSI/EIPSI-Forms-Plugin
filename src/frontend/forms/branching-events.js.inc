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

