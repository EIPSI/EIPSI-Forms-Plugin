<?php
// Pure boundary checks; no WordPress instance or database is used.
define('ABSPATH', __DIR__);
$GLOBALS['test_user'] = 1;
$GLOBALS['test_language']='en';
function get_current_user_id() { return $GLOBALS['test_user']; }
function get_option($key,$default=false) { if($key!=='eipsi_forms_language')throw new Exception('Unexpected option');return $GLOBALS['test_language']??$default; }
require __DIR__ . '/../../includes/i18n/admin.php';
$count=0;
function verify($condition, $name) { global $count; if (!$condition) { throw new Exception($name); } ++$count; echo "PASS $name\n"; }
verify(eipsi_admin_language() === 'en', 'Default plugin language is English');
$GLOBALS['test_language']='es';verify(eipsi_admin_language()==='es','Spanish global language');
$GLOBALS['test_user']=2;verify(eipsi_admin_language()==='es','Another administrator shares plugin language');
$GLOBALS['test_user']=0;verify(eipsi_forms_language()==='es','Anonymous technical fallback shares plugin language');
$GLOBALS['test_language']='fr';verify(eipsi_admin_language()==='en','Unknown global language defaults to English');
$GLOBALS['test_language']=array('es');verify(eipsi_admin_language()==='en','Malformed global language defaults to English');
$GLOBALS['test_language']='en';verify(eipsi_admin_translate('Configuration')==='Configuration','English canonical label');
$GLOBALS['test_language']='es';verify(eipsi_admin_translate('Configuration')==='Configuración','Spanish presentation');
verify(eipsi_admin_translate('UNSUPPORTED_BLOCK')==='UNSUPPORTED_BLOCK','Unknown technical value unchanged');
$GLOBALS['test_language']='en';verify(eipsi_admin_translate('Configuration')==='Configuration','Global language changes without request cache');
verify(!function_exists('switch_to_locale'), 'No global locale service needed');
$GLOBALS['test_user']=1;$GLOBALS['test_language']='en';
verify(eipsi_admin_error('Sesión vencida. Recargá la página.')==='Session expired. Reload the page.', 'English error presentation for historical message');
verify(eipsi_admin_error('Assessment not found')==='Assessment not found', 'English domain value unchanged');
$GLOBALS['test_user']=2;$GLOBALS['test_language']='es';
verify(eipsi_admin_error('Assessment not found')==='No se encontró la evaluación', 'Spanish administrative error presentation');
verify(eipsi_admin_error('INTERNAL_CODE')==='INTERNAL_CODE', 'Technical unknown error code preserved');
echo "$count tests, 0 failures\n";
