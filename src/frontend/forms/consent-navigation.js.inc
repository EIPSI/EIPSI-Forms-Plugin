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

