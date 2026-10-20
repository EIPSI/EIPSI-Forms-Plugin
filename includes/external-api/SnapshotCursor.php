<?php
namespace EIPSI\ExternalApi;
/** Authenticated, opaque snapshot positions; a separate audience from both live cursors. */
final class SnapshotCursor {
    private const AUD='EIPSI_STUDY_DATA_V1:snapshot:1';
    private static function key(string $key): string {return hash_hmac('sha256',self::AUD.'|'.$key,wp_salt('auth'),true);}
    public static function encode(string $key,string $export,array $position,int $expires): string {
        $iv=random_bytes(12);$tag='';$json=json_encode(array('export'=>$export,'position'=>$position,'expires'=>$expires));$cipher=openssl_encrypt($json,'aes-256-gcm',self::key($key),OPENSSL_RAW_DATA,$iv,$tag,self::AUD);if($cipher===false)throw new ApiError('eipsi_external_export_failed',503);return 's1_'.rtrim(strtr(base64_encode($iv.$tag.$cipher),'+/','-_'),'=');
    }
    public static function decode($token,string $key,string $export,int $now): array {
        $bad=static function(){return new ApiError('eipsi_external_invalid_cursor',400);};if(!is_string($token)||!preg_match('/^s1_[A-Za-z0-9_-]{40,1000}$/D',$token))throw $bad();$raw=base64_decode(strtr(substr($token,3),'-_','+/'),true);if($raw===false||strlen($raw)<29||'s1_'.rtrim(strtr(base64_encode($raw),'+/','-_'),'=')!==$token)throw $bad();
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key($key),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),self::AUD);$p=$plain===false?null:json_decode($plain,true);
        if(!is_array($p)||array_keys($p)!==array('export','position','expires')||$p['export']!==$export||!is_int($p['expires'])||$p['expires']<=$now||!is_array($p['position'])||array_keys($p['position'])!==array(0,1)||!is_int($p['position'][0])||!is_int($p['position'][1])||min($p['position'])<0)throw $bad();return $p['position'];
    }
}
