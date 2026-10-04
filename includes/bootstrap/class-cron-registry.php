<?php
/** M1 bootstrap: preserve existing contracts and registration order. */
if (!defined('ABSPATH')) { exit; }

final class EIPSI_Cron_Registry {
    public static function register_schedules() {
        add_filter('cron_schedules', function($schedules) {
            if (!isset($schedules['weekly'])) {
                $schedules['weekly'] = array(
                    'interval' => WEEK_IN_SECONDS,
                    'display' => __('Once Weekly', 'eipsi-forms'),
                );
            }


            if (!isset($schedules['eipsi_daily'])) {
                $schedules['eipsi_daily'] = array(
                    'interval' => DAY_IN_SECONDS,
                    'display' => __('Once Daily (EIPSI)', 'eipsi-forms'),
                );
            }

            if (!isset($schedules['eipsi_weekly'])) {
                $schedules['eipsi_weekly'] = array(
                    'interval' => WEEK_IN_SECONDS,
                    'display' => __('Once Weekly (EIPSI)', 'eipsi-forms'),
                );
            }

            if (!isset($schedules['eipsi_monthly'])) {
                $schedules['eipsi_monthly'] = array(
                    'interval' => 30 * DAY_IN_SECONDS,
                    'display' => __('Once Monthly (EIPSI)', 'eipsi-forms'),
                );
            }


            if (!isset($schedules['every_minute'])) {
                $schedules['every_minute'] = array(
                    'interval' => MINUTE_IN_SECONDS,
                    'display' => __('Every Minute', 'eipsi-forms'),
                );
            }


            if (!isset($schedules['every_5_minutes'])) {
                $schedules['every_5_minutes'] = array(
                    'interval' => 5 * MINUTE_IN_SECONDS,
                    'display' => __('Every 5 Minutes (Legacy)', 'eipsi-forms'),
                );
            }

            return $schedules;
        });
    }

    public static function register_access_log_cleanup() {
        add_action('eipsi_purge_access_logs_daily', 'eipsi_purge_access_logs_handler');
    }

    public static function register_partial_cleanup() {
        add_action('eipsi_cleanup_partial_responses', 'eipsi_run_partial_cleanup');
    }

    public static function schedule_activation() {
        // === Cron Reminders Scheduling (Fase 2 - Legacy) ===
        if (!wp_next_scheduled('eipsi_send_take_reminders_daily')) {
            wp_schedule_event(time(), 'daily', 'eipsi_send_take_reminders_daily');
        }

        if (!wp_next_scheduled('eipsi_send_take_reminders_weekly')) {
            wp_schedule_event(time(), 'weekly', 'eipsi_send_take_reminders_weekly');
        }

        // === Cron Reminders Scheduling (Longitudinal) ===
        if (!wp_next_scheduled('eipsi_send_wave_reminders_hourly')) {
            wp_schedule_event(time(), 'every_minute', 'eipsi_send_wave_reminders_hourly');
        }

        if (!wp_next_scheduled('eipsi_send_dropout_recovery_hourly')) {
            wp_schedule_event(time(), 'every_minute', 'eipsi_send_dropout_recovery_hourly');
        }

        if (!wp_next_scheduled('eipsi_purge_access_logs_daily')) {
            wp_schedule_event(time(), 'daily', 'eipsi_purge_access_logs_daily');
        }

        if (!wp_next_scheduled('eipsi_cleanup_unconfirmed_participants_daily')) {
            wp_schedule_event(time(), 'daily', 'eipsi_cleanup_unconfirmed_participants_daily');
        }

        if (!wp_next_scheduled("eipsi_cleanup_pool_email_logs_monthly")) {
            wp_schedule_event(time(), "eipsi_monthly", "eipsi_cleanup_pool_email_logs_monthly");
        }

        if (!wp_next_scheduled('eipsi_cleanup_partial_responses')) {
            wp_schedule_event(time(), 'daily', 'eipsi_cleanup_partial_responses');
        }

        // === T1-Anchor System Crons (v2.6.0) ===
        // Process assignment expirations every minute
        if (!wp_next_scheduled('eipsi_process_assignment_expirations')) {
            wp_schedule_event(time(), 'every_minute', 'eipsi_process_assignment_expirations');
        }

        // Process wave availability notifications every minute
        if (!wp_next_scheduled('eipsi_process_wave_availability')) {
            wp_schedule_event(time(), 'every_minute', 'eipsi_process_wave_availability');
        }

        // === Phase 2 T1-Anchor: Wave Expiration Check (v2.6.0) ===
        // Check for expired waves every hour (transitions pending/available → expired)
        if (!wp_next_scheduled('eipsi_hourly_wave_expiration_check')) {
            wp_schedule_event(time(), 'hourly', 'eipsi_hourly_wave_expiration_check');
        }

        // === Phase 5 T1-Anchor: Wave Skipping (v2.6.0) ===
        // Check for waves to skip every hour (marks pending/in_progress → skipped)
        if (!wp_next_scheduled('eipsi_wave_skipping_cron')) {
            wp_schedule_event(time(), 'hourly', 'eipsi_wave_skipping_cron');
        }

        // === Phase 5 T1-Anchor: Weekly T1 Reminders (v2.6.0) ===
        // Send weekly reminders to T1 non-completers (daily check)
        if (!wp_next_scheduled('eipsi_weekly_t1_reminders_cron')) {
            wp_schedule_event(time(), 'daily', 'eipsi_weekly_t1_reminders_cron');
        }
    }

    /** Only hooks demonstrably owned by this plugin; all argument variants included. */
    public static function owners() {
        return array(
            'eipsi_send_take_reminders_daily' => 'legacy reminders',
            'eipsi_send_take_reminders_weekly' => 'legacy reminders',
            'eipsi_send_wave_reminders_hourly' => 'longitudinal reminders',
            'eipsi_send_dropout_recovery_hourly' => 'dropout recovery',
            'eipsi_purge_access_logs_daily' => 'participant access logs',
            'eipsi_cleanup_unconfirmed_participants_daily' => 'double opt-in',
            'eipsi_cleanup_pool_email_logs_monthly' => 'pool email logs',
            'eipsi_cleanup_partial_responses' => 'partial responses',
            'eipsi_process_assignment_expirations' => 'assignment expiration',
            'eipsi_process_wave_availability' => 'wave availability',
            'eipsi_hourly_wave_expiration_check' => 'wave expiration',
            'eipsi_wave_skipping_cron' => 'wave skipping',
            'eipsi_weekly_t1_reminders_cron' => 'weekly T1',
            'eipsi_study_cron_job' => 'per-study reminders',
            'eipsi_send_manual_reminder' => 'manual reminders',
            'eipsi_process_nudge_jobs' => 'nudge queue worker',
            'eipsi_scheduled_nudge_event' => 'scheduled nudge stages',
            'eipsi_wave_available' => 'assignment availability event',
            'eipsi_wave_available_retry' => 'availability retry',
        );
    }

    public static function unschedule_owned_events() {
        foreach (array_keys(self::owners()) as $hook) {
            wp_unschedule_hook($hook);
        }
    }
}
