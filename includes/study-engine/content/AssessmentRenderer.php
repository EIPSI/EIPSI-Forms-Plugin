<?php
namespace EIPSI\StudyEngine\Content;
use EIPSI\StudyEngine\Persistence\IntegrityFailure;
require_once dirname(__DIR__).'/runtime/PublicPage.php';

/** Study shell: assessments are rendered exclusively by the Native participant runtime. */
final class AssessmentRenderer {
    public function render(array $frame,?string $error=null): string {
        $m=static function($key)use($frame){return \EIPSI\StudyEngine\Runtime\PublicMessages::text($frame['definition'],$key);};$view=$frame['view'];$kind=$view['next_action']['kind'];$d=$frame['definition'];
        $html='<main'.\EIPSI\StudyEngine\Runtime\PublicMessages::attributes($d).' id="eipsi-study" class="eipsi-study" tabindex="-1" data-state="'.esc_attr($kind).'">';
        if($error!==null)$html.=\EIPSI\StudyEngine\Runtime\PublicPage::notice($error);
        if($kind==='OPEN_ASSESSMENT'){
            require_once dirname(__DIR__).'/runtime/NativeBridge.php';
            $native=\EIPSI\StudyEngine\Runtime\NativeBridge::offer($frame);
            if($native!==null)return $html.'<iframe class="eipsi-native-assessment-frame" title="'.esc_attr($m('document_title')).'" src="'.esc_url($native['url']).'" style="width:100%;min-height:85vh;border:0"></iframe></main>';
            throw new IntegrityFailure('NATIVE_ASSESSMENT_REQUIRED');
        }else{
            if(empty($frame['longitudinal'])&&in_array($kind,array('FINISHED','TERMINATED'),true)){
                require_once dirname(__DIR__).'/runtime/NativeBridge.php';
                $native=\EIPSI\StudyEngine\Runtime\NativeBridge::completedOffer($frame);
                return $html.($native===null?'':'<iframe class="eipsi-native-assessment-frame" title="'.esc_attr($m('document_title')).'" src="'.esc_url($native['url']).'" style="width:100%;min-height:85vh;border:0"></iframe>').'</main>';
            }
            $screens=array();foreach(array('FINISHED'=>'finished','WAIT'=>'wait','TERMINATED'=>'terminated','WITHDRAWN'=>'withdrawn') as $state=>$prefix)$screens[$state]=array($m($prefix.'_title'),$m($prefix.'_body'));
            $screen=$screens[$kind]??array($m('unavailable_title'),$m('operation_failed'));$html.='<h1 tabindex="-1">'.esc_html($screen[0]).'</h1><p>'.esc_html($screen[1]).'</p>';
            if($kind==='WAIT'){$until=$view['next_action']['payload']['until']??null;if($until&&$until>($view['snapshot']['clock']??gmdate('Y-m-d\TH:i:s\Z'))&&(!(bool)($frame['longitudinal']??false)||($d['protocol_metadata']['longitudinal']['show_next_time']??false)))$html.='<p>'.esc_html($m('next_time')).' <time datetime="'.esc_attr($until).'">'.esc_html(\EIPSI\StudyEngine\Runtime\DateFormat::local($until,$view['snapshot']['execution']['timezone']??$d['timezone']['default'],\EIPSI\StudyEngine\Runtime\DateFormat::read($d))).'</time></p>';}
        }return $html.'</main>';
    }
}
