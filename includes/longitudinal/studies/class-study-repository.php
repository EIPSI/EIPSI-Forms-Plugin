<?php
/** M4 longitudinal owner. Existing public adapters preserve their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Longitudinal_Study_Repository {
public static function get($study_id) {
    global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}survey_studies WHERE id=%d",$study_id));
}
public static function insert($data,$formats) { global $wpdb;return $wpdb->insert($wpdb->prefix.'survey_studies',$data,$formats); }
public static function update($study_id,$data,$formats) { global $wpdb;return $wpdb->update($wpdb->prefix.'survey_studies',$data,array('id'=>$study_id),$formats,array('%d')); }


public static function eipsi_generate_unique_study_code($requested_code) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'survey_studies';
    $code = $requested_code;
    $counter = 1;

    while (true) {
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE study_code = %s",
            $code
        ));

        if (!$existing) {
            return $code;
        }

        $counter++;
        $code = $requested_code . '-' . $counter;
    }
}
}
