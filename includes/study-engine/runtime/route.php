<?php
/** Loaded only for the dedicated opt-in query route; legacy registration manifests stay unchanged. */
if (!defined('ABSPATH')) { exit; }
require_once dirname(__DIR__).'/content.php';
require_once __DIR__.'/PublicUrl.php';
require_once __DIR__.'/PublicPage.php';
require_once __DIR__.'/RuntimeDiagnostics.php';
require_once __DIR__.'/PublicEntrySnapshot.php';
add_action('template_redirect', static function () {
    if (!isset($_GET['eipsi_study'])) { return; }
    if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
    nocache_headers();header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('X-Robots-Tag: noindex, nofollow, noarchive');header('Content-Type: text/html; charset=UTF-8');
    header('Vary: Cookie, Accept');header('Referrer-Policy: same-origin');header('X-Content-Type-Options: nosniff');
    $json=strpos($_SERVER['HTTP_ACCEPT']??'','application/json')!==false;
    $displayDefinition=array();$m=static function($key)use(&$displayDefinition){return \EIPSI\StudyEngine\Runtime\PublicMessages::text($displayDefinition,$key);};
    $public=is_string($_GET['eipsi_study'])?wp_unslash($_GET['eipsi_study']):'';$frame=null;$status=200;$message=null;
    $diagnostics=new \EIPSI\StudyEngine\Runtime\RuntimeDiagnostics($public,$_SERVER['REQUEST_METHOD']??'GET');
    try {
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D',$public))throw new \EIPSI\StudyEngine\Runtime\AccessDenied('STUDY_UNAVAILABLE');
        $diagnostics->phase('SEED_CONFIGURATION');
        require_once dirname(__DIR__).'/configuration/KeyringResolver.php';
        $config=\EIPSI\StudyEngine\Configuration\KeyringResolver::binary('seed');$keys=$config['keys'];
        $diagnostics->phase('DATABASE_CONNECTION');
        global $wpdb;$db=new \EIPSI\StudyEngine\Persistence\Database($wpdb);
        $diagnostics->phase('SEED_CONFIGURATION');
        try{$vault=new \EIPSI\StudyEngine\Persistence\SeedVault($keys,$config['current']);}catch(\DomainException $error){throw new \EIPSI\StudyEngine\Runtime\PublicRuntimeFailure('INVALID_SERVER_KEYRING',0,$error);}catch(\InvalidArgumentException $error){throw new \EIPSI\StudyEngine\Runtime\PublicRuntimeFailure('INVALID_SERVER_KEYRING',0,$error);}
        $diagnostics->phase('PUBLIC_URL_LOOKUP');
        $public=\EIPSI\StudyEngine\Runtime\PublicUrl::resolve($db,$public);
        $diagnostics->phase('STUDY_LOOKUP');
        $study=(new \EIPSI\StudyEngine\Runtime\PublicStudyRepository($db))->find($public);if(!$study)throw new \EIPSI\StudyEngine\Runtime\AccessDenied('STUDY_UNAVAILABLE');$diagnostics->study($study);$longitudinal=($study['enrollment_mode']??'ANONYMOUS')==='EMAIL_LONGITUDINAL';
        $diagnostics->phase('PUBLISHED_SNAPSHOT');
        $displayDefinition=\EIPSI\StudyEngine\Runtime\PublicEntrySnapshot::definition($db,$study);if($longitudinal){require_once dirname(__DIR__).'/longitudinal.php';$displayDefinition=\EIPSI\StudyEngine\Longitudinal\InitialScreen::effectiveDefinition($db,$displayDefinition);}
        $diagnostics->phase('ACCESS_CONFIGURATION');
        if($longitudinal){require_once dirname(__DIR__).'/longitudinal.php';$runtime=new \EIPSI\StudyEngine\Longitudinal\Runtime($db,$vault,\EIPSI\StudyEngine\Longitudinal\Capability::configured($db));$name=\EIPSI\StudyEngine\Longitudinal\Identity::cookieName($public);header('Referrer-Policy: no-referrer');}
        else{$runtime=new \EIPSI\StudyEngine\Runtime\CrossSectionalRuntime($db,$vault);$name=\EIPSI\StudyEngine\Runtime\AnonymousAccess::cookieName($public);}
        $diagnostics->phase('SESSION_CONTEXT');
        $token=isset($_COOKIE[$name])&&is_string($_COOKIE[$name])?wp_unslash($_COOKIE[$name]):null;
        $pauseAccess=!\EIPSI\StudyEngine\Runtime\PausePolicy::paused($study)||$token===null?null:($longitudinal?(new \EIPSI\StudyEngine\Longitudinal\Identity($db))->recover($public,$token):(new \EIPSI\StudyEngine\Runtime\AnonymousAccess($db))->recover($public,$token));
        $pausePass=\EIPSI\StudyEngine\Runtime\PausePolicy::portal($db,$study,$pauseAccess)||($longitudinal&&(isset($_GET['eipsi_cap'])||isset($_GET['eipsi_confirm'])));
        if($study['enrollment_state']==='FINALIZED'||(\EIPSI\StudyEngine\Runtime\PausePolicy::paused($study)&&!$pausePass)){
            $state=$study['enrollment_state']==='FINALIZED'?'FINALIZED':'PAUSED';if($state==='PAUSED')\EIPSI\StudyEngine\Runtime\PausePolicy::denied($study);
            $html=\EIPSI\StudyEngine\Runtime\PublicStudyRepository::availabilityHtml($displayDefinition,$state);
            if($json)wp_send_json(array('state'=>$state,'revision'=>null,'receipt'=>null,'html'=>$html,'error_message'=>null),200);
            echo \EIPSI\StudyEngine\Runtime\PublicPage::document($html,$m('document_title'),array('assets/css/study-runtime.css'),array('assets/js/study-runtime.js'),($displayDefinition?\EIPSI\I18n\ContentDefaults::language($displayDefinition):eipsi_forms_language()));exit;
        }
        $token=isset($_COOKIE[$name])&&is_string($_COOKIE[$name])?wp_unslash($_COOKIE[$name]):null;
        $diagnostics->phase('REQUEST_VALIDATION');
        $request=null;$method=$_SERVER['REQUEST_METHOD']??'GET';
        if(!in_array($method,array('GET','POST'),true)){header('Allow: GET, POST');status_header(405);exit;}
        if($method==='POST'){
            $origin=$_SERVER['HTTP_ORIGIN']??null;
            $home=wp_parse_url(home_url('/'));$expected=$home['scheme'].'://'.$home['host'].(isset($home['port'])?':'.$home['port']:'');
            if($origin!==null&&!hash_equals($expected,rtrim($origin,'/')))throw new \EIPSI\StudyEngine\Runtime\AccessDenied('ORIGIN_REJECTED');
            if((int)($_SERVER['CONTENT_LENGTH']??0)>65536)throw new \DomainException('REQUEST_TOO_LARGE');
            $request=wp_unslash($_POST);unset($request['page']);
            if(isset($request['responses'])||in_array($request['action']??null,array('partial','next','back','submit','refresh','restart'),true))throw new \DomainException('UNKNOWN_RUNTIME_ACTION');
            if(isset($request['expected_revision'])&&is_string($request['expected_revision'])&&preg_match('/^[0-9]+$/D',$request['expected_revision']))$request['expected_revision']=(int)$request['expected_revision'];
            if(($request['action']??null)==='back')unset($request['responses']);
            if(isset($request['metadata_json'])){$request['metadata']=json_decode($request['metadata_json'],true);unset($request['metadata_json']);if(!is_array($request['metadata']))throw new \DomainException('INVALID_METADATA');}
        }
        // Bounded local abuse control; reverse-proxy/DDoS policy remains operational.
        $diagnostics->phase('RATE_LIMIT');
        $category=(isset($_GET['eipsi_cap'])||isset($_GET['eipsi_confirm']))?'redemption':($request===null?($token===null?'creation':'read'):(($request['action']??null)==='submit'?'submit':'mutation'));
        if($longitudinal&&in_array($request['action']??null,array('enroll','resend','confirm_email','withdrawal_lookup','withdraw'),true))$category=$request['action']==='withdraw'?'withdrawal':($request['action']==='enroll'?'enrollment':($request['action']==='resend'?'verification_resend':'access_request'));
        $limit=$category==='creation'?20:($category==='read'?120:($category==='submit'?40:240));
        if(in_array($category,array('enrollment','access_request','verification_resend','redemption','withdrawal'),true))$limit=in_array($category,array('redemption','withdrawal'),true)?40:10;
        if(in_array($category,array('creation','enrollment','access_request','verification_resend','redemption','withdrawal'),true)){
            \EIPSI_Rate_Limiter::consume('study.'.$category,$public.'|'.($_SERVER['REMOTE_ADDR']??''),$limit,60);
        }
        $diagnostics->phase('CAPABILITY_REDEMPTION');
        if($longitudinal){require_once dirname(__DIR__).'/longitudinal/CapabilityLanding.php';}
        if(isset($_GET['eipsi_cap'])){
            if(!$longitudinal||$method!=='GET'||!is_string($_GET['eipsi_cap'])||strlen($_GET['eipsi_cap'])>256)throw new \EIPSI\StudyEngine\Runtime\AccessDenied('ACCESS_REQUIRED');
            $pending=wp_unslash($_GET['eipsi_cap']);
            \EIPSI\StudyEngine\Longitudinal\CapabilityLanding::validate($db,$public,$pending,$token);
            \EIPSI\StudyEngine\Longitudinal\CapabilityLanding::cookie($public,$pending,time()+600);
            wp_safe_redirect(add_query_arg(array('eipsi_study'=>$public,'eipsi_confirm'=>'1'),home_url('/')),303);exit;
        }
        $pendingName=$longitudinal?\EIPSI\StudyEngine\Longitudinal\CapabilityLanding::pendingName($public):'';
        $pending=$longitudinal&&is_string($_COOKIE[$pendingName]??null)?wp_unslash($_COOKIE[$pendingName]):null;
        if($longitudinal&&$method==='GET'&&isset($_GET['eipsi_confirm'])){
            if($pending===null)throw new \EIPSI\StudyEngine\Runtime\AccessDenied('ACCESS_REQUIRED');
            \EIPSI\StudyEngine\Longitudinal\CapabilityLanding::validate($db,$public,$pending,$token);
            $frame=$runtime->redeem($public,$pending,$token);$token=$frame['token'];
            setcookie($name,$token,array('expires'=>strtotime($frame['access']['expires_at'].' UTC'),'path'=>wp_parse_url(home_url('/'),PHP_URL_PATH)?:'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax'));
            \EIPSI\StudyEngine\Longitudinal\CapabilityLanding::cookie($public,'',time()-3600);
            // Historical exchanges retain their redirect contract. V2 renders the verification notice.
            if(!\EIPSI\StudyEngine\Longitudinal\InitialScreen::v2(\EIPSI\StudyEngine\Longitudinal\Configuration::read($frame['definition']))){wp_safe_redirect(isset($_GET['eipsi_completion'])&&is_string($_GET['eipsi_completion'])&&($request['action']??null)==='confirm_email'?add_query_arg(array('eipsi_study'=>$public,'eipsi_completion'=>wp_unslash($_GET['eipsi_completion'])),home_url('/')):add_query_arg('eipsi_study',$public,home_url('/')),303);exit;}
        }
        $diagnostics->phase('ANONYMOUS_ACCESS');
        if($method==='GET'&&!$longitudinal&&$token!==null&&!(new \EIPSI\StudyEngine\Runtime\AnonymousAccess($db))->recover($public,$token))$token=null;
        if($method==='GET'&&$token===null&&!$longitudinal){
            $token=\EIPSI\StudyEngine\Runtime\AnonymousAccess::token();
            (new \EIPSI\StudyEngine\Runtime\AnonymousAccess(new \EIPSI\StudyEngine\Persistence\Database($wpdb)))->reserve($public,$token);
            setcookie($name,$token,array('expires'=>time()+365*DAY_IN_SECONDS,'path'=>wp_parse_url(home_url('/'),PHP_URL_PATH)?:'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax'));
        }
        $diagnostics->phase('EXECUTION_INITIALIZATION');
        // A fresh visit after completion starts another permitted transversal response.
        // JSON reads and in-progress visits keep their current execution.
        if(!$longitudinal&&$method==='GET'&&!$json&&$token!==null&&$study['enrollment_state']==='OPEN'&&($displayDefinition['protocol_metadata']['study_type']??null)==='TRANSVERSAL'&&empty($displayDefinition['protocol_metadata']['unique_response'])){
            $finished=$runtime->handle($public,$token,null);
            if(in_array($finished['view']['next_action']['kind'],array('FINISHED','TERMINATED'),true)){
                $token=\EIPSI\StudyEngine\Runtime\AnonymousAccess::token();
                (new \EIPSI\StudyEngine\Runtime\AnonymousAccess($db))->reserve($public,$token);
                $frame=$runtime->handle($public,$token,null);$frame['set_cookie']=true;
            }else $frame=$finished;
        }
        if($frame===null)$frame=$longitudinal&&!\EIPSI\StudyEngine\Runtime\PausePolicy::paused($study)&&$request===null&&!isset($_GET['eipsi_completion'])&&isset($displayDefinition['protocol_metadata']['longitudinal']['initial_screen'])?$runtime->initialFrame($public,$token):$runtime->handle($public,$token,$request);
        if(isset($frame['token']))$_COOKIE[$name]=$frame['token'];
        if($frame['set_cookie']){
            $path=wp_parse_url(home_url('/'),PHP_URL_PATH)?:'/';
            $expires=$longitudinal?(isset($frame['access']['expires_at'])?strtotime($frame['access']['expires_at'].' UTC'):time()+DAY_IN_SECONDS):time()+365*DAY_IN_SECONDS;
            $_COOKIE[$name]=$frame['token'];setcookie($name,$frame['token'],array('expires'=>$expires,'path'=>$path,'secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax'));
        }
        if($method==='POST'&&!$json&&!isset($frame['entry'])&&($request['action']??null)!=='confirm_access'){wp_safe_redirect(isset($_GET['eipsi_completion'])&&is_string($_GET['eipsi_completion'])&&($request['action']??null)==='confirm_email'?add_query_arg(array('eipsi_study'=>$public,'eipsi_completion'=>wp_unslash($_GET['eipsi_completion'])),home_url('/')):add_query_arg('eipsi_study',$public,home_url('/')),303);exit;}
    }catch(\EIPSI\StudyEngine\Persistence\PersistenceConflict $e){$status=409;$message=$m('conflict');}
    catch(\EIPSI\StudyEngine\Runtime\StudyPaused $e){
        \EIPSI\StudyEngine\Runtime\PausePolicy::denied($study);$html=\EIPSI\StudyEngine\Runtime\PublicStudyRepository::availabilityHtml($displayDefinition,'PAUSED');
        if($json)wp_send_json(array('state'=>'PAUSED','revision'=>null,'receipt'=>null,'html'=>$html,'error_message'=>null),200);
        echo \EIPSI\StudyEngine\Runtime\PublicPage::document($html,$m('document_title'),array('assets/css/study-runtime.css'),array('assets/js/study-runtime.js'),($displayDefinition?\EIPSI\I18n\ContentDefaults::language($displayDefinition):eipsi_forms_language()));exit;
    }
    catch(\EIPSI\StudyEngine\Runtime\AccessDenied $e){if(!isset($study['study_id'])&&$e->getMessage()==='STUDY_UNAVAILABLE'){$diagnostics->record($e,'STUDY_NOT_FOUND','info',404);status_header(404);exit;}$status=403;$message=$e->getMessage()==='STUDY_FINALIZED'?$m('finalized_body'):$m('unavailable_title');}
    catch(\EIPSI_Rate_Limit_Exception $e){header('Retry-After: '.$e->retry_after);$status=$e->getCode();$message=$m('rate_limit');}
    catch(\OverflowException $e){$status=429;$message=$m('rate_limit');}
    catch(\DomainException $e){$status=422;$withdrawalMismatch=$e->getMessage()==='WITHDRAWAL_EMAIL_NOT_FOUND';$message=$withdrawalMismatch?$m('withdrawal_email_not_found'):($e->getMessage()==='EMAIL_CHANGE_CONFIRMATION_REQUIRED'?$m('change_unconfirmed'):($e->getMessage()==='RESPONSE_ALREADY_RECORDED'?$m('unique_response'):($e->getMessage()==='EMAIL_ALREADY_ENROLLED'?$m('email_collision'):$m(($longitudinal??false)?'invalid_fields':'operation_failed'))));}
    catch(\Throwable $e){$status=$diagnostics->status($e);$diagnostics->record($e);$message=$m('operation_failed');}
    $diagnostics->phase('FALLBACK_RENDER');
    if($status===403&&($study['enrollment_state']??null)!=='FINALIZED'&&($longitudinal??false)&&isset($runtime,$study['study_id'])){
        if(class_exists('\\EIPSI\\StudyEngine\\Longitudinal\\CapabilityLanding'))\EIPSI\StudyEngine\Longitudinal\CapabilityLanding::cookie($public,'',time()-3600);
        if(!$json){wp_safe_redirect(add_query_arg(array('eipsi_study'=>$public,'eipsi_initial'=>'1','eipsi_notice'=>'unavailable'),home_url('/')),303);exit;}
        try{
            $frame=$runtime->initialFrame($public,$token??null);$displayDefinition=$frame['definition'];$frame['availability_notice']=$m('unavailable_title');$message=null;
            if($frame['set_cookie'])setcookie($name,$frame['token'],array('expires'=>time()+DAY_IN_SECONDS,'path'=>wp_parse_url(home_url('/'),PHP_URL_PATH)?:'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax'));
        }catch(\Throwable $ignored){if(!$ignored instanceof \DomainException)$diagnostics->record($ignored,null,'error',$status);}
    }
    if($status===200&&isset($frame['entry'])&&($_GET['eipsi_initial']??null)==='1'&&($_GET['eipsi_notice']??null)==='unavailable'){
        $displayDefinition=$frame['definition'];$frame['availability_notice']=$m('unavailable_title');
    }
    if($status!==200&&in_array($request['action']??null,array('withdrawal_lookup','withdraw'),true)&&isset($runtime,$token)&&($longitudinal??false))try{$frame=$runtime->initialFrame($public,$token);$frame['withdrawal_error']=$message;$frame['withdrawal_entered_email']=is_string($request['email']??null)?$request['email']:'';$message=null;}catch(\Throwable $ignored){if(!$ignored instanceof \DomainException)$diagnostics->record($ignored,null,'error',$status);}
    if($frame===null&&isset($runtime,$token)&&$token!==null&&in_array($status,array(409,422),true)&&!isset($request['responses'])&&!in_array($request['action']??null,array('partial','next','back','submit','refresh','restart'),true))try{$frame=$runtime->handle($public,$token,null);}catch(\Throwable $ignored){if(!$ignored instanceof \DomainException)$diagnostics->record($ignored,null,'error',$status);}
    if(($status===200||(in_array($status,array(409,422),true)&&($request['action']??null)==='confirm_email'))&&($longitudinal??false)&&isset($frame['access'],$_GET['eipsi_completion'])&&is_string($_GET['eipsi_completion'])){
        try{
            require_once __DIR__.'/NativeBridge.php';$frame=\EIPSI\StudyEngine\Runtime\NativeBridge::completionFrame($frame,wp_unslash($_GET['eipsi_completion']));
        }catch(\Throwable $ignored){if(!$ignored instanceof \DomainException)$diagnostics->record($ignored,null,'error',$status);}
    }
    $diagnostics->phase('FALLBACK_RENDER');
    if($frame===null&&$status!==200&&isset($runtime,$study['study_id'])&&$displayDefinition){
        try{
            if($longitudinal??false){
                $frame=$runtime->initialFrame($public,$token??null);
                if(!empty($frame['set_cookie']))setcookie($name,$frame['token'],array('expires'=>time()+DAY_IN_SECONDS,'path'=>wp_parse_url(home_url('/'),PHP_URL_PATH)?:'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax'));
            }elseif(isset($token)&&$token!==null){
                $known=(new \EIPSI\StudyEngine\Runtime\AnonymousAccess($db))->recover($public,$token);
                if($known&&$known['execution_id']!==null)$frame=$runtime->handle($public,$token,null);
            }
        }catch(\Throwable $ignored){if(!$ignored instanceof \DomainException)$diagnostics->record($ignored,null,'error',$status);}
    }
    if($frame===null&&($longitudinal??false)&&$displayDefinition)$frame=array('study'=>$study,'definition'=>$displayDefinition,'entry'=>true,'portal_disabled'=>true,'csrf'=>'','message'=>null);
    if(isset($frame['definition']))$displayDefinition=$frame['definition'];
    status_header($status);
    $diagnostics->phase('ASSESSMENT_RENDER');
    try{$html=$frame!==null?(($longitudinal??false)?(new \EIPSI\StudyEngine\Longitudinal\EmailTemplate())->screen($frame,$message):(new \EIPSI\StudyEngine\Content\AssessmentRenderer())->render($frame,$message)):\EIPSI\StudyEngine\Runtime\PublicPage::errorContent($message??$m('operation_failed'));}
    catch(\Throwable $e){$status=$diagnostics->status($e);$diagnostics->record($e);status_header($status);$message=$m('content_failed');$html=\EIPSI\StudyEngine\Runtime\PublicPage::errorContent($message);}
    if(($study['enrollment_state']??null)==='FINALIZED')$html=\EIPSI\StudyEngine\Runtime\PublicStudyRepository::finalizedHtml($displayDefinition);
    if($json){wp_send_json(array('state'=>$status===200?($frame['view']['next_action']['kind']??'OPERATIONAL_ERROR'):'ERROR','revision'=>$frame['view']['revision']??null,'receipt'=>$status===200?($frame['receipt']??null):null,'html'=>$html,'withdrawal_check'=>$frame['withdrawal_check']??null,'error_message'=>$frame['withdrawal_error']??$message),$status);}
    $diagnostics->phase('DOCUMENT_RENDER');
    try{echo \EIPSI\StudyEngine\Runtime\PublicPage::document($html,$m('document_title'),array('assets/css/study-runtime.css'),array('assets/js/study-runtime.js'),($displayDefinition?\EIPSI\I18n\ContentDefaults::language($displayDefinition):eipsi_forms_language()));}
    catch(\Throwable $e){$diagnostics->record($e);status_header($diagnostics->status($e));echo \EIPSI\StudyEngine\Runtime\PublicPage::document(\EIPSI\StudyEngine\Runtime\PublicPage::errorContent($m('operation_failed')),'EIPSI Forms');}
    exit;
}, -100);
