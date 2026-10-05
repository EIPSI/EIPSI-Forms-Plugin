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

