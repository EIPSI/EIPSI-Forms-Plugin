<?php
use EIPSI\ExternalApi as A;
a1_case('More than 500 logical Studies paginate without truncation N+1 or unbounded PHP memory',function(){
 global$wpdb;$studies=array();for($i=0;$i<525;$i++)$studies[]=a1_new('SCALE_'.$i);
 $k=a1_key(array_column($studies,'id'));usort($studies,static function($x,$y){return strcmp($x['id'],$y['id']);});$expected=array_column($studies,'code');$seen=array();$cursor=null;$maxQueries=0;$maxBytes=0;$start=microtime(true);
 do{
  $params=array('limit'=>200);if($cursor!==null)$params['cursor']=$cursor;$queries=$wpdb->num_queries;$mem=memory_get_usage();if(function_exists('memory_reset_peak_usage'))memory_reset_peak_usage();$page=a1_page($k,$params);$maxBytes=max($maxBytes,memory_get_peak_usage()-$mem);$maxQueries=max($maxQueries,$wpdb->num_queries-$queries);$seen=array_merge($seen,a1_codes($page));$cursor=$page['next_cursor'];
 }while($cursor!==null);
 a1_assert($seen===$expected&&count(array_unique($seen))===525,'Lost or duplicated logical Studies');a1_assert($maxQueries<=25,'N+1 query regression');a1_assert($maxBytes<8*1024*1024,'Unbounded response memory');
 echo 'METRIC '.json_encode(array('studies'=>525,'page_limit'=>200,'pages'=>3,'max_queries_per_page'=>$maxQueries,'max_incremental_peak_bytes'=>$maxBytes,'elapsed_ms'=>round((microtime(true)-$start)*1000,3)))."\n";
});
a1_case('Insertion and grant expansion during a keyset walk never duplicate previous rows',function()use($keys){
 $items=array();foreach(array('A','B','C')as$tag)$items[]=a1_new('WALK_'.$tag,'STUDY_API1_BOUND_'.$tag);$k=a1_key(array_column($items,'id'));$first=a1_page($k,array('limit'=>1));$extra=a1_new('WALK_BB','STUDY_API1_BOUND_BB');$items[]=$extra;
 $keys->update($k['key']['id'],1,array('name'=>'Expanded walk','scopes'=>array('read:studies'),'grant_mode'=>'SELECTED','studies'=>array_column($items,'id'),'expires_at'=>null),1,A\Authenticator::requestId());
 $seen=a1_codes($first);$cursor=$first['next_cursor'];do{$page=a1_page($k,array('limit'=>1,'cursor'=>$cursor));$seen=array_merge($seen,a1_codes($page));$cursor=$page['next_cursor'];}while($cursor!==null);
 a1_assert(count($seen)===4&&count(array_unique($seen))===4,'Cursor shifted or duplicated on insertion');
});
