<?php
/**
 * Study Dashboard Modal - EIPSI Redesign
 * 
 * @since 1.5.3
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get study data from API (this will be populated via AJAX)
$study_data = isset($study_data) ? $study_data : array();
?>

<div id="eipsi-study-dashboard-modal" class="eipsi-modal eipsi-force-light-mode" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="study-name-display" aria-describedby="study-meta-display">
    <div class="eipsi-modal-content dashboard-wide" role="document">
        
        <!-- Header EIPSI -->
        <div class="dash-header">
            <div>
                <div class="dash-title">
                    <span id="study-name-display">Cargando...</span>
                    <span class="pill pill-active" id="study-status-pill">Activo</span>
                </div>
                <div class="dash-sub" id="study-meta-display">
                    Cargando datos del estudio...
                </div>
            </div>
            <div class="dash-actions">
                <button class="btn-sm" id="refresh-dashboard">Actualizar</button>
                <button class="btn-sm btn-primary" id="action-add-participant">Agregar participante</button>
            </div>
        </div>

        <!-- KPIs -->
        <div class="kpis">
            <div class="kpi">
                <div class="kpi-lbl">Participantes</div>
                <div class="kpi-num blue" id="kpi-total">0</div>
            </div>
            <div class="kpi">
                <div class="kpi-lbl">Activos</div>
                <div class="kpi-num" id="kpi-active">0</div>
            </div>
            <div class="kpi">
                <div class="kpi-lbl">Completados</div>
                <div class="kpi-num green" id="kpi-completed">0</div>
            </div>
            <div class="kpi">
                <div class="kpi-lbl">Emails hoy</div>
                <div class="kpi-num" id="kpi-emails">0</div>
            </div>
        </div>

        <!-- Info cards -->
        <div class="cards-row" style="margin-left: 50px;">
            <div class="card">
                <div class="card-title">Shortcode del estudio</div>
                <div style="font-size:11px;color:#64748b;margin-bottom:4px">Pegá este shortcode en cualquier página WordPress.</div>
                <code class="sc-code" id="shortcode-display">[eipsi_longitudinal_study study_code="..."]</code>
                <div style="display:flex;justify-content:flex-end">
                    <button class="sc-copy" id="copy-shortcode">Copiar</button>
                </div>
            </div>
            <div class="card">
                <div class="card-title">Página del estudio</div>
                <div style="font-size:11px;color:#64748b;margin-bottom:6px">Página pública donde los participantes acceden al estudio.</div>
                <div class="url-row">
                    <input class="url-inp" readonly id="study-page-url" value="">
                    <button class="sc-copy" id="copy-page-url">Copiar</button>
                </div>
                <div style="display:flex;gap:6px;margin-top:8px">
                    <button class="btn-sm" style="font-size:11px;padding:4px 10px" id="view-study-page">Ver página</button>
                    <button class="btn-sm" style="font-size:11px;padding:4px 10px" id="edit-study-page">Editar página</button>
                </div>
            </div>
        </div>

        <!-- Waves Section -->
        <div class="section-title" style="margin-left: 50px; margin-right: 50px;">
            <span>Tomas</span>
            <div class="section-line"></div>
            <button class="btn-sm" style="font-size:11px;padding:4px 10px" id="send-global-reminder">Enviar recordatorio global</button>
        </div>

        <div id="waves-container" class="wave-cards">
            <!-- Waves injected via JS -->
        </div>

        <!-- Bottom cards -->
        <div class="cards-row" style="margin-left: 50px;">
            <div class="card">
                <div class="card-title">Control del estudio</div>
                <div style="font-size:11px;color:#64748b;margin-bottom:8px;line-height:1.5">Al pausar se detienen los recordatorios y no se aceptan nuevas respuestas.</div>
                <div class="control-row">
                    <button class="btn-sm" id="btn-pause-study" style="display:none;">Pausar estudio</button>
                    <button class="btn-sm" id="btn-resume-study" style="display:none;">Reanudar estudio</button>
                    <button class="btn-sm" id="action-view-participants">Ver participantes</button>
                    <button class="btn-sm" id="action-import-csv">Importar CSV</button>
                    <button class="btn-sm" id="action-download-data">Descargar datos</button>
                </div>
                <div style="margin-top:10px;padding-top:10px;border-top:1px solid #f1f5f9;display:flex;gap:8px;flex-wrap:wrap;">
                    <button class="btn-sm" style="font-size:11px" id="action-recalculate-times">⚙️ Recalcular tiempos</button>
                    <button class="btn-sm btn-danger" style="font-size:11px" id="action-delete-study">Eliminar estudio</button>
                </div>
            </div>
            <div class="card">
                <div class="card-title">Emails</div>
                <div class="stat-row"><span class="stat-key">Enviados hoy</span><span class="stat-val" id="emails-today">0</span></div>
                <div class="stat-row"><span class="stat-key">Fallidos</span><span class="stat-val green" id="emails-failed">0</span></div>
                <div class="stat-row"><span class="stat-key">Pendientes</span><span class="stat-val" id="emails-pending">0</span></div>
                <button class="btn-sm" style="margin-top:10px;font-size:11px" id="view-email-logs">Ver log de emails</button>
            </div>
        </div>

        <!-- Close button -->
        <div class="eipsi-modal-footer" style="border-top:1px solid #e2e8f0;margin-top:20px;padding-top:16px;">
            <button class="button button-secondary eipsi-modal-close">Cerrar</button>
        </div>
    </div>
</div>

<!-- CSS EIPSI Dashboard -->
<style>
/* Modal backdrop - MUST keep dark overlay regardless of light mode */
#eipsi-study-dashboard-modal.eipsi-modal {
    background: rgba(0, 0, 0, 0.55) !important;
    backdrop-filter: blur(2px);
}

/* Soft Light Mode - Better contrast without harsh white */
/* NOTE: No aplicar a .eipsi-force-light-mode directamente para no pisar el backdrop */
.eipsi-force-light-mode .eipsi-modal-content,
.eipsi-force-light-mode .wave-card,
.eipsi-force-light-mode table,
.eipsi-force-light-mode tbody,
.eipsi-force-light-mode tr,
.eipsi-force-light-mode td {
    background: #f8fafc !important;
    color: #1e293b !important;
}

/* Main content area slightly lighter */
.eipsi-force-light-mode .eipsi-modal-content {
    background: #ffffff !important;
}

/* Cards with subtle background */
.eipsi-force-light-mode .wave-card {
    background: #f1f5f9 !important;
    border: 1px solid #e2e8f0;
}

/* Table styling */
.eipsi-force-light-mode table {
    background: #ffffff !important;
    border: 1px solid #e2e8f0;
}

.eipsi-force-light-mode table tbody td {
    color: #1e293b !important;
    background: #ffffff !important;
    border-bottom: 1px solid #f1f5f9;
}

.eipsi-force-light-mode table tbody tr:nth-child(even) td {
    background: #f8fafc !important;
}

.eipsi-force-light-mode table tbody tr:hover td {
    background: #f1f5f9 !important;
    color: #0f172a !important;
}

.eipsi-force-light-mode code {
    background: #f1f5f9 !important;
    color: #1e3a5f !important;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    padding: 2px 6px;
}

