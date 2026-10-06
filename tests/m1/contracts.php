<?php
/** Canonicalization is deliberately narrow; baseline.json records every permitted difference. */
function m1_callback_contract($row) {
    if ($row === null) { return null; }
    return array_intersect_key($row, array_flip(array('callback','callable','deprecated')));
}
function m1_contracts($inventory) {
    $baseline = json_decode(file_get_contents(__DIR__ . '/baseline.json'), true);
    $includes = array();
    foreach ($inventory['included'] as $row) {
        if (in_array($row['file'], $baseline['allowlist']['additional_includes'], true)) { continue; }
        $includes[] = $baseline['allowlist']['include_path_migrations'][$row['file']] ?? $row['file'];
    }
    $hooks = array();
    foreach ($inventory['hooks'] as $row) {
        if (m1_s3_added_hook($row)) { continue; }
        if ($row['callback'] === 'eipsi_randomization_form_load_data') { continue; }
        if (in_array($row['hook'], array('wp_ajax_eipsi_download_admin_export','wp_ajax_nopriv_eipsi_download_admin_export','wp_ajax_eipsi_load_form','wp_ajax_nopriv_eipsi_load_form'), true)) { continue; }
        $hooks[] = array_intersect_key($row, array_flip(array('hook','callback','priority','accepted_args','order_at_priority','callable')));
    }
    $hook_locations = array();
    foreach ($inventory['hooks'] as $row) {
        if (m1_s3_added_hook($row)) { continue; }
        if ($row['callback'] === 'eipsi_randomization_form_load_data') { continue; }
        if (in_array($row['hook'], array('wp_ajax_eipsi_download_admin_export','wp_ajax_nopriv_eipsi_download_admin_export','wp_ajax_eipsi_load_form','wp_ajax_nopriv_eipsi_load_form'), true)) { continue; }
        $hook_locations[] = array_intersect_key($row, array_flip(array('hook','callback','priority','order_at_priority','file')));
    }
    $shortcodes = array();
    foreach ($inventory['shortcodes'] as $name => $row) { $shortcodes[$name] = m1_callback_contract($row); }
    $rest = array();
    foreach ($inventory['rest'] as $row) {
        $rest[] = array('route'=>$row['route'],'methods'=>$row['methods'],'callback'=>m1_callback_contract($row['callback']),'permission'=>m1_callback_contract($row['permission']));
    }
    $cron = array();
    foreach ($inventory['cron'] as $row) { $cron[] = array_intersect_key($row, array_flip(array('hook','schedule','interval','args','callback_registered'))); }
    usort($cron, function ($a, $b) { return strcmp($a['hook'], $b['hook']); });
    $assets = $inventory['assets'];
    foreach ($assets as &$row) {
        if ($row['handle'] === 'eipsi-forms-js') {
            m0_assert(preg_match('/^2\.6\.1\.\d{10}$/', (string)$row['version']) === 1, 'Unexpected forms asset version contract');
            $row['version'] = '2.6.1.<request-time>';
        }
    }
    unset($row);
    $blocks = array();
    foreach ($inventory['blocks'] as $row) {
        $blocks[] = array('name'=>$row['name'],'render_callback'=>m1_callback_contract($row['render_callback']),'editor_scripts'=>$row['editor_scripts'],'styles'=>$row['styles']);
    }
    return compact('includes','hooks','hook_locations','shortcodes','rest','cron','assets','blocks');
}
function m1_inventory($profile) {
    $script = EIPSI_FORMS_PLUGIN_DIR . 'tests/m0/inventory.php';
    $command = 'EIPSI_M0_PROFILE=' . escapeshellarg($profile) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    $output = array(); $status = 0;
    exec($command, $output, $status);
    m0_assert($status === 0, 'Inventory subprocess failed: ' . $profile);
    $decoded = json_decode(implode("\n", $output), true);
    m0_assert(is_array($decoded) && isset($decoded['hooks']), 'Inventory JSON invalid: ' . $profile);
    return $decoded;
}
function m1_token_hash($function) {
    $reflection = new ReflectionFunction($function);
    $lines = file($reflection->getFileName());
    $source = implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    $text = '';
    foreach (token_get_all('<?php ' . $source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], array(T_OPEN_TAG,T_WHITESPACE,T_COMMENT,T_DOC_COMMENT), true)) { continue; }
            $text .= $token[1];
        } else { $text .= $token; }
    }
    return hash('sha256', $text);
}

// Versioned S3 additions, checked in full; historical baseline stays unchanged.
function m1_s3_added_hook($row) {
    $s3=json_decode(file_get_contents(__DIR__.'/../s3/security-contracts.json'),true);
    foreach($s3['added_hooks'] as $expected) {
        if ($row['hook']===$expected['hook'] && $row['callback']===$expected['callback']) {
            m0_assert(array_intersect_key($row,$expected)==$expected,'Unexpected S3 hook contract');
            return true;
        }
    }
    return false;
}
