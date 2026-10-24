<?php
/** Disposable review users only; never run against a normal WordPress database. */
define('DOING_CRON',true);require dirname(__DIR__).'/m0/bootstrap.php';
if(get_option('eipsi_rbac_review_users'))throw new RuntimeException('Review users already exist; use saved private credentials or recreate this fixture.');
$base=getenv('EIPSI_RBAC_REVIEW_URL');if($base!=='http://localhost:18225')throw new RuntimeException('Unexpected review address');
update_option('home',$base);update_option('siteurl',$base);wp_set_current_user(1);
$roles=array('administrator','eipsi_administrator','eipsi_researcher','eipsi_data_manager','eipsi_viewer','subscriber');$users=array();
foreach($roles as$role){$login='review_'.$role;if(username_exists($login))throw new RuntimeException('Review user collision');$password=wp_generate_password(24,false);$id=wp_insert_user(array('user_login'=>$login,'user_pass'=>$password,'role'=>$role,'display_name'=>'Review '.$role));if(is_wp_error($id))throw new RuntimeException($id->get_error_code());$users[]=array('role'=>$role,'username'=>$login,'password'=>$password,'user_id'=>$id);}
update_option('eipsi_rbac_review_users',array_column($users,'user_id'),false);
echo wp_json_encode(array('url'=>$base.'/wp-admin/','users'=>$users,'human_review'=>'PENDING','mail'=>'blocked in fixture'),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";ob_end_flush();
