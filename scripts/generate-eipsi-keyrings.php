<?php
/** Manual CLI-only generator. Does not load WordPress or change any server setting. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if($argc!==2||!is_string($argv[1])||$argv[1]===''){fwrite(STDERR,"Usage: php generate-eipsi-keyrings.php /private/directory/eipsi-server-keyrings.php\n");exit(1);}
$path=$argv[1];$previous=umask(0077);$file=null;
try{
    $seed=bin2hex(random_bytes(32));$access=bin2hex(random_bytes(32));
    $contents="<?php\n// Private server secrets. Keep outside the web root and version control.\n";
    $contents.="define('EIPSI_STUDY_ENGINE_KEYRING', ".var_export(array('current'=>'SEED_1','keys'=>array('SEED_1'=>$seed)),true).");\n";
    $contents.="define('EIPSI_STUDY_ENGINE_ACCESS_KEYRING', ".var_export(array('current'=>'ACCESS_1','keys'=>array('ACCESS_1'=>$access)),true).");\n";
    $file=@fopen($path,'x');if($file===false)throw new RuntimeException('Cannot create a new private output file. Existing files are never overwritten.');
    if(!chmod($path,0600)||fwrite($file,$contents)!==strlen($contents))throw new RuntimeException('Cannot securely write the private output file.');
    fclose($file);$file=null;echo "Two independent keyrings created in the requested private file. No server configuration changed.\n";
}catch(Throwable $error){if(is_resource($file)){fclose($file);@unlink($path);}fwrite(STDERR,"Keyring generation failed; check the private destination and permissions.\n");exit(1);}finally{umask($previous);}