/* Header */
.dash-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid #e2e8f0;
}
.dash-title {
    font-size: 18px;
    font-weight: 600;
    color: #2c3e50;
    display: flex;
    align-items: center;
    gap: 10px;
}
.dash-sub {
    font-size: 12px;
    color: #64748b;
    margin-top: 4px;
}
.pill {
    display: inline-block;
    padding: 2px 9px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 500;
    background: #e2e8f0;
    color: #64748b;
    border: 1px solid #cbd5e0;
}
.pill-active {
    background: #e8f5e9;
    color: #006666;
    border-color: #008080;
}
.pill-paused {
    background: #fff8e5;
    color: #856404;
    border-color: #ffc107;
}
.pill-closed {
    background: #fee2e2;
    color: #dc2626;
    border-color: #dc2626;
}
.dash-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
}

/* Buttons */
.btn-sm {
    padding: 6px 14px;
    background: #fff;
    color: #64748b;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-sm:hover {
    border-color: #3B6CAA;
    color: #3B6CAA;
    background: #f0f6fc;
}
.btn-primary {
    background: #3B6CAA;
    color: #fff;
    border-color: #3B6CAA;
}
.btn-primary:hover {
    background: #1E3A5F;
    color: #fff;
}
.btn-danger {
    color: #dc2626;
    border-color: #fecaca;
    background: #fee2e2;
}
.btn-danger:hover {
    background: #dc2626;
    color: #fff;
}

