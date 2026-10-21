<?php
if (!defined('ABSPATH')) { exit; }
foreach (array('Csv','Log','RateLimiter','Http','Maintenance') as $file) { require_once __DIR__.'/'.$file.'.php'; } unset($file);
// Reuse the existing five-minute worker and its lifecycle owner; no new job system.
add_action('init', array('EIPSI_Security_Maintenance','register'), 20);
