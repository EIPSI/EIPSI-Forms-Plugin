<?php
namespace EIPSI\StudyEngine\Configuration;
require_once __DIR__.'/KeyringResolver.php';
/** Read-only web-process diagnostics. Never returns key material or modifies configuration. */
final class ServerKeyrings {
    public static function status(): array {return array('seed'=>KeyringResolver::inspect('seed'),'access'=>KeyringResolver::inspect('access'));}
    public static function table(array $status): void {
        echo '<table class="widefat striped"><thead><tr><th>'.esc_html(eipsi_admin_translate('Keyring')).'</th>';
        foreach(array('Available','Valid format','Active key resolvable','Configured')as $label)echo '<th>'.esc_html(eipsi_admin_translate($label)).'</th>';
        echo '</tr></thead><tbody>';foreach($status as $kind=>$row){echo '<tr data-source="'.esc_attr($row['source']).'"><th>'.esc_html(eipsi_admin_translate($kind==='seed'?'Study Engine seed keyring':'Study Engine access keyring')).'<small>'.esc_html(eipsi_admin_translate($row['source']==='EIPSI'?'Managed by EIPSI':(in_array($row['source'],array('CONSTANT','ENVIRONMENT'),true)?'Externally configured':'Not configured'))).'</small></th>';foreach(array('available','format_valid','active_key_resolvable','ready')as $key)echo '<td>'.esc_html(eipsi_admin_translate($row[$key]?'Yes':'No')).'</td>';echo '</tr>';}echo '</tbody></table>';
    }
    public static function render(): void {
        \EIPSI_Admin_Authorization_Policy::demand('privacy.manage');
        echo '<section class="postbox" style="padding:20px"><h2>'.esc_html(eipsi_admin_translate('Study Engine server keys')).'</h2>';
        echo '<p>'.esc_html(eipsi_admin_translate('Checked in this PHP web request. No key values are displayed. Reload this page after updating server configuration.')).'</p>';self::table(self::status());echo '</section>';
    }
    public static function warning(): void {
        $s=self::status();if($s['seed']['ready']&&$s['access']['ready'])return;
        echo '<div class="notice notice-warning"><p>'.esc_html(eipsi_admin_translate('Study Engine server keys are not fully configured. Public studies require the seed keyring; longitudinal studies also require the access keyring. Configure server keys before publishing or activating studies.')).'</p></div>';
    }
}
