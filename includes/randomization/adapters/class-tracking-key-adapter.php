<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Randomization_Tracking_Key_Adapter {

public static function eipsi_get_user_fingerprint() {
    // 1. Desde POST (enviado por el JS eipsi-fingerprint.js)
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if ( isset( $_POST['eipsi_user_fingerprint'] ) ) {
        $fingerprint = sanitize_text_field( wp_unslash( $_POST['eipsi_user_fingerprint'] ) );
        if ( strpos( $fingerprint, 'fp_' ) === 0 ) {
            return $fingerprint;
        }
    }

    // 2. Desde cookie (si el usuario ya visitó antes)
    if ( isset( $_COOKIE['eipsi_fingerprint'] ) ) {
        $fingerprint = sanitize_text_field( wp_unslash( $_COOKIE['eipsi_fingerprint'] ) );
        if ( strpos( $fingerprint, 'fp_' ) === 0 ) {
            return $fingerprint;
        }
    }

    // 3. Email desde URL param (para asignaciones manuales)
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( isset( $_GET['email'] ) && is_email( $_GET['email'] ) ) {
        return 'email_' . md5( sanitize_email( wp_unslash( $_GET['email'] ) ) );
    }

    // 4. Fallback: generar fingerprint en servidor (menos confiable)
    return eipsi_generate_server_fingerprint();
}

public static function eipsi_generate_server_fingerprint() {
    $components = array();

    // User Agent
    if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
        $components[] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
    }

    // IP Address
    $components[] = eipsi_get_client_ip();

    // Accept Language
    if ( isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
        $components[] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) );
    }

    // Accept Encoding
    if ( isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) {
        $components[] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_ENCODING'] ) );
    }

    $combined     = implode( '|', $components );
    $hash         = hash( 'sha256', $combined );
    $fingerprint  = 'fp_server_' . substr( $hash, 0, 24 );

    error_log( '[EIPSI RCT] Fingerprint generado en servidor (fallback): ' . $fingerprint );

    return $fingerprint;
}
}
