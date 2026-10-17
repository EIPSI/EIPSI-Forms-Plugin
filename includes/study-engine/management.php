<?php
require_once __DIR__.'/persistence.php';
foreach(array('Schema','Audit','Query','Exports','Admin') as $module)require_once __DIR__.'/management/'.$module.'.php';
unset($module);
