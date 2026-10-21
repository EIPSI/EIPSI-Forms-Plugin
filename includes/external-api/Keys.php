<?php
namespace EIPSI\ExternalApi;

final class Keys {
    const SCOPES=array('read:studies','read:data');
    private $store;private $clock;
    public function __construct(?Store $store=null,?callable $clock=null){$this->store=$store??new Store();$this->clock=$clock??static function(){return time();};}
    public function now(): int {return (int)call_user_func($this->clock);}
    public static function status(array $r,int $now): string {return $r['revoked_at']!==null?'REVOKED':($r['expires_at']!==null&&(int)$r['expires_at']<=$now?'EXPIRED':'ACTIVE');}
    public static function publicRow(array $r,array $grants,int $now): array {
        return array('id'=>$r['id'],'name'=>$r['name'],'masked'=>'eipsi_'.$r['id'].'_••••••••'.$r['display_suffix'],'status'=>self::status($r,$now),
            'created_at'=>(int)$r['created_at'],'created_by'=>(int)$r['created_by'],'last_used_at'=>$r['last_used_at']===null?null:(int)$r['last_used_at'],
            'revoked_at'=>$r['revoked_at']===null?null:(int)$r['revoked_at'],'expires_at'=>$r['expires_at']===null?null:(int)$r['expires_at'],
            'scopes'=>json_decode($r['scopes_json'],true),'grant_mode'=>$r['grant_mode'],'studies'=>$grants,'revision'=>(int)$r['revision']);
    }
    private function validate(array $input): array {
        if(array_diff(array_keys($input),array('name','scopes','grant_mode','studies','expires_at')))throw new ApiError('eipsi_external_invalid_input',400);
        $name=$input['name']??null;if(!is_string($name)||strlen($name)>480||mb_strlen($name)>120||preg_match('/[\x00-\x1f\x7f]/u',$name))throw new ApiError('eipsi_external_invalid_input',400);
        $name=trim(sanitize_text_field($name));if($name==='')throw new ApiError('eipsi_external_invalid_input',400);
        $scopes=$input['scopes']??array();if(!is_array($scopes)||count($scopes)>2)throw new ApiError('eipsi_external_invalid_input',400);
        foreach($scopes as $s)if(!is_string($s)||!in_array($s,self::SCOPES,true))throw new ApiError('eipsi_external_invalid_input',400);
        $scopes=array_values(array_unique($scopes));sort($scopes);
        $mode=$input['grant_mode']??'NONE';$studies=$input['studies']??array();if(!is_string($mode)||!in_array($mode,array('NONE','SELECTED','ALL'),true)||!is_array($studies)||count($studies)>1000)throw new ApiError('eipsi_external_invalid_input',400);
        if($mode!=='SELECTED'&&$studies)throw new ApiError('eipsi_external_invalid_input',400);
        foreach($studies as $id)if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$id)||!$this->store->studyExists($id))throw new ApiError('eipsi_external_invalid_input',400);
        $studies=array_values(array_unique($studies));sort($studies);if($mode==='SELECTED'&&!$studies)$mode='NONE';
        $expires=$input['expires_at']??null;if($expires!==null&&(!is_int($expires)||$expires<=$this->now()||$expires>253402300799))throw new ApiError('eipsi_external_invalid_input',400);
        return array('name'=>$name,'scopes'=>$scopes,'grant_mode'=>$mode,'studies'=>$studies,'expires_at'=>$expires);
    }
    private function replaceGrants(string $id,array $studies): void {
        $s=$this->store;$s->rows($s->prepare('DELETE FROM '.$s->table('grants').' WHERE key_id=%s',$id));
        foreach($studies as $study)$s->rows($s->prepare('INSERT INTO '.$s->table('grants').' (key_id,study_id) VALUES (%s,%s)',$id,$study));
    }
    public function create(array $input,int $actor,string $request): array {
        $v=$this->validate($input);$id=bin2hex(random_bytes(16));$secret=bin2hex(random_bytes(32));$s=$this->store;$now=$this->now();
        $row=$s->transaction(function()use($v,$id,$secret,$actor,$request,$s,$now){
            $sql='INSERT INTO '.$s->table('keys').' (id,name,secret_hash,display_suffix,created_at,created_by,expires_at,scopes_json,grant_mode) VALUES (%s,%s,%s,%s,%d,%d,'.($v['expires_at']===null?'NULL':'%d').',%s,%s)';
            $args=array($id,$v['name'],hash('sha256',$secret),substr($secret,-4),$now,$actor);if($v['expires_at']!==null)$args[]=$v['expires_at'];$args[]=json_encode($v['scopes']);$args[]=$v['grant_mode'];$s->rows($s->prepare($sql,...$args));$this->replaceGrants($id,$v['studies']);
            $s->audit('CREATED',$id,$actor,$request,$now,$v);return self::publicRow($s->key($id),$v['studies'],$now);
        });return array('key'=>$row,'secret'=>'eipsi_'.$id.'_'.$secret);
    }
    public function update(string $id,int $revision,array $input,int $actor,string $request): array {
        self::id($id);$v=$this->validate($input);$s=$this->store;$now=$this->now();
        return $s->transaction(function()use($id,$revision,$v,$actor,$request,$s,$now){
            $old=$s->key($id,true);if(!$old)throw new ApiError('eipsi_external_key_not_found',404);
            if((int)$old['revision']!==$revision)throw new ApiError('eipsi_external_conflict',409);
            if(self::status($old,$now)==='REVOKED')throw new ApiError('eipsi_external_conflict',409);
            $prior=self::publicRow($old,$s->grants($id),$now);
            $s->rows($s->prepare('UPDATE '.$s->table('keys').' SET name=%s,scopes_json=%s,grant_mode=%s,expires_at='.($v['expires_at']===null?'NULL':(string)$v['expires_at']).',revision=revision+1 WHERE id=%s',$v['name'],json_encode($v['scopes']),$v['grant_mode'],$id));
            $this->replaceGrants($id,$v['studies']);
            foreach(array('name'=>'NAME_CHANGED','scopes'=>'SCOPES_CHANGED','expires_at'=>'EXPIRATION_CHANGED') as $field=>$event)if($prior[$field]!==$v[$field])$s->audit($event,$id,$actor,$request,$now,$v);
            if($prior['grant_mode']!==$v['grant_mode']||$prior['studies']!==$v['studies'])$s->audit('GRANTS_CHANGED',$id,$actor,$request,$now,$v);
            return self::publicRow($s->key($id),$v['studies'],$now);
        });
    }
    public function revoke(string $id,int $revision,int $actor,string $request): array {
        self::id($id);$s=$this->store;$now=$this->now();return $s->transaction(function()use($id,$revision,$actor,$request,$s,$now){$r=$s->key($id,true);if(!$r)throw new ApiError('eipsi_external_key_not_found',404);
            if($r['revoked_at']===null){if((int)$r['revision']!==$revision)throw new ApiError('eipsi_external_conflict',409);$s->rows($s->prepare('UPDATE '.$s->table('keys').' SET revoked_at=%d,revision=revision+1 WHERE id=%s',$now,$id));$s->audit('REVOKED',$id,$actor,$request,$now);}
            return self::publicRow($s->key($id),$s->grants($id),$now);});
    }
    public static function id(string $id): void {if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new ApiError('eipsi_external_invalid_input',400);}
    public function listing(): array {$out=array();foreach($this->store->rows('SELECT * FROM '.$this->store->table('keys').' ORDER BY created_at DESC,id') as $r)$out[]=self::publicRow($r,$this->store->grants($r['id']),$this->now());return $out;}
}
