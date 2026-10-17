<?php
namespace EIPSI\StudyEngine\Orchestration;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Contracts\Canonical;
use EIPSI\StudyEngine\Contracts\Check;
use EIPSI\StudyEngine\Contracts\StudyExecution;
use EIPSI\StudyEngine\Engine\Simulator;
use EIPSI\StudyEngine\Engine\CompiledStudy;

/** Sole transition owner. No HTTP/auth/render/mail or duplicated engine decisions. */
final class ExecutionOwner {
    private $db;
    private $vault;
    private $observer;
    private $versions;
    private $executions;
    private $blocks;
    private $events;
    private $random;
    private $commands;
    private $intents;
    private $withdrawals;
    private $withdrawing=false;
    private $journal;
    private $responseSink;
    private $joiningAdmission=false;
    private $clock;
    /** Caller owns Native and Study mutations on the same verified SQL connection. */
    public function joinNative(callable $work): array {
        $this->db->requireTransaction();if($this->joiningAdmission)throw new \LogicException('NESTED_COMPOSITION');
        $this->joiningAdmission=true;try{return $work($this);}finally{$this->joiningAdmission=false;}
    }
    /** Trusted composition API: credentials and science join one admission transaction. */
    public function admit(callable $admission): array {
        return $this->db->transactional(function()use($admission){
            $this->joiningAdmission=true;
            try{return $admission(function(string $version,array $subject,string $key,array $context=array()): array {return $this->create($version,$subject,$key,$context);});}
            finally{$this->joiningAdmission=false;}
        });
    }
    public function __construct(P\Database $db,P\SeedVault $vault,?P\TransitionObserver $observer=null,?P\FinalResponseSink $responseSink=null,?callable $clock=null) {
        $this->clock=$clock??static function(){return gmdate('Y-m-d\TH:i:s\Z');};
        $this->db=$db;$this->vault=$vault;$this->responseSink=$responseSink;$this->observer=$observer??new P\TransitionObserver();
        $this->versions=new P\StudyVersionRepository($db);$this->executions=new P\StudyExecutionRepository($db);$this->blocks=new P\BlockExecutionRepository($db);$this->events=new P\ExecutionEventRepository($db);$this->random=new P\RandomDecisionRepository($db);$this->commands=new P\CommandRepository($db);$this->intents=new P\NotificationIntentRepository($db);$this->withdrawals=new P\WithdrawalRepository($db);$this->journal=new P\SubmissionJournalRepository($db);
    }
    public function create(string $versionId,array $subject,string $requestKey,array $context=array()): array {
        Check::id($versionId,'version_id');Check::id($requestKey,'request_key');Check::fields($subject,array('kind','id'));Check::id($subject['id'],'subject.id');
        if(!in_array($subject['kind'],array('PARTICIPANT','ANONYMOUS'),true))throw new \DomainException('INVALID_SUBJECT_KIND');
        Check::fields($context,array(),array('created_at','timezone','timezone_source','max_instances','eager_limit','horizon_days'));
        if(isset($context['timezone_source'])&&!in_array($context['timezone_source'],array('PARTICIPANT_LOCAL','STUDY_FALLBACK'),true))throw new \DomainException('INVALID_TIMEZONE_SOURCE');
        if(isset($context['created_at']))Check::timestamp($context['created_at'],'created_at',false);
        if(isset($context['timezone']))Check::timezone($context['timezone'],'timezone');
        $key=hash('sha256',$requestKey);$hash=hash('sha256',Canonical::json(array('version'=>$versionId,'subject'=>$subject,'context'=>$context)));
        try{$work=function()use($versionId,$subject,$key,$hash,$context){
            // Version lock serializes admission/retirement and same-version creation.
            $v=$this->versions->find($versionId,true);if(!$v)throw new P\IntegrityFailure('VERSION_NOT_FOUND');
            $prior=$this->executions->request($key);if($prior)return $this->createResult($prior,$hash);
            if($v['state']!=='PUBLISHED')throw new P\PersistenceConflict('VERSION_NOT_PUBLISHED');
            $graph=$this->versions->compiled($v);$id='SE_'.bin2hex(random_bytes(16));$seed=$this->vault->create($id);
            $ctx=$context;$ctx['execution_id']=$id;$ctx['created_at']=$context['created_at']??gmdate('Y-m-d\TH:i:s\Z');$ctx['seed_hex']=$seed['seed_hex'];$ctx['seed_ref']='SEED_'.$id;
            $sim=new Simulator($graph,$ctx);$s=$sim->snapshot();$result=array('execution_id'=>$id,'revision'=>0,'next_action'=>$sim->nextAction(),'event_count'=>count($s['events']));
            $public=$this->db->row($this->db->prepare('SELECT enrollment_state,study_cycle FROM '.$this->db->table('public_studies').' WHERE study_id=%s',$v['study_id']));if(($public['enrollment_state']??null)==='FINALIZED')throw new P\PersistenceConflict('STUDY_FINALIZED');
            $row=$this->executionRow($sim);$row['study_cycle']=(int)($public['study_cycle']??1);$row+=array('id'=>$id,'study_id'=>$v['study_id'],'study_version_id'=>$versionId,'participant_id'=>$subject['kind']==='PARTICIPANT'?$subject['id']:null,'anonymous_identity_id'=>$subject['kind']==='ANONYMOUS'?$subject['id']:null,'seed_key_id'=>$seed['key_id'],'seed_ciphertext'=>$seed['ciphertext'],'create_key'=>$key,'create_hash'=>$hash,'create_result_json'=>Canonical::json($result));
            $this->executions->create($row);$this->persist(null,$sim,$subject,null,array(),null,$versionId);
            $this->observer->reached('before_commit');return $result;
        };return $this->joiningAdmission?$work():$this->db->transactional($work);}catch(P\IntegrityFailure $e){if($this->joiningAdmission)throw $e;return $this->operational($e->getMessage());}
        catch(P\DatabaseFailure $e){
            if($this->joiningAdmission)throw $e;
            if($e->getCode()===1062){$prior=$this->executions->request($key);if($prior)return $this->createResult($prior,$hash);}
            return $this->operational('STUDY_ENGINE_DATABASE_ERROR',true);
        }
    }
    private function createResult(array $row,string $hash): array {
        if(!hash_equals($row['create_hash'],$hash))throw new P\PersistenceConflict('CREATE_KEY_REUSED');
        $result=P\Database::json($row['create_result_json']);
        try{
            Check::fields($result,array('execution_id','revision','next_action','event_count'));
            new \EIPSI\StudyEngine\Contracts\NextAction($result['next_action']);Check::integer($result['event_count'],'event_count');
            if($result['execution_id']!==$row['id']||$result['revision']!==0)throw new \DomainException('INVALID_CREATE_RESULT');
        }catch(\Throwable $e){throw new P\IntegrityFailure('CREATE_RESULT_CORRUPTED');}
        return $result;
    }
    private function executionRow(Simulator $sim): array {
        $s=$sim->snapshot();$checkpoint=$sim->checkpoint();$hash=hash('sha256',Canonical::json($checkpoint));
        unset($checkpoint['state']['instances'],$checkpoint['state']['events'],$checkpoint['state']['decisions']);
        $e=$s['execution'];
        return array('state'=>$e['state'],'timezone'=>$e['timezone'],'timezone_source'=>$e['timezone_source'],'schedule_revision'=>$s['schedule_revision'],'revision'=>$e['revision'],'event_sequence'=>count($s['events']),'created_at'=>P\Database::date($e['created_at']),'started_at'=>P\Database::date($e['started_at']),'first_access_at'=>P\Database::date($e['first_access_at']),'updated_at'=>P\Database::now(),'wait_json'=>$s['barrier']===null?null:Canonical::json($s['barrier']),'cursor_json'=>$s['cursor']===null?null:Canonical::json($s['cursor']),'outcome_json'=>Canonical::json($sim->outcome()),'terminal_reason'=>$e['terminal_decision']??(in_array($e['state'],array('COMPLETED','WITHDRAWN'),true)?$e['state']:null),'checkpoint_json'=>Canonical::json($checkpoint),'checkpoint_hash'=>$hash);
    }
    private function hydrate(array $row): Simulator {
        $v=$this->versions->find($row['study_version_id']);if(!$v||!in_array($v['state'],array('PUBLISHED','RETIRED'),true))throw new P\IntegrityFailure('PINNED_VERSION_UNAVAILABLE');
        $graph=$this->versions->compiled($v);$c=P\Database::json($row['checkpoint_json']);
        $c['state']['instances']=$this->blocks->load($row['id'],$row['study_version_id']);$c['state']['events']=$this->events->load($row['id']);$c['state']['decisions']=$this->random->load($row['id']);
        if(!hash_equals($row['checkpoint_hash'],hash('sha256',Canonical::json($c))))throw new P\IntegrityFailure('CHECKPOINT_HASH_MISMATCH');
        $e=$c['state']['execution'];
        if($e['id']!==$row['id']||$e['version_id']!==$v['definition_id']||$e['study_id']!==$row['study_id']||$e['study_id']!==$v['study_id']||$e['state']!==$row['state']||$e['revision']!==(int)$row['revision']||$c['state']['scheduleRevision']!==(int)$row['schedule_revision']||count($c['state']['events'])!==(int)$row['event_sequence']||$e['timezone']!==$row['timezone'])throw new P\IntegrityFailure('EXECUTION_BINDING_MISMATCH');
        foreach(array('created_at','started_at','first_access_at') as $field)if(P\Database::date($e[$field])!==$row[$field])throw new P\IntegrityFailure('EXECUTION_TIME_MISMATCH');
        if($row['cursor_json']!==($c['state']['cursor']===null?null:Canonical::json($c['state']['cursor']))||$row['wait_json']!==($c['state']['barrier']===null?null:Canonical::json($c['state']['barrier'])))throw new P\IntegrityFailure('EXECUTION_PROJECTION_MISMATCH');
        $seed=$this->vault->open($row['id'],$row['seed_key_id'],$row['seed_ciphertext']);
        try{$sim=Simulator::restore($graph,$c,$seed);$this->validateExecution($sim,$this->subject($row));if($row['outcome_json']!==Canonical::json($sim->outcome()))throw new P\IntegrityFailure('OUTCOME_PROJECTION_MISMATCH');return $sim;}catch(P\IntegrityFailure $e){throw $e;}catch(\Throwable $e){throw new P\IntegrityFailure('DURABLE_STATE_INVALID');}
    }
    private function subject(array $row): array {
        if(($row['participant_id']===null)===($row['anonymous_identity_id']===null))throw new P\IntegrityFailure('SUBJECT_BINDING_INVALID');
        return array('kind'=>$row['participant_id']===null?'ANONYMOUS':'PARTICIPANT','id'=>$row['participant_id']??$row['anonymous_identity_id']);
    }
    private function validateExecution(Simulator $sim,array $subject): void {
        $s=$sim->snapshot();$e=$s['execution'];$wait=$s['barrier'];if($wait!==null)unset($wait['target_execution_id'],$wait['decision_id'],$wait['rule']);
        new StudyExecution(array('id'=>$e['id'],'study_id'=>$e['study_id'],'version_id'=>$e['version_id'],'subject'=>$subject,'timezone'=>$e['timezone'],'created_at'=>$e['created_at'],'started_at'=>$e['started_at'],'first_access_at'=>$e['first_access_at'],'state'=>$e['state'],'revision'=>$e['revision'],'wait_context'=>$wait,'cursor'=>$s['cursor']));
    }
    private function locked(string $id): array {$row=$this->executions->find($id,true);if(!$row)throw new P\IntegrityFailure('EXECUTION_NOT_FOUND');return $row;}
    public function view(string $id): array {
        try{$work=function()use($id){$row=$this->locked($id);$this->observer->reached('read_authorization');
            if(!empty($row['privacy_tombstone']))return $this->tombstone($row);
            $sim=$this->hydrate($row);return array('study_finalized_at'=>$row['finalized_at'],'study_cycle'=>(int)$row['study_cycle'],'revision'=>$sim->snapshot()['execution']['revision'],'next_action'=>$sim->nextAction(),'outcome'=>$sim->outcome(),'snapshot'=>$sim->snapshot(),'presentation'=>$sim->nextAction()['kind']==='OPEN_ASSESSMENT'?$sim->presentation($sim->nextAction()['payload']['assessment_execution_id']):null);};return $this->joiningAdmission?$work():$this->db->transactional($work);}
        catch(P\IntegrityFailure $e){return $this->operational($e->getMessage());}
        catch(P\DatabaseFailure $e){return $this->operational('STUDY_ENGINE_DATABASE_ERROR',true);}
    }
    /** Product withdrawal: reauthorize under the same lock as submit and exports. */
    public function withdrawExecution(string $id,string $mode,string $command,int $revision,\EIPSI\StudyEngine\Privacy\AccessContext $access): array {
        require_once dirname(__DIR__).'/privacy.php';
        if(!in_array($mode,array('RETAIN_FOR_ANALYSIS','DO_NOT_USE'),true))throw new \DomainException('WITHDRAWAL_MODE');
        return $this->admit(function()use($id,$mode,$command,$revision,$access){
            $row=$this->locked($id);$access->authorize($row);$this->observer->reached('withdrawal_authorized');$this->withdrawing=true;
            try{return $this->dispatch($id,array('id'=>$command,'kind'=>'WITHDRAW_EXECUTION','expected_revision'=>$revision,'data'=>array('mode'=>$mode,'source'=>$access->source())),array(),null,true);}
            finally{$this->withdrawing=false;}
        });
    }
    public function dispatch(string $id,array $command,array $notificationIntents=array(),?string $externalDestination=null,bool $useServerClock=false): array {
        if(($command['kind']??null)==='WITHDRAW_EXECUTION'&&!$this->withdrawing)throw new \DomainException('WITHDRAWAL_ACCESS_CONTEXT_REQUIRED');
        Check::fields($command,array('id','kind'),array('data','expected_revision'));Check::id($command['id'],'command.id');Check::id($command['kind'],'command.kind');
        if($externalDestination!==null)Check::id($externalDestination,'destination_ref');
        $readOnly=in_array($command['kind'],array('RELOAD','CAPABILITY_OPENED','CREATE_EXECUTION'),true);
        if(!$readOnly&&!isset($command['expected_revision']))throw new P\PersistenceConflict('EXPECTED_REVISION_REQUIRED');
        if(isset($command['expected_revision']))Check::integer($command['expected_revision'],'expected_revision');
        if($command['kind']==='CREATE_EXECUTION'&&!empty($command['data']))throw new \DomainException('CREATE_PROJECTION_HAS_NO_DATA');
        if($readOnly&&($notificationIntents||$externalDestination!==null))throw new \DomainException('READ_COMMAND_CANNOT_WRITE_INTENTS');
        $hash=hash('sha256',Canonical::json(array('command'=>$command,'notification_intents'=>$notificationIntents,'external_destination'=>$externalDestination)));
        if($useServerClock)$hash=hash('sha256',$hash.'|SERVER_CLOCK_V1');
        try{$work=function()use($id,$command,$hash,$notificationIntents,$externalDestination,$useServerClock,$readOnly){
            $row=$this->locked($id);if($row['finalized_at']!==null)throw new P\PersistenceConflict('STUDY_FINALIZED');$this->observer->reached('before_receipt');$prior=$this->commands->find($id,$command['id']);
            if($prior)return $this->commands->result($prior,$hash);
            if(isset($command['expected_revision'])&&$command['expected_revision']!==(int)$row['revision'])throw new P\PersistenceConflict('REVISION_CONFLICT');
            $withdrawal=$this->withdrawals->find($id);
            if($command['kind']==='WITHDRAW_EXECUTION'&&$withdrawal&&$withdrawal['data_policy']==='DO_NOT_USE'&&$command['data']['mode']!=='DO_NOT_USE')throw new P\PersistenceConflict('PRIVACY_POLICY_IRREVERSIBLE');
            if($command['kind']==='WITHDRAW_EXECUTION'&&$withdrawal&&$withdrawal['mode']===$command['data']['mode']){
                $result=array('revision'=>(int)$row['revision'],'next_action'=>array('kind'=>'WITHDRAWN','payload'=>array()),'event_count'=>(int)$row['event_sequence']);
                $this->commands->claim($id,$command,$hash,(int)$row['revision']);$this->commands->finish($id,$command['id'],$result);return $result;
            }
            if(!empty($row['privacy_tombstone']))throw new P\PersistenceConflict('PRIVACY_TOMBSTONE_TERMINAL');
            if($withdrawal&&($row['data_policy']??'USE_ALLOWED')!=='USE_ALLOWED'&&$command['kind']!=='WITHDRAW_EXECUTION'&&!$readOnly)throw new P\PersistenceConflict('EXECUTION_WITHDRAWN');
            $sim=$this->hydrate($row);$before=$sim->snapshot();
            if(in_array($command['kind'],array('SUBMIT_ASSESSMENT','SUBMIT_PAGE'),true)){
                $blockId=$command['data']['block_execution_id']??null;$instance=$before['instances'][$blockId]??null;
                if($instance===null&&isset($command['data']['node_id']))foreach($before['instances'] as $candidate)if($candidate['definition_id']===$command['data']['node_id']&&isset($sim->compiled()->node($candidate['definition_id'])['metadata']['native_version_id']))throw new \DomainException('NATIVE_ASSESSMENT_COMPLETION_REQUIRED');
                if($instance){$definition=$sim->compiled()->node($instance['definition_id']);$native=$definition['metadata']['native_version_id']??null;
                    if($native!==null){$proof=$this->db->row($this->db->prepare('SELECT id FROM '.$this->db->table('native_executions')." WHERE version_id=%s AND state='COMPLETED' AND JSON_UNQUOTE(JSON_EXTRACT(binding_json,'$.execution_id'))=%s AND JSON_UNQUOTE(JSON_EXTRACT(binding_json,'$.block_execution_id'))=%s LIMIT 1 FOR UPDATE",$native,$id,$blockId));if(!$proof)throw new \DomainException('NATIVE_ASSESSMENT_COMPLETION_REQUIRED');}
                }
            }

            $this->commands->claim($id,$command,$hash,(int)$row['revision']);$this->observer->reached('after_command_claim');
            $engineCommand=$command;
            if($useServerClock&&!in_array($command['kind'],array('RELOAD','CAPABILITY_OPENED','CREATE_EXECUTION'),true)){$now=($this->clock)();Check::timestamp($now,'server_clock',false);$engineCommand['data']['at']=max($now,$before['clock']);}
            $result=$sim->dispatch($engineCommand);$this->validateExecution($sim,$this->subject($row));
            $this->persist($before,$sim,$this->subject($row),$command['id'],$notificationIntents,$externalDestination,$row['study_version_id']);
            $this->executions->commitTransition($id,(int)$row['revision'],$this->executionRow($sim));
            if($command['kind']==='WITHDRAW_EXECUTION'){
                \EIPSI\StudyEngine\Privacy\Withdrawal::apply($this->db,$row,$command['data']['mode']);
                $this->observer->reached('before_withdrawal_commit');
            }
            $this->commands->finish($id,$command['id'],$result);$this->observer->reached('before_commit');return $result;
        };return $this->joiningAdmission?$work():$this->db->transactional($work);}catch(P\IntegrityFailure $e){if($this->joiningAdmission)throw $e;return $this->operational($e->getMessage());}catch(P\DatabaseFailure $e){if($this->joiningAdmission)throw $e;return $this->operational('STUDY_ENGINE_DATABASE_ERROR',true);}
    }
    private function persist(?array $before,Simulator $sim,array $subject,?string $command,array $intents,?string $external,string $versionId): void {
        $s=$sim->snapshot();$id=$s['execution']['id'];$graph=$sim->compiled();
        // A final assessment requires this local confirmation in the SAME commit as scientific payload.
        foreach($s['instances'] as $block){if($block['state']==='COMPLETED'&&($before['instances'][$block['id']]['state']??null)!=='COMPLETED'&&$graph->node($block['definition_id'])['completion_boundary']==='ASSESSMENT'){
            $responses=$s['responses'][$block['id']]??array();
            $ref=$this->responseSink===null?null:$this->responseSink->store($this->db,$id,$block,$responses,$versionId,$s);
            $this->journal->confirmLocal($id,$block,$responses,$versionId,$external,$ref);
        }}
        $this->blocks->persist($before['instances']??array(),$s['instances'],$versionId);
        $this->random->persist($before['random_decisions']??array(),$s['random_decisions'],$graph,$this->observer);
        $this->observer->reached('before_event_commit');$last=count($before['events']??array());
        if($last&&Canonical::json(array_slice($s['events'],0,$last))!==Canonical::json($before['events']))throw new P\IntegrityFailure('EVENT_HISTORY_IMMUTABLE');
        $this->events->append($id,$last,array_slice($s['events'],$last),$command);
        $this->withdrawals->persist($id,$subject,$before['withdrawal']??null,$s['withdrawal']);
        $this->observer->reached('before_outbox_commit');$this->intents->invalidate($id,$s);foreach($intents as $spec)$this->intents->enqueue($id,$spec,$s);
        if(($graph->definition()['protocol_metadata']['enrollment_mode']??'ANONYMOUS')==='EMAIL_LONGITUDINAL'){
            require_once dirname(__DIR__).'/longitudinal.php';
            (new \EIPSI\StudyEngine\Longitudinal\NotificationScheduler($this->db))->synchronize($s,$graph,$subject,$versionId);
        }
    }
    private function tombstone(array $row): array {
        $t=P\Database::json($row['checkpoint_json']);
        if(($t['contract']??null)!=='P1G_TOMBSTONE_V1'||!hash_equals($row['checkpoint_hash'],hash('sha256',Canonical::json($t)))||$t['execution_id']!==$row['id']||$t['state']!==$row['state'])throw new P\IntegrityFailure('PRIVACY_TOMBSTONE_CORRUPTED');
        return array('revision'=>(int)$row['revision'],'next_action'=>array('kind'=>'WITHDRAWN','payload'=>array('data_policy'=>'DO_NOT_USE')),'outcome'=>array('data_policy'=>'DO_NOT_USE'),'privacy_tombstone'=>true);
    }
    private function operational(string $code,bool $retryable=false): array {return array('next_action'=>array('kind'=>'OPERATIONAL_ERROR','payload'=>array('code'=>$code,'retryable'=>$retryable)));}
}
