<?php
if (!defined('ABSPATH')) { exit; }
/** Confirmed writes and independent verification; legacy result keys are preserved. */
class EIPSI_Storage_Result {
 public static function normalize($result) {
  $destination=$result['storage']??null;
  $id=$result['insert_id']??($result['emergency_id']??null);
  $confirmed=!empty($result['success']) && (int)$id>0 && in_array($destination,array('wordpress_db','external_db','emergency_table_wp','emergency_table_external'),true);
  $supported=$destination==='wordpress_db';
  $verified=$confirmed && $supported ? EIPSI_Submission_Verification_Service::eipsi_safety_verify_submission($id,$destination,array()) : false;
  $result['success']=$confirmed;
  $result['destination']=$destination;$result['submission_id']=$confirmed?$id:null;
  $result['insert_confirmed']=$confirmed;$result['verified']=$verified;
  $result['verification_supported']=$supported;
  $result['verification_status']=$supported?($verified?'verified':'not_verified'):'unsupported';
  $result['fallback_used']=!empty($result['fallback_used']);
  if(!array_key_exists('error',$result)){$result['error']=$confirmed?null:'No confirmed write';}
  $result['source']=in_array($destination,array('external_db','emergency_table_external'),true)?'external_db':($destination?'wordpress_db':null);
  return $result;
 }
}
