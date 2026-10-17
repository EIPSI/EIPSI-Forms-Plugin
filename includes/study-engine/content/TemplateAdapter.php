<?php
namespace EIPSI\StudyEngine\Content;
use EIPSI\StudyEngine\Contracts\Canonical;
use EIPSI\StudyEngine\Contracts\Check;
use EIPSI\StudyEngine\Contracts\StudyVersionDefinition;

/** Explicit import. Published runtime never reads the mutable template again. */
final class TemplateAdapter {
    private $nodes=array();
    private $seen=array();
    private $version;
    private $clone;
    public function fromTemplate(int $template,string $study,string $public,int $number,array $options=array()): array {
        $post=get_post($template);
        if(!$post||$post->post_type!=='eipsi_form_template')throw new \DomainException('FORM_TEMPLATE_REQUIRED');
        return $this->fromBlocks(parse_blocks($post->post_content),$study,$public,$number,$options);
    }
    public function fromBlocks(array $blocks,string $study,string $public,int $number,array $options=array()): array {
        Check::id($study,'study');Check::id($public,'public');Check::integer($number,'version',1);
        Check::fields($options,array(),array('condition_rules','clone_namespace','random_groups','assessment_schedule','timezone'));
        $this->nodes=array();$this->seen=array();$this->version='VERSION_'.$number;$this->clone=$options['clone_namespace']??'';
        if($this->clone!=='')Check::id($this->clone,'clone_namespace');
        $forms=array_values(array_filter($blocks,static function($b){return ($b['blockName']??null)!==null||trim($b['innerHTML']??'')!=='';}));
        if(count($forms)!==1||$forms[0]['blockName']!=='eipsi/form-container')throw new \DomainException('ONE_FORM_CONTAINER_REQUIRED');
        $form=$forms[0];$a=$form['attrs'];
        if(!empty($a['useRandomization'])||!empty($a['requireLogin']))throw new \DomainException('LEGACY_FORM_WORKFLOW_UNSUPPORTED');
        $macro=$this->block('M_ROOT',$this->version,'MACRO',0,'NONE',$this->schedule('EXECUTION_CREATED',null));
        $assessment=$this->block('E_ASSESSMENT','M_ROOT','MICRO',0,'ASSESSMENT',$options['assessment_schedule']??$this->schedule('BLOCK_AVAILABLE','M_ROOT'));
        $this->nodes[]=$macro;$this->nodes[]=$assessment;
        $position=0;$page=0;
        foreach($form['innerBlocks'] as $child){
            if(($child['blockName']??null)==='eipsi/form-page'){
                $pa=$child['attrs'];if(!empty($pa['isHidden'])||($pa['pageType']??'standard')!=='standard')throw new \DomainException('LEGACY_PAGE_WORKFLOW_UNSUPPORTED');
                if($page++)$this->nodes[]=$this->directive('PB_'.$page,'E_ASSESSMENT',$position++);
                $id='G_PAGE_'.$page;$this->nodes[]=$this->block($id,'E_ASSESSMENT','MICRO',$position++,'GROUP',null);
                $this->convertChildren($child['innerBlocks'],$id);
            }else{$this->convert($child,'E_ASSESSMENT',$position++);}
        }
        foreach($options['random_groups']??array() as $group=>$config){
            $found=false;foreach($this->nodes as &$node)if($node['id']===$group&&$node['kind']==='BLOCK'){$node['children_strategy']=$config['strategy'];$node['randomization']=$config;$found=true;}unset($node);
            if(!$found)throw new \DomainException('RANDOM_GROUP_NOT_FOUND');
        }
        $d=array('contract_version'=>'P1B_V1','study'=>array('logical_id'=>$study,'public_id'=>$public),'id'=>$this->version,'version_number'=>$number,'state'=>'DRAFT','timezone'=>array('policy'=>'PARTICIPANT_LOCAL','default'=>$options['timezone']??'UTC'),'root_block_ids'=>array('M_ROOT'),'nodes'=>$this->nodes,'condition_rules'=>$options['condition_rules']??array(),'compiler_contract_version'=>'P1C_V1','protocol_metadata'=>array('content_adapter'=>'P1E_V1','storage_mode'=>'WORDPRESS_LOCAL','allow_back'=>($a['allowBackwardsNav']??true)===true,'completion_title'=>(wp_strip_all_tags($a['completionTitle']??'')?:'Gracias por participar'),'completion_message'=>(wp_strip_all_tags($a['completionMessage']??'')?:'Tus respuestas quedaron guardadas.')),'closure'=>array('kind'=>'ALL_SELECTED_OPPORTUNITIES_TERMINAL'));
        return (new StudyVersionDefinition($d))->toArray();
    }
    private function schedule(string $anchor,?string $ref): array {return array('anchor'=>$anchor,'anchor_ref'=>$ref,'occurrence_selector'=>$ref===null?null:array('kind'=>'SINGLE'),'temporal_rule'=>array('kind'=>'IMMEDIATE'),'timezone_policy'=>'PARTICIPANT_LOCAL','window'=>array('kind'=>'NO_DEADLINE'),'recurrence'=>null,'dst_policy'=>array('gap'=>'SHIFT_FORWARD_BY_GAP','fold'=>'EARLIER_INSTANT'));}
    private function block(string $id,string $parent,string $scope,int $position,string $boundary,?array $schedule): array {return array('kind'=>'BLOCK','id'=>$id,'logical_id'=>$id,'version_id'=>$this->version,'parent_id'=>$parent,'scope'=>$scope,'position'=>$position,'children_strategy'=>'SEQUENTIAL','randomization'=>null,'schedule'=>$schedule,'completion_boundary'=>$boundary,'metadata'=>array());}
    private function directive(string $id,string $parent,int $position): array {return array('kind'=>'DIRECTIVE','id'=>$id,'version_id'=>$this->version,'parent_id'=>$parent,'position'=>$position,'directive_kind'=>'PAGE_BREAK','target'=>null,'resume_target'=>null,'on_target_unavailable'=>null);}
    private function content(string $id,array $snapshot): array {$json=Canonical::json($snapshot);return array('content_id'=>'CONTENT_'.$id,'version'=>1,'snapshot'=>$json,'sha256'=>hash('sha256',$json));}
    private function convertChildren(array $blocks,string $parent): void {foreach($blocks as $i=>$block)$this->convert($block,$parent,$i);}
    private function convert(array $block,string $parent,int $position): void {
        $name=$block['blockName']??null;$a=$block['attrs']??array();
        if($name===null){if(trim($block['innerHTML']??'')!=='')throw new \DomainException('UNSTRUCTURED_HTML_UNSUPPORTED');return;}
        if(!empty($a['conditionalLogic'])&&($a['conditionalLogic']['enabled']??true))throw new \DomainException('LEGACY_CONDITION_REQUIRES_DOMAIN_RULE');
        if($name==='core/group'){
            $id=$a['anchor']??'';Check::id($id,'micro.anchor');$id='G_'.$id;if(isset($this->seen[$id]))throw new \DomainException('DUPLICATE_LOGICAL_ID');$this->seen[$id]=true;
            $this->nodes[]=$this->block($id,$parent,'MICRO',$position,'GROUP',null);$this->convertChildren($block['innerBlocks'],$id);return;
        }
        if(in_array($name,array('eipsi/campo-descripcion','core/paragraph','core/heading','core/image'),true)){
            $id='C_'.bin2hex(random_bytes(8));$media=$name==='core/image'?$this->media($a):null;
            $html=$name==='eipsi/campo-descripcion'?($a['content']??$a['helperText']??''):($block['innerHTML']??'');
            $snapshot=array('html'=>wp_kses_post($html),'label'=>wp_strip_all_tags($a['label']??''),'media'=>$media);
            $this->nodes[]=array('kind'=>'CONTENT','id'=>$id,'version_id'=>$this->version,'parent_id'=>$parent,'position'=>$position,'type'=>$media===null?'TEXT':'MEDIA','content'=>$this->content($id,$snapshot),'durable_reference'=>$media===null?null:array('uri'=>$media['snapshot_url'],'sha256'=>$media['sha256']));return;
        }
        $types=array('eipsi/campo-texto'=>'TEXT','eipsi/campo-textarea'=>'TEXT','eipsi/campo-radio'=>'CHOICE','eipsi/campo-select'=>'CHOICE','eipsi/campo-likert'=>'INTEGER','eipsi/vas-slider'=>'NUMBER','eipsi/consent-block'=>'BOOLEAN');
        if(!isset($types[$name]))throw new \DomainException('UNSUPPORTED_BLOCK: '.$name);
        if(!empty($block['innerBlocks']))throw new \DomainException('QUESTION_CHILDREN_UNSUPPORTED');
        $key=trim(!empty($a['fieldKey'])?$a['fieldKey']:($a['fieldName']??''));Check::id($key,'stable_field_key');$key=($this->clone===''?'':$this->clone.'_').$key;
        $id='Q_'.$key;if(isset($this->seen[$id]))throw new \DomainException('DUPLICATE_LOGICAL_ID');$this->seen[$id]=true;
        $type=$types[$name];$validation=array();$choices=array();$format=$a['fieldType']??'text';
        if($name==='eipsi/campo-texto'){
            if($format==='number')$type='NUMBER';elseif(!in_array($format,array('text','email','tel'),true))throw new \DomainException('UNSUPPORTED_TEXT_FORMAT');
        }
        if($type==='CHOICE'){
            $labels=$this->options($a['options']??'');foreach($labels as $label)$choices[]=array('id'=>'O_'.substr(hash('sha256',$label),0,24),'label'=>$label);
            if(!$choices)throw new \DomainException('EMPTY_OPTIONS');
        }
        if($name==='eipsi/campo-likert'){
            if(!empty($a['reversed'])||($a['scaleVariation']??'custom')!=='custom')throw new \DomainException('LIKERT_VARIANT_REQUIRES_EXPLICIT_ADAPTER');
            $labels=$this->options($a['labels']??'');if(!$labels)throw new \DomainException('EMPTY_SCALE');$validation=array('min'=>(int)($a['minValue']??0),'max'=>(int)($a['minValue']??0)+count($labels)-1);
        }
        if($name==='eipsi/vas-slider')$validation=array('min'=>$a['minValue']??0,'max'=>$a['maxValue']??100);
        if($type==='TEXT')$validation['max_length']=max(1,(int)($a['maxLength']??1000));
        $snapshot=array('label'=>wp_strip_all_tags($a['label']??$a['titulo']??$key),'helper'=>wp_strip_all_tags($a['helperText']??''),'placeholder'=>wp_strip_all_tags($a['placeholder']??''),'format'=>$format,'widget'=>$name,'min_length'=>max(0,(int)($a['minLength']??0)),'step'=>$name==='eipsi/vas-slider'?($a['step']??1):null,'scale_labels'=>$name==='eipsi/campo-likert'?$labels:array(),'consent_html'=>$name==='eipsi/consent-block'?wp_kses_post($a['contenido']??''):null);
        $this->nodes[]=array('kind'=>'QUESTION','id'=>$id,'version_id'=>$this->version,'parent_id'=>$parent,'position'=>$position,'response_key'=>'R_'.$key,'type'=>$type,'options'=>$choices,'validation'=>$validation,'required'=>($a['required']??$a['isRequired']??false)===true,'semantic_role'=>$name==='eipsi/consent-block'?'INFORMED_CONSENT':null,'content'=>$this->content($id,$snapshot));
    }
    private function options(string $input): array {
        $s=str_replace(array("\r\n","\r"),"\n",trim($input));$rows=strpos($s,';')!==false?explode(';',$s):(strpos($s,"\n")!==false?explode("\n",$s):str_getcsv($s));$rows=array_values(array_filter(array_map('trim',$rows),static function($x){return $x!=='';}));
        if(count($rows)!==count(array_unique($rows)))throw new \DomainException('DUPLICATE_OPTIONS');return $rows;
    }
    private function media(array $a): array {
        $id=(int)($a['id']??0);$file=$id?get_attached_file($id):false;
        if(!$file||!is_file($file))throw new \DomainException('LOCAL_MEDIA_REQUIRED');
        $ext=strtolower(pathinfo($file,PATHINFO_EXTENSION));if(!in_array($ext,array('jpg','jpeg','png','gif','webp'),true))throw new \DomainException('MEDIA_TYPE_UNSUPPORTED');
        if(!getimagesize($file))throw new \DomainException('MEDIA_IMAGE_INVALID');
        $hash=hash_file('sha256',$file);$upload=wp_upload_dir();if(!empty($upload['error']))throw new \DomainException('MEDIA_SNAPSHOT_UNAVAILABLE');
        $dir=$upload['basedir'].'/eipsi-study-media';if(!wp_mkdir_p($dir))throw new \DomainException('MEDIA_SNAPSHOT_UNAVAILABLE');$dest=$dir.'/'.$hash.'.'.$ext;
        if(!is_file($dest)&&!copy($file,$dest))throw new \DomainException('MEDIA_SNAPSHOT_UNAVAILABLE');if(hash_file('sha256',$dest)!==$hash)throw new \DomainException('MEDIA_SNAPSHOT_CORRUPT');
        return array('attachment_id'=>$id,'source_url'=>wp_get_attachment_url($id),'snapshot_url'=>$upload['baseurl'].'/eipsi-study-media/'.$hash.'.'.$ext,'sha256'=>$hash,'snapshot_path'=>$dest,'alt'=>wp_strip_all_tags($a['alt']??''));
    }
}
