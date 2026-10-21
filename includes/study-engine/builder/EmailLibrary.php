<?php
namespace EIPSI\StudyEngine\Builder;
use EIPSI\StudyEngine\Contracts\Check;
use EIPSI\StudyEngine\Longitudinal\InitialScreen;
use EIPSI\StudyEngine\Persistence\PersistenceConflict;
/** Admin library only. Published mail always uses the Study's immutable template snapshot. */
final class EmailLibrary {
    public const OPTION='eipsi_study_email_library_v1';
    private static function document(): array {$d=get_option(self::OPTION,array('revision'=>0,'templates'=>array()));if(!is_array($d)||!isset($d['revision'],$d['templates']))throw new \RuntimeException('EMAIL_LIBRARY_CORRUPTED');return $d;}
    public static function read(): array {
        Service::authorize();$defaults=\EIPSI\StudyEngine\Longitudinal\EmailTemplate::defaults(function_exists('eipsi_admin_language')?eipsi_admin_language():'en');
        $smtp=class_exists('EIPSI_SMTP_Service')?new \EIPSI_SMTP_Service():null;$config=$smtp?$smtp->get_config():null;
        $sender=$config['from_name']??get_option('eipsi_investigator_name',get_bloginfo('name'));if(!is_string($sender)||trim($sender)==='')$sender=get_bloginfo('name');
        foreach($defaults as &$template)$template['from_name']=$sender;unset($template);
        $document=self::document();foreach($document['templates'] as &$entry)foreach(array('subject','html') as $key)$entry[$key]=InitialScreen::removeRetiredEmailLink($entry[$key]);unset($entry);return array_merge($document,array('variables'=>InitialScreen::VARIABLES,'defaults'=>$defaults));
    }
    public static function save(array $entry,int $revision): array {
        Service::authorize();Check::fields($entry,array('name','purpose','subject','html'),array('id','from_name','from_email','content_language'));
        if(!is_string($entry['name'])||trim($entry['name'])===''||strlen($entry['name'])>240||preg_match('/[\r\n\x00-\x1f]/',$entry['name'])||!in_array($entry['purpose'],array('VERIFY_PARTICIPANT','ASSESSMENT_AVAILABLE','ASSESSMENT_REMINDER'),true))throw new \DomainException('INVALID_EMAIL_LIBRARY_ENTRY');
        if(array_key_exists('content_language',$entry))\EIPSI\I18n\ContentDefaults::valid($entry['content_language']);
        $template=InitialScreen::template(array_intersect_key($entry,array_flip(array('subject','html','from_name','from_email'))));unset($template['from_email']);$old=self::document();if($old['revision']!==$revision)throw new PersistenceConflict('EMAIL_LIBRARY_REVISION');
        $id=$entry['id']??'ET_'.bin2hex(random_bytes(16));Check::id($id,'template');$found=false;$next=$old;$next['revision']++;
        $row=array_merge($template,array('id'=>$id,'name'=>trim($entry['name']),'purpose'=>$entry['purpose'],'revision'=>$next['revision']));
        foreach($next['templates'] as &$t)if($t['id']===$id){if(isset($entry['content_language'])||isset($t['content_language']))$row['content_language']=$entry['content_language']??$t['content_language'];$t=$row;$found=true;}unset($t);
        if(!$found){$row['content_language']=$entry['content_language']??(function_exists('eipsi_admin_language')?eipsi_admin_language():'en');if(isset($entry['id']))throw new \DomainException('EMAIL_TEMPLATE_NOT_FOUND');if(count($next['templates'])>=50)throw new \DomainException('EMAIL_LIBRARY_LIMIT');$next['templates'][]=$row;}
        self::commit($old,$next);return self::read();
    }
    public static function delete(string $id,int $revision): array {
        Service::authorize();Check::id($id,'template');$old=self::document();if($old['revision']!==$revision)throw new PersistenceConflict('EMAIL_LIBRARY_REVISION');$next=$old;$next['templates']=array_values(array_filter($old['templates'],static function($t)use($id){return $t['id']!==$id;}));if(count($next['templates'])===count($old['templates']))throw new \DomainException('EMAIL_TEMPLATE_NOT_FOUND');$next['revision']++;self::commit($old,$next);return self::read();
    }
    private static function commit(array $old,array $next): void {
        global $wpdb;
        if(get_option(self::OPTION,false)===false&&!add_option(self::OPTION,$old,'',false))throw new PersistenceConflict('EMAIL_LIBRARY_REVISION');
        if($wpdb->query($wpdb->prepare('UPDATE '.$wpdb->options.' SET option_value=%s WHERE option_name=%s AND option_value=%s',maybe_serialize($next),self::OPTION,maybe_serialize($old)))!==1)throw new PersistenceConflict('EMAIL_LIBRARY_REVISION');
        wp_cache_delete(self::OPTION,'options');
    }
    public static function preview(array $entry): array {
        Service::authorize();Check::fields($entry,array('purpose','subject','html'),array('from_name','from_email'));$key=$entry['purpose'];if(!in_array($key,array('VERIFY_PARTICIPANT','ASSESSMENT_AVAILABLE','ASSESSMENT_REMINDER'),true))throw new \DomainException('UNKNOWN_EMAIL_TEMPLATE');unset($entry['purpose']);$entry=InitialScreen::template($entry);
        return (new \EIPSI\StudyEngine\Longitudinal\EmailTemplate())->render($key,array('study_name'=>'Estudio de ejemplo','assessment_name'=>'Evaluación de ejemplo','available_time'=>'2026-10-13 09:00 UTC','due_time'=>'2026-10-13 12:00 UTC','direct_link'=>'https://example.invalid/access','withdrawal_link'=>'https://example.invalid/withdrawal'),array($key=>$entry));
    }
    public static function screen(): void {
        Service::authorize();Admin::assets();
        echo '<div class="wrap" lang="'.esc_attr(eipsi_admin_language()).'"><h1>'.esc_html(eipsi_admin_translate('Email Templates')).'</h1><p>'.esc_html(eipsi_admin_translate('Reusable global library. Each study retains its own customizable copy; editing this library does not modify published versions.')).'</p><div id="eipsi-email-library"><p role="status">'.esc_html(eipsi_admin_translate('Loading templates…')).'</p></div></div>';
    }
}
