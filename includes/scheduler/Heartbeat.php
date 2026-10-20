<?php
namespace EIPSI\Scheduler;

/** Observe existing cron owners; no new events or execution paths. Two writes per request at most. */
final class Heartbeat {
    const OPTION='eipsi_scheduler_heartbeat_v1';
    const EXPECTED=300;
    const RECENT=900;
    private static $records=array();
    private static $registered=false;
    public static function context(): bool {
        if(function_exists('wp_doing_cron') && wp_doing_cron())return true;
        if(!defined('WP_CLI') || !WP_CLI)return false;
        // WP-CLI global options may precede the command. Never treat wp eval or
        // the EIPSI manual maintenance command as cron event run.
        $args=isset($_SERVER['argv'])?$_SERVER['argv']:array();
        $positionals=array_values(array_filter(array_slice($args,1),static function($arg){return is_string($arg)&&strpos($arg,'--')!==0;}));
        return array_slice($positionals,0,3)===array('cron','event','run');
    }
    public static function state(): array {
        $raw=get_option(self::OPTION,array());$out=array();
        if(!is_array($raw))return $out;
        foreach(array('last_scheduler_seen_at','last_maintenance_started_at','last_maintenance_completed_at','last_external_seen_at','previous_external_seen_at')as$key)
            if(isset($raw[$key])&&is_int($raw[$key])&&$raw[$key]>0)$out[$key]=$raw[$key];
        $out['result']=in_array($raw['result']??'',array('STARTED','COMPLETED','FAILED'),true)?$raw['result']:'UNKNOWN';
        return $out;
    }
    public static function begin(): void {
        if(!self::context())return;
        $blog=get_current_blog_id();$maintenance=current_filter()==='eipsi_process_nudge_jobs';
        if(!isset(self::$records[$blog])) {
            $state=self::state();$now=time();$state['last_scheduler_seen_at']=$now;$state['result']='STARTED';
            if($maintenance)$state['last_maintenance_started_at']=$now;
            if(defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON===true) {
                $previous=(int)($state['last_external_seen_at']??0);
                if($now>$previous) {
                    $state['previous_external_seen_at']=$previous;
                    $state['last_external_seen_at']=$now;
                }
            }
            self::$records[$blog]=array('state'=>$state,'depth'=>0,'failed'=>false);
            update_option(self::OPTION,$state,false);
            if(!self::$registered){register_shutdown_function(array(__CLASS__,'flush'));self::$registered=true;}
        }
        self::$records[$blog]['depth']++;
        if($maintenance)self::$records[$blog]['state']['last_maintenance_started_at']=time();
    }
    public static function end(): void {
        if(!self::context())return;
        $blog=get_current_blog_id();if(!isset(self::$records[$blog]))return;
        $r=&self::$records[$blog];$r['depth']=max(0,$r['depth']-1);
        if(current_filter()==='eipsi_process_nudge_jobs') {
            $api=class_exists(\EIPSI\ExternalApi\Operations::class)?\EIPSI\ExternalApi\Operations::state():array();
            if(!empty($api['errors']))$r['failed']=true;
            if(!$r['failed'])$r['state']['last_maintenance_completed_at']=time();
        }
    }
    public static function failure(): void {
        $blog=get_current_blog_id();if(isset(self::$records[$blog]))self::$records[$blog]['failed']=true;
    }
    public static function flush(): void {
        $error=error_get_last();$fatal=$error&&in_array($error['type'],array(E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR),true);
        foreach(self::$records as$blog=>$record) {
            $switched=(int)$blog!==get_current_blog_id();if($switched)switch_to_blog($blog);
            try {
                $state=$record['state'];$state['result']=$record['depth']||$record['failed']||$fatal?'FAILED':'COMPLETED';
                $latest=self::state();
                if(($latest['last_scheduler_seen_at']??0)>($state['last_scheduler_seen_at']??0))$state['result']=$latest['result'];
                foreach(array_keys($state)as$key)if($key!=='result')$state[$key]=max((int)($state[$key]??0),(int)($latest[$key]??0));
                update_option(self::OPTION,$state,false);
            } finally {if($switched)restore_current_blog();}
        }
        self::$records=array();
    }
    public static function health(bool $disabled,array $state,int $now): array {
        $seen=(int)($state['last_scheduler_seen_at']??0);$external=(int)($state['last_external_seen_at']??0);$previous=(int)($state['previous_external_seen_at']??0);
        $age=$seen>0?max(0,$now-$seen):null;
        // Two independent executions, spaced >=1 minute and <=15 minutes, establish regularity.
        $confirmed=$disabled && $external>0 && $external<=$now && $now-$external<=self::RECENT && $previous>0 && $external-$previous>=60 && $external-$previous<=self::RECENT;
        $status=$disabled&&!$confirmed?'WARNING':($seen&&$age>self::RECENT?'WARNING':'OK');
        if(($state['result']??'')==='FAILED')$status='WARNING';
        $message=$disabled&&!$confirmed
            ?'Las tareas programadas pueden no estar ejecutándose. WP-Cron está desactivado y EIPSI no ha detectado recientemente un scheduler externo regular.'
            :($confirmed?'Se detectaron ejecuciones externas de WordPress Cron.':'WP-Cron por visitas está activado; depende del tráfico del sitio.');
        if($seen && $age>self::RECENT)$message.=' La última ejecución detectada tiene más de 15 minutos.';
        if(($state['result']??'')==='FAILED')$message.=' La última ejecución no terminó correctamente.';
        return array('state'=>$status,'message'=>$message,'external_confirmed'=>$confirmed,'age_seconds'=>$age);
    }
}
