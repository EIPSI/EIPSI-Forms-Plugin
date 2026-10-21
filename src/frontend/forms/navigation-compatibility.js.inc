        scrollToElement( element ) {
            if ( ! this.isElementVisible( element ) ) {
                return;
            }

            const offset = this.config.settings?.scrollOffset || 20;
            const elementRect = element.getBoundingClientRect();
            const elementPosition = elementRect.top + window.pageYOffset;
            const offsetPosition = elementPosition - offset;

            if ( this.config.settings?.smoothScroll ) {
                window.scrollTo( {
                    top: offsetPosition,
                    behavior: 'smooth',
                } );
            } else {
                window.scrollTo( 0, offsetPosition );
            }
        },

        isElementVisible( element ) {
            if ( ! element ) {
                return false;
            }

            if ( element.hidden ) {
                return false;
            }

            if ( element.offsetParent !== null ) {
                return true;
            }

            const style = window.getComputedStyle( element );

            return (
                style.position === 'fixed' &&
                style.visibility !== 'hidden' &&
                style.display !== 'none' &&
                parseFloat( style.opacity || '1' ) > 0
            );
        },

        handleConditionalNavigation( form, currentPage ) {
            const currentPageElement = this.getPageElement( form, currentPage );
            if ( ! currentPageElement ) {
                return null;
            }

            const conditionalFields = currentPageElement.querySelectorAll(
                '[data-conditional-logic]'
            );

            for ( const field of conditionalFields ) {
                let conditionalLogic = {};

                try {
                    conditionalLogic = JSON.parse(
                        field.dataset.conditionalLogic || '{}'
                    );
                } catch ( error ) {
                    continue;
                }

                if (
                    ! conditionalLogic.enabled ||
                    ! Array.isArray( conditionalLogic.rules )
                ) {
                    continue;
                }

                const fieldValue = this.getFieldValue( field );
                const matchingRule = this.findMatchingRule(
                    conditionalLogic.rules,
                    fieldValue
                );

                if ( ! matchingRule ) {
                    continue;
                }

                if ( matchingRule.action === 'submit' ) {
                    return 'submit';
                }

                if (
                    matchingRule.action === 'goToPage' &&
                    matchingRule.targetPage
                ) {
                    const parsedTarget = parseInt(
                        matchingRule.targetPage,
                        10
                    );

                    if ( Number.isNaN( parsedTarget ) ) {
                        continue;
                    }

                    const totalPages = this.getTotalPages( form );
                    const boundedTarget = Math.min(
                        Math.max( parsedTarget, 1 ),
                        totalPages
                    );
                    const targetElement = this.getPageElement(
                        form,
                        boundedTarget
                    );

                    if ( this.isThankYouPageElement( targetElement ) ) {
                        return 'submit';
                    }

                    return boundedTarget;
                }
            }

            return null;
        },

        findMatchingRule( rules, fieldValue ) {
            if ( ! Array.isArray( rules ) ) {
                return null;
            }

            // For checkboxes, fieldValue is an array
            if ( Array.isArray( fieldValue ) ) {
                // Check if any selected value matches a rule
                for ( const value of fieldValue ) {
                    const rule = rules.find( ( r ) => r.value === value );
                    if ( rule ) {
                        return rule;
                    }
                }
            } else {
                // For select and radio
                return rules.find( ( rule ) => rule.value === fieldValue );
            }

            return null;
        },

        getFieldValue( field ) {
            const fieldType = field.dataset.fieldType;

            switch ( fieldType ) {
                case 'select':
                    const select = field.querySelector( 'select' );
                    return select ? select.value : '';

                case 'radio':
                    const checkedRadio = field.querySelector(
                        'input[type="radio"]:checked'
                    );
                    return checkedRadio ? checkedRadio.value : '';

                case 'checkbox':
                    const checkedBoxes = field.querySelectorAll(
                        'input[type="checkbox"]:checked'
                    );
                    return Array.from( checkedBoxes ).map( ( cb ) => cb.value );

                default:
                    return '';
            }
        },

        getPageElement( form, pageNumber ) {
            const pages = form.querySelectorAll( '.eipsi-page' );

            for ( let index = 0; index < pages.length; index++ ) {
                const page = pages[ index ];
                const pageNum =
                    parseInt( page.dataset.page || '', 10 ) || index + 1;

                if ( pageNum === pageNumber ) {
                    return page;
                }
            }

            return null;
        },

        isThankYouPageElement( page ) {
            if ( ! page ) {
                return false;
            }

            return (
                page.dataset.pageType === 'thank_you' ||
                page.dataset.page === 'thank-you' ||
                page.classList.contains( 'eipsi-thank-you-page-block' )
            );
        },

        goToPage( form, pageNumber ) {
            if ( ! form ) {
                return;
            }

            if ( pageNumber === 'submit' ) {
                this.handleSubmit( { preventDefault: () => {} }, form );
                return;
            }

            const targetPage = parseInt( pageNumber, 10 );

            if ( Number.isNaN( targetPage ) ) {
                return;
            }

            this.setCurrentPage( form, targetPage );
        },

