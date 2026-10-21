<?php
// Read-only executable inventory; M0 enforces disposable database and intercepts mail.
ob_start();
require __DIR__.'/../m0/inventory.php';
$inventory=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
$inventory['missing_asset_dependencies']=array();
$inventory['missing_enqueued_assets']=array();
foreach(array('script'=>wp_scripts(),'style'=>wp_styles()) as $kind=>$registry){
 foreach($inventory['assets'] as $asset){if($asset['kind']!==$kind)continue;foreach($asset['deps'] as $dep){if(!isset($registry->registered[$dep]))$inventory['missing_asset_dependencies'][]=array($kind,$asset['handle'],$dep);}}
 foreach($registry->queue as $handle){if(strpos($handle,'eipsi')===0&&!isset($registry->registered[$handle]))$inventory['missing_enqueued_assets'][]=array($kind,$handle);}
}
echo wp_json_encode($inventory,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
