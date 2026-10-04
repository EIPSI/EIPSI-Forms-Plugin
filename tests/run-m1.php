<?php
require __DIR__ . '/m0/bootstrap.php';
require __DIR__ . '/m1/contracts.php';
$baseline = json_decode(file_get_contents(__DIR__ . '/m1/baseline.json'), true);
$tests = array();
$observed = array();
foreach (array('frontend','admin') as $profile) {
    $observed[$profile] = m1_inventory($profile);
    $canonical = m1_contracts($observed[$profile]);
    foreach (array('includes','hooks','hook_locations','shortcodes','rest','cron','assets','blocks') as $contract) {
        $tests["Inventario $profile: $contract equivalente al baseline"] = function () use ($baseline,$profile,$contract,$canonical) {
            m0_assert($canonical[$contract] == $baseline[$profile][$contract], 'Unexpected contract difference: '.$profile.' '.$contract);
        };
    }
}
$tests['Bootstrap: carga completa sin fatal'] = function () {
    m0_assert(class_exists('EIPSI_Bootstrap') && class_exists('EIPSI_Auth_Service') && did_action('init'), 'Bootstrap incomplete');
};
$tests['Includes: sin archivos repetidos y mismos puntos de inicialización'] = function () use ($observed) {
    foreach ($observed as $inventory) {
        $paths = array_column($inventory['included'], 'file');
        m0_assert(count($paths) === count(array_unique($paths)), 'Duplicate includes');
    }
    $files = array_column(EIPSI_Service_Loader::manifest(), 'file');
    m0_assert(count($files) === count(array_unique($files)), 'Duplicate loader entries');
    m0_assert(array_search('admin/manual-overrides-table.php',$files,true) < array_search('admin/randomization-db-setup.php',$files,true), 'RCT dependency order changed');
    m0_assert(array_search('includes/class-eipsi-migration-runner.php',$files,true) + 1 === array_search(':migration',$files,true), 'Migration boundary moved');
    m0_assert(isset($GLOBALS['eipsi_survey_access']) && $GLOBALS['eipsi_survey_access'] instanceof EIPSI_Survey_Access_Handler, 'Global access handler lost');
};
$tests['Compatibilidad: 44 implementaciones globales preservadas'] = function () use ($baseline) {
    foreach ($baseline['callback_token_hashes'] as $function=>$hash) {
        m0_assert(function_exists($function) && m1_token_hash($function) === $hash, 'Callback implementation changed: '.$function);
    }
};
$tests['Registros: bootstrap idempotente preserva closures y orden'] = function () {
    $before = $GLOBALS['wp_filter']['cron_schedules']->callbacks;
    $count = count($GLOBALS['wp_filter']['init']->callbacks[10]);
    EIPSI_Bootstrap::register();
    m0_assert($GLOBALS['wp_filter']['cron_schedules']->callbacks === $before, 'Cron callbacks duplicated');
    m0_assert(count($GLOBALS['wp_filter']['init']->callbacks[10]) === $count, 'Init callbacks duplicated');
};
$tests['Prioridades sensibles: schema, mail, acceso y wakeup'] = function () {
    m0_assert(has_filter('wp_mail_from','eipsi_mail_from') === 99, 'Mail priority');
    m0_assert(has_action('template_redirect','eipsi_handle_pool_access') === 1, 'Access priority');
    m0_assert(has_action('template_redirect','eipsi_handle_pool_email_confirmation') === 2, 'Confirmation priority');
    m0_assert(has_action('wp_loaded','eipsi_wake_up_job_processor') === 20, 'Wakeup priority');
    m0_assert(isset($GLOBALS['wp_filter']['plugins_loaded']->callbacks[5]), 'Schema verification priority');
};
$tests['Assets: localizations e inline data conservan objetos, nonces y contenido'] = function () {
    eipsi_forms_register_blocks(); eipsi_forms_enqueue_frontend_assets();
    $scripts=wp_scripts();
    foreach (array('eipsi-blocks-editor-data'=>'eipsiEditorData','eipsi-tracking-js'=>'eipsiTrackingConfig','eipsi-forms-js'=>'eipsiFormsConfig') as $handle=>$object) {
        m0_assert(strpos($scripts->get_data($handle,'data') ?: '', $object) !== false, 'Localization missing: '.$handle);
    }
    m0_assert(strpos(implode("\n", $scripts->get_data('eipsi-blocks-editor-data','after') ?: array()), 'eipsiAdminNonce') !== false, 'Legacy editor global lost');
    m0_assert(strpos($scripts->get_data('eipsi-blocks-editor-data','data'), wp_create_nonce('eipsi_admin_nonce')) !== false, 'Editor nonce contract changed');
};
$tests['Lifecycle: hooks apuntan al archivo principal y facades públicas'] = function () {
    $base=plugin_basename(EIPSI_FORMS_PLUGIN_FILE);
    m0_assert(has_action('activate_'.$base,'eipsi_forms_activate') === 10, 'Activation file identity');
    m0_assert(has_action('deactivate_'.$base,'eipsi_forms_deactivate') === 10, 'Deactivation file identity');
};
$tests['Schema: columnas presentes e inicialización de migraciones admin'] = function () {
    global $wpdb;
    foreach (EIPSI_Database_Schema_Manager::get_schema_map() as $slug=>$definition) {
        $columns=$wpdb->get_col('SHOW COLUMNS FROM `'.$wpdb->prefix.$slug.'`');
        m0_assert(!array_diff(array_keys($definition['columns']),$columns),'Missing schema: '.$slug);
    }
    m0_assert((int)get_option(EIPSI_Migration_Runner::VERSION_OPTION) === EIPSI_Migration_Runner::LATEST_VERSION, 'Admin migration bootstrap not invoked');
};
$tests['Lifecycle: deactivation limpia 19 owners, argumentos y singles; preserva datos y cron ajeno'] = function () {
    global $wpdb;
    $plugin=plugin_basename(EIPSI_FORMS_PLUGIN_FILE);
    $count_before=$wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->prefix.'survey_participants');
    $migration=get_option(EIPSI_Migration_Runner::VERSION_OPTION);
    $fixture_study=false; $fixture_participant=false;
    try {
        m0_assert(!$wpdb->get_var('SELECT id FROM '.$wpdb->prefix.'survey_studies WHERE id=991103'), 'Study fixture collision');
        m0_assert(!$wpdb->get_var('SELECT id FROM '.$wpdb->prefix.'survey_participants WHERE id=991107'), 'Participant fixture collision');
        $now=current_time('mysql');
        m0_assert($wpdb->insert($wpdb->prefix.'survey_studies',array('id'=>991103,'study_code'=>'m1-lifecycle','study_name'=>'M1 lifecycle fixture','created_at'=>$now,'updated_at'=>$now,'config'=>'{}')) !== false, $wpdb->last_error);
        $fixture_study=true;
        m0_assert($wpdb->insert($wpdb->prefix.'survey_participants',array('id'=>991107,'survey_id'=>991103,'email'=>'m1-lifecycle@example.invalid','first_name'=>'M1','is_active'=>1,'consent_decision'=>'accepted','created_at'=>$now)) !== false, $wpdb->last_error);
        $fixture_participant=true;
        $count_before=$wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->prefix.'survey_participants');
        foreach (array_keys(EIPSI_Cron_Registry::owners()) as $hook) {
            wp_schedule_single_event(time()+600,$hook,array('m1-fixture-a'));
            wp_schedule_single_event(time()+1200,$hook,array('m1-fixture-b'));
            m0_assert(wp_next_scheduled($hook,array('m1-fixture-a')) !== false, 'Fixture not scheduled: '.$hook);
        }
        wp_schedule_single_event(time()+600,'m1_foreign_cron',array('foreign'));
        wp_schedule_single_event(time()+600,'eipsi_unowned_fixture',array('foreign'));
        deactivate_plugins($plugin);
        m0_assert(!is_plugin_active($plugin),'Plugin still active after deactivate');
        foreach (_get_cron_array() as $events) {
            foreach ($events as $hook=>$instances) { m0_assert(!isset(EIPSI_Cron_Registry::owners()[$hook]),'Owned cron left: '.$hook); }
        }
        m0_assert(wp_next_scheduled('m1_foreign_cron',array('foreign')) !== false,'Foreign event removed');
        m0_assert(wp_next_scheduled('eipsi_unowned_fixture',array('foreign')) !== false,'Unknown prefix event removed');
        m0_assert($wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->prefix.'survey_participants') === $count_before,'Participant data changed');
        m0_assert((int)$wpdb->get_var('SELECT survey_id FROM '.$wpdb->prefix.'survey_participants WHERE id=991107') === 991103, 'Persisted fixture lost');
        m0_assert(get_option(EIPSI_Migration_Runner::VERSION_OPTION) === $migration,'Migration state deleted');
    } finally {
        wp_unschedule_hook('m1_foreign_cron');wp_unschedule_hook('eipsi_unowned_fixture');
        $result=activate_plugin($plugin);
        if ($fixture_participant) { $wpdb->delete($wpdb->prefix.'survey_participants',array('id'=>991107)); }
        if ($fixture_study) { $wpdb->delete($wpdb->prefix.'survey_studies',array('id'=>991103)); }
        m0_assert(!is_wp_error($result),'Reactivation failed');
    }
};
$tests['Lifecycle: activación programa trece eventos con frecuencias originales'] = function () {
    $expected=array('eipsi_send_take_reminders_daily'=>'daily','eipsi_send_take_reminders_weekly'=>'weekly','eipsi_send_wave_reminders_hourly'=>'every_minute','eipsi_send_dropout_recovery_hourly'=>'every_minute','eipsi_purge_access_logs_daily'=>'daily','eipsi_cleanup_unconfirmed_participants_daily'=>'daily','eipsi_cleanup_pool_email_logs_monthly'=>'eipsi_monthly','eipsi_cleanup_partial_responses'=>'daily','eipsi_process_assignment_expirations'=>'every_minute','eipsi_process_wave_availability'=>'every_minute','eipsi_hourly_wave_expiration_check'=>'hourly','eipsi_wave_skipping_cron'=>'hourly','eipsi_weekly_t1_reminders_cron'=>'daily');
    foreach ($expected as $hook=>$schedule) { $event=wp_get_scheduled_event($hook);m0_assert($event && $event->schedule === $schedule,'Activation schedule changed: '.$hook); }
};
$tests['Lifecycle: reactivación repetida no duplica cron'] = function () {
    $before=_get_cron_array(); eipsi_forms_activate(); eipsi_forms_activate();
    m0_assert(_get_cron_array() === $before,'Repeated activation duplicated or changed cron');
};
$tests['Smoke después de reactivar: admin, REST público y assets responden'] = function () {
    foreach (array('/wp-admin/admin.php?page=eipsi-results-experience','/wp-json/eipsi/v1/pool-detect','/wp-content/plugins/EIPSI-Forms-Plugin/assets/js/eipsi-forms.js') as $path) {
        $result=m0_http($path,null,strpos($path,'/wp-admin/')===0);
        m0_assert(!is_wp_error($result),'HTTP failure: '.$path);
        $status=wp_remote_retrieve_response_code($result);
        m0_assert($status>=200 && $status<500,'HTTP server failure: '.$path.' '.$status);
        if (strpos($path,'assets/') !== false || strpos($path,'/wp-admin/')===0) { m0_assert($status===200,'Smoke not 200: '.$path); }
    }
};
$tests['WP_DEBUG: helpers solo en perfil debug y bloques equivalentes'] = function () {
    m0_assert(WP_DEBUG && in_array(realpath(EIPSI_FORMS_PLUGIN_DIR.'admin/testing-helpers.php'),get_included_files(),true),'Debug helper path changed');
    m0_assert(count(array_filter(array_keys(WP_Block_Type_Registry::get_instance()->get_all_registered()),function($name){return strpos($name,'eipsi/')===0;}))===13,'Block count changed');
};
$failed=0;
foreach ($tests as $name=>$test) { try {$test();echo 'PASS '.$name."\n";} catch(Throwable $error) {$failed++;echo 'FAIL '.$name.': '.$error->getMessage()."\n";} }
echo count($tests).' tests, '.$failed." failures\n";ob_end_flush();exit($failed?1:0);
