<?php
namespace EIPSI\AssessmentEditor\Runtime;
require_once __DIR__.'/Owner.php';
require_once dirname(__DIR__,2).'/study-engine/runtime/PublicPage.php';
final class Transport {
    private static function origin(): void {if(!empty($_SERVER['HTTP_ORIGIN'])){$a=wp_parse_url($_SERVER['HTTP_ORIGIN']);$b=wp_parse_url(home_url());foreach(array('scheme','host','port') as $k)if(($a[$k]??null)!==($b[$k]??null))throw new \RuntimeException('Invalid origin',403);}}
    public static function ajax(): void {nocache_headers();try{if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new \RuntimeException('POST required',405);self::origin();if(!isset($_POST['execution'],$_POST['payload'])||!is_string($_POST['execution'])||!is_string($_POST['payload'])||strlen($_POST['payload'])>1048576)throw new \InvalidArgumentException('Invalid native request');$id=wp_unslash($_POST['execution']);$payload=json_decode(wp_unslash($_POST['payload']),true,32,JSON_THROW_ON_ERROR);if(!is_array($payload))throw new \InvalidArgumentException('Invalid payload');$owner=new Owner();if(($payload['action']??'')==='view'){\EIPSI\AssessmentEditor\Definition::fields($payload,array('action'));$result=$owner->view($id);}else $result=$owner->command($id,$payload);wp_send_json_success($result);}catch(\Throwable $e){$code=in_array((int)$e->getCode(),array(403,404,405,409),true)?(int)$e->getCode():400;wp_send_json_error(array('message'=>$code===400?'Datos de evaluación inválidos.':$e->getMessage())+($code===403&&isset($id)?array('entry_url'=>add_query_arg('eipsi_native',$id,home_url('/'))):array())+($e instanceof \EIPSI\AssessmentEditor\AnswerValidationFailure?array('validation_errors'=>$e->errors):array()),$code);}}
    public static function page(): void {
        try{$id=isset($_GET['eipsi_native'])&&is_string($_GET['eipsi_native'])?wp_unslash($_GET['eipsi_native']):'';$frame=(new Owner())->pageEntry($id);if(isset($frame['redirect'])){nocache_headers();wp_safe_redirect($frame['redirect'],303);exit;}nocache_headers();header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');header('X-Frame-Options: SAMEORIGIN');status_header(200);$base=EIPSI_FORMS_PLUGIN_URL;
            $content='<main class="ae-root ae-native-runtime" id="eipsi-native-participant"></main><script>window.eipsiNativeParticipant='.wp_json_encode(array('frame'=>$frame,'url'=>admin_url('admin-ajax.php')),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script>';
            echo \EIPSI\StudyEngine\Runtime\PublicPage::document($content,$frame['definition']['title'],array('assets/css/assessment-editor.css'),array('assets/js/assessment-participant.js'));exit;
        }catch(\Throwable $e){nocache_headers();header('Content-Type: text/html; charset=UTF-8');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');status_header(403);echo \EIPSI\StudyEngine\Runtime\PublicPage::document('<main class="eipsi-study"></main>','EIPSI Forms',array('assets/css/study-runtime.css'));exit;}
    }

    public static function cleanup(): void {try{(new Owner())->cleanup();}catch(\Throwable $e){error_log('EIPSI native recovery cleanup failed');}}
}
