<?php
require __DIR__.'/../m0/bootstrap.php';
$plugin='EIPSI-Forms-Plugin/eipsi-forms.php';
deactivate_plugins($plugin);m0_assert(!is_plugin_active($plugin),'Deactivation failed');
$r=activate_plugin($plugin);m0_assert(!is_wp_error($r)&&is_plugin_active($plugin),'Reactivation failed');
m0_assert(is_callable('eipsi_rest_pool_assign')&&is_callable('eipsi_load_form_handler'),'Adapters missing');
echo "M7 deactivate/reactivate OK; adapters callable\n";ob_end_flush();
