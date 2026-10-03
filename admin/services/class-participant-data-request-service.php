<?php
/**
 * EIPSI_Participant_Data_Request_Service
 *
 * Gestiona solicitudes de datos por parte de participantes (GDPR).
 * Permite a los participantes solicitar sus datos desde el portal.
 *
 * @package EIPSI_Forms
 * @subpackage Services
 * @version 2.1.0
 * @since Phase 3 - Task 3C.7
 */

if (!defined('ABSPATH')) {
    exit;
}

class EIPSI_Participant_Data_Request_Service {

    /**
     * Request statuses.
     */
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_REJECTED = 'rejected';

    /**
     * Submit a data request from a participant.
     *
     * @param int $participant_id
     * @param string $request_type Type of request: 'export', 'delete', 'anonymize'
     * @param string $reason Optional reason
     * @return array Result
     */
    public static function submit_request($participant_id, $request_type, $reason = '') {
        global $wpdb;

        $participant_id = absint($participant_id);
        if (!$participant_id) {
            return array(
                'success' => false,
                'message' => 'ID de participante inválido'
            );
        }

        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-auth-service.php';
        $context = EIPSI_Auth_Service::authorize_session_context(array('participant_id'=>$participant_id));
        if (!$context['success']) { return array('success'=>false,'message'=>'No autorizado'); }

        // Validate request type
        $valid_types = array('export', 'delete', 'anonymize');
        if (!in_array($request_type, $valid_types, true)) {
            return array(
                'success' => false,
                'message' => 'Tipo de solicitud inválido'
            );
        }

        // Check if participant exists
        $participant = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_participants WHERE id = %d",
            $participant_id
        ));

        if (!$participant) {
            return array(
                'success' => false,
                'message' => 'Participante no encontrado'
            );
        }

        // Check for existing pending request
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}survey_data_requests
             WHERE participant_id = %d AND status = %s",
            $participant_id,
            self::STATUS_PENDING
        ));

        if ($existing > 0) {
            return array(
                'success' => false,
                'message' => 'Ya tienes una solicitud pendiente. Por favor espera a que sea procesada.'
            );
        }

        // Create request
        $result = $wpdb->insert(
            $wpdb->prefix . 'survey_data_requests',
            array(
                'participant_id' => $participant_id,
                'survey_id' => $participant->survey_id,
                'request_type' => $request_type,
                'reason' => sanitize_textarea_field($reason),
                'status' => self::STATUS_PENDING,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            return array(
                'success' => false,
                'message' => 'Error al crear la solicitud. Por favor intenta nuevamente.'
            );
        }

        $request_id = $wpdb->insert_id;

        // Notify admin
        self::notify_admin_new_request($request_id, $participant, $request_type);

        return array(
            'success' => true,
            'request_id' => $request_id,
            'message' => 'Solicitud enviada exitosamente. Será procesada por el equipo de investigación.'
        );
    }

    /**
     * Get all data requests (for admin).
     *
     * @param array $filters Optional filters
     * @param int $limit
     * @param int $offset
     * @return array Requests with participant info
     */
    public static function get_requests($filters = array(), $limit = 20, $offset = 0) {
        global $wpdb;

        $where = array('1=1');
        $params = array();

        if (!empty($filters['status'])) {
            $where[] = 'r.status = %s';
            $params[] = sanitize_text_field($filters['status']);
        }

        if (!empty($filters['survey_id'])) {
            $where[] = 'r.survey_id = %d';
            $params[] = absint($filters['survey_id']);
        }

        if (!empty($filters['request_type'])) {
            $where[] = 'r.request_type = %s';
            $params[] = sanitize_text_field($filters['request_type']);
        }

        $where_clause = implode(' AND ', $where);

        $query = "SELECT
                    r.*,
                    p.email as participant_email,
                    CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')) as participant_name,
                    s.study_name
                  FROM {$wpdb->prefix}survey_data_requests r
                  JOIN {$wpdb->prefix}survey_participants p ON r.participant_id = p.id
                  LEFT JOIN {$wpdb->prefix}survey_studies s ON r.survey_id = s.id
                  WHERE {$where_clause}
                  ORDER BY r.created_at DESC
                  LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        $requests = $wpdb->get_results($wpdb->prepare($query, $params));

        // Get total count
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}survey_data_requests r WHERE {$where_clause}",
            array_slice($params, 0, -2)
        ));

        return array(
            'requests' => $requests,
            'total' => (int) $total,
            'limit' => $limit,
            'offset' => $offset
        );
    }

    /**
     * Process a pending request.
     *
     * @param int $request_id
     * @param string $action 'approve' or 'reject'
     * @param string $admin_notes Optional notes
     * @return array Result
     */
    public static function process_request($request_id, $action, $admin_notes = '') {
        global $wpdb;
        if (!current_user_can('manage_options') || !in_array($action,array('approve','reject'),true)) { return array('success'=>false,'message'=>'No autorizado.'); }

        $request_id = absint($request_id);
        if (!$request_id) {
            return array('success' => false, 'message' => 'ID de solicitud inválido');
        }

        $request = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_data_requests WHERE id = %d",
            $request_id
        ));

        if (!$request) {
            return array('success' => false, 'message' => 'Solicitud no encontrada');
        }

        if ($request->status !== self::STATUS_PENDING) {
            return array(
                'success' => false,
                'message' => 'Esta solicitud ya ha sido procesada'
            );
        }

        $admin_id = get_current_user_id();
        $processed_at = current_time('mysql');

        if ($action === 'reject') {
            $state_saved = $wpdb->update(
                $wpdb->prefix . 'survey_data_requests',
                array(
                    'status' => self::STATUS_REJECTED,
                    'admin_id' => $admin_id,
                    'admin_notes' => sanitize_textarea_field($admin_notes),
                    'processed_at' => $processed_at
                ),
                array('id' => $request_id),
                array('%s', '%d', '%s', '%s'),
                array('%d')
            );

            if ($state_saved === false) { return array('success'=>false,'message'=>'No se pudo guardar el estado de la solicitud.'); }
            // Notify participant
            self::notify_participant_rejected($request);

            return array(
                'success' => true,
                'message' => 'Solicitud rechazada'
            );
        }

        // Process based on request type
        $state_saved = $wpdb->update(
            $wpdb->prefix . 'survey_data_requests',
            array(
                'status' => self::STATUS_PROCESSING,
                'admin_id' => $admin_id,
                'started_processing_at' => $processed_at
            ),
            array('id' => $request_id),
            array('%s', '%d', '%s'),
            array('%d')
        );

        if ($state_saved === false) { return array('success'=>false,'message'=>'No se pudo iniciar la solicitud.'); }
        try {
        switch ($request->request_type) {
            case 'export':
                $result = self::process_export_request($request);
                break;
            case 'delete':
                $result = self::process_delete_request($request);
                break;
            case 'anonymize':
                $result = self::process_anonymize_request($request);
                break;
            default:
                $result = array('success' => false, 'message' => 'Tipo de solicitud desconocido');
        }

        } catch(Throwable $error) { $result=array('success'=>false,'message'=>$error->getMessage()); }
        if ($result['success']) {
            $state_saved = $wpdb->update(
                $wpdb->prefix . 'survey_data_requests',
                array(
                    'status' => self::STATUS_COMPLETED,
                    'result_data' => !empty($result['data']) ? wp_json_encode($result['data']) : null,
                    'processed_at' => current_time('mysql')
                ),
                array('id' => $request_id),
                array('%s', '%s', '%s'),
                array('%d')
            );

            if ($state_saved === false) {
                $path=$result['data']['file_path']??null;
                if($path && is_file($path)){unlink($path);}
                return array('success'=>false,'message'=>'No se pudo guardar el estado de la solicitud.');
            }
            // Notify participant
            self::notify_participant_completed($request, $result);
        } else {
            // Mark as failed but keep for retry
            $state_saved = $wpdb->update(
                $wpdb->prefix . 'survey_data_requests',
                array(
                    'status' => self::STATUS_PENDING,
                    'admin_notes' => 'Error: ' . $result['message']
                ),
                array('id' => $request_id),
                array('%s', '%s'),
                array('%d')
            );
        }

        if ($state_saved === false) { return array('success'=>false,'message'=>'No se pudo confirmar el estado final.'); }
        return $result;
    }

    /**
     * Process export request.
     */
    private static function process_export_request($request) {
        global $wpdb;
        $id=absint($request->participant_id);
        $participant=$wpdb->get_row($wpdb->prepare("SELECT id,survey_id,email,first_name,last_name,created_at,consent_decision FROM {$wpdb->prefix}survey_participants WHERE id=%d",$id), ARRAY_A);
        if(!$participant){return array('success'=>false,'message'=>'Participante no encontrado.');}
        $data=array('participant_info'=>$participant,'access_logs'=>self::checked_rows($wpdb->prepare(
            "SELECT action_type,created_at FROM {$wpdb->prefix}survey_participant_access_log WHERE participant_id=%d",$id),ARRAY_A),
            'assignments'=>self::checked_rows($wpdb->prepare("SELECT w.wave_index,w.name AS wave_name,a.status,a.assigned_at,a.submitted_at FROM {$wpdb->prefix}survey_assignments a JOIN {$wpdb->prefix}survey_waves w ON w.id=a.wave_id WHERE a.participant_id=%d",$id),ARRAY_A),
            'email_history'=>self::checked_rows($wpdb->prepare("SELECT email_type,subject,sent_at,status FROM {$wpdb->prefix}survey_email_log WHERE participant_id=%d",$id),ARRAY_A),
            'responses'=>self::checked_rows($wpdb->prepare("SELECT form_name,wave_index,submitted_at,form_responses FROM {$wpdb->prefix}vas_form_results WHERE participant_id=%s AND survey_id=%d",(string)$id,$request->survey_id),ARRAY_A),
            'coverage'=>array('source'=>'wordpress_db','complete'=>false,'not_covered'=>array('external_db','unlinked_browser_records','emergency_submissions','historical_export_files')));
        if($wpdb->last_error){return array('success'=>false,'message'=>'No se pudieron consultar los datos.');}
        // Nested answer keys must not reintroduce credentials/tokens supplied by a client.
        $data=self::remove_secrets($data);
        $json=wp_json_encode($data,JSON_PRETTY_PRINT);
        if($json===false){return array('success'=>false,'message'=>'No se pudo generar JSON.');}
        $directory=get_temp_dir();
        $real=realpath($directory);$web=realpath(ABSPATH);
        if(!$real || !is_dir($real) || !is_writable($real) || ($web && ($real===$web || strpos($real,$web.DIRECTORY_SEPARATOR)===0))) { return array('success'=>false,'message'=>'No hay directorio privado disponible.'); }
        $path=@tempnam($real,'eipsi-personal-');
        if(!$path || dirname($path)!==$real || !@chmod($path,0600) || @file_put_contents($path,$json,LOCK_EX)!==strlen($json)) {
            if($path && is_file($path)){unlink($path);}
            return array('success'=>false,'message'=>'No se pudo crear el archivo privado.');
        }
        return array('success'=>true,'message'=>'Export local generado; DB externa no incluida.',
            'data'=>array('file_path'=>$path,'filename'=>basename($path).'.json','coverage'=>$data['coverage'],
                'download_url'=>add_query_arg(array('action'=>'eipsi_download_personal_data','request_id'=>$request->id,'nonce'=>wp_create_nonce('eipsi_data_download')),admin_url('admin-ajax.php')),
                'record_count'=>array('responses'=>count($data['responses']),'assignments'=>count($data['assignments']))));
    }

    private static function checked_rows($query,$output=ARRAY_A) {
        global $wpdb;
        $rows=$wpdb->get_results($query,$output);
        if($wpdb->last_error){throw new RuntimeException('No se pudieron consultar todos los datos personales.');}
        return $rows;
    }

    public static function remove_secrets($data) {
        if(is_object($data)){$data=(array)$data;}
        if(!is_array($data)){return $data;}
        foreach($data as $key=>$item){
            if(preg_match('/password|token|secret|credential|nonce|session|fingerprint/i',(string)$key)){unset($data[$key]);continue;}
            if(is_string($item) && $key==='form_responses'){$decoded=json_decode($item,true);$data[$key]=wp_json_encode(self::remove_secrets($decoded?:array()));}
            else{$data[$key]=self::remove_secrets($item);}
        }
        return $data;
    }

    /** Authorize every download; paths are read from DB, never from a client. */
    public static function get_download($request_id, $nonce) {
        global $wpdb;
        if(!wp_verify_nonce($nonce,'eipsi_data_download')){return array('success'=>false,'message'=>'Token inválido.');}
        $request=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}survey_data_requests WHERE id=%d",absint($request_id)));
        if(!$request || $request->status!==self::STATUS_COMPLETED || $request->request_type!=='export'){return array('success'=>false,'message'=>'Export no disponible.');}
        if(!current_user_can('manage_options')){
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-auth-service.php';
            $access=EIPSI_Auth_Service::authorize_session_context(array('participant_id'=>$request->participant_id,'study_id'=>$request->survey_id));
            if(!$access['success']){return array('success'=>false,'message'=>'No autorizado.');}
        }
        $data=json_decode($request->result_data,true);$path=$data['file_path']??'';
        $real=realpath($path);$private=realpath(get_temp_dir());
        if(!$real || !$private || strpos($real,$private.DIRECTORY_SEPARATOR)!==0 || strpos(basename($real),'eipsi-personal-')!==0 || !is_readable($real)){
            return array('success'=>false,'message'=>'Archivo no disponible.');
        }
        return array('success'=>true,'file_path'=>$real,'filename'=>$data['filename']);
    }

    /**
     * Process delete request.
     */
    private static function process_delete_request($request) {
        // Use anonymize service to delete PII
        if (!class_exists('EIPSI_Anonymize_Service')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-anonymize-service.php';
        }

        $result = EIPSI_Anonymize_Service::anonymize_participant(
            $request->participant_id,
            'Solicitud de eliminación de datos (GDPR)'
        );

        if ($result['success']) {
            return array(
                'success' => true,
                'message' => 'Identificadores locales anonimizados; respuestas conservadas. DB externa no incluida',
                'data' => array('operation'=>'anonymize','coverage'=>$result['coverage'])
            );
        } else {
            return array(
                'success' => false,
                'message' => $result['error'] ?? 'Error al eliminar datos'
            );
        }
    }

    /**
     * Process anonymize request.
     */
    private static function process_anonymize_request($request) {
        // Same as delete for now - can be extended later
        return self::process_delete_request($request);
    }

    /**
     * Get request counts by status.
     */
    public static function get_request_counts() {
        global $wpdb;

        $counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as count
             FROM {$wpdb->prefix}survey_data_requests
             GROUP BY status"
        );

        $result = array(
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'rejected' => 0,
            'total' => 0
        );

        foreach ($counts as $row) {
            $result[$row->status] = (int) $row->count;
            $result['total'] += (int) $row->count;
        }

        return $result;
    }

    /**
     * Create data requests table.
     */
    public static function create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'survey_data_requests';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            participant_id BIGINT(20) UNSIGNED NOT NULL,
            survey_id BIGINT(20) UNSIGNED NOT NULL,
            request_type VARCHAR(20) NOT NULL,
            reason TEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            admin_id BIGINT(20) UNSIGNED,
            admin_notes TEXT,
            result_data TEXT,
            created_at DATETIME NOT NULL,
            started_processing_at DATETIME,
            processed_at DATETIME,
            PRIMARY KEY (id),
            KEY participant_id (participant_id),
            KEY survey_id (survey_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Notify admin of new request.
     */
    private static function notify_admin_new_request($request_id, $participant, $request_type) {
        $admin_email = get_option('eipsi_investigator_email', get_option('admin_email'));
        $type_labels = array(
            'export' => 'Exportar mis datos',
            'delete' => 'Eliminar mis datos',
            'anonymize' => 'Anonimizar mis datos'
        );

        $subject = 'Nueva solicitud de datos GDPR - EIPSI Forms';
        $message = sprintf(
            "El participante %s (%s) ha solicitado: %s\n\n" .
            "Ver solicitudes pendientes:\n%s",
            trim($participant->first_name . ' ' . $participant->last_name),
            $participant->email,
            $type_labels[$request_type] ?? $request_type,
            admin_url('admin.php?page=eipsi-results&tab=data-requests')
        );

        wp_mail($admin_email, $subject, $message);
    }

    /**
     * Notify participant of rejected request.
     */
    private static function notify_participant_rejected($request) {
        global $wpdb;

        $participant = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_participants WHERE id = %d",
            $request->participant_id
        ));

        if (!$participant) return;

        $subject = 'Actualización de tu solicitud de datos';
        $message = sprintf(
            "Hola %s,\n\n" .
            "Tu solicitud de datos ha sido revisada. Desafortunadamente, no podemos procesarla en este momento.\n\n" .
            "Si tienes preguntas, contacta al equipo de investigación.\n\n" .
            "Saludos,\nEquipo de Investigación",
            $participant->first_name
        );

        wp_mail($participant->email, $subject, $message);
    }

    /**
     * Notify participant of completed request.
     */
    private static function notify_participant_completed($request, $result) {
        global $wpdb;

        $participant = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_participants WHERE id = %d",
            $request->participant_id
        ));

        if (!$participant) return;

        $type_labels = array(
            'export' => 'exportación',
            'delete' => 'eliminación',
            'anonymize' => 'anonimización'
        );

        $subject = 'Tu solicitud de datos ha sido procesada';
        $message = sprintf(
            "Hola %s,\n\n" .
            "Tu solicitud de %s de datos ha sido procesada exitosamente.\n\n",
            $participant->first_name,
            $type_labels[$request->request_type] ?? $request->request_type
        );

        if ($request->request_type === 'export' && !empty($result['data']['filename'])) {
            $message .= "El archivo con tus datos está disponible para descarga en el panel de administración.\n\n";
        }

        $message .= "Si tienes preguntas, contacta al equipo de investigación.\n\nSaludos,\nEquipo de Investigación";

        wp_mail($participant->email, $subject, $message);
    }
}
