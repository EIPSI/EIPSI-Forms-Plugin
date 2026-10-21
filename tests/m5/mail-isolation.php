<?php
if (defined('DB_HOST') && DB_HOST==='eipsi-m0-db:3306' && DB_NAME==='m0' && get_option('eipsi_m0_isolated_install')) {add_filter('pre_wp_mail','__return_true',PHP_INT_MAX);}