/* KPIs */
.kpis {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}
.kpi {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px 16px;
    text-align: center;
}
.kpi-lbl {
    font-size: 11px;
    color: #64748b;
    margin-bottom: 4px;
}
.kpi-num {
    font-size: 22px;
    font-weight: 600;
    color: #2c3e50;
}
.kpi-num.blue { color: #3B6CAA; }
.kpi-num.green { color: #008080; }

/* Cards */
.cards-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}
.card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 14px 16px;
}
.card-title {
    font-size: 13px;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 8px;
}
.sc-code {
    display: block;
    background: #f8f9fa;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 8px 12px;
    font-size: 12px;
    color: #006666;
    font-family: monospace;
    margin-bottom: 8px;
    word-break: break-all;
}
.sc-copy {
    padding: 4px 12px;
    background: #fff;
    color: #3B6CAA;
    border: 1px solid #d6edff;
    border-radius: 5px;
    font-size: 11px;
    font-weight: 500;
    cursor: pointer;
}
.sc-copy:hover {
    background: #d6edff;
}
.url-row {
    display: flex;
    gap: 6px;
}
.url-inp {
    flex: 1;
    padding: 5px 10px;
    border: 1px solid #e2e8f0;
    border-radius: 5px;
    font-size: 12px;
    color: #64748b;
    background: #f8f9fa;
}
.control-row {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.stat-row {
    display: flex;
    justify-content: space-between;
    padding: 4px 0;
    font-size: 12px;
}
.stat-key { color: #64748b; }
.stat-val { font-weight: 500; color: #2c3e50; }
.stat-val.green { color: #008080; }

/* Section title */
.section-title {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 24px 0 12px;
    font-size: 14px;
    font-weight: 600;
    color: #2c3e50;
}
.section-line {
    flex: 1;
    height: 1px;
    background: #e2e8f0;
}

/* Wave Cards */
.wave-cards {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.wave-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
}
.wave-card-head {
    padding: 12px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.wave-left {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
}
.wave-idx {
    background: #3B6CAA;
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 4px;
    flex-shrink: 0;
}
.wave-name {
    font-size: 13px;
    font-weight: 500;
    color: #2c3e50;
}
.wave-sub {
    font-size: 11px;
    color: #64748b;
    margin-top: 1px;
}
.wave-right {
    display: flex;
    align-items: center;
    gap: 10px;
}
.wave-body {
    padding: 10px 16px;
    background: #f8f9fa;
    border-top: 1px solid #e2e8f0;
}
.prog-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
}
.prog-track {
    flex: 1;
    height: 5px;
    background: #e2e8f0;
    border-radius: 3px;
}
.prog-fill {
    height: 5px;
    border-radius: 3px;
}
.fill-green { background: #008080; min-width: 8px; }
.fill-blue { background: #3B6CAA; min-width: 8px; }
.fill-gray { background: #cbd5e0; min-width: 8px; }
.prog-lbl {
    font-size: 12px;
    font-weight: 500;
    white-space: nowrap;
}
.deadline-row {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    color: #64748b;
    padding-top: 4px;
}
.deadline-val {
    font-weight: 500;
    color: #2c3e50;
}
.deadline-val.warning { color: #856404; }
.deadline-val.none { color: #94a3b8; font-style: italic; }
.btn-link {
    background: none;
    border: none;
    color: #3B6CAA;
    font-size: 11px;
    cursor: pointer;
    padding: 0;
    text-decoration: underline;
    white-space: nowrap;
}
.btn-link:hover { color: #1E3A5F; }
.btn-link-red { color: #dc2626; }
.btn-link-red:hover { color: #991b1b; }

/* Deadline Editor */
.deadline-editor {
    margin-top: 8px;
    padding: 10px 12px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    display: none;
}
.deadline-editor.open { display: block; }
.de-label {
    font-size: 11px;
    color: #64748b;
    margin-bottom: 6px;
}
.de-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}
.de-row input[type=date] {
    padding: 5px 8px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 12px;
    color: #2c3e50;
    background: #fff;
}
.de-row input[type=date]:focus {
    border-color: #3B6CAA;
    outline: none;
    box-shadow: 0 0 0 3px #d6edff;
}
.de-footer {
    display: flex;
    gap: 6px;
    justify-content: flex-end;
}

/* Nudge Section */
.nudge-section { border-top: 1px solid #e2e8f0; }
.nudge-toggle-row {
    padding: 10px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
}
.nudge-toggle-row:hover { background: #f8f9fa; }
.nudge-lbl {
    font-size: 12px;
    color: #64748b;
}
.nudge-lbl.on {
    color: #006666;
    font-weight: 500;
}
.toggle {
    position: relative;
    width: 32px;
    height: 18px;
    flex-shrink: 0;
}
.toggle input {
    opacity: 0;
    width: 0;
    height: 0;
    position: absolute;
}
.tslider {
    position: absolute;
    inset: 0;
    background: #cbd5e0;
    border-radius: 9px;
    cursor: pointer;
    transition: background .2s;
}
.tslider:before {
    content: '';
    position: absolute;
    height: 12px;
    width: 12px;
    left: 3px;
    bottom: 3px;
    background: #fff;
    border-radius: 50%;
    transition: transform .2s;
}
input:checked + .tslider { background: #3B6CAA; }
input:checked + .tslider:before { transform: translateX(14px); }
.nudge-panel {
    padding: 12px 16px;
    background: #f0f6fc;
    border-top: 1px solid #d6edff;
    display: none;
}
.nudge-panel.open { display: block; }
.nudge-ref-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
    font-size: 12px;
    color: #64748b;
}
.nudge-ref-row select {
    padding: 4px 8px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 12px;
    color: #2c3e50;
    background: #fff;
}
.nudge-rows {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 10px;
}
.nudge-row {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #64748b;
}
.nudge-row input {
    width: 58px;
    padding: 4px 7px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 12px;
    text-align: center;
    color: #2c3e50;
}
.nudge-row select {
    padding: 4px 7px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 12px;
    color: #2c3e50;
    background: #fff;
}
.nudge-num {
    font-size: 11px;
    color: #94a3b8;
    min-width: 14px;
}
.nudge-footer {
    display: flex;
    justify-content: flex-end;
    gap: 6px;
    padding-top: 8px;
    border-top: 1px solid #d6edff;
}
.info-note {
    font-size: 11px;
    color: #856404;
    background: #fff8e5;
    border: 1px solid #ffc107;
    border-radius: 5px;
    padding: 5px 8px;
    margin-bottom: 10px;
}

/* Responsive */
@media (max-width: 600px) {
    .kpis { grid-template-columns: repeat(2, 1fr); }
    .cards-row { grid-template-columns: 1fr; }
    .dash-header { flex-direction: column; }
}
</style>

<!-- 
    TODA la funcionalidad JS está en assets/js/study-dashboard.js
    El JS externo maneja renderWaves, event delegation, y todos los handlers.
    Solo se mantienen aquí las funciones globales para onclick handlers inline.
-->

<script>

// Global functions for inline onclick handlers
function toggleDeadlineEditor(id, btn) {
    const el = document.getElementById(id);
    const isOpen = el.classList.toggle('open');
    if (btn) {
        const hasDeadline = btn.textContent.includes('Cambiar');
        btn.textContent = isOpen ? 'Cancelar' : (hasDeadline ? 'Cambiar' : 'Asignar plazo');
    }
}

function toggleNudgePanel(id) {
    const panel = document.getElementById(id);
    const isOpen = panel.classList.toggle('open');
    const lbl = document.getElementById('nl' + id.substring(1));
    const count = panel.querySelectorAll('.nudge-row').length;
    
    if (lbl) {
        lbl.textContent = isOpen ? `Recordatorios activados · ${count} nudges` : 'Recordatorios desactivados';
        lbl.className = isOpen ? 'nudge-lbl on' : 'nudge-lbl';
    }
}

function saveDeadline(waveId, editorId) {
    const dateEl = document.getElementById(editorId + '-date');
    if (!dateEl || !dateEl.value) return;
    
    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'eipsi_extend_wave_deadline',
            wave_id: waveId,
            new_deadline: dateEl.value,
            nonce: eipsi_dashboard_nonce
        },
        success: function() {
            toggleDeadlineEditor(editorId, null);
            // Refresh dashboard
            if (window.eipsiLoadStudyDashboard) {
                window.eipsiLoadStudyDashboard(jQuery('.wave-card[data-wave-id="' + waveId + '"]').closest('.eipsi-modal-content').data('study-id'));
            }
        }
    });
}

function removeDeadline(waveId) {
    // TODO: Implement remove deadline API call
    alert('Funcionalidad pendiente: quitar plazo');
}

function saveNudgeConfig(waveId) {
    const config = {};
    for (let i = 1; i <= 4; i++) {
        config['nudge_' + i] = {
            enabled: true,
            value: parseInt(document.getElementById('nudge-' + waveId + '-' + i + '-val').value) || 24,
            unit: document.getElementById('nudge-' + waveId + '-' + i + '-unit').value
        };
    }
    
    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'eipsi_save_study_cron_config',
            wave_id: waveId,
            nudge_config: JSON.stringify(config),
            nonce: eipsi_dashboard_nonce
        },
        success: function() {
            toggleNudgePanel('n' + waveId);
        }
    });
}

// Participants Modal Functions
let currentParticipantsPage = 1;
let currentParticipantsFilter = 'all';
let currentParticipantsSearch = '';

function loadParticipants(studyId, page = 1) {
    currentParticipantsPage = page;
    
    jQuery.ajax({
        url: ajaxurl,
        type: 'GET',
        data: {
            action: 'eipsi_get_participants_list',
            study_id: studyId,
            page: page,
            per_page: 20,
            status: currentParticipantsFilter,
            search: currentParticipantsSearch,
            nonce: eipsi_dashboard_nonce
        },
        success: function(response) {
            if (response.success) {
                renderParticipants(response.data);
            }
        }
    });
}

function renderParticipants(data) {
    const tbody = jQuery('#participants-tbody');
    tbody.empty();
    
    // Guardar datos de waves para verificar recordatorios
    window.currentStudyWaves = data.waves || [];
    
    if (data.participants && data.participants.length > 0) {
        data.participants.forEach(function(p) {
            const statusClass = p.is_active ? 'badge-active' : 'badge-inactive';
            const statusText = p.is_active ? 'Activo' : 'Inactivo';
            const toggleIcon = p.is_active ? '🔒' : '🔓';
            const toggleTitle = p.is_active ? 'Desactivar' : 'Activar';
            
            // Verificar si hay wave activa pendiente para este participante
            const hasActiveWave = data.waves && data.waves.some(w => w.status === 'active');
            const showReminder = hasActiveWave && p.is_active;
            
            const row = `
                <tr data-participant-id="${p.id}">
                    <td><code>${p.email}</code></td>
                    <td><span class="eipsi-badge ${statusClass}">${statusText}</span></td>
                    <td>
                        <div class="participant-actions">
                            <button class="btn-icon" onclick="resendParticipantEmail(${p.id}, 'magic_link')" title="Enviar Magic Link">
                                ✨
                            </button>
                            ${showReminder ? `
                            <button class="btn-icon" onclick="showReminderModal(${p.id}, '${p.email}')" title="Enviar recordatorio">
                                �
                            </button>
                            ` : ''}
                            <button class="btn-icon" onclick="toggleParticipantStatus(${p.id}, ${p.is_active ? 0 : 1})" title="${toggleTitle}">
                                ${toggleIcon}
                            </button>
                            <button class="btn-icon btn-icon-danger" onclick="showRemoveParticipantModal(${p.id}, '${p.email}')" title="Eliminar">
                                🗑️
                            </button>
                        </div>
                    </td>
                </tr>
            `;
            tbody.append(row);
        });
    } else {
        tbody.append('<tr><td colspan="3" style="text-align:center;color:#94a3b8;padding:20px;">No hay participantes</td></tr>');
    }
    
    // Update count
    jQuery('#participants-count').text(data.total + ' participantes');
    
    // Render pagination
    renderPagination(data.total_pages, data.current_page);
}

function renderPagination(totalPages, currentPage) {
    const container = jQuery('#participants-pagination');
    if (totalPages <= 1) {
        container.empty();
        return;
    }
    
    let html = '<div class="pagination">';
    for (let i = 1; i <= totalPages; i++) {
        const activeClass = i === currentPage ? 'active' : '';
        html += `<button class="page-btn ${activeClass}" onclick="loadParticipants(currentStudyId, ${i})">${i}</button>`;
    }
    html += '</div>';
    container.html(html);
}

function toggleParticipantStatus(participantId, isActive) {
    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'eipsi_toggle_participant_status',
            participant_id: participantId,
            is_active: isActive,
            nonce: eipsi_dashboard_nonce
        },
        success: function(response) {
            if (response.success) {
                loadParticipants(currentStudyId, currentParticipantsPage);
            }
        }
    });
}

function resendParticipantEmail(participantId, emailType) {
    if (emailType !== 'magic_link') {
        console.log('Email type not supported in new UI:', emailType);
        return;
    }
    
    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'eipsi_resend_email',
            participant_id: participantId,
            email_type: 'magic_link',
            nonce: eipsi_dashboard_nonce
        },
        success: function(response) {
            if (response.success) {
                alert(response.data?.message || 'Magic Link enviado correctamente');
            } else {
                alert('Error: ' + (response.data?.message || 'No se pudo enviar el Magic Link'));
            }
        },
        error: function() {
            alert('Error de conexión al enviar Magic Link');
        }
    });
}

function showRemoveParticipantModal(participantId, email) {
    jQuery('#remove-participant-id').val(participantId);
    jQuery('#remove-participant-email').text(email);
    jQuery('#eipsi-remove-participant-modal').show();
}

function removeParticipant(hardDelete) {
    const participantId = jQuery('#remove-participant-id').val();
    const reason = jQuery('#remove-reason').val();
    
    const action = hardDelete ? 'eipsi_delete_participant' : 'eipsi_remove_participant';
    
    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: action,
            participant_id: participantId,
            reason: reason,
            nonce: eipsi_dashboard_nonce
        },
        success: function(response) {
            if (response.success) {
                jQuery('#eipsi-remove-participant-modal').hide();
                loadParticipants(currentStudyId, currentParticipantsPage);
            } else {
                alert('Error: ' + (response.data.message || 'No se pudo eliminar el participante'));
            }
        }
    });
}

