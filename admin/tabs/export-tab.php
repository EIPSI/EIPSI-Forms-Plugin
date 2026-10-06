<?php
/**
 * Export Tab — Participant Data Export
 *
 * Allows investigators to export the participant roster (with wave-completion
 * progress) in CSV or Excel format, with filters and a live data preview.
 *
 * @package EIPSI_Forms
 * @since   1.4.0
 * @updated 1.8.0 — Rebuilt with participant export, real preview, stats cards
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// Fetch all active studies for the selector
$studies = $wpdb->get_results(
    "SELECT id, study_name, study_code
     FROM {$wpdb->prefix}survey_studies
     ORDER BY created_at DESC"
);

// Determine pre-selected study (from URL or first available)
$selected_study_id = isset($_GET['study_id']) ? absint($_GET['study_id']) : 0;
if (!$selected_study_id && !empty($studies)) {
    $selected_study_id = $studies[0]->id;
}

$nonce = wp_create_nonce('eipsi_admin_nonce');
?>

<div id="export-tab" class="eipsi-export-tab-wrap">

    <!-- ─── Header ──────────────────────────────────────────────────── -->
    <div class="eipsi-export-header">
        <h2>📊 <?php esc_html_e('Exportar Datos de Participantes', 'eipsi-forms'); ?></h2>
        <p class="eipsi-export-subtitle">
            <?php esc_html_e('Descarga el listado completo de participantes con su progreso por onda en formato CSV o Excel, listo para abrir en SPSS, R o Google Sheets.', 'eipsi-forms'); ?>
        </p>
    </div>

    <?php if (empty($studies)): ?>
        <div class="notice notice-warning" style="padding:12px 16px;">
            <p><?php esc_html_e('No hay estudios longitudinales creados todavía. Crea un estudio primero desde el Wave Manager.', 'eipsi-forms'); ?></p>
        </div>
    <?php else: ?>

    <!-- ─── Filters ─────────────────────────────────────────────────── -->
    <div class="eipsi-export-filters">
        <div class="filter-group">
            <label for="ep-study"><?php esc_html_e('Estudio:', 'eipsi-forms'); ?></label>
            <select id="ep-study">
                <?php foreach ($studies as $study): ?>
                    <option value="<?php echo esc_attr($study->id); ?>"
                        <?php selected($selected_study_id, $study->id); ?>>
                        <?php echo esc_html($study->study_name . ' (' . $study->study_code . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <label for="ep-status"><?php esc_html_e('Estado:', 'eipsi-forms'); ?></label>
            <select id="ep-status">
                <option value="all"><?php esc_html_e('Todos', 'eipsi-forms'); ?></option>
                <option value="active"><?php esc_html_e('Activos', 'eipsi-forms'); ?></option>
                <option value="inactive"><?php esc_html_e('Inactivos', 'eipsi-forms'); ?></option>
            </select>
        </div>

        <div class="filter-group">
            <label for="ep-wave"><?php esc_html_e('Onda:', 'eipsi-forms'); ?></label>
            <select id="ep-wave">
                <option value="all"><?php esc_html_e('Todas las ondas', 'eipsi-forms'); ?></option>
                <!-- Populated dynamically via AJAX -->
            </select>
        </div>

        <div class="filter-group">
            <label for="ep-search"><?php esc_html_e('Buscar:', 'eipsi-forms'); ?></label>
            <input type="text" id="ep-search" placeholder="<?php esc_attr_e('Nombre o email…', 'eipsi-forms'); ?>">
        </div>

        <div class="filter-group">
            <label for="ep-date-from"><?php esc_html_e('Desde:', 'eipsi-forms'); ?></label>
            <input type="date" id="ep-date-from">
        </div>

        <div class="filter-group">
            <label for="ep-date-to"><?php esc_html_e('Hasta:', 'eipsi-forms'); ?></label>
            <input type="date" id="ep-date-to">
        </div>

        <div class="filter-actions">
            <button id="ep-apply-filters" class="button button-primary">
                🔍 <?php esc_html_e('Aplicar', 'eipsi-forms'); ?>
            </button>
            <button id="ep-clear-filters" class="button button-secondary">
                ✕ <?php esc_html_e('Limpiar', 'eipsi-forms'); ?>
            </button>
        </div>
    </div>

    <!-- ─── Stats cards ─────────────────────────────────────────────── -->
    <div class="eipsi-export-stats" id="ep-stats" style="display:none;">
        <div class="ep-stat-card">
            <div class="ep-stat-icon">👥</div>
            <div class="ep-stat-body">
                <span class="ep-stat-value" id="ep-stat-total">—</span>
                <span class="ep-stat-label"><?php esc_html_e('Total participantes', 'eipsi-forms'); ?></span>
            </div>
        </div>
        <div class="ep-stat-card">
            <div class="ep-stat-icon">✅</div>
            <div class="ep-stat-body">
                <span class="ep-stat-value" id="ep-stat-active">—</span>
                <span class="ep-stat-label"><?php esc_html_e('Activos', 'eipsi-forms'); ?></span>
            </div>
        </div>
        <div class="ep-stat-card">
            <div class="ep-stat-icon">🏁</div>
            <div class="ep-stat-body">
                <span class="ep-stat-value" id="ep-stat-completed">—</span>
                <span class="ep-stat-label"><?php esc_html_e('Completaron todas las ondas', 'eipsi-forms'); ?></span>
            </div>
        </div>
        <div class="ep-stat-card ep-stat-card--wide" id="ep-wave-rates-card" style="display:none;">
            <div class="ep-stat-icon">📈</div>
            <div class="ep-stat-body">
                <span class="ep-stat-label"><?php esc_html_e('Tasa de completación por onda', 'eipsi-forms'); ?></span>
                <div id="ep-wave-rates"></div>
            </div>
        </div>
    </div>

    <!-- ─── Loading state ───────────────────────────────────────────── -->
    <div id="ep-loading" style="display:none; text-align:center; padding:30px 0;">
        <span class="spinner is-active" style="float:none; margin:0 auto;"></span>
        <p style="margin-top:8px; color:#666;"><?php esc_html_e('Cargando datos…', 'eipsi-forms'); ?></p>
    </div>

    <!-- ─── Data summary bar ────────────────────────────────────────── -->
    <div id="ep-data-summary" class="ep-data-summary" style="display:none;">
        <span>
            <strong><?php esc_html_e('Filas:', 'eipsi-forms'); ?></strong>
            <span id="ep-row-count">0</span>
        </span>
        <span>
            <strong><?php esc_html_e('Columnas:', 'eipsi-forms'); ?></strong>
            <span id="ep-col-count">0</span>
        </span>
        <span>
            <strong><?php esc_html_e('Codificación:', 'eipsi-forms'); ?></strong> UTF-8
        </span>
        <span>
            <strong><?php esc_html_e('Actualizado:', 'eipsi-forms'); ?></strong>
            <span id="ep-last-update">—</span>
        </span>
    </div>

    <!-- ─── Preview table ───────────────────────────────────────────── -->
    <div id="ep-preview-wrap" class="ep-preview-wrap" style="display:none;">
        <h3><?php esc_html_e('Vista previa (primeras 8 filas)', 'eipsi-forms'); ?></h3>
        <div class="ep-preview-scroll">
            <table class="widefat striped ep-preview-table">
                <thead>
                    <tr id="ep-preview-headers"></tr>
                </thead>
                <tbody id="ep-preview-body"></tbody>
            </table>
        </div>
    </div>

    <!-- ─── Export action buttons ───────────────────────────────────── -->
    <div class="eipsi-export-actions" id="ep-actions" style="display:none;">
        <div class="ep-format-buttons">
            <button id="ep-download-excel-wide" class="button button-primary ep-btn-download ep-btn-download--wide">
                📥 <?php esc_html_e('Excel Wide', 'eipsi-forms'); ?>
            </button>
            <button id="ep-download-csv-wide" class="button button-secondary ep-btn-download ep-btn-download--wide">
                📄 <?php esc_html_e('CSV Wide', 'eipsi-forms'); ?>
            </button>
        </div>
    </div>

    <?php endif; // end if studies ?>
</div>

<!-- ─── JavaScript ─────────────────────────────────────────────────── -->
<script>
( function ( $ ) {
    'use strict';

    const nonce    = <?php echo wp_json_encode($nonce); ?>;
    const adminUrl = <?php echo wp_json_encode(admin_url('admin.php')); ?>;

    let currentStudyId = <?php echo (int) $selected_study_id; ?>;
    let statsLoaded    = false;

    // -----------------------------------------------------------------------
    // Init
    // -----------------------------------------------------------------------
    $( document ).ready( function () {
        // Sync currentStudyId with actual select value on page load
        const initialStudyId = parseInt( $( '#ep-study' ).val(), 10 );
        if ( initialStudyId && initialStudyId !== currentStudyId ) {
            currentStudyId = initialStudyId;
        }
        
        if ( currentStudyId ) {
            loadWaves( currentStudyId );
            loadStats( currentStudyId );
        }
        bindEvents();
    } );

    // -----------------------------------------------------------------------
    // Event bindings
    // -----------------------------------------------------------------------
    function bindEvents() {
        $( '#ep-study' ).on( 'change', function () {
            const newStudyId = parseInt( $( this ).val(), 10 );
            console.log( 'Study changed from', currentStudyId, 'to', newStudyId );
            currentStudyId = newStudyId;
            statsLoaded    = false;
            resetUI();
            if ( currentStudyId ) {
                loadWaves( currentStudyId );
                loadStats( currentStudyId );
            }
        } );

        $( '#ep-apply-filters' ).on( 'click', function () {
            if ( currentStudyId ) {
                loadPreview( currentStudyId );
            }
        } );

        $( '#ep-clear-filters' ).on( 'click', function () {
            $( '#ep-status' ).val( 'all' );
            $( '#ep-wave' ).val( 'all' );
            $( '#ep-search' ).val( '' );
            $( '#ep-date-from, #ep-date-to' ).val( '' );
            if ( currentStudyId ) {
                loadPreview( currentStudyId );
            }
        } );

        // Live search with debounce
        let searchTimer;
        $( '#ep-search' ).on( 'input', function () {
            clearTimeout( searchTimer );
            searchTimer = setTimeout( function () {
                if ( currentStudyId ) loadPreview( currentStudyId );
            }, 500 );
        } );

        // Format selection change handler
        $( 'input[name="preview-format"]' ).on( 'change', function () {
            if ( currentStudyId && statsLoaded ) {
                loadPreview( currentStudyId );
            }
        } );
    }

    // -----------------------------------------------------------------------
    // Load wave list
    // -----------------------------------------------------------------------
    function loadWaves( studyId ) {
        $.ajax( {
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'eipsi_get_participant_waves',
                nonce: nonce,
                study_id: studyId,
            },
            success( resp ) {
                if ( ! resp.success ) return;
                const $select = $( '#ep-wave' );
                $select.find( 'option:not([value="all"])' ).remove();
                resp.data.forEach( function ( w ) {
                    $select.append(
                        $( '<option>' )
                            .val( w.wave_index )
                            .text( 'T' + w.wave_index + ' — ' + w.name )
                    );
                } );
            },
        } );
    }

    // -----------------------------------------------------------------------
    // Load stats + auto-trigger preview
    // -----------------------------------------------------------------------
    function loadStats( studyId ) {
        $( '#ep-loading' ).show();
        $( '#ep-stats, #ep-actions, #ep-preview-wrap, #ep-data-summary, #ep-format-selection' ).hide();

        $.ajax( {
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'eipsi_get_participant_stats',
                nonce: nonce,
                study_id: studyId,
            },
            success( resp ) {
                if ( ! resp.success ) return;
                const s = resp.data;

                $( '#ep-stat-total' ).text( s.total_participants || 0 );
                $( '#ep-stat-active' ).text( s.active_participants || 0 );
                $( '#ep-stat-completed' ).text( s.completed_all_waves || 0 );

                // Wave completion rates
                const rates = s.completion_rates || {};
                if ( Object.keys( rates ).length > 0 ) {
                    let html = '';
                    Object.keys( rates ).forEach( function ( key ) {
                        const r = rates[ key ];
                        html += '<div class="ep-wave-rate">' +
                            '<span class="ep-wave-label">' + escHtml( key ) + ' — ' + escHtml( r.wave_name || '' ) + ':</span> ' +
                            '<strong>' + r.rate + '%</strong> ' +
                            '<span class="ep-wave-detail">(' + r.completed + ' / ' + r.total + ')</span>' +
                            '<div class="ep-mini-bar"><div class="ep-mini-fill" style="width:' + r.rate + '%"></div></div>' +
                            '</div>';
                    } );
                    $( '#ep-wave-rates' ).html( html );
                    $( '#ep-wave-rates-card' ).show();
                }

                $( '#ep-stats' ).show();
                $( '#ep-format-selection' ).show();
                statsLoaded = true;

                // Auto-load preview
                loadPreview( studyId );
            },
            error() {
                $( '#ep-loading' ).hide();
            },
        } );
    }

    // -----------------------------------------------------------------------
    // Load preview
    // -----------------------------------------------------------------------
    function loadPreview( studyId ) {
        console.log( 'loadPreview called with studyId:', studyId, 'currentStudyId:', currentStudyId );
        $( '#ep-loading' ).show();
        $( '#ep-preview-wrap, #ep-data-summary, #ep-actions' ).hide();

        const filters = getFilters();

        console.log( 'Sending AJAX with study_id:', studyId, 'action: eipsi_get_participant_preview' );

        $.ajax( {
            url: ajaxurl,
            type: 'POST',
            data: Object.assign( {
                action: 'eipsi_get_participant_preview', // Fixed: matches handler name
                nonce: nonce,
                study_id: studyId,
            }, filters ),
            success( resp ) {
                $( '#ep-loading' ).hide();

                if ( ! resp.success ) {
                    showNotice( 'error', resp.data ? resp.data.message : 'Error al cargar vista previa.' );
                    return;
                }

                const d = resp.data;
                renderPreview( d.headers, d.rows );

                // Update summary bar
                $( '#ep-row-count' ).text( d.total );
                $( '#ep-col-count' ).text( d.columns );
                $( '#ep-last-update' ).text( new Date().toLocaleString() );
                $( '#ep-data-summary' ).show();

                // Enable download buttons
                $( '#ep-actions' ).show();

                if ( d.total === 0 ) {
                    $( '#ep-preview-wrap' ).hide();
                    showNotice( 'warning', 'No se encontraron participantes con los filtros actuales.' );
                } else {
                    $( '#ep-preview-wrap' ).show();
                }
            },
            error() {
                $( '#ep-loading' ).hide();
                showNotice( 'error', 'Error de conexión. Intenta nuevamente.' );
            },
        } );
    }

    // -----------------------------------------------------------------------
    // Download handlers
    // -----------------------------------------------------------------------
    function downloadFile( action, studyId ) {
        console.log( 'downloadFile called with action:', action, 'studyId:', studyId, 'currentStudyId:', currentStudyId );
        const filters = getFilters();
        const $btn    = $( '#' + action.replace( 'eipsi_export_participants_', 'ep-download-' ).replace( '_', '-' ) );
        
        $btn.prop( 'disabled', true ).addClass( 'loading' ).text( '⏳ Generando...' );

        console.log( 'Sending download AJAX with study_id:', studyId, 'action:', action );

        $.ajax( {
            url: ajaxurl,
            type: 'POST',
            data: Object.assign( {
                action: action,
                nonce: nonce,
                study_id: studyId,
            }, filters ),
            success( resp ) {
                $btn.prop( 'disabled', false ).removeClass( 'loading' );
                
                if ( ! resp.success ) {
                    showNotice( 'error', resp.data ? resp.data.message : 'Error al exportar.' );
                    return;
                }

                const filename = resp.data.filename;
                const downloadUrl = resp.data.download_url;
                
                // Trigger download
                const $a = $( '<a>', {
                    href: downloadUrl,
                    download: filename,
                    target: '_blank'
                } )[0];
                document.body.appendChild( $a );
                $a.click();
                document.body.removeChild( $a );

                showNotice( 'success', '✅ Archivo exportado: ' + filename );
            },
            error() {
                $btn.prop( 'disabled', false ).removeClass( 'loading' );
                showNotice( 'error', 'Error de conexión. Intenta nuevamente.' );
            },
        } );
    }

    // Bind download buttons
    $( '#ep-download-excel-wide' ).on( 'click', function () {
        downloadFile( 'eipsi_export_participants_wide_excel', currentStudyId );
    } );

    $( '#ep-download-csv-wide' ).on( 'click', function () {
        downloadFile( 'eipsi_export_participants_wide_csv', currentStudyId );
    } );

    $( '#ep-download-excel-long' ).on( 'click', function () {
        downloadFile( 'eipsi_export_participants_long_excel', currentStudyId );
    } );

    $( '#ep-download-csv-long' ).on( 'click', function () {
        downloadFile( 'eipsi_export_participants_long_csv', currentStudyId );
    } );

    // -----------------------------------------------------------------------
    // Render preview table
    // -----------------------------------------------------------------------
    function renderPreview( headers, rows ) {
        const $thead = $( '#ep-preview-headers' ).empty();
        const $tbody = $( '#ep-preview-body' ).empty();

        headers.forEach( function ( h ) {
            $thead.append( '<th>' + escHtml( h ) + '</th>' );
        } );

        if ( rows.length === 0 ) {
            $tbody.append(
                '<tr><td colspan="' + headers.length + '" style="text-align:center;padding:20px;color:#666;">' +
                    'Sin resultados' +
                '</td></tr>'
            );
            return;
        }

        rows.forEach( function ( row ) {
            const $tr = $( '<tr>' );
            row.forEach( function ( cell ) {
                $tr.append( '<td>' + escHtml( String( cell !== null ? cell : '' ) ) + '</td>' );
            } );
            $tbody.append( $tr );
        } );
    }

    // -----------------------------------------------------------------------
    // Build download URL
    // -----------------------------------------------------------------------
    function buildDownloadUrl( studyId, filters, action ) {
        const params = new URLSearchParams( {
            page: 'eipsi-results',
            tab: 'export',
            action: action,
            study_id: studyId,
            status: filters.status     || 'all',
            wave_index: filters.wave_index || 'all',
            search: filters.search     || '',
            date_from: filters.date_from  || '',
            date_to: filters.date_to    || '',
        } );
        return adminUrl + '?' + params.toString();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------
    function getFilters() {
        return {
            status:     $( '#ep-status' ).val(),
            wave_index: $( '#ep-wave' ).val(),
            search:     $( '#ep-search' ).val(),
            date_from:  $( '#ep-date-from' ).val(),
            date_to:    $( '#ep-date-to' ).val(),
        };
    }

    function resetUI() {
        $( '#ep-stats, #ep-actions, #ep-preview-wrap, #ep-data-summary, #ep-loading, #ep-format-selection' ).hide();
        $( '#ep-preview-headers, #ep-preview-body, #ep-wave-rates' ).empty();
        $( '#ep-stat-total, #ep-stat-active, #ep-stat-completed' ).text( '—' );
        // Reset format selection to wide
        $( 'input[name="preview-format"][value="wide"]' ).prop( 'checked', true );
        // Reset download buttons (remove loading state)
        $( '.ep-btn-download' )
            .prop( 'disabled', false )
            .removeClass( 'loading' )
            .each( function () {
                const $btn = $( this );
                if ( $btn.hasClass( 'ep-btn-download--wide' ) ) {
                    if ( $btn.attr( 'id' ).includes( 'excel' ) ) {
                        $btn.html( '📥 <?php esc_html_e( "Excel Wide", "eipsi-forms" ); ?>' );
                    } else {
                        $btn.html( '📄 <?php esc_html_e( "CSV Wide", "eipsi-forms" ); ?>' );
                    }
                } else {
                    if ( $btn.attr( 'id' ).includes( 'excel' ) ) {
                        $btn.html( '📥 <?php esc_html_e( "Excel Long", "eipsi-forms" ); ?>' );
                    } else {
                        $btn.html( '📄 <?php esc_html_e( "CSV Long", "eipsi-forms" ); ?>' );
                    }
                }
            } );
    }

    function escHtml( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }

    function showNotice( type, msg ) {
        $( '.ep-inline-notice' ).remove();
        const cls = type === 'error' ? 'notice-error' : type === 'warning' ? 'notice-warning' : 'notice-success';
        const $n  = $( '<div class="notice ' + cls + ' ep-inline-notice" style="margin:10px 0;padding:10px 14px;">' +
            '<p>' + escHtml( msg ) + '</p>' +
        '</div>' );
        $( '#ep-preview-wrap' ).before( $n );
        setTimeout( function () { $n.fadeOut( 400, function () { $( this ).remove(); } ); }, 5000 );
    }

} )( jQuery );
</script>

<!-- ─── Styles ──────────────────────────────────────────────────────── -->
<style>
/* ── Layout ─────────────────────────────────────────────────── */
.eipsi-export-tab-wrap {
    max-width: 1200px;
}

