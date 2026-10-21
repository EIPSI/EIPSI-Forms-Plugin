<?php
namespace EIPSI\ExternalApi;

final class Admin {
    public static function dispatch(array $p): array {
        if(!current_user_can('manage_options'))throw new ApiError('eipsi_external_forbidden',403);
        \EIPSI_Http_Policy::post(admin_url());
        if(!isset($p['nonce'])||!is_string($p['nonce'])||!wp_verify_nonce($p['nonce'],'eipsi_external_api'))throw new ApiError('eipsi_external_forbidden',403);
        $action=$p['operation']??'';$s=new Store();$keys=new Keys($s);$actor=get_current_user_id();$request=Authenticator::requestId();
        if(is_string($action)&&strpos($action,'ops_')===0)return OperationsAdmin::dispatch($action,$p);
        if($action==='enabled'){
            if(!isset($p['enabled'])||!is_bool($p['enabled']))throw new ApiError('eipsi_external_invalid_input',400);
            $s->setEnabled($p['enabled'],$actor,$request,time());return array('enabled'=>$s->enabled());
        }
        if($action==='create'&&isset($p['input'])&&is_array($p['input']))return $keys->create($p['input'],$actor,$request);
        if(!isset($p['id'],$p['revision'])||!is_string($p['id'])||!is_int($p['revision'])||$p['revision']<1)throw new ApiError('eipsi_external_invalid_input',400);
        if($action==='revoke')return array('key'=>$keys->revoke($p['id'],$p['revision'],$actor,$request));
        if($action==='update'&&isset($p['input'])&&is_array($p['input']))return array('key'=>$keys->update($p['id'],$p['revision'],$p['input'],$actor,$request));
        throw new ApiError('eipsi_external_invalid_input',400);
    }
    public static function ajax(): void {
        nocache_headers();header('Cache-Control: private, no-store');
        try{
            $raw=wp_unslash($_POST['payload']??'');if(!is_string($raw)||strlen($raw)>200000)throw new ApiError('eipsi_external_invalid_input',400);
            $p=json_decode($raw,true,16);if(!is_array($p))throw new ApiError('eipsi_external_invalid_input',400);
            wp_send_json_success(self::dispatch($p));
        }catch(\Throwable $e){wp_send_json_error(array('code'=>$e instanceof ApiError?$e->apiCode:'eipsi_external_unavailable','message'=>eipsi_admin_translate('Could not complete the operation. Review the fields and reload if they changed.')),$e instanceof ApiError?$e->getCode():503);}
    }
    private static function fields(array $r,array $studies): void {
        echo ('<p><label>'.esc_html(eipsi_admin_translate('Name')).' <input name="name" required maxlength="120" value="').esc_attr($r['name']??'').('"></label></p><fieldset><legend>'.esc_html(eipsi_admin_translate('Permissions (scopes)')).'</legend>');
        foreach(Keys::SCOPES as $scope)echo '<label style="display:inline-flex;align-items:center;gap:6px;margin-right:16px"><input type="checkbox" name="scope" value="'.esc_attr($scope).'" '.checked(in_array($scope,$r['scopes']??array(),true),true,false).'>'.esc_html($scope).'</label>';
        echo ('</fieldset><p><label>'.esc_html(eipsi_admin_translate('Study access')).' <select name="grant_mode">');
        foreach(array('NONE'=>eipsi_admin_translate('No studies'),'SELECTED'=>eipsi_admin_translate('Selected studies'),'ALL'=>eipsi_admin_translate('All studies — including future studies')) as $value=>$label)echo '<option value="'.esc_attr($value).'" '.selected($r['grant_mode']??'NONE',$value,false).'>'.esc_html($label).'</option>';
        echo ('</select></label></p><div data-study-grants><p>'.esc_html(eipsi_admin_translate('Select logical studies; permissions are retained across versions.')).'</p>');
        echo ('<details class="api-study-picker"><summary><span data-study-count>'.esc_html(eipsi_admin_translate('Select studies')).'</span></summary><div class="api-study-options">');
        foreach($studies as $study)echo '<label class="api-study-option"><input type="checkbox" name="study" value="'.esc_attr($study['id']).'" '.checked(in_array($study['id'],$r['studies']??array(),true),true,false).'><span>'.esc_html($study['name'].' · '.$study['code'].' · '.$study['status']).'</span></label>';
        echo ('</div></details></div><p><label>'.esc_html(eipsi_admin_translate('Optional expiration (UTC)')).' <input type="datetime-local" name="expires_at" value="').esc_attr(empty($r['expires_at'])?'':gmdate('Y-m-d\TH:i',$r['expires_at'])).'"></label></p>';
    }
    private static function appearance(): void {
        ?>
        <style>
        #eipsi-api-shell{--api-blue:#2271b1;--api-violet:#7357ad;--api-green:#16836c;color:#263747;margin-top:22px}
        #eipsi-api-shell *{box-sizing:border-box}#eipsi-api-shell [hidden]{display:none!important}
        #eipsi-api-shell .api-banner{display:flex;gap:18px;align-items:center;padding:24px;border:1px solid #d8e3ef;border-radius:12px;background:linear-gradient(115deg,#edf7fd,#f3effb);margin-bottom:18px}
        #eipsi-api-shell .api-logo{display:grid;place-items:center;flex:0 0 56px;height:56px;border-radius:14px;color:#fff;background:linear-gradient(135deg,#2271b1,#7357ad)}
        #eipsi-api-shell .api-logo .dashicons{font-size:30px;width:30px;height:30px}#eipsi-api-shell h2{margin:0 0 8px;font-size:23px}#eipsi-api-shell h3{font-size:17px;margin:0 0 12px}
        #eipsi-api-shell p{line-height:1.65}#eipsi-api-shell .api-banner p{margin:0;max-width:820px}
        #eipsi-api-shell .api-card,#eipsi-api-shell [data-api-panel]{background:#fff;border:1px solid #dce4ec;border-radius:10px;padding:22px;margin:16px 0;box-shadow:0 2px 5px #23364a05}
        #eipsi-api-shell .api-state{display:inline-flex;align-items:center;gap:7px;border-radius:20px;padding:5px 12px;font-weight:600;background:#edf2f7;color:#526479;margin-bottom:12px}
        #eipsi-api-shell .api-state.is-active{background:#e7f5ef;color:#16714c}#eipsi-api-shell label:has(>input[type=checkbox]){display:inline-flex!important;align-items:center;gap:7px}
        #eipsi-api-shell .api-tabs{display:flex;gap:5px;flex-wrap:wrap;border-bottom:2px solid #dce4ec;margin:24px 0 0;padding:0 4px}
        #eipsi-api-shell .api-tabs button{font:inherit;font-weight:600;display:inline-flex;gap:7px;align-items:center;border:1px solid transparent;border-bottom:3px solid transparent;border-radius:8px 8px 0 0;background:transparent;padding:12px 14px;cursor:pointer;color:#526479;margin-bottom:-2px}
        #eipsi-api-shell .api-tabs button:hover{background:#edf4fb;color:#185d93}#eipsi-api-shell .api-tabs button[aria-selected=true]{background:#fff;border-color:#dce4ec;border-bottom-color:var(--api-blue);color:#185d93}
        #eipsi-api-shell .api-tabs .dashicons{color:var(--tab-color,var(--api-blue))}#eipsi-api-shell button:focus-visible,#eipsi-api-shell a:focus-visible{outline:2px solid var(--api-blue);outline-offset:3px}
        #eipsi-api-shell .api-help{padding:12px 16px;border-left:4px solid var(--api-blue);background:#eff6fc;border-radius:0 7px 7px 0;margin:0 0 20px}
        #eipsi-api-shell .api-help p{margin:0}#eipsi-api-shell .api-table{overflow-x:auto;margin:16px 0}#eipsi-api-shell table{border-color:#dce4ec;border-radius:6px}#eipsi-api-shell th{color:#304d65;background:#f1f6fa}#eipsi-api-shell td,#eipsi-api-shell th{padding:12px;vertical-align:top}#eipsi-api-shell td code{overflow-wrap:anywhere}
        #eipsi-api-shell .button{border-radius:6px;padding:3px 12px;font-weight:500}#eipsi-api-shell .button-primary{background:var(--api-blue);border-color:var(--api-blue)}
        #eipsi-api-shell input:not([type=checkbox]),#eipsi-api-shell select{max-width:100%;border-radius:6px;min-height:36px}#eipsi-api-shell details{padding:12px;background:#f7f9fc;border:1px solid #e0e7ef;border-radius:7px;margin:12px 0}#eipsi-api-shell summary{cursor:pointer;font-weight:600;color:#245c85}
        #eipsi-api-shell fieldset{padding:12px 16px;border:1px solid #dce4ec;border-radius:7px;margin:12px 0}#eipsi-api-shell .api-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin:18px 0}#eipsi-api-shell .api-stat{padding:14px;background:#f0f7fb;border:1px solid #ddebf5;border-top:3px solid #6e9ec8;border-radius:7px}#eipsi-api-shell .api-stat strong{display:block;font-size:22px;margin-top:6px;color:#245f91}
        #eipsi-api-shell .api-badge{display:inline-block;border-radius:20px;padding:4px 10px;font-size:12px;font-weight:600;white-space:nowrap}#eipsi-api-shell code{overflow-wrap:anywhere}
        #eipsi-api-shell .api-message:empty{display:none}#eipsi-api-shell .api-message:not(:empty){padding:12px 16px;background:#fff5e8;border-left:4px solid #d28b21;border-radius:4px}
        #eipsi-api-shell dialog{border:1px solid #dce4ec;border-radius:12px;padding:24px;max-width:min(650px,95vw)}#eipsi-api-shell dialog::backdrop{background:#182d4766}
        #eipsi-api-shell .api-toggle{display:inline-flex;align-items:center;gap:12px;border:0;background:transparent;padding:8px 0;cursor:pointer;font:inherit;font-weight:600;color:#304d65}
        #eipsi-api-shell .api-toggle-track{position:relative;width:44px;height:24px;border-radius:20px;background:#8797a7;flex-shrink:0}
        #eipsi-api-shell .api-toggle-track:after{content:"";position:absolute;top:3px;left:3px;width:18px;height:18px;background:#fff;border-radius:50%;transition:transform .15s}
        #eipsi-api-shell .api-toggle[aria-checked=true] .api-toggle-track{background:var(--api-green)}
        #eipsi-api-shell .api-toggle[aria-checked=true] .api-toggle-track:after{transform:translateX(20px)}
        #eipsi-api-shell .api-toggle:disabled{opacity:.6;cursor:wait}
        #eipsi-api-shell .api-study-picker{max-width:600px;background:#fff}
        #eipsi-api-shell .api-study-options{max-height:260px;overflow:auto;margin-top:12px}
        #eipsi-api-shell label.api-study-option{display:flex!important;align-items:center;width:100%;gap:10px;padding:10px;margin:0;border-bottom:1px solid #edf0f4;line-height:1.5}
        #eipsi-api-shell .api-study-option input{flex:0 0 auto;margin:0}
        #eipsi-api-shell .api-study-option span{min-width:0;overflow-wrap:anywhere}
        #eipsi-api-shell .api-example-downloads{display:flex;gap:12px;flex-wrap:wrap}
        #eipsi-api-shell .api-example-downloads button{display:inline-flex;align-items:center;gap:7px}
        @media(max-width:782px){#eipsi-api-shell .api-banner{align-items:flex-start;padding:18px}#eipsi-api-shell [data-api-panel]{padding:16px}#eipsi-api-shell .api-tabs button{padding:10px;font-size:13px}#eipsi-api-shell .form-table th{width:auto}}
        </style>
        <?php
    }
    public static function badge(string $state): string {
        $labels=array('ACTIVE'=>eipsi_admin_translate('Active'),'REVOKED'=>eipsi_admin_translate('Revoked'),'EXPIRED'=>eipsi_admin_translate('Expired'),'PREPARING'=>eipsi_admin_translate('Preparing'),'READY'=>eipsi_admin_translate('Ready'),'FAILED'=>eipsi_admin_translate('Failed'));
        $colors=array('ACTIVE'=>array('#e7f5ef','#16714c'),'READY'=>array('#e7f5ef','#16714c'),'PREPARING'=>array('#f0ebfb','#69469e'),'FAILED'=>array('#fcecec','#a52b35'),'EXPIRED'=>array('#fff4df','#956310'),'REVOKED'=>array('#edf0f4','#586779'));$c=$colors[$state]??array('#edf0f4','#586779');
        return '<span class="api-badge" style="background:'.$c[0].';color:'.$c[1].'">'.esc_html($labels[$state]??$state).'</span>';
    }
    public static function panel(string $id,string $title,string $explanation): void {
        echo '<section id="api-panel-'.esc_attr($id).'" data-api-panel="'.esc_attr($id).'" role="tabpanel" aria-labelledby="api-tab-'.esc_attr($id).'" tabindex="0"'.($id==='keys'?'':' hidden').'><h3>'.esc_html($title).'</h3><div class="api-help"><p>'.esc_html($explanation).'</p></div>';
    }
    public static function render(): void {
        if(!current_user_can('manage_options'))return;
        nocache_headers();$s=new Store();
        try{$keyPage=(new Operations())->keys(max(1,min(10000,is_scalar($_GET['api_keys_page']??1)?(int)($_GET['api_keys_page']??1):1)));$rows=$keyPage['items'];$studies=$s->studies();$enabled=$s->enabled();}catch(\Throwable $e){echo ('<p role="alert">'.esc_html(eipsi_admin_translate('The External API schema is unavailable. Review migration status.')).'</p>');return;}
        self::appearance();echo '<div id="eipsi-api-shell">';
        echo '<section id="eipsi-external-api" data-nonce="'.esc_attr(wp_create_nonce('eipsi_external_api')).'" data-endpoint="'.esc_url(admin_url('admin-ajax.php')).('"><div class="api-banner"><span class="api-logo" aria-hidden="true"><span class="dashicons dashicons-rest-api"></span></span><div><h2>'.esc_html(eipsi_admin_translate('External API')).'</h2><p>'.esc_html(eipsi_admin_translate('Connect analysis tools to your studies. Manage keys, permissions and exports in one place.')).'</p></div></div><div class="api-card"><span class="api-state ').($enabled?'is-active':'').'">'.($enabled?eipsi_admin_translate('● API enabled'):eipsi_admin_translate('○ API disabled')).'</span><p role="status" class="api-message" data-api-message></p><form data-api-operation="enabled"><button type="submit" class="api-toggle" role="switch" aria-checked="'.($enabled?'true':'false').('" aria-label="'.esc_attr(eipsi_admin_translate('Enable External API')).'"><span class="api-toggle-track" aria-hidden="true"></span><span>').($enabled?eipsi_admin_translate('Disable External API'):eipsi_admin_translate('Enable External API')).'</span></button></form>';
        echo ('<p>'.esc_html(eipsi_admin_translate('HTTPS required.')).'</p></div>');
        if($enabled){
            echo '<nav class="api-tabs" role="tablist" aria-label="Secciones de API externa">';
            foreach(array('keys'=>array(eipsi_admin_translate('Keys and permissions'),'admin-network','#2271b1'),'connection'=>array(eipsi_admin_translate('How to connect'),'editor-code','#7357ad'),'health'=>array(eipsi_admin_translate('Health and maintenance'),'heart','#16836c'),'exports'=>array('Exportaciones','download','#be7723'),'limits'=>array(eipsi_admin_translate('Limits'),'admin-settings','#7357ad'),'audit'=>array(eipsi_admin_translate('Audit'),'list-view','#2271b1'),'metrics'=>array(eipsi_admin_translate('Metrics'),'chart-bar','#16836c'))as$id=>$tab)echo '<button type="button" id="api-tab-'.esc_attr($id).'" data-api-tab="'.esc_attr($id).'" role="tab" aria-controls="api-panel-'.esc_attr($id).'" aria-selected="'.($id==='keys'?'true':'false').'" tabindex="'.($id==='keys'?'0':'-1').'" style="--tab-color:'.esc_attr($tab[2]).'"><span class="dashicons dashicons-'.esc_attr($tab[1]).'" aria-hidden="true"></span>'.esc_html($tab[0]).'</button>';
            echo '</nav>';self::panel('keys',eipsi_admin_translate('Keys and permissions'),eipsi_admin_translate('Create a key for each integration. Choose what it can read and which studies it can access. Copy the secret when creating it: only its mask will be shown afterwards.'));
        echo ('<details><summary>'.esc_html(eipsi_admin_translate('Create new key')).'</summary><form data-api-operation="create">');self::fields(array(),$studies);echo ('<button class="button button-primary">'.esc_html(eipsi_admin_translate('Create key')).'</button></form></details>');
        echo '<div class="api-table"><table class="widefat striped" style="margin-top:20px"><thead><tr>';foreach(array(eipsi_admin_translate('Name'),eipsi_admin_translate('Masked key'),eipsi_admin_translate('Status'),eipsi_admin_translate('Permissions'),eipsi_admin_translate('Studies'),eipsi_admin_translate('Created'),eipsi_admin_translate('Last used'),eipsi_admin_translate('Expires'),eipsi_admin_translate('Actions')) as $label)echo '<th>'.esc_html($label).'</th>';echo '</tr></thead><tbody>';
        foreach($rows as $r){
            echo '<tr><td>'.esc_html($r['name']).'</td><td><code>'.esc_html($r['masked']).'</code></td><td>'.self::badge($r['status']).($r['warnings']?'<p class="description">'.esc_html(implode(' · ',$r['warnings'])).'</p>':'').'</td><td>'.esc_html(implode(', ',$r['scopes'])).'</td><td>'.esc_html($r['grant_mode']==='ALL'?eipsi_admin_translate('All, including future studies'):($r['grant_mode']==='NONE'?eipsi_admin_translate('None'):implode(', ',$r['studies']))).'</td>';
            foreach(array('created_at','last_used_at','expires_at') as $field)echo '<td>'.esc_html($r[$field]===null?'—':gmdate('Y-m-d H:i',$r[$field]).' UTC').'</td>';
            echo '<td>';if($r['status']!=='REVOKED'){
                echo ('<details><summary>'.esc_html(eipsi_admin_translate('Edit')).'</summary><form data-api-operation="update" data-key-id="').esc_attr($r['id']).'" data-revision="'.esc_attr($r['revision']).'">';self::fields($r,$studies);echo ('<button class="button">'.esc_html(eipsi_admin_translate('Save changes')).'</button></form></details><form data-api-operation="revoke" data-key-id="').esc_attr($r['id']).'" data-revision="'.esc_attr($r['revision']).'" data-key-label="'.esc_attr($r['name'].' · '.$r['masked']).('"><button class="button">'.esc_html(eipsi_admin_translate('Revoke')).'</button></form>');
            }echo '</td></tr>';
        }
        if(!$rows)echo ('<tr><td colspan="9">'.esc_html(eipsi_admin_translate('There are no keys yet.')).'</td></tr>');
        echo ('</tbody></table></div><p>'.esc_html(eipsi_admin_translate('To rotate a key: create another, update the integration, check that it works and revoke the previous key.')).'</p>');
        echo '<p>';if($keyPage['page']>1)echo '<a class="button" href="'.esc_url(add_query_arg(array('api_keys_page'=>$keyPage['page']-1,'api_section'=>'keys'))).('">'.esc_html(eipsi_admin_translate('Previous')).'</a> ');if($keyPage['has_more'])echo '<a class="button" href="'.esc_url(add_query_arg(array('api_keys_page'=>$keyPage['page']+1,'api_section'=>'keys'))).('">'.esc_html(eipsi_admin_translate('Next')).'</a>');echo '</p></section>';
        }else{echo ('<div class="api-help"><p>'.esc_html(eipsi_admin_translate('Enable the API to view keys, connections and management tools. Disabling it retains keys and exports; internal maintenance continues.')).'</p></div>');}
        echo ('<dialog data-api-secret><h3>'.esc_html(eipsi_admin_translate('Key created')).'</h3><p>'.esc_html(eipsi_admin_translate('Copy this key now. It cannot be shown again.')).'</p><input data-secret-value readonly aria-label="API key" style="width:100%;min-width:300px"><p><button type="button" class="button" data-secret-copy>'.esc_html(eipsi_admin_translate('Copy')).'</button> <button type="button" class="button button-primary" data-secret-close>'.esc_html(eipsi_admin_translate('Close')).'</button></p></dialog></section>');
        ?>
        <script>
        (()=>{'use strict';const host=document.getElementById('eipsi-external-api'),modal=host.querySelector('[data-api-secret]'),secret=modal.querySelector('[data-secret-value]'),message=host.querySelector('[data-api-message]');
        const sync=form=>{const count=form.querySelector('[data-study-count]');if(count){const n=form.querySelectorAll('[name=study]:checked').length;count.textContent=n?window.EIPSIAdminI18n.n('%d selected study',n):<?php echo wp_json_encode(eipsi_admin_translate('Select studies')); ?>;}const grants=form.querySelector('[data-study-grants]');if(grants)grants.hidden=form.elements.grant_mode.value!=='SELECTED'};
        host.querySelectorAll('form').forEach(form=>{sync(form);form.querySelectorAll('[name=study]').forEach(input=>input.addEventListener('change',()=>sync(form)));form.elements.grant_mode?.addEventListener('change',()=>sync(form));form.addEventListener('submit',async event=>{event.preventDefault();const operation=form.dataset.apiOperation;if(operation==='revoke'&&!window.confirm('Revocar '+form.dataset.keyLabel+' impedirá el próximo acceso de esa integración.'))return;
        const p={operation,nonce:host.dataset.nonce};if(operation==='enabled')p.enabled=form.querySelector('[role=switch]').getAttribute('aria-checked')!=='true';else if(operation!=='revoke'){const expires=form.elements.expires_at.value;p.input={name:form.elements.name.value,scopes:[...form.querySelectorAll('[name=scope]:checked')].map(x=>x.value),grant_mode:form.elements.grant_mode.value,studies:form.elements.grant_mode.value==='SELECTED'?[...form.querySelectorAll('[name=study]:checked')].map(x=>x.value):[],expires_at:expires?Math.floor(Date.parse(expires+'Z')/1000):null};}if(form.dataset.keyId){p.id=form.dataset.keyId;p.revision=Number(form.dataset.revision);}
        const button=form.querySelector('button');button.disabled=true;message.textContent='';try{const response=await fetch(host.dataset.endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',body:new URLSearchParams({action:'eipsi_external_api',payload:JSON.stringify(p)})});const result=await response.json();if(!response.ok||!result.success)throw new Error(result.data?.message||<?php echo wp_json_encode(eipsi_admin_translate("Could not complete the operation.")); ?>);if(operation==='create'){secret.value=result.data.secret;result.data.secret=null;modal.showModal();}else window.location.reload();}catch(error){message.textContent=error.message;}finally{button.disabled=false;}})});
        const clear=()=>{secret.value='';modal.remove();window.location.reload()};modal.addEventListener('close',clear);modal.addEventListener('cancel',event=>{event.preventDefault();modal.close()});modal.querySelector('[data-secret-close]').addEventListener('click',()=>modal.close());modal.querySelector('[data-secret-copy]').addEventListener('click',async()=>{try{await navigator.clipboard.writeText(secret.value)}catch(_){secret.select();document.execCommand('copy')}});
        const navigation=()=>{const shell=document.getElementById('eipsi-api-shell');if(!shell)return;shell.querySelectorAll('[data-api-example]').forEach(link=>link.addEventListener('click',()=>{const bytes=Uint8Array.from(atob(link.dataset.apiExample),c=>c.charCodeAt(0));const url=URL.createObjectURL(new Blob([bytes],{type:'text/plain;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download=link.dataset.filename;document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000)}));const tabs=[...shell.querySelectorAll('[data-api-tab]')],panels=[...shell.querySelectorAll('[data-api-panel]')];const select=(id,focus=false)=>{if(!tabs.some(t=>t.dataset.apiTab===id))id='keys';tabs.forEach(t=>{const active=t.dataset.apiTab===id;t.setAttribute('aria-selected',String(active));t.tabIndex=active?0:-1;if(active&&focus)t.focus()});panels.forEach(p=>{p.hidden=p.dataset.apiPanel!==id});};const query=new URLSearchParams(window.location.search);select(query.get('api_section')||'keys');tabs.forEach((tab,i)=>{tab.addEventListener('click',()=>{select(tab.dataset.apiTab);const url=new URL(window.location.href);url.searchParams.set('api_section',tab.dataset.apiTab);window.history.replaceState(null,'',url)});tab.addEventListener('keydown',event=>{let next;if(event.key==='ArrowRight')next=(i+1)%tabs.length;if(event.key==='ArrowLeft')next=(i+tabs.length-1)%tabs.length;if(event.key==='Home')next=0;if(event.key==='End')next=tabs.length-1;if(next!==undefined){event.preventDefault();tabs[next].click();tabs[next].focus()}})});};if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',navigation,{once:true});else navigation();
        window.addEventListener('pagehide',()=>{secret.value=''});
        })();
        </script>
        <?php
        if($enabled){self::panel('connection',eipsi_admin_translate('How to connect an integration'),eipsi_admin_translate('First create a key with the required permissions. Then use it from your analysis tool to query studies and their data.'));echo ('<ol><li><strong>'.esc_html(eipsi_admin_translate('Authentication:')).'</strong> '.esc_html(eipsi_admin_translate('send the key in the Authorization: Bearer header. Do not put it in links or share it.')).'</li><li><strong>'.esc_html(eipsi_admin_translate('Permissions:')).'</strong> '.esc_html(eipsi_admin_translate('read:studies allows listing studies; read:data allows reading data. You also need access to the selected study.')).'</li><li><strong>'.esc_html(eipsi_admin_translate('Data:')).'</strong> '.esc_html(eipsi_admin_translate('use /data for small queries. For a consistent dataset, create an export and wait until it is ready to download.')).'</li></ol><p>'.esc_html(eipsi_admin_translate('Base address of this installation:')).'</p><code>').esc_html(rest_url(Rest::NS)).('</code><p>'.esc_html(eipsi_admin_translate('Exports can be preparing, ready, failed, expired or revoked. A quota warning means previous jobs must finish or be revoked; creating another job does not reset them.')).'</p>');
            echo ('<h4>'.esc_html(eipsi_admin_translate('Dataset download examples')).'</h4><p>'.esc_html(eipsi_admin_translate('Open the file and enter the base address and key at the top, or enter them when prompted at runtime. Usage instructions are included in each file.')).'</p><div class="api-example-downloads">');
            foreach(array('R'=>array('R/eipsi_api.R','eipsi_api.R'),'Python'=>array('python/eipsi_api.py','eipsi_api.py')) as $language=>$example){
                $content=file_get_contents(dirname(__DIR__,2).'/examples/'.$example[0]);
                if($content!==false)echo '<button type="button" class="button button-primary" data-api-example="'.esc_attr(base64_encode($content)).'" data-filename="'.esc_attr($example[1]).'"><span class="dashicons dashicons-download" aria-hidden="true"></span> Descargar ejemplo en '.esc_html($language).'</button>';
            }
            echo '</div></section>';OperationsAdmin::render();}
        echo '</div>';
    }
}
