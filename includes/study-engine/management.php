<?php
require_once __DIR__.'/persistence.php';
foreach(array('Schema','Audit','ScientificReadQuery','Query','ScientificDatasetOwner','Exports','Admin') as $module)require_once __DIR__.'/management/'.$module.'.php';
unset($module);

require_once dirname(__DIR__).'/dataset/CanonicalStudyDataset.php';
require_once dirname(__DIR__).'/dataset/Source.php';
require_once dirname(__DIR__).'/dataset/Transport.php';
require_once dirname(__DIR__).'/dataset/SnapshotV2.php';
