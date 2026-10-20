<?php
namespace EIPSI\ExternalApi;
/** Fixed categories + UTC day only. Never a participant, Study, key or request ID dimension. */
final class OperationsMetrics {
    const CATEGORIES=array('status','discovery','data','dictionary','export_create','export_status','export_data','export_download','export_dictionary','export_manifest','unknown','maintenance');
    public static function record(string $category,array $counters,int $now): void {
        if(!in_array($category,self::CATEGORIES,true))throw new \LogicException('Invalid metrics category');
        $allowed=array('requests','failures','duration_ms','generated_bytes','downloaded_bytes','rate_limits','cleanup_count','worker_failures');$cols=array();$values=array();$updates=array();$s=new Store();
        foreach($counters as$k=>$v){if(!in_array($k,$allowed,true)||!is_int($v)||$v<0)throw new \LogicException('Invalid metric');$cols[]=$k;$values[]=$v;$updates[]="$k=$k+VALUES($k)";}
        if(!$cols)return;
        try{$s->rows($s->prepare('INSERT INTO '.$s->table('metrics').' (day,category,'.implode(',',$cols).') VALUES (%s,%s,'.implode(',',array_fill(0,count($values),'%d')).') ON DUPLICATE KEY UPDATE '.implode(',',$updates),gmdate('Y-m-d',$now),$category,...$values));}catch(\Throwable $ignored){/* Observability never changes the external scientific outcome. */}
    }
    public static function recent(int $now): array {$s=new Store();return $s->rows($s->prepare('SELECT category,SUM(requests) requests,SUM(failures) failures,SUM(duration_ms) duration_ms,SUM(generated_bytes) generated_bytes,SUM(downloaded_bytes) downloaded_bytes,SUM(rate_limits) rate_limits,SUM(cleanup_count) cleanup_count,SUM(worker_failures) worker_failures FROM '.$s->table('metrics').' WHERE day>=%s AND day<=%s GROUP BY category',gmdate('Y-m-d',$now-30*86400),gmdate('Y-m-d',$now)));}
    public static function category(string $route): string {
        if(substr($route,-7)==='/status')return 'status';
        if(strpos($route,'/exports/')!==false){$tail=basename($route);$map=array('data'=>'export_data','download'=>'export_download','dictionary'=>'export_dictionary','manifest'=>'export_manifest');return $map[$tail]??'export_status';}
        if(substr($route,-8)==='/exports')return 'export_create';if(substr($route,-11)==='/dictionary')return 'dictionary';if(substr($route,-5)==='/data')return 'data';if(strpos($route,'/studies')!==false)return 'discovery';return 'unknown';
    }
}
