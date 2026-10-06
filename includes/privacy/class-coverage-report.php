<?php
if (!defined('ABSPATH')) { exit; }
class EIPSI_Privacy_Coverage_Report {
 public static function for_operation($operation) {
  $not_covered=$operation==='personal_export'?array('external_db','unlinked_browser_records','emergency_submissions','historical_export_files'):array('external_db','unlinked_browser_records','historical_export_files','unclassified_free_text');
  return array('source'=>'wordpress_db','complete'=>false,'not_covered'=>$not_covered,
   'excluded'=>array_values(array_unique(array_merge($not_covered,array('backups','server_logs','delivered_email','unreliably_linked_records','identifying_free_text')))));
 }
}
