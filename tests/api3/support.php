<?php
use EIPSI\ExternalApi as A;
function b_create($k,$p=null,$format='json',$partials=false){$api=new A\DatasetSnapshots();return $api->create($k['principal'],($p??$GLOBALS['p'])['public'],array('format'=>$format,'include_partials'=>$partials));}
function b_ready($k,$r){$api=new A\DatasetSnapshots();$storage=new A\ExportStorage();for($i=0;$i<1000;$i++){$status=$storage->get($r['export_id']);if($status['state']!=='PREPARING'){$status=$api->status($k['principal'],$r['export_id']);d2_assert($status['status']==='READY','Export '.$status['status'].' '.($status['error_code']??''));return$status;}$api->work($r['export_id']);}throw new RuntimeException('Worker stalled');}
function b_content($k,$r,$range=null){$d=(new A\DatasetSnapshots())->download($k['principal'],$r['export_id'],$range);try{return fread($d->stream,$d->length?:1);}finally{$d->close();}}
function b_data($k,$r,$params=array()){return(new A\DatasetSnapshots())->data($k['principal'],$r['export_id'],$params);}
function b_case($name,$fn){d2_case($name,$fn);}
