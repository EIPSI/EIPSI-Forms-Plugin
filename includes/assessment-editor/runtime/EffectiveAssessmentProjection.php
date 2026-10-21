<?php
namespace EIPSI\AssessmentEditor\Runtime;
use EIPSI\AssessmentEditor\Randomization;
use EIPSI\AssessmentEditor\Definition as D;
require_once __DIR__.'/PrivateMedia.php';
/** Participant DTO only. Never mutates a snapshot or resolves a random decision. */
final class EffectiveAssessmentProjection {
    const CONTRACT='EIPSI_PARTICIPANT_FRAME_V2';
    public static function reference(?array $record): ?array {
        return $record===null?null:array('contract'=>'EIPSI_RANDOMIZATION_REFERENCE_V1','reference'=>hash('sha256',D::json($record)));
    }
    /** Current page plus pages whose presentation is already persisted by the owner. */
    private static function pages(array $frame): array {
        $allowed=array((int)$frame['page']=>true);$meta=$frame['metadata']??array();
        foreach($frame['definition']['flow']['pages'] as $i=>$page)if(isset($meta['pages'][$page['id']]['presented_at']))$allowed[$i]=true;
        return $allowed;
    }
    private static function elements(array $frame): array {
        $d=Randomization::apply($frame['definition'],$frame['randomization']);
        $allowed=array((int)$frame['page']=>true);
        $skipped=array_flip($frame['logical_skipped_ids']??array());
        $entry=$frame['logical_entry_question']??null;
        $before=$entry!==null;
        // Determine the entry prefix before page filtering, including individual matrix rows.
        $prefix=function($items)use(&$prefix,&$skipped,&$before,$entry){
            foreach($items as$item){
                if($item['response_key']&&$item['type']!=='matrix'){
                    if($item['id']===$entry)$before=false;
                    if($before)$skipped[$item['id']]=true;
                }
                if($item['type']==='matrix')foreach($item['properties']['matrix_rows']as$row){
                    if($row['id']===$entry)$before=false;
                    if($before)$skipped[$row['id']]=true;
                }
                $prefix($item['children']);
            }
        };
        if($before)$prefix($d['elements']);
        $filter=function($items)use(&$filter,$skipped){
            $out=array();
            foreach($items as$item){
                if(isset($skipped[$item['id']]))continue;
                if($item['type']==='matrix'){
                    $item['properties']['matrix_rows']=array_values(array_filter($item['properties']['matrix_rows'],static function($row)use($skipped){return !isset($skipped[$row['id']]);}));
                    if(!$item['properties']['matrix_rows'])continue;
                }
                $item['children']=$filter($item['children']);
                unset($item['randomization'],$item['properties']['randomize_options']);
                foreach($item['options']as&$option)unset($option['value'],$option['fixed']);
                unset($option);
                $out[]=$item;
            }
            return$out;
        };
        $page=0;$out=array();foreach($d['elements'] as$item){if($item['type']==='page_break'){$page++;$item['properties']=array();$item['children']=array();$item['options']=array();$out[]=$item;continue;}if(isset($allowed[$page]))$out=array_merge($out,$filter(array($item)));}return$out;
    }
    public static function frame(array $frame): array {
        $allowed=array((int)$frame['page']=>true);$frame['definition']['elements']=self::elements($frame);
        // Authoring allocators and historical variable catalogs are not participant data.
        unset($frame['definition']['authoring']);
        foreach($frame['definition']['flow']['pages'] as$i=>&$page)if(!isset($allowed[$i])){
            $page['title']='';$page['description_html']='';
            $opaque='PB_'.substr(hash_hmac('sha256',$frame['execution_id'].'|'.$page['id'],wp_salt('nonce')),0,40);
            $page['id']='PG_'.$opaque;
            if($page['boundary_id']!==null)$page['boundary_id']=$opaque;
        }unset($page);
        $index=0;foreach($frame['definition']['elements']as&$element)if($element['type']==='page_break'){
            $index++;if(!isset($allowed[$index]))$element['id']=$frame['definition']['flow']['pages'][$index]['boundary_id'];
        }unset($element);
        if(!in_array($frame['state'],array('COMPLETED','LOGICAL_EXIT'),true)){$frame['definition']['flow']['completion']['title']='';$frame['definition']['flow']['completion']['message_html']='';$frame['definition']['flow']['completion']['image_url']='';$frame['definition']['flow']['completion']['button_label']='';}
        $frame['evaluate_logic_on_open']=($frame['randomization']['contract']??null)==='LOCAL_RANDOMIZATION_LAZY_V3';
        $ref=self::reference($frame['randomization']);$frame['randomization']=$ref;
        foreach(array('metadata','session','partial') as$key){if(!is_array($frame[$key]??null))continue;$meta=&$frame[$key];if($key!=='metadata')$meta=&$frame[$key]['metadata'];if(isset($meta['randomization']))$meta['randomization']=$ref;unset($meta['logical_exit']);unset($meta);}
        $frame['logical_entry_question']=null;$frame['logical_skipped_ids']=array();$frame['participant_contract']=self::CONTRACT;
        $frame['projection_ref']=hash('sha256',D::json(array($frame['definition'],$frame['state'],$frame['logical_exit']??false,$frame['logical_entry_page']??0,$frame['can_discard']??false)));
        $frame['definition']=PrivateMedia::scopeDefinition($frame['definition'],$frame['execution_id']);
        return $frame;
    }
    /** HTTP input boundary; called while the native execution is locked. */
    public static function validateRequest(array $frame,array $request): void {
        $effective=self::frame($frame);$keys=array();$ids=array();$walk=function($items)use(&$walk,&$keys,&$ids){foreach($items as$item){$ids[$item['id']]=true;if($item['response_key'])$keys[$item['response_key']]=true;if($item['type']==='matrix')foreach($item['properties']['matrix_rows']as$row){$keys[$row['response_key']]=true;$ids[$row['id']]=true;}$walk($item['children']);}};$walk($effective['definition']['elements']);
        foreach($frame['metadata']['items']??array()as$key=>$item){$keys[$key]=true;$ids[$item['item_id']]=true;}
        foreach($frame['session']['answers']??array()as$key=>$unused)$keys[$key]=true;
        foreach(array('answers','touched')as$collection)foreach($request[$collection]as$key=>$unused)if(!isset($keys[$key]))D::fail('Unauthorized participant content');
        foreach($request['metadata']['items']??array()as$key=>$item)if(!isset($keys[$key])||!isset($ids[$item['item_id']??'']))D::fail('Unauthorized participant metadata');
        $pages=array();$allowed=self::pages($frame);foreach($frame['definition']['flow']['pages']as$i=>$p)if(isset($allowed[$i]))$pages[$p['id']]=true;
        foreach($request['metadata']['pages']??array()as$key=>$unused)if(!isset($pages[$key]))D::fail('Unauthorized participant page');
        foreach($request['metadata']['events']??array()as$event){$id=$event['identity']??null;if($id!==null&&!isset($ids[$id])&&!isset($pages[$id]))D::fail('Unauthorized participant event');}
    }
}
