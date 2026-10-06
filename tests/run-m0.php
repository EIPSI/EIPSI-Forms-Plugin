<?php
require __DIR__ . '/m0/bootstrap.php';
$tests = array();
$tests['Instalación aislada y plugin activo'] = function () {
    m0_assert(is_plugin_active('EIPSI-Forms-Plugin/eipsi-forms.php'), 'Plugin inactive');
    m0_assert(did_action('init') && class_exists('EIPSI_Auth_Service'), 'Bootstrap incomplete');
};
$tests['Schema limpio: todas las tablas y columnas declaradas existen'] = function () {
    global $wpdb;
    foreach (EIPSI_Database_Schema_Manager::get_schema_map() as $slug => $definition) {
        $columns = $wpdb->get_col('SHOW COLUMNS FROM `' . $wpdb->prefix . $slug . '`');
        m0_assert(!array_diff(array_keys($definition['columns']), $columns), 'Missing columns: ' . $slug);
    }
};
$tests['Build Gutenberg: 13 bloques registrados y artefactos accesibles'] = function () {
    $files = glob(EIPSI_FORMS_PLUGIN_DIR . 'build/blocks/*/block.json');
    m0_assert(count($files) === 13, 'Expected 13 compiled block manifests');
    foreach ($files as $file) {
        $metadata = json_decode(file_get_contents($file), true);
        m0_assert(WP_Block_Type_Registry::get_instance()->is_registered($metadata['name']), 'Unregistered block: ' . $metadata['name']);
        foreach (array('editorScript','editorStyle','style') as $key) {
            foreach ((array) ($metadata[$key] ?? array()) as $value) {
                if (strpos($value, 'file:') === 0) { m0_assert(is_file(dirname($file) . '/' . substr($value,5)), 'Missing built asset: ' . $file . ' ' . $value); }
            }
        }
    }
};
$tests['Shortcodes: nueve callbacks válidos'] = function () {
    foreach (array('eipsi_form','eipsi_survey_login','eipsi_participant_dashboard','eipsi_longitudinal_study','eipsi_randomized_form','eipsi_randomization','eipsi_pool','eipsi_pool_join','eipsi_randomized_form_page') as $tag) {
        m0_assert(isset($GLOBALS['shortcode_tags'][$tag]) && is_callable($GLOBALS['shortcode_tags'][$tag]), 'Missing shortcode: ' . $tag);
    }
};
$tests['Actions activas: submit, debug, retiro, export wide, dashboard y schema'] = function () {
    foreach (array('eipsi_forms_submit_form','eipsi_debug_partial_response','eipsi_abandon_study','eipsi_export_participants_wide_excel','eipsi_export_participants_wide_csv','eipsi_get_study_overview','eipsi_repair_single_table') as $action) {
        m0_assert(has_action('wp_ajax_' . $action) !== false, 'Missing action: ' . $action);
    }
};
$missing = array(
    'eipsi_export_participants_long_excel'=>'admin/tabs/export-tab.php',
    'eipsi_export_participants_long_csv'=>'admin/tabs/export-tab.php',
    'eipsi_send_individual_reminder'=>'admin/templates/study-dashboard-modal.php',
    'eipsi_recalculate_preview'=>'admin/templates/study-dashboard-modal.php',
    'eipsi_recalculate_waves'=>'admin/templates/study-dashboard-modal.php',
    'eipsi_rollback_recalculation'=>'admin/views/study-settings/recalculation-panel.php',
    'eipsi_load_form'=>'assets/js/eipsi-randomization.js',
    'eipsi_create_from_clinical_template'=>'assets/js/form-library-tools.js',
    'eipsi_get_participant_dashboard'=>'assets/js/participant-dashboard.js',
);
foreach ($missing as $action=>$file) {
    $tests['Caracterización deuda conocida: ' . $action] = function () use ($action,$file) {
        m0_assert(strpos(file_get_contents(EIPSI_FORMS_PLUGIN_DIR.$file), $action) !== false, 'Emitter changed; update contract map');
        if ($action === 'eipsi_load_form') { m0_assert(has_action('wp_ajax_'.$action, 'eipsi_load_form_handler') === 10 && has_action('wp_ajax_nopriv_'.$action, 'eipsi_load_form_handler') === 10, 'M7 form loader absent'); }
        elseif (in_array($action,array('eipsi_send_individual_reminder','eipsi_recalculate_preview','eipsi_recalculate_waves','eipsi_get_participant_dashboard'),true)) { m0_assert(has_action('wp_ajax_'.$action,$action.'_handler')===10,'S3 repaired handler absent'); m0_assert(has_action('wp_ajax_nopriv_'.$action,$action.'_handler')===($action==='eipsi_get_participant_dashboard'?10:false),'S3 nopriv boundary'); }
        else {        m0_assert(has_action('wp_ajax_'.$action) === false && has_action('wp_ajax_nopriv_'.$action) === false, 'Handler restored; update characterization'); }
    };
}
$tests['Cron: trece eventos de activación con callbacks ejecutables'] = function () {
    $count = 0;
    foreach (_get_cron_array() as $events) { foreach ($events as $hook=>$instances) {
        if (strpos($hook,'eipsi_') !== 0) { continue; }
        foreach ($GLOBALS['wp_filter'][$hook]->callbacks ?? array() as $entries) { foreach ($entries as $entry) { m0_assert(is_callable($entry['function']), 'Cron callback invalid: '.$hook); } }
        m0_assert(has_action($hook) !== false, 'Scheduled hook without callback: '.$hook); $count += count($instances);
    } }
    m0_assert($count >= 13, 'Activation schedules missing');
};
$tests['Cron nudges: worker y evento registrados; programación contextual'] = function () {
    m0_assert(has_action('eipsi_process_nudge_jobs') !== false, 'Nudge worker absent');
    m0_assert(has_action(EIPSI_Nudge_Event_Scheduler::NUDGE_EVENT_HOOK) !== false, 'Event callback absent');
};
function m0_fixture($callback) {
    global $wpdb;
    $wpdb->query('START TRANSACTION'); $_COOKIE = array(); $GLOBALS['m0_mail'] = array();
    try {
        $now = current_time('mysql');
        foreach (array(990003,990004) as $id) { m0_assert($wpdb->insert($wpdb->prefix.'survey_studies',array('id'=>$id,'study_code'=>'m0-'.$id,'study_name'=>'M0 fixture','created_at'=>$now,'updated_at'=>$now,'config'=>wp_json_encode(array('shortcode_page_url'=>'http://example.invalid/m0')))) !== false, $wpdb->last_error); }
        foreach (array(990007=>990003,990008=>990003,990009=>990004) as $id=>$study) { m0_assert($wpdb->insert($wpdb->prefix.'survey_participants',array('id'=>$id,'survey_id'=>$study,'email'=>'m0-'.$id.'@example.invalid','first_name'=>'M0','password_hash'=>wp_hash_password('fixture'),'is_active'=>1,'consent_decision'=>'accepted','created_at'=>$now)) !== false, $wpdb->last_error); }
        m0_assert($wpdb->insert($wpdb->prefix.'eipsi_longitudinal_pools',array('id'=>990001,'pool_name'=>'M0 fixture','status'=>'active','config'=>wp_json_encode(array('studies'=>array(array('id'=>990003,'probability'=>100)),'method'=>'seeded')))) !== false,$wpdb->last_error);
        $callback();
    } finally { $wpdb->query('ROLLBACK'); $_COOKIE = array(); }
}
function m0_session() {
    $session = EIPSI_Auth_Service::create_session(990007,990003);
    m0_assert($session['success'], 'Session failed'); $_COOKIE[$session['cookie_name']] = $session['token'];
}
function m0_pool_request($params) {
    $request = new WP_REST_Request('POST','/eipsi/v1/pool-assign');
    $request->set_header('Content-Type','application/json'); $request->set_body(wp_json_encode($params)); return $request;
}
$tests['Pool REST: público rechazado por permiso y callback sin escrituras'] = function () {
    m0_fixture(function () { global $wpdb; $request = m0_pool_request(array('pool_id'=>990001,'participant_id'=>990008));
        $permission = eipsi_rest_pool_assign_permission($request);
        m0_assert(is_wp_error($permission) && $permission->get_error_data()['status']===401,'Public permission allowed');
        m0_assert(eipsi_rest_pool_assign($request)->get_status()===401,'Direct callback allowed');
        m0_assert(!$wpdb->get_var("SELECT id FROM {$wpdb->prefix}eipsi_pool_assignments WHERE pool_id=990001"),'Unauthorized write');
    });
};
$tests['Pool REST: sesión A no asigna B'] = function () {
    m0_fixture(function () { global $wpdb; m0_session(); $request=m0_pool_request(array('pool_id'=>990001,'participant_id'=>990008));
        m0_assert(is_wp_error(eipsi_rest_pool_assign_permission($request)),'Permission accepts B');
        m0_assert(eipsi_rest_pool_assign($request)->get_status()===403,'Callback accepts B');
        m0_assert(!$wpdb->get_var("SELECT id FROM {$wpdb->prefix}eipsi_pool_assignments WHERE pool_id=990001"),'Victim assigned');
    });
};
$tests['Pool REST: study_id ajeno rechazado'] = function () {
    m0_fixture(function () { m0_session(); m0_assert(eipsi_rest_pool_assign(m0_pool_request(array('pool_id'=>990001,'study_id'=>990004)))->get_status()===403,'Foreign study allowed'); });
};
$tests['Pool REST: identidad no escalar rechazada'] = function () {
    m0_fixture(function () { m0_session(); m0_assert(eipsi_rest_pool_assign(m0_pool_request(array('pool_id'=>990001,'participant_id'=>array(990007))))->get_status()===403,'Array identity allowed'); });
};
$tests['Pool REST: deriva identidad y conserva 201/200 y campos públicos'] = function () {
    m0_fixture(function () { global $wpdb; m0_session(); $request=m0_pool_request(array('pool_id'=>990001));
        $response=rest_do_request($request); $data=$response->get_data();
        m0_assert($response->get_status()===201 && $data['success'],'Valid assignment failed: '.wp_json_encode($data));
        m0_assert(!array_diff(array('assignment_id','study_id','study_url','is_existing','completed'),array_keys($data)),'Response contract changed');
        m0_assert((int)$wpdb->get_var("SELECT participant_id FROM {$wpdb->prefix}eipsi_pool_assignments WHERE pool_id=990001")===990007,'Session identity not persisted');
        m0_assert(rest_do_request($request)->get_status()===200,'Existing assignment changed');
    });
};
foreach (array('inactive'=>array('is_active'=>0),'withdrawn'=>array('consent_decision'=>'withdrawn'),'expired'=>array()) as $state=>$change) {
    $tests['Pool REST: rechaza sesión '.$state] = function () use ($state,$change) {
        m0_fixture(function () use ($state,$change) { global $wpdb; m0_session();
            if ($state==='expired') { $wpdb->update($wpdb->prefix.'survey_sessions',array('expires_at'=>gmdate('Y-m-d H:i:s',time()-3600)),array('participant_id'=>990007)); }
            else { $wpdb->update($wpdb->prefix.'survey_participants',$change,array('id'=>990007)); }
            m0_assert(eipsi_rest_pool_assign(m0_pool_request(array('pool_id'=>990001)))->get_status()===401,'Invalid session accepted');
        });
    };
}
$tests['Weekly T1: envío vigente conserva log y contador semanal'] = function () {
    m0_fixture(function () { global $wpdb;
        $wpdb->insert($wpdb->prefix.'survey_waves',array('id'=>990021,'study_id'=>990003,'wave_index'=>1,'name'=>'M0 T1','form_id'=>0,'status'=>'active'));
        $wpdb->insert($wpdb->prefix.'survey_assignments',array('id'=>990031,'study_id'=>990003,'wave_id'=>990021,'participant_id'=>990007,'status'=>'pending'));
        $assignment=(object)array('assignment_id'=>990031,'study_id'=>990003,'participant_id'=>990007,'wave_id'=>990021,'email'=>'m0-990007@example.invalid','first_name'=>'M0','study_name'=>'M0 fixture');
        m0_assert(eipsi_send_weekly_t1_reminder($assignment,1),'Weekly send failed');
        m0_assert(count($GLOBALS['m0_mail'])===1,'Email not sent once');
        m0_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}survey_weekly_reminders WHERE assignment_id=990031")===1,'Weekly log missing');
        m0_assert($wpdb->get_var("SELECT email_type FROM {$wpdb->prefix}survey_email_log WHERE participant_id=990007 LIMIT 1")==='weekly_t1_reminder','Email type lost');
    });
};
$tests['Weekly T1: fallo de correo no registra envío semanal'] = function () {
    m0_fixture(function () { global $wpdb; $failure=function(){return false;}; add_filter('pre_wp_mail',$failure,PHP_INT_MAX);
        try { $assignment=(object)array('assignment_id'=>990031,'study_id'=>990003,'participant_id'=>990007,'wave_id'=>990021,'email'=>'m0-990007@example.invalid','first_name'=>'M0','study_name'=>'M0 fixture');
            m0_assert(!eipsi_send_weekly_t1_reminder($assignment,1),'Failed mail reported success');
            m0_assert(!$wpdb->get_var("SELECT id FROM {$wpdb->prefix}survey_weekly_reminders WHERE assignment_id=990031"),'Failed mail counted');
        } finally { remove_filter('pre_wp_mail',$failure,PHP_INT_MAX); }
    });
};
$tests['Parciales: cron elimina vencidos y conserva recientes'] = function () {
    global $wpdb; $wpdb->query('START TRANSACTION');
    try { foreach (array('old'=>-40,'new'=>-1) as $session=>$days) { $wpdb->insert($wpdb->prefix.'eipsi_partial_responses',array('form_id'=>'m0-partial','participant_id'=>'m0','session_id'=>$session,'page_index'=>1,'responses_json'=>'{}','created_at'=>current_time('mysql'),'completed'=>0,'updated_at'=>gmdate('Y-m-d H:i:s',time()+$days*DAY_IN_SECONDS))); }
        do_action('eipsi_cleanup_partial_responses');
        m0_assert($wpdb->get_col("SELECT session_id FROM {$wpdb->prefix}eipsi_partial_responses WHERE form_id='m0-partial'")===array('new'),'Retention callback failed');
    } finally { $wpdb->query('ROLLBACK'); }
};
$tests['HTTP admin: página de schema carga sin fatal'] = function () {
    $response=m0_http('/wp-admin/admin.php?page=eipsi-configuration&tab=schema-status',null,true);
    m0_assert(wp_remote_retrieve_response_code($response)===200 && strpos(wp_remote_retrieve_body($response),'schema-status-tab')!==false,'Admin page failed');
};
$tests['HTTP repair: nonce y capability reales, respuesta compatible'] = function () {
    $body=array('action'=>'eipsi_repair_single_table','nonce'=>m0_admin_nonce('eipsi_admin_nonce'),'table_name'=>'survey_email_log');
    $response=m0_http('/wp-admin/admin-ajax.php',$body,true); $data=json_decode(wp_remote_retrieve_body($response),true);
    m0_assert(wp_remote_retrieve_response_code($response)===200 && !empty($data['success']) && !empty($data['data']['message']),'Repair response invalid: '.substr(wp_remote_retrieve_body($response),0,250));
};
$tests['HTTP repair: sin autorización y tabla ajena rechazados'] = function () {
    $body=array('action'=>'eipsi_repair_single_table','nonce'=>'bad','table_name'=>'survey_email_log');
    m0_assert(in_array(wp_remote_retrieve_response_code(m0_http('/wp-admin/admin-ajax.php',$body,false)), array(400,403), true),'Public repair allowed');
    $body['nonce']=m0_admin_nonce('eipsi_admin_nonce'); $body['table_name']='users';
    m0_assert(wp_remote_retrieve_response_code(m0_http('/wp-admin/admin-ajax.php',$body,true))===400,'Foreign table allowed');
};
$tests['HTTP público: debug parcial y REST pool denegados'] = function () {
    m0_assert(wp_remote_retrieve_response_code(m0_http('/wp-admin/admin-ajax.php',array('action'=>'eipsi_debug_partial_response')))===403,'Debug exposed');
    $response=wp_remote_post('http://127.0.0.1/?rest_route=/eipsi/v1/pool-assign',array('headers'=>array('Content-Type'=>'application/json'),'body'=>'{"pool_id":1,"participant_id":7}'));
    m0_assert(wp_remote_retrieve_response_code($response)===401,'HTTP REST public allowed');
};
$tests['Formulario básico: shortcode y página HTTP con assets'] = function () {
    $id=wp_insert_post(array('post_type'=>'eipsi_form_template','post_status'=>'publish','post_title'=>'M0 smoke','post_content'=>'<!-- wp:eipsi/form-container {"formName":"m0-smoke"} --><form class="eipsi-form"><input name="m0-answer"></form><!-- /wp:eipsi/form-container -->'));
    $page=wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'M0 smoke page','post_content'=>'[eipsi_form id="'.$id.'"]'));
    try { $html=do_shortcode('[eipsi_form id="'.$id.'"]'); m0_assert(strpos($html,'m0-answer')!==false,'Shortcode failed');
        $response=m0_http('/?page_id='.$page); $body=wp_remote_retrieve_body($response);
        m0_assert(wp_remote_retrieve_response_code($response)===200 && strpos($body,'m0-answer')!==false,'HTTP form failed (status '.wp_remote_retrieve_response_code($response).', location '.wp_remote_retrieve_header($response,'location').')');
        m0_assert(strpos($body,'assets/js/eipsi-forms.js')!==false,'Frontend JS missing');
    } finally { wp_delete_post($page,true); wp_delete_post($id,true); }
};
$tests['Assets principales: JS y CSS sirven 200 desde HTTP'] = function () {
    foreach (array('assets/js/eipsi-forms.js','assets/css/eipsi-forms.css','assets/css/theme-toggle.css','build/blocks/pool-block/index.js','build/blocks/pool-block/index.css') as $asset) {
        $response=m0_http('/wp-content/plugins/EIPSI-Forms-Plugin/'.$asset);
        m0_assert(wp_remote_retrieve_response_code($response)===200 && strlen(wp_remote_retrieve_body($response))>0, 'Asset not served: '.$asset);
    }
};
$tests['Schema de emergencia limpio acepta el INSERT real'] = function () {
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/data-safety-system.php';
    global $wpdb; $wpdb->query('START TRANSACTION');
    try { $result=eipsi_safety_emergency_save(array('form_id'=>'m0-emergency','participant_id'=>'m0','form_responses'=>'{}','metadata'=>'{}'),'M0 controlled emergency');
        m0_assert($result['success'] && $result['storage']==='emergency_table_wp', 'Fresh emergency persistence failed: '.($result['error'] ?? 'unknown'));
        m0_assert($wpdb->get_var($wpdb->prepare("SELECT raw_post_data FROM {$wpdb->prefix}eipsi_emergency_submissions WHERE id=%d",$result['emergency_id']))!==null,'Diagnostics missing');
    } finally { $wpdb->query('ROLLBACK'); }
};
$tests['Magic link generado sin columna token_plain valida y se consume'] = function () {
    m0_fixture(function () { global $wpdb; $token=EIPSI_MagicLinksService::generate_magic_link(990003,990007);
        m0_assert($token && EIPSI_MagicLinksService::validate_magic_link($token)['valid'],'Generated token invalid');
        m0_assert($wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}survey_magic_links LIKE 'token_plain'")===null,'Plain token column unexpectedly restored');
    });
};
$tests['Migración v8 no consulta pool_data en schema actual'] = function () {
    global $wpdb; $method=new ReflectionMethod('EIPSI_Migration_Runner','migrate_v8'); $method->setAccessible(true);
    $queries=array(); $capture=function($sql) use (&$queries) { $queries[]=$sql; return $sql; }; add_filter('query',$capture);
    try { $method->invoke(new EIPSI_Migration_Runner());
        m0_assert(!$wpdb->last_error,'Migration produced SQL error');
        foreach ($queries as $sql) { m0_assert(strpos($sql,'SELECT id, pool_data')===false,'Legacy query reached fresh schema'); }
    } finally { remove_filter('query',$capture); }
};
$failed=0;
foreach ($tests as $name=>$test) { try { $test(); echo 'PASS '.$name."\n"; } catch (Throwable $error) { $failed++; echo 'FAIL '.$name.': '.$error->getMessage()."\n"; } }
echo count($tests).' tests, '.$failed." failures\n"; ob_end_flush(); exit($failed?1:0);
