<?php
namespace EIPSI\StudyEngine\Runtime;
use EIPSI\StudyEngine\Persistence as P;

/** Fixed failure taxonomy, no exception messages, request payloads or trace arguments in logs. */
final class PublicRuntimeFailure extends \RuntimeException {}
final class RuntimeDiagnostics {
    private $phase='PUBLIC_ENTRY';
    private $study=null;
    private $hash;
    private $method;
    private const PHASES=array('PUBLIC_ENTRY','SEED_CONFIGURATION','DATABASE_CONNECTION','PUBLIC_URL_LOOKUP','STUDY_LOOKUP','PUBLISHED_SNAPSHOT','ACCESS_CONFIGURATION','SESSION_CONTEXT','REQUEST_VALIDATION','RATE_LIMIT','CAPABILITY_REDEMPTION','ANONYMOUS_ACCESS','EXECUTION_INITIALIZATION','RUNTIME_HANDLE','FALLBACK_RENDER','ASSESSMENT_RENDER','DOCUMENT_RENDER');
    private const CODES=array('SERVER_KEY_STORE_UNDECRYPTABLE','SEED_KEYRING_UNAVAILABLE','INVALID_SERVER_KEYRING','SEED_KEY_UNAVAILABLE','SEED_CRYPTO_UNAVAILABLE','SEED_DECRYPT_FAILED','INVALID_SEED_ENVELOPE','ACCESS_KEYRING_UNAVAILABLE','INVALID_ACCESS_KEYRING','ACCESS_KEY_UNAVAILABLE','PUBLIC_RUNTIME_SCHEMA_UNAVAILABLE','PUBLIC_RUNTIME_TRANSACTION_REQUIRED','DURABLE_SCHEMA_UNAVAILABLE','TRANSACTIONAL_ENGINE_REQUIRED','PUBLISHED_VERSION_MISSING','STUDY_NOT_PUBLISHED','PUBLIC_RECORD_INCONSISTENT','SNAPSHOT_INVALID','ASSESSMENT_REFERENCE_MISSING','ASSESSMENT_SNAPSHOT_INVALID','INVALID_DURABLE_JSON','NON_CANONICAL_DURABLE_JSON','INVALID_CONTENT_LANGUAGE','COMPILER_CONTRACT_UNAVAILABLE','PUBLISHED_PROTOCOL_INVALID','PUBLISHED_HASH_MISMATCH','VERSION_BINDING_MISMATCH','COMPILED_GRAPH_INCOMPLETE','GRAPH_PROTOCOL_MISMATCH','EXECUTION_CREATION_FAILED','EXECUTION_LOAD_FAILED','VERSION_UNAVAILABLE');
    public function __construct(string $public,string $method) {
        $this->hash=preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D',$public)?substr(hash('sha256',$public),0,20):'invalid';
        $this->method=in_array($method,array('GET','POST'),true)?$method:'OTHER';
    }
    public function phase(string $phase): void { $this->phase=in_array($phase,self::PHASES,true)?$phase:'PUBLIC_ENTRY'; }
    public function study(array $study): void { $id=$study['study_id']??null;if(is_string($id)&&preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D',$id))$this->study=$id; }
    private static function known(\Throwable $error): ?string { return in_array($error->getMessage(),self::CODES,true)?$error->getMessage():null; }
    public function code(\Throwable $error): string {
        if($error instanceof P\DatabaseFailure)return 'STORAGE_FAILURE';
        $known=self::known($error);if($known!==null)return $known;
        $map=array('DATABASE_CONNECTION'=>'STORAGE_INIT_FAILED','PUBLIC_URL_LOOKUP'=>'PUBLIC_RECORD_INCONSISTENT','STUDY_LOOKUP'=>'STUDY_LOOKUP_FAILED','SEED_CONFIGURATION'=>'SEED_CONFIGURATION_FAILED','ACCESS_CONFIGURATION'=>'ACCESS_CONFIGURATION_FAILED','PUBLISHED_SNAPSHOT'=>'SNAPSHOT_INVALID','SESSION_CONTEXT'=>'SESSION_INIT_FAILED','ANONYMOUS_ACCESS'=>'SESSION_INIT_FAILED','EXECUTION_INITIALIZATION'=>'EXECUTION_INIT_FAILED','ASSESSMENT_RENDER'=>'RENDER_FAILURE','FALLBACK_RENDER'=>'RENDER_FAILURE','DOCUMENT_RENDER'=>'RENDER_FAILURE');
        return $map[$this->phase]??'UNEXPECTED_RUNTIME_FAILURE';
    }
    public function status(\Throwable $error): int {
        // Broken definitions/configuration are server errors, not temporary unavailability.
        return $error instanceof P\DatabaseFailure&&in_array((int)$error->getCode(),array(1040,1042,1047,1053,1158,1159,1160,1161,1205,1213,2002,2003,2006,2013),true)?503:500;
    }
    public function context(\Throwable $error,?string $code=null): array {
        $root=$error;while($root->getPrevious()!==null)$root=$root->getPrevious();
        $file=$root->getFile();$inside=strpos($file,EIPSI_FORMS_PLUGIN_DIR)===0;
        $component=$inside?pathinfo($file,PATHINFO_FILENAME):'ExternalComponent';
        if($component==='route')$component='PublicStudyRoute';
        $trace=$root->getTrace();$method=$trace[0]['function']??'public_entry';
        if(!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$method))$method='public_entry';
        $frames=array();foreach($trace as $frame)$frames[]=array($frame['class']??'', $frame['function']??'', basename($frame['file']??''), $frame['line']??0);
        $class=str_replace('\\','.',get_class($root));if(!preg_match('/^[A-Za-z0-9_.]{1,160}$/D',$class))$class='AnonymousThrowable';
        $row=array('operation'=>$this->phase,'route'=>'public_study','request_method'=>$this->method,'study_code_hash'=>$this->hash,'exception_class'=>$class,'code'=>$code??$this->code($error),'component'=>$component,'source_method'=>$method,'source_line'=>$root->getLine(),'exception_code'=>(int)$root->getCode(),'stack_fingerprint'=>hash('sha256',json_encode(array($class,basename($file),$root->getLine(),$frames))));
        if($this->study!==null)$row['study_id']=$this->study;
        $cause=self::known($root);if($cause!==null&&$cause!==$row['code'])$row['cause_code']=$cause;
        if($error instanceof P\DatabaseFailure)$row['db_errno']=(int)$error->getCode();
        return $row;
    }
    public function record(\Throwable $error,?string $code=null,string $level='error',?int $status=null): void {
        \EIPSI_Security_Log::event('STUDY_OPERATION_FAILED',$this->context($error,$code)+array('status'=>$status??$this->status($error)),$level);
    }
}
