<?php
/** M4 longitudinal owner. Existing public adapters preserve their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Longitudinal_Assignment_Service {
public static function create_assignments_for_participant($participant_id, $study_id) {
        global $wpdb;

        // ✅ DIAGNÓSTICO: Solo en modo debug
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[EIPSI-DIAG-CREATE] >>> FUNCIÓN create_assignments_for_participant EJECUTÁNDOSE con participant_id=%s, study_id=%s',
                $participant_id, $study_id));
        }

        $participant_id = absint($participant_id);
        $study_id = absint($study_id);

        $result = array(
            'created' => 0,
            'skipped' => 0,
            'errors' => array()
        );

        if (!$participant_id || !$study_id) {
            $result['errors'][] = 'Invalid participant_id or study_id';
            return $result;
        }

        // Validate participant exists
        $participant_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}survey_participants WHERE id = %d",
            $participant_id
        ));

        if (!$participant_exists) {
            $result['errors'][] = 'Participant not found';
            return $result;
        }

        // Get all active waves for the study
        // ✅ v1.5.7 - Buscar TODAS las waves sin filtrar por status (las waves pueden tener cualquier estado)
        $table_name = $wpdb->prefix . 'survey_waves';

        // ✅ DIAGNÓSTICO: Solo en modo debug
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
            error_log(sprintf('[EIPSI-DIAG-CREATE] Tabla esperada: %s - Existe: %s',
                $table_name,
                $table_exists ? 'SÍ' : 'NO'
            ));

            $all_wave_tables = $wpdb->get_col("SHOW TABLES LIKE '%wave%'");
            error_log(sprintf('[EIPSI-DIAG-CREATE] Tablas con "wave" encontradas: %s',
                implode(', ', $all_wave_tables)
            ));
        }

        $active_waves = $wpdb->get_results($wpdb->prepare(
            "SELECT id, wave_index, name, status
             FROM {$wpdb->prefix}survey_waves
             WHERE study_id = %d
             ORDER BY wave_index ASC",
            $study_id
        ));

        // ✅ DEBUG: Solo en modo debug
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[EIPSI-DIAG-CREATE] Buscando waves para study %d: encontradas %d waves',
                $study_id,
                count($active_waves)
            ));
            foreach ($active_waves as $w) {
                error_log(sprintf('[EIPSI-DIAG-CREATE] Wave: id=%d, wave_index=%d, name=%s, status=%s',
                    $w->id, $w->wave_index, $w->name, $w->status));
            }
        }

        if (empty($active_waves)) {
            // No active waves - not an error, just nothing to do
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('[EIPSI-DIAG-CREATE] No se encontraron waves para study %d', $study_id));
            }
            return $result;
        }

        // Create assignment for each active wave
        foreach ($active_waves as $wave) {
            // Check if assignment already exists (idempotent)
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}survey_assignments
                 WHERE wave_id = %d AND participant_id = %d",
                $wave->id,
                $participant_id
            ));

            if ($existing) {
                $result['skipped']++;
                continue;
            }

            // Create the assignment
            $inserted = $wpdb->insert(
                $wpdb->prefix . 'survey_assignments',
                array(
                    'study_id' => $study_id,
                    'wave_id' => $wave->id,
                    'participant_id' => $participant_id,
                    'status' => 'pending',
                    'created_at' => current_time('mysql')
                ),
                array('%d', '%d', '%d', '%s', '%s')
            );

            if ($inserted === false) {
                // Check if it's a duplicate (race condition)
                if (strpos($wpdb->last_error, 'Duplicate entry') !== false) {
                    $result['skipped']++;
                } else {
                    $result['errors'][] = sprintf(
                        'Failed to create assignment for wave %d: %s',
                        $wave->id,
                        $wpdb->last_error
                    );
                }
            } else {
                $result['created']++;
                $assignment_id = (int) $wpdb->insert_id;

                // v2.1.5 - Solo disparar evento para la PRIMERA wave activa (menor wave_index)
                // Las waves posteriores se activarán automáticamente cuando se complete la anterior
                $is_first_wave = ($wave->wave_index == $active_waves[0]->wave_index);

                if ($is_first_wave) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log(sprintf('[EIPSI-DIAG-CREATE] Firing eipsi_wave_available for assignment %d (wave %d, FIRST wave)',
                            $assignment_id, $wave->id));
                    }
                    do_action('eipsi_wave_available', $assignment_id);
                } else {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log(sprintf('[EIPSI-DIAG-CREATE] Assignment %d created for wave %d (wave_index=%d) - nudges will start when previous wave is completed',
                            $assignment_id, $wave->id, $wave->wave_index));
                    }
                }
            }
        }

        // Log the result (solo en debug)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[EIPSI-DIAG-CREATE] Final: creados=%d, skipped=%d, errores=%d para participant %d, study %d',
                $result['created'],
                $result['skipped'],
                count($result['errors']),
                $participant_id,
                $study_id
            ));
        }

        return $result;
    }
}
