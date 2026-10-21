<?php
final class EIPSI_Security_Maintenance {
    public static function register(): void {
        if (has_action('eipsi_process_nudge_jobs',array(__CLASS__,'run')) === false) { add_action('eipsi_process_nudge_jobs',array(__CLASS__,'run'),20); }
        if (!wp_next_scheduled('eipsi_process_nudge_jobs')) { wp_schedule_event(time()+300,'every_5_minutes','eipsi_process_nudge_jobs'); }
    }
    public static function run(): void {
        if(class_exists(\EIPSI\ExternalApi\Operations::class))(new \EIPSI\ExternalApi\Operations())->maintenance();
        try {
            EIPSI_Rate_Limiter::cleanup();
            require_once EIPSI_FORMS_PLUGIN_DIR.'includes/study-engine/management/Exports.php';
            EIPSI\StudyEngine\Management\Exports::cleanup();
        } catch (Throwable $e) { if(class_exists('EIPSI\Scheduler\Heartbeat'))\EIPSI\Scheduler\Heartbeat::failure(); EIPSI_Security_Log::event('HOUSEKEEPING_FAILED',array('code'=>'CLEANUP_RETRY_NEXT_TICK')); }
    }
}
