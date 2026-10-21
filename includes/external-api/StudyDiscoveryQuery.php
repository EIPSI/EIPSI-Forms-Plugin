<?php
namespace EIPSI\ExternalApi;

/** Administrative projection only. Never loads definitions, participants or scientific events. */
final class StudyDiscoveryQuery {
    private $store;private $policy;private $cursor;private $clock;
    public function __construct(?Store $store=null,?ExternalApiPolicy $policy=null,?Cursor $cursor=null,?callable $clock=null){
        $this->store=$store??new Store();$this->clock=$clock??static function(){return time();};
        $this->policy=$policy??new ExternalApiPolicy($this->store,$this->clock);$this->cursor=$cursor??new Cursor($this->clock);
    }
    private function versions(): string {global $wpdb;return '`'.$wpdb->prefix.'eipsi_engine_study_versions`';}
    private function publicStudies(): string {global $wpdb;return '`'.$wpdb->prefix.'eipsi_engine_public_studies`';}
    private function grantPredicate(ExternalPrincipal $p): string {
        $v=$p->context();
        if($v['grant_mode']==='ALL')return '1=1';
        if($v['grant_mode']!=='SELECTED')return '1=0';
        return $this->store->prepare('EXISTS (SELECT 1 FROM '.$this->store->table('grants').' eg WHERE eg.key_id=%s AND eg.study_id=v.study_id)',$p->id());
    }
    private function execute(ExternalPrincipal $p,string $operation,callable $read){
        $start=microtime(true);$ok=false;$count=0;
        try{
            // Outside the read transaction: rejected cursors/params also consume their budget.
            \EIPSI_Rate_Limiter::consume('external_studies',$p->id(),60,60,(int)call_user_func($this->clock));
            $result=$this->policy->run($p,'read:studies',null,$read);$count=$operation==='LIST'?count($result['items']):1;$ok=true;
            return $result;
        }finally{
            $duration=(int)round((microtime(true)-$start)*1000);
            $this->store->audit('STUDIES_'.$operation.'_'.($ok?'OK':'FAILED'),$p->id(),0,$p->context()['request_id'],(int)call_user_func($this->clock),array('returned_count'=>$count,'duration_ms'=>$duration,'result'=>$ok?'OK':'FAILED'),true);
        }
    }
    public function page(ExternalPrincipal $p,array $params=array()): array {
        return $this->execute($p,'LIST',function(ExternalPrincipal $fresh)use($params){
            if(array_diff(array_keys($params),array('limit','cursor')))throw new ApiError('eipsi_external_invalid_parameters',400);
            $limit=array_key_exists('limit',$params)?$params['limit']:50;
            if((!is_int($limit)&&(!is_string($limit)||!preg_match('/^[1-9][0-9]{0,2}$/D',$limit)))||(int)$limit<1||(int)$limit>200)throw new ApiError('eipsi_external_invalid_parameters',400);
            $limit=(int)$limit;$after='';$upper=null;
            if(array_key_exists('cursor',$params)){$position=$this->cursor->decode($params['cursor'],$fresh->id());$after=$position['after'];$upper=$position['upper'];}
            $grant=$this->grantPredicate($fresh);
            if($upper===null){$row=$this->store->row('SELECT MAX(v.study_id) upper_id FROM '.$this->versions().' v WHERE '.$grant);$upper=$row['upper_id']??null;}
            if($upper===null)return array('items'=>array(),'next_cursor'=>null);
            $where=$grant.$this->store->prepare(' AND v.study_id>%s AND v.study_id<=%s',$after,$upper);
            $rows=$this->projectedRows($where,$limit+1);$more=count($rows)>$limit;if($more)array_pop($rows);
            $items=array_map(array($this,'dto'),$rows);$last=$rows?end($rows)['study_id']:null;
            return array('items'=>$items,'next_cursor'=>$more?$this->cursor->encode($fresh->id(),$last,$upper):null);
        });
    }
    public function detail(ExternalPrincipal $p,$code,array $params=array()): array {
        return $this->execute($p,'DETAIL',function(ExternalPrincipal $fresh)use($code,$params){
            if($params)throw new ApiError('eipsi_external_invalid_parameters',400);
            if(!is_string($code)||!preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,127}$/D',$code))throw new ApiError('eipsi_external_study_unavailable',404);
            $rows=$this->projectedRows($this->grantPredicate($fresh),1,$code);
            if(!$rows)throw new ApiError('eipsi_external_study_unavailable',404);
            return $this->dto($rows[0]);
        });
    }
    private function projectedRows(string $where,int $limit,?string $code=null): array {
        $v=$this->versions();$p=$this->publicStudies();
        // One aggregate per logical Study; LIMIT follows grant filtering and precedes JSON work.
        $group='SELECT v.study_id,MIN(v.version_number) first_version,MAX(v.version_number) last_version,MIN(v.created_at) created_at,MAX(v.updated_at) updated_at FROM '.$v.' v WHERE '.$where.' GROUP BY v.study_id';
        if($code===null)$group.=$this->store->prepare(' ORDER BY v.study_id LIMIT %d',$limit);
        $sql="SELECT g.study_id,JSON_UNQUOTE(JSON_EXTRACT(first.protocol_json,'$.study.public_id')) code,
            COALESCE(JSON_UNQUOTE(JSON_EXTRACT(last.metadata_json,'$.study_admin.name')),JSON_UNQUOTE(JSON_EXTRACT(last.protocol_json,'$.protocol_metadata.study_name')),JSON_UNQUOTE(JSON_EXTRACT(last.protocol_json,'$.protocol_metadata.title')),JSON_UNQUOTE(JSON_EXTRACT(first.protocol_json,'$.study.public_id'))) name,
            JSON_UNQUOTE(JSON_EXTRACT(last.protocol_json,'$.protocol_metadata.study_type')) study_type,
            JSON_UNQUOTE(JSON_EXTRACT(last.protocol_json,'$.protocol_metadata.enrollment_mode')) enrollment_mode,
            JSON_UNQUOTE(JSON_EXTRACT(last.metadata_json,'$.archived')) archived,
            p.enrollment_state,g.created_at,GREATEST(g.updated_at,COALESCE(p.updated_at,g.updated_at)) updated_at
            FROM ($group) g JOIN $v first ON first.study_id=g.study_id AND first.version_number=g.first_version
            JOIN $v last ON last.study_id=g.study_id AND last.version_number=g.last_version
            LEFT JOIN $p p ON p.study_id=g.study_id";
        if($code!==null)$sql.=$this->store->prepare(" WHERE BINARY JSON_UNQUOTE(JSON_EXTRACT(first.protocol_json,'$.study.public_id'))=BINARY %s",$code);
        $sql.=$this->store->prepare(' ORDER BY g.study_id LIMIT %d',$limit);
        return $this->store->rows($sql);
    }
    private function dto(array $r): array {
        $type=$r['study_type']??null;
        if($type===null)$type=($r['enrollment_mode']??'ANONYMOUS')==='EMAIL_LONGITUDINAL'?'LONGITUDINAL':'TRANSVERSAL';
        if(!in_array($type,array('TRANSVERSAL','LONGITUDINAL'),true))throw new ApiError('eipsi_external_unavailable',503);
        $archived=($r['archived']??null)==='true';$state=$r['enrollment_state'];
        $states=array('OPEN'=>'ACTIVE','PAUSED'=>'PAUSED','CLOSED'=>'PAUSED','FINALIZED'=>'FINALIZED');
        if($state!==null&&!isset($states[$state]))throw new ApiError('eipsi_external_unavailable',503);
        return array('code'=>$r['code'],'name'=>$r['name'],'type'=>$type,'status'=>$archived?'ARCHIVED':($state===null?'DRAFT':$states[$state]),'created_at'=>self::utc($r['created_at']),'updated_at'=>self::utc($r['updated_at']),'archived'=>$archived);
    }
    private static function utc(string $time): string {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$time,new \DateTimeZone('UTC'));
        if(!$d||$d->format('Y-m-d H:i:s')!==$time)throw new ApiError('eipsi_external_unavailable',503);
        return $d->format('Y-m-d\TH:i:s\Z');
    }
}
