<?php
/** M1 bootstrap: preserve existing contracts and registration order. */
if (!defined('ABSPATH')) { exit; }

function eipsi_forms_activate() { EIPSI_Lifecycle::activate(); }
function eipsi_forms_deactivate() { EIPSI_Lifecycle::deactivate(); }
