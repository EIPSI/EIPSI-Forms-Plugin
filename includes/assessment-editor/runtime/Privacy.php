<?php
namespace EIPSI\AssessmentEditor\Runtime;
require_once dirname(__DIR__).'/Definition.php';
/** Joined to the existing Study DO_NOT_USE cleanup transaction; no separate policy or commit. */
final class Privacy {
    public static function eraseStudy(\EIPSI\StudyEngine\Persistence\Database $db,string $id): void {
        $db->requireTransaction();global $wpdb;$name=$wpdb->prefix.'eipsi_engine_native_executions';$table=$db->row($db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$name));if(!$table)return;if(strcasecmp($table['ENGINE'],'InnoDB')!==0)throw new \RuntimeException('Native privacy requires InnoDB');
        $parent=$db->row($db->prepare('SELECT data_policy FROM '.$db->table('study_executions').' WHERE id=%s FOR UPDATE',$id));if(!$parent||$parent['data_policy']!=='DO_NOT_USE')throw new \RuntimeException('Native privacy scope required');
        $rows=$db->rows($db->prepare('SELECT id FROM '.$db->table('native_executions')." WHERE JSON_UNQUOTE(JSON_EXTRACT(binding_json,'$.execution_id'))=%s AND JSON_UNQUOTE(JSON_EXTRACT(binding_json,'$.kind')) IN ('STUDY_SESSION','STUDY_ANONYMOUS') FOR UPDATE",$id));
        foreach($rows as $r){$db->query($db->prepare('DELETE FROM '.$db->table('native_commands').' WHERE execution_id=%s',$r['id']));$db->query($db->prepare('UPDATE '.$db->table('native_executions')." SET state='REDACTED', binding_json=%s, partial_json=NULL, partial_started_at=NULL, expires_at=NULL, final_json=NULL, metadata_json=NULL, completed_at=NULL, page_index=0, revision=revision+1 WHERE id=%s",\EIPSI\AssessmentEditor\Definition::json(array('kind'=>'REDACTED')),$r['id']));}
    }
    public static function eraseUser(int $user): void {
        $store=new Store();$store->transaction(function()use($store,$user){$rows=$store->db->rows($store->prepare('SELECT id FROM '.$store->table('executions')." WHERE JSON_UNQUOTE(JSON_EXTRACT(binding_json,'$.kind'))='WORDPRESS_USER' AND JSON_EXTRACT(binding_json,'$.user_id')=%d FOR UPDATE",$user));foreach($rows as $r){$store->db->query($store->prepare('DELETE FROM '.$store->table('commands').' WHERE execution_id=%s',$r['id']));$store->update('executions',array('state'=>'REDACTED','binding_json'=>'{"kind":"REDACTED"}','partial_json'=>null,'partial_started_at'=>null,'expires_at'=>null,'final_json'=>null,'metadata_json'=>null,'completed_at'=>null,'page_index'=>0),$store->prepare('id=%s',$r['id']));}});
    }
}
