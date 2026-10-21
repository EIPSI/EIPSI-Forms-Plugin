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