// Event handlers for participants
jQuery('#action-view-participants').on('click', function() {
    if (window.currentStudyId) {
        loadParticipants(window.currentStudyId);
        jQuery('#eipsi-participants-modal').show();
    }
});

jQuery('#participant-status-filter').on('change', function() {
    currentParticipantsFilter = jQuery(this).val();
    if (window.currentStudyId) {
        loadParticipants(window.currentStudyId, 1);
    }
});

jQuery('#participant-search').on('input', function() {
    currentParticipantsSearch = jQuery(this).val();
    if (window.currentStudyId) {
        loadParticipants(window.currentStudyId, 1);
    }
});

// CSV Import Modal Handler
jQuery('#action-import-csv').on('click', function() {
    jQuery('#eipsi-csv-import-modal').show();
});

// Show reminder modal with context
function showReminderModal(participantId, email) {
    jQuery('#reminder-participant-id').val(participantId);
    jQuery('#reminder-participant-email').text(email);
    
    // Find active wave
    const activeWave = window.currentStudyWaves && window.currentStudyWaves.find(w => w.status === 'active');
    if (activeWave) {
        jQuery('#reminder-wave-name').text(activeWave.wave_name || activeWave.name || 'Toma activa');
        jQuery('#reminder-wave-id').val(activeWave.wave_id || activeWave.id || 0);
    } else {
        jQuery('#reminder-wave-name').text('No hay toma activa');
        jQuery('#reminder-wave-id').val(0);
    }
    
    // Check if participant already received reminder recently (mock check)
    // In production, this would check from the API response
    jQuery('#reminder-warning').hide();
    jQuery('#reminder-last-time').text('');
    
    jQuery('#eipsi-reminder-modal').show();
}

// Send individual reminder (cron manual)
function sendIndividualReminderConfirmed() {
    const participantId = jQuery('#reminder-participant-id').val();
    const waveId = jQuery('#reminder-wave-id').val() || 0;
    
    if (!participantId) return;
    
    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'eipsi_send_individual_reminder',
            participant_id: participantId,
            wave_id: waveId,
            nonce: eipsiStudyDash.nonce
        },
        success: function(response) {
            jQuery('#eipsi-reminder-modal').hide();
            if (response.success) {
                alert('Recordatorio enviado correctamente');
            } else {
                alert('Error: ' + (response.data?.message || 'No se pudo enviar el recordatorio'));
            }
        },
        error: function() {
            alert('Error de conexión al enviar recordatorio');
        }
    });
}
</script>

<!-- CSV Import Modal EIPSI -->
<div id="eipsi-csv-import-modal" class="eipsi-modal eipsi-force-light-mode" style="display:none; z-index: 100001;">
    <div class="eipsi-modal-content" style="max-width:500px;">
        <div class="eipsi-modal-header" style="border-bottom:1px solid #e2e8f0;padding:16px 20px;">
            <h2 style="font-size:16px;font-weight:600;color:#2c3e50;margin:0;">Importar Participantes CSV</h2>
            <button class="eipsi-modal-close" style="background:none;border:none;font-size:20px;color:#64748b;cursor:pointer;">&times;</button>
        </div>
        <div class="eipsi-modal-body" style="padding:20px;">
            <!-- Step 1: Upload -->
            <div id="csv-step-1">
                <div class="csv-upload-area" id="csv-upload-area">
                    <div class="csv-upload-icon">📁</div>
                    <p class="csv-upload-text">Arrastra tu archivo CSV aquí o haz clic para seleccionar</p>
                    <p class="csv-upload-hint">Formato: email (una columna, un email por línea)</p>
                    <input type="file" id="csv-file-input" accept=".csv,.txt" style="display:none;">
                </div>
                <div style="margin-top:12px;text-align:center;">
                    <a href="#" id="download-csv-template" class="btn-link" style="font-size:12px;">📥 Descargar plantilla CSV</a>
                </div>
            </div>
            
            <!-- Step 2: Preview -->
            <div id="csv-step-2" style="display:none;">
                <div style="margin-bottom:12px;">
                    <span id="csv-preview-count" style="font-size:13px;font-weight:500;color:#2c3e50;"></span>
                </div>
                <div id="csv-validation-summary" style="margin-bottom:12px;"></div>
                <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;max-height:300px;overflow-y:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead>
                            <tr style="background:#f8f9fa;">
                                <th style="padding:8px 12px;text-align:left;font-weight:600;border-bottom:1px solid #e2e8f0;">#</th>
                                <th style="padding:8px 12px;text-align:left;font-weight:600;border-bottom:1px solid #e2e8f0;">Email</th>
                                <th style="padding:8px 12px;text-align:left;font-weight:600;border-bottom:1px solid #e2e8f0;">Estado</th>
                            </tr>
                        </thead>
                        <tbody id="csv-preview-tbody"></tbody>
                    </table>
                </div>
                <div style="margin-top:12px;padding:10px;background:#fff8e5;border:1px solid #ffc107;border-radius:6px;">
                    <p style="font-size:11px;color:#856404;margin:0;">⚠️ Los participantes existentes serán omitidos. Se generarán contraseñas automáticas.</p>
                </div>
            </div>
            
            <!-- Step 3: Progress -->
            <div id="csv-step-3" style="display:none;text-align:center;padding:40px 20px;">
                <div class="spinner" style="display:inline-block;width:40px;height:40px;border:3px solid #e2e8f0;border-top-color:#3B6CAA;border-radius:50%;animation:spin 1s linear infinite;"></div>
                <p style="margin-top:16px;font-size:13px;color:#64748b;">Importando participantes y enviando invitaciones...</p>
                <div style="width:100%;height:8px;background:#e2e8f0;border-radius:4px;margin-top:16px;overflow:hidden;">
                    <div id="csv-progress-bar" style="width:0%;height:100%;background:#3B6CAA;transition:width 0.3s;"></div>
                </div>
                <p id="csv-progress-text" style="margin-top:8px;font-size:12px;color:#64748b;">0 / 0</p>
            </div>
            
            <!-- Step 4: Results -->
            <div id="csv-step-4" style="display:none;">
                <div id="csv-results" style="text-align:center;padding:20px;"></div>
            </div>
            
            <div id="csv-import-error" style="display:none;margin-top:12px;padding:10px;background:#fee2e2;border:1px solid #dc2626;border-radius:6px;color:#dc2626;font-size:12px;"></div>
        </div>
        <div class="eipsi-modal-footer" style="border-top:1px solid #e2e8f0;padding:16px 20px;display:flex;justify-content:space-between;">
            <button class="btn-sm" id="csv-cancel-btn">Cancelar</button>
            <div>
                <button class="btn-sm" id="csv-prev-btn" style="display:none;margin-right:6px;">← Anterior</button>
                <button class="btn-sm btn-primary" id="csv-validate-btn" style="display:none;">Validar</button>
                <button class="btn-sm btn-primary" id="csv-import-btn" style="display:none;" disabled>📧 Importar</button>
                <button class="btn-sm btn-primary" id="csv-done-btn" style="display:none;">Finalizar</button>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes spin { to { transform: rotate(360deg); } }

