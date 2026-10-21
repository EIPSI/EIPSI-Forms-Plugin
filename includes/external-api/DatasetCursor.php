<?php
namespace EIPSI\ExternalApi;
/** Live scientific positions; credentials, filters and audience cannot be exchanged. */
final class DatasetCursor {
    const AUDIENCE='EIPSI_EXTERNAL_DATA_V1:EIPSI_STUDY_DATA_V1:live:v1';
    private $clock;
    public function __construct(?callable $clock=null){$this->clock=$clock??static function(){return time();};}
    private static function key(string $id): string {return hash_hmac('sha256','external_data_cursor_v1|'.$id,wp_salt('auth'),true);}
    public function encode(string $key,string $study,bool $partials,array $position): string {
        $p=array('v'=>1,'aud'=>self::AUDIENCE,'key'=>$key,'study'=>$study,'partials'=>$partials,'position'=>$position,'exp'=>(int)call_user_func($this->clock)+3600);
        $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt(json_encode($p),'aes-256-gcm',self::key($key),OPENSSL_RAW_DATA,$iv,$tag,self::AUDIENCE,16);if($cipher===false)throw new ApiError('eipsi_external_dataset_failed',503);
        return 'd1_'.rtrim(strtr(base64_encode($iv.$tag.$cipher),'+/','-_'),'=');
    }
    public function decode($token,string $key,string $study,bool $partials): array {
        $bad=static function(){return new ApiError('eipsi_external_invalid_cursor',400);};
        if(!is_string($token)||strlen($token)>4096||!preg_match('/^d1_[A-Za-z0-9_-]{40,4093}$/D',$token))throw $bad();
        $raw=base64_decode(strtr(substr($token,3),'-_','+/'),true);if($raw===false||strlen($raw)<29||'d1_'.rtrim(strtr(base64_encode($raw),'+/','-_'),'=')!==$token)throw $bad();
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key($key),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),self::AUDIENCE);$p=$plain===false?null:json_decode($plain,true,8);
        if(!is_array($p)||array_keys($p)!==array('v','aud','key','study','partials','position','exp')||$p['v']!==1||$p['aud']!==self::AUDIENCE||$p['key']!==$key||$p['study']!==$study||$p['partials']!==$partials||!is_int($p['exp'])||$p['exp']<=(int)call_user_func($this->clock))throw $bad();
        $pos=$p['position'];if(!is_array($pos)||array_keys($pos)!==array('execution','row')||!is_string($pos['execution'])||!preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,127}$/D',$pos['execution']))throw $bad();
        if($pos['row']!==null){if(!is_string($pos['row'])||strlen($pos['row'])>257||!preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,127}\x00[A-Za-z][A-Za-z0-9_.:-]{0,127}$/D',$pos['row']))throw $bad();}
        return $pos;
    }
}
