<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Auth services, real wpdb and disposable schema from the actual schema manager. */
require __DIR__ . '/bootstrap-p1.php';
$tests = array();
$tests['Activo consentido: contraseña permitida, email-only requiere prueba'] = function ($db) {
    p0_assert(EIPSI_Auth_Service::authenticate(3, 'p7@example.invalid', 'test-password')['success'], 'Password login rejected');
    p0_assert(!EIPSI_Auth_Service::authenticate_passwordless(3, 'p7@example.invalid')['success'], 'Email-only authenticated');
};
foreach (array('inactive' => array('is_active' => 0), 'declined' => array('consent_decision' => 'declined'), 'withdrawn' => array('consent_decision' => 'withdrawn')) as $state => $change) {
    $tests['Login y passwordless rechazan ' . $state] = function ($db) use ($change) {
        $db->update($db->prefix . 'survey_participants', $change, array('id' => 7));
        p0_assert(!EIPSI_Auth_Service::authenticate(3, 'p7@example.invalid', 'test-password')['success'], 'Password login allowed');
        p0_assert(!EIPSI_Auth_Service::authenticate_passwordless(3, 'p7@example.invalid')['success'], 'Passwordless allowed');
    };
    $tests['Magic link y creación de sesión rechazan ' . $state] = function ($db) use ($change) {
        $token = p1_magic_link();
        $db->update($db->prefix . 'survey_participants', $change, array('id' => 7));
        p0_assert(!EIPSI_MagicLinksService::validate_magic_link($token)['valid'], 'Magic link allowed');
        p0_assert(!EIPSI_Auth_Service::create_session(7, 3)['success'], 'Blocked participant session created');
        p0_assert((int) $db->get_var("SELECT COUNT(*) FROM {$db->prefix}survey_sessions") === 0, 'Unexpected session write');
    };
    $tests['Sesión anterior deja de autorizar ' . $state] = function ($db) use ($change) {
        p1_session();
        $db->update($db->prefix . 'survey_participants', $change, array('id' => 7));
        p0_assert(EIPSI_Auth_Service::get_current_participant() === null && EIPSI_Auth_Service::get_current_survey() === null, 'Old session still valid');
        p0_assert(!EIPSI_Auth_Service::is_authenticated(), 'Invalid state authenticated');
        p0_assert((int) $db->get_var("SELECT COUNT(*) FROM {$db->prefix}survey_sessions") === 0, 'Invalid session not revoked');
    };
}
$tests['Consentimiento pendiente permite entrar y decidir'] = function ($db) {
    $db->update($db->prefix . 'survey_participants', array('consent_decision' => null), array('id' => 7));
    p0_assert(EIPSI_Auth_Service::authenticate(3, 'p7@example.invalid', 'test-password')['success'], 'Pending consent cannot log in with a verified password');
    p1_session();
    $_POST = array('form_id' => 'long-form', 'participant_id' => '7', 'decision' => 'accepted', 'nonce' => 'valid:eipsi_forms_nonce');
    p0_assert(p0_ajax('eipsi_save_consent_decision_handler')->success, 'Pending participant cannot accept');
    p0_assert($db->get_var("SELECT consent_decision FROM {$db->prefix}survey_participants WHERE id = 7") === 'accepted', 'Consent not persisted');
};
$tests['Contraseña incorrecta sigue rechazada'] = function ($db) {
    p0_assert(!EIPSI_Auth_Service::authenticate(3, 'p7@example.invalid', 'wrong-password')['success'], 'Wrong password accepted');
};
$tests['Magic link y sesión rechazan participante de otro estudio'] = function ($db) {
    $token = p1_magic_link(7, 4);
    p0_assert(!EIPSI_MagicLinksService::validate_magic_link($token)['valid'], 'Wrong study token accepted');
    p0_assert(!EIPSI_Auth_Service::create_session(7, 4)['success'], 'Wrong study session created');
};
$tests['Magic link activo conserva vencimiento y single-use'] = function ($db) {
    $token = p1_magic_link();
    $result = EIPSI_MagicLinksService::validate_magic_link($token);
    p0_assert($result['valid'], 'Valid magic link rejected');
    p0_assert(EIPSI_Auth_Service::create_session($result['participant_id'], $result['survey_id'])['success'], 'Magic session denied');
    EIPSI_MagicLinksService::mark_magic_link_used($result['ml_id']);
    p0_assert(EIPSI_MagicLinksService::validate_magic_link($token)['reason'] === 'already_used', 'Token reused');
    p0_assert(EIPSI_MagicLinksService::validate_magic_link(p1_magic_link(7, 3, false, true))['reason'] === 'expired', 'Expired token allowed');
};
$tests['Sesión válida revalida cambio de pertenencia al estudio'] = function ($db) {
    p1_session();
    $db->update($db->prefix . 'survey_participants', array('survey_id' => 4), array('id' => 7));
    p0_assert(EIPSI_Auth_Service::get_current_participant() === null, 'Session survives membership change');
};
$tests['Sesión A no puede operar como B'] = function ($db) {
    p1_session();
    p0_assert(!EIPSI_Auth_Service::authorize_session_context(array('participant_id' => '8'))['success'], 'Foreign identity accepted');
    p0_assert(EIPSI_Auth_Service::get_current_participant() === 7, 'Attacker context changed valid session');
};
$tests['Consentimiento con participante ajeno rechazado sin cambio'] = function ($db) {
    p1_session();
    $_POST = array('form_id' => 'long-form', 'participant_id' => '8', 'decision' => 'declined', 'nonce' => 'valid:eipsi_forms_nonce');
    $response = p0_ajax('eipsi_save_consent_decision_handler');
    p0_assert(!$response->success && $response->status === 403, 'Foreign consent accepted');
    p0_assert($db->get_var("SELECT consent_decision FROM {$db->prefix}survey_participants WHERE id = 8") === 'accepted', 'Victim modified');
};
$tests['Consentimiento study_id ajeno rechazado'] = function ($db) {
    p1_session();
    $_POST = array('form_id' => 'long-form', 'participant_id' => '7', 'study_id' => '4', 'decision' => 'declined', 'nonce' => 'valid:eipsi_forms_nonce');
    p0_assert(!p0_ajax('eipsi_save_consent_decision_handler')->success, 'Foreign study consent accepted');
    p0_assert($db->get_var("SELECT consent_decision FROM {$db->prefix}survey_participants WHERE id = 7") === 'accepted', 'Own consent modified by invalid context');
};
$tests['Consentimiento longitudinal sin sesión rechazado'] = function ($db) {
    $_POST = array('form_id' => 'long-form', 'participant_id' => '8', 'decision' => 'accepted', 'nonce' => 'valid:eipsi_forms_nonce');
    p0_assert(!p0_ajax('eipsi_save_consent_decision_handler')->success, 'Unauthenticated consent accepted');
};
$tests['Rechazo propio conserva decisión, redirect y revoca sesión'] = function ($db) {
    p1_session();
    $_POST = array('form_id' => 'long-form', 'decision' => 'declined', 'nonce' => 'valid:eipsi_forms_nonce');
    $response = p0_ajax('eipsi_save_consent_decision_handler');
    p0_assert($response->success && strpos($response->data['redirect'], 'consent=declined') !== false, 'Decline contract broken');
    p0_assert($db->get_var("SELECT consent_decision FROM {$db->prefix}survey_participants WHERE id = 7") === 'declined', 'Decline not saved');
    p0_assert(EIPSI_Auth_Service::get_current_participant() === null, 'Declined session still valid');
};
$tests['Submit longitudinal sin sesión no atribuye mediante email'] = function ($db) {
    $_POST = p1_submit_request(); $_POST['survey_id'] = '3'; $_POST['wave_id'] = '21';
    $response = p0_ajax('eipsi_forms_submit_form_handler');
    p0_assert(!$response->success && $response->status === 403, 'Email impersonation allowed');
    p1_assert_no_responses($db);
};
$tests['Submit reconoce formulario longitudinal aunque se omita contexto'] = function ($db) {
    $_POST = p1_submit_request();
    p0_assert(!p0_ajax('eipsi_forms_submit_form_handler')->success, 'Longitudinal downgraded to anonymous');
    p1_assert_no_responses($db);
};
$tests['Submit con sesión A y email B persiste identidad A y su assignment'] = function ($db) {
    p1_session(); $_POST = p1_submit_request(); $_POST['wave_id'] = '21';
    $_POST['metadata'] = '{"participant_id":8,"survey_id":4,"longitudinal_participant_id":8}';
    $response = p0_ajax('eipsi_forms_submit_form_handler');
    p0_assert($response->success && empty($response->data['emergency_mode']), 'Valid normal submit failed');
    $row = $db->get_row("SELECT * FROM {$db->prefix}vas_form_results LIMIT 1");
    p0_assert($row && $row->participant_id === '7' && (int) $row->survey_id === 3, 'Response attributed to submitted email/metadata');
    $metadata = json_decode($row->metadata, true);
    p0_assert($metadata['participant_id'] === '7' && $metadata['longitudinal_participant_id'] === 7 && $metadata['survey_id'] === 3, 'Metadata impersonation');
    p0_assert($db->get_var("SELECT status FROM {$db->prefix}survey_assignments WHERE participant_id = 7") === 'submitted', 'Own assignment not submitted');
    p0_assert($db->get_var("SELECT status FROM {$db->prefix}survey_assignments WHERE participant_id = 8") === 'pending', 'Other assignment changed');
    $b = $db->get_row("SELECT * FROM {$db->prefix}survey_participants WHERE id = 8", ARRAY_A);
    p0_assert(empty($b['T1_email']), 'Other participant synced from email');
};
$tests['Submit numeric participant_id ajeno rechazado'] = function ($db) {
    p1_session(); $_POST = p1_submit_request(); $_POST['participant_id'] = '8';
    p0_assert(!p0_ajax('eipsi_forms_submit_form_handler')->success, 'Foreign numeric identity accepted');
    p1_assert_no_responses($db);
};
$tests['Submit wave de otro estudio rechazado antes de persistir'] = function ($db) {
    p1_session(); $_POST = p1_submit_request(); $_POST['wave_id'] = '22';
    p0_assert(!p0_ajax('eipsi_forms_submit_form_handler')->success, 'Foreign wave accepted');
    p1_assert_no_responses($db);
};
$tests['Submit sin assignment propio rechazado'] = function ($db) {
    p1_session(); $db->delete($db->prefix . 'survey_assignments', array('participant_id' => 7)); $_POST = p1_submit_request();
    p0_assert(!p0_ajax('eipsi_forms_submit_form_handler')->success, 'Missing assignment accepted');
    p1_assert_no_responses($db);
};
$tests['Submit anónimo independiente funciona sin alterar participantes'] = function ($db) {
    $_POST = p1_submit_request('anonymous-form');
    $response = p0_ajax('eipsi_forms_submit_form_handler');
    p0_assert($response->success && empty($response->data['emergency_mode']), 'Anonymous submit failed');
    $row = $db->get_row("SELECT * FROM {$db->prefix}vas_form_results LIMIT 1");
    p0_assert($row && $row->participant_id === 'p-browser-tracking' && $row->survey_id === null, 'Anonymous response acquired longitudinal identity');
    $participant = $db->get_row("SELECT * FROM {$db->prefix}survey_participants WHERE id = 8", ARRAY_A);
    p0_assert(!array_key_exists('T1_email', $participant), 'Anonymous email changed longitudinal schema/data');
};
$tests['Formulario independiente no hereda sesión longitudinal abierta'] = function ($db) {
    p1_session(); $_POST = p1_submit_request('anonymous-form');
    p0_assert(p0_ajax('eipsi_forms_submit_form_handler')->success, 'Standalone submit rejected with session');
    $row = $db->get_row("SELECT * FROM {$db->prefix}vas_form_results LIMIT 1");
    p0_assert($row->survey_id === null && $row->participant_id === 'p-browser-tracking', 'Unrelated session attached to standalone');
};
$tests['Consentimiento anónimo no fabrica participantes'] = function ($db) {
    $_POST = array('form_id' => 'anonymous-form', 'participant_id' => '', 'decision' => 'accepted', 'nonce' => 'valid:eipsi_forms_nonce');
    p0_assert(p0_ajax('eipsi_save_consent_decision_handler')->success, 'Anonymous consent UI broken');
    p0_assert((int) $db->get_var("SELECT COUNT(*) FROM {$db->prefix}survey_participants") === 3, 'Fabricated participant');
};
$tests['Helper de render usa token y no cookie legacy de otro participante'] = function ($db) {
    p1_session(); $_COOKIE['eipsi_participant_id'] = '8';
    $participant = eipsi_get_current_participant();
    p0_assert((int) $participant['id'] === 7, 'Legacy cookie overrode session');
};
$tests['Render longitudinal rechaza sesión de otro estudio'] = function ($db) {
    p1_session(9, 4);
    p0_assert(strpos(eipsi_render_form_template_markup(101), 'fixture') === false, 'Foreign study form rendered');
};
$tests['Render válido conserva formulario longitudinal'] = function ($db) {
    p1_session();
    p0_assert(strpos(eipsi_render_form_template_markup(101), '<form>fixture</form>') !== false, 'Valid form not rendered');
};
$tests['Sesión vencida y participante eliminado no autorizan'] = function ($db) {
    p1_session();
    $db->update($db->prefix . 'survey_sessions', array('expires_at' => gmdate('Y-m-d H:i:s', time() - 3600)), array('participant_id' => 7));
    p0_assert(EIPSI_Auth_Service::get_current_participant() === null, 'Expired session authorized');
    p1_session();
    $db->delete($db->prefix . 'survey_participants', array('id' => 7));
    p0_assert(EIPSI_Auth_Service::get_current_participant() === null, 'Deleted participant authorized');
};
$tests['Magic link permite render en la petición que crea sesión'] = function ($db) {
    $validation = EIPSI_MagicLinksService::validate_magic_link(p1_magic_link());
    p0_assert($validation['valid'], 'Valid token denied');
    $session = EIPSI_Auth_Service::create_session($validation['participant_id'], $validation['survey_id']);
    p0_assert($session['success'] && $session['cookie_set'], 'Valid magic cookie not set');
    p0_assert(EIPSI_Auth_Service::get_current_participant() === 7, 'New session invisible to same request');
    p0_assert(strpos(eipsi_render_form_template_markup(101), '<form>fixture</form>') !== false, 'Magic first render denied');
};
$tests['Submit study_id o survey_id ajeno rechazado'] = function ($db) {
    p1_session();
    foreach (array('study_id', 'survey_id') as $key) {
        $_POST = p1_submit_request(); $_POST[$key] = '4';
        p0_assert(!p0_ajax('eipsi_forms_submit_form_handler')->success, 'Foreign study claim allowed');
    }
    p1_assert_no_responses($db);
};
$tests['Submit POST y GET con waves contradictorias rechazado'] = function ($db) {
    p1_session(); $_POST = p1_submit_request(); $_POST['wave_id'] = '21'; $_GET['wave_id'] = '22';
    p0_assert(!p0_ajax('eipsi_forms_submit_form_handler')->success, 'Contradictory wave context accepted');
    p1_assert_no_responses($db);
};
$tests['Submit estado de assignment no autorizado rechazado antes de guardar'] = function ($db) {
    p1_session();
    $db->update($db->prefix . 'survey_assignments', array('status' => 'submitted'), array('participant_id' => 7));
    $_POST = p1_submit_request(); $_POST['wave_id'] = '21';
    p0_assert(!p0_ajax('eipsi_forms_submit_form_handler')->success, 'Closed assignment accepted');
    p1_assert_no_responses($db);
};
$tests['Submit preserva clave de tracking para completar parcial'] = function ($db) {
    p1_session();
    p0_assert(EIPSI_Partial_Responses::save('long-form', 'p-browser-tracking', 'browser-session', 2, array('answer' => 4))['success'], 'Partial fixture failed');
    $_POST = p1_submit_request();
    p0_assert(p0_ajax('eipsi_forms_submit_form_handler')->success, 'Valid submit rejected');
    p0_assert((int) $db->get_var("SELECT completed FROM {$db->prefix}eipsi_partial_responses WHERE participant_id = 'p-browser-tracking'") === 1, 'Tracking-key partial not completed');
};
$tests['Submit anónimo elimina identidad longitudinal falsificada de metadata'] = function ($db) {
    $_POST = p1_submit_request('anonymous-form'); $_POST['metadata'] = '{"participant_id":8,"survey_id":3,"longitudinal_participant_id":8}';
    p0_assert(p0_ajax('eipsi_forms_submit_form_handler')->success, 'Standalone submit failed');
    $metadata = json_decode($db->get_var("SELECT metadata FROM {$db->prefix}vas_form_results LIMIT 1"), true);
    p0_assert($metadata['participant_id'] === 'p-browser-tracking' && !array_key_exists('longitudinal_participant_id', $metadata) && !array_key_exists('survey_id', $metadata), 'Anonymous metadata impersonation');
};
$tests['Consentimiento estado desconocido falla cerrado'] = function ($db) {
    $db->update($db->prefix . 'survey_participants', array('consent_decision' => 'unexpected'), array('id' => 7));
    p0_assert(!EIPSI_Auth_Service::authenticate_passwordless(3, 'p7@example.invalid')['success'], 'Unknown consent state accepted');
};
$failed = 0;
foreach ($tests as $name => $test) {
    try { p1_fixture($test); echo "PASS $name\n"; } catch (Throwable $error) { $failed++; echo "FAIL $name: {$error->getMessage()}\n"; }
}
echo count($tests) . ' tests, ' . $failed . " failures\n";
ob_end_flush();
exit($failed ? 1 : 0);
