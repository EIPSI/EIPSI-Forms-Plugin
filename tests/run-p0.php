<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Minimal dependency-free regression runner. Run: php tests/run-p0.php [--integration] */
require __DIR__ . '/bootstrap.php';
$tests = array();
$submission = array('form_id' => 'form-p0', 'participant_id' => '7', 'form_responses' => '{"answer":4}');
$debug_request = array('form_id' => 'f', 'participant_id' => '7', 'session_id' => 's', 'nonce' => 'valid:eipsi_admin_nonce');
$withdraw_request = array('participant_id' => '7', 'study_id' => '3', 'nonce' => 'valid:eipsi_abandon_study', 'withdrawal_type' => 'b1');
$tests['Emergency INSERT exitoso preserva respuestas y destino'] = function () use ($submission) {
    $result = eipsi_safety_emergency_save($submission, 'original failure');
    p0_assert($result['success'] && $result['emergency_id'] === 42 && $result['storage'] === 'emergency_table_wp', 'Expected confirmed local storage');
    p0_assert($GLOBALS['wpdb']->writes[1]['data']['form_responses'] === $submission['form_responses'], 'Responses lost');
};
$tests['Emergency INSERT fallido rechaza éxito e ID previo'] = function () use ($submission) {
    $GLOBALS['wpdb']->insert_result = false;
    $result = eipsi_safety_emergency_save($submission, 'original failure');
    p0_assert(!$result['success'] && $result['emergency_id'] === null && $result['storage'] === null, 'False success or stale insert ID');
    p0_assert(strpos($result['error'], 'INSERT denied') !== false && strpos($result['error'], 'original failure') !== false, 'Missing diagnostics');
    p0_assert(strpos($GLOBALS['p0_mail'][0]['subject'], 'NO guardada') !== false, 'Misleading failure alert');
};
$tests['Emergency CREATE fallido no intenta INSERT'] = function () use ($submission) {
    $GLOBALS['wpdb']->create_result = false;
    $result = eipsi_safety_emergency_save($submission, 'original failure');
    p0_assert(!$result['success'] && count($GLOBALS['wpdb']->writes) === 1, 'CREATE failure must fail closed');
};
$tests['Partial debug no autorizado no lee respuestas'] = function () use ($debug_request) {
    $_POST = $debug_request;
    $response = p0_ajax('eipsi_debug_partial_response_handler');
    p0_assert(!$response->success && $response->status === 403 && $GLOBALS['wpdb']->loads === 0, 'Unauthorized read');
};
$tests['Partial debug administrador con nonce accede'] = function () use ($debug_request) {
    $GLOBALS['p0_admin'] = true; $_POST = $debug_request;
    $response = p0_ajax('eipsi_debug_partial_response_handler');
    p0_assert($response->success && $response->data['raw_responses']['phq_score'] === 4, 'Authorized diagnostic contract broken');
};
$tests['Partial debug administrador sin nonce no lee'] = function () use ($debug_request) {
    $GLOBALS['p0_admin'] = true; $_POST = $debug_request; unset($_POST['nonce']);
    $response = p0_ajax('eipsi_debug_partial_response_handler');
    p0_assert(!$response->success && $GLOBALS['wpdb']->loads === 0, 'Missing nonce accepted');
};
$tests['Partial debug nonce inválido no lee'] = function () use ($debug_request) {
    $GLOBALS['p0_admin'] = true; $_POST = $debug_request; $_POST['nonce'] = 'invalid';
    $response = p0_ajax('eipsi_debug_partial_response_handler');
    p0_assert(!$response->success && $GLOBALS['wpdb']->loads === 0, 'Invalid nonce accepted');
};
$tests['Retiro de otro participante bloqueado antes de escribir'] = function () use ($withdraw_request) {
    $_POST = $withdraw_request; $_POST['participant_id'] = '8'; $_POST['withdrawal_type'] = 'b2';
    $_POST['verification_text'] = 'QUIERO QUE ELIMINEN MIS DATOS';
    $response = p0_ajax('eipsi_abandon_study_handler');
    p0_assert(!$response->success && $response->status === 403 && !$GLOBALS['wpdb']->writes, 'Foreign participant modified');
    p0_assert(!EIPSI_Auth_Service::$destroyed, 'Attacker session unexpectedly destroyed');
};
$tests['Retiro propio conserva transición y redirect'] = function () use ($withdraw_request) {
    $_POST = $withdraw_request;
    $response = p0_ajax('eipsi_abandon_study_handler');
    p0_assert($response->success && $response->data['withdrawal_type'] === 'b1', 'Valid withdrawal rejected');
    p0_assert($GLOBALS['wpdb']->records[7]['consent_decision'] === 'withdrawn' && $GLOBALS['wpdb']->records[7]['is_active'] === 0, 'Withdrawal not persisted');
    p0_assert($GLOBALS['wpdb']->records[8]['is_active'] === 1 && EIPSI_Auth_Service::$destroyed, 'Wrong participant or missing logout');
    p0_assert(strpos($response->data['redirect_url'], 'withdrawal=success') !== false, 'Redirect contract broken');
};
$tests['Retiro sin sesión no escribe'] = function () use ($withdraw_request) {
    EIPSI_Auth_Service::$participant = 0; $_POST = $withdraw_request;
    $response = p0_ajax('eipsi_abandon_study_handler');
    p0_assert(!$response->success && !$GLOBALS['wpdb']->writes, 'Anonymous withdrawal accepted');
};
$tests['Retiro de otro estudio no escribe'] = function () use ($withdraw_request) {
    $_POST = $withdraw_request; $_POST['study_id'] = '4';
    $response = p0_ajax('eipsi_abandon_study_handler');
    p0_assert(!$response->success && !$GLOBALS['wpdb']->writes, 'Foreign study accepted');
};
$tests['Retiro comprueba pertenencia real a estudio'] = function () use ($withdraw_request) {
    $GLOBALS['wpdb']->member = false; $_POST = $withdraw_request;
    $response = p0_ajax('eipsi_abandon_study_handler');
    p0_assert(!$response->success && !$GLOBALS['wpdb']->writes, 'Session study mismatch accepted');
};
$tests['Retiro con nonce inválido no escribe'] = function () use ($withdraw_request) {
    $_POST = $withdraw_request; $_POST['nonce'] = 'invalid';
    $response = p0_ajax('eipsi_abandon_study_handler');
    p0_assert(!$response->success && !$GLOBALS['wpdb']->writes, 'Invalid withdrawal nonce accepted');
};
$tests['Retiro B2 propio conserva confirmación y logout'] = function () use ($withdraw_request) {
    $_POST = $withdraw_request; $_POST['withdrawal_type'] = 'b2';
    $_POST['verification_text'] = 'QUIERO QUE ELIMINEN MIS DATOS';
    $response = p0_ajax('eipsi_abandon_study_handler');
    p0_assert($response->success && $response->data['withdrawal_type'] === 'b2' && EIPSI_Auth_Service::$destroyed, 'Valid B2 withdrawal broken');
};
$tests['Retiro deriva identidad aunque payload omita IDs'] = function () use ($withdraw_request) {
    $_POST = $withdraw_request; unset($_POST['participant_id'], $_POST['study_id']);
    p0_assert(p0_ajax('eipsi_abandon_study_handler')->success, 'Session identity not used');
};
if (in_array('--integration', $argv, true)) {
    require __DIR__ . '/integration-p0.php';
}
$failed = 0;
foreach ($tests as $name => $test) {
    p0_reset();
    try { $test(); echo "PASS $name\n"; } catch (Throwable $error) { $failed++; echo "FAIL $name: {$error->getMessage()}\n"; }
}
echo count($tests) . ' tests, ' . $failed . " failures\n";
exit($failed ? 1 : 0);
