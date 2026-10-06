<?php
/** M6 owner; existing public adapters retain their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Export_Access_Log_Service {

public static function export_access_logs($filters = array(), $format = 'csv') {
        global $wpdb;
        $logs=EIPSI_Access_Log_Export_Query_Service::load($filters);

        if (empty($logs)) {
            return array(
                'success' => false,
                'message' => __('No hay logs de acceso para exportar con los filtros seleccionados.', 'eipsi-forms'),
                'count' => 0
            );
        }

        // Prepare headers
        $headers = array(
            'Date',
            'Participant Name',
            'Email',
            'Study',
            'Action',
            'IP Address',
            'Device',
            'Metadata'
        );

        // Prepare data rows
        $data = array($headers);
        foreach ($logs as $log) {
            // Parse metadata if exists
            $metadata_str = '';
            if (!empty($log->metadata)) {
                require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-export-service.php';
                $metadata = EIPSI_Export_Service::strip_credentials(json_decode($log->metadata, true));
                if (is_array($metadata)) {
                    $metadata_parts = array();
                    foreach ($metadata as $key => $value) {
                        $metadata_parts[] = $key . ': ' . (is_array($value) ? json_encode($value) : $value);
                    }
                    $metadata_str = implode('; ', $metadata_parts);
                }
            }

            $data[] = array(
                $log->date,
                trim($log->participant_name) ?: 'N/A',
                $log->participant_email ?: 'N/A',
                $log->study ?: 'N/A',
                self::translate_action($log->action),
                $log->ip ?: 'N/A',
                self::simplify_user_agent($log->device),
                $metadata_str
            );
        }

        // Generate filename
        $timestamp = date('Y-m-d_H-i-s');
        $study_suffix = !empty($filters['study_id']) && $filters['study_id'] !== 'all' ? '_study-' . $filters['study_id'] : '';
        $filename = 'access-logs' . $study_suffix . '_' . $timestamp;

        // Export based on format
        if ($format === 'excel') {
            return self::export_to_excel($data, $filename, count($logs));
        } else {
            return self::export_to_csv($data, $filename, count($logs));
        }
    }

private static function export_to_excel($data, $filename, $count) {
        if (!class_exists('\Shuchkin\SimpleXLSXGen')) { require_once EIPSI_FORMS_PLUGIN_DIR . 'lib/SimpleXLSXGen.php'; }

        $export_dir = EIPSI_Export_File_Service::directory();
        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }

        $full_filename = $filename . '.xlsx';
        $full_filename = EIPSI_Export_File_Service::reserve_filename($full_filename);
        $file_path = $export_dir . '/' . $full_filename;

        $xlsx = \Shuchkin\SimpleXLSXGen::fromArray($data);
        if (!$xlsx->saveAs($file_path) || !is_file($file_path) || !filesize($file_path)) { return array('success'=>false,'message'=>'No se pudo crear el archivo XLSX.'); }

        return array(
            'success' => true,
            'file_path' => $file_path,
            'filename' => $full_filename,
            'count' => $count,
            'message' => sprintf(__('Exportado %d registros a Excel', 'eipsi-forms'), $count)
        );
    }

private static function export_to_csv($data, $filename, $count) {
        $export_dir = EIPSI_Export_File_Service::directory();
        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }

        $full_filename = $filename . '.csv';
        $full_filename = EIPSI_Export_File_Service::reserve_filename($full_filename);
        $file_path = $export_dir . '/' . $full_filename;

        $file = @fopen($file_path, 'w');
        if (!$file) { return array('success'=>false,'message'=>'No se pudo crear el archivo CSV.'); }
        // UTF-8 BOM for Excel
        fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

        foreach ($data as $row) {
            if (fputcsv($file,$row) === false) { fclose($file); return array('success'=>false,'message'=>'No se pudo escribir el export CSV.'); }
        }

        if (!fclose($file) || !is_file($file_path) || filesize($file_path)===0) {
            return array('success'=>false,'message'=>'No se pudo confirmar el archivo CSV.');
        }

        return array(
            'success' => true,
            'file_path' => $file_path,
            'filename' => $full_filename,
            'count' => $count,
            'message' => sprintf(__('Exportado %d registros a CSV', 'eipsi-forms'), $count)
        );
    }

private static function translate_action($action) {
        $translations = array(
            'registration' => 'Registro',
            'login' => 'Inicio de sesión',
            'login_failed' => 'Intento fallido',
            'magic_link_clicked' => 'Magic Link usado',
            'magic_link_sent' => 'Magic Link enviado',
            'wave_started' => 'Toma iniciada',
            'wave_completed' => 'Toma completada',
            'logout' => 'Cierre de sesión',
            'session_expired' => 'Sesión expirada',
            'password_reset_requested' => 'Reset de contraseña solicitado',
            'password_reset_completed' => 'Reset de contraseña completado'
        );

        return isset($translations[$action]) ? $translations[$action] : $action;
    }

private static function simplify_user_agent($user_agent) {
        if (empty($user_agent)) {
            return 'Unknown';
        }

        // Detect device type
        if (strpos($user_agent, 'Mobile') !== false || strpos($user_agent, 'Android') !== false) {
            return 'Mobile';
        }
        if (strpos($user_agent, 'Tablet') !== false || strpos($user_agent, 'iPad') !== false) {
            return 'Tablet';
        }
        if (strpos($user_agent, 'Windows') !== false) {
            return 'Desktop - Windows';
        }
        if (strpos($user_agent, 'Mac') !== false) {
            return 'Desktop - Mac';
        }
        if (strpos($user_agent, 'Linux') !== false) {
            return 'Desktop - Linux';
        }

        return 'Desktop';
    }

public static function get_action_types() {
        return array(
            'all' => __('Todos los tipos', 'eipsi-forms'),
            'registration' => __('Registro', 'eipsi-forms'),
            'login' => __('Inicio de sesión', 'eipsi-forms'),
            'login_failed' => __('Intento fallido', 'eipsi-forms'),
            'magic_link_clicked' => __('Magic Link usado', 'eipsi-forms'),
            'magic_link_sent' => __('Magic Link enviado', 'eipsi-forms'),
            'wave_started' => __('Toma iniciada', 'eipsi-forms'),
            'wave_completed' => __('Toma completada', 'eipsi-forms'),
            'logout' => __('Cierre de sesión', 'eipsi-forms'),
            'session_expired' => __('Sesión expirada', 'eipsi-forms'),
            'password_reset_requested' => __('Reset de contraseña solicitado', 'eipsi-forms'),
            'password_reset_completed' => __('Reset de contraseña completado', 'eipsi-forms')
        );
    }

public static function stream_access_logs_csv($filters = array()) {
        global $wpdb;
        $logs=EIPSI_Access_Log_Export_Query_Service::load_stream($filters);

        // Output headers
        if ($wpdb->last_error) { wp_die('No se pudieron consultar los logs de acceso.'); }
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-export-service.php';
        header('Content-Type: text/csv; charset=utf-8');
        $study_slug = !empty($filters['study_id']) && $filters['study_id'] !== 'all' ? '-study-' . $filters['study_id'] : '';
        header('Content-Disposition: attachment; filename="access-logs' . $study_slug . '-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: max-age=0');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // CSV headers
        EIPSI_Export_Service::write_csv_row($output, array(
            'Date',
            'Participant Name',
            'Email',
            'Study',
            'Action',
            'IP Address',
            'Device'
        ));

        // Output rows
        foreach ($logs as $log) {
            EIPSI_Export_Service::write_csv_row($output, array(
                $log->date,
                trim($log->participant_name) ?: 'N/A',
                $log->participant_email ?: 'N/A',
                $log->study ?: 'N/A',
                self::translate_action($log->action),
                $log->ip ?: 'N/A',
                self::simplify_user_agent($log->device)
            ));
        }

        fclose($output);
        exit;
    }
}
