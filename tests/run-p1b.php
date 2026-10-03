<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('EIPSI_P1B_TESTS', true);
require __DIR__ . '/bootstrap-p1.php';
if (!class_exists('WP_Error')) { require ABSPATH . 'wp-includes/class-wp-error.php'; }
function eipsi_user_can_manage_longitudinal() { return $GLOBALS['p0_admin']; }
function sanitize_textarea_field($text) { return sanitize_text_field($text); }
function plugin_dir_path($file) { return dirname($file) . '/'; }
function wp_die($message = '') { throw new P0JsonResponse(false, array('message' => $message)); }
function wp_clear_scheduled_hook($hook, $args) {
    unset($GLOBALS['p1b_cron'][$hook . serialize($args)]);
    return 1;
}
function wp_schedule_single_event($time, $hook, $args) {
    global $wpdb;
    if (!empty($GLOBALS['p1b_schedule_failure'])) { return false; }
    $config = $wpdb->get_var("SELECT nudge_config FROM {$wpdb->prefix}survey_waves WHERE id = 21");
    $GLOBALS['p1b_cron'][$hook . serialize($args)] = compact('time', 'hook', 'args', 'config');
    return true;
}
// Same registration order as the plugin bootstrap. AJAX handlers loaded by shared bootstrap.
foreach (array('study-close-handler.php', 'waves-manager-api.php', 'study-dashboard-api.php', 'pool-assignment-api.php', 'pool-dashboard-api.php') as $file) {
    require EIPSI_FORMS_PLUGIN_DIR . 'admin/' . $file;
}
require EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-wave-service.php';
require EIPSI_FORMS_PLUGIN_DIR . 'includes/services/class-nudge-service.php';
require EIPSI_FORMS_PLUGIN_DIR . 'includes/services/class-nudge-event-scheduler.php';
require EIPSI_FORMS_PLUGIN_DIR . 'includes/services/class-nudge-job-queue.php';
function p1b_wave_request($extra = array()) {
    return array_merge(array('wave_id' => 21, 'study_id' => 3, 'name' => 'Edited wave', 'wave_index' => 2, 'form_id' => 101,
        'start_date' => '', 'due_date' => '', 'status' => 'active', 'has_time_limit' => '0', 'is_mandatory' => '0',
        'nonce' => 'valid:eipsi_waves_nonce'), $extra);
}
function p1b_config($value = 17, $unit = 'minutes', $enabled = true) {
    return array('nudge_1' => array('enabled' => $enabled, 'value' => $value, 'unit' => $unit));
}
function p1b_ready($db) {
    $GLOBALS['p0_admin'] = true;
    $db->update($db->prefix . 'survey_assignments', array('available_at' => gmdate('Y-m-d H:i:s', time() + 3600), 'reminder_count' => 1), array('participant_id' => 7));
}
function p1b_event($stage = 1) {
    foreach ($GLOBALS['p1b_cron'] as $event) {
        if ($event['hook'] === EIPSI_Nudge_Event_Scheduler::NUDGE_EVENT_HOOK && $event['args'][0]['stage'] === $stage) { return $event; }
    }
    throw new RuntimeException('Expected event missing');
}
$tests = array();
$expected = array('delete_participant' => 'eipsi_delete_participant_dispatch', 'close_study' => 'wp_ajax_eipsi_close_study_handler',
    'get_pool_analytics' => 'eipsi_get_pool_analytics_dispatch', 'export_pool_assignments' => 'eipsi_export_pool_assignments_dispatch');
