<?php
namespace EIPSI\Scheduler;

/** Conservative editor: only an exact owned block in a conventional config is writable. */
/** Stable presentation identity for administrative configuration failures. */
final class ConfigError extends \RuntimeException {
    private $presentationCode;
    public function __construct(string $presentationCode,string $message,int $status) {
        $this->presentationCode=$presentationCode;parent::__construct($message,$status);
    }
    public function presentationCode(): string { return $this->presentationCode; }
}

final class Config {
    const BEGIN = '/* EIPSI Forms — WP-Cron configuration */';
    const END = '/* End EIPSI Forms — WP-Cron configuration */';

    public static function locate(): ?string {
        if (is_file(ABSPATH.'wp-config.php')) return ABSPATH.'wp-config.php';
        $parent=dirname(rtrim(ABSPATH,'/\\'));
        return !is_file($parent.'/wp-settings.php') && is_file($parent.'/wp-config.php') ? $parent.'/wp-config.php' : null;
    }
    public static function parse(string $source): array {
        $result=array('ownership'=>'external','value'=>null,'reason_code'=>'DYNAMIC_OR_UNKNOWN','reason'=>'Configuración dinámica o estructura no reconocida.','anchor'=>null,'block'=>null);
        try { $tokens=token_get_all($source,TOKEN_PARSE); } catch (\Throwable $e) { return $result; }
        $offset=0;$braces=0;$parens=0;$constants=array();$unsafe=false;$anchor=null;$previous=null;$constantDepths=array();
        foreach($tokens as $index=>$token) {
            $text=is_array($token)?$token[1]:$token;$id=is_array($token)?$token[0]:null;
            if ($id===T_CONSTANT_ENCAPSED_STRING && preg_match('/^[\'"]DISABLE_WP_CRON[\'"]$/D',$text)) { $constants[]=$offset;$constantDepths[]=$braces; }
            if ($id===T_STRING && strtoupper($text)==='DISABLE_WP_CRON') { $constants[]=$offset;$constantDepths[]=$braces; }
            if($id===T_STRING && strtolower($text)==='define') {
                $next=array();for($j=$index+1;$j<count($tokens)&&count($next)<2;$j++){
                    if(is_array($tokens[$j])&&in_array($tokens[$j][0],array(T_WHITESPACE,T_COMMENT,T_DOC_COMMENT),true))continue;
                    $next[]=$tokens[$j];
                }
                if(($next[0]??null)!=='(' || !is_array($next[1]??null) || $next[1][0]!==T_CONSTANT_ENCAPSED_STRING)$unsafe=true;
                if($braces===0 && !in_array($previous,array(';','}',T_OPEN_TAG),true))$unsafe=true;
            }
            if (in_array($id,array(T_EVAL,T_HALT_COMPILER,T_NAMESPACE,T_INLINE_HTML),true)) $unsafe=true;
            if (in_array($id,array(T_INCLUDE,T_INCLUDE_ONCE,T_REQUIRE,T_REQUIRE_ONCE),true)) {
                $tail=substr($source,$offset);
                if ($braces===0 && $parens===0 && in_array($previous,array(';','}',T_OPEN_TAG),true) && preg_match('/^require_once\s*(?:\(\s*)?ABSPATH\s*\.\s*([\'"])wp-settings\.php\1\s*\)?\s*;/',$tail)) {
                    if ($anchor!==null) $unsafe=true;
                    $anchor=$offset;
                } else $unsafe=true;
            }
            if (!is_array($token)) {
                if($text==='{')$braces++;if($text==='}')$braces--;
                if($text==='(')$parens++;if($text===')')$parens--;
            }
            $offset+=strlen($text);
            if(!in_array($id,array(T_WHITESPACE,T_COMMENT,T_DOC_COMMENT),true))$previous=$id??$text;
        }
        $result['anchor']=$anchor;
        if(count($constants)===1 && preg_match('/define\s*\(\s*([\'"])DISABLE_WP_CRON\1\s*,\s*(true|false)\s*\)\s*;/i',$source,$literal))
            $result['value']=strtolower($literal[2])==='true';
        $begin=strpos($source,self::BEGIN);$end=strpos($source,self::END);
        if($begin!==false || $end!==false) {
            if($begin===false || $end===false || $end<$begin || substr_count($source,self::BEGIN)!==1 || substr_count($source,self::END)!==1) return $result;
            $length=$end+strlen(self::END)-$begin;$block=substr($source,$begin,$length);
            $pattern='~^'.preg_quote(self::BEGIN,'~').'\s*define\( \'DISABLE_WP_CRON\', (true|false) \);\s*'.preg_quote(self::END,'~').'$~D';
            if(!$unsafe && $anchor!==null && $end<$anchor && count($constants)===1 && ($constantDepths[0]??-1)===0 && $constants[0]>$begin && $constants[0]<$end && preg_match($pattern,$block,$m)) {
                $result=array_merge($result,array('ownership'=>'eipsi','value'=>$m[1]==='true','reason_code'=>'EIPSI_MANAGED','reason'=>'Bloque administrado por EIPSI.','block'=>array($begin,$length)));
            }
            return $result;
        }
        if(!$constants && !$unsafe && $anchor!==null) return array_merge($result,array('ownership'=>'absent','reason_code'=>'EIPSI_CAN_CREATE','reason'=>'EIPSI puede crear su bloque.'));
        if($constants) {$result['reason_code']='EXTERNAL_MANAGED';$result['reason']='Esta configuración está siendo administrada externamente.';}
        return $result;
    }
    public static function inspect(?string $path=null): array {
        $path=$path??self::locate();
        $out=array('ownership'=>'external','value'=>null,'reason_code'=>'CONFIG_UNREADABLE','reason'=>'No se pudo localizar o leer wp-config.php.','writable'=>false,'hash'=>null);
        if(!$path || is_link($path) || !is_file($path) || !is_readable($path) || filesize($path)>1048576) return $out;
        $source=@file_get_contents($path);if($source===false)return $out;
        $out=array_merge($out,self::parse($source));$out['hash']=hash('sha256',$source);
        $stat=stat($path);$dir=dirname($path);
        // Atomic replacement needs both file and parent permissions. Symlinked configs are external.
        $out['writable']=is_writable($path)&&is_writable($dir)&&(($stat['mode']&0222)!==0)&&((fileperms($dir)&0222)!==0);
        if(!$out['writable']){$out['reason_code']='UNSAFE_REPLACEMENT';$out['reason']='El archivo o su directorio no permiten una sustitución segura.';}
        return $out;
    }
    public static function block(bool $disabled): string {
        return self::BEGIN."\ndefine( 'DISABLE_WP_CRON', ".($disabled?'true':'false')." );\n".self::END;
    }
    /** $writer is a test seam for short writes; never accepted from HTTP. */
    public static function write(string $path,bool $disabled,string $expected,?callable $writer=null): void {
        $info=self::inspect($path);
        if(!$info['writable'] || !in_array($info['ownership'],array('absent','eipsi'),true) || !hash_equals($info['hash']??'', $expected))
            throw new ConfigError('MANUAL_REVIEW_REQUIRED','No se modificó la configuración: revisá las instrucciones manuales y recargá.',409);
        $handle=@fopen($path,'rb');$temp=null;$out=null;
        if(!$handle || !flock($handle,LOCK_EX|LOCK_NB)) { if($handle)fclose($handle);throw new ConfigError('CONFIG_BUSY','La configuración está ocupada. Reintentá más tarde.',409); }
        try {
            $old=stream_get_contents($handle);$stat=fstat($handle);
            if(!is_string($old)||!hash_equals($expected,hash('sha256',$old)))throw new ConfigError('CONFIG_CHANGED','La configuración cambió. Recargá antes de reintentar.',409);
            $info=self::parse($old);$block=self::block($disabled);
            if($info['ownership']==='eipsi')$new=substr_replace($old,$block,$info['block'][0],$info['block'][1]);
            elseif($info['ownership']==='absent')$new=substr_replace($old,$block."\n",$info['anchor'],0);
            else throw new ConfigError('EXTERNAL_MANAGED','La configuración se administra externamente.',409);
            // Parse without evaluating a single line of configuration.
            token_get_all($new,TOKEN_PARSE);
            $parsed=self::parse($new);
            if($parsed['ownership']!=='eipsi'||$parsed['value']!==$disabled)throw new ConfigError('VALIDATION_FAILED','No se pudo validar la configuración.',409);
            $temp=dirname($path).'/.eipsi-config-'.bin2hex(random_bytes(16)).'.php';
            $out=@fopen($temp,'x+b');
            if(!$out || !chmod($temp,0600))throw new ConfigError('TEMPORARY_FILE_FAILED','No se pudo preparar el archivo temporal.',409);
            $written=$writer?$writer($out,$new):fwrite($out,$new);
            if($written!==strlen($new) || !fflush($out) || (function_exists('fsync')&&!fsync($out)))throw new ConfigError('WRITE_FAILED','No se pudo completar la escritura. El original se conservó.',409);
            rewind($out);if(stream_get_contents($out)!==$new)throw new ConfigError('WRITE_VALIDATION_FAILED','La validación de escritura falló.',409);
            $tmpstat=fstat($out);
            if(($tmpstat['uid']!==$stat['uid']&&!@chown($temp,$stat['uid'])) || ($tmpstat['gid']!==$stat['gid']&&!@chgrp($temp,$stat['gid'])) || !chmod($temp,$stat['mode']&0777))
                throw new ConfigError('PERMISSIONS_FAILED','No se pudieron preservar los permisos del archivo.',409);
            fclose($out);$out=null;clearstatcache(true,$path);$current=@stat($path);
            if(is_link($path)||!$current||$current['ino']!==$stat['ino']||$current['dev']!==$stat['dev']||!hash_equals($expected,(string)@hash_file('sha256',$path)))
                throw new ConfigError('CONFIG_CHANGED_DURING_WRITE','La configuración cambió durante la operación.',409);
            if(!@rename($temp,$path))throw new ConfigError('REPLACEMENT_FAILED','No se pudo reemplazar el archivo. El original se conservó.',409);
            $temp=null;
            if(function_exists('opcache_invalidate'))@opcache_invalidate($path,true);
        } finally {
            if(is_resource($out))fclose($out);
            if($temp!==null && is_file($temp))unlink($temp);
            flock($handle,LOCK_UN);fclose($handle);
        }
    }
}
