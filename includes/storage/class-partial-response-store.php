<?php
/** M6 owner; existing public adapters retain their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Storage_Partial_Response_Store {

public static function save($form_id, $participant_id, $session_id, $page_index, $responses) {
            global $wpdb;

            $table_name = $wpdb->prefix . 'eipsi_partial_responses';

            error_log(sprintf('[EIPSI SAVE&CONTINUE] save() called: form_id=%s, participant_id=%s, session_id=%s, page_index=%d, response_count=%d',
                $form_id, $participant_id, $session_id, $page_index, is_array($responses) ? count($responses) : 0));

            // Sanitize inputs
            $form_id = sanitize_text_field($form_id);
            $participant_id = sanitize_text_field($participant_id);
            $session_id = sanitize_text_field($session_id);
            $page_index = intval($page_index);

            // Validate
            if (empty($form_id) || empty($participant_id) || empty($session_id)) {
                error_log('[EIPSI SAVE&CONTINUE] save() validation failed: missing required fields');
                return array(
                    'success' => false,
                    'error' => 'Missing required fields'
                );
            }

            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/privacy-config.php';
            $privacy_form = function_exists('generate_stable_form_id') ? generate_stable_form_id($form_id) : $form_id;
            $responses = eipsi_filter_capture_data($responses, get_privacy_config($privacy_form));
            $responses_json = wp_json_encode($responses);
            $now = current_time('mysql');

            // v2.1.3 Fix: Use INSERT...ON DUPLICATE KEY UPDATE to prevent race conditions
            // This is atomic and handles concurrent requests gracefully
            $sql = $wpdb->prepare(
                "INSERT INTO $table_name
                (form_id, participant_id, session_id, page_index, responses_json, completed, created_at, updated_at)
                VALUES (%s, %s, %s, %d, %s, 0, %s, %s)
                ON DUPLICATE KEY UPDATE
                page_index = VALUES(page_index),
                responses_json = VALUES(responses_json),
                updated_at = VALUES(updated_at)",
                $form_id,
                $participant_id,
                $session_id,
                $page_index,
                $responses_json,
                $now,
                $now
            );

            $result = $wpdb->query($sql);

            if ($result === false) {
                error_log(sprintf('[EIPSI SAVE&CONTINUE] save() DB error: %s', $wpdb->last_error));
                return array(
                    'success' => false,
                    'error' => $wpdb->last_error
                );
            }

            error_log(sprintf('[EIPSI SAVE&CONTINUE] save() DB query result: %d rows affected', $result));

            // Get the ID (either inserted or updated)
            $record_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_name
                WHERE form_id = %s AND participant_id = %s AND session_id = %s",
                $form_id,
                $participant_id,
                $session_id
            ));

            $action = $record_id ? ($result > 0 ? 'inserted' : 'updated') : 'updated';
            error_log(sprintf('[EIPSI SAVE&CONTINUE] save() success: action=%s, record_id=%d', $action, $record_id));

            return array(
                'success' => true,
                'action' => $action,
                'id' => $record_id
            );
        }

public static function load($form_id, $participant_id, $session_id) {
            global $wpdb;

            $table_name = $wpdb->prefix . 'eipsi_partial_responses';

            error_log(sprintf('[EIPSI SAVE&CONTINUE] load() called: form_id=%s, participant_id=%s, session_id=%s',
                $form_id, $participant_id, $session_id));

            $result = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name
                WHERE form_id = %s AND participant_id = %s AND session_id = %s AND completed = 0
                ORDER BY updated_at DESC
                LIMIT 1",
                sanitize_text_field($form_id),
                sanitize_text_field($participant_id),
                sanitize_text_field($session_id)
            ));

            if (!$result) {
                error_log('[EIPSI SAVE&CONTINUE] load() result: no partial response found');
                return null;
            }

            error_log(sprintf('[EIPSI SAVE&CONTINUE] load() result: found record id=%d, page_index=%d, updated_at=%s',
                $result->id, $result->page_index, $result->updated_at));

            return array(
                'id' => $result->id,
                'form_id' => $result->form_id,
                'participant_id' => $result->participant_id,
                'session_id' => $result->session_id,
                'page_index' => intval($result->page_index),
                'responses' => json_decode($result->responses_json, true),
                'created_at' => $result->created_at,
                'updated_at' => $result->updated_at
            );
        }

public static function mark_completed($form_id, $participant_id, $session_id) {
            global $wpdb;

            $table_name = $wpdb->prefix . 'eipsi_partial_responses';

            error_log(sprintf('[EIPSI SAVE&CONTINUE] mark_completed() called: form_id=%s, participant_id=%s, session_id=%s',
                $form_id, $participant_id, $session_id));

            $result = $wpdb->update(
                $table_name,
                array(
                    'completed' => 1,
                    'updated_at' => current_time('mysql')
                ),
                array(
                    'form_id' => sanitize_text_field($form_id),
                    'participant_id' => sanitize_text_field($participant_id),
                    'session_id' => sanitize_text_field($session_id)
                ),
                array('%d', '%s'),
                array('%s', '%s', '%s')
            );

            if ($result !== false) {
                error_log(sprintf('[EIPSI SAVE&CONTINUE] mark_completed() success: %d rows updated', $result));
            } else {
                error_log(sprintf('[EIPSI SAVE&CONTINUE] mark_completed() failed: %s', $wpdb->last_error));
            }

            return $result !== false;
        }

public static function discard($form_id, $participant_id, $session_id) {
            global $wpdb;

            $table_name = $wpdb->prefix . 'eipsi_partial_responses';

            error_log(sprintf('[EIPSI SAVE&CONTINUE] discard() called: form_id=%s, participant_id=%s, session_id=%s',
                $form_id, $participant_id, $session_id));

            $result = $wpdb->delete(
                $table_name,
                array(
                    'form_id' => sanitize_text_field($form_id),
                    'participant_id' => sanitize_text_field($participant_id),
                    'session_id' => sanitize_text_field($session_id)
                ),
                array('%s', '%s', '%s')
            );

            if ($result !== false) {
                error_log(sprintf('[EIPSI SAVE&CONTINUE] discard() success: %d rows deleted', $result));
            } else {
                error_log(sprintf('[EIPSI SAVE&CONTINUE] discard() failed: %s', $wpdb->last_error));
            }

            return $result !== false;
        }

public static function cleanup_old_responses() {
            global $wpdb;

            $table_name = $wpdb->prefix . 'eipsi_partial_responses';

            error_log('[EIPSI SAVE&CONTINUE] cleanup_old_responses() called');

            $result = $wpdb->query("
                DELETE FROM $table_name
                WHERE updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");

            error_log(sprintf('[EIPSI SAVE&CONTINUE] cleanup_old_responses() deleted %d old records', intval($result)));

            return intval($result);
        }
}
