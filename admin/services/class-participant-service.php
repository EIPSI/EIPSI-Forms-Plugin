<?php
/**
 * EIPSI_Participant_Service
 *
 * Gestiona participantes y su ciclo de vida en estudios longitudinales:
 * - CRUD de participantes
 * - Status tracking (active/inactive)
 * - Password management
 *
 * @package EIPSI_Forms
 * @subpackage Services
 * @version 1.4.2
 * @since 1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class EIPSI_Participant_Service {
    
    /**
     * Create participant for survey.
     *
     * Valida email, password (opcional) y crea registro en wp_survey_participants.
     *
     * @param int    $survey_id ID del survey.
     * @param string $email Email del participante (será sanitizado).
     * @param string|null $password Password en texto plano (opcional, null para passwordless).
     * @param array  $metadata Datos adicionales (first_name, last_name).
     * @return array { success: bool, participant_id: int|null, error: string|null }
     * @since 1.4.0
     * @access public
     */
    public static function create_participant($survey_id, $email, $password = null, $metadata = array()) {
        global $wpdb;

        try {
            // Validar email con is_email()
            if (!is_email($email)) {
                return array(
                    'success' => false,
                    'participant_id' => null,
                    'error' => 'invalid_email'
                );
            }

            // Sanitizar email
            $email = sanitize_email($email);

            // Validar password solo si se proporciona (mínimo 8 caracteres, no espacios-only)
            if ($password !== null) {
                if (strlen($password) < 8 || trim($password) === '') {
                    return array(
                        'success' => false,
                        'participant_id' => null,
                        'error' => 'short_password'
                    );
                }
            }

            // Sanitizar metadata
            $first_name = isset($metadata['first_name']) ? sanitize_text_field($metadata['first_name']) : '';
            $last_name = isset($metadata['last_name']) ? sanitize_text_field($metadata['last_name']) : '';

            // Hash password o generar hash aleatorio para passwordless
            if ($password !== null) {
                $password_hash = wp_hash_password($password);
            } else {
                // Passwordless: generar hash aleatorio para satisfacer constraint de DB
                $temp_password = wp_generate_password(32, true, true);
                $password_hash = wp_hash_password($temp_password);
            }

            // Verificar UNIQUE(survey_id, email)
            $table_name = $wpdb->prefix . 'survey_participants';
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_name WHERE survey_id = %d AND email = %s",
                $survey_id,
                $email
            ));

            if ($existing) {
                return array(
                    'success' => false,
                    'participant_id' => null,
                    'error' => 'email_exists'
                );
            }

            // INSERT en wp_survey_participants
            $result = $wpdb->insert(
                $table_name,
                array(
                    'survey_id' => $survey_id,
                    'email' => $email,
                    'password_hash' => $password_hash,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'created_at' => current_time('mysql'),
                    'is_active' => 1
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%d')
            );

            if ($result === false) {
                // Log error pero no mostrar al usuario
                error_log('EIPSI Participant creation failed: ' . $wpdb->last_error);
                return array(
                    'success' => false,
                    'participant_id' => null,
                    'error' => 'db_error'
                );
            }

            return array(
                'success' => true,
                'participant_id' => (int) $wpdb->insert_id,
                'error' => null
            );

        } catch (Exception $e) {
            error_log('EIPSI Participant creation exception: ' . $e->getMessage());
            return array(
                'success' => false,
                'participant_id' => null,
                'error' => 'db_error'
            );
        }
    }

    /**
     * Create participant with explicit active status.
     *
     * This is used for double opt-in flow where participant starts as inactive.
     *
     * @param int         $survey_id ID del survey.
     * @param string      $email Email del participante.
     * @param string|null $password Password en texto plano (opcional).
     * @param array       $metadata Datos adicionales (first_name, last_name).
     * @param bool        $is_active Initial active status (default true).
     * @return array { success: bool, participant_id: int|null, error: string|null }
     * @since 1.5.7
     * @access public
     */
    public static function create_participant_with_status($survey_id, $email, $password = null, $metadata = array(), $is_active = true) {
        global $wpdb;

        try {
            // Validar email con is_email()
            if (!is_email($email)) {
                return array(
                    'success' => false,
                    'participant_id' => null,
                    'error' => 'invalid_email'
                );
            }

            // Sanitizar email
            $email = sanitize_email($email);

            // Validar password solo si se proporciona
            if ($password !== null) {
                if (strlen($password) < 8 || trim($password) === '') {
                    return array(
                        'success' => false,
                        'participant_id' => null,
                        'error' => 'short_password'
                    );
                }
            }

            // Sanitizar metadata
            $first_name = isset($metadata['first_name']) ? sanitize_text_field($metadata['first_name']) : '';
            $last_name = isset($metadata['last_name']) ? sanitize_text_field($metadata['last_name']) : '';

            // Hash password o generar hash aleatorio para passwordless
            if ($password !== null) {
                $password_hash = wp_hash_password($password);
            } else {
                $temp_password = wp_generate_password(32, true, true);
                $password_hash = wp_hash_password($temp_password);
            }

            // Verificar UNIQUE(survey_id, email)
            $table_name = $wpdb->prefix . 'survey_participants';
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_name WHERE survey_id = %d AND email = %s",
                $survey_id,
                $email
            ));

            if ($existing) {
                return array(
                    'success' => false,
                    'participant_id' => null,
                    'error' => 'email_exists'
                );
            }

            // INSERT en wp_survey_participants with explicit is_active status
            $result = $wpdb->insert(
                $table_name,
                array(
                    'survey_id' => $survey_id,
                    'email' => $email,
                    'password_hash' => $password_hash,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'created_at' => current_time('mysql'),
                    'is_active' => $is_active ? 1 : 0
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%d')
            );

            if ($result === false) {
                error_log('EIPSI Participant creation failed: ' . $wpdb->last_error);
                return array(
                    'success' => false,
                    'participant_id' => null,
                    'error' => 'db_error'
                );
            }

            return array(
                'success' => true,
                'participant_id' => (int) $wpdb->insert_id,
                'error' => null
            );

        } catch (Exception $e) {
            error_log('EIPSI Participant creation exception: ' . $e->getMessage());
            return array(
                'success' => false,
                'participant_id' => null,
                'error' => 'db_error'
            );
        }
    }
    
    /**
     * Get participant by email.
     *
     * @param int    $survey_id ID del survey.
     * @param string $email Email del participante.
     * @return object|null Fila de wp_survey_participants.
     * @since 1.4.0
     * @access public
     */
    public static function get_by_email($survey_id, $email) {
        global $wpdb;
        
        // Sanitizar email
        $email = sanitize_email($email);
        
        $table_name = $wpdb->prefix . 'survey_participants';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE survey_id = %d AND email = %s",
            $survey_id,
            $email
        ));
    }
    
    /**
     * Get participant by ID.
     *
     * @param int $participant_id ID del participante.
     * @return object|null Participante o null si no existe.
     * @since 1.4.0
     * @access public
     */
    public static function get_by_id($participant_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'survey_participants';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $participant_id
        ));
    }
    
    /**
     * Verify participant password.
     *
     * @param int    $participant_id ID del participante.
     * @param string $plain_password Password en texto plano.
     * @return bool True si el password es válido.
     * @since 1.4.0
     * @access public
     */
    public static function verify_password($participant_id, $plain_password) {
        global $wpdb;
        
        // Obtener participante
        $participant = self::get_by_id($participant_id);
        if (!$participant) {
            return false;
        }
        
        // Verificar si está activo
        if (!$participant->is_active) {
            return false;
        }
        
        // Usar wp_check_password para verificar
        $is_valid = wp_check_password($plain_password, $participant->password_hash, $participant_id);
        
        // Log para rate limiting (implementado en Auth_Service)
        return $is_valid;
    }
    
    /**
     * Update last login timestamp.
     *
     * @param int $participant_id ID del participante.
     * @return bool True si actualizó correctamente.
     * @since 1.4.0
     * @access public
     */
    public static function update_last_login($participant_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'survey_participants';
        $result = $wpdb->update(
            $table_name,
            array('last_login_at' => current_time('mysql')),
            array('id' => $participant_id),
            array('%s'),
            array('%d')
        );
        
        return $result !== false;
    }
    
    /**
     * Set active/inactive participant status.
     *
     * @param int  $participant_id ID del participante.
     * @param bool $is_active Estado (true = activo, false = inactivo).
     * @return bool True si se actualizó correctamente.
     * @since 1.4.0
     * @access public
     */
    public static function set_active($participant_id, $is_active) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'survey_participants';
        $result = $wpdb->update(
            $table_name,
            array('is_active' => $is_active ? 1 : 0),
            array('id' => $participant_id),
            array('%d'),
            array('%d')
        );
        
        return $result !== false;
    }
    
    /**
     * Change participant password.
     *
     * @param int    $participant_id ID del participante.
     * @param string $password_old Password actual.
     * @param string $password_new Password nuevo.
     * @return array { success: bool, error: string|null }
     * @since 1.4.0
     * @access public
     */
    public static function change_password($participant_id, $password_old, $password_new) {
        global $wpdb;
        
        // Verificar password actual
        if (!self::verify_password($participant_id, $password_old)) {
            return array(
                'success' => false,
                'error' => 'invalid_password'
            );
        }
        
        // Validar password nuevo: mínimo 8 chars
        if (strlen($password_new) < 8) {
            return array(
                'success' => false,
                'error' => 'short_password'
            );
        }
        
        // Hash password nuevo
        $password_hash = wp_hash_password($password_new);
        
        // UPDATE password_hash
        $table_name = $wpdb->prefix . 'survey_participants';
        $result = $wpdb->update(
            $table_name,
            array('password_hash' => $password_hash),
            array('id' => $participant_id),
            array('%s'),
            array('%d')
        );
        
        if ($result === false) {
            error_log('EIPSI Password change failed: ' . $wpdb->last_error);
            return array(
                'success' => false,
                'error' => 'db_error'
            );
        }
        
        return array(
            'success' => true,
            'error' => null
        );
    }
    
    /**
     * List participants with pagination and filters.
     *
     * @param int   $survey_id ID del survey.
     * @param int   $page Página (default 1).
     * @param int   $per_page Registros por página (default 50).
     * @param array $filters Filtros: status, search.
     * @return array { total, participants, page, per_page, pages }
     * @since 1.4.0
     * @access public
     */
    public static function list_participants($survey_id, $page = 1, $per_page = 50, $filters = array()) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'survey_participants';
        $offset = ($page - 1) * $per_page;
        
        // Construir WHERE clause
        $where_conditions = array('survey_id = %d');
        $where_values = array($survey_id);
        
        // Filtro por status
        if (isset($filters['status'])) {
            if ($filters['status'] === 'active') {
                $where_conditions[] = 'is_active = 1';
            } elseif ($filters['status'] === 'inactive') {
                $where_conditions[] = 'is_active = 0';
            }
        }
        
        // Filtro por búsqueda
        if (isset($filters['search']) && !empty($filters['search'])) {
            $where_conditions[] = 'email LIKE %s';
            $where_values[] = '%' . $wpdb->esc_like($filters['search']) . '%';
        }
        
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        
        // Query total (sin LIMIT)
        $total_query = "SELECT COUNT(*) FROM $table_name $where_clause";
        $total = (int) $wpdb->get_var($wpdb->prepare($total_query, $where_values));
        
        // Query paginada
        $query = "SELECT * FROM $table_name $where_clause ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $where_values[] = $per_page;
        $where_values[] = $offset;
        
        $participants = $wpdb->get_results($wpdb->prepare($query, $where_values));
        
        return array(
            'total' => $total,
            'participants' => $participants,
            'page' => (int) $page,
            'per_page' => (int) $per_page,
            'pages' => ceil($total / $per_page)
        );
    }

    /**
     * Get participant's wave completion history.
     *
     * @param int $participant_id ID del participante.
     * @param int $study_id ID del estudio.
     * @return array Array de completaciones de wave.
     * @since 1.6.0
     * @access public
     */
    public static function get_wave_completions($participant_id, $study_id) {
        global $wpdb;
        
        $query = "
            SELECT 
                a.id as assignment_id,
                a.wave_id,
                a.status,
                a.started_at,
                a.completed_at,
                a.submitted_at,
                w.name as wave_name,
                w.wave_index,
                f.post_title as form_title
            FROM {$wpdb->prefix}survey_assignments a
            LEFT JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
            LEFT JOIN {$wpdb->prefix}posts f ON w.form_id = f.ID
            WHERE a.participant_id = %d AND a.study_id = %d
            ORDER BY w.wave_index ASC
        ";
        
        return $wpdb->get_results($wpdb->prepare($query, $participant_id, $study_id));
    }

    /**
     * Get participant's magic link history.
     *
     * @param int $participant_id ID del participante.
     * @return array Array de magic links.
     * @since 1.6.0
     * @access public
     */
    public static function get_magic_link_history($participant_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'survey_magic_links';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE participant_id = %d ORDER BY created_at DESC LIMIT 20",
            $participant_id
        ));
    }

    /**
     * Check if participant has active session.
     *
     * @param int $participant_id ID del participante.
     * @return bool True if has active session.
     * @since 1.6.0
     * @access public
     */
    public static function has_active_session($participant_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'survey_sessions';
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE participant_id = %d AND expires_at > NOW() LIMIT 1",
            $participant_id
        ));
        
        return !empty($session);
    }

    /**
     * Deactivate participant (soft delete).
     *
     * @param int $participant_id ID del participante.
     * @param string $reason Razón de desactivación.
     * @return bool True if success.
     * @since 1.6.0
     * @access public
     */
    public static function deactivate($participant_id, $reason = '') {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'survey_participants';
        $result = $wpdb->update(
            $table_name,
            array(
                'is_active' => 0,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $participant_id),
            array('%d', '%s'),
            array('%d')
        );

        // Log de auditoría
        if ($result !== false) {
            eipsi_log_participant_audit($participant_id, 'deactivated', $reason);
        }

        return $result !== false;
    }

    /**
     * Remove the local participant row and linked operational copies.
     * 
     * Preserves scrubbed responses and audit trails. Returns explicit coverage;
     * external storage and unlinked historical copies are not included.
     *
     * @param int    $participant_id ID del participante.
     * @param string $reason Razón de eliminación.
     * @return array { success: bool, deleted: array, anonymized: array, errors: array }
     * @since 1.6.0
     * @access public
     */
    public static function hard_delete($participant_id, $reason = '') {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-participant-data-cleanup.php';
        return EIPSI_Participant_Data_Cleanup::run($participant_id, 'hard_delete', $reason);
    }

    /**
     * Create or get participant for magic link flow (email only).
     *
     * Este método permite crear un participante con solo email para el flujo
     * de magic links, sin requerir contraseña ni nombre/apellido.
     *
     * @param int    $survey_id ID del survey.
     * @param string $email Email del participante.
     * @return array { success: bool, participant_id: int|null, is_new: bool, error: string|null }
     * @since 1.7.0
     * @access public
     */
    public static function create_or_get_for_magic_link($survey_id, $email) {
        global $wpdb;

        try {
            // Validar email
            if (!is_email($email)) {
                return array(
                    'success' => false,
                    'participant_id' => null,
                    'is_new' => false,
                    'error' => 'invalid_email'
                );
            }

            // Sanitizar email
            $email = sanitize_email($email);
            $table_name = $wpdb->prefix . 'survey_participants';

            // Verificar si ya existe
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE survey_id = %d AND email = %s",
                $survey_id,
                $email
            ));

            if ($existing) {
                // Si existe pero está inactivo, reactivarlo
                if (!$existing->is_active) {
                    $wpdb->update(
                        $table_name,
                        array('is_active' => 1, 'updated_at' => current_time('mysql')),
                        array('id' => $existing->id),
                        array('%d', '%s'),
                        array('%d')
                    );
                }

                return array(
                    'success' => true,
                    'participant_id' => (int) $existing->id,
                    'is_new' => false,
                    'error' => null
                );
            }

            // Crear nuevo participante sin contraseña (magic link only)
            // Generar contraseña aleatoria para satisfacer constraint de DB
            $temp_password = wp_generate_password(32, true, true);
            $password_hash = wp_hash_password($temp_password);

            $result = $wpdb->insert(
                $table_name,
                array(
                    'survey_id' => $survey_id,
                    'email' => $email,
                    'password_hash' => $password_hash,
                    'first_name' => '',
                    'last_name' => '',
                    'created_at' => current_time('mysql'),
                    'is_active' => 1
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%d')
            );

            if ($result === false) {
                error_log('EIPSI Participant creation for magic link failed: ' . $wpdb->last_error);
                return array(
                    'success' => false,
                    'participant_id' => null,
                    'is_new' => false,
                    'error' => 'db_error'
                );
            }

            return array(
                'success' => true,
                'participant_id' => (int) $wpdb->insert_id,
                'is_new' => true,
                'error' => null
            );

        } catch (Exception $e) {
            error_log('EIPSI Participant creation for magic link exception: ' . $e->getMessage());
            return array(
                'success' => false,
                'participant_id' => null,
                'is_new' => false,
                'error' => 'db_error'
            );
        }
    }
}

/**
 * Log participant audit action.
 *
 * @param int    $participant_id ID del participante.
 * @param string $action Acción realizada.
 * @param string $reason Razón (opcional).
 * @since 1.6.0
 */
function eipsi_log_participant_audit($participant_id, $action, $reason = '') {
    global $wpdb;
    
    $current_user = wp_get_current_user();
    $user_id = $current_user->ID ?? 0;
    $user_name = $current_user->user_login ?? 'system';
    
    $wpdb->insert(
        $wpdb->prefix . 'survey_email_log',
        array(
            'survey_id' => 0,
            'participant_id' => $participant_id,
            'email_type' => 'audit_log',
            'recipient_email' => $user_name . '@admin',
            'subject' => 'Audit: ' . $action,
            'content' => json_encode(array(
                'action' => $action,
                'reason' => $reason,
                'performed_by' => $user_name,
                'user_id' => $user_id,
                'timestamp' => current_time('mysql')
            )),
            'status' => 'audit',
            'sent_at' => current_time('mysql')
        ),
        array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
    );
}