.eipsi-export-header {
    margin-bottom: 20px;
}

.eipsi-export-header h2 {
    margin: 0 0 4px;
    font-size: 22px;
}

.eipsi-export-subtitle {
    color: #555;
    margin: 0;
    font-size: 14px;
}

/* ── Filters ─────────────────────────────────────────────────── */
.eipsi-export-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    align-items: flex-end;
    background: #f6f7f7;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 16px 18px;
    margin-bottom: 20px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 150px;
}

.filter-group label {
    font-size: 12px;
    font-weight: 600;
    color: #444;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.filter-group select,
.filter-group input[type="text"],
.filter-group input[type="date"] {
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 6px 10px;
    font-size: 13px;
    height: 34px;
}

.filter-actions {
    display: flex;
    gap: 8px;
    align-items: flex-end;
    padding-bottom: 0;
}

/* ── Stats cards ─────────────────────────────────────────────── */
.eipsi-export-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}

.ep-stat-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,.07);
}

.ep-stat-card--wide {
    grid-column: 1 / -1;
    flex-direction: column;
    align-items: flex-start;
}

.ep-stat-icon {
    font-size: 28px;
    line-height: 1;
}

.ep-stat-body {
    display: flex;
    flex-direction: column;
}

.ep-stat-value {
    font-size: 30px;
    font-weight: 700;
    color: #1d2327;
    line-height: 1.1;
}

