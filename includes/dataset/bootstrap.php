<?php
if(!defined('ABSPATH'))exit;
foreach(array('CanonicalStudyDataset','Source','Transport','SnapshotV2','EffectiveMetadataPolicy')as$file)require_once __DIR__.'/'.$file.'.php';unset($file);
