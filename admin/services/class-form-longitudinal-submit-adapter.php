<?php
/** M3 owner; external contracts remain behind their existing facades. */
if (!defined('ABSPATH')) { exit; }

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/bootstrap.php';

/** Legacy M4 boundary: original transaction/T1/next-wave/notification calls, not Forms rules. */
class EIPSI_Form_Longitudinal_Submit_Adapter {
    public static function after_persistence($wave_id, $study_id, $longitudinal_participant_id, $stable_form_id, $submitted_at, $user_data) {
    return EIPSI_Longitudinal_Submission_Service::handle_submission(array(
        'wave_id'=>$wave_id,
        'study_id'=>$study_id,
        'longitudinal_participant_id'=>$longitudinal_participant_id,
        'stable_form_id'=>$stable_form_id,
        'submitted_at'=>$submitted_at,
        'user_data'=>$user_data
    ));
}
}
