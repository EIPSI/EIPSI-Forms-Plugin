<?php
namespace EIPSI\StudyEngine\Longitudinal;
use EIPSI\StudyEngine\Contracts\Check;
/** Versioned presentation and portal verification; possession remains mandatory for recovery. */
final class InitialScreen {
    public static function defaults(): array {
        return array('contract'=>'INITIAL_SCREEN_V2','title_html'=>'<h1>Participar en el estudio</h1>','description_html'=>'','footer_html'=>'','background'=>'#ffffff','text_color'=>'#243247','max_width'=>720,'authentication'=>array('type'=>'EMAIL','label'=>'Email','label_html'=>'','label_visible'=>true,'placeholder'=>'nombre@ejemplo.com','button'=>'Continuar','verification'=>'NEVER','confirm_after_first'=>true,'membership_enabled'=>false,'membership_label'=>'Ya participo en este estudio'),'withdrawal'=>array('label'=>'Abandonar estudio','position'=>'BOTTOM','align'=>'RIGHT','text_color'=>'#536176','background'=>'#ffffff','border_color'=>'#d5dce5','border_width'=>1,'radius'=>6,'font_size'=>13,'modal_title'=>'Abandonar estudio','modal_html'=>'<p>Elegí qué debe ocurrir con tus datos.</p>','retain_label'=>'Dejar el estudio y permitir el uso de mis datos','delete_label'=>'Dejar el estudio y solicitar eliminar mis datos','confirm_label'=>'Confirmar abandono'),'templates'=>array('VERIFY_PARTICIPANT'=>array('subject'=>'','html'=>''),'ASSESSMENT_AVAILABLE'=>array('subject'=>'','html'=>''),'ASSESSMENT_REMINDER'=>array('subject'=>'','html'=>'')));
    }
    public static function normalize(array $c): array {
        $defaults=self::defaults();Check::fields($c,array_keys($defaults),array('button_styles'));if(isset($c['button_styles'])){if(!is_array($c['button_styles']))throw new \DomainException('INVALID_BUTTON_STYLE');foreach($c['button_styles'] as $key=>$style){if(!in_array($key,array('continue','resend','withdrawal','retain','delete','cancel','confirm','back'),true)||!is_array($style))throw new \DomainException('INVALID_BUTTON_STYLE');Check::fields($style,array(),array('text_color','background','border_color','border_width','radius','font_size','hover_text_color','hover_background','hover_border_color'));foreach($style as $field=>$v){if(in_array($field,array('border_width','radius','font_size'),true)){Check::integer($v,$field,$field==='font_size'?10:0);if($v>array('border_width'=>8,'radius'=>40,'font_size'=>32)[$field])throw new \DomainException('INVALID_BUTTON_STYLE');}else self::color($v);}}}if(!in_array($c['contract'],array('INITIAL_SCREEN_V1','INITIAL_SCREEN_V2'),true))throw new \DomainException('INVALID_INITIAL_SCREEN');
        foreach(array('background','text_color') as $k)self::color($c[$k]);Check::integer($c['max_width'],'max_width',320);if($c['max_width']>1200)throw new \DomainException('INVALID_INITIAL_WIDTH');
        foreach(array('title_html','description_html','footer_html') as $k)$c[$k]=self::content($c[$k]);
        $a=&$c['authentication'];if(is_array($a)&&!array_key_exists('confirm_after_first',$a))$a['confirm_after_first']=false;if(!is_array($a))throw new \DomainException('INVALID_AUTHENTICATION');Check::fields($a,array_values(array_diff(array_keys($defaults['authentication']),array('label_html','label_visible'))),array('label_html','label_visible'));if(array_key_exists('label_html',$a))$a['label_html']=self::content($a['label_html']);if(array_key_exists('label_visible',$a)&&!is_bool($a['label_visible']))throw new \DomainException('INVALID_AUTHENTICATION');
        if($a['type']!=='EMAIL'||!in_array($a['verification'],array('IMMEDIATE','AFTER_FIRST','NEVER'),true)||!is_bool($a['membership_enabled'])||!is_bool($a['confirm_after_first']))throw new \DomainException('INVALID_AUTHENTICATION');foreach(array('label','placeholder','button','membership_label') as $k)self::text($a[$k]);unset($a);
        $w=&$c['withdrawal'];if(!is_array($w))throw new \DomainException('INVALID_WITHDRAWAL_PRESENTATION');Check::fields($w,array_keys($defaults['withdrawal']),array('completion_html','modal_title_html','typed_confirmation','retain_background','retain_text_color','delete_background','delete_text_color','choices_layout','confirmation','back_label'));if(isset($w['back_label']))self::text($w['back_label']);if(isset($w['choices_layout'])&&!in_array($w['choices_layout'],array('COLUMN','ROW'),true))throw new \DomainException('INVALID_WITHDRAWAL_LAYOUT');
        if(isset($w['confirmation'])){
            $f=$w['confirmation'];if(!is_array($f))throw new \DomainException('INVALID_WITHDRAWAL_CONFIRMATION');Check::fields($f,array(),array_keys(self::confirmationDefaults()));
            foreach($f as $key=>$value){if(in_array($key,array('title_html','message_html','instruction_html','body_html'),true))$f[$key]=self::content($value);elseif(in_array($key,array('background','text_color'),true))self::color($value);elseif($key==='max_width'){Check::integer($value,$key,320);if($value>1200)throw new \DomainException('INVALID_INITIAL_WIDTH');}elseif($key==='buttons_layout'){if(!in_array($value,array('ROW','COLUMN'),true))throw new \DomainException('INVALID_WITHDRAWAL_LAYOUT');}else self::text($value);}$w['confirmation']=$f;
        }
        if(isset($w['completion_html']))$w['completion_html']=self::content($w['completion_html']);
        if(isset($w['modal_title_html']))$w['modal_title_html']=self::content($w['modal_title_html']);
        if(isset($w['typed_confirmation'])&&!is_bool($w['typed_confirmation']))throw new \DomainException('INVALID_WITHDRAWAL_PRESENTATION');
        foreach(array('retain_background','retain_text_color','delete_background','delete_text_color') as $k)if(isset($w[$k]))self::color($w[$k]);
        if(!in_array($w['position'],array('TOP','BOTTOM'),true)||!in_array($w['align'],array('LEFT','RIGHT'),true))throw new \DomainException('INVALID_WITHDRAWAL_POSITION');
        foreach(array('text_color','background','border_color') as $k)self::color($w[$k]);foreach(array('border_width'=>8,'radius'=>40,'font_size'=>32) as $k=>$max){Check::integer($w[$k],$k,$k==='font_size'?10:0);if($w[$k]>$max)throw new \DomainException('INVALID_WITHDRAWAL_STYLE');}
        foreach(array('label','modal_title','retain_label','delete_label','confirm_label') as $k)self::text($w[$k]);$w['modal_html']=self::content($w['modal_html']);unset($w);
        if(!is_array($c['templates']))throw new \DomainException('INVALID_EMAIL_TEMPLATES');Check::fields($c['templates'],array_keys($defaults['templates']));foreach($c['templates'] as &$t)$t=self::template($t);unset($t);return $c;
    }
    public static function buttonStyle(array $c,string $key): string {
        $w=$c['withdrawal']??array();$base=array('text_color'=>'#243247','background'=>'#edf3fa','border_color'=>'#d5dce5','border_width'=>1,'radius'=>6,'font_size'=>13);
        if($key==='withdrawal')foreach($base as $field=>$value)$base[$field]=$w[$field]??$value;
        if(in_array($key,array('retain','delete'),true)){$base['background']=$w[$key.'_background']??($key==='delete'?'#fff1f1':'#edf3fa');$base['text_color']=$w[$key.'_text_color']??($key==='delete'?'#8a2424':'#243247');}
        $s=array_merge($base,$c['button_styles'][$key]??array());$css='';foreach(array('text_color','background','border_color','border_width','radius','font_size') as $field)$css.='--eipsi-button-'.str_replace('_','-',$field).':'.$s[$field].(in_array($field,array('border_width','radius','font_size'),true)?'px':'').';';
        foreach(array('text_color','background','border_color') as $field)$css.='--eipsi-button-hover-'.str_replace('_','-',$field).':'.($s['hover_'.$field]??($field==='background'?self::hoverColor($s[$field]):$s[$field])).';';return $css;
    }
    public static function confirmationDefaults(): array {return array('title_html'=>'<h2>Confirmar decisión</h2>','message_html'=>'<p>¿Está seguro de que desea [opcion]?</p>','instruction_html'=>'<p>Para confirmar, complete el siguiente texto:</p>','body_html'=>'<p>¿Está seguro de que desea [OPCION]? Para confirmar, escriba <strong>[CONFIRMACION]</strong>.</p>','input_label'=>'Campo de confirmación','input_placeholder'=>'Escribí el texto indicado en mayúsculas','cancel_label'=>'Cancelar','background'=>'#ffffff','text_color'=>'#243247','max_width'=>720,'buttons_layout'=>'ROW');}
    private static function hoverColor(string $color): string {$rgb=array(hexdec(substr($color,1,2)),hexdec(substr($color,3,2)),hexdec(substr($color,5,2)));$light=array_sum($rgb)>380;$result='#';foreach($rgb as $v)$result.=sprintf('%02x',(int)round($light?$v*.85:$v+(255-$v)*.2));return $result;}
    public static function withdrawalPhrase(array $w,string $mode): string {
        $text=$w[$mode==='RETAIN_FOR_ANALYSIS'?'retain_label':'delete_label'];
        return function_exists('mb_strtoupper')?mb_strtoupper($text,'UTF-8'):strtoupper($text);
    }
    public static function withdrawalCompletion(array $w,string $mode): string {
        $label=$w[$mode==='RETAIN_FOR_ANALYSIS'?'retain_label':'delete_label']??($mode==='RETAIN_FOR_ANALYSIS'?'Conservar datos':'Eliminar datos');
        $body=self::html($w['completion_html']??'<h2>Tu participación terminó</h2><p>Registramos tu decisión: <strong>[OPCION]</strong>. Gracias por participar.</p>');
        return preg_replace_callback('/\[(opcion|confirmacion)\](?=[^<>]*(?:<|$))/i',static function($m)use($label,$w,$mode){return htmlspecialchars(strtolower($m[1])==='opcion'?$label:self::withdrawalPhrase($w,$mode),ENT_QUOTES,'UTF-8');},$body);
    }
    public const VARIABLES=array('study_name'=>'Nombre del estudio','assessment_name'=>'Evaluación de esta oportunidad','available_time'=>'Fecha y hora de disponibilidad, en la zona del participante','due_time'=>'Fecha y hora de vencimiento','direct_link'=>'Enlace personal para validar y acceder al estado actual','withdrawal_link'=>'Enlace para las opciones de abandono');
    /** Remove the retired preference link from stored template copies without rewriting protocols. */
    public static function removeRetiredEmailLink(string $value): string {
        $token='(?:\[communications_link\]|\{\{communications_link\}\})';
        $value=preg_replace('~(?:\s*·\s*)?<a\b[^>]*href\s*=\s*([\"\'])[^\"\']*'.$token.'[^\"\']*\1[^>]*>.*?</a>(?:\s*·\s*)?~is','',$value);
        return preg_replace('~'.$token.'~','',$value);
    }
    public static function template($t): array {
        if(!is_array($t))throw new \DomainException('INVALID_EMAIL_TEMPLATE');
        Check::fields($t,array('subject','html'),array('from_name','from_email','source_id','source_revision','source_name'));
        foreach(array('subject','html') as $key)if(isset($t[$key])&&is_string($t[$key]))$t[$key]=self::removeRetiredEmailLink($t[$key]);
        self::text($t['subject'],true);$t['html']=self::content($t['html']);
        foreach(array('from_name','source_name') as $k)if(isset($t[$k]))self::text($t[$k],true);
        if(isset($t['from_email'])){self::text($t['from_email'],true);if($t['from_email']!==''&&(!filter_var($t['from_email'],FILTER_VALIDATE_EMAIL)||preg_match('/[^\x21-\x7e]/',$t['from_email'])))throw new \DomainException('INVALID_TEMPLATE_SENDER');}
        if(isset($t['source_id']))Check::id($t['source_id'],'source_id');if(isset($t['source_revision']))Check::integer($t['source_revision'],'source_revision',1);
        foreach(array($t['subject'],$t['html']) as $v)if(preg_match_all('/\{\{([^}]+)\}\}|\[([a-z_]+)\]/',$v,$m,PREG_SET_ORDER))foreach($m as $match){$key=$match[1]!==''?$match[1]:$match[2];if(!isset(self::VARIABLES[$key]))throw new \DomainException('UNKNOWN_TEMPLATE_VARIABLE');}
        return $t;
    }
    public static function required(array $config,array $snapshot,array $definition): bool {
        $mode=$config['initial_screen']['authentication']['verification']??'NEVER';if($mode==='IMMEDIATE')return true;if($mode!=='AFTER_FIRST')return false;
        return self::firstCompleted($snapshot,$definition)||(self::v2($config)&&!self::available($snapshot,$definition,$snapshot['execution']['created_at']??null));
    }
    public static function v2(array $config): bool {return ($config['initial_screen']['contract']??null)==='INITIAL_SCREEN_V2';}
    public static function available(array $snapshot,array $definition,?string $at=null): bool {
        $ids=array();foreach($definition['nodes'] as $n)if(($n['completion_boundary']??null)==='ASSESSMENT')$ids[$n['id']]=true;
        foreach($snapshot['instances'] as $i)if(isset($ids[$i['definition_id']])&&($at===null?in_array($i['state'],array('AVAILABLE','IN_PROGRESS'),true):($i['available_at']!==null&&strtotime($i['available_at'])<=strtotime($at)&&($i['due_at']===null||strtotime($i['due_at'])>strtotime($at)))))return true;
        return false;
    }
    public static function suppressInitialAvailability(array $config,array $snapshot,array $definition,array $block): bool {
        $access=$snapshot['first_access_at']??$snapshot['execution']['first_access_at']??null;
        return self::v2($config)&&($config['initial_screen']['authentication']['verification']??'NEVER')!=='IMMEDIATE'&&!self::firstCompleted($snapshot,$definition)&&$access!==null&&$block['available_at']!==null&&strtotime($block['available_at'])<=strtotime($access);
    }
    public static function reviewRequired(array $config): bool {
        return (!self::v2($config)||($config['initial_screen']['authentication']['verification']??'NEVER')!=='IMMEDIATE')&&!empty($config['initial_screen']['authentication']['confirm_after_first']);
    }
    public static function reviewLink(array $config): bool {return !self::v2($config);}
    public static function firstCompleted(array $snapshot,array $definition): bool {
        $assessments=array();foreach($definition['nodes'] as $n)if(($n['completion_boundary']??null)==='ASSESSMENT')$assessments[$n['id']]=true;
        foreach($snapshot['instances'] as $i)if($i['state']==='COMPLETED'&&isset($assessments[$i['definition_id']]))return true;return false;
    }
    /** Pure compilers validate bounds; WordPress save/render additionally sanitizes presentation. */
    private static function content($value): string {if(!is_string($value)||strlen($value)>80000)throw new \DomainException('INVALID_INITIAL_HTML');return function_exists('wp_kses')?self::html($value):$value;}
    public static function html($value): string {if(!is_string($value)||strlen($value)>80000)throw new \DomainException('INVALID_INITIAL_HTML');if($value==='')return '';require_once dirname(__DIR__,2).'/assessment-editor/RichText.php';return \EIPSI\AssessmentEditor\RichText::sanitize($value);}
    private static function text($v,bool $empty=false): void {if(!is_string($v)||strlen($v)>240||(!$empty&&trim($v)==='')||preg_match('/[\r\n\x00-\x1f]/',$v))throw new \DomainException('INVALID_INITIAL_TEXT');}
    private static function color($v): void {if(!is_string($v)||!preg_match('/^#[a-fA-F0-9]{6}$/D',$v))throw new \DomainException('INVALID_INITIAL_COLOR');}
}
