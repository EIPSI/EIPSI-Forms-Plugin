<?php
namespace EIPSI\ExternalApi;

/** Immutable request identity; never a WP_User and never contains a bearer/hash. */
final class ExternalPrincipal {
    private $identity;private $readViewAt;
    public function __construct(array $publicMetadata,string $requestId,int $authenticatedAt,?string $readViewAt=null){$this->readViewAt=$readViewAt;
        $this->identity=array_intersect_key($publicMetadata,array_flip(array('id','name','scopes','grant_mode','studies','status','expires_at','revision')));
        $this->identity['request_id']=$requestId;$this->identity['authenticated_at']=$authenticatedAt;
    }
    public function readViewAt(): ?string {return $this->readViewAt;}
    public function id(): string {return $this->identity['id'];}
    public function context(): array {return $this->identity;}
}

final class Authenticator {
    private $store;private $clock;
    public function __construct(?Store $store=null,?callable $clock=null){$this->store=$store??new Store();$this->clock=$clock??static function(){return time();};}
    public static function requestId(): string {return bin2hex(random_bytes(16));}
    public static function secureTransport(): bool {
        // The standard Docker wp-config promotes arbitrary X-Forwarded-Proto to HTTPS.
        // Without explicit trusted-proxy support, that promotion is not transport proof.
        if(is_ssl()&&(!isset($_SERVER['HTTP_X_FORWARDED_PROTO'])||(int)($_SERVER['SERVER_PORT']??0)===443))return true;
        // Explicit WP development environment, not a spoofable Host/X-Forwarded-Proto header.
        return in_array(wp_get_environment_type(),array('local','development'),true);
    }
    public function authenticate($authorization,string $request,string $remoteAddress): ExternalPrincipal {
        $s=$this->store;$now=(int)call_user_func($this->clock);
        if(!$s->enabled())throw new ApiError('eipsi_external_unavailable',404);
        if(!self::secureTransport())throw new ApiError('eipsi_external_https_required',403);
        try{
            if(!is_string($authorization)||strlen($authorization)>256||!preg_match('/^Bearer (eipsi_([a-f0-9]{32})_([a-f0-9]{64}))$/Di',$authorization,$m))throw new ApiError('eipsi_external_unauthorized',401);
            // Prefix/ID/secret are case-sensitive; only the HTTP authentication scheme isn't.
            if(substr($m[1],0,6)!=='eipsi_'||strtolower($m[2])!==$m[2]||strtolower($m[3])!==$m[3])throw new ApiError('eipsi_external_unauthorized',401);
            $principal=$s->transaction(function()use($s,$m,$request,$now){
                if(!$s->enabled(true))throw new ApiError('eipsi_external_unavailable',404);
                $row=$s->key($m[2],true);
                $now=(int)call_user_func($this->clock); // Evaluate expiry after any lock wait.
                // A dummy digest keeps the same comparison for unknown IDs; indexed lookup only.
                $valid=hash_equals($row['secret_hash']??str_repeat('0',64),hash('sha256',$m[3]));
                if(!$row||!$valid||Keys::status($row,$now)!=='ACTIVE')throw new ApiError('eipsi_external_unauthorized',401);
                if($row['last_used_at']===null||(int)$row['last_used_at']<=$now-300){
                    $s->rows($s->prepare('UPDATE '.$s->table('keys').' SET last_used_at=%d WHERE id=%s',$now,$row['id']));
                    $s->audit('AUTH_OK',$row['id'],0,$request,$now,array(),true);
                }
                return new ExternalPrincipal(Keys::publicRow($row,$s->grants($row['id']),$now),$request,$now);
            });
            \EIPSI_Rate_Limiter::consume('external_valid',$principal->id(),120,60,$now);
            return $principal;
        }catch(ApiError $e){
            if($e->getCode()===401){
                // Invalid arbitrary IDs share one aggregate audit row per five-minute window.
                \EIPSI_Rate_Limiter::consume('external_invalid',$remoteAddress,60,60,$now);
                $s->audit('AUTH_FAILED','',0,$request,$now,array(),true);
            }
            throw $e;
        }
    }
}

/** Re-read authorization under locks. Future long jobs must call this again at delivery. */
final class ExternalApiPolicy {
    private $store;private $clock;
    public function __construct(?Store $store=null,?callable $clock=null){$this->store=$store??new Store();$this->clock=$clock??static function(){return time();};}
    public function run(ExternalPrincipal $principal,?string $scope,?string $study,callable $read){
        if($scope!==null&&!in_array($scope,Keys::SCOPES,true))throw new \LogicException('Unknown external scope');
        $s=$this->store;
        return $s->transaction(function()use($s,$principal,$scope,$study,$read){
            if(!$s->enabled(true))throw new ApiError('eipsi_external_unavailable',404);
            $row=$s->key($principal->id(),true);
            $now=(int)call_user_func($this->clock);
            if(!$row||Keys::status($row,$now)!=='ACTIVE')throw new ApiError('eipsi_external_unauthorized',401);
            $scopes=json_decode($row['scopes_json'],true);$grants=$s->grants($row['id']);
            if($scope!==null&&!in_array($scope,$scopes,true))throw new ApiError('eipsi_external_scope_denied',403);
            if($study!==null&&($row['grant_mode']!=='ALL'&&($row['grant_mode']!=='SELECTED'||!in_array($study,$grants,true))))throw new ApiError('eipsi_external_study_unavailable',404);
            return $read(new ExternalPrincipal(Keys::publicRow($row,$grants,$now),$principal->context()['request_id'],$principal->context()['authenticated_at'],$s->readViewAt()));
        });
    }
}
