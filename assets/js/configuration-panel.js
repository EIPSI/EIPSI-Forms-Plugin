/* global jQuery, ajaxurl, eipsiConfigL10n, confirm */
/**
 * EIPSI Forms - Database Configuration Panel JavaScript
 * Handles connection testing, saving, and status updates
 * @param {Object} $ - jQuery object
 */

( function ( $ ) {
    'use strict';

    // Configuration object
    const EIPSIConfig = {
        connectionTested: false,

        init() {
            this.bindEvents();
        },

        bindEvents() {
            $( '#eipsi-test-connection' ).on(
                'click',
                this.testConnection.bind( this )
            );
            $( '#eipsi-db-config-form' ).on(
                'submit',
                this.saveConfiguration.bind( this )
            );
            $( '#eipsi-disable-external-db' ).on(
                'click',
                this.disableExternalDB.bind( this )
            );
            $( '#eipsi-verify-schema' ).on(
                'click',
                this.verifySchema.bind( this )
            );
            $( '#eipsi-verify-local-schema' ).on(
                'click',
                this.verifyLocalSchema.bind( this )
            );
            $( '#eipsi-check-table-status' ).on(
                'click',
                this.checkTableStatus.bind( this )
            );
            $( '#eipsi-check-local-table-status' ).on(
                'click',
                this.checkLocalTableStatus.bind( this )
            );
            $( '#eipsi-delete-all-data' ).on(
                'click',
                this.deleteAllData.bind( this )
            );

            // Enable save button after successful test
            $( '#eipsi-db-config-form input' ).on( 'input', function () {
                EIPSIConfig.connectionTested = false;
                $( '#eipsi-save-config' ).prop( 'disabled', true );
            } );
        },

        testConnection( e ) {
            e.preventDefault();

            // Get form values
            const data = {
                action: 'eipsi_test_db_connection',
                nonce: $( '#eipsi_db_config_nonce' ).val(),
                host: $( '#db_host' ).val(),
                user: $( '#db_user' ).val(),
                password: $( '#db_password' ).val(),
                db_name: $( '#db_name' ).val(),
            };

            // Validate fields
            if ( ! data.host || ! data.user || ! data.db_name ) {
                this.showMessage( 'error', eipsiConfigL10n.fillAllFields );
                return;
            }

            const $button = $( '#eipsi-test-connection' );

            // Show loading state
            $button.prop( 'disabled', true ).addClass( 'eipsi-loading' );
            this.hideMessage();

            // Make AJAX request
            $.ajax( {
                url: ajaxurl,
                type: 'POST',
                data,
                success( response ) {
                    if ( response.success ) {
                        EIPSIConfig.showMessage(
                            'success',
                            response.data.message
                        );
                        EIPSIConfig.updateStatusBox( true, response.data );
                        EIPSIConfig.connectionTested = true;
                        $( '#eipsi-save-config' ).prop( 'disabled', false );
                    } else {
                        EIPSIConfig.showMessage(
                            'error',
                            response.data.message
                        );
                        EIPSIConfig.updateStatusBox( false, {} );
                    }
                },
                error() {
                    EIPSIConfig.showMessage(
                        'error',
                        eipsiConfigL10n.connectionError
                    );
                    EIPSIConfig.updateStatusBox( false, {} );
                },
                complete() {
                    $button
                        .prop( 'disabled', false )
                        .removeClass( 'eipsi-loading' );
                },
            } );
        },

        saveConfiguration( e ) {
            e.preventDefault();

            if ( ! this.connectionTested ) {
                this.showMessage( 'warning', eipsiConfigL10n.testFirst );
                return;
            }

            const $button = $( '#eipsi-save-config' );
            const data = {
                action: 'eipsi_save_db_config',
                nonce: $( '#eipsi_db_config_nonce' ).val(),
                host: $( '#db_host' ).val(),
                user: $( '#db_user' ).val(),
                password: $( '#db_password' ).val(),
                db_name: $( '#db_name' ).val(),
            };

            // Show loading state
            $button.prop( 'disabled', true ).addClass( 'eipsi-loading' );
            this.hideMessage();

            // Make AJAX request
            $.ajax( {
                url: ajaxurl,
                type: 'POST',
                data,
                success( response ) {
                    if ( response.success ) {
                        EIPSIConfig.showMessage(
                            'success',
                            response.data.message
                        );
                        EIPSIConfig.updateStatusBox(
                            true,
                            response.data.status
                        );
                        $( '.nav-tab[href*="tab=database"] .eipsi-setup-tab-warning' ).prop( 'hidden', ! response.data.database_pending );

                        // Clear password field after successful save
                        $( '#db_password' ).val( '' );

                        // Show disable button if not already visible
                        if ( $( '#eipsi-disable-external-db' ).length === 0 ) {
                            $( '.eipsi-form-actions' ).append(
                                '<button type="button" id="eipsi-disable-external-db" class="button button-link-delete">' +
                                    eipsiConfigL10n.disableExternal +
                                    '</button>'
                            );
                            $( '#eipsi-disable-external-db' ).on(
                                'click',
                                EIPSIConfig.disableExternalDB.bind(
                                    EIPSIConfig
                                )
                            );
                        }
                    } else {
                        EIPSIConfig.showMessage(
                            'error',
                            response.data.message
                        );
                    }
                },
                error() {
                    EIPSIConfig.showMessage(
                        'error',
                        eipsiConfigL10n.saveError
                    );
                },
                complete() {
                    $button
                        .prop( 'disabled', false )
                        .removeClass( 'eipsi-loading' );
                },
            } );
        },

        disableExternalDB( e ) {
            e.preventDefault();

            // eslint-disable-next-line no-alert
            if ( ! confirm( eipsiConfigL10n.confirmDisable ) ) {
                return;
            }

            const $button = $( e.currentTarget );

            // Show loading state
            $button.prop( 'disabled', true );

            // Make AJAX request
            $.ajax( {
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'eipsi_disable_external_db',
                    nonce: $( '#eipsi_db_config_nonce' ).val(),
                },
                success( response ) {
                    if ( response.success ) {
                        EIPSIConfig.showMessage(
                            'success',
                            response.data.message
                        );
                        EIPSIConfig.updateStatusBox( false, {} );
                        $( '.nav-tab[href*="tab=database"] .eipsi-setup-tab-warning' ).prop( 'hidden', ! response.data.database_pending );
                        $button.remove();
                        EIPSIConfig.connectionTested = false;
                        $( '#eipsi-save-config' ).prop( 'disabled', true );
                    } else {
                        EIPSIConfig.showMessage(
                            'error',
                            response.data.message
                        );
                    }
                },
                error() {
                    EIPSIConfig.showMessage(
                        'error',
                        eipsiConfigL10n.disableError
                    );
                },
                complete() {
                    $button.prop( 'disabled', false );
                },
            } );
        },

        verifySchema( e ) {
            e.preventDefault();

            const $button = $( '#eipsi-verify-schema' );

            // Show loading state
            $button.prop( 'disabled', true ).addClass( 'eipsi-loading' );
            this.hideMessage();

            // Make AJAX request
            $.ajax( {
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'eipsi_verify_schema',
                    nonce: $( '#eipsi_db_config_nonce' ).val(),
                },
                success( response ) {
                    if ( response.success ) {
                        const results = response.data.results;
                        let message = '✅ Schema verification completed!\n\n';

                        if (
                            results.results_table.created ||
                            results.events_table.created
                        ) {
                            message += 'Tables created:\n';
                            if ( results.results_table.created ) {
                                message += '  • wp_vas_form_results\n';
                            }
                            if ( results.events_table.created ) {
                                message += '  • wp_vas_form_events\n';
                            }
                            message += '\n';
                        }

                        const columnsAdded =
                            results.results_table.columns_added.length +
                            results.events_table.columns_added.length;
                        if ( columnsAdded > 0 ) {
                            message += `Columns synchronized: ${ columnsAdded }\n`;
                        }

                        if (
                            ! results.results_table.created &&
                            ! results.events_table.created &&
                            columnsAdded === 0
                        ) {
                            message += 'All tables and columns are up to date!';
                        }

                        EIPSIConfig.showMessage( 'success', message );

                        // Reload page to refresh schema status
                        setTimeout( function () {
                            window.location.reload();
                        }, 2000 );
                    } else {
                        EIPSIConfig.showMessage(
                            'error',
                            'Schema verification failed: ' +
                                response.data.message
                        );
                    }
                },
                error() {
                    EIPSIConfig.showMessage(
                        'error',
                        'Failed to verify schema'
                    );
                },
                complete() {
                    $button
                        .prop( 'disabled', false )
                        .removeClass( 'eipsi-loading' );
                },
            } );
        },

        verifyLocalSchema( e ) {
            e.preventDefault();

            const $button = $( '#eipsi-verify-local-schema' );
            const $resultsContainer = $( '#eipsi-local-schema-results' );
            const $resultsContent = $( '#eipsi-local-schema-content' );
            const originalButtonHtml = $button.html();

            // Show loading state - spinner + text
            $button
                .prop( 'disabled', true )
                .addClass( 'eipsi-loading' )
                .html( '<span class="dashicons dashicons-update-alt eipsi-spin"></span> Verificando esquema...' );
            $resultsContainer.hide();

            // Make AJAX request
            $.ajax( {
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'eipsi_verify_local_schema',
                    nonce: $( '#eipsi_db_config_nonce' ).val(),
                },
                success( response ) {
                    if ( response.success ) {
                        let html = '<div class="eipsi-schema-success" style="padding: 12px; background: #e8f5e8; border-radius: 4px; margin-bottom: 12px; border-left: 4px solid #46b450;">';
                        html += '<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">';
                        html += '<span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 20px; width: 20px; height: 20px;"></span> ';
                        html += '<strong style="font-size: 14px; color: #2d5a2d;">' + ( response.data.message || 'Esquema verificado correctamente' ) + '</strong>';
                        html += '</div>';
                        html += '</div>';

                        // Summary stats
                        const tablesCreated = response.data.tables_created ? response.data.tables_created.length : 0;
                        const columnsAdded = response.data.columns_added || 0;

                        html += '<div style="background: #fff; padding: 12px; border-radius: 4px; border: 1px solid #ddd;">';
                        html += '<table style="width: 100%; font-size: 13px;">';
                        html += '<tr><td style="padding: 4px 0; color: #666;">Tablas creadas:</td><td style="padding: 4px 0; font-weight: 600; text-align: right;">' + tablesCreated + '</td></tr>';
                        html += '<tr><td style="padding: 4px 0; color: #666;">Columnas agregadas:</td><td style="padding: 4px 0; font-weight: 600; text-align: right;">' + columnsAdded + '</td></tr>';
                        html += ("<tr><td style=\"padding: 4px 0; color: #666;\">"+window.EIPSIAdminI18n.h("Status:")+"</td><td style=\"padding: 4px 0; font-weight: 600; color: #46b450; text-align: right;\">✓ OK</td></tr>");
                        html += '</table>';

                        // Show tables created details
                        if ( response.data.tables_created && response.data.tables_created.length > 0 ) {
                            html += '<div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee;">';
                            html += '<strong style="font-size: 12px; color: #666; text-transform: uppercase;">Tablas creadas:</strong>';
                            html += '<ul style="margin: 6px 0 0 0; padding-left: 18px; font-size: 12px; color: #444;">';
                            response.data.tables_created.forEach( function( table ) {
                                html += '<li style="margin: 2px 0;">' + table + '</li>';
                            } );
                            html += '</ul></div>';
                        }

                        // Show columns added details
                        if ( columnsAdded > 0 ) {
                            html += '<div style="margin-top: 8px; font-size: 12px; color: #46b450;">';
                            html += '<span class="dashicons dashicons-plus-alt" style="font-size: 12px; width: 12px; height: 12px;"></span> ';
                            html += columnsAdded + ' columna(s) agregada(s) exitosamente';
                            html += '</div>';
                        }

                        html += '</div>';

                        // Show last verified timestamp
                        if ( response.data.last_verified ) {
                            html += '<div style="margin-top: 10px; color: #888; font-size: 11px; text-align: right;">';
                            html += '<span class="dashicons dashicons-clock" style="font-size: 11px; width: 11px; height: 11px;"></span> ';
                            html += 'Última verificación: ' + response.data.last_verified;
                            html += '</div>';
                        }

                        $resultsContent.html( html );
                        $resultsContainer.show();

                        // Reload page after 3 seconds to show updated status
                        setTimeout( function () {
                            window.location.reload();
                        }, 3000 );
                    } else {
                        let html = '<div class="eipsi-schema-error" style="padding: 12px; background: #ffebee; border-radius: 4px; color: #d32f2f; border-left: 4px solid #d32f2f; margin-bottom: 12px;">';
                        html += '<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">';
                        html += '<span class="dashicons dashicons-dismiss" style="font-size: 20px; width: 20px; height: 20px;"></span> ';
                        html += '<strong style="font-size: 14px;">' + ( response.data.message || 'Error en verificación' ) + '</strong>';
                        html += '</div>';
                        if ( response.data.errors && response.data.errors.length > 0 ) {
                            html += '<ul style="margin: 8px 0 0 0; padding-left: 20px; font-size: 12px;">';
                            response.data.errors.forEach( function( error ) {
                                html += '<li style="margin: 2px 0;">' + error + '</li>';
                            } );
                            html += '</ul>';
                        }
                        html += '</div>';
                        $resultsContent.html( html );
                        $resultsContainer.show();
                    }
                },
                error() {
                    let html = '<div class="eipsi-schema-error" style="padding: 12px; background: #ffebee; border-radius: 4px; color: #d32f2f; border-left: 4px solid #d32f2f;">';
                    html += '<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">';
                    html += '<span class="dashicons dashicons-dismiss" style="font-size: 20px; width: 20px; height: 20px;"></span> ';
                    html += '<strong style="font-size: 14px;">Error al verificar el esquema</strong>';
                    html += '</div>';
                    html += '<p style="margin: 0; font-size: 13px;">No se pudo completar la verificación. Por favor, intenta nuevamente o contacta soporte si el problema persiste.</p>';
                    html += '</div>';
                    $resultsContent.html( html );
                    $resultsContainer.show();
                },
                complete() {
                    $button
                        .prop( 'disabled', false )
                        .removeClass( 'eipsi-loading' )
                        .html( originalButtonHtml );
                },
            } );
        },

        checkTableStatus( e ) {
            e.preventDefault();

            const $button = $( '#eipsi-check-table-status' );
            const $resultsContainer = $( '#eipsi-table-status-results' );

            // Show loading state
            $button.prop( 'disabled', true ).addClass( 'eipsi-loading' );
            $resultsContainer.hide();

            // Make AJAX request
            $.ajax( {
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'eipsi_check_table_status',
                    nonce: $( '#eipsi_db_config_nonce' ).val(),
                },
                success( response ) {
                    if ( response.success ) {
                        EIPSIConfig.displayTableStatus( response.data );
                    } else {
                        $resultsContainer
                            .html(
                                '<div class="eipsi-table-status-error">' +
                                    '<span class="dashicons dashicons-warning" style="color: #d32f2f;"></span>' +
                                    '<span>' +
                                    ( response.data.message ||
                                        'Failed to check table status' ) +
                                    '</span>' +
                                    '</div>'
                            )
                            .show();
                    }
                },
                error() {
                    $resultsContainer
                        .html(
                            '<div class="eipsi-table-status-error">' +
                                '<span class="dashicons dashicons-warning" style="color: #d32f2f;"></span>' +
                                '<span>Failed to check table status. Please try again.</span>' +
                                '</div>'
                        )
                        .show();
                },
                complete() {
                    $button
                        .prop( 'disabled', false )
                        .removeClass( 'eipsi-loading' );
                },
                } );
                },

                checkLocalTableStatus( e ) {
                e.preventDefault();

                const $button = $( '#eipsi-check-local-table-status' );
                const $resultsContainer = $( '#eipsi-table-status-results' );

                // Show loading state
                $button.prop( 'disabled', true ).addClass( 'eipsi-loading' );
                $resultsContainer.hide();

                // Make AJAX request
                $.ajax( {
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'eipsi_check_local_table_status',
                    nonce: $( '#eipsi_db_config_nonce' ).val(),
                },
                success( response ) {
                    if ( response.success ) {
                        EIPSIConfig.displayLocalTableStatus( response.data );
                    } else {
                        $resultsContainer
                            .html(
                                '<div class="eipsi-table-status-error" style="padding: 12px; background: #ffebee; border-radius: 4px; color: #d32f2f;">' +
                                '<span class="dashicons dashicons-warning"></span> ' +
                                '<span>' +
                                ( response.data.message ||
                                    'Error al verificar el estado de las tablas' ) +
                                '</span>' +
                                '</div>'
                            )
                            .show();
                    }
                },
                error() {
                    $resultsContainer
                        .html(
                            '<div class="eipsi-table-status-error" style="padding: 12px; background: #ffebee; border-radius: 4px; color: #d32f2f;">' +
                            '<span class="dashicons dashicons-warning"></span> ' +
                            '<span>Error al verificar el estado. Intenta nuevamente.</span>' +
                            '</div>'
                        )
                        .show();
                },
                complete() {
                    $button
                        .prop( 'disabled', false )
                        .removeClass( 'eipsi-loading' );
                },
                } );
                },

                displayTableStatus( data ) {
            const $resultsContainer = $( '#eipsi-table-status-results' );
            let html = '';

            // Overall status indicator
            if ( data.all_tables_exist && data.all_columns_ok ) {
                html +=
                    '<div class="eipsi-table-status-success">' +
                    '<span class="dashicons dashicons-yes-alt" style="color: #198754;"></span>' +
                    '<strong>' +
                    data.message +
                    '</strong>' +
                    '</div>';
            } else {
                html +=
                    '<div class="eipsi-table-status-warning">' +
                    '<span class="dashicons dashicons-warning" style="color: #b35900;"></span>' +
                    '<strong>' +
                    data.message +
                    '</strong>' +
                    '</div>';
            }

            // Results table details
            if ( data.results_table ) {
                html +=
                    '<div class="eipsi-table-detail">' +
                    '<h4>' +
                    '<span class="dashicons dashicons-database"></span>' +
                    ' Results Table: ' +
                    data.results_table.table_name +
                    '</h4>';

                if ( data.results_table.exists ) {
                    html +=
                        '<div class="eipsi-table-exists">' +
                        '<span class="dashicons dashicons-yes-alt" style="color: #198754;"></span>' +
                        ' Table exists' +
                        '</div>';
                    html +=
                        '<div class="eipsi-table-info">' +
                        ("<span class=\"detail-label\">"+window.EIPSIAdminI18n.h("Records:")+"</span> ") +
                        '<span class="detail-value">' +
                        data.results_table.row_count.toLocaleString() +
                        '</span>' +
                        '</div>';

                    if ( data.results_table.columns_ok ) {
                        html +=
                            '<div class="eipsi-table-info">' +
                            '<span class="detail-label">Schema:</span> ' +
                            '<span class="detail-value" style="color: #198754;">✓ All columns present</span>' +
                            '</div>';
                    } else {
                        html +=
                            '<div class="eipsi-table-warning">' +
                            '<span class="dashicons dashicons-warning" style="color: #b35900;"></span>' +
                            ' Missing columns: ' +
                            data.results_table.missing_columns.join( ', ' ) +
                            '</div>';
                    }
                } else {
                    html +=
                        '<div class="eipsi-table-missing">' +
                        '<span class="dashicons dashicons-dismiss" style="color: #d32f2f;"></span>' +
                        ' Table does not exist' +
                        '</div>';
                }

                html += '</div>';
            }

            // Events table details
            if ( data.events_table ) {
                html +=
                    '<div class="eipsi-table-detail">' +
                    '<h4>' +
                    '<span class="dashicons dashicons-database"></span>' +
                    ' Events Table: ' +
                    data.events_table.table_name +
                    '</h4>';

                if ( data.events_table.exists ) {
                    html +=
                        '<div class="eipsi-table-exists">' +
                        '<span class="dashicons dashicons-yes-alt" style="color: #198754;"></span>' +
                        ' Table exists' +
                        '</div>';
                    html +=
                        '<div class="eipsi-table-info">' +
                        ("<span class=\"detail-label\">"+window.EIPSIAdminI18n.h("Records:")+"</span> ") +
                        '<span class="detail-value">' +
                        data.events_table.row_count.toLocaleString() +
                        '</span>' +
                        '</div>';

                    if ( data.events_table.columns_ok ) {
                        html +=
                            '<div class="eipsi-table-info">' +
                            '<span class="detail-label">Schema:</span> ' +
                            '<span class="detail-value" style="color: #198754;">✓ All columns present</span>' +
                            '</div>';
                    } else {
                        html +=
                            '<div class="eipsi-table-warning">' +
                            '<span class="dashicons dashicons-warning" style="color: #b35900;"></span>' +
                            ' Missing columns: ' +
                            data.events_table.missing_columns.join( ', ' ) +
                            '</div>';
                    }
                } else {
                    html +=
                        '<div class="eipsi-table-missing">' +
                        '<span class="dashicons dashicons-dismiss" style="color: #d32f2f;"></span>' +
                        ' Table does not exist' +
                        '</div>';
                }

                html += '</div>';
            }

            // Show guidance if tables are missing
            if ( ! data.all_tables_exist || ! data.all_columns_ok ) {
                html +=
                    '<div class="eipsi-table-guidance">' +
                    '<h4>' +
                    '<span class="dashicons dashicons-info"></span>' +
                    ' What to do next' +
                    '</h4>' +
                    '<p>The plugin should automatically create required tables when you save the database configuration or submit a form.</p>' +
                    '<p><strong>To manually create or repair tables:</strong></p>' +
                    '<ol>' +
                    '<li>Click the <strong>"Verify & Repair Schema"</strong> button above</li>' +
                    '<li>This will create missing tables and add any missing columns</li>' +
                    '<li>Then click <strong>"Check Table Status"</strong> again to verify</li>' +
                    '</ol>' +
                    '<p style="margin-top: 10px;"><strong>Why this might happen:</strong></p>' +
                    '<ul>' +
                    '<li>First time connecting to this database</li>' +
                    '<li>Database user lacks CREATE TABLE permissions</li>' +
                    '<li>Manual database migration without schema sync</li>' +
                    '</ul>' +
                    '</div>';
            }

            $resultsContainer.html( html ).show();
            },

            displayLocalTableStatus( data ) {
            const $resultsContainer = $( '#eipsi-table-status-results' );
            let html = '';

            // Overall status indicator
            if ( data.all_tables_exist && data.all_columns_ok ) {
                html +=
                    '<div style="padding: 12px; background: #e8f5e8; border-radius: 4px; margin-bottom: 15px;">' +
                    '<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> ' +
                    '<strong>' + data.message + '</strong>' +
                    '</div>';
            } else {
                html +=
                    '<div style="padding: 12px; background: #fff3cd; border-radius: 4px; margin-bottom: 15px;">' +
                    '<span class="dashicons dashicons-warning" style="color: #f0b849;"></span> ' +
                    '<strong>' + data.message + '</strong>' +
                    '</div>';
            }

            // Database info
            html += '<div style="margin-bottom: 15px;">';
            html += ("<strong>"+window.EIPSIAdminI18n.h("Database:")+"</strong> ") + data.db_name + ' @ ' + data.db_host + '<br>';
            html += '<strong>Total registros:</strong> ' + data.total_records.toLocaleString() + '<br>';
            if ( data.last_verified ) {
                html += ("<strong>"+window.EIPSIAdminI18n.h("Last verification:")+"</strong> ") + data.last_verified;
            }
            html += '</div>';

            // Core tables
            html += '<h4 style="margin-top: 20px; margin-bottom: 10px;">Tablas principales</h4>';
            html += '<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">';
            html += '<thead><tr style="background: #f0f0f0;">';
            html += '<th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Tabla</th>';
            html += ("<th style=\"padding: 8px; text-align: center; border: 1px solid #ddd;\">"+window.EIPSIAdminI18n.h("Status")+"</th>");
            html += '<th style="padding: 8px; text-align: right; border: 1px solid #ddd;">Registros</th>';
            html += '</tr></thead><tbody>';

            // Results table
            if ( data.results_table ) {
                html += '<tr>';
                html += '<td style="padding: 8px; border: 1px solid #ddd;">' + data.results_table.table_name + '</td>';
                if ( data.results_table.exists ) {
                    html += '<td style="padding: 8px; text-align: center; border: 1px solid #ddd;"><span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span></td>';
                    html += '<td style="padding: 8px; text-align: right; border: 1px solid #ddd;">' + data.results_table.row_count.toLocaleString() + '</td>';
                } else {
                    html += '<td style="padding: 8px; text-align: center; border: 1px solid #ddd;"><span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span></td>';
                    html += '<td style="padding: 8px; text-align: right; border: 1px solid #ddd;">-</td>';
                }
                html += '</tr>';
            }

            // Events table
            if ( data.events_table ) {
                html += '<tr>';
                html += '<td style="padding: 8px; border: 1px solid #ddd;">' + data.events_table.table_name + '</td>';
                if ( data.events_table.exists ) {
                    html += '<td style="padding: 8px; text-align: center; border: 1px solid #ddd;"><span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span></td>';
                    html += '<td style="padding: 8px; text-align: right; border: 1px solid #ddd;">' + data.events_table.row_count.toLocaleString() + '</td>';
                } else {
                    html += '<td style="padding: 8px; text-align: center; border: 1px solid #ddd;"><span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span></td>';
                    html += '<td style="padding: 8px; text-align: right; border: 1px solid #ddd;">-</td>';
                }
                html += '</tr>';
            }

            // RCT Configs
            if ( data.randomization_configs_table ) {
                html += '<tr>';
                html += '<td style="padding: 8px; border: 1px solid #ddd;">' + data.randomization_configs_table.table_name + '</td>';
                if ( data.randomization_configs_table.exists ) {
                    html += '<td style="padding: 8px; text-align: center; border: 1px solid #ddd;"><span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span></td>';
                    html += '<td style="padding: 8px; text-align: right; border: 1px solid #ddd;">' + data.randomization_configs_table.row_count.toLocaleString() + '</td>';
                } else {
                    html += '<td style="padding: 8px; text-align: center; border: 1px solid #ddd;"><span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span></td>';
                    html += '<td style="padding: 8px; text-align: right; border: 1px solid #ddd;">-</td>';
                }
                html += '</tr>';
            }

            // RCT Assignments
            if ( data.randomization_assignments_table ) {
                html += '<tr>';
                html += '<td style="padding: 8px; border: 1px solid #ddd;">' + data.randomization_assignments_table.table_name + '</td>';
                if ( data.randomization_assignments_table.exists ) {
                    html += '<td style="padding: 8px; text-align: center; border: 1px solid #ddd;"><span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span></td>';
                    html += '<td style="padding: 8px; text-align: right; border: 1px solid #ddd;">' + data.randomization_assignments_table.row_count.toLocaleString() + '</td>';
                } else {
                    html += '<td style="padding: 8px; text-align: center; border: 1px solid #ddd;"><span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span></td>';
                    html += '<td style="padding: 8px; text-align: right; border: 1px solid #ddd;">-</td>';
                }
                html += '</tr>';
            }

            html += '</tbody></table>';

            // Longitudinal tables
            if ( data.longitudinal_tables ) {
                html += '<h4 style="margin-top: 20px; margin-bottom: 10px;">Tablas longitudinales (v1.4.0+)</h4>';
                html += '<table style="width: 100%; border-collapse: collapse;">';
                html += '<thead><tr style="background: #f0f0f0;">';
                html += '<th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Tabla</th>';
                html += ("<th style=\"padding: 8px; text-align: center; border: 1px solid #ddd;\">"+window.EIPSIAdminI18n.h("Status")+"</th>");
                html += '<th style="padding: 8px; text-align: right; border: 1px solid #ddd;">Registros</th>';
                html += '</tr></thead><tbody>';

                const tableLabels = {
                    survey_studies: window.EIPSIAdminI18n.t("Studies"),
                    survey_participants: window.EIPSIAdminI18n.t("Participants"),
                    survey_sessions: 'Sesiones',
                    survey_waves: window.EIPSIAdminI18n.t("Waves"),
                    survey_assignments: window.EIPSIAdminI18n.t("Assignments"),
                    survey_magic_links: window.EIPSIAdminI18n.t("Magic Links"),
                    survey_email_log: 'Email Log',
                    survey_audit_log: 'Audit Log'
                };

                for ( const [ key, tableInfo ] of Object.entries( data.longitudinal_tables ) ) {
                    html += '<tr>';
                    html += '<td style="padding: 8px; border: 1px solid #ddd;">' + ( tableLabels[ key ] || key ) + '</td>';
                    if ( tableInfo.exists ) {
                        html += '<td style="padding: 8px; text-align: center; border: 1px solid #ddd;"><span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span></td>';
                        html += '<td style="padding: 8px; text-align: right; border: 1px solid #ddd;">' + tableInfo.row_count.toLocaleString() + '</td>';
                    } else {
                        html += '<td style="padding: 8px; text-align: center; border: 1px solid #ddd;"><span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span></td>';
                        html += '<td style="padding: 8px; text-align: right; border: 1px solid #ddd;">-</td>';
                    }
                    html += '</tr>';
                }

                html += '</tbody></table>';
            }

            $resultsContainer.html( html ).show();
            },

            showMessage( type, message ) {
            const $container = $( '#eipsi-message-container' );
            $container
                .removeClass( 'success error warning' )
                .addClass( type )
                .text( message )
                .show();

            // Auto-hide success messages after 5 seconds
            if ( type === 'success' ) {
                setTimeout( function () {
                    $container.fadeOut();
                }, 5000 );
            }
        },

        hideMessage() {
            $( '#eipsi-message-container' ).hide();
        },

        deleteAllData( e ) {
            e.preventDefault();

            // Custom styled confirmation dialog
            const confirmationHTML = `
                <div id="eipsi-delete-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 999999;">
                    <div style="position: relative; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; max-width: 500px; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
                        <h2 style="color: #d32f2f; margin-top: 0; font-size: 20px;">
                            <span class="dashicons dashicons-warning" style="font-size: 24px; vertical-align: middle;"></span>
                            ${ eipsiConfigL10n.confirmDeleteTitle }
                        </h2>
                        <p style="white-space: pre-line; line-height: 1.6; font-size: 15px; color: #333;">
                            ${ eipsiConfigL10n.confirmDeleteMessage }
                        </p>
                        <div style="margin-top: 25px; text-align: right;">
                            <button id="eipsi-delete-cancel" class="button" style="margin-right: 10px;">
                                ${ eipsiConfigL10n.confirmDeleteNo }
                            </button>
                            <button id="eipsi-delete-confirm" class="button" style="background: #d32f2f; color: white; border-color: #b71c1c; padding: 6px 20px; font-weight: 600;">
                                ${ eipsiConfigL10n.confirmDeleteYes }
                            </button>
                        </div>
                    </div>
                </div>
            `;

            // Add modal to page
            $( 'body' ).append( confirmationHTML );
            $( '#eipsi-delete-modal' ).fadeIn( 200 );

            // Cancel button
            $( '#eipsi-delete-cancel' ).on( 'click', function () {
                $( '#eipsi-delete-modal' ).fadeOut( 200, function () {
                    $( this ).remove();
                } );
            } );

            // Confirm button
            $( '#eipsi-delete-confirm' ).on( 'click', function () {
                $( '#eipsi-delete-modal' ).fadeOut( 200, function () {
                    $( this ).remove();
                } );
                EIPSIConfig.executeDeleteAllData();
            } );

            // Close on background click
            $( '#eipsi-delete-modal' ).on( 'click', function ( e ) {
                if ( e.target === this ) {
                    $( this ).fadeOut( 200, function () {
                        $( this ).remove();
                    } );
                }
            } );
        },

        executeDeleteAllData() {
            const $button = $( '#eipsi-delete-all-data' );

            // Show loading state
            $button.prop( 'disabled', true ).addClass( 'eipsi-loading' );
            this.hideMessage();

            // Make AJAX request
            $.ajax( {
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'eipsi_delete_all_data',
                    nonce: $( '#eipsi-delete-data-nonce' ).val(),
                },
                success( response ) {
                    if ( response.success ) {
                        EIPSIConfig.showMessage(
                            'success',
                            response.data.message
                        );

                        // Reload page after 2 seconds to refresh all stats
                        setTimeout( function () {
                            window.location.reload();
                        }, 2000 );
                    } else {
                        EIPSIConfig.showMessage(
                            'error',
                            response.data.message || eipsiConfigL10n.deleteError
                        );
                    }
                },
                error() {
                    EIPSIConfig.showMessage(
                        'error',
                        eipsiConfigL10n.deleteError
                    );
                },
                complete() {
                    $button
                        .prop( 'disabled', false )
                        .removeClass( 'eipsi-loading' );
                },
            } );
        },

        updateStatusBox( connected, data ) {
            const $statusBox = $( '#eipsi-status-box' );

            if ( connected ) {
                $statusBox.html(
                    '<div class="eipsi-status-indicator">' +
                        '<span class="status-icon status-connected"></span>' +
                        '<span class="status-text">' +
                        eipsiConfigL10n.connected +
                        '</span>' +
                        '</div>' +
                        '<div class="eipsi-status-details">' +
                        '<div class="status-detail-row">' +
                        '<span class="detail-label">' +
                        eipsiConfigL10n.currentDatabase +
                        '</span>' +
                        '<span class="detail-value">' +
                        ( data.db_name || '' ) +
                        '</span>' +
                        '</div>' +
                        '<div class="status-detail-row">' +
                        '<span class="detail-label">' +
                        eipsiConfigL10n.records +
                        '</span>' +
                        '<span class="detail-value">' +
                        ( data.record_count || 0 ) +
                        '</span>' +
                        '</div>' +
                        '</div>'
                );
            } else {
                $statusBox.html(
                    '<div class="eipsi-status-indicator">' +
                        '<span class="status-icon status-disconnected"></span>' +
                        '<span class="status-text">' +
                        eipsiConfigL10n.disconnected +
                        '</span>' +
                        '</div>' +
                        '<div class="eipsi-status-message">' +
                        '<p>' +
                        eipsiConfigL10n.noExternalDB +
                        '</p>' +
                        '</div>'
                );
            }
        },
    };

    const EIPSISmtpConfig = {
        smtpTested: false,

        init() {
            this.bindEvents();
        },

        bindEvents() {
            $( '#eipsi-test-smtp' ).on(
                'click',
                this.testSmtp.bind( this )
            );
            $( '#eipsi-smtp-config-form' ).on(
                'submit',
                this.saveSmtpConfig.bind( this )
            );
            $( '#eipsi-disable-smtp' ).on(
                'click',
                this.disableSmtp.bind( this )
            );

            $( '#eipsi-smtp-config-form input, #eipsi-smtp-config-form select' ).on(
                'input change',
                function () {
                    EIPSISmtpConfig.smtpTested = false;
                    $( '#eipsi-save-smtp-config' ).prop( 'disabled', true );
                }
            );
        },

        testSmtp( e ) {
            e.preventDefault();

            const data = {
                action: 'eipsi_test_smtp_connection',
                nonce: $( '#eipsi_smtp_config_nonce' ).val(),
                host: $( '#smtp_host' ).val(),
                port: $( '#smtp_port' ).val(),
                user: $( '#smtp_user' ).val(),
                password: $( '#smtp_password' ).val(),
                encryption: $( '#smtp_encryption' ).val(),
            };

            if ( ! data.host || ! data.port || ! data.user ) {
                this.showMessage( 'warning', eipsiConfigL10n.smtpFillAllFields );
                return;
            }

            const $button = $( '#eipsi-test-smtp' );
            $button.prop( 'disabled', true ).addClass( 'eipsi-loading' );
            this.hideMessage();

            $.ajax( {
                url: ajaxurl,
                type: 'POST',
                data,
                success( response ) {
                    if ( response.success ) {
                        EIPSISmtpConfig.showMessage(
                            'success',
                            response.data.message
                        );
                        EIPSISmtpConfig.updateStatusBox(
                            true,
                            response.data.status
                        );
                        EIPSISmtpConfig.smtpTested = true;
                        $( '#eipsi-save-smtp-config' ).prop(
                            'disabled',
                            false
                        );
                    } else {
                        EIPSISmtpConfig.showMessage(
                            'error',
                            response.data.message
                        );
                    }
                },
                error() {
                    EIPSISmtpConfig.showMessage(
                        'error',
                        eipsiConfigL10n.smtpTestError
                    );
                },
                complete() {
                    $button
                        .prop( 'disabled', false )
                        .removeClass( 'eipsi-loading' );
                },
            } );
        },

        saveSmtpConfig( e ) {
            e.preventDefault();

            if ( ! this.smtpTested ) {
                this.showMessage( 'warning', eipsiConfigL10n.smtpTestFirst );
                return;
            }

            const $button = $( '#eipsi-save-smtp-config' );
            const data = {
                action: 'eipsi_save_smtp_config',
                nonce: $( '#eipsi_smtp_config_nonce' ).val(),
                host: $( '#smtp_host' ).val(),
                port: $( '#smtp_port' ).val(),
                user: $( '#smtp_user' ).val(),
                password: $( '#smtp_password' ).val(),
                encryption: $( '#smtp_encryption' ).val(),
            };

            $button.prop( 'disabled', true ).addClass( 'eipsi-loading' );
            this.hideMessage();

            $.ajax( {
                url: ajaxurl,
                type: 'POST',
                data,
                success( response ) {
                    if ( response.success ) {
                        EIPSISmtpConfig.showMessage(
                            'success',
                            response.data.message
                        );
                        EIPSISmtpConfig.updateStatusBox(
                            true,
                            response.data.status
                        );
                        $( '#smtp_password' ).val( '' );

                        if ( $( '#eipsi-disable-smtp' ).length === 0 ) {
                            $( '#eipsi-save-smtp-config' )
                                .closest( '.eipsi-form-actions' )
                                .append(
                                    '<button type="button" id="eipsi-disable-smtp" class="button button-link-delete">' +
                                        eipsiConfigL10n.smtpDisableLabel +
                                        '</button>'
                                );
                            $( '#eipsi-disable-smtp' ).on(
                                'click',
                                EIPSISmtpConfig.disableSmtp.bind(
                                    EIPSISmtpConfig
                                )
                            );
                        }
                    } else {
                        EIPSISmtpConfig.showMessage(
                            'error',
                            response.data.message
                        );
                    }
                },
                error() {
                    EIPSISmtpConfig.showMessage(
                        'error',
                        eipsiConfigL10n.smtpSaveError
                    );
                },
                complete() {
                    $button
                        .prop( 'disabled', false )
                        .removeClass( 'eipsi-loading' );
                },
            } );
        },

        disableSmtp( e ) {
            e.preventDefault();

            // eslint-disable-next-line no-alert
            if ( ! confirm( eipsiConfigL10n.smtpConfirmDisable ) ) {
                return;
            }

            const $button = $( e.currentTarget );
            $button.prop( 'disabled', true );

            $.ajax( {
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'eipsi_disable_smtp',
                    nonce: $( '#eipsi_smtp_config_nonce' ).val(),
                },
                success( response ) {
                    if ( response.success ) {
                        EIPSISmtpConfig.showMessage(
                            'success',
                            response.data.message
                        );
                        EIPSISmtpConfig.updateStatusBox( false, {} );
                        $button.remove();
                        EIPSISmtpConfig.smtpTested = false;
                        $( '#eipsi-save-smtp-config' ).prop(
                            'disabled',
                            true
                        );
                    } else {
                        EIPSISmtpConfig.showMessage(
                            'error',
                            response.data.message
                        );
                    }
                },
                error() {
                    EIPSISmtpConfig.showMessage(
                        'error',
                        eipsiConfigL10n.smtpDisableError
                    );
                },
                complete() {
                    $button.prop( 'disabled', false );
                },
            } );
        },

        updateStatusBox( enabled, data ) {
            const $statusBox = $( '#eipsi-smtp-status-box' );

            if ( enabled ) {
                $statusBox.html(
                    '<div class="eipsi-status-indicator">' +
                        '<span class="status-icon status-connected"></span>' +
                        '<span class="status-text">' +
                        eipsiConfigL10n.smtpActive +
                        '</span>' +
                        '</div>' +
                        '<div class="eipsi-status-details">' +
                        '<div class="status-detail-row">' +
                        '<span class="detail-label">' +
                        eipsiConfigL10n.smtpHostLabel +
                        '</span>' +
                        '<span class="detail-value">' +
                        ( data.host || '' ) +
                        '</span>' +
                        '</div>' +
                        '<div class="status-detail-row">' +
                        '<span class="detail-label">' +
                        eipsiConfigL10n.smtpPortLabel +
                        '</span>' +
                        '<span class="detail-value">' +
                        ( data.port || '' ) +
                        '</span>' +
                        '</div>' +
                        '<div class="status-detail-row">' +
                        '<span class="detail-label">' +
                        eipsiConfigL10n.smtpUserLabel +
                        '</span>' +
                        '<span class="detail-value">' +
                        ( data.user || '' ) +
                        '</span>' +
                        '</div>' +
                        '<div class="status-detail-row">' +
                        '<span class="detail-label">' +
                        eipsiConfigL10n.smtpEncryptionLabel +
                        '</span>' +
                        '<span class="detail-value">' +
                        ( data.encryption_label || data.encryption || '' ) +
                        '</span>' +
                        '</div>' +
                        '</div>'
                );
                return;
            }

            $statusBox.html(
                '<div class="eipsi-status-indicator">' +
                    '<span class="status-icon status-disconnected"></span>' +
                    '<span class="status-text">' +
                    eipsiConfigL10n.smtpInactive +
                    '</span>' +
                    '</div>' +
                    '<div class="eipsi-status-message">' +
                    '<p>' +
                    eipsiConfigL10n.smtpNoConfig +
                    '</p>' +
                    '</div>'
            );
        },

        showMessage( type, message ) {
            const $container = $( '#eipsi-smtp-message-container' );
            $container
                .removeClass( 'success error warning' )
                .addClass( type )
                .text( message )
                .show();

            if ( type === 'success' ) {
                setTimeout( function () {
                    $container.fadeOut();
                }, 5000 );
            }
        },

        hideMessage() {
            $( '#eipsi-smtp-message-container' ).hide();
        },
    };

    // Initialize on document ready
    $( document ).ready( function () {
        if ( $( '#eipsi-db-config-form' ).length ) {
            EIPSIConfig.init();
        }

        if ( $( '#eipsi-smtp-config-form' ).length ) {
            EIPSISmtpConfig.init();
        }
    } );
} )( jQuery );
