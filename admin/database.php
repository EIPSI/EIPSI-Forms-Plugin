<?php
/** M6 owner; existing public adapters retain their contracts. */
if (!defined('ABSPATH')) { exit; }

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/storage/class-external-submission-store.php';
class EIPSI_External_Database extends EIPSI_Storage_External_Submission_Store {}
