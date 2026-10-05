<?php
/** M3 owner; external contracts remain behind their existing facades. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Form_Storage_Adapter {
    public static function validate($data) { return eipsi_safety_validate_submission($data); }
    public static function save($data, $attempts = 3) { return eipsi_safety_save_with_retry($data, $attempts); }
    public static function verify($id, $destination, $data) { return eipsi_safety_verify_submission($id, $destination, $data); }
}
