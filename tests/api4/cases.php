<?php
use EIPSI\ExternalApi as A;
function a4_http($k,$path,$query=array(),$body=null,$headers=array()){
 $h=array_merge(array('Host'=>'127.0.0.1:18080'),$headers);if($k!==null)$h['Authorization']='Bearer '.$k['secret'];if($body!==null)$h['Content-Type']='application/json';
 $r=wp_remote_request('http://127.0.0.1/?'.http_build_query(array_merge(array('rest_route'=>'/eipsi-external/v1'.$path),$query)),array('method'=>$body===null?'GET':'POST','headers'=>$h,'body'=>$body===null?null:json_encode($body),'timeout'=>30,'redirection'=>0));d2_assert(!is_wp_error($r));$status=wp_remote_retrieve_response_code($r);$data=json_decode(wp_remote_retrieve_body($r),true);$GLOBALS['a4_samples'][]=array('path'=>$path,'status'=>$status,'body'=>$data,'headers'=>iterator_to_array(wp_remote_retrieve_headers($r)));
 return array('status'=>$status,'data'=>$data,'body'=>wp_remote_retrieve_body($r),'headers'=>wp_remote_retrieve_headers($r));
}
d2_case('OpenAPI paths methods exactly match registered WordPress external routes',function(){
 $spec=json_decode(file_get_contents(EIPSI_FORMS_PLUGIN_DIR.'openapi/eipsi-external-v1.yaml'),true);$actual=array();foreach(rest_get_server()->get_routes()as$route=>$defs){if(strpos($route,'/eipsi-external/v1/')!==0)continue;$path=substr($route,strlen('/eipsi-external/v1'));$path=preg_replace('/\(\?P<code>[^)]*\)/','{code}',$path);$path=preg_replace('/\(\?P<export_id>[^)]*\)/','{id}',$path);foreach($defs as$def)foreach($def['methods']??array()as$method=>$on)if($on)$actual[]=strtolower($method).' '.$path;}
 $wanted=array();foreach($spec['paths']as$path=>$methods)foreach($methods as$method=>$def)$wanted[]=$method.' '.$path;sort($actual);sort($wanted);d2_assert($actual===$wanted,'OpenAPI route/method drift');
});
d2_case('Data columns and all documented parameters match runtime owners',function(){
 $spec=json_decode(file_get_contents(EIPSI_FORMS_PLUGIN_DIR.'openapi/eipsi-external-v1.yaml'),true);$columns=$spec['components']['schemas']['Row']['required'];d2_assert($columns===EIPSI\StudyEngine\Management\Exports::COLUMNS);
 $v2=array('contract','version','cycle','execution','assessment','opportunity','response_from','response_to','include_retained');
 $expect=array('/studies'=>array('limit','cursor'),'/studies/{code}'=>array(),'/studies/{code}/data'=>array_merge(array('format','limit','cursor','include_partials'),$v2),'/studies/{code}/dictionary'=>array_merge(array('include_partials'),$v2),'/exports/{id}/data'=>array('limit','cursor'));
 foreach($expect as$path=>$names){$q=array();foreach($spec['paths'][$path]['get']['parameters']as$p)if($p['in']==='query')$q[]=$p['name'];sort($q);sort($names);d2_assert($q===$names);}
 $keys=array_keys($spec['components']['schemas']['ExportRequestV1']['properties']);sort($keys);d2_assert($keys===array('format','include_partials'));
 // Compare documentation to the actual allowlist expressions, not only a second hand-written inventory.
 $source=file_get_contents(EIPSI_FORMS_PLUGIN_DIR.'includes/external-api/StudyDiscoveryQuery.php');
 d2_assert(preg_match('/array_diff\\(array_keys\\(\\$params\\),array\\(([^)]*)\\)\\)/',$source,$m)===1,'Discovery allowlist not found');
 preg_match_all("/'([^']+)'/",$m[1],$names);$actual=$names[1];sort($actual);$wanted=$expect['/studies'];sort($wanted);d2_assert($actual===$wanted,'Discovery parameter drift');
 $source=file_get_contents(EIPSI_FORMS_PLUGIN_DIR.'includes/external-api/DatasetSnapshots.php');
 foreach(array('body'=>'ExportRequestV1','params'=>null)as$variable=>$schema){
  $pattern='/array_diff\\(array_keys\\(\\$'.$variable.'\\),array\\(([^)]*)\\)\\)/';
  d2_assert(preg_match($pattern,$source,$m)===1,'Snapshot allowlist not found');preg_match_all("/'([^']+)'/",$m[1],$names);$actual=$names[1];sort($actual);$wanted=$schema?$keys:$expect['/exports/{id}/data'];sort($wanted);d2_assert($actual===$wanted,'Snapshot parameter drift');
 }
 $v2columns=$spec['components']['schemas']['ObservationV2']['required'];d2_assert($v2columns===EIPSI\Dataset\CanonicalStudyDataset::CONTEXT,'V2 context drift');$v2request=array_keys($spec['components']['schemas']['ExportRequestV2']['properties']);sort($v2request);d2_assert($v2request===array('annexes','contract','filters','format','include_partials'),'V2 request drift');


});
d2_case('Real HTTP success envelopes and private headers produce contract samples',function(){global$p;$k=d2_key(null,array('read:studies','read:data'));foreach(array('/status','/studies','/studies/'.$p['public'],'/studies/'.$p['public'].'/data','/studies/'.$p['public'].'/dictionary')as$path){$r=a4_http($k,$path);d2_assert($r['status']===200&&strpos($r['headers']['cache-control'],'no-store')!==false&&strpos($r['headers']['vary'],'Authorization')!==false&&isset($r['headers']['x-request-id']),'Headers/status drift '.$path.' HTTP '.$r['status']);}
 foreach(array('data','dictionary')as$section){$r=a4_http($k,'/studies/'.$p['public'].'/'.$section,array('contract'=>'EIPSI_STUDY_DATA_V2'));d2_assert($r['status']===200&&$r['data']['dataset_contract']==='EIPSI_STUDY_DATA_V2','V2 HTTP negotiation failed');}

});
d2_case('HTTP authentication permission parameters and absence errors match documented codes',function(){global$p;$all=d2_key(null,array('read:studies','read:data'));$none=d2_key(array(),array('read:studies'),'NONE');$a=a4_http(null,'/status');d2_assert($a['status']===401&&$a['data']['code']==='eipsi_external_unauthorized');$a=a4_http($none,'/studies/'.$p['public'].'/data');d2_assert($a['status']===403);$a=a4_http($none,'/studies/'.$p['public']);d2_assert($a['status']===404);$a=a4_http($all,'/studies',array('unexpected'=>'1'));d2_assert($a['status']===400);$a=a4_http($all,'/studies/'.$p['public'].'/data',array('limit'=>'0'));d2_assert($a['status']===400);$a=a4_http($all,'/studies/'.$p['public'].'/dictionary',array('include_partials'=>'1'));d2_assert($a['status']===400);});
d2_case('HTTP snapshot schemas range errors and immutable manifest match OpenAPI',function(){global$p;$k=d2_key(null,array('read:studies','read:data'));$r=a4_http($k,'/studies/'.$p['public'].'/exports',array(),array('format'=>'json'));d2_assert($r['status']===202);$id=$r['data']['export_id'];$r=a4_http($k,'/exports/'.$id.'/data');d2_assert($r['status']===409);b_ready($k,array('export_id'=>$id));foreach(array('','/manifest','/data','/dictionary')as$tail)d2_assert(a4_http($k,'/exports/'.$id.$tail)['status']===200);$r=a4_http($k,'/exports/'.$id.'/download',array(),null,array('Range'=>'bytes=0-31'));d2_assert($r['status']===206&&strlen($r['body'])===32);$r=a4_http($k,'/exports/'.$id.'/download',array(),null,array('Range'=>'bytes=999999999-'));d2_assert($r['status']===416);$s=new A\Store();$s->rows($s->prepare('UPDATE '.$s->table('exports').' SET expires_at=%d WHERE id=%s',time()-1,$id));d2_assert(a4_http($k,'/exports/'.$id.'/data')['status']===410);
 $v2=a4_http($k,'/studies/'.$p['public'].'/exports',array(),array('contract'=>'EIPSI_STUDY_DATA_V2','format'=>'xlsx','annexes'=>array('data','dictionary','process_metadata')));d2_assert($v2['status']===202);$id=$v2['data']['export_id'];b_ready($k,array('export_id'=>$id));foreach(array('','/manifest','/data','/dictionary')as$tail)d2_assert(a4_http($k,'/exports/'.$id.$tail)['status']===200);$r=a4_http($k,'/exports/'.$id.'/download',array(),null,array('Range'=>'bytes=0-31'));d2_assert($r['status']===206&&strlen($r['body'])===32&&substr($r['body'],0,2)==='PK'&&$r['headers']['content-type']==='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});
d2_case('HTTP rate and disabled failures remain documented and diagnostics-free',function(){
 $k=d2_key();for($i=0;$i<119;$i++)EIPSI_Rate_Limiter::consume('external_valid',$k['key']['id'],120,60,time());$r=a4_http($k,'/status');d2_assert($r['status']===429&&isset($r['headers']['retry-after']));$s=new A\Store();$s->setEnabled(false,1,A\Authenticator::requestId(),time());try{d2_assert(a4_http($k,'/status')['status']===404);}finally{$s->setEnabled(true,1,A\Authenticator::requestId(),time());}
});
d2_case('Integrated synthetic fixture covers historical privacy partial and native data',function(){
 $f=a4_fixture();$ids=array_map(static function($v){return$v['study_id'];},array_intersect_key($f,array_flip(array('transversal','longitudinal','native'))));$k=d2_key($ids,array('read:studies','read:data'));$r=(new A\StudyDataExport())->read($k['principal'],$f['longitudinal']['code']);d2_assert(count($r['items'])===2&&strpos(json_encode($r),'EXCLUDED_DO_NOT_USE')===false&&strpos(json_encode($r),'INCLUDED_RETAINED')!==false);$r=(new A\StudyDataExport())->read($k['principal'],$f['longitudinal']['code'],array('include_partials'=>'true'));d2_assert(count($r['items'])===3&&strpos(json_encode($r),'INCLUDED_PARTIAL_ONLY_IF_TRUE')!==false);$r=a4_http($k,'/studies/'.$f['native']['code'].'/data');d2_assert(count($r['data']['items'])>=7);
 $GLOBALS['a4_fixture']=$f;$GLOBALS['a4_key']=$k;
});
d2_case('Python and curl executable examples run against real disposable HTTP',function(){
 $f=$GLOBALS['a4_fixture'];$k=$GLOBALS['a4_key'];$dir='/tmp/eipsi-api4-example-'.bin2hex(random_bytes(6));mkdir($dir,0700);$env=array_merge(getenv(),array('EIPSI_API_BASE'=>'http://127.0.0.1/?rest_route=/eipsi-external/v1','EIPSI_API_KEY'=>$k['secret']));
 $run=function($args)use($env,$k){$pipes=array();$p=proc_open($args,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,$env);d2_assert(is_resource($p));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($p);d2_assert($exit===0,'Example exit '.(int)$exit.' '.preg_replace('/eipsi_[a-f0-9]{32}_[a-f0-9]{64}/','REDACTED',substr($err,0,300)));d2_assert(strpos($out.$err,$k['secret'])===false);return$out;};
 // curl ships in the pinned WordPress image; Python availability is checked by the runner.
 $out=$run(array('bash',EIPSI_FORMS_PLUGIN_DIR.'examples/curl/eipsi_api.sh','/status'));d2_assert(json_decode($out,true)['authenticated']);
 $python=trim((string)shell_exec('command -v python3'));if($python!==''){$out=$run(array($python,EIPSI_FORMS_PLUGIN_DIR.'examples/python/eipsi_api.py','--study',$f['transversal']['code'],'--output',$dir.'/snapshot.json'));d2_assert(json_decode($out,true)['live_rows']===8);unlink($dir.'/snapshot.json');}else{$GLOBALS['a4_python_unavailable']=true;echo "LIMITATION Python absent from pinned WordPress image; host HTTP runner required\n";}
 rmdir($dir);
});
