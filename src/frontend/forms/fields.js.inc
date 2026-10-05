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

