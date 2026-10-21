<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Randomization_Override_Service {

public static function eipsi_check_manual_override_db( $randomization_id, $user_fingerprint ) {
    global $wpdb;

    $overrides_table = $wpdb->prefix . 'eipsi_manual_overrides';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $override = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT assigned_form_id, expires_at, status
            FROM {$overrides_table}
            WHERE randomization_id = %s
            AND user_fingerprint = %s
            AND status = 'active'
            LIMIT 1",
            $randomization_id,
            $user_fingerprint
        )
    );

    if ( $override ) {
        // Verificar si NO ha expirado
        if ( ! $override->expires_at || strtotime( $override->expires_at ) > time() ) {
            error_log( "[EIPSI Manual Override] Override encontrado para {$user_fingerprint} → Form {$override->assigned_form_id}" );
            return intval( $override->assigned_form_id );
        } else {
            error_log( "[EIPSI Manual Override] Override expirado para {$user_fingerprint}" );
            // Marcar como expired (background task)
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->update(
                $overrides_table,
                array( 'status' => 'expired' ),
                array(
                    'randomization_id' => $randomization_id,
                    'user_fingerprint'   => $user_fingerprint
                ),
                array( '%s' ),
                array( '%s', '%s' )
            );
        }
    }

    return null;
}
public static function save($randomization_id, $user_fingerprint, $assigned_form_id, $reason, $current_user_id, $expires_at) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'eipsi_manual_overrides';
    $key = 'eipsi-rct-' . md5($wpdb->prefix . ':' . $randomization_id . ':' . $user_fingerprint);
    if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $key)) !== 1) { return false; }
    try {
        $query = "
            INSERT INTO {$table_name}
                (randomization_id, user_fingerprint, assigned_form_id, reason, created_by, status, expires_at)
            VALUES (%s, %s, %d, %s, %d, 'active', %s)
            ON DUPLICATE KEY UPDATE
                assigned_form_id = VALUES(assigned_form_id),
                reason = VALUES(reason),
                status = 'active',
                expires_at = VALUES(expires_at),
                updated_at = NOW()
        ";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $params = array($randomization_id, $user_fingerprint, $assigned_form_id, $reason, $current_user_id);
        if ($expires_at === null) { $query = str_replace("'active', %s)", "'active', NULL)", $query); }
        else { $params[] = $expires_at; }
        $result = $wpdb->query($wpdb->prepare($query, $params));

return $result;
 } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key)); }
}

}
