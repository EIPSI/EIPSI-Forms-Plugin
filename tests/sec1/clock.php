<?php
/** Loaded as an MU plugin only inside the guarded disposable fixture. */
if (getenv('WORDPRESS_DB_NAME')!=='m0'||!get_option('eipsi_m0_isolated_install'))return;
add_filter('eipsi_runtime_timestamp',static function($real){$path=ABSPATH.'wp-content/sec1-test-clock';$value=is_file($path)?file_get_contents($path):'';return ctype_digit($value)?(int)$value:$real;});
