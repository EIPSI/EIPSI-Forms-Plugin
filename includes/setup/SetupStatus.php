<?php
namespace EIPSI\Setup;
use EIPSI\StudyEngine\Configuration\ServerKeyrings;
final class SetupStatus {
    /** Read-only summaries from existing owners. No repairs, emails, leases or scheduler callbacks. */
    public static function inspect(): array {
        \EIPSI_Admin_Authorization_Policy::demand('privacy.manage');
        $keys=ServerKeyrings::status();$cards=array();$keyReady=$keys['seed']['ready']&&$keys['access']['ready'];
        $cards['keys']=array('state'=>$keyReady?'OK':(($keys['seed']['error']||$keys['access']['error'])?'ERROR':'MISSING'),'required'=>true,'keys'=>$keys,'tab'=>'setup');
        try{require_once EIPSI_FORMS_PLUGIN_DIR.'includes/schema/class-schema-diagnostic.php';$schema=\EIPSI_Schema_Diagnostic::report();$cards['database']=array('state'=>$schema['status']==='ok'?'OK':'ATTENTION','required'=>true,'tab'=>'schema-status','issues'=>$schema['summary']['tables_with_issues']);}catch(\Throwable $e){$cards['database']=array('state'=>'ERROR','required'=>true,'tab'=>'schema-status');}
        try{$cron=\EIPSI\Scheduler\Diagnostics::inspect();$cards['scheduler']=array('state'=>$cron['health']['state']==='OK'?'OK':'ATTENTION','required'=>false,'tab'=>'scheduler','wp_cron_enabled'=>!$cron['disabled'],'external_confirmed'=>$cron['health']['external_confirmed'],'heartbeat'=>$cron['heartbeat']['last_scheduler_seen_at']??null,'overdue'=>$cron['overdue']);}catch(\Throwable $e){$cards['scheduler']=array('state'=>'ERROR','required'=>false,'tab'=>'scheduler');}
        try{$smtp=new \EIPSI_SMTP_Service();$cards['email']=array('state'=>$smtp->is_configured()?'OK':'OPTIONAL','required'=>false,'tab'=>'smtp');}catch(\Throwable $e){$cards['email']=array('state'=>'ERROR','required'=>false,'tab'=>'smtp');}
        $privacy=get_option(\EIPSI\Dataset\EffectiveMetadataPolicy::OPTION,null);$cards['privacy']=array('state'=>is_array($privacy)?'OK':'ATTENTION','required'=>false,'tab'=>'privacy-security');
        try{$api=new \EIPSI\ExternalApi\Store();$enabled=$api->enabled();$count=0;if($enabled)$count=(int)$api->row($api->prepare('SELECT COUNT(*) n FROM '.$api->table('keys').' WHERE revoked_at IS NULL AND (expires_at IS NULL OR expires_at>%d)',time()))['n'];$cards['api']=array('state'=>!$enabled?'DISABLED':($count?'OK':'ATTENTION'),'required'=>false,'tab'=>'external-api','enabled'=>$enabled,'keys_configured'=>$count>0);}catch(\Throwable $e){$cards['api']=array('state'=>'ERROR','required'=>false,'tab'=>'external-api');}
        $critical=!$keyReady||$cards['database']['state']!=='OK';$attention=false;foreach(array('scheduler','privacy','api')as $name)if(in_array($cards[$name]['state'],array('ATTENTION','ERROR'),true))$attention=true;
        return array('state'=>$critical?'CRITICAL':($attention?'ATTENTION':'OK'),'cards'=>$cards);
    }
}
