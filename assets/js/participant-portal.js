/**
 * EIPSI Forms - Participant Portal Authentication
 * 
 * Handles login, registration, magic link, and logout for participants
 * 
 * @package EIPSI_Forms
 * @since 2.0.0
 */

(function($) {
    'use strict';
    
    // Auth state
    var authState = {
        isLoading: false,
        lastAction: null
    };
    
    // Session timer state
    var sessionTimer = {
        intervalId: null,
        remainingSeconds: 0,
        showThreshold: 300, // 5 minutes in seconds
        warningThreshold: 60, // 1 minute in seconds
        isExtending: false,
        isExpired: false,
        currentWave: null
    };
    
    /**
     * Initialize auth handlers
     */
    function init() {
        // Tab switching
        $(document).on('click', '.eipsi-survey-login-tab', handleTabSwitch);
        $(document).on('click', '.switch-to-login, .switch-to-register', handleSwitchTab);

        // Form submissions
        $(document).on('submit', '#eipsi-participant-login-form', handleLoginSubmit);
        $(document).on('submit', '#eipsi-participant-register-form', handleRegisterSubmit);

        // Logout
        $(document).on('click', '#eipsi-logout-button', handleLogout);
        
        // Form validation
        initFormValidation();
        
        // Check session on page load
        checkSessionStatus();
        
        // Initialize session timer on authenticated pages
        initSessionTimer();
    }
    
    // =============================================================================
    // SESSION TIMER FUNCTIONS
    // =============================================================================
    
    /**
     * Initialize session timer on authenticated pages
     */
    function initSessionTimer() {
        var $dashboard = $('.eipsi-participant-dashboard');
        var $studyPage = $('.eipsi-longitudinal-study-container');
        
        if ($dashboard.length === 0 && $studyPage.length === 0) {
            return;
        }
        
        // Get current wave from URL or data attribute
        var urlParams = new URLSearchParams(window.location.search);
        sessionTimer.currentWave = urlParams.get('wave') || $('body').data('current-wave') || null;
        
        // Create timer bar if it doesn't exist
        createTimerBar();
        
        // Start checking session time
        checkSessionTime();
        
        // Check every 30 seconds
        sessionTimer.intervalId = setInterval(checkSessionTime, 30000);
    }
    
    /**
     * Create timer bar element
     */
    function createTimerBar() {
        if ($('#eipsi-session-timer-bar').length > 0) {
            return;
        }
        
        var timerBarHtml = '<div id="eipsi-session-timer-bar" class="eipsi-session-timer-bar" style="display: none;">' +
            '<div class="eipsi-timer-content">' +
                '<span class="eipsi-timer-icon">⏱️</span>' +
                '<span class="eipsi-timer-text">' + eipsiAuth.strings.session_expires_in + '</span>' +
                '<span class="eipsi-timer-time" id="eipsi-timer-time"></span>' +
            '</div>' +
            '<button type="button" class="eipsi-extend-session-btn" id="eipsi-extend-session-btn">' +
                '<span class="btn-text">' + eipsiAuth.strings.extend_session + '</span>' +
                '<span class="eipsi-spinner" style="display: none;"></span>' +
            '</button>' +
            '<button type="button" class="eipsi-timer-close" id="eipsi-timer-close" title="' + eipsiAuth.strings.hide_timer + '">×</button>' +
        '</div>';
        
        $('body').append(timerBarHtml);
        
        // Bind events
        $(document).on('click', '#eipsi-extend-session-btn', handleExtendSession);
        $(document).on('click', '#eipsi-timer-close', handleHideTimer);
    }
    
    /**
     * Check session time via AJAX
     */
    function checkSessionTime() {
        if (sessionTimer.isExpired) {
            return;
        }
        
        $.ajax({
            url: eipsiAuth.ajaxUrl,
            type: 'POST',
            data: {
                action: 'eipsi_check_session_time',
                nonce: eipsiAuth.nonce
            },
            success: function(response) {
                if (response.success) {
                    sessionTimer.remainingSeconds = response.data.remaining_seconds;
                    updateTimerDisplay();
                    
                    // Show timer bar if below threshold
                    if (sessionTimer.remainingSeconds <= sessionTimer.showThreshold) {
                        showTimerBar();
                    }
                    
                    // Show warning if below warning threshold
                    if (sessionTimer.remainingSeconds <= sessionTimer.warningThreshold && 
                        sessionTimer.remainingSeconds > 0) {
                        showWarningState();
                    }
                    
                    // Start countdown if below threshold
                    if (sessionTimer.remainingSeconds <= sessionTimer.showThreshold) {
                        startCountdown();
                    }
                } else {
                    // Session expired
                    handleSessionExpired();
                }
            },
            error: function() {
                // Network error - don't break the page
                if (window.console) {
                    console.log('EIPSI: Could not check session time');
                }
            }
        });
    }
    
    /**
     * Start countdown timer
     */
    function startCountdown() {
        // Clear existing countdown
        if (sessionTimer.countdownId) {
            clearInterval(sessionTimer.countdownId);
        }
        
        // Update every second
        sessionTimer.countdownId = setInterval(function() {
            if (sessionTimer.remainingSeconds > 0) {
                sessionTimer.remainingSeconds--;
                updateTimerDisplay();
                
                // Check for warning threshold
                if (sessionTimer.remainingSeconds <= sessionTimer.warningThreshold) {
                    showWarningState();
                }
                
                // Check for expiration
                if (sessionTimer.remainingSeconds <= 0) {
                    handleSessionExpired();
                }
            }
        }, 1000);
    }
    
    /**
     * Update timer display
     */
    function updateTimerDisplay() {
        var $timerTime = $('#eipsi-timer-time');
        if ($timerTime.length === 0) return;
        
        var minutes = Math.floor(sessionTimer.remainingSeconds / 60);
        var seconds = sessionTimer.remainingSeconds % 60;
        
        var displayText = '';
        if (minutes > 0) {
            displayText = minutes + ' ' + (minutes === 1 ? eipsiAuth.strings.minute : eipsiAuth.strings.minutes);
            if (seconds > 0 && minutes < 10) {
                displayText += ' ' + seconds + ' ' + (seconds === 1 ? eipsiAuth.strings.second : eipsiAuth.strings.seconds);
            }
        } else {
            displayText = seconds + ' ' + (seconds === 1 ? eipsiAuth.strings.second : eipsiAuth.strings.seconds);
        }
        
        $timerTime.text(displayText);
    }
    
    /**
     * Show timer bar
     */
    function showTimerBar() {
        var $timerBar = $('#eipsi-session-timer-bar');
        if ($timerBar.length === 0) return;
        
        $timerBar.slideDown(300);
    }
    
    /**
     * Show warning state
     */
    function showWarningState() {
        var $timerBar = $('#eipsi-session-timer-bar');
        $timerBar.addClass('eipsi-timer-warning');
        
        // Show warning message
        if (!$('#eipsi-session-warning').length) {
            var warningHtml = '<div id="eipsi-session-warning" class="eipsi-session-warning">' +
                '<span class="warning-icon">⚠️</span>' +
                '<span class="warning-text">' + eipsiAuth.strings.session_expiring_soon + '</span>' +
            '</div>';
            
            $timerBar.find('.eipsi-timer-content').after(warningHtml);
        }
    }
    
    /**
     * Handle session expired
     */
    function handleSessionExpired() {
        sessionTimer.isExpired = true;
        
        // Clear intervals
        if (sessionTimer.intervalId) clearInterval(sessionTimer.intervalId);
        if (sessionTimer.countdownId) clearInterval(sessionTimer.countdownId);
        
        // Show expired modal
        var expiredHtml = '<div id="eipsi-session-expired-modal" class="eipsi-modal-overlay">' +
            '<div class="eipsi-modal-content">' +
                '<div class="eipsi-modal-icon">🔒</div>' +
                '<h3 class="eipsi-modal-title">' + eipsiAuth.strings.session_expired_title + '</h3>' +
                '<p class="eipsi-modal-message">' + eipsiAuth.strings.session_expired_message + '</p>' +
                '<button type="button" class="eipsi-modal-btn" id="eipsi-login-redirect-btn">' +
                    eipsiAuth.strings.login_again +
                '</button>' +
            '</div>' +
        '</div>';
        
        $('body').append(expiredHtml);
        
        // Bind redirect button
        $(document).on('click', '#eipsi-login-redirect-btn', function() {
            redirectToLogin();
        });
    }
    
    /**
     * Redirect to login with current wave state
     */
    function redirectToLogin() {
        var currentUrl = window.location.href;
        var loginUrl = eipsiAuth.loginUrl || window.location.origin;
        
        // Preserve wave state in redirect
        var redirectUrl = loginUrl;
        if (sessionTimer.currentWave) {
            redirectUrl = loginUrl + (loginUrl.indexOf('?') > -1 ? '&' : '?') + 
                'redirect_to=' + encodeURIComponent(currentUrl);
        } else {
            redirectUrl = loginUrl + (loginUrl.indexOf('?') > -1 ? '&' : '?') + 
                'redirect_to=' + encodeURIComponent(currentUrl);
        }
        
        window.location.href = redirectUrl;
    }
    
    /**
     * Handle extend session
     */
    function handleExtendSession(e) {
        e.preventDefault();
        
        if (sessionTimer.isExtending) return;
        
        var $btn = $('#eipsi-extend-session-btn');
        var $btnText = $btn.find('.btn-text');
        var $spinner = $btn.find('.eipsi-spinner');
        
        // Set loading state
        sessionTimer.isExtending = true;
        $btn.prop('disabled', true);
        $btnText.hide();
        $spinner.show();
        
        $.ajax({
            url: eipsiAuth.ajaxUrl,
            type: 'POST',
            data: {
                action: 'eipsi_extend_session',
                nonce: eipsiAuth.nonce,
                ttl_hours: 168 // 7 days
            },
            success: function(response) {
                if (response.success) {
                    // Update remaining time
                    sessionTimer.remainingSeconds = response.data.remaining_seconds;
                    updateTimerDisplay();
                    
                    // Remove warning state
                    $('#eipsi-session-timer-bar').removeClass('eipsi-timer-warning');
                    $('#eipsi-session-warning').remove();
                    
                    // Show success message
                    showExtendSuccess();
                    
                    // Hide timer bar after extension
                    setTimeout(function() {
                        $('#eipsi-session-timer-bar').slideUp(300);
                    }, 3000);
                } else {
                    showError(response.data.message || eipsiAuth.strings.extend_error);
                }
            },
            error: function() {
                showError(eipsiAuth.strings.network_error);
            },
            complete: function() {
                sessionTimer.isExtending = false;
                $btn.prop('disabled', false);
                $btnText.show();
                $spinner.hide();
            }
        });
    }
    
    /**
     * Show extend success message
     */
    function showExtendSuccess() {
        var $timerBar = $('#eipsi-session-timer-bar');
        
        var successHtml = '<div class="eipsi-extend-success">' +
            '<span class="success-icon">✅</span>' +
            '<span class="success-text">' + eipsiAuth.strings.session_extended + '</span>' +
        '</div>';
        
        $timerBar.find('.eipsi-timer-content').after(successHtml);
        
        // Remove after 3 seconds
        setTimeout(function() {
            $('.eipsi-extend-success').fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
    }
    
    /**
     * Handle hide timer
     */
    function handleHideTimer(e) {
        e.preventDefault();
        $('#eipsi-session-timer-bar').slideUp(300);
    }
    
    /**
     * Handle tab switching
     */
    function handleTabSwitch(e) {
        e.preventDefault();
        
        var $tab = $(this);
        var targetTab = $tab.data('tab');
        
        // Update tab states
        $('.eipsi-survey-login-tab').removeClass('active');
        $tab.addClass('active');
        
        // Update pane visibility
        $('.eipsi-survey-login-pane').removeClass('active');
        var paneId = '#eipsi-' + targetTab + '-pane';
        $(paneId).addClass('active');
        
        // Clear any existing messages
        clearMessages();
    }
    
    /**
     * Handle switch to login/register links
     */
    function handleSwitchTab(e) {
        e.preventDefault();

        var targetTab = $(this).hasClass('switch-to-login') ? 'login' : 'register';
        $('.eipsi-survey-login-tab[data-tab="' + targetTab + '"]').trigger('click');
    }

    /**
     * Start passwordless access; email only requests a secret link
     */
    function handleLoginSubmit(e) {
        e.preventDefault();

        if (authState.isLoading) return;

        var $form = $(this);
        var $submitBtn = $form.find('button[type="submit"]');

        // Get form data
        var formData = {
            action: 'eipsi_participant_login',
            nonce: eipsiAuth.nonce,
            email: $form.find('input[name="email"]').val(),
            survey_id: $form.find('input[name="survey_id"]').val(),
            remember: $form.find('input[name="remember"]').is(':checked'),
            redirect_url: $form.closest('.eipsi-survey-login-container').data('redirect') || ''
        };

        // Validate
        if (!validateEmail(formData.email)) {
            showFieldError($form.find('input[name="email"]'), eipsiAuth.strings.invalid_email);
            return;
        }

        // Set loading state
        setLoadingState($submitBtn, true);
        authState.isLoading = true;
        authState.lastAction = 'login';

        // Send request
        $.ajax({
            url: eipsiAuth.ajaxUrl,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    showSuccess(response.data.message);

                    if (response.data.requires_email_link) {
                        return; // Await the link: no authenticated state or redirect yet.
                    }

                    // Redirect after short delay
                    setTimeout(function() {
                        window.location.href = response.data.redirect_url;
                    }, 1000);
                } else {
                    showError(response.data.message);

                    // Handle rate limiting
                    if (response.data.code === 'rate_limited') {
                        showRateLimitWarning(response.data.retry_after);
                    }

                    // Handle email exists - show login prompt
                    if (response.data.show_login_link) {
                        showLoginPrompt();
                    }
                }
            },
            error: function() {
                showError(eipsiAuth.strings.network_error);
            },
            complete: function() {
                setLoadingState($submitBtn, false);
                authState.isLoading = false;
            }
        });
    }
    
    /**
     * Handle registration form submission (passwordless - email + terms only)
     */
    function handleRegisterSubmit(e) {
        e.preventDefault();

        if (authState.isLoading) return;

        var $form = $(this);
        var $submitBtn = $form.find('button[type="submit"]');

        // Get form data
        var $termsCheckbox = $form.find('input[name="accept_terms"]');
        var formData = {
            action: 'eipsi_participant_register',
            nonce: eipsiAuth.nonce,
            email: $form.find('input[name="email"]').val(),
            study_code: $form.find('input[name="study_code"]').val(),
            survey_id: $form.find('input[name="survey_id"]').val(),
            accept_terms: $termsCheckbox.is(':checked') ? '1' : '',
            redirect_url: $form.closest('.eipsi-survey-login-container').data('redirect') || ''
        };

        // Validate
        if (!validateEmail(formData.email)) {
            showFieldError($form.find('input[name="email"]'), eipsiAuth.strings.invalid_email);
            return;
        }

        // Validate terms checkbox
        if (!formData.accept_terms) {
            showError(eipsiAuth.strings.terms_required);
            $termsCheckbox.closest('.eipsi-checkbox-label').addClass('has-error');
            return;
        }

        // Clear terms checkbox error if valid
        $termsCheckbox.closest('.eipsi-checkbox-label').removeClass('has-error');

        // Set loading state
        setLoadingState($submitBtn, true);
        authState.isLoading = true;
        authState.lastAction = 'register';

        // Send request
        $.ajax({
            url: eipsiAuth.ajaxUrl,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    showSuccess(response.data.message);

                    if (response.data.requires_login) {
                        // Switch to login tab
                        setTimeout(function() {
                            $('.eipsi-survey-login-tab[data-tab="login"]').trigger('click');
                        }, 2000);
                    } else if (response.data.redirect_url) {
                        // Redirect after short delay
                        setTimeout(function() {
                            window.location.href = response.data.redirect_url;
                        }, 1000);
                    }
                } else {
                    showError(response.data.message);

                    // Handle email exists - show login prompt
                    if (response.data.show_login_link) {
                        showLoginPrompt();
                    }
                }
            },
            error: function() {
                showError(eipsiAuth.strings.network_error);
            },
            complete: function() {
                setLoadingState($submitBtn, false);
                authState.isLoading = false;
            }
        });
    }

    /**
     * Handle logout
     */
    function handleLogout(e) {
        e.preventDefault();
        
        if (!confirm(eipsiAuth.strings.confirm_logout)) {
            return;
        }
        
        var $btn = $(this);
        var nonce = $btn.data('nonce');
        
        setLoadingState($btn, true);
        
        $.ajax({
            url: eipsiAuth.ajaxUrl,
            type: 'POST',
            data: {
                action: 'eipsi_participant_logout',
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    window.location.href = response.data.redirect_url;
                } else {
                    showError(response.data.message);
                    setLoadingState($btn, false);
                }
            },
            error: function() {
                showError(eipsiAuth.strings.network_error);
                setLoadingState($btn, false);
            }
        });
    }

    /**
     * Check session status
     */
    function checkSessionStatus() {
        var $container = $('.eipsi-survey-login-container');
        if ($container.length === 0) return;
        
        $.ajax({
            url: eipsiAuth.ajaxUrl,
            type: 'POST',
            data: {
                action: 'eipsi_participant_check_session',
                nonce: eipsiAuth.nonce
            },
            success: function(response) {
                if (response.success) {
                    // User is logged in - redirect to study
                    var redirectUrl = $container.data('redirect') || '';
                    if (redirectUrl) {
                        window.location.href = redirectUrl;
                    }
                }
            }
        });
    }
    
    /**
     * Initialize form validation
     */
    function initFormValidation() {
        // Real-time email validation
        $(document).on('blur', 'input[type="email"]', function() {
            var email = $(this).val();
            var $wrapper = $(this).closest('.eipsi-input-wrapper');
            
            if (email && !validateEmail(email)) {
                $wrapper.addClass('has-error');
                $wrapper.find('.eipsi-valid-icon').hide();
            } else if (email) {
                $wrapper.removeClass('has-error');
                $wrapper.addClass('has-success');
                $wrapper.find('.eipsi-valid-icon').show();
            }
        });
        
        // Real-time password confirmation
        $(document).on('blur', '#register-confirm-password', function() {
            var password = $('#register-password').val();
            var confirm = $(this).val();
            
            if (confirm && password !== confirm) {
                showFieldError($(this), eipsiAuth.strings.password_mismatch);
            } else {
                clearFieldError($(this));
            }
        });
    }
    
    /**
     * Validate email format
     */
    function validateEmail(email) {
        var re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }
    
    /**
     * Show error message
     */
    function showError(message) {
        var $container = $('.eipsi-survey-login-content');
        clearMessages();
        
        var $error = $('<div class="eipsi-auth-message eipsi-auth-error">' +
            '<span class="message-icon">❌</span>' +
            '<span class="message-text">' + message + '</span>' +
            '</div>');
        
        $container.prepend($error);
        
        // Scroll to message
        $('html, body').animate({
            scrollTop: $error.offset().top - 100
        }, 300);
    }
    
    /**
     * Show success message
     */
    function showSuccess(message) {
        var $container = $('.eipsi-survey-login-content');
        clearMessages();
        
        var $success = $('<div class="eipsi-auth-message eipsi-auth-success">' +
            '<span class="message-icon">✅</span>' +
            '<span class="message-text">' + message + '</span>' +
            '</div>');
        
        $container.prepend($success);
    }
    
    /**
     * Show field-specific error
     */
    function showFieldError($field, message) {
        var $formGroup = $field.closest('.eipsi-form-group');
        $formGroup.addClass('has-error');
        
        // Remove existing error message
        $formGroup.find('.field-error').remove();
        
        // Add error message
        $formGroup.append('<div class="field-error">' + message + '</div>');
        
        // Focus field
        $field.focus();
    }
    
    /**
     * Clear field-specific error
     */
    function clearFieldError($field) {
        var $formGroup = $field.closest('.eipsi-form-group');
        $formGroup.removeClass('has-error');
        $formGroup.find('.field-error').remove();
    }
    
    /**
     * Clear all messages
     */
    function clearMessages() {
        $('.eipsi-auth-message').remove();
        $('.field-error').remove();
        $('.has-error').removeClass('has-error');
    }
    
    /**
     * Show rate limit warning
     */
    function showRateLimitWarning(retryAfter) {
        var minutes = Math.ceil(retryAfter / 60);
        var message = eipsiAuth.strings.rate_limited.replace('%d', minutes);
        showError(message);
    }
    
    /**
     * Show login prompt for existing email
     */
    function showLoginPrompt() {
        var $container = $('.eipsi-survey-login-content');
        
        // Remove existing prompt
        $('.eipsi-login-prompt').remove();
        
        var $prompt = $('<div class="eipsi-login-prompt">' +
            '<p>' + eipsiAuth.strings.email_exists + '</p>' +
            '<a href="#" class="switch-to-login">' + eipsiAuth.strings.login_here + '</a>' +
            '</div>');
        
        $container.prepend($prompt);
    }
    
    /**
     * Set loading state on button
     */
    function setLoadingState($btn, isLoading) {
        if (isLoading) {
            $btn.prop('disabled', true);
            $btn.find('.button-text').hide();
            $btn.find('.eipsi-spinner').show();
        } else {
            $btn.prop('disabled', false);
            $btn.find('.button-text').show();
            $btn.find('.eipsi-spinner').hide();
        }
    }
    
    // Initialize on document ready
    $(document).ready(init);
    
})(jQuery);
