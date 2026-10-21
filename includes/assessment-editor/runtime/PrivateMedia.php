<?php
namespace EIPSI\AssessmentEditor\Runtime;
/** Durable raster snapshot in non-autoloaded DB storage, never in a public static directory. */
final class PrivateMedia {
    const PREFIX='eipsi_native_private_media_';
    public static function key(string $url): ?string {
        if(strpos($url,home_url('/').'?eipsi_native_asset=')!==0)return null;
        parse_str(parse_url($url,PHP_URL_QUERY)??'',$query);$key=$query['eipsi_native_asset']??null;
        return is_string($key)&&preg_match('/^[a-f0-9]{64}\.(png|jpg|gif|webp|avif)$/D',$key)?$key:null;
    }
    public static function load(string $key): array {
        if(!preg_match('/^[a-f0-9]{64}\.(png|jpg|gif|webp|avif)$/D',$key))throw new \RuntimeException('Media unavailable',404);
        $r=get_option(self::PREFIX.$key,null);$bytes=is_array($r)?base64_decode($r['bytes']??'',true):false;
        if(!$bytes||!hash_equals(substr($key,0,64),hash('sha256',$bytes))||!in_array($r['mime']??null,array('image/png','image/jpeg','image/gif','image/webp','image/avif'),true))throw new \RuntimeException('Media unavailable',404);
        return array('bytes'=>$bytes,'mime'=>$r['mime']);
    }
    public static function pin(string $file,string $mime,string $extension): string {
        $bytes=file_get_contents($file);if($bytes===false)throw new \RuntimeException('Media unavailable',409);$key=hash('sha256',$bytes).'.'.$extension;
        if(get_option(self::PREFIX.$key,null)===null&&!add_option(self::PREFIX.$key,array('mime'=>$mime,'bytes'=>base64_encode($bytes)),'',false)&&get_option(self::PREFIX.$key,null)===null)throw new \RuntimeException('Cannot pin native image',409);
        self::load($key);return add_query_arg('eipsi_native_asset',$key,home_url('/'));
    }
    public static function scoped(string $url,string $execution): string {
        $key=self::key($url);if($key===null)return$url;$expires=time()+900;$mac=hash_hmac('sha256',$execution.'|'.$key.'|'.$expires,wp_salt('auth'));
        return add_query_arg(array('execution'=>$execution,'expires'=>$expires,'signature'=>$mac),$url);
    }
    public static function scopeDefinition(array $d,string $execution): array {
        $rewrite=function($value)use($execution){if(!is_string($value))return$value;$key=self::key($value);if($key!==null)return self::scoped($value,$execution);if(stripos($value,'<img')===false)return$value;$tags=new \WP_HTML_Tag_Processor($value);while($tags->next_tag('IMG')){$src=$tags->get_attribute('src');if(is_string($src))$tags->set_attribute('src',self::scoped($src,$execution));}return$tags->get_updated_html();};
        $walk=function(&$value)use(&$walk,$rewrite){foreach($value as&$v){if(is_array($v))$walk($v);else$v=$rewrite($v);}unset($v);};$walk($d);return$d;
    }
    private static function contains(array $d,string $key): bool {
        $found=false;$walk=function($value)use(&$walk,&$found,$key){if(is_array($value)){foreach($value as$v)$walk($v);return;}if(!is_string($value))return;if(self::key($value)===$key){$found=true;return;}if(stripos($value,'<img')!==false){$tags=new \WP_HTML_Tag_Processor($value);while($tags->next_tag('IMG')){$src=$tags->get_attribute('src');if(is_string($src)&&self::key($src)===$key)$found=true;}}};$walk($d);return$found;
    }
    public static function page(): void {
        nocache_headers();header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');
        try{$key=$_GET['eipsi_native_asset']??null;if(!is_string($key))throw new \RuntimeException('Media unavailable',404);
            // Author previews are a distinct, authenticated administrative context.
            if(isset($_GET['execution'])||!current_user_can('manage_options')){
                $execution=$_GET['execution']??null;$expires=$_GET['expires']??null;$signature=$_GET['signature']??null;
                if(!is_string($execution)||!is_string($expires)||!ctype_digit($expires)||(int)$expires<time()||(int)$expires>time()+900||!is_string($signature)||!hash_equals(hash_hmac('sha256',$execution.'|'.$key.'|'.$expires,wp_salt('auth')),$signature))throw new \RuntimeException('Media unavailable',404);
                $f=EffectiveAssessmentProjection::frame((new Owner())->view($execution));if(!self::contains($f['definition'],$key))throw new \RuntimeException('Media unavailable',404);
            }
            $r=self::load($key);header('Content-Type: '.$r['mime']);header('Content-Length: '.strlen($r['bytes']));status_header(200);echo$r['bytes'];
        }catch(\Throwable$e){status_header(404);}exit;
    }
}
