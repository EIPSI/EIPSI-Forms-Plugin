<?php
/** M6 owner; existing public adapters retain their contracts. */
if (!defined('ABSPATH')) { exit; }

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/storage/class-device-data-store.php';
class EIPSI_Device_Data_Service extends EIPSI_Storage_Device_Data_Store {}
