<?php
if(getenv('WORDPRESS_DB_NAME')!=='m0'||!get_option('eipsi_m0_isolated_install'))return;
if(!defined('EIPSI_STUDY_ENGINE_KEYRING'))define('EIPSI_STUDY_ENGINE_KEYRING',array('current'=>'RBAC_TEST','keys'=>array('RBAC_TEST'=>str_repeat('6b',32))));
if(!defined('EIPSI_STUDY_ENGINE_ACCESS_KEYRING'))define('EIPSI_STUDY_ENGINE_ACCESS_KEYRING',array('current'=>'RBAC_ACCESS_TEST','keys'=>array('RBAC_ACCESS_TEST'=>str_repeat('61',32))));
