<?php
namespace EIPSI\StudyEngine\Longitudinal;

/** WHAT owner: escaped named values, no PHP, shortcode interpreter or timing decisions. */
final class EmailTemplate {
    public static function defaults(string $language='es'): array {return \EIPSI\I18n\ContentDefaults::get('emails',$language);}
    /** Empty configuration slots mean the visible default template, not an edited blank body. */
    public static function configured(array $templates): array {
        return array_filter($templates,static function($template){return $template!==array('subject'=>'','html'=>'')&&$template!==array('html'=>'','subject'=>'');});
    }
    public function render(string $key,array $values,array $templates=array(),string $language='es'): array {
        $defaults=self::defaults($language);if(!isset($defaults[$key]))throw new \DomainException('UNKNOWN_EMAIL_TEMPLATE');
        foreach(array('study_name','assessment_name','available_time','due_time','direct_link','withdrawal_link') as $k)if(!isset($values[$k])||!is_string($values[$k]))throw new \DomainException('INVALID_TEMPLATE_VARIABLE');
        $template=InitialScreen::template($templates[$key]??$defaults[$key]);
        $pattern='/\{\{([a-z_]+)\}\}|\[([a-z_]+)\]/';
        $subject=preg_replace_callback($pattern,static function($match)use($values){return $values[$match[1]!==''?$match[1]:$match[2]]??'';},$template['subject']);
        $html=preg_replace_callback($pattern,static function($match)use($values){return esc_html($values[$match[1]!==''?$match[1]:$match[2]]??'');},$template['html']);
        return array('subject'=>preg_replace('/[\r\n\x00-\x1f]+/',' ',$subject),'html'=>InitialScreen::html($html),'from_name'=>$template['from_name']??'','from_email'=>'');
    }
    public static function local(?string $utc,string $zone,string $format='YYYY-MM-DD'): string {return \EIPSI\StudyEngine\Runtime\DateFormat::local($utc,$zone,$format);}
    private $definition=array();
    private function m(string $key,array $values=array()): string {return \EIPSI\StudyEngine\Runtime\PublicMessages::text($this->definition,$key,$values);}
    private function h(string $key,array $values=array()): string {return esc_html($this->m($key,$values));}
    public function screen(array $frame,?string $error=null): string {
        $this->definition=$frame['definition']??array();$html=$this->renderScreen($frame,$error);
        $html=preg_replace_callback('/<main\b(?![^>]*data-study-messages)/',function(){return '<main'.\EIPSI\StudyEngine\Runtime\PublicMessages::attributes($this->definition);},$html,1);
        return $html;
    }
    private function renderScreen(array $frame,?string $error=null): string {
        $action=add_query_arg('eipsi_study',$frame['study']['public_id'],home_url('/'));
        $initial=isset($frame['definition'])?(Configuration::read($frame['definition'])['initial_screen']??InitialScreen::normalize(InitialScreen::defaults($frame['definition']['protocol_metadata']['content_language']??null))):null;
        if(isset($frame['assessment_completion_native_url']))return '<main id="eipsi-study" class="eipsi-study" data-state="ASSESSMENT_COMPLETED"><iframe class="eipsi-native-assessment-frame" title="'.esc_attr($this->m('document_title')).'" src="'.esc_url($frame['assessment_completion_native_url']).'" style="width:100%;min-height:85vh;border:0"></iframe></main>';
        if(isset($frame['assessment_completion_html'])){
            $notices=$frame;unset($notices['email_confirmation']);
            if(!empty($frame['email_confirmation']['pending']))$notices['notice_keys'][]='review_sent';
            return '<main id="eipsi-study" class="eipsi-study eipsi-assessment-completion" data-state="ASSESSMENT_COMPLETED">'.$this->portalNotices($notices,$error).'<div class="eipsi-assessment-completion-card">'.$frame['assessment_completion_html'].'</div></main>';
        }
        if($initial!==null&&(isset($frame['entry'])||isset($frame['email_confirmation'])||isset($frame['shell'])||($frame['view']['next_action']['kind']??'EMAIL_ENTRY')!=='OPEN_ASSESSMENT'))return $this->initial($frame,$action,$initial,$error);
        $html=(new \EIPSI\StudyEngine\Content\AssessmentRenderer())->render($frame,$error);

        return $html;
    }
    private function portalNotices(array $frame,?string $error): string {
        $items=array();$keys=array();
        $add=function(string $key,string $level='info',array $values=array())use(&$items,&$keys){if(isset($keys[$key]))return;$keys[$key]=true;$items[]=array('key'=>$key,'level'=>$level,'text'=>$this->m($key,$values));};
        foreach($frame['notice_keys']??array() as $key)$add($key,in_array($key,array('email_verified','review_done'),true)?'success':($key==='assessment_expired'?'error':'info'),array('PERTENENCIA'=>$this->definition['protocol_metadata']['longitudinal']['initial_screen']['authentication']['membership_label']??'Ya participo'));
        if(isset($frame['availability_notice']))$add('unavailable_title','error');
        if($error!==null&&$error!=='')$items[]=array('key'=>'error','level'=>'error','text'=>$error);
        if(!empty($frame['message'])&&empty($frame['notice_keys']))$items[]=array('key'=>'entry','level'=>'info','text'=>$frame['message']);
        $view=$frame['portal_view']??$frame['view']??array();$kind=$view['next_action']['kind']??'EMAIL_ENTRY';
        if(!empty($frame['portal_closed']))$add(($frame['portal_state']??'FINALIZED')==='PAUSED'?'paused_body':'finalized_body');
        elseif(isset($frame['withdrawal']))$items[]=array('key'=>'withdrawal','level'=>'info','html'=>InitialScreen::withdrawalCompletion($this->definition['protocol_metadata']['longitudinal']['initial_screen']['withdrawal']??array(),$frame['withdrawal']['mode'],\EIPSI\I18n\ContentDefaults::language($this->definition)));
        elseif($kind==='WAIT'){
            if(!isset($keys['unavailable_title']))$add('wait_title','error');
            if($this->definition['protocol_metadata']['longitudinal']['email_enabled']??true)$add('email_future_notices');
            $until=$view['next_action']['payload']['until']??null;
            if($until&&($this->definition['protocol_metadata']['longitudinal']['show_next_time']??false))$items[]=array('key'=>'next_time','level'=>'info','text'=>$this->m('next_time'),'time'=>$until,'zone'=>$view['snapshot']['execution']['timezone']??$this->definition['timezone']['default']);
        }elseif($kind==='FINISHED')$add('finished_body');
        elseif($kind==='TERMINATED')$add('terminated_body');
        elseif($kind==='OPERATIONAL_ERROR')$add('operation_failed','error');
        if(isset($frame['email_confirmation'])){$add('review_title');$add('review_body');if($frame['email_confirmation']['pending'])$add('review_pending','info',array('EMAIL'=>$frame['email_confirmation']['email']));}
        $html='<div class="eipsi-portal-notices" aria-live="polite">';
        foreach($items as $item){if(($item['text']??null)===''&&!isset($item['time'])&&!isset($item['html']))continue;$role=$item['level']==='error'?'alert':'status';$html.='<section class="eipsi-portal-notice is-'.$item['level'].'" data-notice="'.esc_attr($item['key']).'" role="'.$role.'">'.(isset($item['html'])?InitialScreen::html($item['html']):esc_html($item['text']??''));if(isset($item['time']))$html.=' <time datetime="'.esc_attr($item['time']).'">'.esc_html(self::local($item['time'],$item['zone'],\EIPSI\StudyEngine\Runtime\DateFormat::read($this->definition))).'</time>';$html.='</section>';}
        return $html.'</div>';
    }
    private function reviewForm(array $frame,string $action): string {
        $c=$frame['email_confirmation'];$attributes=' data-previous-email="'.esc_attr($c['email']).'"';if(isset($frame['completion_native_id'])){$action=add_query_arg('eipsi_completion',$frame['completion_native_id'],$action);$attributes.=' target="_top"';}return $this->form($frame,$action,$attributes).'<input type="hidden" name="action" value="confirm_email"><input type="hidden" name="confirm_email_change" value="0"><input type="hidden" name="expected_revision" value="'.esc_attr((string)$frame['view']['revision']).'"><label for="confirm-study-email">'.$this->h('review_label').'</label><input id="confirm-study-email" name="email" type="email" value="" maxlength="254" autocomplete="off" required><button name="action" value="confirm_email">'.$this->h(!empty($c['requires_link'])?'review_link_button':'review_button').'</button></form>';
    }
    private function initial(array $frame,string $action,array $c,?string $error): string {
        if(isset($frame['email_confirmation'])){
            // Email review is a separate step, not a second rendering of the study entry screen.
            $intro=($this->m('review_title')!==''?'<h1>'.$this->h('review_title').'</h1>':'').($this->m('review_body')!==''?'<p>'.$this->h('review_body').'</p>':'');
            $notice=$error!==null?$this->portalNotices(array(),$error):'';
            if(!empty($frame['email_confirmation']['pending']))$notice.='<p role="status">'.esc_html($this->m('review_pending',array('EMAIL'=>$frame['email_confirmation']['email']))).'</p>';
            return '<main id="eipsi-study" class="eipsi-study eipsi-initial eipsi-email-review" tabindex="-1" data-state="EMAIL_CONFIRMATION_REQUIRED" style="--eipsi-screen-width:'.$c['max_width'].'px;max-width:'.$c['max_width'].'px;padding:0;background:transparent;color:'.esc_attr($c['text_color']).'">'.$notice.'<div class="eipsi-initial-card" style="padding:24px;background:'.esc_attr($c['background']).'">'.$intro.$this->reviewForm($frame,$action).'</div></main>';
        }
        $frame+=array('message'=>null);$a=$c['authentication'];$w=$c['withdrawal'];$style='color:'.$w['text_color'].';background:'.$w['background'].';border:'.$w['border_width'].'px solid '.$w['border_color'].';border-radius:'.$w['radius'].'px;font-size:'.$w['font_size'].'px';
        $withdraw='<div class="eipsi-initial-withdrawal" style="text-align:'.strtolower($w['align']).'"><button type="button" data-open-withdrawal style="'.esc_attr($style).'">'.esc_html($w['label']).'</button></div>';
        $html='<main id="eipsi-study" class="eipsi-study eipsi-initial" tabindex="-1" data-state="'.($frame['portal_state']??(isset($frame['email_confirmation'])?'EMAIL_CONFIRMATION_REQUIRED':(!empty($frame['verification_required'])?'EMAIL_VERIFICATION_REQUIRED':($frame['view']['next_action']['kind']??'EMAIL_ENTRY')))).'" style="--eipsi-screen-width:'.$c['max_width'].'px;max-width:'.$c['max_width'].'px;padding:0;background:transparent;color:'.$c['text_color'].'">';
        $html.=$this->portalNotices($frame,$error).'<div class="eipsi-initial-card" style="padding:24px;background:'.esc_attr($c['background']).'">';
        if($w['position']==='TOP')$html.=$withdraw;
        $html.=InitialScreen::html($c['title_html']).InitialScreen::html($c['description_html']);
        if(isset($frame['email_confirmation']))$html.=$this->reviewForm($frame,$action);
        else{
            $html.=$this->form($frame,$action).$this->authenticationField($a,'study-email',$frame['email']??'');
            if($a['membership_enabled'])$html.='<label><input name="membership" type="checkbox" value="1"> '.esc_html($a['membership_label']).'</label>';
            $html.='<button name="action" value="enroll">'.esc_html($a['button']).'</button></form>';
        }
        $html.=InitialScreen::html($c['footer_html']);
        if($w['position']==='BOTTOM')$html.=$withdraw;
        $choices=$this->withdrawalChoices($w,$this->form($frame,$action).'<input type="hidden" name="action" value="withdraw"><input type="hidden" name="email" value=""><input type="hidden" name="expected_revision" value="'.esc_attr((string)($frame['withdrawal_revision']??0)).'">',true,true,$c['max_width']);
        // Confirmation is a sibling dialog, so closing the choice dialog does not hide it.
        $split=strpos($choices,'<dialog');$buttons=substr($choices,0,$split);$confirmation=substr($choices,$split);
        $html.='<dialog class="eipsi-withdrawal-dialog" data-withdrawal-lookup="1"'.(!empty($frame['withdrawal_error'])?' data-withdrawal-error="1"':'').' style="width:min('.(int)$c['max_width'].'px,calc(100vw - 40px));max-width:none" aria-labelledby="eipsi-withdrawal-title"><div role="heading" aria-level="2" id="eipsi-withdrawal-title">'.(!empty($w['modal_title_html'])?InitialScreen::html($w['modal_title_html']):esc_html($w['modal_title'])).'</div>'.InitialScreen::html($w['modal_html']).'<p class="eipsi-withdrawal-error" role="alert"'.(empty($frame['withdrawal_error'])?' hidden':'').'>'.esc_html($frame['withdrawal_error']??'').'</p>'.$this->authenticationField($a,'withdrawal-email',$frame['withdrawal_entered_email']??'',false).$buttons.'<button type="button" data-close-withdrawal>'.esc_html($w['back_label']??InitialScreen::defaults(\EIPSI\I18n\ContentDefaults::language($this->definition))['withdrawal']['back_label']).'</button></dialog>'.$confirmation.'</div></main>';
        if(isset($frame['availability_notice']))$html=preg_replace('/<main\b/','<main data-unavailable="1"',$html,1);
        if(!empty($frame['portal_closed'])||!empty($frame['portal_disabled']))$html=preg_replace('/<(input|button)\b/','<$1 disabled',$html);
        return $this->styledButtons($html,$c);
    }
    private function withdrawalChoices(array $w,string $form,bool $authorized,bool $retain,int $width=720): string {
        $buttons='<div class="eipsi-withdrawal-choices'.(($w['choices_layout']??'COLUMN')==='ROW'?' is-parallel':'').'">';
        foreach(array('retain'=>'RETAIN_FOR_ANALYSIS','delete'=>'DO_NOT_USE') as $key=>$mode){
            if($key==='retain'&&!$retain)continue;
            $style='background:'.($w[$key.'_background']??($key==='retain'?'#edf3fa':'#fff1f1')).';color:'.($w[$key.'_text_color']??($key==='retain'?'#243247':'#8a2424'));
            $buttons.='<button type="button" data-withdrawal-choice="'.$mode.'" data-confirm-phrase="'.esc_attr(InitialScreen::withdrawalPhrase($w,$mode)).'" style="'.esc_attr($style).'">'.esc_html($w[$key.'_label']).'</button>';
        }
        $f=array_merge(InitialScreen::confirmationDefaults(\EIPSI\I18n\ContentDefaults::language($this->definition)),$w['confirmation']??array());
        if(isset($w['confirmation']['body_html']))$body=$w['confirmation']['body_html'];
        elseif(isset($w['confirmation']['message_html'])||isset($w['confirmation']['instruction_html']))$body=$f['message_html'].$f['instruction_html'].'<p><strong>[CONFIRMACION]</strong></p>';
        else $body=$f['body_html'];
        $body=InitialScreen::html($body);$hasPhrase=preg_match('/\[confirmacion\]/i',$body);
        $body=preg_replace('/\[opcion\](?=[^<>]*(?:<|$))/i','<span data-confirm-description></span>',$body);
        $body=preg_replace('/\[confirmacion\](?=[^<>]*(?:<|$))/i','<span data-confirm-phrase-display></span>',$body);
        if(strpos($body,'data-confirm-phrase-display')===false)$body.='<p><span data-confirm-phrase-display></span></p>';
        $buttons.='</div><dialog class="eipsi-withdrawal-confirmation" style="background:'.esc_attr($f['background']).';color:'.esc_attr($f['text_color']).';width:min('.(int)$width.'px,calc(100vw - 40px))" aria-labelledby="withdrawal-confirm-title"><div id="withdrawal-confirm-title" role="heading" aria-level="2">'.InitialScreen::html($f['title_html']).'</div>'.$body;
        if($authorized)$form.='<input type="hidden" name="mode" value=""><label><input aria-label="'.esc_attr($this->m('withdrawal_input_label')).'" name="confirmation_text" placeholder="'.esc_attr($f['input_placeholder']).'" autocomplete="off" required data-withdrawal-confirm-text></label>';
        else $form.='<label><input aria-label="'.esc_attr($this->m('withdrawal_input_label')).'" placeholder="'.esc_attr($f['input_placeholder']).'" autocomplete="off" required data-withdrawal-confirm-text></label>';
        return $buttons.$form.'<div class="eipsi-withdrawal-choices'.($f['buttons_layout']==='ROW'?' is-parallel':'').'"><button type="button" data-cancel-withdrawal-confirmation>'.esc_html($f['cancel_label']).'</button><button type="submit" data-confirm-withdrawal disabled>'.esc_html($w['confirm_label']).'</button></div></form></dialog><noscript><p>'.$this->h('withdrawal_no_script').'</p></noscript>';
    }
    private function styledButtons(string $html,?array $c): string {
        if(empty($c['button_styles'])&&empty($c['withdrawal']['typed_confirmation']))return $html;
        return preg_replace_callback('/<button\b([^>]*)>/i',static function($m)use($c){
            $attrs=$m[1];$key=null;
            if(strpos($attrs,'data-withdrawal-request="RETAIN_FOR_ANALYSIS"')!==false)$key='retain';
            elseif(strpos($attrs,'data-withdrawal-request="DO_NOT_USE"')!==false)$key='delete';
            elseif(strpos($attrs,'data-open-withdrawal')!==false)$key='withdrawal';
            elseif(strpos($attrs,'data-withdrawal-choice="RETAIN_FOR_ANALYSIS"')!==false)$key='retain';
            elseif(strpos($attrs,'data-withdrawal-choice="DO_NOT_USE"')!==false)$key='delete';
            elseif(strpos($attrs,'data-cancel-withdrawal-confirmation')!==false)$key='cancel';
            elseif(strpos($attrs,'data-confirm-withdrawal')!==false)$key='confirm';
            elseif(strpos($attrs,'data-close-withdrawal')!==false)$key='back';
            elseif(strpos($attrs,'value="enroll"')!==false)$key='continue';
            elseif(strpos($attrs,'value="resend"')!==false)$key='resend';
            if($key===null)return $m[0];
            $attrs=preg_replace('/\sstyle="[^"]*"/i','',$attrs);
            return '<button'.$attrs.' class="eipsi-configured-button" style="'.esc_attr(InitialScreen::buttonStyle($c,$key)).'">';
        },$html);
    }
    private function authenticationField(array $a,string $id,string $value='',bool $autocomplete=true): string {
        $labelHtml=!empty($a['label_html'])?InitialScreen::html($a['label_html']):esc_html($a['label']);$visible=$a['label_visible']??true;
        $text=trim(html_entity_decode(wp_strip_all_tags($labelHtml),ENT_QUOTES|ENT_HTML5,'UTF-8'));if($text==='')$text=$a['label'];
        $label=$visible?'<div id="'.esc_attr($id).'-label" class="eipsi-email-label">'.$labelHtml.'</div>':'';
        $accessible=$visible?' aria-labelledby="'.esc_attr($id).'-label"':' aria-label="'.esc_attr($text).'"';
        return $label.'<input id="'.esc_attr($id).'"'.$accessible.' name="email" type="email" autocomplete="'.($autocomplete?'email':'off').'" maxlength="254" value="'.esc_attr($value).'" placeholder="'.esc_attr($a['placeholder']).'" required>';
    }
    private function form(array $frame,string $action,string $attributes=''): string {
        return '<form class="eipsi-study-form" method="post" action="'.esc_url($action).'"'.$attributes.'><input type="hidden" name="csrf" value="'.esc_attr($frame['csrf']).'"><input type="hidden" name="command_id" value="HTTP_'.bin2hex(random_bytes(16)).'">';
    }
}
