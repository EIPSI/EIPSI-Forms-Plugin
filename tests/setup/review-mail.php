<?php
if(getenv('WORDPRESS_DB_NAME')!=='m0'||!get_option('eipsi_setup_review'))return;
add_filter('pre_wp_mail',static function($pre,$args){$path='/tmp/eipsi-setup-review-mail.json';$mail=is_file($path)?json_decode(file_get_contents($path),true):array();if(!is_array($mail))$mail=array();$mail[]=array('subject'=>$args['subject'],'message'=>$args['message']);file_put_contents($path,json_encode(array_slice($mail,-20)));chmod($path,0600);return true;},PHP_INT_MAX,2);
