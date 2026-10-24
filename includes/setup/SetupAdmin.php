<?php
namespace EIPSI\Setup;
use EIPSI\StudyEngine\Configuration\SecretStore;
use EIPSI\StudyEngine\Configuration\ServerKeyrings;
final class SetupAdmin {
    public static function dispatch(array $payload): array {
        \EIPSI_Admin_Authorization_Policy::demand('privacy.manage');\EIPSI_Http_Policy::post(admin_url());
        if(!is_string($payload['_wpnonce']??null)||!wp_verify_nonce($payload['_wpnonce'],'eipsi_setup_keys'))throw new \RuntimeException('SETUP_CSRF',403);
        if(($payload['confirmation']??null)!=='1')throw new \RuntimeException('SETUP_CONFIRMATION_REQUIRED',400);
        return array('result'=>SecretStore::provision()?'GENERATED':'ALREADY_CONFIGURED');
    }
    public static function post(): void {
        nocache_headers();header('Cache-Control: private, no-store');
        try{$r=self::dispatch(wp_unslash($_POST));wp_safe_redirect(add_query_arg(array('page'=>'eipsi-configuration','tab'=>'setup','setup_result'=>$r['result']),admin_url('admin.php')),303);exit;}
        catch(\Throwable $e){$code=in_array((int)$e->getCode(),array(400,403,405,409,503),true)?(int)$e->getCode():503;wp_die(esc_html(self::error($e->getMessage())),'',array('response'=>$code));}
    }
    private static function error(string $code): string {
        $messages=array('SERVER_KEYS_INSTALLATION_SECRETS_REQUIRED'=>'Secure provisioning requires valid WordPress installation keys and salts and OpenSSL AES-GCM. Ask your server administrator to review the installation configuration.','SERVER_KEY_STORE_UNDECRYPTABLE'=>'Server key store could not be decrypted. Restore the encrypted store and its original WordPress keys and salts. No keys were regenerated.','SERVER_KEYS_EXISTING_DATA'=>'Existing Study executions or access credentials require recovery or key rotation. Initial key generation is unavailable.','SERVER_KEYS_EXTERNAL'=>'Server keys are configured externally. Setup does not copy or overwrite external configuration.','SETUP_SCHEMA_REQUIRED'=>'Review Database settings before generating server keys.','SETUP_CSRF'=>'Authorization expired. Reload the page.','BUILDER_FORBIDDEN'=>'You do not have sufficient permissions to access this page.','METHOD_NOT_ALLOWED'=>'This action requires POST.','SETUP_CONFIRMATION_REQUIRED'=>'Confirm the backup and recovery requirement before generating keys.');
        return eipsi_admin_translate($messages[$code]??'Could not complete secure key provisioning. No existing keys were replaced.');
    }
    public static function url(string $tab): string {return add_query_arg(array('page'=>'eipsi-configuration','tab'=>$tab),admin_url('admin.php'));}
    /** Authorized component summaries only; never connects to an external database or sends mail. */
    public static function pending(): array {
        $out=array();$tabs=\EIPSI_Admin_Authorization_Policy::configurationTabs();
        foreach(array('setup','database','smtp')as$tab){
            if(!\EIPSI_Admin_Authorization_Policy::allows($tabs[$tab]))continue;
            try{
                if($tab==='setup'){$keys=ServerKeyrings::status();$pending=!$keys['seed']['ready']||!$keys['access']['ready'];}
                elseif($tab==='database'){
                    require_once EIPSI_FORMS_PLUGIN_DIR.'includes/schema/class-schema-diagnostic.php';$pending=\EIPSI_Schema_Diagnostic::report()['status']!=='ok';
                    $external=get_option('eipsi_external_db_enabled',null);if($external!==null&&!$external)$pending=true;
                    if(get_option('eipsi_external_db_enabled',false))$pending=$pending||get_option('eipsi_external_db_last_error','')!==''||!get_option('eipsi_external_db_host','')||!get_option('eipsi_external_db_user','')||!get_option('eipsi_external_db_name','');
                }else{$pending=!(new \EIPSI_SMTP_Service())->is_configured();}
            }catch(\Throwable $e){$pending=true;}
            if($pending)$out[$tab]=array('setup'=>'Setup','database'=>'Database & Storage','smtp'=>'SMTP')[$tab];
        }return $out;
    }
    private static function styles(): void {
        if(!empty($GLOBALS['eipsi_readiness_styles']))return;$GLOBALS['eipsi_readiness_styles']=true;
        echo '<style>'.file_get_contents(EIPSI_FORMS_PLUGIN_DIR.'assets/css/setup.css').'</style>';
    }
    public static function warningIcon(string $tab,array $pending): string {
        $cap=\EIPSI_Admin_Authorization_Policy::configurationTabs()[$tab]??null;if(!$cap||!\EIPSI_Admin_Authorization_Policy::allows($cap))return '';
        return '<span class="dashicons dashicons-warning eipsi-setup-tab-warning"'.(isset($pending[$tab])?'':' hidden').' role="img" aria-label="'.esc_attr(eipsi_admin_translate('Needs attention')).'" title="'.esc_attr(eipsi_admin_translate('Needs attention')).'"></span>';
    }
    public static function configurationNotices(string $active,array $pending): void {
        self::styles();$messages=array('setup'=>'Generate or configure the Study Engine server keys in Setup.','database'=>'Review the database configuration or schema status.','smtp'=>'Configure SMTP and test email delivery before using email notifications.');
        foreach($pending as$tab=>$label){if($tab===$active)continue;
            echo '<div class="notice notice-warning eipsi-readiness-notice" data-pending="'.esc_attr($tab).'"><span class="dashicons dashicons-warning" aria-hidden="true"></span><div><strong>'.esc_html(eipsi_admin_translate($label)).'</strong><p>'.esc_html(eipsi_admin_translate($messages[$tab])).'</p></div><a class="button eipsi-readiness-action" href="'.esc_url(self::url($tab)).'">'.esc_html(eipsi_admin_translate('Review configuration')).'</a></div>';
        }
    }
    public static function notice(): void {
        if(!empty($GLOBALS['eipsi_readiness_notice_rendered'])||strpos((string)($_GET['page']??''),'eipsi-')!==0||($_GET['page']??'')==='eipsi-configuration')return;
        $pending=self::pending();if(!$pending)return;$GLOBALS['eipsi_readiness_notice_rendered']=true;self::styles();
        $labels=array_map('eipsi_admin_translate',array_values($pending));
        echo '<div class="notice notice-warning eipsi-readiness-notice" data-pending="configuration"><span class="dashicons dashicons-warning" aria-hidden="true"></span><div><strong>'.esc_html(eipsi_admin_translate('Configuration needs attention')).'</strong><p>'.esc_html(sprintf(eipsi_admin_translate('Review the pending configuration: %s.'),implode(', ',$labels))).'</p></div><a class="button eipsi-readiness-action" href="'.esc_url(self::url(key($pending))).'">'.esc_html(eipsi_admin_translate('Review configuration')).'</a></div>';
    }
    private static function keysSection(array $card,array $labels): void {
        echo '<section class="setup-keys-panel state-'.esc_attr($card['state']).'" data-component="keys" aria-labelledby="setup-keys-title"><h2 id="setup-keys-title">'.esc_html(eipsi_admin_translate('Study Engine server keys')).'</h2><span class="setup-badge">'.esc_html(eipsi_admin_translate($labels[$card['state']])).'</span><p>'.esc_html(eipsi_admin_translate('Required for public Studies. Keys are never displayed.')).'</p>';
                echo '<div class="setup-key-status">';ServerKeyrings::table($card['keys']);echo '</div>';if($card['keys']['seed']['source']==='EIPSI'||$card['keys']['access']['source']==='EIPSI')echo '<p class="setup-backup">'.esc_html(eipsi_admin_translate('Changing WordPress keys or salts can make managed server keys unreadable. Preserve their originals with your database backup.')).'</p>';$none=true;foreach($card['keys'] as $row)if($row['source']!=='NONE')$none=false;
                if($none){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="eipsi_setup_keys">';wp_nonce_field('eipsi_setup_keys');echo '<label class="setup-confirm"><input type="checkbox" name="confirmation" value="1" required><span>'.esc_html(eipsi_admin_translate('I understand that recovery requires the database and the original WordPress keys and salts. I will back up both securely.')).'</span></label><button type="submit" class="button button-primary">'.esc_html(eipsi_admin_translate('Generate server keys')).'</button></form>';}
                foreach($card['keys'] as $row)if($row['error']==='SERVER_KEY_STORE_UNDECRYPTABLE'){echo '<p class="setup-error" role="alert">'.esc_html(self::error($row['error'])).'</p>';break;}
                echo '<p class="description">'.esc_html(eipsi_admin_translate('No reset or regeneration is available here. Existing external keys are never copied into EIPSI. For managed keys, preserve the database and the original WordPress salts when migrating or restoring the site.')).'</p>';

        echo '</section>';
    }
    public static function render(): void {
        \EIPSI_Admin_Authorization_Policy::demand('privacy.manage');$s=SetupStatus::inspect();
        $labels=array('OK'=>'Ready','ATTENTION'=>'Needs attention','MISSING'=>'Not configured','ERROR'=>'Error','OPTIONAL'=>'Optional','DISABLED'=>'Disabled','CRITICAL'=>'Critical configuration missing');
        $details=array(
            'keys'=>array('Study Engine server keys','Required for public Studies. Keys are never displayed.','dashicons-admin-network','Server key configuration'),
            'database'=>array('Database / schema','Required. Checks the existing schema diagnostic without running repairs.','dashicons-database','Open Database settings'),
            'scheduler'=>array('Scheduler / cron','Recommended for longitudinal schedules and maintenance.','dashicons-clock','Open Scheduler'),
            'email'=>array('Email','Optional for transversal studies. Longitudinal email delivery must be tested separately.','dashicons-email','Open Email settings'),
            'privacy'=>array('Privacy & Security','Review the metadata policy for your research protocol.','dashicons-shield','Open Privacy & Security'),
            'api'=>array('External API','Optional. Disabled is a valid configuration.','dashicons-rest-api','Open External API'));
        self::styles();echo '<section id="eipsi-setup" aria-labelledby="eipsi-setup-title">';self::keysSection($s['cards']['keys'],$labels);self::configurationNotices('setup',self::pending());echo '<header class="setup-banner state-'.esc_attr($s['state']).'"><h2 id="eipsi-setup-title">'.esc_html(eipsi_admin_translate('EIPSI Forms setup status')).'</h2><p><strong>'.esc_html(eipsi_admin_translate($labels[$s['state']])).'</strong></p><p>'.esc_html(eipsi_admin_translate('Complete the required components, then review the settings that apply to your study.')).'</p></header>';
        if(in_array($_GET['setup_result']??'',array('GENERATED','ALREADY_CONFIGURED'),true))echo '<p class="setup-backup" role="status">'.esc_html(eipsi_admin_translate('Back up your Study Engine server keys securely. Preserve the encrypted secret store in the database together with all eight WordPress keys and salts in wp-config.php.')).'</p>';
        echo '<div class="setup-grid">';
        foreach($s['cards'] as $name=>$card){if($name==='keys')continue;list($title,$description,$icon,$action)=$details[$name];echo '<article class="setup-card state-'.esc_attr($card['state']).'" data-component="'.esc_attr($name).'"><h3><span class="dashicons '.esc_attr($icon).'" aria-hidden="true"></span>'.esc_html(eipsi_admin_translate($title)).'</h3><span class="setup-badge">'.esc_html(eipsi_admin_translate($labels[$card['state']])).'</span><p>'.esc_html(eipsi_admin_translate($description)).'</p>';
            if($name==='scheduler'&&isset($card['wp_cron_enabled'])){
                echo '<dl><dt>WP-Cron</dt><dd>'.esc_html(eipsi_admin_translate($card['wp_cron_enabled']?'Enabled':'Disabled')).'</dd><dt>'.esc_html(eipsi_admin_translate('External cron')).'</dt><dd>'.esc_html(eipsi_admin_translate($card['external_confirmed']?'Confirmed':'Not confirmed')).'</dd><dt>'.esc_html(eipsi_admin_translate('Last heartbeat')).'</dt><dd>'.esc_html($card['heartbeat']?gmdate('Y-m-d H:i:s \U\T\C',(int)$card['heartbeat']):eipsi_admin_translate('No record')).'</dd></dl>';
            }elseif($name==='email')echo '<p>'.esc_html(eipsi_admin_translate($card['state']==='OK'?'SMTP configured':'WordPress mail fallback')).'</p>';
            elseif($name==='api'&&isset($card['keys_configured']))echo '<p>'.esc_html(eipsi_admin_translate($card['enabled']?'Enabled':'Disabled')).'</p><p>'.esc_html(eipsi_admin_translate('Keys configured')).': '.esc_html(eipsi_admin_translate($card['keys_configured']?'Yes':'No')).'</p>';
            $tab=$card['tab'];$cap=\EIPSI_Admin_Authorization_Policy::configurationTabs()[$tab]??null;
            if($name!=='keys'&&$cap&&\EIPSI_Admin_Authorization_Policy::allows($cap))echo '<p class="setup-action"><a class="button eipsi-readiness-action" href="'.esc_url(self::url($tab)).'">'.esc_html(eipsi_admin_translate($action)).'</a></p>';
            echo '</article>';
        }
        echo '</div></section>';
    }
}
