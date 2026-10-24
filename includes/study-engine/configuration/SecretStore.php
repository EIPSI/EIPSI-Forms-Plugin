<?php
namespace EIPSI\StudyEngine\Configuration;
/** One versioned, non-autoloaded encrypted administrative option; never scientific storage. */
final class SecretStore {
    const OPTION='eipsi_server_secrets_v1';
    const CONTEXT='EIPSI_SERVER_SECRETS_V1';
    private static function record(): ?string {
        global $wpdb;if(!isset($wpdb->options)||!preg_match('/^[A-Za-z0-9_]+$/D',$wpdb->options))throw new \RuntimeException('SERVER_KEY_STORE_UNDECRYPTABLE',503);
        $raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM `'.$wpdb->options.'` WHERE option_name=%s',self::OPTION));
        if($wpdb->last_error||($raw!==null&&!is_string($raw)))throw new \RuntimeException('SERVER_KEY_STORE_UNDECRYPTABLE',503);return $raw;
    }
    public static function exists(): bool {return self::record()!==null;}
    private static function master(): string {
        $inputs=array();
        foreach(array('AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT')as $name){
            if(!defined($name)||!is_string(constant($name))||strlen(constant($name))<32||constant($name)==='put your unique phrase here')throw new \RuntimeException('SERVER_KEY_STORE_UNDECRYPTABLE');
            $inputs[$name]=constant($name);
        }
        if(!function_exists('hash_hkdf')||!function_exists('openssl_encrypt')||!in_array('aes-256-gcm',openssl_get_cipher_methods(),true))throw new \RuntimeException('SERVER_KEY_STORE_UNDECRYPTABLE');
        return hash_hkdf('sha256',json_encode($inputs,JSON_UNESCAPED_SLASHES),32,self::CONTEXT.'|blog:'.get_current_blog_id(),'EIPSI_HKDF_WP8_V1');
    }
    private static function aad(): string {return self::CONTEXT.'|AES-256-GCM|HKDF-SHA256-WP8|blog:'.get_current_blog_id();}
    public static function read(): ?array {
        $raw=self::record();if($raw===null)return null;
        try{
            if(!is_string($raw)||strlen($raw)>65536)throw new \RuntimeException();$e=json_decode($raw,true);
            if(!is_array($e)||($e['version']??null)!==1||($e['cipher']??null)!=='AES-256-GCM'||($e['kdf']??null)!=='HKDF-SHA256-WP8'||($e['blog_id']??null)!==get_current_blog_id())throw new \RuntimeException();
            foreach(array('nonce','tag','ciphertext')as $field){if(!isset($e[$field])||!is_string($e[$field]))throw new \RuntimeException();$e[$field]=base64_decode($e[$field],true);if($e[$field]===false)throw new \RuntimeException();}
            if(strlen($e['nonce'])!==12||strlen($e['tag'])!==16)throw new \RuntimeException();
            $plain=openssl_decrypt($e['ciphertext'],'aes-256-gcm',self::master(),OPENSSL_RAW_DATA,$e['nonce'],$e['tag'],self::aad());
            if($plain===false)throw new \RuntimeException();$values=json_decode($plain,true);
            if(!is_array($values)||array_keys($values)!==array('seed','access'))throw new \RuntimeException();
            KeyringResolver::validate($values['seed'],'seed');KeyringResolver::validate($values['access'],'access');return $values;
        }catch(\Throwable $error){throw new \RuntimeException('SERVER_KEY_STORE_UNDECRYPTABLE',503);}
    }
    private static function assertEmptyScientificState(): void {
        global $wpdb;
        foreach(array('study_executions','access_capabilities')as $name){
            $table=$wpdb->prefix.'eipsi_engine_'.$name;
            $exists=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
            if((int)$exists!==1||$wpdb->last_error)throw new \RuntimeException('SETUP_SCHEMA_REQUIRED',409);
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new \RuntimeException('SETUP_SCHEMA_REQUIRED',409);
            $count=$wpdb->get_var('SELECT COUNT(*) FROM `'.$table.'`');if($count===null||$wpdb->last_error)throw new \RuntimeException('SETUP_SCHEMA_REQUIRED',409);
            if((int)$count>0)throw new \RuntimeException('SERVER_KEYS_EXISTING_DATA',409);
        }
    }
    /** Explicit initial provisioning only. Unique option name makes concurrent requests non-destructive. */
    public static function provision(): bool {
        \EIPSI_Admin_Authorization_Policy::demand('privacy.manage');
        if(self::exists()){self::read();return false;}
        foreach(array('seed','access')as $kind)if(KeyringResolver::source($kind)!=='NONE')throw new \RuntimeException('SERVER_KEYS_EXTERNAL',409);
        self::assertEmptyScientificState();try{$master=self::master();}catch(\Throwable $e){throw new \RuntimeException('SERVER_KEYS_INSTALLATION_SECRETS_REQUIRED',503);}
        $values=array('seed'=>array('current'=>'SEED_1','keys'=>array('SEED_1'=>bin2hex(random_bytes(32)))),'access'=>array('current'=>'ACCESS_1','keys'=>array('ACCESS_1'=>bin2hex(random_bytes(32)))));
        $nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt(json_encode($values,JSON_UNESCAPED_SLASHES),'aes-256-gcm',$master,OPENSSL_RAW_DATA,$nonce,$tag,self::aad(),16);
        if($cipher===false||strlen($tag)!==16)throw new \RuntimeException('SERVER_KEY_STORE_UNDECRYPTABLE',503);
        $envelope=array('version'=>1,'cipher'=>'AES-256-GCM','kdf'=>'HKDF-SHA256-WP8','blog_id'=>get_current_blog_id(),'nonce'=>base64_encode($nonce),'ciphertext'=>base64_encode($cipher),'tag'=>base64_encode($tag));
        global $wpdb;
        // WordPress add_option uses an upsert. INSERT IGNORE alone must arbitrate the unique name.
        $previousErrors=$wpdb->suppress_errors(true);try{$inserted=$wpdb->query($wpdb->prepare('INSERT IGNORE INTO `'.$wpdb->options.'` (option_name,option_value,autoload) VALUES (%s,%s,%s)',self::OPTION,json_encode($envelope,JSON_UNESCAPED_SLASHES),'no'));}finally{$wpdb->suppress_errors($previousErrors);}
        wp_cache_delete(self::OPTION,'options');wp_cache_delete('notoptions','options');
        if($inserted!==1){if($inserted===0&&self::read()!==null)return false;throw new \RuntimeException('SERVER_KEY_STORE_WRITE_FAILED',503);}
        $event=array('actor'=>get_current_user_id(),'at'=>gmdate('Y-m-d\TH:i:s\Z'),'operation'=>'KEYS_PROVISIONED','result'=>'SUCCESS','source'=>'EIPSI');
        $audit=get_option('eipsi_setup_audit_v1',array());if(!is_array($audit))$audit=array();$audit[]=$event;update_option('eipsi_setup_audit_v1',array_slice($audit,-50),false);
        \EIPSI_Security_Log::event('SERVER_KEYS_PROVISIONED',array('actor'=>$event['actor'],'operation'=>$event['operation'],'result'=>$event['result'],'source'=>$event['source'],'at'=>$event['at']),'info');
        return true;
    }
}
