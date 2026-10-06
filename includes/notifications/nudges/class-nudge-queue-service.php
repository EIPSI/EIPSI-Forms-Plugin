<?php
/** Notifications owner; public WordPress adapters retain existing contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Notification_Nudge_Queue_Service {
    const PROCESSING_LEASE_SECONDS = 900;
    private static $claims = array();

    private static function lock_name($id) {
        global $wpdb;
        return 'eipsi_job_' . md5(DB_NAME . ':' . $wpdb->prefix . ':' . intval($id));
    }
    private static function owns_claim($id) {
        global $wpdb;
        if (!isset(self::$claims[$id])) { return false; }
        $connection = $wpdb->get_var('SELECT CONNECTION_ID()');
        return (string)$connection === (string)self::$claims[$id]
            && (string)$wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', self::lock_name($id))) === (string)$connection;
    }
    public static function release_processing($job_id) {
        global $wpdb;
        $error=$wpdb->last_error;
        if (self::owns_claim($job_id)) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name($job_id)));
        }
        unset(self::$claims[$job_id]);
        $wpdb->last_error=$error;
    }
    private static function write_outcome($result, $id, $operation) {
        if ($result === false || (int)$result !== 1) {
            // IDs and operation only: never payload, email, token or raw DB error.
            error_log(sprintf('[EIPSI JobQueue] persistence_%s job=%d operation=%s', $result === false ? 'sql_error' : 'conflict', $id, $operation));
            return false;
        }
        return true;
    }
    private static function retry_schedule($retries) {
        $minutes = array(5, 15, 45, 120, 360);
        return date('Y-m-d H:i:s', current_time('timestamp') + $minutes[min($retries - 1, 4)] * MINUTE_IN_SECONDS);
    }

    /** Recover only stale AND unowned jobs. Live workers retain the connection lock. */
    public static function recover_stale_processing($limit = 10) {
        global $wpdb;
        $table = self::get_table_name();
        $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - self::PROCESSING_LEASE_SECONDS);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, retries, processed_at, updated_at FROM {$table}
            WHERE status='processing' AND COALESCE(processed_at,updated_at) <= %s ORDER BY id LIMIT %d", $cutoff, max(1,intval($limit))));
        if ($rows === null || $wpdb->last_error) { return false; }
        $changed = 0;
        foreach ($rows as $row) {
            if (self::owns_claim($row->id)) { continue; }
            $lock = self::lock_name($row->id);
            if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== '1') { continue; }
            try {
                $retries = intval($row->retries) + 1;
                $terminal = $retries >= 5;
                $data = array('status'=>$terminal?'failed':'pending', 'retries'=>$retries,
                    'error'=>'Processing lease expired; prior delivery outcome unknown', 'processed_at'=>null);
                if ($terminal) { $data['failed_at']=current_time('mysql'); }
                else { $data['scheduled_at']=self::retry_schedule($retries); }
                $where = array('id'=>$row->id,'status'=>'processing','retries'=>$row->retries,'processed_at'=>$row->processed_at,'updated_at'=>$row->updated_at);
                $result = $wpdb->update($table,$data,$where);
                if ($result === false) { self::write_outcome($result,$row->id,'recover'); return false; }
                $changed += intval($result);
            } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
        }
        return $changed;
    }


public static function cancel_follow_up_jobs($assignment_id) {
        global $wpdb;
        $table = self::get_table_name();
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'cancelled', updated_at = %s
             WHERE status = 'pending' AND job_type IN ('send_nudge_1','send_nudge_2','send_nudge_3','send_nudge_4')
             AND JSON_EXTRACT(payload, '$.assignment_id') = %d", current_time('mysql'), $assignment_id
        ));
    }

private static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'survey_nudge_jobs';
    }

public static function enqueue($job_type, $payload, $priority = 10, $scheduled_at = null) {
        global $wpdb;

        if (!$scheduled_at) {
            $scheduled_at = current_time('mysql');
        }

        $result = $wpdb->insert(
            self::get_table_name(),
            array(
                'job_type' => sanitize_text_field($job_type),
                'payload' => wp_json_encode($payload),
                'priority' => intval($priority),
                'scheduled_at' => $scheduled_at,
                'status' => 'pending',
                'retries' => 0,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%d', '%s', '%s', '%d', '%s')
        );

        if ($result !== 1) {
            error_log('[EIPSI JobQueue] persistence_failure operation=enqueue');
            return false;
        }

        $job_id = $wpdb->insert_id;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[EIPSI JobQueue] Enqueued job #%d: type=%s, priority=%d, scheduled=%s',
                $job_id,
                $job_type,
                $priority,
                $scheduled_at
            ));
        }

        return $job_id;
    }

public static function count_pending_urgent() {
        global $wpdb;

        $table = self::get_table_name();
        $now = current_time('mysql');

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE status = 'pending'
             AND scheduled_at <= %s
             AND retries < 5",
            $now
        ));

        return intval($count);
    }

