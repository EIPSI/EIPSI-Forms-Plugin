<?php
/** Opt-in composition; no legacy Wave authority or transport side effects. */
require_once __DIR__.'/content.php';
foreach(array('Configuration','Identity','Capability','EmailConfirmation','NotificationScheduler','EmailTemplate','Runtime','Worker') as $module)require_once __DIR__.'/longitudinal/'.$module.'.php';
unset($module);
