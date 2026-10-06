<?php
if(PHP_SAPI!=='cli'){exit;}
define('ABSPATH','/var/www/html/');define('EIPSI_FORMS_PLUGIN_DIR',dirname(__DIR__,2).'/');
function wp_mkdir_p($path){return is_dir($path)||mkdir($path,0755,true);}
require EIPSI_FORMS_PLUGIN_DIR.'includes/export/class-export-file-service.php';
$dir=$argv[1];$slot=$argv[2];if(!preg_match('#^/tmp/eipsi-m6-files-[a-zA-Z0-9]+$#',$dir)||!in_array($slot,array('0','1'),true)){exit(2);}
touch($dir.'/ready'.$slot);$deadline=microtime(true)+15;while(!file_exists($dir.'/start')){if(microtime(true)>$deadline){exit(3);}usleep(10000);}
try{$f=EIPSI_Export_File_Service::reserve_filename('m6-concurrent.csv');file_put_contents(EIPSI_FORMS_PLUGIN_DIR.'exports/'.$f,'slot'.$slot);file_put_contents($dir.'/result'.$slot,json_encode(array('filename'=>$f)));}catch(Throwable$e){file_put_contents($dir.'/result'.$slot,json_encode(array('error'=>$e->getMessage())));}
