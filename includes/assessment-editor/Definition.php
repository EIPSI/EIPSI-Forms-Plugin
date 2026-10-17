<?php
namespace EIPSI\AssessmentEditor;

require_once __DIR__.'/Authoring.php';
require_once __DIR__.'/Variables.php';
require_once __DIR__.'/Flow.php';

/** Native authoring DTO. No WordPress or Gutenberg dependency. */
final class Definition {
    const CONTRACT = 'EIPSI_ASSESSMENT_V1';
    const TYPES = array('text','textarea','email','number','tel','date','url','single_choice','multiple_choice','select','likert','matrix','vas','instruction','image','group','randomization','page_break');
    const QUESTIONS = array('text','textarea','email','number','tel','date','url','single_choice','multiple_choice','select','likert','matrix','vas');
    public static function validate(array $d): array {
        self::fields($d,array_merge(array('contract','id','title','elements'),array_key_exists('authoring',$d)?array('authoring'):array(),array_key_exists('flow',$d)?array('flow'):array()));
        if ($d['contract']!==self::CONTRACT) self::fail('Unsupported contract');
        self::id($d['id']); self::text($d['title'],200);
        self::listing($d['elements']);
        $d=Variables::normalize(self::withDefaults($d));
        $seen=array($d['id']=>true); $keys=array(); $count=0;
        self::elements($d['elements'],$seen,$keys,$count,0); Authoring::variables($d['elements']);
        return Flow::normalize($d);
    }
    private static function elements(array $elements,array &$seen,array &$keys,int &$count,int $depth): void {
        if ($depth>8) self::fail('Maximum group depth is 8');
        foreach ($elements as $e) {
            if (!is_array($e)) self::fail('Element must be an object');
            self::fields($e,array_merge(array('id','type','response_key','properties','options','children'),array_key_exists('randomization',$e)?array('randomization'):array()));
            if(array_key_exists('randomization',$e)){ $r=$e['randomization'];if(!is_array($r)||array_diff(array_keys($r),array('weight','always_show','fixed_position')))self::fail('Invalid child randomization settings');if(array_key_exists('weight',$r)){self::number($r['weight']);if($r['weight']<=0)self::fail('Weight must be positive');}if(array_key_exists('fixed_position',$r)&&!is_bool($r['fixed_position']))self::fail('Fixed position must be boolean');if(array_key_exists('always_show',$r)&&!is_bool($r['always_show']))self::fail('Always show must be boolean'); }
            self::id($e['id']); if(isset($seen[$e['id']])) self::fail('Duplicate identity'); $seen[$e['id']]=true;
            if (++$count>300) self::fail('Maximum 300 elements');
            if (!in_array($e['type'],self::TYPES,true)) self::fail('Unknown element type');
            $q=in_array($e['type'],self::QUESTIONS,true);
            if ($q) { self::id($e['response_key']); if(isset($keys[$e['response_key']])) self::fail('Duplicate response key'); $keys[$e['response_key']]=true; }
            elseif ($e['response_key']!==null) self::fail('Content cannot have a response key');
            self::listing($e['options']); self::listing($e['children']);
            if (!in_array($e['type'],array('group','randomization'),true) && $e['children']) self::fail('Only containers have children');
            if ($e['type']==='page_break' && $depth!==0) self::fail('PageBreak must be at assessment level');
            $p=$e['properties']; if(!is_array($p)) self::fail('Properties must be an object');
            $fields=array('label');if($e['type']==='instruction')$fields[]='label_html';
            if ($q) $fields=array_merge($fields,array('helper','required','variable_name','label_html','helper_html','footer_html'));
            if (in_array($e['type'],array('text','textarea','email','tel','url'),true)) $fields=array_merge($fields,array('placeholder','min_length','max_length','content_type','display_mode'));
            if ($e['type']==='textarea') $fields[]='rows';
            if ($e['type']==='number') $fields=array_merge($fields,array('placeholder','min','max','step','integer_only'));
            if ($e['type']==='select') $fields[]='placeholder';
            if(in_array($e['type'],array('single_choice','multiple_choice'),true))$fields[]='columns';
            if($e['type']==='matrix')$fields=array_merge($fields,array('matrix_kind','matrix_rows'));
            if ($e['type']==='multiple_choice') $fields=array_merge($fields,array('min_selected','max_selected'));
            if (in_array($e['type'],array('likert','matrix'),true)) $fields=array_merge($fields,array('min','preset','reversed','gradient','gradient_start','gradient_end'));
            if (in_array($e['type'],array('single_choice','multiple_choice','likert','matrix'),true)) $fields[]='presentation';
            if ($e['type']==='vas') $fields=array_merge($fields,array('min','max','step','initial','left_label','right_label','show_value','labels','label_settings','value_position','value_font_size','label_font_size','label_scale','bold_labels','label_boxes','label_alignment','gradient','gradient_start','gradient_end'));
            if ($e['type']==='image') $fields=array_merge($fields,array('alt','caption','src'));
            if($e['type']==='randomization')$fields=array_merge($fields,array('mode','count','order'));
            if(array_key_exists('algorithm',$p)&&$e['type']==='randomization'){$fields[]='algorithm';if(!in_array($p['algorithm'],array('weighted_positions','selection_uniform_order'),true))self::fail('Invalid randomization algorithm');}
            if(array_key_exists('fixed_policy',$p)&&$e['type']==='randomization'){$fields[]='fixed_policy';if(!in_array($p['fixed_policy'],array('original','mixed'),true))self::fail('Invalid fixed policy');}
            if(array_key_exists('randomize_options',$p)&&in_array($e['type'],array('single_choice','multiple_choice','likert'),true)){$fields[]='randomize_options';if(!is_bool($p['randomize_options']))self::fail('Randomize options must be boolean');}
            foreach(array('required_message','validation_message','matrix_heading') as $key)if(array_key_exists($key,$p)){if(($key==='matrix_heading'&&$e['type']!=='matrix')||($key!=='matrix_heading'&&!$q))self::fail('Invalid message property');$fields[]=$key;self::text($p[$key],2000);}
            foreach(array('selection_min_message','selection_max_message') as $key)if(array_key_exists($key,$p)){if($e['type']!=='multiple_choice')self::fail('Invalid selection message');$fields[]=$key;self::text($p[$key],2000);}
            $messageTypes=array('unanswered_value_message'=>array('vas'),'length_message'=>array('text','textarea','email','tel','url'),'number_range_message'=>array('number'),'min_length_message'=>array('textarea'),'max_length_message'=>array('textarea'),'invalid_value_message'=>array('date','vas','single_choice','multiple_choice','select','likert','matrix'),'empty_image_message'=>array('image'),'date_placeholder'=>array('date'),'date_open_label'=>array('date'),'date_close_label'=>array('date'),'date_previous_label'=>array('date'),'date_next_label'=>array('date'),'date_month_names'=>array('date'),'date_weekday_names'=>array('date'));
            foreach($messageTypes as $key=>$types)if(array_key_exists($key,$p)){if(!in_array($e['type'],$types,true))self::fail('Invalid control message');$fields[]=$key;self::text($p[$key],2000);if(in_array($key,array('date_month_names','date_weekday_names'),true)&&trim($p[$key])!==''&&count(explode('|',$p[$key]))!==($key==='date_month_names'?12:7))self::fail('Invalid calendar names');}
            if(array_key_exists('date_format',$p)){$fields[]='date_format';if($e['type']!=='date'||!in_array($p['date_format'],array('YYYY-MM-DD','DD/MM/YYYY','MM/DD/YYYY'),true))self::fail('Invalid date format');}
            self::fields($p,$fields); self::text($p['label'],$e['type']==='instruction'?20000:2000);
            if ($q) { self::text($p['helper'],2000); if(!is_bool($p['required'])) self::fail('Required must be boolean'); }
            if (isset($p['placeholder'])) self::text($p['placeholder'],2000);
            if(array_key_exists('max_length',$p)){if($p['max_length']!==null)self::integer($p['max_length'],1,100000);if($p['min_length']!==null)self::integer($p['min_length'],0,$p['max_length']??100000);}
            if ($e['type']==='textarea') self::integer($p['rows'],1,100);
            if ($e['type']==='number') {
                foreach(array('min','max') as $k) if($p[$k]!==null)self::number($p[$k]);
                self::number($p['step']); if($p['step']<=0||($p['min']!==null&&$p['max']!==null&&$p['min']>$p['max'])) self::fail('Invalid number bounds/step');
            }
            if ($e['type']==='multiple_choice') { self::integer($p['min_selected'],0,count($e['options'])); if($p['max_selected']!==null)self::integer($p['max_selected'],max($p['min_selected'],$p['required']?1:0),count($e['options'])); }
            if (in_array($e['type'],array('likert','matrix'),true)) { self::integer($p['min'],-1000000,1000000); if(!is_bool($p['reversed'])||!in_array($p['preset'],array('custom','likert5-agreement','likert5-satisfaction','likert5-frequency','likert7-agreement','likert7-satisfaction','likert4-agreement'),true))self::fail('Invalid Likert presentation'); }
            if (in_array($e['type'],array('likert','matrix','vas'),true)) {
                if(!in_array($p['gradient'],array('none','improvement-right','improvement-left','progress-neutral','custom'),true))self::fail('Invalid gradient');
                foreach(array('gradient_start','gradient_end') as $k)if(!is_string($p[$k])||!preg_match('/^#[0-9a-fA-F]{6}$/D',$p[$k]))self::fail('Invalid gradient color');
            }
            if(isset($p['presentation'])&&!in_array($p['presentation'],array('vertical','horizontal'),true)) self::fail('Invalid presentation');
            if($e['type']==='vas') {
                foreach(array('min','max','step','initial') as $k) if((!is_int($p[$k])&&!is_float($p[$k]))||!is_finite((float)$p[$k])) self::fail('VAS values must be finite numbers');
                if($p['min']>=$p['max']||$p['step']<=0||$p['step']>$p['max']-$p['min']||$p['initial']<$p['min']||$p['initial']>$p['max']) self::fail('Invalid VAS bounds/step/initial');
                if(!is_finite((float)($p['max']-$p['min']))) self::fail('Invalid VAS range');
                foreach(array('initial') as $k) { $steps=($p[$k]-$p['min'])/$p['step']; if(!is_finite((float)$steps)||abs($steps-round($steps))>1e-7) self::fail('VAS initial must align with step'); }
                self::text($p['left_label'],2000); self::text($p['right_label'],2000); if(!is_bool($p['show_value'])||!is_bool($p['bold_labels'])||!is_bool($p['label_boxes'])) self::fail('Invalid VAS presentation');
                self::listing($p['labels']); if(count($p['labels'])<1||count($p['labels'])>100)self::fail('VAS requires 1–100 labels'); foreach($p['labels'] as $label)self::text($label,2000);
                self::integer($p['label_font_size'],12,36);self::integer($p['value_font_size'],16,72);self::number($p['label_alignment']);if($p['label_alignment']<0||$p['label_alignment']>100)self::fail('Invalid label alignment');
                if(!in_array($p['value_position'],array('above','below'),true))self::fail('Invalid value position');
            }
            if($e['type']==='image') { self::text($p['alt'],2000); self::text($p['caption'],2000);self::text($p['src'],2000);if($p['src']!==''&&!preg_match('~^(?:https?://|/(?!/))~i',$p['src']))self::fail('Invalid image URL'); }
            if($e['type']==='randomization'){if(!in_array($p['mode'],array('all','select'),true)||!in_array($p['order'],array('original','random'),true))self::fail('Invalid randomization mode/order');self::integer($p['count'],1,300);if($p['mode']==='select'&&$p['count']>count(array_filter($e['children'],static function($child){return ($child['randomization']['always_show']??false)!==true;}))-(($p['algorithm']??null)==='selection_uniform_order'?1:0))self::fail('Randomization count exceeds candidates');}
            if($e['type']==='page_break'&&$p['label']!=='Salto de página') self::fail('Malformed PageBreak');
            $choice=in_array($e['type'],array('single_choice','multiple_choice','select','likert','matrix'),true);
            if($choice?(count($e['options'])<1||count($e['options'])>100):(count($e['options'])!==0)) self::fail('Invalid options for type');
            foreach($e['options'] as $o) {
                if(!is_array($o)) self::fail('Invalid option'); $of=in_array($e['type'],array('likert','matrix'),true)?array('id','label','value'):array('id','label');if(array_key_exists('fixed',$o)&&in_array($e['type'],array('single_choice','multiple_choice','likert'),true)){$of[]='fixed';if(!is_bool($o['fixed']))self::fail('Fixed option must be boolean');}self::fields($o,$of); if(array_key_exists('value',$o)&&$o['value']!==null&&((!is_int($o['value'])&&!is_float($o['value']))||!is_finite((float)$o['value'])))self::fail('Scale value must be finite numeric or null'); self::id($o['id']); self::text($o['label'],2000);
                if(isset($seen[$o['id']])) self::fail('Duplicate option identity'); $seen[$o['id']]=true;
            }
            Authoring::validate($e,$seen,$keys);self::elements($e['children'],$seen,$keys,$count,$depth+1);
        }
    }
    /** Add only properties introduced after v0. Original required properties remain mandatory. */
    public static function withDefaults(array $d): array {
        $visit=function(&$items)use(&$visit){
            foreach($items as &$e){
                if(!is_array($e)||!isset($e['type'],$e['properties'],$e['children'])||!is_array($e['properties'])||!is_array($e['children']))self::fail('Invalid element shape');
                $p=$e['properties'];$defaults=array();$t=$e['type'];if($t==='image')$defaults['src']='';if($t==='instruction')$defaults['label_html']=null;
                if(in_array($t,array('text','textarea','email','tel','url'),true))$defaults['min_length']=0;
                if($t==='textarea')$defaults['rows']=4;
                if($t==='multiple_choice')$defaults=array('min_selected'=>0,'max_selected'=>null);
                if(in_array($t,array('likert','matrix'),true))$defaults=array('min'=>1,'preset'=>'custom','reversed'=>false);
                if(in_array($t,array('likert','matrix','vas'),true))$defaults=array_merge($defaults,array('gradient'=>'none','gradient_start'=>'#f44336','gradient_end'=>'#4caf50'));
                if($t==='vas')$defaults=array_merge($defaults,array('labels'=>array($p['left_label']??'Nada',$p['right_label']??'Mucho'),'value_position'=>'below','value_font_size'=>36,'label_font_size'=>16,'bold_labels'=>true,'label_boxes'=>false,'label_alignment'=>100));
                $e['properties']=array_merge($defaults,$p);if(in_array($t,array('likert','matrix'),true)&&isset($e['options'])&&is_array($e['options']))foreach($e['options'] as $i=>&$o){if(is_array($o)&&!array_key_exists('value',$o))$o['value']=($e['properties']['min']??1)+$i;}unset($o);$e['properties']=Authoring::defaults($e);if($t==='instruction'&&$e['properties']['label_html']!==null){if(!is_string($e['properties']['label_html'])||strlen($e['properties']['label_html'])>80000)self::fail('Invalid instruction rich text');$e['properties']['label_html']=RichText::sanitize($e['properties']['label_html']);}$visit($e['children']);
            }
        };
        $visit($d['elements']);return $d;
    }
    private static function number($v): void {if((!is_int($v)&&!is_float($v))||!is_finite((float)$v))self::fail('Expected finite number');}
    private static function integer($v,int $min,int $max): void {if(!is_int($v)||$v<$min||$v>$max)self::fail('Integer outside allowed range');}
    public static function fields(array $v,array $keys): void { $actual=array_keys($v); sort($actual); sort($keys); if($actual!==$keys) self::fail('Unexpected or missing fields'); }
    public static function id($v): void { if(!is_string($v)||!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,99}$/D',$v)) self::fail('Invalid identity'); }
    private static function listing($v): void { if(!is_array($v)||($v&&array_keys($v)!==range(0,count($v)-1))) self::fail('Expected ordered list'); }
    private static function text($v,int $max): void { if(!is_string($v)||strlen($v)>$max*4||preg_match('//u',$v)!==1) self::fail('Invalid text'); }
    public static function fail(string $message): void { throw new \InvalidArgumentException($message); }
    public static function json(array $v): string { return json_encode(self::normalize($v),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    private static function normalize($v) { if(!is_array($v)) return $v; if($v&&array_keys($v)!==range(0,count($v)-1)) ksort($v,SORT_STRING); foreach($v as &$child) $child=self::normalize($child); return $v; }
}
