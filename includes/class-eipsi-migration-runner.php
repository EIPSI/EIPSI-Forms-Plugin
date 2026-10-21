<?php
/** M8 definition owner; historical public contracts remain facades. */
if (!defined('ABSPATH')) { exit; }

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/schema/bootstrap.php';
class EIPSI_Migration_Runner extends EIPSI_Schema_Migration_Runner {}
