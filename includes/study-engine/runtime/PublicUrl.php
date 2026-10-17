<?php
namespace EIPSI\StudyEngine\Runtime;
use EIPSI\StudyEngine\Persistence as P;
/** URL aliases never change the canonical Study identity or participant credentials. */
final class PublicUrl {
    const PREFIX='eipsi_engine_public_url_';
    public static function url(array $d): string {return add_query_arg('eipsi_study',($d['protocol_metadata']['public_slug']??'')?:$d['study']['public_id'],home_url('/'));}
    public static function resolve(P\Database $db,string $public): string {global $wpdb;$r=$db->row($db->prepare('SELECT option_value FROM `'.$wpdb->options.'` WHERE option_name=%s',self::PREFIX.$public));if(!$r)return $public;$v=json_decode($r['option_value'],true);return $v['public_id']??$public;}
    public static function reserve(P\Database $db,string $slug,string $study,string $public): void {
        $db->requireTransaction();if($slug==='')return;if(!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D',$slug))throw new \DomainException('INVALID_PUBLIC_URL');global $wpdb;
        $engine=$db->row($db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->options));if(!$engine||strcasecmp($engine['ENGINE'],'InnoDB')!==0)throw new P\IntegrityFailure('PUBLIC_URL_TRANSACTION_REQUIRED');
        $other=$db->row($db->prepare('SELECT study_id FROM '.$db->table('study_versions')." WHERE JSON_UNQUOTE(JSON_EXTRACT(protocol_json,'$.study.public_id'))=%s AND study_id<>%s LIMIT 1",$slug,$study));if($other)throw new P\PersistenceConflict('PUBLIC_URL_ALREADY_USED');
        $value=wp_json_encode(array('study_id'=>$study,'public_id'=>$public));$db->query($db->prepare('INSERT IGNORE INTO `'.$wpdb->options.'` (option_name,option_value,autoload) VALUES (%s,%s,%s)',self::PREFIX.$slug,$value,'off'));
        $row=$db->row($db->prepare('SELECT option_value FROM `'.$wpdb->options.'` WHERE option_name=%s FOR UPDATE',self::PREFIX.$slug));if(!$row||$row['option_value']!==$value)throw new P\PersistenceConflict('PUBLIC_URL_ALREADY_USED');
    }
    public static function deleteStudy(P\Database $db,string $study): void {global $wpdb;$db->query($db->prepare('DELETE FROM `'.$wpdb->options.'` WHERE LEFT(option_name,%d)=%s AND JSON_UNQUOTE(JSON_EXTRACT(option_value,%s))=%s',strlen(self::PREFIX),self::PREFIX,'$.study_id',$study));}
}