foreach ($expected as $action => $callback) {
    $tests['AJAX único callback ' . $action] = function ($db) use ($action, $callback) {
        p0_assert(($GLOBALS['p0_hooks']['wp_ajax_eipsi_' . $action] ?? array()) === array($callback), 'Ambiguous registration');
        p0_assert(empty($GLOBALS['p0_hooks']['wp_ajax_nopriv_eipsi_' . $action]), 'Unexpected public action');
    };
}
$tests['Cierre dashboard usa nonce y modelo vigentes, sin interceptación legacy'] = function ($db) {
    $GLOBALS['p0_admin'] = true; $_POST = array('study_id' => 3, 'nonce' => 'valid:eipsi_study_dashboard_nonce');
    p0_assert(p0_ajax($GLOBALS['p0_hooks']['wp_ajax_eipsi_close_study'][0])->success, 'Dashboard close intercepted');
    p0_assert($db->get_var("SELECT status FROM {$db->prefix}survey_studies WHERE id=3") === 'completed', 'Wrong close model');
};
$tests['Eliminar participante distingue los dos nonces de consumidores actuales'] = function ($db) {
    $GLOBALS['p0_admin'] = true;
    foreach (array('eipsi_study_dashboard_nonce', 'eipsi_waves_nonce') as $nonce) {
        $_POST = array('nonce' => 'valid:' . $nonce);
        $response = p0_ajax($GLOBALS['p0_hooks']['wp_ajax_eipsi_delete_participant'][0]);
        p0_assert(!$response->success && $response->status !== 403, 'Correct UI nonce intercepted');
    }
};
$tests['Pools preservan contratos GET hub y POST dashboard sin interceptación'] = function ($db) {
    $GLOBALS['p0_admin'] = true;
    foreach (array('get_pool_analytics', 'export_pool_assignments') as $action) {
        foreach (array('hub', 'dashboard') as $mode) {
            $_POST = $mode === 'dashboard' ? array('nonce' => 'valid:eipsi_pool_dashboard_nonce') : array();
            $_GET = $mode === 'hub' ? array('nonce' => 'valid:eipsi_pool_hub') : array();
            $response = p0_ajax($GLOBALS['p0_hooks']['wp_ajax_eipsi_' . $action][0]);
            p0_assert(!$response->success && stripos($response->data['message'], 'Pool') !== false, 'Wrong callback/nonce intercepted');
        }
    }
};
$tests['Dispatchers no aceptan nonce inválido'] = function ($db) use ($expected) {
    $GLOBALS['p0_admin'] = true; $_POST = $_GET = array('nonce' => 'invalid');
    foreach ($expected as $callback) { p0_assert(!p0_ajax($callback)->success, 'Invalid nonce accepted'); }
};
$tests['Wave campos soportados y ceros persisten, assignments intactos'] = function ($db) {
    $GLOBALS['p0_admin'] = true;
    $before = $db->get_results("SELECT * FROM {$db->prefix}survey_assignments", ARRAY_A);
    $_POST = p1b_wave_request(); p0_assert(p0_ajax('wp_ajax_eipsi_save_wave_handler')->success, 'Supported edit rejected');
    $wave = EIPSI_Wave_Service::get_wave(21);
    p0_assert($wave->name === 'Edited wave' && (int)$wave->has_time_limit === 0 && (int)$wave->is_mandatory === 0, 'Fields ignored');
    p0_assert($db->get_results("SELECT * FROM {$db->prefix}survey_assignments", ARRAY_A) === $before, 'Assignments altered');
};
$tests['Wave índice existente es read-only y no aparenta guardarse'] = function ($db) {
    $GLOBALS['p0_admin'] = true; $_POST = p1b_wave_request(array('wave_index' => 1));
    p0_assert(!p0_ajax('wp_ajax_eipsi_save_wave_handler')->success, 'Read-only edit acknowledged');
    p0_assert((int) EIPSI_Wave_Service::get_wave(21)->wave_index === 2, 'Index changed');
};
$tests['Wave fechas y formulario con assignments quedan protegidos'] = function ($db) {
    $GLOBALS['p0_admin'] = true;
    foreach (array('start_date' => '2026-10-20T12:00', 'due_date' => '2026-10-21T12:00', 'form_id' => 103) as $key => $value) {
        $_POST = p1b_wave_request(array($key => $value));
        p0_assert(!p0_ajax('wp_ajax_eipsi_save_wave_handler')->success, 'Assigned wave mutable: ' . $key);
    }
};
$tests['Wave sin assignments guarda y limpia fechas realmente'] = function ($db) {
    $GLOBALS['p0_admin'] = true; $db->delete($db->prefix . 'survey_assignments', array('wave_id' => 21));
    $_POST = p1b_wave_request(array('start_date' => '2026-10-20T12:00', 'due_date' => '2026-10-21T12:00'));
    p0_assert(p0_ajax('wp_ajax_eipsi_save_wave_handler')->success, 'Dates not saved');
    p0_assert(EIPSI_Wave_Service::get_wave(21)->start_date === '2026-10-20 12:00:00', 'Start ignored');
    $_POST = p1b_wave_request(); p0_assert(p0_ajax('wp_ajax_eipsi_save_wave_handler')->success, 'Dates not cleared');
    p0_assert(EIPSI_Wave_Service::get_wave(21)->start_date === null, 'Empty start ignored');
};
$tests['Wave descripción y offsets no soportados rechazan éxito'] = function ($db) {
    $GLOBALS['p0_admin'] = true;
    foreach (array('description' => 'unsupported', 'offset_minutes' => 300, 'window_minutes' => 400) as $key => $value) {
        $_POST = p1b_wave_request(array($key => $value)); p0_assert(!p0_ajax('wp_ajax_eipsi_save_wave_handler')->success, 'Unsupported field silently ignored');
    }
};
$tests['Crear T1 mantiene índice 1, límite y fechas'] = function ($db) {
    $GLOBALS['p0_admin'] = true; $_POST = p1b_wave_request(array('wave_id' => 0, 'wave_index' => 1, 'has_time_limit' => 1, 'completion_time_limit' => 15, 'start_date' => '2026-10-20T12:00'));
    $response = p0_ajax('wp_ajax_eipsi_save_wave_handler'); p0_assert($response->success, 'Create failed');
    $wave = EIPSI_Wave_Service::get_wave($response->data['wave_id']);
    p0_assert((int)$wave->wave_index === 1 && (int)$wave->completion_time_limit === 15 && $wave->start_date === '2026-10-20 12:00:00', 'Create fields dropped');
};
$tests['Crear wave rechaza índice duplicado y fecha inválida'] = function ($db) {
    p0_assert(is_wp_error(EIPSI_Wave_Service::create_wave(3, array('name'=>'duplicate','form_id'=>101,'wave_index'=>2))), 'Duplicate index accepted');
    p0_assert(is_wp_error(EIPSI_Wave_Service::create_wave(3, array('name'=>'invalid','form_id'=>101,'wave_index'=>3,'start_date'=>'2026-02-31T12:00'))), 'Invalid date accepted');
};
foreach (array(1,2,3) as $index) {
    $tests['Submit T' . $index . ' copia con índice correcto'] = function ($db) use ($index) {
        p1_session(); $db->update($db->prefix . 'survey_waves', array('wave_index' => $index), array('id' => 21));
        $_POST = p1_submit_request(); p0_assert(p0_ajax('eipsi_forms_submit_form_handler')->success, 'Valid submit failed');
        $participant = $db->get_row("SELECT * FROM {$db->prefix}survey_participants WHERE id=7", ARRAY_A);
        p0_assert(($participant['T' . $index . '_answer'] ?? null) === '4', 'Wrong T index copied');
        p0_assert(!array_key_exists('T' . ($index + 1) . '_answer', $participant), 'Submission shifted by one');
        $row = $db->get_row("SELECT wave_index FROM {$db->prefix}vas_form_results LIMIT 1");
        p0_assert((int)$row->wave_index === $index, 'Result index wrong');
    };
}
$tests['Recalculador T1 no sobrescribe su assignment'] = function ($db) {
    require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/services/class-wave-recalculator.php';
    $db->update($db->prefix . 'survey_waves', array('wave_index'=>1), array('id'=>21));
    $db->update($db->prefix . 'survey_assignments', array('status'=>'submitted','available_at'=>'2026-10-01 12:00:00'), array('participant_id'=>7));
    EIPSI_Wave_Recalculator::recalculate_after_t1(7,3,'2026-10-03 12:00:00');
    p0_assert($db->get_var("SELECT available_at FROM {$db->prefix}survey_assignments WHERE participant_id=7") === '2026-10-01 12:00:00', 'T1 anchor recalculated itself');
};
$tests['Nudges persisten antes de programar y scheduler lee nuevos offsets'] = function ($db) {
    p1b_ready($db); $_POST = array('wave_id'=>21,'nonce'=>'valid:eipsi_study_dashboard_nonce','enabled'=>'true','nudges'=>array(array('value'=>17,'unit'=>'minutes')));
    $response = p0_ajax('wp_ajax_eipsi_save_wave_nudges_handler'); p0_assert($response->success, 'Nudges save failed');
    $event = p1b_event(); $config = json_decode($event['config'],true);
    p0_assert($config['nudge_1']['value'] == 17 && $config['nudge_1']['unit'] === 'minutes', 'Scheduler saw old/rebuilt config');
    $available = strtotime($db->get_var("SELECT available_at FROM {$db->prefix}survey_assignments WHERE participant_id=7"));
    p0_assert($event['time'] === $available + 17 * 60, 'Wrong scheduling units');
};
$tests['Nudges window enviada persiste y due_at cambia sin alterar available_at'] = function ($db) {
    p1b_ready($db); $available = $db->get_var("SELECT available_at FROM {$db->prefix}survey_assignments WHERE participant_id=7");
    $result = EIPSI_Nudge_Service::save_wave_configuration(21,p1b_config(),true,'90'); p0_assert(!is_wp_error($result),'Window save failed');
    $row=$db->get_row("SELECT available_at,due_at FROM {$db->prefix}survey_assignments WHERE participant_id=7");
    p0_assert($row->available_at === $available && strtotime($row->due_at) === strtotime($available)+5400,'Window inconsistent');
    p0_assert((int)EIPSI_Wave_Service::get_wave(21)->window_minutes === 90,'Window ignored');
};
$tests['Nudges window omitida preserva ventana y offsets personalizados'] = function ($db) {
    p1b_ready($db); $db->update($db->prefix.'survey_waves',array('window_minutes'=>120),array('id'=>21));
    $_POST=array('wave_id'=>21,'nonce'=>'valid:eipsi_study_dashboard_nonce','enabled'=>'true','window_minutes'=>'','nudges'=>array(array('value'=>23,'unit'=>'minutes')));
    p0_assert(p0_ajax('wp_ajax_eipsi_save_wave_nudges_handler')->success,'Save failed');
    $wave=EIPSI_Wave_Service::get_wave(21); $config=json_decode($wave->nudge_config,true);
    p0_assert((int)$wave->window_minutes===120 && $config['nudge_1']['value']==23,'Custom config overwritten');
};
foreach (array('minutes'=>60,'hours'=>3600,'days'=>86400) as $unit=>$factor) {
    $tests['Nudges unidad '.$unit.' conserva offset absoluto'] = function ($db) use($unit,$factor) {
        p1b_ready($db); $result=EIPSI_Nudge_Service::save_wave_configuration(21,p1b_config(1.5,$unit));
        p0_assert(!is_wp_error($result),'Unit rejected');
        $available=strtotime($db->get_var("SELECT available_at FROM {$db->prefix}survey_assignments WHERE participant_id=7"));
        p0_assert(p1b_event()['time']==$available+1.5*$factor,'Wrong conversion');
    };
}
$tests['Nudges persistencia fallida no reprograma ni confirma éxito'] = function ($db) {
    p1b_ready($db); $table=$db->prefix.'survey_waves';
    p0_assert($db->query("CREATE TRIGGER `{$db->prefix}reject_wave` BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rejection'")!==false,'Trigger failed');
    $result=EIPSI_Nudge_Service::save_wave_configuration(21,p1b_config());
    p0_assert(is_wp_error($result) && !$GLOBALS['p1b_cron'],'Failed write scheduled events');
};
$tests['Nudges fallo assignment revierte configuración y evita programación'] = function ($db) {
    p1b_ready($db); $table=$db->prefix.'survey_assignments';
    p0_assert($db->query("CREATE TRIGGER `{$db->prefix}reject_assignment` BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rejection'")!==false,'Trigger failed');
    $result=EIPSI_Nudge_Service::save_wave_configuration(21,p1b_config(),true,90);
    p0_assert(is_wp_error($result) && !$GLOBALS['p1b_cron'],'Failed deadline scheduled');
    p0_assert(EIPSI_Wave_Service::get_wave(21)->nudge_config === null,'Failed transaction not rolled back');
};
$tests['Nudges retry reemplaza eventos y cancela jobs follow-up sin nudge0'] = function ($db) {
    p1b_ready($db); $id=(int)$db->get_var("SELECT id FROM {$db->prefix}survey_assignments WHERE participant_id=7");
    EIPSI_Nudge_Job_Queue::enqueue('send_nudge_1',array('assignment_id'=>$id)); EIPSI_Nudge_Job_Queue::enqueue('send_nudge_0',array('assignment_id'=>$id));
    EIPSI_Nudge_Service::save_wave_configuration(21,p1b_config()); $first=$GLOBALS['p1b_cron'];
    EIPSI_Nudge_Service::save_wave_configuration(21,p1b_config());
    p0_assert($first===$GLOBALS['p1b_cron'] && count($first)===1,'Retry duplicated events');
    p0_assert($db->get_var("SELECT status FROM {$db->prefix}survey_nudge_jobs WHERE job_type='send_nudge_1'")==='cancelled','Old follow-up job active');
    p0_assert($db->get_var("SELECT status FROM {$db->prefix}survey_nudge_jobs WHERE job_type='send_nudge_0'")==='pending','Availability job touched');
};
$tests['Nudges apagar cancela eventos sin reenviar disponibilidad'] = function ($db) {
    p1b_ready($db); EIPSI_Nudge_Service::save_wave_configuration(21,p1b_config());
    p0_assert(!is_wp_error(EIPSI_Nudge_Service::save_wave_configuration(21,p1b_config(17,'minutes',false))),'Disable failed');
    p0_assert(!$GLOBALS['p1b_cron'] && !$GLOBALS['p0_mail'],'Disabled event/mail remained');
};
$tests['Nudges granular acepta nonce dashboard y persiste flag general'] = function ($db) {
    p1b_ready($db); $_POST=array('wave_id'=>21,'nonce'=>'valid:eipsi_study_dashboard_nonce','nudge_config'=>p1b_config(31));
    p0_assert(p0_ajax('eipsi_save_wave_nudge_config_handler')->success,'Actual UI nonce rejected');
    p0_assert(p1b_event()['config']===EIPSI_Wave_Service::get_wave(21)->nudge_config,'Granular scheduler stale');
};
$tests['Nudges Waves Manager preserva unidades y asunto'] = function ($db) {
    p1b_ready($db); $_POST=array('wave_id'=>21,'nonce'=>'valid:eipsi_waves_nonce','config'=>json_encode(array(1=>array('enabled'=>true,'hours'=>35,'unit'=>'minutes','subject'=>'Custom'))));
    p0_assert(p0_ajax('wp_ajax_eipsi_save_reminder_config_handler')->success,'Reminder UI failed');
    $config=json_decode(EIPSI_Wave_Service::get_wave(21)->nudge_config,true);
    p0_assert($config['nudge_1']['value']==35 && $config['nudge_1']['subject']==='Custom','Reminder custom values lost');
};
$tests['Nudges invalid window y unidad rechazan antes de guardar'] = function ($db) {
    p1b_ready($db);
    p0_assert(is_wp_error(EIPSI_Nudge_Service::save_wave_configuration(21,p1b_config(),true,-3)),'Invalid window allowed');
    p0_assert(is_wp_error(EIPSI_Nudge_Service::save_wave_configuration(21,p1b_config(17,'weeks'))),'Invalid unit allowed');
    p0_assert(!$GLOBALS['p1b_cron'] && EIPSI_Wave_Service::get_wave(21)->nudge_config===null,'Invalid input persisted');
};
$tests['Nudges fallo cron informa configuración guardada y permite retry'] = function ($db) {
    p1b_ready($db); $GLOBALS['p1b_schedule_failure']=true;
    $result=EIPSI_Nudge_Service::save_wave_configuration(21,p1b_config());
    p0_assert(is_wp_error($result) && EIPSI_Wave_Service::get_wave(21)->nudge_config!==null,'Scheduler failure hidden');
    $GLOBALS['p1b_schedule_failure']=false; p0_assert(!is_wp_error(EIPSI_Nudge_Service::save_wave_configuration(21,p1b_config())),'Retry failed');
};
$tests['Wave edición con fechas bloqueadas omitidas conserva valores y assignments'] = function ($db) {
    $GLOBALS['p0_admin']=true;
    $db->update($db->prefix.'survey_waves',array('start_date'=>'2026-10-20 12:00:17','due_date'=>'2026-10-21 12:00:17'),array('id'=>21));
    $_POST=p1b_wave_request(); unset($_POST['start_date'],$_POST['due_date']);
    p0_assert(p0_ajax('wp_ajax_eipsi_save_wave_handler')->success,'Locked omitted dates rejected');
    p0_assert(EIPSI_Wave_Service::get_wave(21)->start_date==='2026-10-20 12:00:17','Locked date cleared');
};
$tests['Nudges toggle de Waves Manager guarda antes de cancelar eventos'] = function ($db) {
    p1b_ready($db); EIPSI_Nudge_Service::save_wave_configuration(21,p1b_config());
    $_POST=array('wave_id'=>21,'nonce'=>'valid:eipsi_waves_nonce','enabled'=>'0');
    p0_assert(p0_ajax('eipsi_toggle_follow_up_reminders_handler')->success,'Toggle failed');
    p0_assert(!$GLOBALS['p1b_cron'] && (int)EIPSI_Wave_Service::get_wave(21)->follow_up_reminders_enabled===0,'Toggle left old events');
};
$tests['Nudges reprogramar deadline mantiene followups sin nudge0'] = function ($db) {
    p1b_ready($db); EIPSI_Nudge_Service::save_wave_configuration(21,p1b_config());
    $id=(int)$db->get_var("SELECT id FROM {$db->prefix}survey_assignments WHERE participant_id=7");
    $GLOBALS['p1b_cron']=array(); EIPSI_Nudge_Event_Scheduler::reschedule_nudges_for_deadline($id);
    p0_assert(count($GLOBALS['p1b_cron'])===1 && !$GLOBALS['p0_mail'],'Deadline replayed availability or lost followups');
};
$tests['Nudges reprogramar assignment usa configuración vigente sin nudge0'] = function ($db) {
    p1b_ready($db); EIPSI_Nudge_Service::save_wave_configuration(21,p1b_config());
    $id=(int)$db->get_var("SELECT id FROM {$db->prefix}survey_assignments WHERE participant_id=7");
    p0_assert(EIPSI_Nudge_Event_Scheduler::reschedule_nudges_for_assignment($id),'Reschedule failed');
    p0_assert(count($GLOBALS['p1b_cron'])===1 && !$GLOBALS['p0_mail'],'Reschedule duplicated/replayed');
};
$tests['Nudges ya enviados no vuelven a programarse'] = function ($db) {
    p1b_ready($db); $db->update($db->prefix.'survey_assignments',array('reminder_count'=>2),array('participant_id'=>7));
    $config=p1b_config(); $config['nudge_2']=array('enabled'=>true,'value'=>33,'unit'=>'minutes');
    EIPSI_Nudge_Service::save_wave_configuration(21,$config);
    p0_assert(count($GLOBALS['p1b_cron'])===1 && p1b_event(2)['args'][0]['stage']===2,'Sent stage rescheduled');
};
$tests['Nudges offsets fuera de deadline no se programan ni se sobrescriben'] = function ($db) {
    p1b_ready($db); $result=EIPSI_Nudge_Service::save_wave_configuration(21,p1b_config(120),true,90);
    p0_assert(!is_wp_error($result) && !$GLOBALS['p1b_cron'],'Event beyond due_at scheduled');
    p0_assert(json_decode(EIPSI_Wave_Service::get_wave(21)->nudge_config,true)['nudge_1']['value']==120,'Offset silently redistributed');
};
$tests['Nudges ventana no modifica assignments submitted ni sin anchor'] = function ($db) {
    p1b_ready($db); $db->update($db->prefix.'survey_assignments',array('status'=>'submitted','due_at'=>'2026-10-21 12:00:00'),array('participant_id'=>7));
    EIPSI_Nudge_Service::save_wave_configuration(21,p1b_config(),true,90);
    p0_assert($db->get_var("SELECT due_at FROM {$db->prefix}survey_assignments WHERE participant_id=7")==='2026-10-21 12:00:00','Submitted deadline changed');
    p0_assert($db->get_var("SELECT due_at FROM {$db->prefix}survey_assignments WHERE participant_id=8")===null,'Unanchored deadline invented');
};
$failed=0;
foreach($tests as $name=>$test) {
    if (getenv('EIPSI_TEST_FILTER') && strpos($name, getenv('EIPSI_TEST_FILTER')) === false) { continue; }
    $GLOBALS['p1b_cron']=array(); $GLOBALS['p1b_schedule_failure']=false;
    try { p1_fixture($test); echo "PASS $name\n"; } catch(Throwable $error) { $failed++; echo "FAIL $name: {$error->getMessage()}\n"; }
}
echo count($tests).' tests, '.$failed." failures\n";
ob_end_flush(); exit($failed?1:0);