.csv-upload-area {
    border: 2px dashed #d6edff;
    border-radius: 12px;
    padding: 40px 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    background: #f8f9fa;
}
.csv-upload-area:hover {
    border-color: #3B6CAA;
    background: #f0f6fc;
}
.csv-upload-area.dragover {
    border-color: #3B6CAA;
    background: #d6edff;
}
.csv-upload-icon {
    font-size: 32px;
    margin-bottom: 12px;
}
.csv-upload-text {
    font-size: 14px;
    font-weight: 500;
    color: #2c3e50;
    margin-bottom: 6px;
}
.csv-upload-hint {
    font-size: 12px;
    color: #64748b;
}
</style>

<script>
// CSV Import functionality
(function($) {
    let csvParticipants = [];
    let csvValidated = [];
    
    // Upload area click - prevent bubbling from file input
    $('#csv-upload-area').on('click', function(e) {
        if (e.target.id === 'csv-file-input') return;
        $('#csv-file-input').trigger('click');
    });

    // File input change
    $('#csv-file-input').on('change', function(e) {
        e.stopPropagation();
        if (e.target.files.length > 0) {
            handleCsvFile(e.target.files[0]);
        }
    });
    
    // Drag and drop
    const uploadArea = document.getElementById('csv-upload-area');
    uploadArea.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('dragover');
    });
    uploadArea.addEventListener('dragleave', function() {
        this.classList.remove('dragover');
    });
    uploadArea.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
        if (e.dataTransfer.files.length > 0) {
            handleCsvFile(e.dataTransfer.files[0]);
        }
    });
    
    function handleCsvFile(file) {
        if (!file.name.match(/\.(csv|txt)$/i)) {
            showCsvError('Por favor selecciona un archivo CSV o TXT');
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            const content = e.target.result;
            csvParticipants = parseCsv(content);
            
            if (csvParticipants.length === 0) {
                showCsvError('No se encontraron participantes válidos en el archivo');
                return;
            }
            
            if (csvParticipants.length > 500) {
                showCsvError('El archivo contiene más de 500 participantes. Por favor divide el archivo.');
                return;
            }
            
            showCsvStep(2);
            previewCsv(csvParticipants);
        };
        reader.readAsText(file);
    }
    
    function parseCsv(content) {
        const lines = content.split(/\r?\n/);
        const participants = [];
        let isFirstLine = true;

        for (const line of lines) {
            const trimmed = line.trim();
            if (!trimmed) continue;

            // Remove commas and get email
            let email = trimmed;

            // Skip header row
            if (isFirstLine) {
                isFirstLine = false;
                if (email.toLowerCase().includes('email')) continue;
            }
            isFirstLine = false;

            // Clean email (remove quotes, trim)
            email = email.replace(/^["']|["']$/g, '').trim();

            if (email && email.includes('@')) {
                participants.push({
                    email: email,
                    status: 'pending'
                });
            }
        }
        return participants;
    }
    
    function previewCsv(participants) {
        $('#csv-preview-count').text(participants.length + ' participantes encontrados');
        const tbody = $('#csv-preview-tbody');
        tbody.empty();

        participants.forEach((p, i) => {
            tbody.append(`
                <tr>
                    <td style="padding:8px 12px;border-bottom:1px solid #f1f5f9;">${i + 1}</td>
                    <td style="padding:8px 12px;border-bottom:1px solid #f1f5f9;"><code>${p.email}</code></td>
                    <td style="padding:8px 12px;border-bottom:1px solid #f1f5f9;"><span class="eipsi-badge badge-pending">Pendiente</span></td>
                </tr>
            `);
        });
        
        $('#csv-validate-btn').show();
        $('#csv-import-btn').hide();
        $('#csv-prev-btn').show();
    }
    
    function showCsvStep(step) {
        $('#csv-step-1, #csv-step-2, #csv-step-3, #csv-step-4').hide();
        $('#csv-step-' + step).show();
        
        $('#csv-cancel-btn').text(step === 1 ? 'Cancelar' : 'Cerrar');
        $('#csv-prev-btn').toggle(step > 1 && step < 4);
        $('#csv-validate-btn').toggle(step === 2);
        $('#csv-import-btn').toggle(step === 2);
        $('#csv-done-btn').toggle(step === 4);
    }
    
    function showCsvError(msg) {
        $('#csv-import-error').text(msg).show();
        setTimeout(() => $('#csv-import-error').hide(), 5000);
    }
    
    // Validate button
    $('#csv-validate-btn').on('click', function() {
        if (!currentStudyId) return;
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'eipsi_validate_csv_participants',
                study_id: currentStudyId,
                csv_data: JSON.stringify(csvParticipants),
                nonce: eipsi_dashboard_nonce
            },
            success: function(response) {
                if (response.success) {
                    csvValidated = response.data.participants;
                    updateValidationUI(response.data.summary);
                    $('#csv-import-btn').prop('disabled', false);
                } else {
                    showCsvError(response.data?.message || 'Error al validar participantes');
                }
            }
        });
    });
    
    function updateValidationUI(summary) {
        const html = `
            <div style="display:flex;gap:16px;justify-content:center;">
                <div style="text-align:center;"><div style="font-size:20px;font-weight:600;color:#008080;">${summary.valid}</div><div style="font-size:11px;color:#64748b;">Válidos</div></div>
                <div style="text-align:center;"><div style="font-size:20px;font-weight:600;color:#856404;">${summary.existing}</div><div style="font-size:11px;color:#64748b;">Existentes</div></div>
                <div style="text-align:center;"><div style="font-size:20px;font-weight:600;color:#dc2626;">${summary.invalid}</div><div style="font-size:11px;color:#64748b;">Inválidos</div></div>
            </div>
        `;
        $('#csv-validation-summary').html(html);
    }
    
    // Import button
    $('#csv-import-btn').on('click', function() {
        showCsvStep(3);
        importParticipants();
    });
    
    function importParticipants() {
        const validParticipants = csvValidated.filter(p => p.status === 'valid');
        const total = validParticipants.length;
        let processed = 0;
        let imported = 0;
        let failed = 0;
        
        function updateProgress() {
            const pct = Math.round((processed / total) * 100);
            $('#csv-progress-bar').css('width', pct + '%');
            $('#csv-progress-text').text(processed + ' / ' + total);
        }
        
        function processBatch(batch) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'eipsi_import_csv_participants',
                    study_id: currentStudyId,
                    participants: JSON.stringify(batch),
                    nonce: eipsi_dashboard_nonce
                },
                success: function(response) {
                    if (response.success) {
                        imported += response.data.results.imported;
                        failed += response.data.results.failed;
                    }
                    processed += batch.length;
                    updateProgress();
                    
                    if (processed >= total) {
                        showResults(imported, failed);
                    }
                }
            });
        }
        
        // Process in batches of 10
        for (let i = 0; i < validParticipants.length; i += 10) {
            const batch = validParticipants.slice(i, i + 10);
            setTimeout(() => processBatch(batch), i * 100);
        }
        
        if (total === 0) {
            showResults(0, 0);
        }
    }
    
    function showResults(imported, failed) {
        showCsvStep(4);
        const html = `
            <div style="font-size:48px;margin-bottom:16px;">${failed === 0 ? '✅' : '⚠️'}</div>
            <h3 style="font-size:16px;font-weight:600;color:#2c3e50;margin-bottom:12px;">Importación completada</h3>
            <div style="display:flex;gap:24px;justify-content:center;margin-bottom:16px;">
                <div style="text-align:center;"><div style="font-size:24px;font-weight:600;color:#008080;">${imported}</div><div style="font-size:12px;color:#64748b;">Importados</div></div>
                <div style="text-align:center;"><div style="font-size:24px;font-weight:600;color:#dc2626;">${failed}</div><div style="font-size:12px;color:#64748b;">Fallidos</div></div>
            </div>
            <p style="font-size:12px;color:#64748b;">Se han enviado los emails de invitación a los participantes importados.</p>
        `;
        $('#csv-results').html(html);
        
        // Refresh dashboard data
        if (window.eipsiLoadStudyDashboard && currentStudyId) {
            window.eipsiLoadStudyDashboard(currentStudyId);
        }
    }
    
    // Navigation buttons
    $('#csv-cancel-btn').on('click', function() {
        $('#eipsi-csv-import-modal').hide();
        resetCsvModal();
    });
    
    $('#csv-prev-btn').on('click', function() {
        showCsvStep(1);
        resetCsvModal();
    });
    
    $('#csv-done-btn').on('click', function() {
        $('#eipsi-csv-import-modal').hide();
        resetCsvModal();
    });
    
    function resetCsvModal() {
        csvParticipants = [];
        csvValidated = [];
        $('#csv-file-input').val('');
        $('#csv-import-error').hide();
        $('#csv-import-btn').prop('disabled', true);
        showCsvStep(1);
    }
    
    // Download template
    $('#download-csv-template').on('click', function(e) {
        e.preventDefault();
        const template = 'email\nparticipante1@email.com\nparticipante2@email.com';
        const blob = new Blob([template], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'plantilla_participantes.csv';
        a.click();
        URL.revokeObjectURL(url);
    });
    
})(jQuery);
</script>

