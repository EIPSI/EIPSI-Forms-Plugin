<?php
/** Explicit Study Engine adapters for existing privacy/export owners. */
require_once __DIR__.'/longitudinal.php';
foreach(array('AccessContext','Policy','Coverage','Withdrawal','CleanupAdapter','ExportAdapter') as $module)require_once __DIR__.'/privacy/'.$module.'.php';
unset($module);
