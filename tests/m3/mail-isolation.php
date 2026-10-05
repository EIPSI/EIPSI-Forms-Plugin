<?php
/** Mail interception only in the disposable M0 database during M3 HTTP tests. */
if (defined('DB_HOST') && DB_HOST === 'eipsi-m0-db:3306' && defined('DB_NAME') && DB_NAME === 'm0' && get_option('eipsi_m0_isolated_install')) {
    add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
    // Observe actual HTTP hooks without replacing any callback.
    foreach (array(0, 99) as $priority) {
        add_action('eipsi_form_submitted', function($data) use ($priority) {
            if ((int)($data['participant_id'] ?? 0) !== 992207) { return; }
            global $wpdb;
            $trace=get_option('eipsi_m3_completion_trace',array());
            $trace[]=array('priority'=>$priority,'data'=>$data,
                'assignment'=>$wpdb->get_var("SELECT status FROM {$wpdb->prefix}survey_assignments WHERE id=992231"),
                't1'=>$wpdb->get_var("SELECT t1_completed_at FROM {$wpdb->prefix}survey_assignments WHERE id=992231"));
            update_option('eipsi_m3_completion_trace',$trace,false);
        }, $priority);
    }
}
