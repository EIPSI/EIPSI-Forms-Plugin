<?php
if (!defined('ABSPATH')) { exit; }
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/schema/class-schema-registry.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/schema/class-schema-lock.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/schema/class-schema-version-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/schema/class-schema-inspector.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/schema/class-schema-repair-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/schema/class-schema-installer.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/storage/external/class-external-schema-adapter.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/migrations/class-migration-runner.php';
