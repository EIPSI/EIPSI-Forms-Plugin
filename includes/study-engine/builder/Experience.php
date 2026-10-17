<?php
namespace EIPSI\StudyEngine\Builder;
use EIPSI\StudyEngine\Contracts as C;
use EIPSI\StudyEngine\Engine as E;
use EIPSI\StudyEngine\Longitudinal as L;
use EIPSI\AssessmentEditor as A;
require_once dirname(__DIR__,2).'/assessment-editor/Answers.php';
require_once dirname(__DIR__).'/runtime/ResponsePolicy.php';
/** Read-only, bounded replay. No Owner, credentials, queue, Storage or mail transport. */
final class Experience {
    private $noticeKeys=array();
    private $studyState='ACTIVE',$studyCycle=1,$archives=array();
    private $completionInstance=null,$repeatInstance=null,$attempts=array(),$withdrawalView=false,$presentAt=null,$seed,$sim,$graph,$definition,$native,$config,$sessions=array(),$mail=array(),$sent=array(),$serial=0,$enrolled=false,$hasSession=false,$verified=false,$inside=false,$review=false,$reviewed=false,$email='participante@example.test',$notice='',$completion=null,$partials=array();
    public static function run(array $d,array $context,array $commands,array $native,array $lifecycle=array()): array {
        C\Check::fields($context,array('created_at','timezone'),array('seed_hex'));C\Check::timestamp($context['created_at'],'created_at',false);C\Check::timezone($context['timezone'],'timezone');
        C\Check::listOf($commands,'commands');if(count($commands)>300)throw new \DomainException('PREVIEW_COMMAND_LIMIT');
        $x=new self();$x->studyState=($lifecycle['state']??'ACTIVE')==='DRAFT'?'ACTIVE':($lifecycle['state']??'ACTIVE');$x->studyCycle=(int)($lifecycle['cycle']??1);$x->seed=$context['seed_hex']??str_repeat('01',32);$x->definition=$d;$x->native=$native;$x->graph=(new E\DefinitionCompiler())->compile($d);$x->config=($d['protocol_metadata']['enrollment_mode']??'')==='EMAIL_LONGITUDINAL'?L\Configuration::read($d):null;
        $x->config=$x->config!==null?L\Configuration::read($x->screenDefinition()):null;
        $x->sim=new E\Simulator($x->graph,$context+array('seed_hex'=>str_repeat('01',32),'execution_id'=>'DUMMY_EXPERIENCE','max_instances'=>10000,'eager_limit'=>10000,'horizon_days'=>366));
        foreach($commands as $cmd){C\Check::fields($cmd,array('kind','data'));if(!is_array($cmd['data']))throw new \DomainException('INVALID_PREVIEW_DATA');$x->command($cmd['kind'],$cmd['data']);}
        return $x->frame();
    }
    private function message(string $key): string {return \EIPSI\StudyEngine\Runtime\PublicMessages::text($this->definition,$key);}
    private function snapshot(): array {return $this->sim->snapshot();}
    private function dispatch(string $kind,array $data=array()): void {$this->sim->dispatch(array('id'=>'DUMMY_'.(++$this->serial),'kind'=>$kind,'data'=>$data,'expected_revision'=>$this->snapshot()['execution']['revision']));}
    private function required(): bool {return $this->config!==null&&!$this->verified&&L\InitialScreen::required($this->config,$this->snapshot(),$this->definition)&&(!L\InitialScreen::firstCompleted($this->snapshot(),$this->definition)||L\InitialScreen::available($this->snapshot(),$this->definition));}
    private function command(string $kind,array $data): void {
        $this->notice='';$this->noticeKeys=array();
        if($this->studyState==='FINALIZED'&&!in_array($kind,array('REOPEN','FINALIZE'),true))throw new \DomainException('PREVIEW_STUDY_FINALIZED');
        switch($kind){
            case 'PAUSE':C\Check::fields($data,array());$this->studyState='PAUSED';break;
            case 'ACTIVATE':C\Check::fields($data,array());$this->studyState='ACTIVE';break;
            case 'FINALIZE':
                C\Check::fields($data,array('erase_emails'));if(!is_bool($data['erase_emails']))throw new \DomainException('INVALID_STUDY_STATE');
                if($this->studyState==='FINALIZED')break;$this->archives[]=array('snapshot'=>$this->snapshot(),'sessions'=>$this->sessions,'email'=>$data['erase_emails']?null:$this->email);
                $this->studyState='FINALIZED';$this->hasSession=false;$this->inside=false;$this->review=false;$this->completion=null;if($data['erase_emails']){$this->email='';foreach($this->archives as &$archive)$archive['email']=null;unset($archive);}foreach($this->mail as &$mail)$mail['revoked']=true;unset($mail);$this->notice='El estudio ha finalizado.';break;
            case 'REOPEN':
                C\Check::fields($data,array());if($this->studyState!=='FINALIZED')throw new \DomainException('STUDY_NOT_FINALIZED');$at=$this->snapshot()['clock'];$zone=$this->snapshot()['timezone'];$this->studyCycle++;
                $this->sim=new E\Simulator($this->graph,array('created_at'=>$at,'timezone'=>$zone,'seed_hex'=>$this->seed,'execution_id'=>'DUMMY_CYCLE_'.$this->studyCycle,'max_instances'=>10000,'eager_limit'=>10000,'horizon_days'=>366));$this->studyState='ACTIVE';$this->enrolled=false;$this->hasSession=false;$this->verified=false;$this->inside=false;$this->review=false;$this->reviewed=false;$this->withdrawalView=false;$this->email='';$this->completion=null;$this->completionInstance=null;$this->repeatInstance=null;$this->sessions=array();$this->attempts=array();$this->sent=array();$this->notice='Estudio reabierto. El próximo ingreso será un sujeto nuevo.';break;
            case 'ENTER':
                if($this->studyState==='PAUSED'&&!$this->enrolled){$this->notice='El estudio está pausado para nuevas inscripciones.';break;}

                C\Check::fields($data,array(),array('email'));if(isset($data['email'])){if(!is_string($data['email'])||strlen($data['email'])>254||!filter_var($data['email'],FILTER_VALIDATE_EMAIL))throw new \DomainException('Ingresá un email válido.');if($this->enrolled&&strtolower($data['email'])!==$this->email)throw new \DomainException('Este recorrido ya pertenece a otro email. Corregilo después de la primera evaluación o reiniciá el recorrido.');$this->email=strtolower($data['email']);}
                if($this->enrolled){if($this->verified){$this->hasSession=true;$this->inside=true;$this->withdrawalView=false;$this->completion=null;$this->presentAt=strtotime($this->snapshot()['clock']);break;}$this->inside=false;$this->hasSession=false;$this->send('VERIFY_PARTICIPANT',null,'VERIFY_ENTRY_'.count($this->mail));$this->noticeKeys=array('email_validation_sent');$this->notice=$this->message('email_validation_sent');break;}
                if(!$this->enrolled){$this->enrolled=true;$this->hasSession=true;$this->dispatch('FIRST_ACCESS');}
                $this->withdrawalView=false;$this->inside=true;$this->presentAt=strtotime($this->snapshot()['clock']);$this->completion=null;
                if($this->required()){$available=null;foreach($this->snapshot()['instances'] as $item)if(in_array($item['state'],array('AVAILABLE','IN_PROGRESS'),true)&&$this->graph->node($item['definition_id'])['completion_boundary']==='ASSESSMENT'){$available=$item['id'];break;}$this->send('VERIFY_PARTICIPANT',$available,$available?'VERIFY_'.$available:'VERIFY_INITIAL');$this->noticeKeys=array(L\InitialScreen::available($this->snapshot(),$this->definition)?'email_first_link':'email_validation_sent');$this->notice=L\InitialScreen::available($this->snapshot(),$this->definition)?$this->message('email_first_link'):$this->message('email_validation_sent');}
                break;
            case 'REPEAT':
                C\Check::fields($data,array());if(!$this->inside||$this->review||$this->completion===null||!$this->repeatAllowed($this->completionInstance))throw new \DomainException('PREVIEW_REPEAT_UNAVAILABLE');
                $this->repeatInstance=$this->completionInstance;$this->completion=null;$this->attempts[$this->repeatInstance]=($this->attempts[$this->repeatInstance]??0)+1;unset($this->sessions[$this->repeatInstance]);break;
            case 'CONTINUE':C\Check::fields($data,array());$this->completion=null;break;
            case 'LOGOUT':C\Check::fields($data,array());$this->hasSession=false;$this->inside=false;break;
            case 'LEAVE':C\Check::fields($data,array());$this->inside=false;break;
            case 'ADVANCE':
                C\Check::fields($data,array('seconds'));C\Check::integer($data['seconds'],'seconds',1);if($data['seconds']>366*86400)throw new \DomainException('PREVIEW_TIME_LIMIT');
                $target=strtotime($this->snapshot()['clock'])+$data['seconds'];$steps=0;
                while(($next=$this->nextTime())!==null&&$next<=$target){if(++$steps>20000)throw new \DomainException('PREVIEW_EVENT_LIMIT');$this->dispatch('ADVANCE_TIME',array('at'=>gmdate('Y-m-d\TH:i:s\Z',$next)));$this->deliver();}
                if(strtotime($this->snapshot()['clock'])<$target)$this->dispatch('ADVANCE_TIME',array('at'=>gmdate('Y-m-d\TH:i:s\Z',$target)));$this->deliver();$this->expire();break;
            case 'WITHDRAW':
                C\Check::fields($data,array('mode'),array('confirmation_text','confirm_privacy','email'));
                if(!$this->enrolled||(!$this->withdrawalView&&strtolower(trim($data['email']??''))!==$this->email))throw new \DomainException('PREVIEW_WITHDRAWAL_ACCESS_REQUIRED');
                if(!in_array($data['mode'],array('RETAIN_FOR_ANALYSIS','DO_NOT_USE'),true))throw new \DomainException('WITHDRAWAL_MODE');
                $w=$this->screenDefinition()['protocol_metadata']['longitudinal']['initial_screen']['withdrawal']??array();if(!empty($w['typed_confirmation'])&&($data['confirmation_text']??null)!==L\InitialScreen::withdrawalPhrase($w,$data['mode']))throw new \DomainException('WITHDRAWAL_CONFIRMATION_REQUIRED');
                $this->dispatch('WITHDRAW_EXECUTION',array('mode'=>$data['mode'],'source'=>'SIMULATION'));
                $this->review=false;$this->completion=null;$this->withdrawalView=false;foreach($this->mail as &$m)$m['revoked']=true;unset($m);
                $this->notice='Abandono registrado para el participante simulado.';break;
            case 'RESEND':C\Check::fields($data,array());if($this->snapshot()['withdrawal']!==null){$this->notice='Esta participación ya fue abandonada.';break;}if(!$this->enrolled)throw new \DomainException('PREVIEW_ENROLL_FIRST');$this->send('VERIFY_PARTICIPANT',null,'RESEND_'.count($this->mail));$this->notice='Enviamos un enlace para validar tu email.';break;
            case 'EMAIL':
                C\Check::fields($data,array('id'));$m=$this->mail[$data['id']]??null;if(!$m)throw new \DomainException('PREVIEW_EMAIL_NOT_FOUND');
                if(!empty($m['revoked'])||strtotime($this->snapshot()['clock'])>=strtotime($m['expires_at'])){$this->notice=$this->message('unavailable_title');break;}
                $verifiedNow=!$this->verified;$this->withdrawalView=false;$this->hasSession=true;if(!$this->verified){$this->verified=true;$this->dispatch('VERIFY_PARTICIPANT');}$this->inside=true;$this->presentAt=strtotime($this->snapshot()['clock']);$this->review=false;if($m['purpose']!=='VERIFY_PARTICIPANT'||$m['instance']!==null)$this->completion=null;
                $this->noticeKeys=$verifiedNow?array('email_verified'):array();$this->notice=$verifiedNow?$this->message('email_verified'):'';
                if($m['instance']!==null&&!in_array($this->snapshot()['instances'][$m['instance']]['state'],array('AVAILABLE','IN_PROGRESS'),true)){$this->noticeKeys[]='assessment_expired';$this->notice=$this->active()!==null?$this->message('email_expired_next'):$this->message('email_expired_wait');}
                break;
            case 'CONFIRM_EMAIL':
                C\Check::fields($data,array('email'),array('confirm_email_change'));if(!$this->review)throw new \DomainException('PREVIEW_EMAIL_REVIEW_UNAVAILABLE');if(!is_string($data['email'])||strlen($data['email'])>254||!filter_var($data['email'],FILTER_VALIDATE_EMAIL))throw new \DomainException('Ingresá un email válido.');if(strtolower($data['email'])!==$this->email){if(($data['confirm_email_change']??false)!==true)throw new \DomainException('EMAIL_CHANGE_CONFIRMATION_REQUIRED');foreach($this->mail as &$oldMail)$oldMail['revoked']=true;unset($oldMail);$this->verified=false;}$this->email=strtolower($data['email']);$this->reviewed=true;
                if(L\InitialScreen::reviewLink($this->config)){$this->verified=false;$this->send('VERIFY_PARTICIPANT',null,'VERIFY_REVIEW_'.count($this->mail));$this->review=false;$this->notice=$this->message('review_sent');}else{$this->review=false;$this->noticeKeys=array('review_done');$this->notice=$this->message('review_done');}break;
            case 'ANSWER':case 'NEXT':case 'BACK':case 'SUBMIT':
                $this->respond($kind,$data);break;
            default:throw new \DomainException('PREVIEW_COMMAND_DENIED');
        }
        $this->deliver();if(in_array($kind,array('ENTER','EMAIL','CONTINUE'),true)&&($active=$this->active())!==null&&$this->snapshot()['instances'][$active]['state']!=='COMPLETED')$this->dispatch('OPEN_ASSESSMENT',array('block_execution_id'=>$active));
    }
    private function specs(): array {
        if($this->studyState==='FINALIZED'||!$this->config||!$this->enrolled||$this->review||$this->required()||!$this->config['email_enabled'])return array();
        $s=$this->snapshot();if(!in_array($s['execution']['state'],array('ACTIVE','WAITING'),true))return array();$out=array();
        foreach($s['instances'] as $i){$node=$this->graph->node($i['definition_id']);if($node['completion_boundary']!=='ASSESSMENT'||!in_array($i['state'],array('SCHEDULED','AVAILABLE','IN_PROGRESS'),true)||!$i['available_at']||($s['barrier']['target_execution_id']??$i['id'])!==$i['id'])continue;
            $scheduled=$node['metadata']['scheduled_interaction']??null;if($scheduled===null&&$i['parent_execution_id']!==null)$scheduled=$this->graph->node($s['instances'][$i['parent_execution_id']]['definition_id'])['metadata']['scheduled_interaction']??null;
            $offsets=$scheduled!==null?$scheduled['reminders']:array_map(static function($m){return $m*60;},$this->config['reminder_offsets']);
            $events=array(array('ASSESSMENT_AVAILABLE',0));foreach($offsets as $offset)$events[]=array('ASSESSMENT_REMINDER',$offset);
            foreach($events as [$purpose,$seconds]){$at=strtotime($i['available_at'])+$seconds;if($i['due_at']!==null&&$at>=strtotime($i['due_at']))continue;$out[]=array('key'=>$i['id'].'|'.$purpose.'|'.$at,'purpose'=>$purpose,'instance'=>$i['id'],'at'=>$at);}
        }return $out;
    }
    private function nextTime(): ?int {
        $now=strtotime($this->snapshot()['clock']);$times=array();foreach($this->snapshot()['instances'] as $i)foreach(array('available_at','due_at') as $key)if($i[$key]!==null&&strtotime($i[$key])>$now)$times[]=strtotime($i[$key]);
        foreach($this->specs() as $e)if(!isset($this->sent[$e['key']])&&$e['at']>$now)$times[]=$e['at'];return $times?min($times):null;
    }
    private function deliver(): void {
        if($this->snapshot()['withdrawal']!==null)return;
        foreach($this->specs() as $e)if($e['at']<=strtotime($this->snapshot()['clock'])&&!isset($this->sent[$e['key']])){
            $i=$this->snapshot()['instances'][$e['instance']];if(!in_array($i['state'],array('AVAILABLE','IN_PROGRESS'),true))continue;
            if($e['purpose']==='ASSESSMENT_AVAILABLE'&&L\InitialScreen::suppressInitialAvailability($this->config,$this->snapshot(),$this->definition,$i)){$this->sent[$e['key']]=true;continue;}
            if($e['purpose']==='ASSESSMENT_AVAILABLE'&&$this->inside&&$this->presentAt!==null&&strtotime($this->snapshot()['clock'])-$this->presentAt<60&&$this->config['immediate_availability_policy']==='SUPPRESS_WHILE_PRESENT'){$this->sent[$e['key']]=true;continue;}
            $this->send($e['purpose'],$e['instance'],$e['key']);
        }
        if($this->enrolled&&$this->required())foreach($this->snapshot()['instances'] as $i)if(in_array($i['state'],array('AVAILABLE','IN_PROGRESS'),true)&&$this->graph->node($i['definition_id'])['completion_boundary']==='ASSESSMENT')$this->send('VERIFY_PARTICIPANT',$i['id'],'VERIFY_'.$i['id']);
    }
    private function send(string $purpose,?string $instance,string $key): void {
        if(isset($this->sent[$key]))return;if(count($this->mail)>=1000)throw new \DomainException('PREVIEW_EMAIL_LIMIT');$this->sent[$key]=true;$s=$this->snapshot();$i=$instance!==null?$s['instances'][$instance]:null;$node=$i?$this->graph->node($i['definition_id']):null;$id='MAIL_'.count($this->mail);
        $values=array('study_name'=>$this->definition['protocol_metadata']['study_name']??$this->definition['study']['logical_id'],'assessment_name'=>$node['metadata']['label']??'','available_time'=>L\EmailTemplate::local($i['available_at']??null,$s['execution']['timezone']??$this->definition['timezone']['default'],\EIPSI\StudyEngine\Runtime\DateFormat::read($this->definition)),'due_time'=>L\EmailTemplate::local($i['due_at']??null,$s['execution']['timezone']??$this->definition['timezone']['default'],\EIPSI\StudyEngine\Runtime\DateFormat::read($this->definition)),'direct_link'=>'https://example.invalid/dummy/'.$id,'withdrawal_link'=>'https://example.invalid/dummy/withdrawal');
        $template=L\InitialScreen::v2($this->config)&&$purpose==='VERIFY_PARTICIPANT'&&$i!==null?$this->config['availability_template']:($purpose==='ASSESSMENT_AVAILABLE'?$this->config['availability_template']:($purpose==='ASSESSMENT_REMINDER'?$this->config['reminder_template']:$purpose));
        $this->mail[$id]=array('id'=>$id,'purpose'=>$purpose,'instance'=>$instance,'to'=>$this->email,'at'=>$s['clock'],'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',strtotime($s['clock'])+($this->config['capability_hours']??48)*3600),'content'=>(new L\EmailTemplate())->render($template,$values,L\EmailTemplate::configured($this->config['initial_screen']['templates']??array())));
    }
    private function expire(): void {
        foreach($this->sessions as &$session)if(!$session['finished']&&!$session['expired']&&$session['expires']<=strtotime($this->snapshot()['clock'])){if($session['save_partial']&&!empty($session['answers']))$this->partials[]=array('at'=>$this->snapshot()['clock'],'answers'=>$session['answers'],'page'=>$session['page']);$session['answers']=array();$session['touched']=array();$session['page']=0;$session['expired']=true;}unset($session);
    }
    private function repeatAllowed(?string $id): bool {
        if($this->config===null||$id===null)return false;$s=$this->snapshot();$block=$s['instances'][$id]??null;if(!$block||$s['withdrawal']!==null||$this->required())return false;
        $now=strtotime($s['clock']);$mandatory=false;
        foreach($s['instances'] as $other){$node=$this->graph->node($other['definition_id']);if($node['completion_boundary']==='ASSESSMENT'&&in_array($other['state'],array('AVAILABLE','IN_PROGRESS'),true)&&$other['available_at']!==null&&strtotime($other['available_at'])<=$now&&($other['due_at']===null||strtotime($other['due_at'])>$now)&&($node['metadata']['mandatory']??$node['metadata']['scheduled_interaction']['mandatory']??false)===true){$mandatory=true;break;}}
        return \EIPSI\StudyEngine\Runtime\ResponsePolicy::repeatOpportunity($this->graph->node($block['definition_id']),$block,$mandatory,$now);
    }
    private function active(): ?string {if($this->studyState==='FINALIZED'||$this->withdrawalView||$this->snapshot()['withdrawal']!==null||!$this->inside||!$this->enrolled||$this->required()||$this->review||$this->completion!==null)return null;if($this->repeatInstance!==null&&$this->repeatAllowed($this->repeatInstance))return $this->repeatInstance;$action=$this->sim->nextAction();return $action['kind']==='OPEN_ASSESSMENT'?$action['payload']['assessment_execution_id']:null;}
    private function session(string $id): array {
        $i=$this->snapshot()['instances'][$id];$d=$this->native[$i['definition_id']]??null;if(!$d)throw new \DomainException('PREVIEW_NATIVE_ASSESSMENT_REQUIRED');
        if(!isset($this->sessions[$id])){$seed=hash('sha256',$this->snapshot()['execution']['id'].'|'.$id.'|'.$this->seed.(empty($this->attempts[$id])?'':'|'.$this->attempts[$id]));$this->sessions[$id]=array('save_partial'=>$d['flow']['save_continue']['enabled'],'page'=>0,'answers'=>array(),'touched'=>array(),'finished'=>false,'expired'=>false,'expires'=>strtotime($this->snapshot()['clock'])+A\Flow::retention($d),'randomization'=>array('contract'=>'LOCAL_RANDOMIZATION_LAZY_V3','seed'=>$seed,'created_at'=>strtotime($this->snapshot()['clock'])*1000,'resolved_at'=>null,'blocks'=>array(),'option_orders'=>array()));}
        $session=$this->sessions[$id];if($session['expired']){$this->notice=$d['flow']['save_continue']['enabled']?'La sesión anterior quedó guardada como parcial. Empezá desde el inicio.':'La sesión venció. Empezá desde el inicio.';$session['expired']=false;$session['expires']=strtotime($this->snapshot()['clock'])+A\Flow::retention($d);}$state=$this->snapshot()['logical_trajectory']??array();$nav=$state['navigation']??null;if($nav&&($nav['target_execution_id']??null)===$id&&($session['navigation']??null)!==($nav['transition_id']??$nav['condition_id'])){$session['page']=$nav['entry_page'];$session['navigation']=$nav['transition_id']??$nav['condition_id'];}
        $controls=array();foreach($state['random_controls']??array() as $key=>$v)if(strpos($key,$i['definition_id'].'|')===0)$controls[substr($key,strlen($i['definition_id'])+1)]=$v;
        $session['randomization']=A\Randomization::reach($d,$session['randomization'],$session['page'],strtotime($this->snapshot()['clock'])*1000,$controls,0,$state['native_plans'][$id]??array());
        $keys=array();$collect=function($items)use(&$collect,&$keys){foreach($items as $e){if($e['response_key'])$keys[$e['response_key']]=true;if($e['type']==='matrix')foreach($e['properties']['matrix_rows'] as $row)$keys[$row['response_key']]=true;$collect($e['children']);}};$collect(A\Randomization::apply($d,$session['randomization'])['elements']);$session['answers']=array_intersect_key($session['answers'],$keys);$session['touched']=array_intersect_key($session['touched'],$keys);$this->sessions[$id]=$session;return $session;
    }
    private function respond(string $kind,array $data): void {
        C\Check::fields($data,array('answers','touched'),array('instance'));if(!is_array($data['answers'])||!is_array($data['touched']))throw new \DomainException('INVALID_PREVIEW_ANSWERS');if(isset($data['instance'])){C\Check::id($data['instance'],'instance');if($kind==='SUBMIT'&&!empty($this->sessions[$data['instance']]['finished']))return;}$id=$this->active();if($id===null||isset($data['instance'])&&$data['instance']!==$id)throw new \DomainException('PREVIEW_ASSESSMENT_UNAVAILABLE');$this->expire();$session=$this->session($id);$i=$this->snapshot()['instances'][$id];$d=$this->native[$i['definition_id']];
        if($session['expired']){$this->notice=$d['flow']['save_continue']['enabled']?'La sesión anterior quedó guardada como parcial. Empezá desde el inicio.':'La sesión venció. Empezá desde el inicio.';$session['expired']=false;$session['expires']=strtotime($this->snapshot()['clock'])+A\Flow::retention($d);$this->sessions[$id]=$session;return;}
        if($kind==='BACK'&&(!$d['flow']['allow_back']||$session['page']<=0))throw new \DomainException('PREVIEW_BACK_UNAVAILABLE');
        if($kind==='NEXT'&&$session['page']>=count($d['flow']['pages'])-1)throw new \DomainException('PREVIEW_FINAL_PAGE_REQUIRED');if($kind==='SUBMIT'&&$session['page']!==count($d['flow']['pages'])-1)throw new \DomainException('PREVIEW_FINAL_PAGE_REQUIRED');
        $resolved=A\Randomization::apply($d,$session['randomization']);$validation=$resolved;$skipped=$this->snapshot()['logical_trajectory']['question_states'][$i['definition_id']]??array();$optional=function(&$items)use(&$optional,$skipped){foreach($items as &$e){if(($skipped[$e['id']]??null)==='skipped_by_logic'){if(isset($e['properties']['required']))$e['properties']['required']=false;if($e['type']==='multiple_choice')$e['properties']['min_selected']=0;}if($e['type']==='matrix')foreach($e['properties']['matrix_rows'] as &$r)if(($skipped[$r['id']]??null)==='skipped_by_logic')$r['required']=false;unset($r);$optional($e['children']);}unset($e);};$optional($validation['elements']);$page=0;$walk=function(&$items,$current)use(&$walk,$session,$kind){foreach($items as &$e){if(!in_array($kind,array('NEXT','SUBMIT'),true)||($kind==='NEXT'&&$current!==$session['page'])){if(isset($e['properties']['required']))$e['properties']['required']=false;if($e['type']==='multiple_choice')$e['properties']['min_selected']=0;if($e['type']==='matrix')foreach($e['properties']['matrix_rows'] as &$r)$r['required']=false;unset($r);}$walk($e['children'],$current);}unset($e);};foreach($validation['elements'] as &$e){if($e['type']==='page_break')$page++;$items=array($e);$walk($items,$page);$e=$items[0];}unset($e);
        $requiredValidation=$validation;$noRequired=function(&$items)use(&$noRequired){foreach($items as &$e){if(isset($e['properties']['required']))$e['properties']['required']=false;if($e['type']==='multiple_choice')$e['properties']['min_selected']=0;if($e['type']==='matrix')foreach($e['properties']['matrix_rows'] as &$r)$r['required']=false;unset($r);$noRequired($e['children']);}unset($e);};$noRequired($validation['elements']);$errors=A\Answers::errors($validation,$data['answers'],$data['touched']);if($errors)throw new \DomainException($errors[0]['message']);
        $this->presentAt=strtotime($this->snapshot()['clock']);if($i['state']!=='COMPLETED')$this->dispatch('OPEN_ASSESSMENT',array('block_execution_id'=>$id));$old=$session['answers'];$session['answers']=$data['answers'];$session['touched']=$data['touched'];$session['expires']=strtotime($this->snapshot()['clock'])+A\Flow::retention($d);$this->sessions[$id]=$session;
        $catalog=$this->graph->node($i['definition_id'])['metadata']['native_questions']??array();$selected=array();$values=array();$scan=function($items)use(&$scan,&$selected,&$values,$session){foreach($items as $e){$selected[$e['id']]=true;if($e['response_key']&&array_key_exists($e['response_key'],$session['answers']))$values[$e['id']]=A\Answers::value($e,$session['answers'][$e['response_key']]);if($e['type']==='matrix')foreach($e['properties']['matrix_rows'] as $r){$selected[$r['id']]=true;if(array_key_exists($r['response_key'],$session['answers']))$values[$r['id']]=A\Answers::value(array_replace($e,array('type'=>'likert')),$session['answers'][$r['response_key']]);}$scan($e['children']);}};$scan($resolved['elements']);
        $states=$presence=$changed=array();foreach($catalog as $q){$chosen=isset($selected[$q['id']]);$excluded=false;foreach($q['randomization_path']??array() as $path){$draw=$session['randomization']['blocks'][$path['randomization_id']]??null;if($draw&&!in_array($path['candidate_id'],$draw['order'],true))$excluded=true;}$unresolved=!$excluded&&$q['randomization_id']!==null&&!isset($session['randomization']['blocks'][$q['randomization_id']]);$previousState=$this->snapshot()['logical_trajectory']['question_states'][$i['definition_id']][$q['id']]??null;$states[$q['id']]=$previousState==='skipped_by_logic'?'skipped_by_logic':($unresolved?'not_reached':(!$chosen?'not_selected_by_randomization':(isset($values[$q['id']])&&$values[$q['id']]!==''?'answered':($q['page']>$session['page']?'not_reached':'unanswered'))));$presence[$q['id']]=array('selected'=>$unresolved?null:$chosen,'shown'=>$chosen&&$q['page']<=$session['page']);if(($old[$q['response_key']]??null)!==($session['answers'][$q['response_key']]??null))$changed[]=$q['id'];}
        $logical=array('block_execution_id'=>$id,'answers'=>$values,'question_states'=>$states,'question_presence'=>$presence,'changed_questions'=>$changed,'native_randomizations'=>array($i['definition_id']=>$session['randomization']),'event'=>'ANSWER','transition_id'=>'DUMMY_LOGIC_'.($this->serial+1));$this->dispatch('NATIVE_LOGIC',$logical);
        $firstNav=$this->snapshot()['logical_trajectory']['navigation']??null;if($firstNav&&($firstNav['transition_id']??null)===$logical['transition_id']&&in_array($firstNav['type'],array('JUMP','WAIT','END_ASSESSMENT','GO_QUESTION'),true)){$this->sessions[$id]['finished']=$firstNav['type']!=='GO_QUESTION';if($firstNav['type']==='GO_QUESTION')$this->sessions[$id]['page']=$firstNav['entry_page'];return;}
        if($kind==='SUBMIT'){$logical['event']='COMPLETE';$logical['changed_questions']=array_keys($states);$logical['transition_id']='DUMMY_LOGIC_'.($this->serial+1);$this->dispatch('NATIVE_LOGIC',$logical);}
        $nav=$this->snapshot()['logical_trajectory']['navigation']??null;$exit=$nav&&($nav['transition_id']??null)===$logical['transition_id']&&($nav['source_execution_id']??null)===$id&&in_array($nav['type'],array('JUMP','WAIT','END_ASSESSMENT'),true);
        if($exit){$this->sessions[$id]['finished']=true;return;}
        if($nav&&($nav['transition_id']??null)===$logical['transition_id']&&$nav['type']==='GO_QUESTION'){$this->sessions[$id]['page']=$nav['entry_page'];return;}
        $errors=A\Answers::errors($requiredValidation,$data['answers'],$data['touched']);if($errors)throw new \DomainException($errors[0]['message']);
        if($kind==='SUBMIT'){if($i['state']!=='COMPLETED')$this->dispatch('SUBMIT_ASSESSMENT',array('block_execution_id'=>$id,'responses'=>array()));$this->sessions[$id]['finished']=true;$this->completionInstance=$id;$this->repeatInstance=null;$this->completion=$d['flow']['completion'];if($this->config&&!$this->verified&&!$this->reviewed&&L\InitialScreen::reviewRequired($this->config)&&L\InitialScreen::available($this->snapshot(),$this->definition,$this->snapshot()['first_access_at']??$this->snapshot()['execution']['first_access_at']))$this->review=true;}
        elseif($kind==='NEXT'){$this->sessions[$id]['page']=min(count($d['flow']['pages'])-1,$session['page']+1);}
        elseif($kind==='BACK')$this->sessions[$id]['page']--;
    }
    private function displayDefinition(string $id): array {
        $i=$this->snapshot()['instances'][$id];$d=$this->native[$i['definition_id']];$states=$this->snapshot()['logical_trajectory']['question_states'][$i['definition_id']]??array();
        $walk=function(&$items)use(&$walk,$states){$items=array_values(array_filter($items,static function($e)use($states){return ($states[$e['id']]??null)!=='skipped_by_logic';}));foreach($items as &$e){if($e['type']==='matrix')$e['properties']['matrix_rows']=array_values(array_filter($e['properties']['matrix_rows'],static function($r)use($states){return ($states[$r['id']]??null)!=='skipped_by_logic';}));$walk($e['children']);}unset($e);};$walk($d['elements']);$format=\EIPSI\StudyEngine\Runtime\DateFormat::read($this->definition);$dates=function(&$items)use(&$dates,$format){foreach($items as &$item){if($item['type']==='date')$item['properties']['date_format']=$format;$dates($item['children']);}unset($item);};$dates($d['elements']);return $d;
    }
    /** Match the Initial Screen editor's unsaved defaults for older drafts, without rewriting their protocol. */
    private function screenDefinition(): array {
        $d=$this->definition;if($this->config!==null&&!isset($d['protocol_metadata']['longitudinal']['initial_screen'])){
            $screen=L\InitialScreen::defaults();$screen['withdrawal']['typed_confirmation']=true;
            $d['protocol_metadata']['longitudinal']['initial_screen']=$screen;
        }return $d;
    }
    private function frame(): array {
        $this->expire();$s=$this->snapshot();$id=$this->active();$assessment=null;if($id!==null){$node=$this->graph->node($s['instances'][$id]['definition_id']);if(isset($this->native[$node['id']]))$assessment=array('instance'=>$id,'definition'=>$this->displayDefinition($id),'session'=>$this->session($id));else throw new \DomainException('PREVIEW_NATIVE_ASSESSMENT_REQUIRED');}
        $withdrawalHtml=null;if($this->config&&$this->withdrawalView)$withdrawalHtml=(new L\EmailTemplate())->screen(array('study'=>$this->definition['study'],'definition'=>$this->screenDefinition(),'shell'=>'WITHDRAWAL_OPTIONS','withdrawal'=>$s['withdrawal'],'view'=>array('revision'=>$s['execution']['revision']),'csrf'=>'DUMMY'));
        $portal=array('notice_keys'=>$this->noticeKeys,'email_verified'=>$this->verified,'portal_view'=>$this->enrolled&&!$this->required()?array('snapshot'=>$s,'next_action'=>$this->sim->nextAction()):array());
        $initial=null;if($this->config)$initial=(new L\EmailTemplate())->screen($portal+array('study'=>$this->definition['study'],'definition'=>$this->screenDefinition(),'entry'=>true,'message'=>null,'email'=>$this->hasSession?$this->email:'','withdrawal_revision'=>$s['execution']['revision'],'csrf'=>'DUMMY'));
        $landing=$this->config?(new L\EmailTemplate())->screen(array('study'=>$this->definition['study'],'definition'=>$this->screenDefinition(),'entry'=>true,'message'=>null,'email'=>'','withdrawal_email'=>$this->hasSession?$this->email:'','csrf'=>'DUMMY')):null;
        $statusHtml=null;if($this->enrolled&&$this->sim->nextAction()['kind']!=='OPEN_ASSESSMENT')$statusHtml=(new L\EmailTemplate())->screen($portal+array('study'=>array_merge($this->definition['study'],array('allow_restart'=>0)),'definition'=>$this->screenDefinition(),'longitudinal'=>true,'access'=>array('execution_id'=>$s['execution']['id']),'view'=>array('revision'=>$s['execution']['revision'],'snapshot'=>$s,'next_action'=>$this->sim->nextAction()),'csrf'=>'DUMMY'));
        $reviewHtml=$this->review?(new L\EmailTemplate())->screen($portal+array('study'=>$this->definition['study'],'definition'=>$this->screenDefinition(),'csrf'=>'DUMMY','view'=>array('revision'=>$s['execution']['revision']),'email_confirmation'=>array('email'=>$this->email,'pending'=>false,'requires_link'=>L\InitialScreen::reviewLink($this->config)))):null;
        if(in_array($this->studyState,array('PAUSED','FINALIZED'),true)){$initial=\EIPSI\StudyEngine\Runtime\PublicStudyRepository::availabilityHtml($this->definition,$this->studyState);$statusHtml=$initial;$assessment=null;}
        return array('public_messages'=>\EIPSI\StudyEngine\Runtime\PublicMessages::all($this->definition),'study_state'=>$this->studyState,'study_cycle'=>$this->studyCycle,'archived_cycles'=>count($this->archives),'status_html'=>$statusHtml,'review_html'=>$reviewHtml,'show_next_time'=>$this->config['show_next_time']??false,'review_requires_link'=>$this->config?L\InitialScreen::reviewLink($this->config):false,'withdrawal_completion_html'=>$s['withdrawal']!==null?L\InitialScreen::withdrawalCompletion($this->config['initial_screen']['withdrawal']??array(),$s['withdrawal']['mode']):null,'withdrawal_html'=>$withdrawalHtml,'simulation_only'=>true,'snapshot'=>$s,'next_action'=>$this->sim->nextAction(),'next_event'=>$this->nextTime()!==null?gmdate('Y-m-d\TH:i:s\Z',$this->nextTime()):null,'enrolled'=>$this->enrolled,'inside'=>$this->inside,'verified'=>$this->verified,'verification_required'=>$this->required(),'review'=>$this->review,'email'=>$this->email,'notice'=>$this->notice,'notice_in_portal'=>!empty($this->noticeKeys),'initial_html'=>$initial,'landing_html'=>$landing,'assessment'=>$assessment,'completion'=>$this->completion,'completion_can_repeat'=>$this->repeatAllowed($this->completionInstance),'saved_partials'=>$this->partials,'emails'=>array_values($this->mail));
    }
}
