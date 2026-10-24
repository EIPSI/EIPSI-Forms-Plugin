<?php
namespace EIPSI\StudyEngine\Configuration;
require_once __DIR__.'/SecretStore.php';
/** Sole source/format authority. Invalid higher-precedence sources never fall through. */
final class KeyringResolver {
    private static function name(string $kind): string {if(!in_array($kind,array('seed','access'),true))throw new \InvalidArgumentException('INVALID_KEYRING_KIND');return $kind==='seed'?'EIPSI_STUDY_ENGINE_KEYRING':'EIPSI_STUDY_ENGINE_ACCESS_KEYRING';}
    public static function source(string $kind): string {$name=self::name($kind);if(defined($name))return 'CONSTANT';if(getenv($name)!==false)return 'ENVIRONMENT';return function_exists('get_option')&&SecretStore::exists()?'EIPSI':'NONE';}
    public static function raw(string $kind) {
        $name=self::name($kind);$source=self::source($kind);
        if($source==='CONSTANT')return constant($name);if($source==='ENVIRONMENT')return getenv($name);if($source==='EIPSI')return SecretStore::read()[$kind];return null;
    }
    public static function parse($raw,string $kind,bool $legacyUppercase=false): array {
        if(is_string($raw))$raw=json_decode($raw,true);
        if(!is_array($raw)||!isset($raw['current'],$raw['keys']))throw new \RuntimeException($kind==='seed'?'SEED_KEYRING_UNAVAILABLE':'ACCESS_KEYRING_UNAVAILABLE');
        return self::validate($raw,$kind,$legacyUppercase);
    }
    public static function validate(array $raw,string $kind,bool $legacyUppercase=false): array {
        self::name($kind);$invalid=$kind==='seed'?'INVALID_SERVER_KEYRING':'INVALID_ACCESS_KEYRING';$pattern=$kind==='seed'?'/^[A-Za-z][A-Za-z0-9_.:-]{0,127}$/D':'/^[A-Za-z0-9_-]{1,64}$/D';
        if(!is_string($raw['current']??null)||!preg_match($pattern,$raw['current'])||!is_array($raw['keys']??null))throw new \RuntimeException($invalid);
        foreach($raw['keys'] as $id=>$hex)if(!preg_match($pattern,(string)$id)||!is_string($hex)||!preg_match($legacyUppercase&&$kind==='seed'?'/^[a-fA-F0-9]{64}$/D':'/^[a-f0-9]{64}$/D',$hex))throw new \RuntimeException($invalid);
        if(!isset($raw['keys'][$raw['current']]))throw new \RuntimeException($kind==='seed'?'SEED_KEY_UNAVAILABLE':'ACCESS_KEY_UNAVAILABLE');
        return array('current'=>$raw['current'],'keys'=>$raw['keys']);
    }
    public static function resolve(string $kind,bool $legacyUppercase=false): array {return self::parse(self::raw($kind),$kind,$legacyUppercase);}
    public static function binary(string $kind,bool $legacyUppercase=false): array {$c=self::resolve($kind,$legacyUppercase);foreach($c['keys'] as &$hex)$hex=hex2bin($hex);unset($hex);return $c;}
    public static function inspect(string $kind): array {
        $s=array('available'=>false,'format_valid'=>false,'active_key_resolvable'=>false,'ready'=>false,'source'=>'ERROR','error'=>'SERVER_KEY_STORE_UNDECRYPTABLE');
        try{$source=self::source($kind);}catch(\Throwable $e){return $s;}$s['source']=$source;$s['available']=$source!=='NONE';$s['error']=null;
        try{$raw=self::raw($kind);if(is_string($raw))$raw=json_decode($raw,true);self::parse($raw,$kind);$s['format_valid']=$s['active_key_resolvable']=true;$s['ready']=$kind==='access'||(function_exists('openssl_get_cipher_methods')&&in_array('aes-256-gcm',openssl_get_cipher_methods(),true));}
        catch(\Throwable $error){$code=$error->getMessage();if(in_array($code,array('SEED_KEY_UNAVAILABLE','ACCESS_KEY_UNAVAILABLE'),true))$s['format_valid']=true;$s['error']=$source==='EIPSI'?'SERVER_KEY_STORE_UNDECRYPTABLE':($source==='NONE'?null:'INVALID_EXTERNAL_KEYRING');}
        return $s;
    }
}
