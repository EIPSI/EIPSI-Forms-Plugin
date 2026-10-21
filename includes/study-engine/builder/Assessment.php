<?php
namespace EIPSI\StudyEngine\Builder;
/** Reuse Form Library conversion, adding only core/separator -> existing PAGE_BREAK presentation. */
final class Assessment {
    public static function fromTemplate(int $id,string $study,string $public,int $number,array $options): array {
        $post=get_post($id);if(!$post||$post->post_type!=='eipsi_form_template')throw new \DomainException('FORM_TEMPLATE_REQUIRED');
        $markers=array();$locations=array();$visit=function($blocks,$path)use(&$visit,&$markers,&$locations){foreach($blocks as $i=>&$b){$where=$path.'/'.($i+1);$name=$b['blockName']??'';$locations[$name][]=$where;
            if($name==='core/separator'){$marker='EIPSI_BUILDER_PAGE_BREAK_'.bin2hex(random_bytes(16));$anchor=$b['attrs']['anchor']??null;$markers[$marker]=is_string($anchor)&&preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,127}$/D',$anchor)?$anchor:'PB_'.substr(hash('sha256',$where),0,24);$b=array('blockName'=>'eipsi/campo-descripcion','attrs'=>array('content'=>$marker),'innerBlocks'=>array(),'innerHTML'=>'','innerContent'=>array());}
            else $b['innerBlocks']=$visit($b['innerBlocks']??array(),$where);
        }unset($b);return $blocks;};$blocks=$visit(parse_blocks($post->post_content),'Assessment');
        try{$d=(new \EIPSI\StudyEngine\Content\TemplateAdapter())->fromBlocks($blocks,$study,$public,$number,$options);}catch(\EIPSI\StudyEngine\Content\UnsupportedBlock $e){throw $e->atLocations($locations[$e->payload()['block']]??array());}
        foreach($d['nodes'] as &$n)if($n['kind']==='CONTENT'){$s=json_decode($n['content']['snapshot'],true);$marker=$s['html']??'';if(isset($markers[$marker]))$n=array('kind'=>'DIRECTIVE','id'=>$markers[$marker],'version_id'=>$n['version_id'],'parent_id'=>$n['parent_id'],'position'=>$n['position'],'directive_kind'=>'PAGE_BREAK','target'=>null,'resume_target'=>null,'on_target_unavailable'=>null);}unset($n);
        return (new \EIPSI\StudyEngine\Contracts\StudyVersionDefinition($d))->toArray();
    }
}
