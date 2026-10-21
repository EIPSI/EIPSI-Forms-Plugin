<?php
/** M1 bootstrap: preserve existing contracts and registration order. */
if (!defined('ABSPATH')) { exit; }

final class EIPSI_Bootstrap {
    private static $registered = false;
    public static function register() {
        if (self::$registered) { return; }
        self::$registered = true;
        EIPSI_Hook_Registry::register();
    }
}
