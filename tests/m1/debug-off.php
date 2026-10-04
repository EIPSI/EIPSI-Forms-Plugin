<?php
require dirname(__DIR__) . '/m0/bootstrap.php';
$failed=0;
foreach (array(
    'WP_DEBUG false no carga testing-helpers'=>function(){m0_assert(!WP_DEBUG,'Expected WORDPRESS_DEBUG=0');m0_assert(!in_array(realpath(EIPSI_FORMS_PLUGIN_DIR.'admin/testing-helpers.php'),get_included_files(),true),'Debug helpers leaked');},
    'Producción mantiene AJAX, REST, bloques y capacidades'=>function(){m0_assert(has_action('wp_ajax_eipsi_forms_submit_form')!==false,'Submit missing');m0_assert(isset(rest_get_server()->get_routes()['/eipsi/v1/pool-assign']),'REST missing');m0_assert(WP_Block_Type_Registry::get_instance()->is_registered('eipsi/form-container'),'Block missing');m0_assert(eipsi_get_default_menu_capabilities()['results']==='manage_options','Capability changed');}
) as $name=>$test) {try{$test();echo 'PASS '.$name."\n";}catch(Throwable $error){$failed++;echo 'FAIL '.$name.': '.$error->getMessage()."\n";}}
echo '2 tests, '.$failed." failures\n";ob_end_flush();exit($failed?1:0);
