<?php
/** Six inexpensive integration checks, only on the guarded disposable M0 install. */
define('DOING_CRON', true);
require __DIR__ . '/../m0/bootstrap.php';

$form = 0;
$page = 0;
$failed = 0;
$cron = get_option('cron');
$key = 't0-' . bin2hex(random_bytes(8)); // form_id is VARCHAR(20) in the canonical schema.
$content = '<!-- wp:eipsi/form-container {"formName":"t0-smoke"} --><form class="eipsi-form"><input name="t0-answer"></form><!-- /wp:eipsi/form-container -->';
$tests = array();

$tests['Plugin and canonical schema/version load'] = function () {
    m0_assert(is_plugin_active('EIPSI-Forms-Plugin/eipsi-forms.php'), 'Plugin inactive');
    m0_assert(EIPSI_Migration_Runner::LATEST_VERSION === 10 && (int) get_option('eipsi_migration_version') === 10, 'Migration version differs');
    m0_assert(get_option('eipsi_db_schema_version') === EIPSI_FORMS_VERSION, 'Schema version differs');
    $map = EIPSI_Schema_Registry::get_schema_map();
    m0_assert(count($map) === 26, 'Expected 26 schema domains');
    foreach (array_keys($map) as $slug) {
        $result = EIPSI_Schema_Inspector::inspect($slug);
        m0_assert(empty($result['issues']), 'Schema issues: ' . $slug);
    }
};
$tests['WordPress public and principal admin page return HTTP 200'] = function () {
    foreach (array(array('/', false), array('/wp-admin/admin.php?page=eipsi-configuration&tab=schema-status', true)) as $request) {
        $response = m0_http($request[0], null, $request[1]);
        m0_assert(!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200, 'HTTP page failed');
        m0_assert(strpos(wp_remote_retrieve_body($response), 'There has been a critical error') === false, 'WordPress fatal');
    }
};
$tests['One valid Forms shortcode and block render'] = function () use (&$form, $content) {
    m0_assert(strpos(do_shortcode('[eipsi_form id="' . $form . '"]'), 't0-answer') !== false, 'Shortcode did not render input');
    m0_assert(WP_Block_Type_Registry::get_instance()->is_registered('eipsi/form-container'), 'Block missing');
    m0_assert(strpos(do_blocks($content), 't0-answer') !== false, 'Block did not render input');
};
$tests['Forms page and generated frontend asset load over HTTP'] = function () use (&$page) {
    $response = m0_http('/?page_id=' . $page);
    $body = wp_remote_retrieve_body($response);
    m0_assert(!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200 && strpos($body, 't0-answer') !== false, 'Forms HTTP page failed');
    m0_assert(strpos($body, 'assets/js/eipsi-forms.js') !== false, 'Runtime not enqueued');
    $asset = m0_http('/wp-content/plugins/EIPSI-Forms-Plugin/assets/js/eipsi-forms.js');
    m0_assert(!is_wp_error($asset) && wp_remote_retrieve_response_code($asset) === 200 && strlen(wp_remote_retrieve_body($asset)) > 0, 'Runtime HTTP asset failed');
};
$tests['Anonymous Forms submission confirms actual local persistence'] = function () use ($key) {
    global $wpdb;
    // The preceding admin HTTP probe populated CLI cookies; guest nonce must use no session token.
    $cookies = $_COOKIE;
    $_COOKIE = array();
    wp_set_current_user(0);
    $nonce = wp_create_nonce('eipsi_forms_nonce');
    wp_set_current_user(1);
    $_COOKIE = $cookies;
    $response = m0_http('/wp-admin/admin-ajax.php', array(
        'action' => 'eipsi_forms_submit_form', 'nonce' => $nonce,
        'form_id' => $key, 'participant_id' => $key, 'session_id' => $key, 'answer' => '4',
    ));
    $json = json_decode(wp_remote_retrieve_body($response), true);
    m0_assert(!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200 && !empty($json['success']), 'Anonymous submit failed: HTTP ' . wp_remote_retrieve_response_code($response) . ' ' . wp_remote_retrieve_body($response));
    m0_assert(($json['data']['storage_type'] ?? '') === 'wordpress_db' && empty($json['data']['emergency_mode']), 'Unexpected storage fallback');
    m0_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}vas_form_results WHERE participant_id=%s", $key)) === 1, 'Submitted response not persisted exactly once in this request');
};
$tests['Critical RCT owner resolves and resets its own anonymous assignment'] = function () use (&$form, $key) {
    $config = array('formularios' => array(array('id' => $form)), 'probabilidades' => array(100), 'method' => 'seeded', 'persistent_mode' => true);
    $assignment = EIPSI_Randomization_Assignment_Service::resolve($key, $config, $key);
    m0_assert(!is_wp_error($assignment) && !empty($assignment['reset_capability']), 'RCT assignment creation failed');
    $_POST['reset_capability'] = $assignment['reset_capability'];
    m0_assert(EIPSI_Randomization_Assignment_Service::eipsi_close_randomization_session($key, $key), 'Own RCT reset failed');
    m0_assert(!EIPSI_Randomization_Assignment_Service::eipsi_get_existing_assignment($key, $key), 'Reset left assignment');
    $_POST = array();
};

try {
    $form = wp_insert_post(array('post_type' => 'eipsi_form_template', 'post_status' => 'publish', 'post_title' => $key, 'post_content' => $content));
    m0_assert($form > 0, 'Form fixture failed');
    $page = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => $key, 'post_content' => '[eipsi_form id="' . $form . '"]'));
    m0_assert($page > 0, 'Page fixture failed');
    foreach ($tests as $name => $test) {
        try {
            $test();
            echo 'PASS ' . $name . "\n";
        } catch (Throwable $error) {
            $failed++;
            echo 'FAIL ' . $name . ': ' . $error->getMessage() . "\n";
        }
    }
} catch (Throwable $error) {
    $failed++;
    echo 'FAIL smoke fixture: ' . $error->getMessage() . "\n";
} finally {
    $wpdb->delete($wpdb->prefix . 'vas_form_results', array('participant_id' => $key));
    $wpdb->delete($wpdb->prefix . 'eipsi_randomization_assignments', array('randomization_id' => $key));
    if ($page) { wp_delete_post($page, true); }
    if ($form) { wp_delete_post($form, true); }
    update_option('cron', $cron);
    $_POST = array();
}
echo count($tests) . ' tests, ' . $failed . " failures\n";
ob_end_flush();
exit($failed ? 1 : 0);
