<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register and sanitize access log retention setting
 * Phase 2 - Admin Settings for Retention Days
 *
 * @since 2.0.0
 */
function eipsi_register_access_log_retention_setting() {
    register_setting('eipsi_forms_options', 'eipsi_access_log_retention_days', array(
        'type' => 'integer',
        'default' => 365,
        'sanitize_callback' => function($value) {
            $value = intval($value);
            // Clamp between 30 and 2555 (7 years max)
            return max(30, min(2555, $value));
        }
    ));
}
add_action('admin_init', 'eipsi_register_access_log_retention_setting');

/**
 * Get access log retention days
 *
 * @return int Number of days to retain access logs (default: 365)
 */
function eipsi_get_access_log_retention_days() {
    return get_option('eipsi_access_log_retention_days', 365);
}

/**
 * Get last access log purge date
 *
 * @return string|null MySQL datetime or null if never purged
 */
function eipsi_get_last_access_log_purge_date() {
    return get_option('eipsi_last_access_log_purge', null);
}

/** Global Native metadata policy. No per-response/form selector or historical deletion. */
function render_privacy_dashboard($form_id = null) {
    if (!EIPSI_Admin_Authorization_Policy::allows('privacy.manage')) return;
    $policyClass = \EIPSI\Dataset\EffectiveMetadataPolicy::class;
    $message = ''; $error = false;
    if (isset($_POST['eipsi_metadata_policy_nonce'])) {
        try {
            \EIPSI_Http_Policy::post(admin_url());
            if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eipsi_metadata_policy_nonce'])), 'eipsi_metadata_policy')) throw new RuntimeException(eipsi_admin_translate('The request is invalid.'));
            $submitted = isset($_POST['metadata']) && is_array($_POST['metadata']) ? wp_unslash($_POST['metadata']) : array();
            if (array_diff(array_keys($submitted), array_keys($policyClass::defaults()))) throw new RuntimeException(eipsi_admin_translate('The configuration is invalid.'));
            $values = array(); foreach ($policyClass::defaults() as $key => $default) $values[$key] = isset($submitted[$key]) && $submitted[$key] === '1';
            $policyClass::save($values); $message = eipsi_admin_translate('Metadata policy saved.');
        } catch (Throwable $e) { $message = eipsi_admin_translate('Could not save metadata policy.'); $error = true; }
    }
    $values = $policyClass::global();
    $groups = array('session'=>array(eipsi_admin_translate('Session'),eipsi_admin_translate('Questionnaire timing and recovery.')),'page'=>array(eipsi_admin_translate('Pages'),eipsi_admin_translate('Page presentation, duration and navigation.')),'item'=>array(eipsi_admin_translate('Questions'),eipsi_admin_translate('Interactions, response states and trajectory. Text records length, not intermediate content.')),'client'=>array(eipsi_admin_translate('Browser and device'),eipsi_admin_translate('Response environment characteristics. Basic does not request these data. IP comes from the server connection; other characteristics are reported by the browser. Unavailable data is recorded as empty.')),'events'=>array(eipsi_admin_translate('Events'),eipsi_admin_translate('Navigation and interaction sequence. Basic does not request events; Detailed may include states.')));
    $labels = array('started_at'=>eipsi_admin_translate('Start'),'completed_at'=>eipsi_admin_translate('Completion'),'duration'=>eipsi_admin_translate('Duration'),'recovery_used'=>eipsi_admin_translate('Recovery used'),'recovery_count'=>eipsi_admin_translate('Recovery count'),'dropped_events'=>eipsi_admin_translate('Events omitted by limit'),'presented_at'=>eipsi_admin_translate('First presentation'),'leave_at'=>eipsi_admin_translate('Exit'),'visit_count'=>eipsi_admin_translate('Visits'),'revisit_count'=>eipsi_admin_translate('Revisits'),'next_transitions'=>eipsi_admin_translate('Forward transitions'),'previous_transitions'=>eipsi_admin_translate('Backward transitions'),'resumed_here'=>eipsi_admin_translate('Recovery on this page'),'current_presented_at'=>eipsi_admin_translate('Current presentation'),'first_interaction_at'=>eipsi_admin_translate('First interaction'),'final_response_at'=>eipsi_admin_translate('Last response'),'time_to_first_interaction'=>eipsi_admin_translate('Time to first interaction'),'time_to_final_response'=>eipsi_admin_translate('Time to final response'),'interaction_count'=>eipsi_admin_translate('Interaction count'),'change_count'=>eipsi_admin_translate('Change count'),'focus_count'=>eipsi_admin_translate('Focus count'),'focus_duration'=>eipsi_admin_translate('Focus duration'),'initial_state'=>eipsi_admin_translate('Initial state'),'first_interacted_state'=>eipsi_admin_translate('First interacted state'),'final_state'=>eipsi_admin_translate('Final state'),'input_method'=>eipsi_admin_translate('Input method'),'trajectory'=>eipsi_admin_translate('Response trajectory'),'ip_address'=>eipsi_admin_translate('IP address'),'webgl_renderer'=>eipsi_admin_translate('WebGL renderer'),'screen_depth'=>eipsi_admin_translate('Color depth'),'pixel_ratio'=>eipsi_admin_translate('Pixel density'),'cpu_cores'=>eipsi_admin_translate('Logical CPU cores'),'cookies_enabled'=>eipsi_admin_translate('Cookies enabled'),'device'=>eipsi_admin_translate('Device type'),'browser'=>eipsi_admin_translate('Browser'),'os'=>eipsi_admin_translate('Operating system'),'screen'=>eipsi_admin_translate('Screen size'),'viewport'=>eipsi_admin_translate('Viewport'),'timezone'=>eipsi_admin_translate('Time zone'),'language'=>eipsi_admin_translate('Language'),'input_capabilities'=>eipsi_admin_translate('Input capabilities'),'events'=>eipsi_admin_translate('Event sequence'));
    ?>
    <style>
    #eipsi-metadata-policy{color:#263747}#eipsi-metadata-policy *{box-sizing:border-box}
    #eipsi-metadata-policy .mp-banner{padding:24px;border:1px solid #d8e3ef;border-radius:12px;background:linear-gradient(115deg,#edf7fd,#f3effb)}
    #eipsi-metadata-policy h2{font-size:23px;margin-top:0}#eipsi-metadata-policy p{line-height:1.65}
    #eipsi-metadata-policy .mp-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:22px 0 0;border-bottom:2px solid #dce4ec}
    #eipsi-metadata-policy .mp-tabs button{font:inherit;font-weight:600;padding:12px 16px;border:1px solid transparent;border-bottom:3px solid transparent;border-radius:8px 8px 0 0;background:transparent;cursor:pointer;color:#526479}
    #eipsi-metadata-policy .mp-tabs button[aria-selected=true]{background:#fff;color:#2271b1;border-color:#dce4ec;border-bottom-color:#2271b1}
    #eipsi-metadata-policy .mp-panel{background:#fff;border:1px solid #dce4ec;border-radius:10px;padding:22px;margin:16px 0}
    #eipsi-metadata-policy .mp-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px}
    #eipsi-metadata-policy .mp-fields label{display:flex;align-items:center;gap:9px;margin:0;padding:10px;background:#f4f7fb;border-radius:6px}
    #eipsi-metadata-policy input[type=checkbox]{margin:0;flex-shrink:0}#eipsi-metadata-policy [hidden]{display:none!important}
    </style>
    <div id="eipsi-metadata-policy">
      <div class="mp-banner"><h2><span class="dashicons dashicons-shield"></span> <?php echo esc_html(eipsi_admin_translate('Privacy & Security')); ?></h2>
      <p><?php echo esc_html(eipsi_admin_translate('This setting determines which metadata EIPSI may collect in new responses.')); ?></p>
      <p><?php echo esc_html(eipsi_admin_translate('Disabling a field does not delete historical data already collected.')); ?></p>
      <p><?php echo esc_html(eipsi_admin_translate('Effective collection combines this policy with each assessment\'s Basic, Standard or Detailed profile. Collected data is retained in the metadata attachment. Responses and scientific identifiers are retained independently of these optional fields.')); ?></p></div>
      <?php if ($message): ?><div role="status" class="notice <?php echo $error ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html($message); ?></p></div><?php endif; ?>
      <form method="post">
      <?php wp_nonce_field('eipsi_metadata_policy','eipsi_metadata_policy_nonce'); ?>
      <div class="mp-tabs" role="tablist" aria-label="<?php echo esc_attr(eipsi_admin_translate('Metadata groups')); ?>">
      <?php foreach ($groups as $group=>$info): ?><button type="button" id="mp-tab-<?php echo esc_attr($group); ?>" role="tab" aria-controls="mp-panel-<?php echo esc_attr($group); ?>" aria-selected="<?php echo $group==='session'?'true':'false'; ?>" data-mp-tab="<?php echo esc_attr($group); ?>"><?php echo esc_html($info[0]); ?></button><?php endforeach; ?>
      </div>
      <?php foreach ($groups as $group=>$info): ?>
      <section class="mp-panel" role="tabpanel" id="mp-panel-<?php echo esc_attr($group); ?>" aria-labelledby="mp-tab-<?php echo esc_attr($group); ?>" data-mp-panel="<?php echo esc_attr($group); ?>" <?php echo $group==='session'?'':'hidden'; ?>>
      <h3><?php echo esc_html($info[0]); ?></h3><p><?php echo esc_html($info[1]); ?></p><div class="mp-fields">
      <?php foreach ($policyClass::fields()[$group] as $field): $key=$group.'_'.$field; ?><label><input type="checkbox" name="metadata[<?php echo esc_attr($key); ?>]" value="1" <?php checked($values[$key]); ?>> <span><?php echo esc_html($labels[$field] ?? $field); ?></span></label><?php endforeach; ?>
      </div></section><?php endforeach; ?>
      <button type="submit" class="button button-primary"><?php echo esc_html(eipsi_admin_translate('Save metadata policy')); ?></button>
      </form>
    </div>
    <script>(function(){const root=document.getElementById('eipsi-metadata-policy');root.querySelectorAll('[data-mp-tab]').forEach(button=>button.addEventListener('click',()=>{root.querySelectorAll('[data-mp-tab]').forEach(tab=>tab.setAttribute('aria-selected',String(tab===button)));root.querySelectorAll('[data-mp-panel]').forEach(panel=>panel.hidden=panel.dataset.mpPanel!==button.dataset.mpTab);}));})();</script>
    <?php
}
