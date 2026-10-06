<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/bootstrap.php';
class EIPSI_Pool_Dashboard_Service extends EIPSI_Pool_Dashboard_Query_Service {}
