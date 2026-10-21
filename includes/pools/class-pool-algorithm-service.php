<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Pool_Algorithm_Service {

public static function eipsi_weighted_random_select($studies, $participant_id, $method = 'seeded', $seed = '') {
    if (empty($studies)) {
        return false;
    }

    // If only one study, return it
    if (count($studies) === 1) {
        return $studies[0];
    }

    // For seeded method, use participant_id + seed to generate deterministic assignment
    if ($method === 'seeded' && !empty($seed)) {
        $hash = md5($participant_id . $seed);
        $random_value = hexdec(substr($hash, 0, 8)) / 0xFFFFFFFF;
    } else {
        // Pure random
        $random_value = mt_rand() / mt_getrandmax();
    }

    // Weighted random selection
    $cumulative = 0;
    $total_probability = array_sum(array_column($studies, 'probability'));

    foreach ($studies as $study) {
        $probability = isset($study['probability']) ? floatval($study['probability']) : 0;
        $cumulative += $probability / $total_probability;

        if ($random_value <= $cumulative) {
            return $study;
        }
    }

    // Fallback to last study
    return end($studies);
}
public static function weighted_random_assign( $pool_id, $participant_id, $method, $studies ) {
        if ( empty( $studies ) ) {
            return false;
        }

        if ( count( $studies ) === 1 ) {
            return $studies[0];
        }

        // Generar número aleatorio según método
        if ( $method === 'seeded' ) {
            // Seeded: usar hash determinístico
            $seed = crc32( $pool_id . '_' . $participant_id );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_srand
            srand( $seed );
            $rand = mt_rand( 0, 10000 ) / 100; // 0.00 a 100.00
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_srand
            srand();
        } else {
            // Pure-random: usar random_int criptográfico
            $rand = random_int( 0, 10000 ) / 100;
        }

        // Recorrer estudios acumulando probabilidades
        $cumulative = 0;
        foreach ( $studies as $study ) {
            $probability = isset( $study['probability'] ) ? floatval( $study['probability'] ) : 0;
            $cumulative += $probability;
            if ( $rand <= $cumulative ) {
                return $study;
            }
        }

        // Fallback al último estudio
        return end( $studies );
    }

}
