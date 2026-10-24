<?php
namespace EIPSI\AssessmentEditor;

/** Dedicated, non-autoloaded options. Revision CAS prevents lost updates. */
final class Repository {
    const PREFIX='eipsi_native_assessment_';
    private static function authorize(string $action='assessments.view'): void { \EIPSI_Admin_Authorization_Policy::demand($action); }
    public static function load(string $id): array {
        self::authorize(); Definition::id($id); $json=get_option(self::PREFIX.$id,null);
        if(!is_string($json)) throw new \RuntimeException('Assessment not found',404);
        $record=json_decode($json,true,32,JSON_THROW_ON_ERROR); $record['definition']=Definition::validate($record['definition']); return $record;
    }
    /** Published snapshots are read-only and scoped to the assessment identity. */
    public static function versions(string $id): array {
        self::authorize();Definition::id($id);require_once __DIR__.'/runtime/Store.php';$store=new Runtime\Store();global $wpdb;
        if(!$store->db->row($store->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->prefix.'eipsi_engine_native_versions')))return array();
        $rows=$store->db->rows($store->prepare('SELECT id,assessment_id,author_revision,definition_hash,state,created_at FROM '.$store->table('versions').' WHERE assessment_id=%s ORDER BY author_revision,created_at,id',$id));
        foreach($rows as $i=>&$row){$row['number']=$i+1;$row['author_revision']=(int)$row['author_revision'];$row['created_at']=(int)$row['created_at'];}unset($row);return array_reverse($rows);
    }
    public static function loadVersion(string $id,string $version): array {
        self::authorize();Definition::id($id);Definition::id($version);$versions=self::versions($id);$summary=null;foreach($versions as $row)if($row['id']===$version)$summary=$row;if(!$summary)throw new \RuntimeException('Version not found',404);
        $store=new Runtime\Store();$row=$store->db->row($store->prepare('SELECT definition_json FROM '.$store->table('versions').' WHERE id=%s AND assessment_id=%s',$version,$id));
        if(!$row||!hash_equals($summary['definition_hash'],hash('sha256',$row['definition_json'])))throw new \RuntimeException('Published snapshot integrity failed',409);
        return array('definition'=>Definition::validate(json_decode($row['definition_json'],true,512,JSON_THROW_ON_ERROR)),'version'=>$summary,'read_only'=>true);
    }
    public static function save(array $d,int $revision): array {
        self::authorize('assessments.edit'); $d=Definition::validate($d);if($revision<0) Definition::fail('Invalid revision');
        $name=self::PREFIX.$d['id'];
        if($revision>0){$prior=get_option($name,null);$decoded=is_string($prior)?json_decode($prior,true):null;foreach(($decoded['definition']['authoring']['variable_next']??array()) as $prefix=>$next)if(isset($d['authoring']['variable_next'][$prefix])&&is_int($next))$d['authoring']['variable_next'][$prefix]=max($d['authoring']['variable_next'][$prefix],$next);}
        $record=array('definition'=>$d,'revision'=>$revision+1,'updated_at'=>gmdate('c'));
        $json=Definition::json($record);
        if($revision===0) { if(!add_option($name,$json,'',false)) throw new \RuntimeException('Already saved; reopen before saving',409); }
        else {
            global $wpdb; $old=get_option($name,null); $decoded=is_string($old)?json_decode($old,true):null;
            if(!$decoded||$decoded['revision']!==$revision) throw new \RuntimeException('Changed in another window. Reopen before saving.',409);
            $changed=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s",$json,$name,$old));
            if($changed!==1) throw new \RuntimeException('Concurrent save failed. Reopen before saving.',409);
            wp_cache_delete($name,'options');
        }
        return $record;
    }
    public static function duplicate(string $id,int $revision): array {
        self::authorize('assessments.edit');$record=self::load($id);if($record['revision']!==$revision)throw new \RuntimeException('Changed before duplication',409);$d=self::freshDefinition($record['definition']);$d['title']=mb_substr($d['title'],0,180).' — copia';return self::save($d,0);
    }
    public static function freshDefinition(array $d): array {
        $d=Definition::validate($d);$sourcePages=$d['flow']['pages'];$fresh=static function($prefix){return $prefix.'_'.bin2hex(random_bytes(16));};$d['id']=$fresh('AE');
        $walk=function(&$items)use(&$walk,$fresh){foreach($items as &$e){$e['id']=$fresh('EL');if($e['response_key'])$e['response_key']=$fresh('R');foreach($e['options'] as &$o)$o['id']=$fresh('O');unset($o);if(isset($e['properties']['matrix_rows']))foreach($e['properties']['matrix_rows'] as &$r){$r['id']=$fresh('ROW');$r['response_key']=$fresh('R');}unset($r);if(isset($e['properties']['label_settings']))foreach($e['properties']['label_settings'] as &$l)$l['id']=$fresh('L');unset($l);$walk($e['children']);}unset($e);};$walk($d['elements']);$d['flow']['pages']=array();$d=Definition::validate($d);foreach($d['flow']['pages'] as $i=>&$p){$p['title']=$sourcePages[$i]['title'];$p['description_html']=$sourcePages[$i]['description_html'];}unset($p);return $d;
    }
    public static function delete(string $id,int $revision): void {
        self::authorize('assessments.delete');Definition::id($id);require_once __DIR__.'/runtime/Owner.php';$store=new Runtime\Store();if(\EIPSI_Admin_Authorization_Policy::allows('schema.manage'))$store->install();else $store->ready();$store->transaction(function()use($store,$id,$revision){global $wpdb;$name=self::PREFIX.$id;$old=$store->db->row($store->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",$name));$record=$old?json_decode($old['option_value'],true):null;if(!$record||(int)$record['revision']!==$revision)throw new \RuntimeException('Changed before deletion',409);if($store->db->row($store->prepare('SELECT id FROM '.$store->table('versions').' WHERE assessment_id=%s LIMIT 1',$id)))throw new \RuntimeException('Assessment has published snapshots; deletion is blocked to preserve references.',409);
            require_once __DIR__.'/PartialProgress.php';PartialProgress::clearAssessment($id);
            $rows=$store->db->rows($store->prepare("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s FOR UPDATE",$wpdb->esc_like('_transient_eipsi_native_partial_').'%'));foreach($rows as $row){$r=maybe_unserialize($row['option_value']);if(is_array($r)&&($r['assessment_id']??null)===$id)delete_transient(substr($row['option_name'],strlen('_transient_')));}
            $store->db->query($store->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND BINARY option_value=BINARY %s",$name,$old['option_value']));wp_cache_delete($name,'options');
        });
    }
    public static function listing(): array {
        self::authorize(); global $wpdb;
        $rows=$wpdb->get_col($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 100",$wpdb->esc_like(self::PREFIX).'%'));
        $out=array(); foreach($rows as $json) { $r=json_decode($json,true); if(!is_array($r)||!isset($r['definition']['id'],$r['definition']['title'],$r['revision'])) continue; $out[]=array('id'=>$r['definition']['id'],'title'=>$r['definition']['title'],'revision'=>$r['revision'],'updated_at'=>$r['updated_at']); } return $out;
    }
}
