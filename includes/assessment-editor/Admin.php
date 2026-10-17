<?php
namespace EIPSI\AssessmentEditor;
final class Admin {
    public static function assets(): void {
        if (!current_user_can('manage_options') || ($_GET['page'] ?? '') !== 'eipsi-assessment-editor') return;
        $base=EIPSI_FORMS_PLUGIN_URL;
        wp_enqueue_media();
        wp_enqueue_editor();
        wp_enqueue_style('eipsi-native-assessment-editor',$base.'assets/css/assessment-editor.css',array(),(string)filemtime(EIPSI_FORMS_PLUGIN_DIR.'assets/css/assessment-editor.css'));
        wp_enqueue_script('eipsi-native-assessment-editor',$base.'assets/js/assessment-editor.js',array('media-editor'),(string)filemtime(EIPSI_FORMS_PLUGIN_DIR.'assets/js/assessment-editor.js'),true);
        wp_add_inline_script('eipsi-native-assessment-editor','window.eipsiAssessmentEditorConfig='.wp_json_encode(array('url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('eipsi_native_assessment'))).';','before');
    }
    public static function screen(): void {
        if(!current_user_can('manage_options')) wp_die('Forbidden',403);
        echo '<div class="wrap ae-root" id="eipsi-assessment-editor"><p>Cargando Assessment Editor…</p></div>';
    }
    public static function ajax(): void {
        try {
            if($_SERVER['REQUEST_METHOD']!=='POST') throw new \RuntimeException('POST required',405);
            if(!current_user_can('manage_options')) throw new \RuntimeException('Forbidden',403);
            if(!check_ajax_referer('eipsi_native_assessment','nonce',false)) throw new \RuntimeException('Sesión vencida. Recargá la página.',403);
            if(!empty($_SERVER['HTTP_ORIGIN'])) {
                $origin=wp_parse_url($_SERVER['HTTP_ORIGIN']); $expected=wp_parse_url(admin_url());
                foreach(array('scheme','host','port') as $k) if(($origin[$k]??null)!==($expected[$k]??null)) throw new \RuntimeException('Invalid origin',403);
            }
            $operation=isset($_POST['operation'])&&is_string($_POST['operation'])?$_POST['operation']:'';
            switch($operation) {
                case 'import_question':
                    require_once __DIR__.'/Portable.php';if(!isset($_POST['document'])||!is_string($_POST['document']))Definition::fail('Archivo inválido.');$result=Portable::importQuestion(wp_unslash($_POST['document']));break;
                case 'export_question':
                    require_once __DIR__.'/Portable.php';if(!isset($_POST['id'],$_POST['revision'],$_POST['question_id'])||!is_string($_POST['id'])||!is_string($_POST['question_id'])||!is_string($_POST['revision'])||!preg_match('/^[1-9][0-9]{0,8}$/D',$_POST['revision']))Definition::fail('Solicitud inválida.');$result=Portable::exportQuestion(wp_unslash($_POST['id']),(int)$_POST['revision'],wp_unslash($_POST['question_id']));break;
                case 'import_definition':
                    require_once __DIR__.'/Portable.php';if(!isset($_POST['document'])||!is_string($_POST['document']))Definition::fail('Archivo inválido.');$result=Portable::import(wp_unslash($_POST['document']));break;
                case 'export_definition':
                    require_once __DIR__.'/Portable.php';if(!isset($_POST['id'],$_POST['revision'])||!is_string($_POST['id'])||!is_string($_POST['revision'])||!preg_match('/^[1-9][0-9]{0,8}$/D',$_POST['revision']))Definition::fail('Solicitud inválida.');$result=Portable::export(wp_unslash($_POST['id']),(int)$_POST['revision']);break;
                case 'versions': case 'load_version':
                    if(!isset($_POST['id'])||!is_string($_POST['id']))Definition::fail('Invalid identity');$id=wp_unslash($_POST['id']);
                    if($operation==='versions')$result=Repository::versions($id);else{if(!isset($_POST['version_id'])||!is_string($_POST['version_id']))Definition::fail('Invalid version');$result=Repository::loadVersion($id,wp_unslash($_POST['version_id']));}break;
                case 'list': $result=Repository::listing(); break;
                case 'load': if(!isset($_POST['id'])||!is_string($_POST['id'])) Definition::fail('Invalid identity'); $result=Repository::load(wp_unslash($_POST['id'])); break;
                case 'duplicate': case 'delete': case 'publish':
                    if(!isset($_POST['id'],$_POST['revision'])||!is_string($_POST['id'])||!is_string($_POST['revision'])||!preg_match('/^[1-9][0-9]{0,8}$/D',$_POST['revision']))Definition::fail('Invalid library request');$id=wp_unslash($_POST['id']);$revision=(int)$_POST['revision'];
                    if($operation==='duplicate')$result=Repository::duplicate($id,$revision);elseif($operation==='delete'){Repository::delete($id,$revision);$result=array('deleted'=>true);}else{require_once __DIR__.'/runtime/Owner.php';$result=(new Runtime\Owner())->publish($id,$revision);}break;
                case 'assign':
                    require_once __DIR__.'/runtime/Owner.php';if(!isset($_POST['version_id'])||!is_string($_POST['version_id']))Definition::fail('Invalid version');$binding=isset($_POST['binding'])&&is_string($_POST['binding'])?json_decode(wp_unslash($_POST['binding']),true,16,JSON_THROW_ON_ERROR):array('kind'=>'WORDPRESS_USER','user_id'=>get_current_user_id());if(!is_array($binding))Definition::fail('Invalid owner');$result=(new Runtime\Owner())->assign(wp_unslash($_POST['version_id']),$binding);break;
                case 'retire':
                    require_once __DIR__.'/runtime/Owner.php';if(!isset($_POST['version_id'])||!is_string($_POST['version_id']))Definition::fail('Invalid version');(new Runtime\Owner())->retire(wp_unslash($_POST['version_id']));$result=array('retired'=>true);break;
                case 'export_execution':
                    require_once __DIR__.'/runtime/Owner.php';if(!isset($_POST['execution_id'])||!is_string($_POST['execution_id']))Definition::fail('Invalid execution');$result=(new Runtime\Owner())->export(wp_unslash($_POST['execution_id']));break;
                case 'export':
                    require_once __DIR__.'/AnalyticalExport.php';foreach(array('definition','answers','touched') as $key)if(!isset($_POST[$key])||!is_string($_POST[$key])||strlen($_POST[$key])>1048576)Definition::fail('Invalid export request');$d=json_decode(wp_unslash($_POST['definition']),true,32,JSON_THROW_ON_ERROR);$answers=json_decode(wp_unslash($_POST['answers']),true,32,JSON_THROW_ON_ERROR);$touched=json_decode(wp_unslash($_POST['touched']),true,32,JSON_THROW_ON_ERROR);if(!is_array($d)||!is_array($answers)||!is_array($touched))Definition::fail('Invalid export payload');$rows=AnalyticalExport::rows($d,$answers,$touched,array('timepoint'=>'NATIVE_PREVIEW'));$result=array('contract'=>'NATIVE_ANALYTICAL_LONG_V1','rows'=>$rows,'csv'=>AnalyticalExport::csv($rows));break;
                case 'save':
                    if(!isset($_POST['definition'],$_POST['revision'])||!is_string($_POST['definition'])||strlen($_POST['definition'])>1048576||!is_string($_POST['revision'])||!preg_match('/^(0|[1-9][0-9]{0,8})$/D',$_POST['revision'])) Definition::fail('Invalid save request');
                    $d=json_decode(wp_unslash($_POST['definition']),true,32,JSON_THROW_ON_ERROR); if(!is_array($d)) Definition::fail('Invalid definition');
                    $result=Repository::save($d,(int)$_POST['revision']); break;
                default: Definition::fail('Unknown operation');
            }
            wp_send_json_success($result);
        } catch(\Throwable $e) {
            $code=(int)$e->getCode(); if(!in_array($code,array(403,404,405,409),true)) $code=400;
            wp_send_json_error(array('message'=>$code===400?'Definición inválida: '.$e->getMessage():$e->getMessage()),$code);
        }
    }
}
