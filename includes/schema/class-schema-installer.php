<?php
if (!defined('ABSPATH')) { exit; }
class EIPSI_Schema_Installer {
    public static function repair_local_schema() {
        try { return EIPSI_Schema_Lock::run(function() {
            $result = array('success'=>true);
            foreach (EIPSI_Schema_Registry::get_table_creation_order() as $slug) {
                $row = EIPSI_Schema_Repair_Service::sync_local_table($slug);
                $result[$slug.'_table'] = $row;
                if (!$row['success']) { $result['success'] = false; }
            }
            if ($result['success']) { self::add_foreign_keys_phase2(); EIPSI_Schema_Version_Service::record_verified(); }
            return $result;
        }); } catch(Throwable $e) { return array('success'=>false,'error'=>$e->getMessage()); }
    }
public static function add_foreign_keys_phase2() {

        global $wpdb;

        $fks = array(

            'survey_waves' => array(

                'fk_waves_study' => "ALTER TABLE {$wpdb->prefix}survey_waves ADD CONSTRAINT fk_waves_study FOREIGN KEY (study_id) REFERENCES {$wpdb->prefix}survey_studies(id) ON DELETE CASCADE",

            ),

            'survey_assignments' => array(

                'fk_assignments_study' => "ALTER TABLE {$wpdb->prefix}survey_assignments ADD CONSTRAINT fk_assignments_study FOREIGN KEY (study_id) REFERENCES {$wpdb->prefix}survey_studies(id) ON DELETE CASCADE",

                'fk_assignments_wave' => "ALTER TABLE {$wpdb->prefix}survey_assignments ADD CONSTRAINT fk_assignments_wave FOREIGN KEY (wave_id) REFERENCES {$wpdb->prefix}survey_waves(id) ON DELETE CASCADE",

                'fk_assignments_participant' => "ALTER TABLE {$wpdb->prefix}survey_assignments ADD CONSTRAINT fk_assignments_participant FOREIGN KEY (participant_id) REFERENCES {$wpdb->prefix}survey_participants(id) ON DELETE CASCADE",

            ),

            'survey_weekly_reminders' => array(

                'fk_weekly_reminders_assignment' => "ALTER TABLE {$wpdb->prefix}survey_weekly_reminders ADD CONSTRAINT fk_weekly_reminders_assignment FOREIGN KEY (assignment_id) REFERENCES {$wpdb->prefix}survey_assignments(id) ON DELETE CASCADE",

            ),

            'eipsi_pool_assignments' => array(

                'fk_pool_assignments_pool' => "ALTER TABLE {$wpdb->prefix}eipsi_pool_assignments ADD CONSTRAINT fk_pool_assignments_pool FOREIGN KEY (pool_id) REFERENCES {$wpdb->prefix}eipsi_longitudinal_pools(id) ON DELETE CASCADE",

                'fk_pool_assignments_participant' => "ALTER TABLE {$wpdb->prefix}eipsi_pool_assignments ADD CONSTRAINT fk_pool_assignments_participant FOREIGN KEY (participant_id) REFERENCES {$wpdb->prefix}survey_participants(id) ON DELETE CASCADE",

                'fk_pool_assignments_study' => "ALTER TABLE {$wpdb->prefix}eipsi_pool_assignments ADD CONSTRAINT fk_pool_assignments_study FOREIGN KEY (study_id) REFERENCES {$wpdb->prefix}survey_studies(id) ON DELETE CASCADE",

            ),

        );

        foreach ( $fks as $table => $constraints ) {

            foreach ( $constraints as $name => $sql ) {

                if ( function_exists( 'eipsi_longitudinal_ensure_foreign_key' ) ) {

                    eipsi_longitudinal_ensure_foreign_key( $wpdb->prefix . $table, $name, $sql );

                }

            }

        }

    }

}
