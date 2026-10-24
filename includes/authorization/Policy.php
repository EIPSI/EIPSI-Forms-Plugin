<?php
/** Administrative WordPress authorization only; never participant or API-key authentication. */
if (!defined('ABSPATH')) { exit; }
final class EIPSI_Admin_Authorization_Policy {
    public const VERSION = 1;
    private const ACTIONS = array(
        'access'=>'eipsi_access',
        'assessments.view'=>'eipsi_view_assessments','assessments.edit'=>'eipsi_manage_assessments',
        'assessments.publish'=>'eipsi_publish_assessments','assessments.delete'=>'eipsi_delete_assessments',
        'studies.view'=>'eipsi_view_studies','studies.edit'=>'eipsi_manage_studies',
        'studies.publish'=>'eipsi_publish_studies','studies.delete'=>'eipsi_delete_studies',
        'progress.view'=>'eipsi_view_progress','participants.manage'=>'eipsi_manage_participants',
        'responses.view'=>'eipsi_view_responses','data.export'=>'eipsi_export_data',
        'identities.view'=>'eipsi_view_identities','audit.view'=>'eipsi_view_audit',
        'templates.manage'=>'eipsi_manage_templates','settings.manage'=>'eipsi_manage_settings',
        'email.manage'=>'eipsi_manage_email','privacy.manage'=>'eipsi_manage_privacy',
        'api.manage'=>'eipsi_manage_api','scheduler.manage'=>'eipsi_manage_scheduler','schema.manage'=>'eipsi_manage_schema'
    );
    public static function capability(string $action): string {
        if (!isset(self::ACTIONS[$action])) { throw new LogicException('EIPSI_UNKNOWN_AUTHORIZATION_ACTION'); }
        return self::ACTIONS[$action];
    }
    public static function actions(): array { return self::ACTIONS; }
    public static function allows(string $action): bool {
        return isset(self::ACTIONS[$action]) && current_user_can(self::ACTIONS['access']) && current_user_can(self::ACTIONS[$action]);
    }
    public static function demand(string $action): void {
        if (!self::allows($action)) { throw new DomainException('BUILDER_FORBIDDEN',403); }
    }
    public static function configurationTabs(): array {
        return array('setup'=>'privacy.manage','database'=>'schema.manage','smtp'=>'email.manage','privacy-security'=>'privacy.manage',
            'schema-status'=>'schema.manage','external-api'=>'api.manage','scheduler'=>'scheduler.manage','fallback-messages'=>'settings.manage');
    }
    public static function configuration(): bool {
        foreach (self::configurationTabs() as $action) { if (self::allows($action)) { return true; } }
        return self::allows('settings.manage');
    }
    /** Unknown operations fail closed, even if a caller has the landing-page capability. */
    public static function assessmentOperation(string $operation): string {
        $groups=array(
            'assessments.view'=>array('list','load','versions','load_version','export_definition','export_question'),
            'assessments.edit'=>array('save','duplicate','import_definition','import_question'),
            'assessments.publish'=>array('publish','retire'), 'assessments.delete'=>array('delete'),
            'participants.manage'=>array('assign'), 'data.export'=>array('export_execution','export')
        );
        return self::operation($groups,$operation);
    }
    public static function studyOperation(string $operation): string {
        $groups=array(
            'studies.view'=>array('list','load','validate','study_state_summary','native_templates','templates','email_preview','experience','simulate'),
            'studies.edit'=>array('create','save','clone','discard','edit','duplicate_study','import','source'),
            'studies.publish'=>array('publish','retire','activate','study_state','archive_study'), 'studies.delete'=>array('delete_study'),
            'templates.manage'=>array('email_template_preview','email_library','email_library_save','email_library_delete')
        );
        return self::operation($groups,$operation);
    }
    public static function managementOperation(array $request): string {
        if (($request['operation']??'')!=='manage') {
            return self::operation(array('data.export'=>array('manage_export','manage_export_step','manage_export_download')), $request['operation']??'');
        }
        return self::operation(array('progress.view'=>array('summary','participants','opportunities'),
            'responses.view'=>array('attempts','attempt'), 'identities.view'=>array('identity'),
            'audit.view'=>array('communications','audit','diagnosis'), 'data.export'=>array('jobs')), $request['section']??'summary');
    }
    private static function operation(array $groups,string $operation): string {
        foreach ($groups as $action=>$operations) { if (in_array($operation,$operations,true)) { return $action; } }
        throw new DomainException('BUILDER_FORBIDDEN',403);
    }
    public static function client(): array {
        $out=array();foreach(self::ACTIONS as $action=>$cap){$out[$action]=self::allows($action);}return $out;
    }
    public static function roleActions(): array {
        $viewer=array('access','assessments.view','studies.view','progress.view');
        $data=array_merge($viewer,array('responses.view','data.export','audit.view'));
        $researcher=array_merge($data,array('assessments.edit','assessments.publish','assessments.delete','studies.edit','studies.publish','studies.delete','participants.manage','templates.manage'));
        return array('eipsi_administrator'=>array_keys(self::ACTIONS),'eipsi_researcher'=>$researcher,'eipsi_data_manager'=>$data,'eipsi_viewer'=>$viewer);
    }
    /** Runs once per site/model version; preserve existing role customizations and assignments. */
    public static function upgrade(): void {
        if ((int)get_option('eipsi_rbac_version',0)>=self::VERSION) { return; }
        $names=array('eipsi_administrator'=>'EIPSI Administrator','eipsi_researcher'=>'EIPSI Researcher','eipsi_data_manager'=>'EIPSI Data Manager','eipsi_viewer'=>'EIPSI Viewer');
        foreach(self::roleActions() as $slug=>$actions){
            if(get_role($slug))continue;
            $caps=array('read'=>true);foreach($actions as $action)$caps[self::capability($action)]=true;
            if(in_array($slug,array('eipsi_administrator','eipsi_researcher'),true))$caps['upload_files']=true;
            add_role($slug,$names[$slug],$caps);
        }
        $admin=get_role('administrator');if(!$admin)return;
        foreach(self::ACTIONS as $cap){if(!$admin->has_cap($cap))$admin->add_cap($cap);}
        if(isset($GLOBALS['current_user'])&&$GLOBALS['current_user'] instanceof WP_User)$GLOBALS['current_user']->get_role_caps();
        update_option('eipsi_rbac_version',self::VERSION,false);
    }
    public static function switched(int $site): void {
        // Core temporarily replaces role definitions while populating a new site.
        if(is_multisite()&&wp_installing())return;
        if(function_exists('wp_is_site_initialized')&&!wp_is_site_initialized($site))return;
        self::upgrade();
    }
    public static function initialized($site): void {
        if(!is_multisite())return;
        $switch=get_current_blog_id()!==(int)$site->blog_id;
        if($switch)switch_to_blog((int)$site->blog_id);
        try{self::upgrade();}finally{if($switch)restore_current_blog();}
    }
    public static function roleLabels(array $roles): array {
        $labels=array('eipsi_administrator'=>'EIPSI Administrator','eipsi_researcher'=>'EIPSI Researcher','eipsi_data_manager'=>'EIPSI Data Manager','eipsi_viewer'=>'EIPSI Viewer');
        foreach($labels as $slug=>$label)if(isset($roles[$slug]))$roles[$slug]['name']=eipsi_admin_translate($label);
        return $roles;
    }
}
