<?php
/** Notifications owner; public WordPress adapters retain existing contracts. */
if (!defined('ABSPATH')) { exit; }

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/email/class-email-message-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/email/class-email-delivery-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/email/class-email-log-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/email/class-email-template-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/email/class-wave-availability-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/nudges/class-nudge-policy-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/nudges/class-nudge-queue-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/nudges/class-nudge-worker-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/nudges/class-nudge-cache-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/nudges/class-nudge-schedule-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/reminders/class-legacy-reminder-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/reminders/class-reminder-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/reminders/class-dropout-recovery-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/reminders/class-weekly-t1-reminder-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/adapters/class-notification-cron-adapters.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/notifications/adapters/class-longitudinal-notification-adapter.php';