<!-- Participants Modal EIPSI -->
<div id="eipsi-participants-modal" class="eipsi-modal eipsi-force-light-mode" style="display:none; z-index: 100001;">
    <div class="eipsi-modal-content participants-modal-wide">
        <div class="eipsi-modal-header" style="border-bottom:1px solid #e2e8f0;padding:16px 20px;">
            <h2 style="font-size:16px;font-weight:600;color:#2c3e50;margin:0;">Participantes</h2>
            <button class="eipsi-modal-close" style="background:none;border:none;font-size:20px;color:#64748b;cursor:pointer;">&times;</button>
        </div>
        <div class="eipsi-modal-body" style="padding:20px;">
            <!-- Filters -->
            <div style="display:flex;gap:12px;margin-bottom:16px;align-items:center;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:12px;color:#64748b;">Estado:</label>
                    <select id="participant-status-filter" style="padding:5px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;">
                        <option value="all">Todos</option>
                        <option value="active">Activos</option>
                        <option value="inactive">Inactivos</option>
                    </select>
                </div>
                <div style="display:flex;align-items:center;gap:6px;flex:1;min-width:200px;">
                    <label style="font-size:12px;color:#64748b;">Buscar:</label>
                    <input type="text" id="participant-search" placeholder="Email o nombre..." style="flex:1;padding:5px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;">
                </div>
                <span id="participants-count" style="font-size:12px;color:#64748b;font-weight:500;">0 participantes</span>
            </div>
            
            <!-- Table -->
            <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                <table style="width:100%;border-collapse:collapse;font-size:12px;">
                    <thead>
                        <tr style="background:#f8f9fa;">
                            <th style="padding:10px 12px;text-align:left;font-weight:600;color:#2c3e50;border-bottom:1px solid #e2e8f0;">Email</th>
                            <th style="padding:10px 12px;text-align:left;font-weight:600;color:#2c3e50;border-bottom:1px solid #e2e8f0;">Estado</th>
                            <th style="padding:10px 12px;text-align:left;font-weight:600;color:#2c3e50;border-bottom:1px solid #e2e8f0;width:140px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="participants-tbody">
                        <!-- Injected via JS -->
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <div id="participants-pagination" style="margin-top:12px;display:flex;justify-content:center;"></div>
        </div>
    </div>
</div>

<!-- Reminder Confirmation Modal -->
<div id="eipsi-reminder-modal" class="eipsi-modal eipsi-force-light-mode" style="display:none; z-index: 100002;">
    <div class="eipsi-modal-content" style="max-width:400px;">
        <div class="eipsi-modal-header" style="border-bottom:1px solid #e2e8f0;padding:16px 20px;">
            <h3 style="font-size:14px;font-weight:600;color:#2c3e50;margin:0;">Enviar Recordatorio</h3>
            <button class="eipsi-modal-close" style="background:none;border:none;font-size:20px;color:#64748b;cursor:pointer;">&times;</button>
        </div>
        <div class="eipsi-modal-body" style="padding:20px;">
            <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
                <strong id="reminder-participant-email" style="color:#2c3e50;"></strong>
            </p>
            
            <div id="reminder-wave-info" style="background:#f0f6fc;border:1px solid #d6edff;border-radius:8px;padding:12px;margin-bottom:16px;">
                <div style="font-size:12px;color:#64748b;margin-bottom:4px;">Toma actual:</div>
                <div id="reminder-wave-name" style="font-size:13px;font-weight:500;color:#2c3e50;"></div>
            </div>
            
            <div id="reminder-warning" style="display:none;background:#fff8e5;border:1px solid #ffc107;border-radius:8px;padding:12px;margin-bottom:16px;">
                <div style="font-size:12px;color:#856404;">
                    <span style="font-weight:500;">⚠️ Atención:</span> Este participante ya recibió un recordatorio <span id="reminder-last-time"></span>.
                </div>
            </div>
            
            <input type="hidden" id="reminder-participant-id">
            <input type="hidden" id="reminder-wave-id">
            
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button class="btn-sm" onclick="jQuery('#eipsi-reminder-modal').hide();">Cancelar</button>
                <button class="btn-sm btn-primary" id="btn-send-reminder" onclick="sendIndividualReminderConfirmed()">Enviar ahora</button>
            </div>
        </div>
    </div>
</div>