.ep-stat-label {
    font-size: 12px;
    color: #666;
    margin-top: 2px;
}

/* Wave rate bars */
.ep-wave-rate {
    margin: 8px 0 4px;
}
.ep-wave-label {
    font-size: 13px;
    color: #333;
}
.ep-wave-detail {
    font-size: 12px;
    color: #888;
}
.ep-mini-bar {
    height: 5px;
    background: #e2e4e7;
    border-radius: 3px;
    margin-top: 4px;
    overflow: hidden;
    max-width: 400px;
}
.ep-mini-fill {
    height: 100%;
    background: linear-gradient(90deg, #0073aa, #00a0d2);
    border-radius: 3px;
    transition: width .4s ease;
}

/* ── Format selection ───────────────────────────────────────── */
.eipsi-export-format-selection {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 16px 20px;
    margin-bottom: 20px;
}

.ep-format-intro {
    font-size: 14px;
    color: #333;
    margin: 0 0 12px;
    text-align: center;
}

.ep-format-options {
    display: flex;
    gap: 20px;
    justify-content: center;
    flex-wrap: wrap;
}

.ep-format-option {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    padding: 8px 16px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: #fff;
    transition: all 0.2s ease;
}

.ep-format-option:hover {
    border-color: #0073aa;
    background: #f0f6fc;
}

.ep-format-option input[type="radio"] {
    margin: 0;
}

.ep-format-option:has(input:checked) {
    border-color: #0073aa;
    background: #eaf4fb;
    box-shadow: 0 0 0 2px rgba(0,115,170,0.2);
}

.ep-format-label {
    font-size: 13px;
    color: #1d2327;
}

.ep-format-label strong {
    color: #0073aa;
}

/* ── Data summary bar ────────────────────────────────────────── */
.ep-data-summary {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    background: #eaf4fb;
    border: 1px solid #b3d7e8;
    border-radius: 4px;
    padding: 10px 16px;
    font-size: 13px;
    margin-bottom: 16px;
    color: #1d2327;
}

.ep-data-summary span strong {
    color: #0073aa;
}

/* ── Preview ─────────────────────────────────────────────────── */
.ep-preview-wrap {
    margin-bottom: 24px;
}

.ep-preview-wrap h3 {
    margin: 0 0 10px;
    font-size: 15px;
}

.ep-preview-scroll {
    overflow-x: auto;
}

.ep-preview-table {
    min-width: 700px;
    font-size: 13px;
}

.ep-preview-table th {
    background: #f0f0f1;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    padding: 8px 10px;
}

.ep-preview-table td {
    padding: 7px 10px;
    max-width: 220px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* ── Action buttons ──────────────────────────────────────────── */
.eipsi-export-actions {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px 22px;
    margin-bottom: 20px;
}

.ep-actions-intro {
    font-size: 14px;
    color: #333;
    margin: 0 0 16px;
    text-align: center;
}

.ep-format-section {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 16px 20px;
    margin-bottom: 16px;
}

.ep-format-section:last-of-type {
    margin-bottom: 0;
}

.ep-format-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
}

.ep-format-icon {
    font-size: 18px;
}

.ep-format-header strong {
    font-size: 14px;
    color: #1d2327;
}

.ep-format-desc {
    font-size: 12px;
    color: #666;
    margin: 0 0 12px;
}

.ep-format-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.ep-btn-download {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 18px;
    font-size: 14px;
    font-weight: 600;
    border-radius: 5px;
    text-decoration: none;
    transition: box-shadow .2s ease, transform .1s ease;
    border: 1px solid transparent;
    cursor: pointer;
}

.ep-btn-download:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(0,115,170,.3);
}

.ep-btn-download:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.ep-btn-download.loading {
    opacity: 0.8;
}

/* Primary button (Excel) */
.ep-btn-download.button-primary {
    background: #0073aa;
    color: #fff;
    border-color: #006ba1;
}

.ep-btn-download.button-primary:hover:not(:disabled) {
    background: #005f8d;
    border-color: #005078;
}

/* Secondary button (CSV) */
.ep-btn-download.button-secondary {
    background: #fff;
    color: #3c434a;
    border-color: #c3c4c7;
}

.ep-btn-download.button-secondary:hover:not(:disabled) {
    background: #f6f7f7;
    border-color: #a7aaad;
}

/* ── Responsive ──────────────────────────────────────────────── */
@media (max-width: 782px) {
    .eipsi-export-filters {
        flex-direction: column;
    }
    .filter-group {
        min-width: 100%;
    }
    .eipsi-export-stats {
        grid-template-columns: 1fr;
    }
}
</style>