public static function get_pending_jobs($limit = 10) {
        global $wpdb;
        
        if (self::recover_stale_processing($limit) === false) { return array(); }
        // SKIP LOCKED requiere MySQL 8.0+ o PostgreSQL
        // Para WordPress/MySQL 5.7, usamos status='pending' + update atómico
        $table = self::get_table_name();
        $now = current_time('mysql');
        
        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE status = 'pending' 
             AND scheduled_at <= %s 
             AND retries < 5
             ORDER BY priority ASC, scheduled_at ASC 
             LIMIT %d",
            $now,
            intval($limit)
        ));
        
        return $jobs;
    }

public static function mark_processing($job_id) {
        global $wpdb;
        // GET_LOCK is recursive on MariaDB: do not acquire twice in one connection.
        if (self::owns_claim($job_id)) { return false; }
        $lock=self::lock_name($job_id);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock)) !== '1') { return false; }
        $now=current_time('mysql');
        $table=self::get_table_name();
        $result=$wpdb->query($wpdb->prepare("UPDATE `{$table}` SET `status`='processing', `processed_at`=%s
            WHERE `id`=%d AND `status`='pending' AND scheduled_at<=%s AND retries<5",$now,$job_id,$now));
        if (!self::write_outcome($result,$job_id,'claim')) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); return false;
        }
        self::$claims[$job_id]=$wpdb->get_var('SELECT CONNECTION_ID()');
        return true;
    }

public static function mark_completed($job_id, $result = null) {
        global $wpdb;
        if (!self::owns_claim($job_id)) { return false; }
        try {
            $data=array('status'=>'completed','completed_at'=>current_time('mysql'));
            if ($result) { $data['result']=$result; }
            return self::write_outcome($wpdb->update(self::get_table_name(),$data,
                array('id'=>$job_id,'status'=>'processing')),$job_id,'completed');
        } finally { self::release_processing($job_id); }
    }

/** Explicit internal outcome; facade bool retains retry-only semantics. */
public static function persist_retry_outcome($job_id, $error) {
        global $wpdb;
        if (!self::owns_claim($job_id)) { return 'conflict'; }
        try {
            $job=$wpdb->get_row($wpdb->prepare('SELECT retries,status FROM '.self::get_table_name().' WHERE id=%d',$job_id));
            if (!$job || $job->status!=='processing') { return $wpdb->last_error?'sql_error':'conflict'; }
            $irrecoverable=false;
            foreach(array('Assignment not found','Participant not found','Wave not found','Invalid payload JSON','Invalid assignment ID','Invalid participant ID') as $message) {
                if (stripos($error,$message)!==false) { $irrecoverable=true; break; }
            }
            $retries=intval($job->retries)+($irrecoverable?0:1);
            $terminal=$irrecoverable || $retries>=5;
            $data=array('status'=>$terminal?'failed':'pending','retries'=>$retries,
                'error'=>sanitize_text_field($error.($irrecoverable?' [IRRECOVERABLE]':'')));
            if ($terminal) { $data['failed_at']=current_time('mysql'); }
            else { $data['scheduled_at']=self::retry_schedule($retries); }
            $result=$wpdb->update(self::get_table_name(),$data,array('id'=>$job_id,'status'=>'processing','retries'=>$job->retries));
            if (!self::write_outcome($result,$job_id,$terminal?'failed':'retry')) { return $result===false?'sql_error':'conflict'; }
            return $terminal?'failed':'pending';
        } finally { self::release_processing($job_id); }
    }

public static function mark_for_retry($job_id, $error) {
        return self::persist_retry_outcome($job_id,$error)==='pending';
    }

public static function get_stats() {
        global $wpdb;
        $table = self::get_table_name();

        $stats = $wpdb->get_row(
            "SELECT
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status='processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed
             FROM {$table}"
        );

        return array(
            'pending' => intval($stats->pending),
            'processing' => intval($stats->processing),
            'completed' => intval($stats->completed),
            'failed' => intval($stats->failed)
        );
    }

public static function cancel_jobs_for_assignment($assignment_id) {
        global $wpdb;
        $table = self::get_table_name();

        $assignment_id = intval($assignment_id);

        // Cancelar jobs pendientes que contengan el assignment_id en el payload
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = 'cancelled',
                 updated_at = %s
             WHERE status = 'pending'
             AND JSON_EXTRACT(payload, '$.assignment_id') = %d",
            current_time('mysql'),
            $assignment_id
        ));

        if ($result === false) {
            error_log('[EIPSI JobQueue] persistence_sql_error operation=cancel assignment=' . $assignment_id);
            return false;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[EIPSI JobQueue] Cancelled %d jobs for assignment %d',
                $result,
                $assignment_id
            ));
        }

        return intval($result);
    }
public static function cancel_pending_nudges($participant_id, $wave_id) {
        global $wpdb;
        $table=self::get_table_name();
        $result=$wpdb->query($wpdb->prepare("UPDATE {$table} SET status='cancelled',updated_at=%s
            WHERE status='pending' AND job_type IN ('send_nudge_0','send_nudge_1','send_nudge_2','send_nudge_3','send_nudge_4')
            AND JSON_EXTRACT(payload,'$.participant_id')=%d AND JSON_EXTRACT(payload,'$.wave_id')=%d",
            current_time('mysql'),$participant_id,$wave_id));
        if ($result===false) { error_log('[EIPSI JobQueue] persistence_sql_error operation=cancel_participant_wave'); }
        return $result;
    }
}