<!-- Remove Participant Confirmation Modal -->
<div id="eipsi-remove-participant-modal" class="eipsi-modal eipsi-force-light-mode" style="display:none; z-index: 100002;">
    <div class="eipsi-modal-content" style="max-width:400px;">
        <div class="eipsi-modal-header" style="border-bottom:1px solid #e2e8f0;padding:16px 20px;">
            <h3 style="font-size:14px;font-weight:600;color:#2c3e50;margin:0;">Desactivar o Eliminar</h3>
            <button class="eipsi-modal-close" style="background:none;border:none;font-size:20px;color:#64748b;cursor:pointer;">&times;</button>
        </div>
        <div class="eipsi-modal-body" style="padding:20px;">
            <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
                <strong id="remove-participant-email" style="color:#2c3e50;"></strong>
            </p>
            
            <div style="background:#f8f9fa;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:16px;">
                <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;margin-bottom:8px;">
                    <input type="radio" name="remove-type" value="soft" checked style="margin-top:2px;">
                    <div>
                        <div style="font-size:12px;font-weight:500;color:#2c3e50;">Desactivar (Soft Delete)</div>
                        <div style="font-size:11px;color:#64748b;">Conserva el historial, solo desactiva el acceso.</div>
                    </div>
                </label>
                <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
                    <input type="radio" name="remove-type" value="hard" style="margin-top:2px;">
                    <div>
                        <div style="font-size:12px;font-weight:500;color:#dc2626;">Eliminar Permanentemente</div>
                        <div style="font-size:11px;color:#64748b;">Borra todo el historial. No se puede deshacer.</div>
                    </div>
                </label>
            </div>
            
            <div style="margin-bottom:16px;">
                <label style="font-size:11px;color:#64748b;display:block;margin-bottom:4px;">Razón (opcional):</label>
                <input type="text" id="remove-reason" placeholder="Ej: Solicitud del participante" style="width:100%;padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;">
            </div>
            
            <input type="hidden" id="remove-participant-id">
            
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button class="btn-sm" onclick="jQuery('#eipsi-remove-participant-modal').hide();">Cancelar</button>
                <button class="btn-sm btn-danger" onclick="removeParticipant(jQuery('input[name=remove-type]:checked').val() === 'hard')">Confirmar</button>
            </div>
        </div>
    </div>
</div>

<!-- Additional CSS for Participants -->
<style>
/* Badges */
.eipsi-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 500;
}
.badge-active {
    background: #e8f5e9;
    color: #006666;
}
.badge-inactive {
    background: #fee2e2;
    color: #dc2626;
}

/* Email Log Badges */
.badge-sent {
    background: #dbeafe;
    color: #1e40af;
}
.badge-delivered {
    background: #dcfce7;
    color: #166534;
}
.badge-clicked {
    background: #e0e7ff;
    color: #4338ca;
}
.badge-failed {
    background: #fee2e2;
    color: #991b1b;
}
.badge-pending {
    background: #fef3c7;
    color: #92400e;
}

/* Table Styles - Better Contrast */
table tbody td {
    color: #1e293b;
    padding: 10px 12px;
    border-bottom: 1px solid #e2e8f0;
}
table tbody tr:hover {
    background: #f1f5f9;
}
table tbody tr:hover td {
    color: #0f172a;
}

/* Participants Table - High Contrast */
#participants-tbody td {
    color: #0f172a !important;
    font-weight: 500;
}
#participants-tbody td code {
    color: #1e40af !important;
    background: #eff6ff !important;
    padding: 4px 8px;
    border-radius: 4px;
    font-weight: 600;
}
#participants-tbody tr:hover td {
    color: #020617 !important;
    background: #e2e8f0;
}

/* Participant Actions */
.participant-actions {
    display: flex;
    gap: 4px;
    position: relative;
}
.btn-icon {
    padding: 4px 6px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    line-height: 1;
}
.btn-icon:hover {
    background: #f0f6fc;
    border-color: #3B6CAA;
}
.btn-icon-danger:hover {
    background: #fee2e2;
    border-color: #dc2626;
}

/* Pagination */
.pagination {
    display: flex;
    gap: 4px;
}
.page-btn {
    padding: 4px 10px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    font-size: 12px;
    color: #64748b;
    cursor: pointer;
}
.page-btn:hover {
    border-color: #3B6CAA;
    color: #3B6CAA;
}
.page-btn.active {
    background: #3B6CAA;
    color: #fff;
    border-color: #3B6CAA;
}

/* Modal sizing */
.participants-modal-wide {
    max-width: 800px;
    width: 90%;
}

/* Table row hover */
#participants-tbody tr:hover {
    background: #f8f9fa;
}
</style>

<!-- Add Participant Modal EIPSI -->
<div id="eipsi-add-participant-modal" class="eipsi-modal eipsi-force-light-mode" style="display:none; z-index: 100001;">
    <div class="eipsi-modal-content" style="max-width:450px;">
        <div class="eipsi-modal-header" style="border-bottom:1px solid #e2e8f0;padding:16px 20px;">
            <h2 style="font-size:16px;font-weight:600;color:#2c3e50;margin:0;">Agregar Participante</h2>
            <button class="eipsi-modal-close" style="background:none;border:none;font-size:20px;color:#64748b;cursor:pointer;">&times;</button>
        </div>
        <div class="eipsi-modal-body" style="padding:20px;">
            <form id="add-participant-form">
                <input type="hidden" id="add-participant-study-id">
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:12px;font-weight:500;color:#2c3e50;margin-bottom:4px;">Email *</label>
                    <input type="email" id="participant-email" required style="width:100%;padding:8px 12px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;" placeholder="participante@email.com">
                </div>
                <div id="add-participant-error" style="display:none;margin-bottom:12px;padding:10px;background:#fee2e2;border:1px solid #dc2626;border-radius:6px;color:#dc2626;font-size:12px;"></div>
                <div id="add-participant-success" style="display:none;margin-bottom:12px;padding:10px;background:#d1fae5;border:1px solid #059669;border-radius:6px;color:#065f46;font-size:12px;"></div>
            </form>
        </div>
        <div class="eipsi-modal-footer" style="border-top:1px solid #e2e8f0;padding:16px 20px;display:flex;justify-content:flex-end;gap:8px;">
            <button class="btn-sm" id="cancel-add-participant">Cancelar</button>
            <button class="btn-sm btn-primary" id="submit-add-participant">Agregar</button>
        </div>
    </div>
</div>

