<?php
namespace EIPSI\AssessmentEditor;
/** Presentation content only. WordPress KSES is the server authority. */
final class RichText {
    public static function sanitize(string $html): string {
        if (!function_exists('wp_kses')) throw new \LogicException('Rich text requires WordPress KSES');
        $tags=array_fill_keys(array('p','br','strong','b','em','i','u','s','del','sub','sup','h1','h2','h3','h4','h5','h6','hr','pre','code','table','thead','tbody','tfoot','tr','th','td','ul','ol','li','blockquote','span','figure','figcaption'),array());
        $tags['a']=array('href'=>true,'title'=>true);
        $tags['img']=array('src'=>true,'alt'=>true,'title'=>true,'width'=>true,'height'=>true);
        foreach($tags as &$attributes)$attributes['style']=true;unset($attributes);
        foreach(array('th','td') as $tag)$tags[$tag]+=array('colspan'=>true,'rowspan'=>true);
        $html=preg_replace_callback('/\sstyle\s*=\s*([\'"])(.*?)\1/is',static function($m){$style=self::style(html_entity_decode($m[2],ENT_QUOTES|ENT_HTML5,'UTF-8'));return $style===''?'':' style="'.htmlspecialchars($style,ENT_QUOTES|ENT_HTML5,'UTF-8').'"';},$html);
        $html=wp_kses($html,$tags,array('http','https','mailto'));
        // KSES normalizes attributes first, including unquoted input. Restrict CSS again.
        $html=preg_replace_callback('/\sstyle\s*=\s*([\'"])(.*?)\1/is',static function($m){$style=self::style(html_entity_decode($m[2],ENT_QUOTES|ENT_HTML5,'UTF-8'));return $style===''?'':' style="'.htmlspecialchars($style,ENT_QUOTES|ENT_HTML5,'UTF-8').'"';},$html);
        $html=preg_replace_callback('/<a\b([^>]*)>/i',static function($m){return '<a'.$m[1].' target="_blank" rel="noopener noreferrer">';},$html);
        $html=preg_replace_callback('/<img\b[^>]*>/i',static function($m){if(!preg_match('/\bsrc\s*=\s*([\'"])(.*?)\1/is',$m[0],$a))return ''; $url=html_entity_decode($a[2],ENT_QUOTES|ENT_HTML5,'UTF-8');return preg_match('~^(?:https?://|/(?!/))~i',$url)?$m[0]:'';},$html);
        return trim(html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8')," \t\r\n\xC2\xA0")===''&&!preg_match('/<(?:img|hr)\b/i',$html)?'':$html;
    }
    private static function style(string $css): string {
        $rules=array(
            'font-family'=>'[a-zA-Z0-9 ,"\'-]{1,160}',
            'font-size'=>'[0-9]+(?:\.[0-9]+)?(?:px|pt|em|rem|%)',
            'font-weight'=>'(?:normal|bold|bolder|lighter|[1-9]00)',
            'font-style'=>'(?:normal|italic|oblique)',
            'text-decoration'=>'(?:none|underline|line-through|overline)(?: (?:underline|line-through|overline))*',
            'text-align'=>'(?:left|center|right|justify)',
            'color'=>'(?:#[0-9a-fA-F]{3,8}|[a-zA-Z]{1,24}|rgba?\([0-9.,% ]+\))',
            'background-color'=>'(?:#[0-9a-fA-F]{3,8}|[a-zA-Z]{1,24}|rgba?\([0-9.,% ]+\))',
            'line-height'=>'[0-9]+(?:\.[0-9]+)?(?:px|pt|em|rem|%)?',
            'margin-left'=>'[0-9]+(?:\.[0-9]+)?(?:px|pt|em|rem|%)',
            'margin-right'=>'[0-9]+(?:\.[0-9]+)?(?:px|pt|em|rem|%)',
            'vertical-align'=>'(?:baseline|sub|super|top|middle|bottom)',
        );
        $safe=array();foreach(explode(';',$css) as $rule){$parts=explode(':',$rule,2);if(count($parts)!==2)continue;$key=strtolower(trim($parts[0]));$value=trim($parts[1]);if(isset($rules[$key])&&preg_match('~^'.$rules[$key].'$~D',$value))$safe[]=$key.': '.$value;}
        return implode('; ',$safe);
    }
}
