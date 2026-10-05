        setupFieldValidation( form ) {
            const fields = form.querySelectorAll( 'input, textarea, select' );

            fields.forEach( ( field ) => {
                field.addEventListener( 'blur', () => {
                    this.validateField( field );
                } );

                field.addEventListener( 'input', () => {
                    if ( field.classList.contains( 'error' ) ) {
                        this.validateField( field );
                    }
                } );
            } );
        },

        clearFieldError( formGroup, field, options = {} ) {
            if ( formGroup ) {
                formGroup.classList.remove( 'has-error' );
                const errorElement = formGroup.querySelector( '.form-error' );
                if ( errorElement ) {
                    errorElement.style.display = 'none';
                    errorElement.textContent = '';
                }
            }

            if ( field ) {
                field.classList.remove( 'error' );
                field.removeAttribute( 'aria-invalid' );
            }

            const { groupSelector } = options;

            if ( groupSelector && formGroup ) {
                const groupedInputs =
                    formGroup.querySelectorAll( groupSelector );
                groupedInputs.forEach( ( input ) => {
                    input.classList.remove( 'error' );
                    input.removeAttribute( 'aria-invalid' );
                } );
            }
        },

        validateField( field ) {
            if ( ! field ) {
                return true;
            }

            const formGroup = field.closest( '.form-group' );
            if ( ! formGroup ) {
                return true;
            }

            if ( field.disabled ) {
                this.clearFieldError( formGroup, field );
                return true;
            }

            const isRadio = field.type === 'radio';
            const isCheckbox = field.type === 'checkbox';
            const isSelect = field.tagName === 'SELECT';
            const isRange = field.type === 'range';
            const groupSelector =
                isRadio || isCheckbox
                    ? `input[type="${ field.type }"][name="${ field.name }"]`
                    : null;
            const isRequired =
                formGroup.dataset.required === 'true' ||
                field.hasAttribute( 'required' );
            let isValid = true;
            let errorMessage = '';

            const pageElement = field.closest( '.eipsi-page' );
            if (
                ( isSelect || isRadio ) &&
                pageElement &&
                ! this.isElementVisible( pageElement )
            ) {
                this.clearFieldError( formGroup, field, {
                    groupSelector,
                } );
                return true;
            }

            const errorElement = formGroup.querySelector( '.form-error' );
            const strings = this.config.strings || {};

            if ( isRange ) {
                if ( isRequired && field.dataset.touched === 'false' ) {
                    isValid = false;
                    errorMessage =
                        strings.sliderRequired ||
                        'Por favor desliza el slider para responder';
                }
            } else if ( isSelect ) {
                if ( isRequired && ( ! field.value || field.value === '' ) ) {
                    isValid = false;
                    errorMessage =
                        strings.requiredField || 'Este campo es obligatorio.';
                }
            } else if ( isRadio ) {
                const radioGroup = formGroup.querySelectorAll(
                    `input[type="radio"][name="${ field.name }"]`
                );
                const isChecked = Array.from( radioGroup ).some(
                    ( radio ) => radio.checked
                );

                if ( isRequired && ! isChecked ) {
                    isValid = false;
                    errorMessage =
                        strings.requiredField || 'Este campo es obligatorio.';
                }
            } else if ( isCheckbox ) {
                const isConsent = formGroup.dataset.consentBlock === 'true';
                const checkboxGroup = formGroup.querySelectorAll(
                    `input[type="checkbox"][name="${ field.name }"]`
                );
                const isChecked = Array.from( checkboxGroup ).some(
                    ( checkbox ) => checkbox.checked
                );

                if ( isRequired && ! isChecked ) {
                    isValid = false;
                    errorMessage = isConsent
                        ? strings.consentRequired ||
                          'Debe aceptar el consentimiento para continuar'
                        : strings.requiredField || 'Este campo es obligatorio.';
                }
            } else if ( isRequired && ! field.value.trim() ) {
                isValid = false;
                errorMessage =
                    strings.requiredField || 'Este campo es obligatorio.';
            } else if ( field.type === 'email' && field.value ) {
                const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if ( ! emailPattern.test( field.value ) ) {
                    isValid = false;
                    errorMessage =
                        strings.invalidEmail ||
                        'Por favor, introduzca una dirección de correo electrónico válida.';
                }
            }

            if ( isValid ) {
                this.clearFieldError( formGroup, field, {
                    groupSelector,
                } );
            } else {
                formGroup.classList.add( 'has-error' );

                if ( errorElement ) {
                    errorElement.style.display = 'block';
                    errorElement.textContent = errorMessage;
                }

                if ( isRadio || isCheckbox ) {
                    const groupedInputs = groupSelector
                        ? formGroup.querySelectorAll( groupSelector )
                        : [];
                    groupedInputs.forEach( ( input ) => {
                        input.classList.add( 'error' );
                        input.setAttribute( 'aria-invalid', 'true' );
                    } );
                } else {
                    field.classList.add( 'error' );
                    field.setAttribute( 'aria-invalid', 'true' );
                }
            }

            return isValid;
        },

        validateCurrentPage( form ) {
            if ( ! form ) {
                return true;
            }

            const currentPage = this.getCurrentPage( form );
            const pageElement = this.getPageElement( form, currentPage );

            if ( ! pageElement ) {
                return true;
            }

            const fields = pageElement.querySelectorAll(
                'input, textarea, select'
            );
            let isValid = true;
            const validatedGroups = new Set();

            fields.forEach( ( field ) => {
                const formGroup = field.closest( '.form-group' );
                const groupKey = formGroup
                    ? formGroup.dataset.fieldName || formGroup.id || ''
                    : '';

                if (
                    ( field.type === 'radio' || field.type === 'checkbox' ) &&
                    groupKey
                ) {
                    if ( validatedGroups.has( groupKey ) ) {
                        return;
                    }
                    validatedGroups.add( groupKey );
                }

                if ( ! this.validateField( field ) ) {
                    isValid = false;
                }
            } );

            if ( ! isValid ) {
                this.focusFirstInvalidField( form, pageElement );
            }

            return isValid;
        },

        resetValidationState( form ) {
            if ( ! form ) {
                return;
            }

            form.querySelectorAll( '.form-group.has-error' ).forEach(
                ( el ) => {
                    el.classList.remove( 'has-error' );
                }
            );

            form.querySelectorAll(
                'input.error, textarea.error, select.error'
            ).forEach( ( field ) => {
                field.classList.remove( 'error' );
            } );

            form.querySelectorAll( '[aria-invalid="true"]' ).forEach(
                ( el ) => {
                    el.removeAttribute( 'aria-invalid' );
                }
            );

            form.querySelectorAll( '.form-error' ).forEach( ( el ) => {
                el.style.display = 'none';
                el.textContent = '';
            } );
        },

        validateForm( form ) {
            if ( ! form ) {
                return true;
            }

            const navigator = this.getNavigator( form );
            let fieldsToValidate;

            if ( navigator && navigator.visitedPages.size > 0 ) {
                fieldsToValidate = [];
                const visitedPageNumbers = Array.from( navigator.visitedPages );

                visitedPageNumbers.forEach( ( pageNum ) => {
                    const pageElement = this.getPageElement( form, pageNum );
                    if ( pageElement ) {
                        const pageFields = pageElement.querySelectorAll(
                            'input, textarea, select'
                        );
                        fieldsToValidate.push( ...Array.from( pageFields ) );
                    }
                } );
            } else {
                fieldsToValidate = Array.from(
                    form.querySelectorAll( 'input, textarea, select' )
                );
            }

            let isValid = true;

            this.resetValidationState( form );

            const validatedGroups = new Set();

            fieldsToValidate.forEach( ( field ) => {
                const formGroup = field.closest( '.form-group' );
                const groupKey = formGroup
                    ? formGroup.dataset.fieldName || formGroup.id || ''
                    : '';

                if (
                    ( field.type === 'radio' || field.type === 'checkbox' ) &&
                    groupKey
                ) {
                    if ( validatedGroups.has( groupKey ) ) {
                        return;
                    }
                    validatedGroups.add( groupKey );
                }

                if ( ! this.validateField( field ) ) {
                    isValid = false;
                }
            } );

            if ( ! isValid ) {
                this.focusFirstInvalidField( form );
            }

            return isValid;
        },

