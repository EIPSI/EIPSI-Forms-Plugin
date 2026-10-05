<?php
/** M3 owner; external contracts remain behind their existing facades. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Form_Response {
    public static function success($data, $status = 200) { return array('success'=>true, 'data'=>$data, 'status'=>$status); }
    public static function error($data, $status = 200) { return array('success'=>false, 'data'=>$data, 'status'=>$status); }
    public static function emit($result) {
        if ($result['success']) { wp_send_json_success($result['data'], $result['status']); }
        else { wp_send_json_error($result['data'], $result['status']); }
    }
}
