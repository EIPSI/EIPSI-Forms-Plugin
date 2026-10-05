    function initConsentV2() {

    /**
     * Muestra un modal de confirmación con diseño EIPSI
     * Reutiliza tokens de diseño EIPSI.
     */
    function showConfirmationModal(title, message, confirmText = 'Confirmar', cancelText = 'Cancelar') {
        return new Promise((resolve) => {
            const modalId = 'eipsi-confirmation-modal';
            let modal = document.getElementById(modalId);
            
            if (!modal) {
                modal = document.createElement('div');
                modal.id = modalId;
                
                // Estilos inyectados para el modal
                const style = document.createElement('style');
                style.textContent = `
                    .eipsi-modal-overlay {
                        position: fixed;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 100%;
                        background: rgba(0, 0, 0, 0.5);
                        display: none;
                        justify-content: center;
                        align-items: center;
                        z-index: 10000;
                        backdrop-filter: blur(2px);
                    }
                    .eipsi-modal-card {
                        background: var(--eipsi-bg, #ffffff);
                        padding: var(--eipsi-space-lg, 1.5rem);
                        border-radius: var(--eipsi-radius, 8px);
                        box-shadow: var(--eipsi-shadow-lg, 0 4px 16px rgba(0, 0, 0, 0.1));
                        max-width: 450px;
                        width: 90%;
                        animation: eipsi-modal-in 0.3s ease-out;
                    }
                    @keyframes eipsi-modal-in {
                        from { transform: translateY(20px); opacity: 0; }
                        to { transform: translateY(0); opacity: 1; }
                    }
                    .eipsi-modal-content h3 {
                        margin-top: 0;
                        color: var(--eipsi-text, #2c3e50);
                        font-size: var(--eipsi-font-xl, 1.25rem);
                    }
                    .eipsi-modal-content p {
                        color: var(--eipsi-text-muted, #64748b);
                        line-height: 1.5;
                        margin-bottom: var(--eipsi-space-lg, 1.5rem);
                    }
                    .eipsi-modal-actions {
                        display: flex;
                        justify-content: flex-end;
                        gap: var(--eipsi-space-md, 1rem);
                    }
                    .eipsi-modal-actions .eipsi-btn {
                        padding: var(--eipsi-space-sm, 0.5rem) var(--eipsi-space-lg, 1.5rem);
                        border-radius: var(--eipsi-radius-sm, 4px);
                        font-weight: 600;
                        cursor: pointer;
                        border: none;
                        transition: var(--eipsi-transition, all 0.2s ease);
                    }
                    .eipsi-modal-actions .eipsi-btn-secondary {
                        background: var(--eipsi-bg-muted, #f1f5f9);
                        color: var(--eipsi-text, #2c3e50);
                    }
                    .eipsi-modal-actions .eipsi-btn-secondary:hover {
                        background: var(--eipsi-border, #e2e8f0);
                    }
                    .eipsi-modal-actions .eipsi-btn-danger {
                        background: var(--eipsi-error, #800000);
                        color: white;
                    }
                    .eipsi-modal-actions .eipsi-btn-danger:hover {
                        background: var(--eipsi-error-text, #660000);
                    }
                `;
                document.head.appendChild(style);
                
                modal.className = 'eipsi-modal-overlay';
                document.body.appendChild(modal);
            }
            
            modal.innerHTML = `
                <div class="eipsi-modal-card">
                    <div class="eipsi-modal-content">
                        <h3>${title}</h3>
                        <p>${message}</p>
                    </div>
                    <div class="eipsi-modal-actions">
                        <button class="eipsi-btn eipsi-btn-secondary" id="eipsi-modal-cancel">${cancelText}</button>
                        <button class="eipsi-btn eipsi-btn-danger" id="eipsi-modal-confirm">${confirmText}</button>
                    </div>
                </div>
            `;
            
            modal.style.display = 'flex';
            
            const handleConfirm = () => {
                modal.style.display = 'none';
                resolve(true);
            };
            
            const handleCancel = () => {
                modal.style.display = 'none';
                resolve(false);
            };
            
            modal.querySelector('#eipsi-modal-confirm').onclick = handleConfirm;
            modal.querySelector('#eipsi-modal-cancel').onclick = handleCancel;
        });
    }

        const consentBlocks = document.querySelectorAll('.eipsi-consent-block');
        
        consentBlocks.forEach((block) => { if (block.dataset.consentInitialized) return; block.dataset.consentInitialized = "true";
            const readingCheckbox = block.querySelector('#eipsi-consent-confirm-reading');
            const acceptBtn = block.querySelector('.eipsi-btn-accept');
            const rejectBtn = block.querySelector('.eipsi-btn-reject');
            const decisionInput = block.querySelector('#eipsi-consent-decision');
            const errorMsg = block.querySelector('.eipsi-consent-error');
            const originalRejectText = rejectBtn ? rejectBtn.textContent : 'No deseo participar';
            const originalAcceptText = acceptBtn ? acceptBtn.textContent : 'Acepto participar';
            
            if (!readingCheckbox || !acceptBtn || !rejectBtn) {
                return;
            }
            
            // Gate: Habilitar "Aceptar" solo cuando se marca el checkbox de lectura
            readingCheckbox.addEventListener('change', () => {
                const isChecked = readingCheckbox.checked;
                acceptBtn.disabled = !isChecked;
                acceptBtn.setAttribute('aria-disabled', !isChecked);
                
                if (errorMsg) {
                    errorMsg.style.display = 'none';
                }
                
            });
            
            // Click en "No deseo participar" (Rechazo T1)
            rejectBtn.addEventListener('click', async () => {
                const confirmed = await showConfirmationModal(
                    "¿Estás seguro?",
                    "¿Estás seguro de que no deseas participar? Esta decisión es definitiva y no podrás acceder a las encuestas.",
                    "Rechazar",
                    "Cancelar"
                );

                if (!confirmed) {
                    return;
                }
                rejectBtn.disabled = true;
                rejectBtn.textContent = 'Procesando...';
                
                // Guardar decisión en campo oculto
                if (decisionInput) {
                    decisionInput.value = 'declined';
                }
                
                // Guardar en backend (consent_declined) y redirigir
                try {
                    const result = await saveConsentDecision(block, 'declined');
                    if (!result || !result.success) {
                        throw new Error(result?.data?.message || 'Error saving declined consent');
                    }
                    // Usar URL del response o fallback
                    const redirectUrl = result?.data?.redirect || '/';
                    window.location.href = redirectUrl;
                } catch (e) {
                    rejectBtn.disabled = false;
                    rejectBtn.textContent = originalRejectText;
                    alert(e?.message || 'No se pudo registrar tu decisión. Intentá nuevamente.');
                }
            });
            
            // Click en "Acepto participar"
            acceptBtn.addEventListener('click', async () => { const form = block.closest("form"); if (form && form.dataset.submitting === "true") return;
                if (!readingCheckbox.checked) {
                    if (errorMsg) {
                        errorMsg.style.display = 'block';
                        errorMsg.textContent = 'Debes confirmar que leíste el consentimiento informado para continuar.';
                    }
                    return;
                }
                
                if (form) form.dataset.submitting = "true"; acceptBtn.disabled = true;
                acceptBtn.textContent = 'Procesando...';
                
                // Guardar decisión en campo oculto
                if (decisionInput) {
                    decisionInput.value = 'accepted';
                }
                
                // Guardar en backend
                try {
                    const result = await saveConsentDecision(block, 'accepted');
                    if (!result || !result.success) {
                        throw new Error(result?.data?.message || 'Error saving accepted consent');
                    }
                } catch (e) {
                    acceptBtn.disabled = false;
                    acceptBtn.textContent = originalAcceptText;
                    if (form) delete form.dataset.submitting; alert(e?.message || "No se pudo registrar tu consentimiento. Intentá nuevamente.");
                    return;
                }
                
                // Marcar bloque como aceptado
                block.classList.add('consent-accepted');
                
                // Guardar estado actual con Save & Continue antes de avanzar
                // Esperar hasta 2 segundos a que Save & Continue se inicialice
                if (form) {
                    let attempts = 0;
                    const maxAttempts = 20; // 20 * 100ms = 2 segundos
                    
                    while (!form.eipsiSaveContinue && attempts < maxAttempts) {
                        await new Promise(resolve => setTimeout(resolve, 100));
                        attempts++;
                    }
                    
                    if (form.eipsiSaveContinue && typeof form.eipsiSaveContinue.queueSave === 'function') {
                        try {
                            await form.eipsiSaveContinue.queueSave('consent-accepted');
                            console.log('[S&C] Consent decision saved before page advance');
                        } catch (e) {
                            console.warn('[S&C] Failed to save consent decision:', e);
                        }
                    } else {
                        console.warn('[S&C] Save & Continue not initialized after 2s, proceeding without save');
                    }
                }
                
                // Avanzar a la siguiente página (como el botón "Siguiente")
                
                if (form && window.EIPSIForms && window.EIPSIForms.handlePagination) {
                    if (form) delete form.dataset.submitting; window.EIPSIForms.handlePagination(form, "next");
                } else {
                    acceptBtn.disabled = false;
                    acceptBtn.textContent = originalAcceptText;
                }
            });
        });
    }
    
    /**
     * Guardar decisión de consentimiento en backend
     */
    async function saveConsentDecision(block, decision) {
        const formId = block.closest('form')?.dataset?.formId || 'default';
        const participantId = window.eipsiAuth?.participantId || '';
        const payload = new URLSearchParams({
            action: 'eipsi_save_consent_decision',
            nonce: window.eipsiFormsConfig?.nonce || '',
            decision: decision,
            form_id: formId,
            participant_id: participantId,
            timestamp: new Date().toISOString()
        });
        
        const ajaxUrl = window.eipsiFormsConfig?.ajaxUrl || '/wp-admin/admin-ajax.php';
        
        const response = await fetch(ajaxUrl, {
            method: 'POST',
            body: payload,
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            credentials: 'same-origin'
        });
        
        return response.json();
    }
    
