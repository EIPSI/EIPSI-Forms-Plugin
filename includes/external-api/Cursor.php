<?php
namespace EIPSI\ExternalApi;

/** Authenticated, key-bound opaque positions. They never replace current authorization. */
final class Cursor {
    const AUDIENCE='studies:list:v1:study_id_asc';
    const TTL=3600;
    private $clock;
    public function __construct(?callable $clock=null){$this->clock=$clock??static function(){return time();};}
    private static function cipherKey(string $id): string {return hash_hmac('sha256','external_discovery_cursor_v1|'.$id,wp_salt('auth'),true);}
    public function encode(string $key,string $after,string $upper): string {
        $payload=json_encode(array('v'=>1,'aud'=>self::AUDIENCE,'key'=>$key,'after'=>$after,'upper'=>$upper,'exp'=>(int)call_user_func($this->clock)+self::TTL));
        $iv=random_bytes(12);$tag='';$encrypted=openssl_encrypt($payload,'aes-256-gcm',self::cipherKey($key),OPENSSL_RAW_DATA,$iv,$tag,self::AUDIENCE,16);
        if($encrypted===false)throw new ApiError('eipsi_external_unavailable',503);
        return 'c1_'.rtrim(strtr(base64_encode($iv.$tag.$encrypted),'+/','-_'),'=');
    }
    public function decode($token,string $key): array {
        $invalid=static function(){return new ApiError('eipsi_external_invalid_cursor',400);};
        if(!is_string($token)||strlen($token)>2048||!preg_match('/^c1_[A-Za-z0-9_-]{40,2045}$/D',$token))throw $invalid();
        $raw=base64_decode(strtr(substr($token,3),'-_','+/'),true);
        if($raw===false||strlen($raw)<29||'c1_'.rtrim(strtr(base64_encode($raw),'+/','-_'),'=')!==$token)throw $invalid();
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::cipherKey($key),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),self::AUDIENCE);
        $p=$plain===false?null:json_decode($plain,true,8);
        if(!is_array($p)||array_keys($p)!==array('v','aud','key','after','upper','exp')||$p['v']!==1||$p['aud']!==self::AUDIENCE||$p['key']!==$key||!is_int($p['exp'])||$p['exp']<=(int)call_user_func($this->clock))throw $invalid();
        foreach(array('after','upper') as $field)if(!is_string($p[$field])||!preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,127}$/D',$p[$field]))throw $invalid();
        if(strcmp($p['after'],$p['upper'])>0)throw $invalid();
        return $p;
    }
}
