<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Randomization_Submission_Algorithm_Service {

public static function eipsi_calculate_submission_assignment( $user_fingerprint, $form_id, $timestamp ) {
    // Get all RCT configs from post meta
    $configs = eipsi_get_randomization_configs_from_post_meta();
    
    if ( empty( $configs ) ) {
        return null;
    }
    
    // Find RCT config associated with this form
    $rct_config = null;
    $config_id = null;
    
    foreach ( $configs as $config ) {
        $formularios = $config['formularios'] ?? array();
        foreach ( $formularios as $form ) {
            $form_post_id = isset( $form['id'] ) ? intval( $form['id'] ) : 0;
            if ( $form_post_id === intval( $form_id ) ) {
                $rct_config = $config;
                $config_id = $config['randomization_id'];
                break 2;
            }
        }
    }
    
    // No RCT config found for this form
    if ( ! $rct_config || empty( $config_id ) ) {
        return null;
    }
    
    // Get formularios and probabilidades
    $formularios = $rct_config['formularios'] ?? array();
    $probabilidades = $rct_config['probabilidades'] ?? array();
    $method = $rct_config['method'] ?? 'seeded';
    $manual_assignments = $rct_config['manualAssignments'] ?? array();
    
    if ( empty( $formularios ) ) {
        return null;
    }
    
    // Check for manual assignment first
    if ( ! empty( $manual_assignments ) && is_array( $manual_assignments ) ) {
        // Manual assignments keyed by some identifier (email, etc)
        // For now, we'll skip manual assignment at submission time
        // as it requires more frontend context
    }
    
    // Build weighted list for randomized assignment
    $weighted_forms = array();
    
    foreach ( $formularios as $index => $form ) {
        $form_post_id = isset( $form['id'] ) ? intval( $form['id'] ) : 0;
        if ( $form_post_id <= 0 ) {
            continue;
        }
        
        // Get probability for this form
        $weight = 1; // Default equal weight
        
        if ( isset( $probabilidades[ $form_post_id ] ) ) {
            $weight = floatval( $probabilidades[ $form_post_id ] );
        } elseif ( isset( $probabilidades[ $index ] ) ) {
            $weight = floatval( $probabilidades[ $index ] );
        }
        
        // Add form to weighted list (repeat based on weight)
        // We use 100 as base to handle percentages
        $weight_int = max( 1, round( $weight ) );
        for ( $i = 0; $i < $weight_int; $i++ ) {
            $weighted_forms[] = array(
                'id' => $form_post_id,
                'title' => get_the_title( $form_post_id ) ?? 'Form ' . $form_post_id,
                'weight' => $weight
            );
        }
    }
    
    if ( empty( $weighted_forms ) ) {
        return null;
    }
    
    // Generate seeded random index
    // Seed: fingerprint + timestamp + config_id (for uniqueness)
    $seed = $user_fingerprint . $config_id . $timestamp;
    $hash = crc32( $seed );
    
    // Ensure positive value for modulo
    $hash = abs( $hash );
    $index = $hash % count( $weighted_forms );
    
    $selected = $weighted_forms[ $index ];
    
    return array(
        'randomization_id' => $config_id,
        'assigned_variant' => $selected['title'],
        'assigned_form_id' => $selected['id'],
        'method' => $method,
        'seed' => $seed,
        'formularios' => $formularios,
        'probabilidades' => $probabilidades
    );
}
}
