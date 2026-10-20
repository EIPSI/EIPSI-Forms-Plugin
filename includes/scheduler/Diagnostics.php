<?php
namespace EIPSI\Scheduler;

final class Diagnostics {
    public static function inspect(?int $now=null): array {
        $now=$now??time();$disabled=defined('DISABLE_WP_CRON')&&(bool)DISABLE_WP_CRON;
        $state=Heartbeat::state();$health=Heartbeat::health($disabled,$state,$now);
        $owners=\EIPSI_Cron_Registry::owners();$events=array();$overdue=0;$total=0;
        // Read-only WordPress event inventory. No callbacks are invoked by diagnostics.
        foreach((_get_cron_array()?:array())as$timestamp=>$hooks)foreach($hooks as$hook=>$variants) {
            if(!isset($owners[$hook]))continue;
            foreach($variants as$event) {
                $total++;if((int)$timestamp<$now-Heartbeat::EXPECTED)$overdue++;
                if(count($events)<20)$events[]=array('hook'=>$hook,'at'=>(int)$timestamp,'schedule'=>$event['schedule']?:'Una vez');
            }
        }
        if($overdue){$health['state']='WARNING';$health['message'].=' Hay eventos EIPSI atrasados más de 5 minutos.';}
        $api=class_exists(\EIPSI\ExternalApi\Operations::class)?\EIPSI\ExternalApi\Operations::state():array();
        return array('disabled'=>$disabled,'heartbeat'=>$state,'health'=>$health,'overdue'=>$overdue,'scheduled'=>$total,'events'=>$events,'maintenance'=>$api,'checked_at'=>$now);
    }
    public static function executable(string $name): ?string {
        // Fixed names only; never execute shell commands to detect tools.
        if(!in_array($name,array('wp','php','curl'),true))return null;
        foreach(explode(PATH_SEPARATOR,(string)getenv('PATH'))as$dir) {
            if(!$dir || $dir[0]!=='/' || strpos($dir,"\n")!==false)continue;
            $path=rtrim($dir,'/').'/'.$name;
            if(is_file($path)&&is_executable($path))return $path;
        }
        return null;
    }
    public static function commands(): array {
        $root=realpath(ABSPATH);$wp=self::executable('wp');$php=self::executable('php');$curl=self::executable('curl');
        $out=array();
        if($root && $wp)$out['WP-CLI']='cd '.escapeshellarg($root).' && '.escapeshellarg($wp).' cron event run --due-now --quiet';
        if($root && $php && is_file($root.'/wp-cron.php'))$out['PHP CLI']=escapeshellarg($php).' '.escapeshellarg($root.'/wp-cron.php').' >/dev/null 2>&1';
        $url=site_url('wp-cron.php');
        $parts=wp_parse_url($url);
        if(is_array($parts)&&in_array($parts['scheme']??'',array('http','https'),true)&&!isset($parts['user'])&&!isset($parts['pass'])) {
            $out['HTTP (alternativa)']=($curl?escapeshellarg($curl):'curl').' --fail --silent --show-error --max-time 240 '.escapeshellarg($url).' >/dev/null';
        }
        return $out;
    }
}
