<?php
/** M6 owner; existing public adapters retain their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Privacy_Data_Cleanup_Service {

public static function columns($table) {
        global $wpdb;
        return $wpdb->get_col("SHOW COLUMNS FROM `{$table}`");
    }

private static function check_read() {
        global $wpdb;
        if ($wpdb->last_error) { throw new RuntimeException('Could not resolve participant relationships'); }
    }

public static function scrub($value, $email = '') {
        if (is_object($value)) { $value = (array) $value; }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (preg_match('/(^|_)(email|correo|name|nombre|first_name|last_name|ip|ip_address|user_agent|password|token|fingerprint)(_|$)/i', (string)$key)) { unset($value[$key]); }
                else { $value[$key] = self::scrub($item, $email); }
            }
            return $value;
        }
        return is_string($value) && $email !== '' ? str_ireplace($email, '[removed]', $value) : $value;
    }

public static function run($participant_id, $mode = 'hard_delete', $reason = '') {
        global $wpdb;
        $id = absint($participant_id);
        $result = array('success'=>false,'deleted'=>array(),'anonymized'=>array(),'errors'=>array(),
            'coverage'=>EIPSI_Privacy_Coverage_Report::for_operation('cleanup'));
        $participant = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}survey_participants WHERE id=%d",$id));
        if (!$participant) { $result['errors'][]='Participant not found'; return $result; }
        $email = $participant->email ?? '';
        $tables = array();
        try {
        foreach (array('survey_participants','survey_sessions','survey_magic_links','survey_assignments','survey_email_confirmations',
            'survey_email_log','survey_audit_log','survey_participant_access_log','survey_data_requests','vas_form_results','vas_form_events',
            'eipsi_device_data','eipsi_partial_responses','eipsi_emergency_submissions','survey_nudge_jobs','survey_weekly_reminders',
            'eipsi_pool_assignments','eipsi_pool_email_log','eipsi_randomization_assignments') as $name) {
            $table = $wpdb->prefix.$name;
            $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table))); self::check_read();
            if ($exists === $table) { $tables[$name] = self::columns($table); self::check_read(); }
        }
        // Snapshot identities before removing sessions, assignments or responses.
        $sessions = isset($tables['survey_sessions']) ? $wpdb->get_col($wpdb->prepare("SELECT token FROM {$wpdb->prefix}survey_sessions WHERE participant_id=%d",$id)) : array();
        self::check_read();
        $responses = isset($tables['vas_form_results']) ? $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}vas_form_results WHERE participant_id=%s",(string)$id),ARRAY_A) : array();
        self::check_read();
        $submission_ids = array_column($responses,'id');
        $sessions = array_unique(array_merge($sessions,array_filter(array_column($responses,'session_id'))));
        $fingerprints = array_unique(array_filter(array_column($responses,'user_fingerprint')));
        // Browser identities can be shared. Never erase another participant's copies by a shared key.
        if (isset($tables['vas_form_results'])) {
            foreach (array('session_id'=>'sessions','user_fingerprint'=>'fingerprints') as $column=>$identity_list) {
                foreach ($$identity_list as $index=>$value) {
                    $shared=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}vas_form_results WHERE `{$column}`=%s AND (participant_id IS NULL OR participant_id<>%s)",$value,(string)$id));
                    self::check_read();
                    if ($shared) { unset(${$identity_list}[$index]); $result['coverage']['not_covered'][]='shared_'.$column; }
                }
            }
        }
        $assignments = isset($tables['survey_assignments']) ? $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}survey_assignments WHERE participant_id=%d",$id)) : array();
        self::check_read();
        } catch(Throwable $error) { $result['errors'][]=$error->getMessage(); return $result; }
        if ($wpdb->query('START TRANSACTION') === false) { $result['errors'][]='Could not start cleanup'; return $result; }
        try {
            foreach ($tables as $name=>$columns) {
                $table=$wpdb->prefix.$name;
                if ($name==='survey_participants') { continue; }
                $clauses=array();
                if (in_array('participant_id',$columns,true)) { $clauses[]=$wpdb->prepare('participant_id=%s',(string)$id); }
                if (in_array('session_id',$columns,true) && $sessions) { $clauses[]='session_id IN ('.implode(',',array_map(function($v)use($wpdb){return $wpdb->prepare('%s',$v);},$sessions)).')'; }
                if ($name==='eipsi_device_data' && $submission_ids) { $clauses[]='submission_id IN ('.implode(',',array_map('absint',$submission_ids)).')'; }
                if ($name==='survey_weekly_reminders' && $assignments) { $clauses[]='assignment_id IN ('.implode(',',array_map('absint',$assignments)).')'; }
                if ($name==='survey_nudge_jobs') { $clauses[]=$wpdb->prepare("JSON_EXTRACT(payload,'$.participant_id')=%d",$id); if($assignments){$clauses[]="JSON_EXTRACT(payload,'$.assignment_id') IN (".implode(',',array_map('absint',$assignments)).')';} }
                if ($name==='eipsi_randomization_assignments' && $fingerprints) { $clauses[]='user_fingerprint IN ('.implode(',',array_map(function($v)use($wpdb){return $wpdb->prepare('%s',$v);},$fingerprints)).')'; }
                if (!$clauses) { continue; }
                $where='('.implode(' OR ',$clauses).')';
                $retain=in_array($name,array('survey_email_log','survey_audit_log','survey_participant_access_log'),true) || ($name==='vas_form_results' && $mode!=='b2');
                if ($mode==='anonymize' && in_array($name,array('survey_assignments','eipsi_pool_assignments','survey_weekly_reminders'),true)) { continue; }
                if ($name==='survey_data_requests' && $mode==='anonymize') {
                    // Keep the request so the processing caller can finish it; clear free text/results.
                    $changes=array('reason'=>'','admin_notes'=>'','result_data'=>null);
                } elseif ($retain) {
                    $changes=array();
                    foreach (array('recipient_email','subject','content','error_message','ip_address','user_agent','metadata','session_id','user_fingerprint','device','browser','os','screen_width') as $field) {
                        if(in_array($field,$columns,true)){ $changes[$field]=in_array($field,array('metadata','session_id','ip_address','user_agent'),true)?'':null; }
                    }
                    if (isset($changes['recipient_email']) || in_array('recipient_email',$columns,true)) { $changes['recipient_email']='removed@participant.invalid'; }
                    if (in_array('metadata',$columns,true)) { $changes['metadata']='{}'; }
                    if ($mode!=='anonymize' && in_array('participant_id',$columns,true)) { $changes['participant_id']=$name==='vas_form_results'?'purged_'.bin2hex(random_bytes(12)):0; }
                    if ($name==='vas_form_results') {
                        foreach ($responses as $row) {
                            $row_changes=$changes;
                            $decoded=json_decode($row['form_responses']??'',true);
                            $row_changes['form_responses']=wp_json_encode(self::scrub($decoded?:array(),$email));
                            if($wpdb->update($table,$row_changes,array('id'=>$row['id']))===false){throw new RuntimeException('Failed cleanup: '.$name);}
                        }
                        $result['anonymized'][$table]=count($responses); continue;
                    }
                } else {
                    $count=$wpdb->query("DELETE FROM `{$table}` WHERE {$where}");
                    if($count===false){throw new RuntimeException('Failed cleanup: '.$name);}
                    $result['deleted'][$table]=$count; continue;
                }
                // Explicit columns; never reference legacy/nonexistent metadata fields.
                $changes=array_intersect_key($changes,array_flip($columns));
                if(!$changes){continue;}
                $sets=array();foreach($changes as $key=>$value){$sets[]='`'.$key.'`='.($value===null?'NULL':$wpdb->prepare('%s',$value));}
                $count=$wpdb->query("UPDATE `{$table}` SET ".implode(',',$sets)." WHERE {$where}");
                if($count===false){throw new RuntimeException('Failed cleanup: '.$name);}
                $result['anonymized'][$table]=$count;
            }
            $table=$wpdb->prefix.'survey_participants';
            if($mode==='hard_delete') {
                $count=$wpdb->delete($table,array('id'=>$id));
                if($count===false){throw new RuntimeException('Failed participant PK delete');}
                $result['deleted'][$table]=$count;
            } else {
                $changes=array('email'=>'anonymous-'.$id.'@participant.invalid','password_hash'=>'','first_name'=>null,'last_name'=>null,
                    'consent_ip_address'=>null,'consent_user_agent'=>null,'is_active'=>0);
                foreach($tables['survey_participants']??array() as $column){
                    if(preg_match('/^T\d+_(email|correo|name|nombre|first_name|last_name|ip_address|user_agent|fingerprint)$/i',$column)){$changes[$column]=null;}
                    elseif (preg_match('/^T\d+_/',$column) && isset($participant->$column) && is_string($participant->$column)) { $changes[$column]=self::scrub($participant->$column,$email); }
                }
                $changes=array_intersect_key($changes,array_flip($tables['survey_participants']??array()));
                if($changes && $wpdb->update($table,$changes,array('id'=>$id))===false){throw new RuntimeException('Failed participant anonymization');}
                $result['anonymized'][$table]=1;
            }
            if (isset($tables['survey_audit_log'])) {
                $audit = array('survey_id'=>(int)($participant->survey_id??0),'participant_id'=>0,
                    'action'=>'participant_'.$mode,'actor_type'=>'system',
                    'metadata'=>wp_json_encode(array('operation'=>$mode,'local_only'=>true,'tables_deleted'=>count($result['deleted']),'tables_anonymized'=>count($result['anonymized']))),
                    'created_at'=>current_time('mysql'));
                if ($wpdb->insert($wpdb->prefix.'survey_audit_log',$audit)===false) { throw new RuntimeException('Failed cleanup audit'); }
            }
            if($wpdb->query('COMMIT')===false){throw new RuntimeException('Cleanup commit failed');}
            $result['success']=true;
        } catch(Throwable $error) {
            $result['rolled_back']=$wpdb->query('ROLLBACK')!==false;
            $result['deleted']=array();$result['anonymized']=array();$result['errors'][]=$error->getMessage();
            if (!$result['rolled_back']) { $result['errors'][]='Cleanup rollback failed; manual verification required'; }
        }
        return $result;
    }
}
