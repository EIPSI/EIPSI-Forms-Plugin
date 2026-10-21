/**
 * EIPSI Participant Authentication JavaScript
 * 
 * Maneja las interacciones de frontend para registro, login y logout
 * de participantes en estudios longitudinales.
 * 
 * @package EIPSI_Forms
 * @since 1.4.0
 */

(function($) {
    'use strict';

    /**
     * Configuración global del sistema de autenticación
     */
    window.EIPSIParticipantAuth = {
        
        /**
         * Inicializar el sistema de autenticación
         */
        init: function() {
            // Inicializar event listeners
            this.initEventListeners();
            
            // Verificar estado de autenticación
            this.checkAuthState();
            
            console.log('[EIPSI Auth] Sistema de autenticación inicializado');
        },
        
        /**
         * Inicializar event listeners
         */
        initEventListeners: function() {
            // Formulario de registro
            $(document).on('submit', '.eipsi-participant-register-form', this.handleRegister);
            
            // Formulario de login
            $(document).on('submit', '.eipsi-participant-login-form', this.handleLogin);
            
            // Botón de logout
            $(document).on('click', '.eipsi-participant-logout', this.handleLogout);
            
            // Verificar estado de autenticación
            $(document).on('click', '.eipsi-check-auth', this.checkAuthState);
            
            // Auto-check de estado (cada 30 segundos)
            setInterval(this.checkAuthState.bind(this), 30000);
        },
        
        /**
         * Manejar registro de participante
         */
        handleRegister: function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $submitBtn = $form.find('button[type="submit"]');
            var $btnText = $submitBtn.find('.button-text');
            var $spinner = $submitBtn.find('.eipsi-spinner');
            var originalText = $btnText.length ? $btnText.text() : $submitBtn.text();
            
            // Mostrar estado de carga
            if ($btnText.length) {
                $btnText.text(eipsiAuth.strings.registering);
            } else {
                $submitBtn.text(eipsiAuth.strings.registering);
            }
            if ($spinner.length) $spinner.show();
            $submitBtn.prop('disabled', true);
            $form.find('.error-message').remove();
            
            // Recopilar datos del formulario
            var formData = {
                action: 'eipsi_participant_register',
                nonce: eipsiAuth.nonce,
                survey_id: $form.find('[name="survey_id"]').val(),
                email: $form.find('[name="email"]').val(),
                password: $form.find('[name="password"]').val(),
                first_name: $form.find('[name="first_name"]').val(),
                last_name: $form.find('[name="last_name"]').val()
            };
            
            // Validación básica frontend
            if (!formData.email || !formData.password || !formData.survey_id) {
                window.EIPSIParticipantAuth.showError($form, 'Campos requeridos faltantes');
                if ($btnText.length) {
                    $btnText.text(originalText);
                } else {
                    $submitBtn.text(originalText);
                }
                if ($spinner.length) $spinner.hide();
                $submitBtn.prop('disabled', false);
                return;
            }
            
            if (formData.password.length < 8) {
                window.EIPSIParticipantAuth.showError($form, 'La contraseña debe tener al menos 8 caracteres');
                if ($btnText.length) {
                    $btnText.text(originalText);
                } else {
                    $submitBtn.text(originalText);
                }
                if ($spinner.length) $spinner.hide();
                $submitBtn.prop('disabled', false);
                return;
            }
            
            // Enviar AJAX request
            $.ajax({
                url: eipsiAuth.ajaxUrl,
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Registro exitoso
                        window.EIPSIParticipantAuth.showSuccess($form, response.data.message || 'Registro exitoso!');
                        
                        if (response.data.requires_email_link || response.data.requires_confirmation) {
                            return; // No reload or authentication event before proof of possession.
                        }

                        // Limpiar formulario
                        $form[0].reset();
                        
                        // Emitir evento personalizado
                        $(document).trigger('eipsi:participant_registered', [response.data]);
                        
                        // Redirigir después de un breve delay
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                        
                    } else {
                        // Error en registro
                        var errorMsg = response.data.message || 'Error en el registro';
                        window.EIPSIParticipantAuth.showError($form, errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[EIPSI Auth] Error en registro:', error);
                    window.EIPSIParticipantAuth.showError($form, 'Error de conexión. Intenta nuevamente.');
                },
                complete: function() {
                    if ($btnText.length) {
                        $btnText.text(originalText);
                    } else {
                        $submitBtn.text(originalText);
                    }
                    if ($spinner.length) $spinner.hide();
                    $submitBtn.prop('disabled', false);
                }
            });
        },
        
        /**
         * Manejar login de participante
         */
        handleLogin: function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $submitBtn = $form.find('button[type="submit"]');
            var $btnText = $submitBtn.find('.button-text');
            var $spinner = $submitBtn.find('.eipsi-spinner');
            var originalText = $btnText.length ? $btnText.text() : $submitBtn.text();
            
            // Mostrar estado de carga
            if ($btnText.length) {
                $btnText.text(eipsiAuth.strings.logging_in);
            } else {
                $submitBtn.text(eipsiAuth.strings.logging_in);
            }
            if ($spinner.length) $spinner.show();
            $submitBtn.prop('disabled', true);
            $form.find('.error-message').remove();
            
            // Recopilar datos del formulario
            var formData = {
                action: 'eipsi_participant_login',
                nonce: eipsiAuth.nonce,
                survey_id: $form.find('[name="survey_id"]').val(),
                email: $form.find('[name="email"]').val(),
                password: $form.find('[name="password"]').val()
            };
            
            // Validación básica frontend
            if (!formData.email || !formData.survey_id) {
                window.EIPSIParticipantAuth.showError($form, 'Campos requeridos faltantes');
                if ($btnText.length) {
                    $btnText.text(originalText);
                } else {
                    $submitBtn.text(originalText);
                }
                if ($spinner.length) $spinner.hide();
                $submitBtn.prop('disabled', false);
                return;
            }
            
            // Enviar AJAX request
            $.ajax({
                url: eipsiAuth.ajaxUrl,
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Login exitoso
                        window.EIPSIParticipantAuth.showSuccess($form, response.data.message || 'Login exitoso!');
                        
                        if (response.data.requires_email_link) {
                            return; // Initiation is not participant_logged_in.
                        }

                        // Limpiar formulario
                        $form[0].reset();
                        
                        // Emitir evento personalizado
                        $(document).trigger('eipsi:participant_logged_in', [response.data]);
                        
                        // Redirigir si hay URL de redirect
                        if (response.data.redirect) {
                            setTimeout(function() {
                                window.location.href = response.data.redirect;
                            }, 1000);
                        } else {
                            setTimeout(function() {
                                window.location.reload();
                            }, 1000);
                        }
                        
                    } else {
                        // Error en login
                        var errorMsg = response.data.message || 'Error en el login';
                        window.EIPSIParticipantAuth.showError($form, errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[EIPSI Auth] Error en login:', error);
                    window.EIPSIParticipantAuth.showError($form, 'Error de conexión. Intenta nuevamente.');
                },
                complete: function() {
                    if ($btnText.length) {
                        $btnText.text(originalText);
                    } else {
                        $submitBtn.text(originalText);
                    }
                    if ($spinner.length) $spinner.hide();
                    $submitBtn.prop('disabled', false);
                }
            });
        },
        
        /**
         * Manejar logout de participante
         */
        handleLogout: function(e) {
            e.preventDefault();
            
            // Confirmar logout
            if (!confirm(eipsiAuth.strings.confirm_logout || '¿Estás seguro de que quieres cerrar sesión?')) {
                return;
            }
            
            var $btn = $(this);
            var originalText = $btn.text();
            $btn.text(eipsiAuth.strings.loading).prop('disabled', true);
            
            $.ajax({
                url: eipsiAuth.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eipsi_participant_logout',
                    nonce: eipsiAuth.nonce
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Logout exitoso
                        $(document).trigger('eipsi:participant_logged_out');
                        
                        // Recargar página o redirigir
                        if (response.data.redirect) {
                            window.location.href = response.data.redirect;
                        } else {
                            window.location.reload();
                        }
                    } else {
                        console.error('[EIPSI Auth] Error en logout:', response);
                        alert('Error al cerrar sesión. Intenta nuevamente.');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[EIPSI Auth] Error en logout:', error);
                    alert('Error de conexión. Intenta nuevamente.');
                },
                complete: function() {
                    $btn.text(originalText).prop('disabled', false);
                }
            });
        },
        
        /**
         * Verificar estado de autenticación
         */
        checkAuthState: function() {
            $.ajax({
                url: eipsiAuth.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eipsi_participant_info',
                    nonce: eipsiAuth.nonce
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Usuario autenticado
                        window.EIPSIParticipantAuth.setAuthState(true, response.data);
                        $(document).trigger('eipsi:auth_state_checked', [true, response.data]);
                    } else {
                        // Usuario no autenticado
                        window.EIPSIParticipantAuth.setAuthState(false);
                        $(document).trigger('eipsi:auth_state_checked', [false]);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[EIPSI Auth] Error verificando estado:', error);
                    window.EIPSIParticipantAuth.setAuthState(false);
                    $(document).trigger('eipsi:auth_state_checked', [false]);
                }
            });
        },
        
        /**
         * Establecer estado de autenticación en la UI
         */
        setAuthState: function(isAuthenticated, participantData) {
            $('body').removeClass('eipsi-authenticated eipsi-not-authenticated');
            
            if (isAuthenticated) {
                $('body').addClass('eipsi-authenticated');
                
                // Actualizar elementos con datos del participante
                if (participantData) {
                    $('.eipsi-participant-email').text(participantData.email);
                    $('.eipsi-participant-name').text(
                        participantData.first_name && participantData.last_name 
                        ? participantData.first_name + ' ' + participantData.last_name
                        : participantData.email
                    );
                    
                    // Mostrar elementos que requieren autenticación
                    $('.eipsi-requires-auth').show();
                    $('.eipsi-requires-no-auth').hide();
                }
                
            } else {
                $('body').addClass('eipsi-not-authenticated');
                
                // Ocultar elementos que requieren autenticación
                $('.eipsi-requires-auth').hide();
                $('.eipsi-requires-no-auth').show();
            }
        },
        
        /**
         * Mostrar mensaje de error
         */
        showError: function($container, message) {
            var $error = $('<div class="error-message" role="alert" aria-live="assertive" style="color: #d63384; margin: 10px 0;">' + message + '</div>');
            $container.prepend($error);
            
            // Auto-remover después de 5 segundos
            setTimeout(function() {
                $error.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        },
        
        /**
         * Mostrar mensaje de éxito
         */
        showSuccess: function($container, message) {
            var $success = $('<div class="success-message" role="status" style="color: #198754; margin: 10px 0;">' + message + '</div>');
            $container.prepend($success);
            
            // Auto-remover después de 3 segundos
            setTimeout(function() {
                $success.fadeOut(function() {
                    $(this).remove();
                });
            }, 3000);
        },
        
        /**
         * API pública para verificar autenticación
         */
        isAuthenticated: function() {
            return $('body').hasClass('eipsi-authenticated');
        },
        
        /**
         * API pública para obtener datos del participante
         */
        getParticipantData: function() {
            return window.currentParticipantData || null;
        }
    };
    
    /**
     * Inicializar cuando el DOM esté listo
     */
    $(document).ready(function() {
        window.EIPSIParticipantAuth.init();
    });
    
    /**
     * Event listeners personalizados para que los desarrolladores puedan engancharse
     */
    $(document)
        .on('eipsi:participant_registered', function(e, data) {
            console.log('[EIPSI Auth] Participante registrado:', data);
        })
        .on('eipsi:participant_logged_in', function(e, data) {
            console.log('[EIPSI Auth] Login exitoso:', data);
        })
        .on('eipsi:participant_logged_out', function(e) {
            console.log('[EIPSI Auth] Logout exitoso');
        })
        .on('eipsi:auth_state_checked', function(e, isAuthenticated, data) {
            // Guardar datos del participante para uso global
            if (isAuthenticated && data) {
                window.currentParticipantData = data;
            } else {
                window.currentParticipantData = null;
            }
        });
    
})(jQuery);