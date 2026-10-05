    class ConditionalNavigator {
        constructor( form ) {
            this.form = form;
            this.fieldCache = new Map();
            this.history = [];
            this.visitedPages = new Set();
            this.skippedPages = new Set(); // v2.1.3 Fix: missing initialization
        }

        parseConditionalLogic( jsonString ) {
            if ( ! jsonString || jsonString === 'true' ) {
                return null;
            }

            try {
                return JSON.parse( jsonString );
            } catch ( error ) {
                if ( window.console && window.console.warn ) {
                    window.console.warn(
                        '[EIPSI Forms] Invalid conditional logic JSON:',
                        jsonString,
                        error
                    );
                }
                return null;
            }
        }

        normalizeConditionalLogic( logic ) {
            if ( ! logic ) {
                return null;
            }

            if ( Array.isArray( logic ) ) {
                return {
                    enabled: logic.length > 0,
                    rules: logic.map( ( rule ) => ( {
                        id: rule.id || `rule-${ Date.now() }`,
                        matchValue: rule.value || rule.matchValue || '',
                        action: rule.action || 'nextPage',
                        targetPage: rule.targetPage || null,
                    } ) ),
                    defaultAction: 'nextPage',
                    defaultTargetPage: null,
                };
            }

            if ( typeof logic === 'object' && logic.enabled !== undefined ) {
                return logic;
            }

            return null;
        }

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

                case 'likert':
                    const checkedLikert = field.querySelector(
                        'input[type="radio"]:checked'
                    );
                    return checkedLikert ? checkedLikert.value : '';

                case 'checkbox':
                    const checkedBoxes = field.querySelectorAll(
                        'input[type="checkbox"]:checked'
                    );
                    return Array.from( checkedBoxes ).map( ( cb ) => cb.value );

                case 'vas-slider':
                    const slider = field.querySelector( 'input[type="range"]' );
                    if ( slider ) {
                        // If untouched, treat as null (no answer)
                        if ( slider.dataset.touched === 'false' ) {
                            return null;
                        }
                        const value = parseFloat( slider.value );
                        return ! Number.isNaN( value ) ? value : null;
                    }
                    return null;

                default:
                    return '';
            }
        }

        /**
         * Evalúa una condición individual (nueva estructura con conditions[])
         * @param {Object} condition - Condición individual con fieldId, operator, value/threshold
         * @param {HTMLElement} pageElement - Elemento de la página actual
         * @return {boolean} true si la condición se cumple
         */
        evaluateCondition( condition, pageElement ) {
            if ( ! condition || ! pageElement ) {
                return false;
            }

            // Buscar el campo por fieldId o fieldName
            const fieldId = condition.fieldId;
            if ( ! fieldId ) {
                return false;
            }

            const field = pageElement.querySelector(
                `[data-field-name="${ fieldId }"]`
            );

            if ( ! field ) {
                return false;
            }

            const fieldValue = this.getFieldValue( field );

            if (
                ! fieldValue &&
                fieldValue !== 0 &&
                ( ! Array.isArray( fieldValue ) || fieldValue.length === 0 )
            ) {
                return false;
            }

            // Condición numérica (VAS, Likert con operadores numéricos)
            if (
                condition.operator &&
                condition.threshold !== undefined &&
                condition.fieldType === 'numeric'
            ) {
                const numValue =
                    typeof fieldValue === 'number'
                        ? fieldValue
                        : parseFloat( fieldValue );

                if ( Number.isNaN( numValue ) ) {
                    return false;
                }

                const threshold = parseFloat( condition.threshold );
                if ( Number.isNaN( threshold ) ) {
                    return false;
                }

                switch ( condition.operator ) {
                    case '>=':
                        return numValue >= threshold;
                    case '<=':
                        return numValue <= threshold;
                    case '>':
                        return numValue > threshold;
                    case '<':
                        return numValue < threshold;
                    case '==':
                        return numValue === threshold;
                    default:
                        return false;
                }
            }

            // Condición discreta (RADIO, CHECKBOX, SELECT)
            if ( condition.value !== undefined ) {
                // CHECKBOX (array de valores)
                if ( Array.isArray( fieldValue ) ) {
                    return fieldValue.includes( condition.value );
                }

                // RADIO, SELECT, LIKERT (valor único)
                return String( fieldValue ) === String( condition.value );
            }

            return false;
        }

        /**
         * Evalúa una regla completa con múltiples condiciones AND/OR
         * @param {Object} rule - Regla con conditions[] y logicalOperator
         * @param {HTMLElement} pageElement - Elemento de la página actual
         * @return {boolean} true si la regla se cumple
         */
        evaluateRule( rule, pageElement ) {
            if ( ! rule || ! pageElement ) {
                return false;
            }

            // Nueva estructura: rule.conditions[]
            if (
                Array.isArray( rule.conditions ) &&
                rule.conditions.length > 0
            ) {
                // Evaluar cada condición
                const results = rule.conditions.map( ( cond ) =>
                    this.evaluateCondition( cond, pageElement )
                );

                // Si solo hay una condición, devolver su resultado
                if ( results.length === 1 ) {
                    return results[ 0 ];
                }

                // Múltiples condiciones: aplicar AND/OR
                // La primera condición no tiene logicalOperator, las siguientes sí
                let finalResult = results[ 0 ];

                for ( let i = 1; i < results.length; i++ ) {
                    const operator =
                        rule.conditions[ i ].logicalOperator || 'AND';

                    if ( operator === 'OR' ) {
                        finalResult = finalResult || results[ i ];
                    } else {
                        // AND por defecto
                        finalResult = finalResult && results[ i ];
                    }
                }

                return finalResult;
            }

            // Estructura legacy: rule.operator + rule.threshold (sin conditions[])
            // Mantener compatibilidad hacia atrás
            if ( rule.operator && rule.threshold !== undefined ) {
                // Buscar campo por rule.fieldId si existe
                const fieldId = rule.fieldId;
                if ( ! fieldId ) {
                    return false;
                }

                const field = pageElement.querySelector(
                    `[data-field-name="${ fieldId }"]`
                );

                if ( ! field ) {
                    return false;
                }

                const fieldValue = this.getFieldValue( field );

                if ( typeof fieldValue === 'number' ) {
                    const threshold = parseFloat( rule.threshold );

                    if ( Number.isNaN( threshold ) ) {
                        return false;
                    }

                    switch ( rule.operator ) {
                        case '>=':
                            return fieldValue >= threshold;
                        case '<=':
                            return fieldValue <= threshold;
                        case '>':
                            return fieldValue > threshold;
                        case '<':
                            return fieldValue < threshold;
                        case '==':
                            return fieldValue === threshold;
                    }
                }
            }

            // Estructura legacy: rule.matchValue o rule.value (sin conditions[])
            if ( rule.matchValue !== undefined || rule.value !== undefined ) {
                const fieldId = rule.fieldId;
                if ( ! fieldId ) {
                    return false;
                }

                const field = pageElement.querySelector(
                    `[data-field-name="${ fieldId }"]`
                );

                if ( ! field ) {
                    return false;
                }

                const fieldValue = this.getFieldValue( field );

                if ( Array.isArray( fieldValue ) ) {
                    for ( const value of fieldValue ) {
                        if (
                            rule.matchValue === value ||
                            rule.value === value
                        ) {
                            return true;
                        }
                    }
                    return false;
                }

                return (
                    rule.matchValue === fieldValue || rule.value === fieldValue
                );
            }

            return false;
        }

        findMatchingRule( rules, fieldValue ) {
            if ( ! Array.isArray( rules ) ) {
                return null;
            }

            // DEPRECATED: Este método se mantiene solo para compatibilidad legacy
            // El nuevo sistema usa evaluateRule() que soporta AND/OR
            // Este método solo se usa si la regla NO tiene conditions[]

            for ( const rule of rules ) {
                if ( rule.operator && rule.threshold !== undefined ) {
                    if ( typeof fieldValue === 'number' ) {
                        const threshold = parseFloat( rule.threshold );

                        if ( Number.isNaN( threshold ) ) {
                            continue;
                        }

                        let matches = false;
                        switch ( rule.operator ) {
                            case '>=':
                                matches = fieldValue >= threshold;
                                break;
                            case '<=':
                                matches = fieldValue <= threshold;
                                break;
                            case '>':
                                matches = fieldValue > threshold;
                                break;
                            case '<':
                                matches = fieldValue < threshold;
                                break;
                            case '==':
                                matches = fieldValue === threshold;
                                break;
                        }

                        if ( matches ) {
                            return rule;
                        }
                    }
                } else if (
                    rule.matchValue !== undefined ||
                    rule.value !== undefined
                ) {
                    if ( Array.isArray( fieldValue ) ) {
                        for ( const value of fieldValue ) {
                            if (
                                rule.matchValue === value ||
                                rule.value === value
                            ) {
                                return rule;
                            }
                        }
                    } else if (
                        rule.matchValue === fieldValue ||
                        rule.value === fieldValue
                    ) {
                        return rule;
                    }
                }
            }

            return null;
        }

        getNextPage( currentPage ) {
            const currentPageElement = EIPSIForms.getPageElement(
                this.form,
                currentPage
            );

            if ( ! currentPageElement ) {
                return { action: 'nextPage', targetPage: currentPage + 1 };
            }

            const conditionalFields = currentPageElement.querySelectorAll(
                '[data-conditional-logic]'
            );

            for ( const field of conditionalFields ) {
                const jsonString = field.dataset.conditionalLogic;
                const parsedLogic = this.parseConditionalLogic( jsonString );
                const conditionalLogic =
                    this.normalizeConditionalLogic( parsedLogic );

                if ( ! conditionalLogic || ! conditionalLogic.enabled ) {
                    continue;
                }

                // Nuevo sistema: evaluar reglas con conditions[] usando evaluateRule()
                if ( ! Array.isArray( conditionalLogic.rules ) ) {
                    continue;
                }

                // Evaluar cada regla en orden
                for ( const rule of conditionalLogic.rules ) {
                    // Usar evaluateRule() que soporta AND/OR
                    const ruleMatches = this.evaluateRule(
                        rule,
                        currentPageElement
                    );

                    if ( ruleMatches ) {
                        if ( rule.action === 'submit' ) {
                            return { action: 'submit' };
                        }

                        if ( rule.action === 'goToPage' && rule.targetPage ) {
                            const targetPage = parseInt( rule.targetPage, 10 );

                            if ( ! Number.isNaN( targetPage ) ) {
                                const totalPages = EIPSIForms.getTotalPages(
                                    this.form
                                );
                                const boundedTarget = Math.min(
                                    Math.max( targetPage, 1 ),
                                    totalPages
                                );
                                const targetElement = EIPSIForms.getPageElement(
                                    this.form,
                                    boundedTarget
                                );

                                if (
                                    targetElement &&
                                    EIPSIForms.isThankYouPageElement(
                                        targetElement
                                    )
                                ) {
                                    return { action: 'submit' };
                                }

                                return {
                                    action: 'goToPage',
                                    targetPage: boundedTarget,
                                    fieldId:
                                        field.id || field.dataset.fieldName,
                                    rule,
                                };
                            }
                        }

                        if ( rule.action === 'nextPage' ) {
                            return {
                                action: 'nextPage',
                                targetPage: currentPage + 1,
                            };
                        }
                    }
                }

                // Si ninguna regla coincidió, usar defaultAction
                if ( conditionalLogic.defaultAction ) {
                    if ( conditionalLogic.defaultAction === 'submit' ) {
                        return { action: 'submit' };
                    }

                    if (
                        conditionalLogic.defaultAction === 'goToPage' &&
                        conditionalLogic.defaultTargetPage
                    ) {
                        const targetPage = parseInt(
                            conditionalLogic.defaultTargetPage,
                            10
                        );

                        if ( ! Number.isNaN( targetPage ) ) {
                            const totalPages = EIPSIForms.getTotalPages(
                                this.form
                            );
                            const boundedTarget = Math.min(
                                Math.max( targetPage, 1 ),
                                totalPages
                            );
                            const targetElement = EIPSIForms.getPageElement(
                                this.form,
                                boundedTarget
                            );

                            if (
                                targetElement &&
                                EIPSIForms.isThankYouPageElement(
                                    targetElement
                                )
                            ) {
                                return { action: 'submit' };
                            }

                            return {
                                action: 'goToPage',
                                targetPage: boundedTarget,
                                isDefault: true,
                            };
                        }
                    }
                }
            }

            return { action: 'nextPage', targetPage: currentPage + 1 };
        }

        shouldSubmit( currentPage ) {
            const result = this.getNextPage( currentPage );
            return result.action === 'submit';
        }

        pushHistory( pageNumber ) {
            if (
                this.history.length === 0 ||
                this.history[ this.history.length - 1 ] !== pageNumber
            ) {
                this.history.push( pageNumber );
                this.visitedPages.add( pageNumber );
            }
        }

        popHistory() {
            if ( this.history.length > 1 ) {
                this.history.pop();
                return this.history[ this.history.length - 1 ];
            }
            return null;
        }

        markSkippedPages( fromPage, toPage ) {
            if ( fromPage === toPage || Math.abs( toPage - fromPage ) === 1 ) {
                return;
            }

            const start = Math.min( fromPage, toPage );
            const end = Math.max( fromPage, toPage );

            for ( let i = start + 1; i < end; i++ ) {
                if ( ! this.visitedPages.has( i ) ) {
                    this.skippedPages.add( i );
                }
            }
        }

        getActivePath() {
            return Array.from( this.visitedPages ).sort( ( a, b ) => a - b );
        }

        isPageSkipped( pageNumber ) {
            return this.skippedPages.has( pageNumber );
        }

        reset() {
            this.history = [];
            this.visitedPages.clear();
            this.skippedPages.clear();
        }
    }

    /**
     * Detecta el tipo de dispositivo basado en user agent
     * @return {string} 'mobile', 'tablet', o 'desktop'
     */
