<?php
namespace EIPSI\ExternalApi;

final class Rest {
    const NS='eipsi-external/v1';
    private $contexts;
    public function __construct(){$this->contexts=new \SplObjectStorage();}
    public static function owns($request): bool {return strpos($request->get_route(),'/'.self::NS.'/')===0||$request->get_route()==='/'.self::NS;}
    public function register(): void {
        register_rest_route(self::NS,'/status',array('methods'=>'GET','permission_callback'=>array($this,'permission'),'callback'=>array($this,'status')));
        register_rest_route(self::NS,'/studies',array('methods'=>'GET','permission_callback'=>array($this,'permission'),'callback'=>array($this,'studies')));
        foreach(array('/data','/dictionary')as$suffix)register_rest_route(self::NS,'/studies/(?P<code>[^/]+)'.$suffix,array('methods'=>'GET','permission_callback'=>array($this,'permission'),'callback'=>array($this,'dataset')));
        register_rest_route(self::NS,'/studies/(?P<code>[^/]+)/exports',array('methods'=>'POST','permission_callback'=>array($this,'permission'),'callback'=>array($this,'export')));
        foreach(array('','/data','/download','/dictionary','/manifest')as$suffix)register_rest_route(self::NS,'/exports/(?P<export_id>[^/]+)'.$suffix,array('methods'=>'GET','permission_callback'=>array($this,'permission'),'callback'=>array($this,'export')));
        register_rest_route(self::NS,'/studies/(?P<code>[^/]+)',array('methods'=>'GET','permission_callback'=>array($this,'permission'),'callback'=>array($this,'study')));
    }
    public function export($request){
        $c=$this->context($request);
        try {
            if(!isset($c['principal']))throw new ApiError('eipsi_external_unauthorized',401);
            $api=new DatasetSnapshots();$url=$request->get_url_params();$params=$request->get_query_params();unset($params['rest_route']);
            if(isset($url['code'])){if($params||stripos((string)$request->get_header('content-type'),'application/json')!==0)throw new ApiError('eipsi_external_invalid_filters',400);$body=json_decode($request->get_body(),true);if(!is_array($body)||substr(ltrim($request->get_body()),0,1)!=='{')throw new ApiError('eipsi_external_invalid_filters',400);$result=$api->create($c['principal'],$url['code'],$body);$response=new \WP_REST_Response($result,202);$response->header('Retry-After',(string)OperationsSettings::get()['poll_seconds']);return $response;}
            if((string)$request->get_body()!=='')throw new ApiError('eipsi_external_invalid_filters',400);
            $id=$url['export_id'];$action=basename($request->get_route());if($action!=='data'&&$params)throw new ApiError('eipsi_external_invalid_filters',400);
            if($action==='download'){$download=$api->download($c['principal'],$id,$request->get_header('range')?:null);$download->ifRange($request->get_header('if-range')?:null);$c['download']=$download;$this->contexts[$request]=$c;$response=new \WP_REST_Response(null,$download->status);
                $response->header('Content-Type',array('csv'=>'text/csv; charset=utf-8','json_bundle'=>'application/json; charset=utf-8','xlsx'=>\EIPSI\Dataset\Transport::MIME_XLSX)[$download->format]??'application/x-ndjson; charset=utf-8');$response->header('Content-Disposition','attachment; filename="eipsi-export-'.$id.'.'.(array('csv'=>'csv','json_bundle'=>'json','xlsx'=>'xlsx')[$download->format]??'ndjson').'"');$response->header('Content-Length',(string)$download->length);$response->header('Accept-Ranges','bytes');$response->header('ETag','"'.$download->sha256.'"');if($download->status===206)$response->header('Content-Range','bytes '.$download->start.'-'.($download->start+$download->length-1).'/'.$download->total);return $response;}
            if($action==='data')$result=$api->data($c['principal'],$id,$params);elseif($action==='dictionary')$result=$api->dictionary($c['principal'],$id);elseif($action==='manifest')$result=$api->manifest($c['principal'],$id);else{$result=$api->status($c['principal'],$id);if($result['status']==='PREPARING'){try{$api->work($id);}catch(\Throwable $ignored){}$result=$api->status($c['principal'],$id);}}
            $response=new \WP_REST_Response($result,200);if(($result['status']??null)==='PREPARING')$response->header('Retry-After',(string)OperationsSettings::get()['poll_seconds']);return $response;
        }catch(\Throwable $e){if($e instanceof ApiError){$c['error_headers']=$e->responseHeaders;$this->contexts[$request]=$c;}return $this->failure($e,$c['request_id']);}
    }
    public function dataset($request){
        $c=$this->context($request);
        try{if(!isset($c['principal']))throw new ApiError('eipsi_external_unauthorized',401);$params=$request->get_query_params();unset($params['rest_route']);if((string)$request->get_body()!=='')throw new ApiError('eipsi_external_invalid_filters',400);
            $result=(new StudyDataExport())->read($c['principal'],$request->get_url_params()['code']??null,$params,substr($request->get_route(),-11)==='/dictionary');
            if(isset($result['csv'])){$c['csv']=true;$this->contexts[$request]=$c;$response=new \WP_REST_Response($result['csv'],200);$response->header('Content-Type','text/csv; charset=utf-8');$response->header('X-EIPSI-As-Of',$result['as_of']);$response->header('Content-Disposition','attachment; filename="'.$result['filename'].'"');return $response;}
            return new \WP_REST_Response($result,200);
        }catch(\Throwable $e){return $this->failure($e,$c['request_id']);}
    }
    private function context($request): array {
        if(!$this->contexts->contains($request))$this->contexts[$request]=array('request_id'=>Authenticator::requestId(),'started_at'=>microtime(true));
        return $this->contexts[$request];
    }
    private function failure(\Throwable $e,string $id): \WP_Error {
        $status=$e instanceof ApiError?$e->getCode():($e instanceof \EIPSI_Rate_Limit_Exception?$e->getCode():503);
        $code=$e instanceof ApiError?$e->apiCode:($e instanceof \EIPSI_Rate_Limit_Exception?'eipsi_external_rate_limited':'eipsi_external_unavailable');
        $data=array('status'=>$status,'request_id'=>$id,'api_version'=>'v1');if($e instanceof \EIPSI_Rate_Limit_Exception)$data['retry_after']=$e->retry_after;
        return new \WP_Error($code,'External API request could not be authorized.',$data);
    }
    public function preDispatch($result,$server,$request){
        if(!self::owns($request))return $result;
        $c=$this->context($request);
        try{
            // Disabled gate precedes all Bearer parsing, writes, and method/route discovery.
            if(!(new Store())->enabled())throw new ApiError('eipsi_external_unavailable',404);
            $headers=$request->get_headers();$auth=$headers['authorization']??array();
            if(count($auth)!==1)$header=null;else $header=$auth[0];
            // Reject differing copies exposed by the webserver instead of silently picking one.
            foreach(array('HTTP_AUTHORIZATION','REDIRECT_HTTP_AUTHORIZATION') as $name)if(isset($_SERVER[$name])&&$_SERVER[$name]!==$header)$header=null;
            $c['principal']=(new Authenticator())->authenticate($header,$c['request_id'],(string)($_SERVER['REMOTE_ADDR']??'unknown'));
            $this->contexts[$request]=$c;
            return $result;
        }catch(\Throwable $e){return $this->failure($e,$c['request_id']);}
    }
    public function permission($request){$c=$this->context($request);return isset($c['principal'])?true:$this->failure(new ApiError('eipsi_external_unauthorized',401),$c['request_id']);}
    public function status($request){
        $c=$this->context($request);
        try{if(!isset($c['principal']))throw new ApiError('eipsi_external_unauthorized',401);
            return (new ExternalApiPolicy())->run($c['principal'],null,null,static function(ExternalPrincipal $p){$v=$p->context();return new \WP_REST_Response(array('api_version'=>'v1','authenticated'=>true,'key_id'=>$v['id'],'key_name'=>$v['name'],'scopes'=>$v['scopes'],'request_id'=>$v['request_id']),200);});
        }catch(\Throwable $e){return $this->failure($e,$c['request_id']);}
    }
    private function discovery($request,bool $detail){
        $c=$this->context($request);
        try{
            if(!isset($c['principal']))throw new ApiError('eipsi_external_unauthorized',401);
            $params=$request->get_query_params();unset($params['rest_route']);
            // No request bodies or global REST projections can alter the discovery contract.
            if((string)$request->get_body()!=='')throw new ApiError('eipsi_external_invalid_parameters',400);
            $query=new StudyDiscoveryQuery();
            return new \WP_REST_Response($detail?$query->detail($c['principal'],$request->get_url_params()['code']??null,$params):$query->page($c['principal'],$params),200);
        }catch(\Throwable $e){return $this->failure($e,$c['request_id']);}
    }
    public function studies($request){return $this->discovery($request,false);}
    public function study($request){return $this->discovery($request,true);}
    public function headers($response,$server,$request){
        if(!self::owns($request))return $response;
        $response=rest_ensure_response($response);$ctx=$this->context($request);if(empty($ctx['measured'])){OperationsMetrics::record(OperationsMetrics::category($request->get_route()),array('requests'=>1,'failures'=>$response->get_status()>=400?1:0,'duration_ms'=>(int)round((microtime(true)-($ctx['started_at']??microtime(true)))*1000),'rate_limits'=>$response->get_status()===429?1:0,'downloaded_bytes'=>isset($ctx['download'])&&$response->get_status()<400?$ctx['download']->length:0),time());$ctx['measured']=true;$this->contexts[$request]=$ctx;}$response->header('Cache-Control','private, no-store');$response->header('Pragma','no-cache');$response->header('X-Content-Type-Options','nosniff');$response->header('Vary','Authorization');$response->header('X-Request-ID',$this->context($request)['request_id']);
        foreach($this->context($request)['error_headers']??array()as$name=>$value)$response->header($name,$value);
        if($response->get_status()===401)$response->header('WWW-Authenticate','Bearer');
        if($response->get_status()===429){$data=$response->get_data();$response->header('Retry-After',(string)($data['data']['retry_after']??60));}
        return $response;
    }
    public function noCors($served,$response,$request,$server){
        if(self::owns($request))foreach(array('Access-Control-Allow-Origin','Access-Control-Allow-Credentials','Access-Control-Allow-Methods','Access-Control-Allow-Headers','Access-Control-Expose-Headers') as $header)header_remove($header);
        if(self::owns($request)&&isset($this->context($request)['download'])&&in_array($response->get_status(),array(200,206),true)){$this->context($request)['download']->emit();return true;}
        if(self::owns($request)&&!empty($this->context($request)['csv'])&&$response->get_status()===200){echo $response->get_data();return true;}
        return $served;
    }
}
