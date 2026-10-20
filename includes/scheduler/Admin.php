<?php
namespace EIPSI\Scheduler;

final class Admin {
    public static function dispatch(array $payload): array {
        if(!current_user_can('manage_options'))throw new \RuntimeException(eipsi_admin_translate('You do not have permission to manage scheduled tasks.'),403);
        \EIPSI_Http_Policy::post(admin_url());
        if(!isset($payload['nonce'])||!is_string($payload['nonce'])||!wp_verify_nonce($payload['nonce'],'eipsi_scheduler'))
            throw new \RuntimeException(eipsi_admin_translate('Authorization expired. Reload the page.'),403);
        if(($payload['operation']??'')==='verify')return Diagnostics::inspect();
        if(($payload['operation']??'')!=='toggle'||!isset($payload['disabled'],$payload['expected'])||!is_bool($payload['disabled'])||!is_string($payload['expected'])||!preg_match('/^[a-f0-9]{64}$/D',$payload['expected']))
            throw new \RuntimeException(eipsi_admin_translate('The request is invalid.'),400);
        if(is_multisite())throw new \RuntimeException(eipsi_admin_translate('On multisite, this configuration affects the entire network and must be managed by its infrastructure administrator.'),403);
        if($payload['disabled']&&($payload['confirmed']??false)!==true)throw new \RuntimeException(eipsi_admin_translate('Confirm the warning before disabling WP-Cron.'),400);
        $path=Config::locate();$config=Config::inspect($path);
        if(!$path || ($config['ownership']==='absent'&&defined('DISABLE_WP_CRON')) ||
            ($config['ownership']==='eipsi'&&(!defined('DISABLE_WP_CRON')||$config['value']!==(bool)DISABLE_WP_CRON)))
            throw new \RuntimeException(eipsi_admin_translate('This configuration is managed externally. Use the manual instructions.'),409);
        Config::write($path,$payload['disabled'],$payload['expected']);
        return array('changed'=>true);
    }
    public static function ajax(): void {
        nocache_headers();header('Cache-Control: private, no-store');
        try {
            $raw=wp_unslash($_POST['payload']??'');
            if(!is_string($raw)||strlen($raw)>4096)throw new \RuntimeException(eipsi_admin_translate('The request is invalid.'),400);
            $p=json_decode($raw,true,8);
            if(!is_array($p))throw new \RuntimeException(eipsi_admin_translate('The request is invalid.'),400);
            wp_send_json_success(self::dispatch($p));
        } catch(\Throwable $e) {
            $code=in_array($e->getCode(),array(400,403,405,409),true)?$e->getCode():503;
            wp_send_json_error(array('code'=>$e instanceof ConfigError?'SCHEDULER_'.$e->presentationCode():'SCHEDULER_OPERATION_FAILED','message'=>$e instanceof ConfigError?eipsi_admin_field('scheduler_error_'.$e->presentationCode()):($code===503?eipsi_admin_translate('Could not complete the operation. The original configuration was retained.'):$e->getMessage())),$code);
        }
    }
    private static function timestamp($time): string {
        if(!$time)return eipsi_admin_translate('No record');
        return eipsi_admin_format('%1$s UTC · %2$s ago',gmdate('Y-m-d H:i:s',(int)$time),eipsi_admin_elapsed(abs(time()-(int)$time)));
    }
    /** Uses established diagnostic decisions; does not recalculate scheduler health. */
    public static function healthMessage(array $diagnostic): string {
        $health=$diagnostic['health'];
        $text=($diagnostic['disabled']&&!$health['external_confirmed'])
            ? eipsi_admin_translate('Scheduled tasks may not be running. WP-Cron is disabled and EIPSI has not recently detected regular external execution.')
            : ($health['external_confirmed']?eipsi_admin_translate('External WordPress Cron executions detected.'):eipsi_admin_translate('Visit-triggered WP-Cron is enabled; it depends on site traffic.'));
        if($health['age_seconds']!==null && $health['age_seconds']>Heartbeat::RECENT)$text.=' '.eipsi_admin_translate('The last detected execution was more than 15 minutes ago.');
        if(($diagnostic['heartbeat']['result']??'')==='FAILED')$text.=' '.eipsi_admin_translate('The last execution did not complete successfully.');
        if($diagnostic['overdue'])$text.=' '.eipsi_admin_translate('EIPSI events are overdue by more than 5 minutes.');
        return $text;
    }
    public static function render(): void {
        if(!current_user_can('manage_options'))return;
        nocache_headers();$d=Diagnostics::inspect();$config=Config::inspect();
        $managed=!is_multisite()&&$config['writable']&&in_array($config['ownership'],array('absent','eipsi'),true);
        if(($config['ownership']==='absent'&&defined('DISABLE_WP_CRON'))||($config['ownership']==='eipsi'&&(!defined('DISABLE_WP_CRON')||$config['value']!==(bool)DISABLE_WP_CRON)))$managed=false;
        ?>
        <style>
        #eipsi-scheduler{--sch-blue:#2271b1;--sch-green:#16836c;color:#263747;margin-top:24px}
        #eipsi-scheduler *{box-sizing:border-box}#eipsi-scheduler [hidden]{display:none!important}
        #eipsi-scheduler .sch-banner{display:flex;align-items:center;gap:18px;padding:24px;border:1px solid #d8e3ef;border-radius:12px;background:linear-gradient(115deg,#edf7fd,#f3effb)}
        #eipsi-scheduler .sch-logo{display:grid;place-items:center;width:56px;height:56px;flex-shrink:0;background:linear-gradient(135deg,#2271b1,#7357ad);color:white;border-radius:14px}
        #eipsi-scheduler .sch-logo .dashicons{width:30px;height:30px;font-size:30px}
        #eipsi-scheduler h2{margin:0 0 8px;font-size:23px}#eipsi-scheduler h3{margin:0 0 14px;font-size:17px}#eipsi-scheduler p{line-height:1.65}
        #eipsi-scheduler .sch-banner p{margin:0}
        #eipsi-scheduler .sch-card{background:#fff;border:1px solid #dce4ec;border-radius:10px;padding:22px;margin-top:18px}
        #eipsi-scheduler .sch-tabs{display:flex;gap:5px;flex-wrap:wrap;border-bottom:2px solid #dce4ec;margin:24px 0 0;padding:0 4px}
        #eipsi-scheduler .sch-tabs button{font:inherit;font-weight:600;display:inline-flex;gap:7px;align-items:center;border:1px solid transparent;border-bottom:3px solid transparent;border-radius:8px 8px 0 0;background:transparent;padding:12px 14px;cursor:pointer;color:#526479;margin-bottom:-2px}
        #eipsi-scheduler .sch-tabs button:hover{background:#edf4fb;color:#185d93}
        #eipsi-scheduler .sch-tabs button[aria-selected=true]{background:#fff;border-color:#dce4ec;border-bottom-color:var(--sch-tab-color);color:#185d93}
        #eipsi-scheduler .sch-tabs .dashicons{color:var(--sch-tab-color)}
        #eipsi-scheduler [data-sch-panel]{background:#fff;border:1px solid #dce4ec;border-radius:10px;padding:22px;margin-top:16px}
        #eipsi-scheduler [data-sch-panel]>.sch-help{margin-top:0}
        #eipsi-scheduler .sch-grid .sch-card{background:#f7f9fc}
        #eipsi-scheduler .sch-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}
        #eipsi-scheduler .sch-state{display:inline-block;padding:5px 12px;border-radius:20px;font-weight:600;background:#edf2f7;color:#526479}
        #eipsi-scheduler .sch-state.ok{background:#e7f5ef;color:#16714c}#eipsi-scheduler .sch-state.warn{background:#fff0d8;color:#8b5713}
        #eipsi-scheduler .sch-help{padding:12px 16px;border-left:4px solid #2271b1;background:#eff6fc;border-radius:0 7px 7px 0}
        #eipsi-scheduler .sch-help.warning{border-color:#c17612;background:#fff3df;color:#75470e}
        #eipsi-scheduler .sch-toggle{display:inline-flex;gap:12px;align-items:center;font:inherit;font-weight:600;color:#304d65;background:transparent;border:0;padding:10px 0;cursor:pointer}
        #eipsi-scheduler .sch-track{position:relative;width:44px;height:24px;border-radius:20px;background:#8797a7}
        #eipsi-scheduler .sch-track:after{content:"";position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff}
        #eipsi-scheduler .sch-toggle[aria-checked=true] .sch-track{background:#16836c}#eipsi-scheduler .sch-toggle[aria-checked=true] .sch-track:after{transform:translateX(20px)}
        #eipsi-scheduler .button{border-radius:6px;padding:3px 12px;font-weight:500}#eipsi-scheduler button:disabled{opacity:.6;cursor:wait}
        #eipsi-scheduler button:focus-visible,#eipsi-scheduler summary:focus-visible{outline:2px solid #2271b1;outline-offset:3px}
        #eipsi-scheduler details{background:#f7f9fc;border:1px solid #e0e7ef;border-radius:7px;padding:16px;margin:12px 0}#eipsi-scheduler summary{cursor:pointer;font-weight:600;color:#245c85}
        #eipsi-scheduler .sch-command{display:flex;gap:10px;align-items:flex-start;margin:10px 0}#eipsi-scheduler pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#edf2f7;padding:12px;border-radius:6px;margin:0;flex:1;min-width:0}
        #eipsi-scheduler .sch-table{overflow-x:auto}#eipsi-scheduler th{background:#f1f6fa}#eipsi-scheduler th,#eipsi-scheduler td{padding:10px;text-align:left}
        #eipsi-scheduler dialog{max-width:min(580px,94vw);border:1px solid #dce4ec;border-radius:12px;padding:24px}#eipsi-scheduler dialog::backdrop{background:#182d4766}
        #eipsi-scheduler [data-sch-message]:empty{display:none}
        @media(max-width:782px){#eipsi-scheduler .sch-banner{align-items:flex-start;padding:18px}#eipsi-scheduler [data-sch-panel]{padding:16px}#eipsi-scheduler .sch-tabs button{padding:10px;font-size:13px}#eipsi-scheduler .sch-grid{grid-template-columns:minmax(0,1fr)}#eipsi-scheduler .sch-command{flex-wrap:wrap}}
        </style>
        <div id="eipsi-scheduler" data-endpoint="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('eipsi_scheduler')); ?>" data-expected="<?php echo esc_attr($config['hash']??''); ?>">
            <div class="sch-banner"><span class="sch-logo" aria-hidden="true"><span class="dashicons dashicons-clock"></span></span><div><h2><?php echo esc_html(eipsi_admin_translate('Scheduler')); ?></h2><p><?php echo esc_html(eipsi_admin_translate('Control how WordPress starts EIPSI tasks and check whether they are running.')); ?></p></div></div>
            <p class="sch-help" role="status" data-sch-message></p>
            <nav class="sch-tabs" role="tablist" aria-label="<?php echo esc_attr(eipsi_admin_translate('Scheduler sections')); ?>">
                <?php foreach(array('status'=>array(eipsi_admin_translate('Status and control'),'dashboard','#2271b1'),'activity'=>array(eipsi_admin_translate('Activity and events'),'chart-bar','#16836c'),'setup'=>array(eipsi_admin_translate('Set up server cron'),'admin-tools','#7357ad'))as$id=>$tab): ?>
                    <button type="button" id="sch-tab-<?php echo esc_attr($id); ?>" data-sch-tab="<?php echo esc_attr($id); ?>" role="tab" aria-controls="sch-panel-<?php echo esc_attr($id); ?>" aria-selected="<?php echo $id==='status'?'true':'false'; ?>" tabindex="<?php echo $id==='status'?'0':'-1'; ?>" style="--sch-tab-color:<?php echo esc_attr($tab[2]); ?>"><span class="dashicons dashicons-<?php echo esc_attr($tab[1]); ?>" aria-hidden="true"></span><?php echo esc_html($tab[0]); ?></button>
                <?php endforeach; ?>
            </nav>
            <section id="sch-panel-status" data-sch-panel="status" role="tabpanel" aria-labelledby="sch-tab-status" tabindex="0">
            <h3><?php echo esc_html(eipsi_admin_translate('Status and control')); ?></h3><p class="sch-help"><?php echo esc_html(eipsi_admin_translate('Enable or disable visit-triggered execution and check for recent evidence of external execution.')); ?></p>
            <div class="sch-grid">
                <section class="sch-card"><h3><?php echo esc_html(eipsi_admin_translate('Visit-triggered WP-Cron')); ?></h3><span class="sch-state <?php echo $d['disabled']?'':'ok'; ?>"><?php echo $d['disabled']?eipsi_admin_translate('Disabled'):eipsi_admin_translate('Enabled'); ?></span><p><?php echo esc_html(eipsi_admin_translate('When enabled, WordPress can start scheduled tasks on site visits. Disabling it affects all of WordPress.')); ?></p>
                <?php if($managed): ?>
                    <button type="button" class="sch-toggle" role="switch" aria-label="<?php echo esc_attr(eipsi_admin_translate('Visit-triggered WP-Cron')); ?>" aria-checked="<?php echo $d['disabled']?'false':'true'; ?>" data-sch-toggle data-disabled="<?php echo $d['disabled']?'false':'true'; ?>"><span class="sch-track" aria-hidden="true"></span><?php echo $d['disabled']?eipsi_admin_translate('Enable WP-Cron'):eipsi_admin_translate('Disable WP-Cron'); ?></button>
                <?php else: ?><p class="sch-help warning"><?php echo esc_html(is_multisite()?eipsi_admin_translate('This configuration affects the entire multisite network. The infrastructure administrator must change it manually.'):eipsi_admin_field('scheduler_reason_'.($config['reason_code']??'DYNAMIC_OR_UNKNOWN'))); ?></p><?php endif; ?>
                <details><summary><?php echo esc_html(eipsi_admin_translate('Technical configuration and manual instructions')); ?></summary>
                    <p><?php echo esc_html(eipsi_admin_translate('Effective status:')); ?> <code>DISABLE_WP_CRON = <?php echo defined('DISABLE_WP_CRON')?((bool)DISABLE_WP_CRON?'true':'false'):eipsi_admin_translate('not defined'); ?></code>.</p>
                    <p><?php echo esc_html($config['ownership']==='eipsi'?eipsi_admin_translate('EIPSI manages only its own block.'):eipsi_admin_translate('This configuration is managed externally or requires manual review.')); ?></p>
                    <p><?php echo wp_kses(eipsi_admin_translate('If automatic changes are unavailable, ask the server administrator to review <code>wp-config.php</code> and environment or hosting configuration. To enable visit-triggered cron, <code>DISABLE_WP_CRON</code> must be <code>false</code> or undefined; to disable it, <code>true</code>. Define it before loading <code>wp-settings.php</code>. Do not add a second definition: edit the existing source.'),array('code'=>array())); ?></p>
                    <p><?php echo esc_html(eipsi_admin_translate('EIPSI does not overwrite external definitions, dynamic configuration, symlinks or read-only files. In Docker, manual file changes may be lost when recreating a container: review persistent configuration.')); ?></p>
                </details></section>
                <section class="sch-card"><h3><?php echo esc_html(eipsi_admin_translate('External execution detected')); ?></h3><span class="sch-state <?php echo $d['health']['external_confirmed']?'ok':'warn'; ?>"><?php echo $d['health']['external_confirmed']?eipsi_admin_translate('Yes'):eipsi_admin_translate('Not confirmed'); ?></span>
                    <p><?php echo esc_html(eipsi_admin_translate('Last detected execution:')); ?><br><strong><?php echo esc_html(self::timestamp($d['heartbeat']['last_scheduler_seen_at']??null)); ?></strong></p>
                    <p class="sch-help <?php echo $d['health']['state']==='OK'?'':'warning'; ?>"><?php echo esc_html(self::healthMessage($d)); ?></p>
                    <button type="button" class="button button-primary" data-sch-verify><?php echo esc_html(eipsi_admin_translate('Verify external cron')); ?></button>
                    <p><?php echo esc_html(eipsi_admin_translate('This check only reads previous evidence. It does not run cron or maintenance. It does not identify whether execution comes from cPanel, Plesk or another service.')); ?></p>
                </section>
            </div>
            </section>
            <section id="sch-panel-activity" data-sch-panel="activity" role="tabpanel" aria-labelledby="sch-tab-activity" tabindex="0" hidden><h3><?php echo esc_html(eipsi_admin_translate('Activity and pending tasks')); ?></h3>
                <p class="sch-help"><?php echo esc_html(eipsi_admin_translate('Review maintenance execution times and scheduled or overdue events.')); ?></p>
                <p><?php echo esc_html(eipsi_admin_translate('Last maintenance started:')); ?> <?php echo esc_html(self::timestamp($d['heartbeat']['last_maintenance_started_at']??null)); ?><br>
                <?php echo esc_html(eipsi_admin_translate('Last maintenance completed:')); ?> <?php echo esc_html(self::timestamp($d['heartbeat']['last_maintenance_completed_at']??null)); ?><br>
                <?php echo esc_html(eipsi_admin_translate('Last execution result:')); ?> <?php echo esc_html(array('STARTED'=>eipsi_admin_translate('Started; no completion recorded'),'COMPLETED'=>eipsi_admin_translate('Completed'),'FAILED'=>eipsi_admin_translate('Not completed successfully'),'UNKNOWN'=>eipsi_admin_translate('No record'))[$d['heartbeat']['result']??'UNKNOWN']); ?></p>
                <p><strong><?php echo esc_html(eipsi_admin_plural('%d overdue EIPSI event',(int)$d['overdue'])); ?></strong> <?php echo esc_html(eipsi_admin_translate('by more than 5 minutes')); ?> · <?php echo esc_html(eipsi_admin_plural('%d scheduled event',(int)$d['scheduled'])); ?>.</p>
                <p><?php echo esc_html(eipsi_admin_translate('API maintenance (includes manual runs; does not prove external cron):')); ?> <?php echo esc_html(self::timestamp($d['maintenance']['last_tick']??null)); ?>.</p>
                <details><summary><?php echo esc_html(eipsi_admin_translate('Upcoming EIPSI events (up to 20)')); ?></summary><div class="sch-table"><table class="widefat"><thead><tr><th><?php echo esc_html(eipsi_admin_translate('Event')); ?></th><th><?php echo esc_html(eipsi_admin_translate('UTC date')); ?></th><th><?php echo esc_html(eipsi_admin_translate('Frequency')); ?></th></tr></thead><tbody>
                <?php foreach($d['events']as$event): ?><tr><td><code><?php echo esc_html($event['hook']); ?></code></td><td><?php echo esc_html(gmdate('Y-m-d H:i:s',$event['at'])); ?></td><td><?php echo esc_html($event['schedule']); ?></td></tr><?php endforeach; ?>
                <?php if(!$d['events']): ?><tr><td colspan="3"><?php echo esc_html(eipsi_admin_translate('No EIPSI events are scheduled.')); ?></td></tr><?php endif; ?></tbody></table></div></details>
                <p><?php echo esc_html(eipsi_admin_translate('Recommended server cron frequency:')); ?> <strong><?php echo esc_html(eipsi_admin_translate('every 5 minutes')); ?></strong><?php echo esc_html(eipsi_admin_translate('Runs are recent for 15 minutes. Confirming regular external execution requires at least two independent runs with visit-triggered WP-Cron disabled, spaced between 1 and 15 minutes.')); ?></p>
            </section>
            <section id="sch-panel-setup" data-sch-panel="setup" role="tabpanel" aria-labelledby="sch-tab-setup" tabindex="0" hidden><h3><?php echo esc_html(eipsi_admin_translate('Set up server cron')); ?></h3>
                <p class="sch-help"><?php echo esc_html(eipsi_admin_translate('Choose your environment, copy the relevant command and follow the steps to schedule execution every five minutes.')); ?></p>
                <p><?php echo esc_html(eipsi_admin_translate('Choose one method. EIPSI generates instructions; it does not access your hosting panel or change crontab. Detected paths belong to the PHP environment and may differ from host paths in containers.')); ?></p>
                <p>WP-CLI: <?php echo Diagnostics::executable('wp')?eipsi_admin_translate('Detected; recommended method.'):eipsi_admin_translate('Not detected in this process. It may be available for another user or at another server path.'); ?></p>
                <?php foreach(Diagnostics::commands()as$name=>$command): ?>
                    <h4><?php echo esc_html($name); ?></h4><p><?php echo esc_html(eipsi_admin_translate('Command for the cPanel/Plesk Command field (schedule separately):')); ?></p>
                    <div class="sch-command"><pre><?php echo esc_html($command); ?></pre><button type="button" class="button" data-sch-copy><?php echo esc_html(eipsi_admin_translate('Copy')); ?></button></div>
                    <p><?php echo esc_html(eipsi_admin_translate('Complete crontab line:')); ?></p><div class="sch-command"><pre><?php echo esc_html('*/5 * * * * '.$command); ?></pre><button type="button" class="button" data-sch-copy><?php echo esc_html(eipsi_admin_translate('Copy')); ?></button></div>
                <?php endforeach; ?>
                <?php foreach(array(
                    'cPanel'=>eipsi_admin_translate('Open cPanel → Cron Jobs. Create a new task every 5 minutes: minute */5; hour, day, month and weekday *. Paste only the selected command into Command, without the five schedule fields. Save.'),
                    'Plesk'=>eipsi_admin_translate('Open Websites & Domains → Scheduled Tasks → Add Task. Choose Run a command, set schedule */5 * * * * and paste only the selected command. Use the site user and save. In restricted environments, ask your host to confirm paths and permissions.'),
                    'SSH / crontab'=>eipsi_admin_translate('Connect over SSH as the site user. Run crontab -e. Add one complete line from above, including all five schedule fields. Save and check with crontab -l. Do not use root if the site belongs to another user.'),
                    'WP-CLI'=>eipsi_admin_translate('If WP-CLI is available, use the detected command with cron event run --due-now --quiet. Run it as the site user on the correct installation. If not detected, ask the administrator for the absolute wp path and WordPress path; do not use web PHP paths as PHP CLI paths.'),
                    eipsi_admin_translate('External service (HTTP)')=>eipsi_admin_translate('Alternatively, configure an HTTP scheduler to request the wp-cron.php URL from the HTTP command every 5 minutes. It must reach the installation without credentials in the link. localhost is accessible only from that machine or container; an Internet service cannot access your localhost.')
                )as$title=>$instructions): ?>
                    <details><summary><?php echo esc_html($title); ?></summary><p><?php echo esc_html($instructions); ?></p><p><?php echo esc_html(eipsi_admin_translate('Once configured, disable visit-triggered WP-Cron to use only that scheduler. Wait for at least two runs (about 10 minutes) and press Verify external cron. If unconfirmed, check scheduler logs, paths, permissions and WordPress access. Missing evidence does not prove that cron is absent.')); ?></p></details>
                <?php endforeach; ?>
            </section>
            <dialog data-sch-confirm aria-labelledby="sch-confirm-title"><h3 id="sch-confirm-title"><?php echo esc_html(eipsi_admin_translate('Disable WP-Cron')); ?></h3><p><?php echo esc_html(eipsi_admin_translate('Disabling WP-Cron stops WordPress from automatically starting scheduled tasks on site visits. Without server cron or another external scheduler, EIPSI reminders, longitudinal scheduling, maintenance and export preparation may run late or not run at all.')); ?></p><p><?php echo esc_html(eipsi_admin_translate('You can disable it even without confirmed external cron.')); ?></p><button type="button" class="button" data-sch-cancel><?php echo esc_html(eipsi_admin_translate('Cancel')); ?></button> <button type="button" class="button button-primary" data-sch-confirmed><?php echo esc_html(eipsi_admin_translate('Disable WP-Cron')); ?></button></dialog>
        </div>
        <script>
        (()=>{'use strict';const root=document.getElementById('eipsi-scheduler');if(!root)return;
        const tabs=[...root.querySelectorAll('[data-sch-tab]')],panels=[...root.querySelectorAll('[data-sch-panel]')];
        const select=id=>{if(!tabs.some(tab=>tab.dataset.schTab===id))id='status';tabs.forEach(tab=>{const active=tab.dataset.schTab===id;tab.setAttribute('aria-selected',String(active));tab.tabIndex=active?0:-1;});panels.forEach(panel=>panel.hidden=panel.dataset.schPanel!==id);};
        select(new URLSearchParams(window.location.search).get('sch_section')||'status');
        tabs.forEach((tab,index)=>{tab.addEventListener('click',()=>{select(tab.dataset.schTab);const url=new URL(window.location.href);url.searchParams.set('sch_section',tab.dataset.schTab);window.history.replaceState(null,'',url);});tab.addEventListener('keydown',event=>{let next;if(event.key==='ArrowRight')next=(index+1)%tabs.length;if(event.key==='ArrowLeft')next=(index+tabs.length-1)%tabs.length;if(event.key==='Home')next=0;if(event.key==='End')next=tabs.length-1;if(next!==undefined){event.preventDefault();tabs[next].click();tabs[next].focus();}});});
        const message=root.querySelector('[data-sch-message]'),toggle=root.querySelector('[data-sch-toggle]'),dialog=root.querySelector('[data-sch-confirm]');
        const send=async(operation,extra={})=>{const buttons=[...root.querySelectorAll('button')];buttons.forEach(b=>b.disabled=true);message.textContent='';
            try{const response=await fetch(root.dataset.endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',body:new URLSearchParams({action:'eipsi_scheduler',payload:JSON.stringify({operation,nonce:root.dataset.nonce,...extra})})});const result=await response.json();if(!response.ok||!result.success)throw new Error(result.data?.message||<?php echo wp_json_encode(eipsi_admin_translate("Could not complete the operation.")); ?>);window.location.reload();}
            catch(error){message.textContent=error.message;}finally{buttons.forEach(b=>b.disabled=false);}};
        toggle?.addEventListener('click',()=>{if(toggle.dataset.disabled==='true')dialog.showModal();else send('toggle',{disabled:false,expected:root.dataset.expected});});
        root.querySelector('[data-sch-cancel]').addEventListener('click',()=>dialog.close());
        root.querySelector('[data-sch-confirmed]').addEventListener('click',()=>{dialog.close();send('toggle',{disabled:true,confirmed:true,expected:root.dataset.expected});});
        root.querySelector('[data-sch-verify]').addEventListener('click',()=>send('verify'));
        root.querySelectorAll('[data-sch-copy]').forEach(button=>button.addEventListener('click',async()=>{const text=button.parentElement.querySelector('pre').textContent;try{await navigator.clipboard.writeText(text);message.textContent=<?php echo wp_json_encode(eipsi_admin_translate("Command copied.")); ?>;}catch(_){const selection=window.getSelection(),range=document.createRange();range.selectNodeContents(button.parentElement.querySelector('pre'));selection.removeAllRanges();selection.addRange(range);message.textContent=<?php echo wp_json_encode(eipsi_admin_translate("Select and copy the command.")); ?>;}}));
        })();
        </script>
        <?php
    }
}
