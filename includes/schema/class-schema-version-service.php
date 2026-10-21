<?php
if (!defined('ABSPATH')) { exit; }
class EIPSI_Schema_Version_Service {
    const REVISION_OPTION = 'eipsi_schema_revision';
    public static function revision() { return hash('sha256', wp_json_encode(EIPSI_Schema_Registry::get_schema_map())); }
    public static function record_verified() {
        update_option(self::REVISION_OPTION, self::revision());
        // Compatibility display marker; explicitly NOT a schema or migration version.
        update_option('eipsi_db_schema_version', EIPSI_FORMS_VERSION);
        update_option('eipsi_schema_last_verified', current_time('mysql'));
    }
}
