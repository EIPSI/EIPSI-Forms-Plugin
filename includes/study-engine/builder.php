<?php
/** Admin authoring opt-in; no hooks, participants, Storage or mail side effects. */
require_once __DIR__.'/longitudinal.php';
require_once __DIR__.'/management.php';
foreach(array('Draft','Preview','Experience','Assessment','Service','EmailLibrary','Admin') as $module)require_once __DIR__.'/builder/'.$module.'.php';
unset($module);