<!-- Email Logs Modal EIPSI -->
<div id="eipsi-email-logs-modal" class="eipsi-modal eipsi-force-light-mode" style="display:none; z-index: 100001;">
    <div class="eipsi-modal-content" style="max-width:700px;max-height:80vh;display:flex;flex-direction:column;">
        <div class="eipsi-modal-header" style="border-bottom:1px solid #e2e8f0;padding:16px 20px;">
            <h2 style="font-size:16px;font-weight:600;color:#2c3e50;margin:0;">Log de Emails</h2>
            <button class="eipsi-modal-close" style="background:none;border:none;font-size:20px;color:#64748b;cursor:pointer;">&times;</button>
        </div>
        <div class="eipsi-modal-body" style="padding:20px;flex:1;overflow:auto;">
            <div style="display:flex;gap:12px;margin-bottom:16px;align-items:center;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:12px;color:#64748b;">Estado:</label>
                    <select id="email-log-status-filter" style="padding:5px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;">
                        <option value="all">Todos</option>
                        <option value="sent">Enviado</option>
                        <option value="delivered">Entregado</option>
                        <option value="clicked">Click</option>
                        <option value="failed">Fallido</option>
                    </select>
                </div>
                <div style="display:flex;align-items:center;gap:6px;flex:1;min-width:150px;">
                    <label style="font-size:12px;color:#64748b;">Buscar:</label>
                    <input type="text" id="email-log-search" placeholder="Email..." style="flex:1;padding:5px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;">
                </div>
                <button class="btn-sm" id="refresh-email-logs">Actualizar</button>
            </div>
            <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                <table style="width:100%;border-collapse:collapse;font-size:12px;">
                    <thead>
                        <tr style="background:#f8f9fa;">
                            <th style="padding:10px 12px;text-align:left;font-weight:600;color:#2c3e50;border-bottom:1px solid #e2e8f0;">Fecha</th>
                            <th style="padding:10px 12px;text-align:left;font-weight:600;color:#2c3e50;border-bottom:1px solid #e2e8f0;">Para</th>
                            <th style="padding:10px 12px;text-align:left;font-weight:600;color:#2c3e50;border-bottom:1px solid #e2e8f0;">Tipo</th>
                            <th style="padding:10px 12px;text-align:left;font-weight:600;color:#2c3e50;border-bottom:1px solid #e2e8f0;">Estado</th>
                        </tr>
                    </thead>
                    <tbody id="email-logs-tbody">
                        <tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:30px;">Cargando...</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="email-logs-pagination" style="margin-top:12px;display:flex;justify-content:center;"></div>
        </div>
    </div>
</div>

<!-- Recalculate Times Modal -->
<div id="eipsi-recalculate-modal" class="eipsi-modal eipsi-force-light-mode" style="display:none; z-index: 100002;">
    <div class="eipsi-modal-content" style="max-width:500px;">
        <div class="eipsi-modal-header" style="border-bottom:1px solid #e2e8f0;padding:16px 20px;">
            <h3 style="font-size:14px;font-weight:600;color:#2c3e50;margin:0;">⚙️ Recalcular Tiempos de Tomas</h3>
            <button class="eipsi-modal-close" style="background:none;border:none;font-size:20px;color:#64748b;cursor:pointer;">&times;</button>
        </div>
        <div class="eipsi-modal-body" style="padding:20px;">
            
            <!-- Warning -->
            <div style="background:#fff3cd;border:2px solid #ffc107;border-radius:8px;padding:14px;margin-bottom:16px;">
                <div style="font-size:12px;font-weight:600;color:#856404;margin-bottom:6px;">⚠️ Acción Avanzada</div>
                <div style="font-size:11px;color:#856404;line-height:1.5;">
                    Esta función modifica las fechas de disponibilidad de tomas futuras. Solo úsala si:
                    <ul style="margin:6px 0 0 16px;padding:0;">
                        <li>Cometiste un error en la configuración inicial</li>
                        <li>El protocolo del estudio cambió oficialmente</li>
                    </ul>
                </div>
            </div>

            <!-- Info -->
            <div style="background:#f0f6fc;border:1px solid #d6edff;border-radius:8px;padding:12px;margin-bottom:16px;">
                <div style="font-size:11px;color:#64748b;line-height:1.5;">
                    <strong style="color:#2c3e50;">¿Qué se modificará?</strong>
                    <ul style="margin:6px 0 0 16px;padding:0;">
                        <li>Fechas de disponibilidad de tomas no completadas</li>
                        <li>Fechas de vencimiento (si aplica)</li>
                        <li>Recordatorios programados</li>
                    </ul>
                </div>
            </div>

            <!-- Preview -->
            <div id="recalc-preview" style="background:#f8f9fa;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:16px;">
                <div style="font-size:12px;color:#64748b;margin-bottom:4px;">Impacto estimado:</div>
                <div style="font-size:13px;color:#2c3e50;">
                    <div id="recalc-participants-count">Calculando...</div>
                    <div id="recalc-assignments-count"></div>
                </div>
            </div>

            <!-- Confirmation -->
            <label style="display:flex;align-items:center;gap:8px;margin-bottom:16px;cursor:pointer;">
                <input type="checkbox" id="confirm-recalculation" style="cursor:pointer;">
                <span style="font-size:12px;color:#64748b;">Entiendo que esta acción modificará tiempos de participantes activos</span>
            </label>

            <!-- Actions -->
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button class="btn-sm eipsi-modal-close">Cancelar</button>
                <button class="btn-sm btn-primary" id="btn-recalculate" disabled>Recalcular ahora</button>
            </div>

            <!-- Result -->
            <div id="recalc-result" style="margin-top:12px;"></div>
        </div>
    </div>
</div>

<script>
(function($) {
    let currentStudyId = 0;

    // Open modal
    $('#action-recalculate-times').on('click', function() {
        currentStudyId = $(this).data('study-id') || window.currentStudyId || 0;
        $('#eipsi-recalculate-modal').show();
        loadRecalcPreview();
    });

    // Enable/disable button
    $('#confirm-recalculation').on('change', function() {
        $('#btn-recalculate').prop('disabled', !this.checked);
    });

    // Load preview
    function loadRecalcPreview() {
        $('#recalc-preview').html('<div style="text-align:center;color:#94a3b8;padding:10px;">Cargando...</div>');
        
        $.post(ajaxurl, {
            action: 'eipsi_recalculate_preview',
            study_id: currentStudyId,
            nonce: eipsiStudyDash.nonce
        }, function(response) {
            if (response.success) {
                const data = response.data;
                $('#recalc-participants-count').text(data.affected_participants + ' participantes afectados');
                $('#recalc-assignments-count').text(data.waves_to_update + ' tomas serán recalculadas');
            } else {
                $('#recalc-preview').html('<div style="color:#dc2626;font-size:12px;">Error al cargar preview</div>');
            }
        });
    }

    // Recalculate
    $('#btn-recalculate').on('click', function() {
        const $btn = $(this);
        $btn.prop('disabled', true).text('Recalculando...');
        
        $.post(ajaxurl, {
            action: 'eipsi_recalculate_waves',
            study_id: currentStudyId,
            nonce: eipsiStudyDash.nonce
        }, function(response) {
            const type = response.success ? 'success' : 'error';
            const color = response.success ? '#008080' : '#dc2626';
            const bg = response.success ? '#e8f5e9' : '#fee2e2';
            
            $('#recalc-result').html(
                '<div style="background:' + bg + ';border:1px solid ' + color + ';border-radius:6px;padding:10px;font-size:12px;color:' + color + ';">' +
                    (response.data.message || 'Operación completada') +
                '</div>'
            );
            
            $btn.prop('disabled', false).text('Recalcular ahora');
            $('#confirm-recalculation').prop('checked', false);
            
            if (response.success) {
                setTimeout(function() {
                    $('#eipsi-recalculate-modal').hide();
                    if (typeof eipsiLoadStudyDashboard === 'function') {
                        eipsiLoadStudyDashboard(currentStudyId);
                    }
                }, 2000);
            }
        });
    });
})(jQuery);
</script>

<!-- 
    TODA la funcionalidad JS está en assets/js/study-dashboard.js
    El JS externo maneja renderWaves, event delegation, y todos los handlers.
-->

</div>
