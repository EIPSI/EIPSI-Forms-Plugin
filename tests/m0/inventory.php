<?php
require __DIR__ . '/bootstrap.php';
$root = realpath(EIPSI_FORMS_PLUGIN_DIR) . '/';
function m0_callback($callback) {
    try {
        if (is_array($callback)) { $reflection = new ReflectionMethod($callback[0], $callback[1]); $name = (is_object($callback[0]) ? get_class($callback[0]) : $callback[0]) . '::' . $callback[1]; }
        elseif ($callback instanceof Closure) { $reflection = new ReflectionFunction($callback); $name = '{closure}'; }
        elseif (is_string($callback) && strpos($callback, '::') !== false) { $reflection = new ReflectionMethod($callback); $name = $callback; }
        else { $reflection = new ReflectionFunction($callback); $name = $callback; }
        return array('callback' => $name, 'file' => $reflection->getFileName(), 'line' => $reflection->getStartLine(), 'end_line' => $reflection->getEndLine(), 'callable' => is_callable($callback), 'deprecated' => strpos((string)$reflection->getDocComment(), '@deprecated') !== false);
    } catch (Throwable $error) { return array('callback' => is_string($callback) ? $callback : 'unresolved', 'file' => null, 'line' => null, 'callable' => false); }
}
// Capture declarations with PHP tokens, excluding comments and generated/dependency files.
$functions = array('add_menu_page','add_submenu_page','register_activation_hook','register_deactivation_hook','wp_localize_script','add_action','add_filter','add_shortcode','register_rest_route','register_block_type','register_block_type_from_metadata','wp_register_script','wp_enqueue_script','wp_register_style','wp_enqueue_style','wp_schedule_event','wp_schedule_single_event','do_action','apply_filters');
$source = array(); $texts = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $relative = substr($file->getPathname(), strlen($root));
    if (preg_match('~^(node_modules|vendor|build|tests|\.git)/~', $relative) || !preg_match('/\.(php|js)$/', $relative)) { continue; }
    $text = file_get_contents($file->getPathname()); $texts[$relative] = $text;
    if (substr($relative, -4) !== '.php') { continue; }
    $tokens = token_get_all($text);
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) { continue; }
        $include = in_array($token[0], array(T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE), true);
        if (!$include && ($token[0] !== T_STRING || !in_array($token[1], $functions, true))) { continue; }
        // Skip function definitions rather than counting them as invocations.
        $previous = $i - 1; while ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) { $previous--; }
        if ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_FUNCTION) { continue; }
        $args = array(); $part = ''; $depth = 0; $started = $include;
        for ($j = $i + 1; $j < $n; $j++) {
            $value = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], array(T_COMMENT,T_DOC_COMMENT), true)) { continue; }
            if (!$started) { if ($value === '(') { $started = true; $depth = 1; } continue; }
            if ($include && $value === ';' && $depth === 0) { $args[] = trim($part); break; }
            if (!$include && $value === ')' && $depth === 1) { $args[] = trim($part); break; }
            if (!$include && $value === ',' && $depth === 1) { $args[] = trim($part); $part = ''; continue; }
            if (in_array($value, array('(', '[', '{'), true)) { $depth++; }
            if (in_array($value, array(')', ']', '}'), true)) { $depth--; }
            $part .= $value;
        }
        $name = null;
        $name_arg = $token[1] === 'wp_schedule_event' ? 2 : ($token[1] === 'wp_schedule_single_event' ? 1 : 0);
        if (isset($args[$name_arg]) && preg_match('/^([\x27\x22])(.*)\1$/s', $args[$name_arg], $match) && strpos($match[2], $match[1]) === false) { $name = $match[2]; }
        $source[] = array('kind' => $include ? 'include' : $token[1], 'name' => $name, 'args' => $args, 'file' => $relative, 'line' => $token[2], 'status' => 'indeterminado');
    }
}
// Observe real frontend or admin/editor contexts independently.
if (defined('WP_ADMIN') && WP_ADMIN) {
    do_action('admin_init'); do_action('admin_menu');
    set_current_screen('edit-eipsi_form_template');
    do_action('admin_enqueue_scripts', 'edit.php');
    foreach (array(
        array('eipsi-results-experience','submissions','toplevel_page_eipsi-results-experience'),
        array('eipsi-results-experience','randomization','toplevel_page_eipsi-results-experience'),
        array('eipsi-longitudinal-study','dashboard-study','eipsi-forms_page_eipsi-longitudinal-study'),
        array('eipsi-longitudinal-study','waves-manager','eipsi-forms_page_eipsi-longitudinal-study'),
        array('eipsi-longitudinal-study','export','eipsi-forms_page_eipsi-longitudinal-study'),
        array('eipsi-configuration','smtp','eipsi-forms_page_eipsi-configuration'),
        array('eipsi-configuration','schema-status','eipsi-forms_page_eipsi-configuration'),
        array('eipsi-results-experience','privacy','toplevel_page_eipsi-results-experience')
    ) as $context) {
        $_GET['page']=$context[0]; $_GET['tab']=$context[1];
        set_current_screen($context[2]); do_action('admin_enqueue_scripts',$context[2]);
    }
    $_GET=array(); set_current_screen('edit-eipsi_form_template');
    do_action('enqueue_block_editor_assets');
} else {
    $GLOBALS['post'] = new WP_Post((object) array('ID'=>0,'post_type'=>'page','post_content'=>'[eipsi_form id="0"] [eipsi_survey_login] [eipsi_participant_dashboard] [eipsi_longitudinal_study]'));
    do_action('wp_enqueue_scripts');
}
$loaded_paths = array(); foreach (get_included_files() as $path) { if (strpos($path,$root)===0) { $loaded_paths[substr($path,strlen($root))]=true; } }
$hooks = array();
foreach ($GLOBALS['wp_filter'] as $hook => $object) {
    foreach ($object->callbacks as $priority => $callbacks) {
        $order = 0;
        foreach ($callbacks as $entry) {
            $order++; $row = m0_callback($entry['function']);
            if ($row['file'] && (strpos($row['file'], $root) !== 0 || strpos($row['file'], $root.'tests/') === 0)) { continue; }
            if (!$row['file'] && strpos($hook, 'eipsi') === false) { continue; }
            $row['file'] = $row['file'] ? substr($row['file'], strlen($root)) : null;
            $row += array('hook'=>$hook,'priority'=>$priority,'order_at_priority'=>$order,'accepted_args'=>$entry['accepted_args'],'status'=>$row['callable'] ? (!empty($row['deprecated']) ? 'legacy' : 'activo') : 'roto','registrations'=>array(),'consumers'=>array(),'references'=>array());
            foreach ($source as $declaration) {
                if (($declaration['name'] === $hook || $declaration['name'] === null || in_array($declaration['kind'], array('add_menu_page','add_submenu_page'), true)) && in_array($declaration['kind'], array('add_action','add_filter','register_activation_hook','register_deactivation_hook','add_menu_page','add_submenu_page'), true)) {
                    $method = strpos($row['callback'], '::') !== false ? substr($row['callback'], strpos($row['callback'], '::') + 2) : $row['callback'];
                    $callback_index = $declaration['kind'] === 'add_menu_page' ? 4 : ($declaration['kind'] === 'add_submenu_page' ? 5 : 1);
                    $matches = $method === '{closure}' ? ($row['file'] === $declaration['file'] && abs($row['line'] - $declaration['line']) <= 3) : strpos($declaration['args'][$callback_index] ?? '', $method) !== false;
                    if ($matches && strpos($row['callback'],'::') !== false) {
                        $class = substr($row['callback'],0,strpos($row['callback'],'::'));
                        $callback_arg = $declaration['args'][$callback_index] ?? '';
                        $matches = strpos($callback_arg,$class) !== false || ($row['file'] === $declaration['file'] && (strpos($callback_arg,'__CLASS__') !== false || strpos($callback_arg,'self::') !== false || strpos($callback_arg,'$this') !== false));
                    }
                    if ($matches && isset($loaded_paths[$declaration['file']])) { $row['registrations'][] = $declaration['file'] . ':' . $declaration['line']; }
                }
                if ($declaration['name'] === $hook && in_array($declaration['kind'], array('do_action','apply_filters'), true)) { $row['consumers'][] = $declaration['file'] . ':' . $declaration['line']; }
            }
            if (strpos($hook, 'wp_ajax_') === 0) {
                $action = preg_replace('/^wp_ajax_(nopriv_)?/', '', $hook);
                foreach ($texts as $file => $text) { if (strpos($text, "'" . $action . "'") !== false || strpos($text, '"' . $action . '"') !== false) { $row['references'][] = $file;
                        if (preg_match('/action\s*:\s*[\x27\x22]' . preg_quote($action, '/') . '[\x27\x22]|append\s*\(\s*[\x27\x22]action[\x27\x22]\s*,\s*[\x27\x22]' . preg_quote($action, '/') . '[\x27\x22]/', $text)) { $row['consumers'][] = $file; } } }
            }
            $row['consumers'] = array_values(array_unique($row['consumers'])); $hooks[] = $row;
        }
    }
}
$shortcodes = array(); foreach ($GLOBALS['shortcode_tags'] as $tag => $callback) { $row = m0_callback($callback); if ($row['file'] && strpos($row['file'], $root) === 0) { $row['file'] = substr($row['file'], strlen($root)); $shortcodes[$tag] = $row; } }
$rest = array(); foreach (rest_get_server()->get_routes() as $route => $endpoints) { if (strpos($route, '/eipsi/') !== 0) { continue; } foreach ($endpoints as $endpoint) { if (!isset($endpoint['callback']) || empty($endpoint['permission_callback'])) { continue; } $rest[] = array('route'=>$route,'methods'=>$endpoint['methods'],'callback'=>m0_callback($endpoint['callback']),'permission'=>m0_callback($endpoint['permission_callback'])); } }
$cron = array(); foreach (_get_cron_array() as $timestamp => $events) { foreach ($events as $hook => $instances) { if (strpos($hook, 'eipsi') === false) { continue; } foreach ($instances as $event) { $cron[] = array('hook'=>$hook,'timestamp'=>$timestamp,'schedule'=>$event['schedule'],'interval'=>$event['interval'] ?? null,'args'=>$event['args'],'callback_registered'=>has_action($hook) !== false); } } }
$assets = array(); foreach (array('script'=>wp_scripts(), 'style'=>wp_styles()) as $kind=>$registry) { foreach ($registry->registered as $handle=>$asset) { if (strpos((string)$asset->src, 'EIPSI-Forms-Plugin/') === false && strpos($handle, 'eipsi') !== 0) { continue; } $assets[] = array('kind'=>$kind,'handle'=>$handle,'src'=>$asset->src,'deps'=>$asset->deps,'version'=>$asset->ver,'group'=>$asset->extra['group'] ?? null,'enqueued'=>in_array($handle,$registry->queue,true)); } }
$blocks = array(); foreach (WP_Block_Type_Registry::get_instance()->get_all_registered() as $name=>$block) { if (strpos($name,'eipsi/') !== 0) { continue; } $blocks[] = array('name'=>$name,'render_callback'=>$block->render_callback ? m0_callback($block->render_callback) : null,'editor_scripts'=>$block->editor_script_handles,'styles'=>$block->style_handles); }
$included = array(); foreach (get_included_files() as $index=>$file) { if (strpos($file,$root) === 0 && strpos($file,$root.'tests/') !== 0) { $included[] = array('order'=>$index,'file'=>substr($file,strlen($root))); } }
// Reflection resolves the owning function/method for loaded source paths.
$scopes = array();
foreach (get_defined_functions()['user'] as $function) {
    $reflection = new ReflectionFunction($function);
    if (strpos((string)$reflection->getFileName(), $root) === 0) { $scopes[] = array(substr($reflection->getFileName(),strlen($root)), $reflection->getStartLine(), $reflection->getEndLine(), $function); }
}
foreach (get_declared_classes() as $class) {
    $reflection = new ReflectionClass($class);
    if (strpos((string)$reflection->getFileName(),$root) !== 0) { continue; }
    foreach ($reflection->getMethods() as $method) { $scopes[] = array(substr($method->getFileName(),strlen($root)), $method->getStartLine(), $method->getEndLine(), $class.'::'.$method->getName()); }
}
foreach ($hooks as $hook) { if ($hook['callback'] === '{closure}') { $scopes[] = array($hook['file'],$hook['line'],$hook['end_line'],'{closure}@'.$hook['line']); } }
foreach ($source as &$row) {
    $row['owner'] = 'archivo / contexto no resuelto'; $span=PHP_INT_MAX;
    foreach ($scopes as $scope) { if ($scope[0] === $row['file'] && $scope[1] <= $row['line'] && $scope[2] >= $row['line'] && $scope[2]-$scope[1] < $span) { $span=$scope[2]-$scope[1]; $row['owner']=$scope[3]; } }
    if ($row['kind'] === 'include') {
        $row['resolved_targets']=array();
        if (preg_match_all('/[\x27\x22]([^\x27\x22]+\.php)[\x27\x22]/', $row['args'][0] ?? '', $paths)) {
            foreach ($paths[1] as $path) {
                $candidate = strpos($row['args'][0],'EIPSI_FORMS_PLUGIN_DIR') !== false ? $root.ltrim($path,'/') : dirname($root.$row['file']).'/'.ltrim($path,'/');
                $resolved=realpath($candidate);
                if ($resolved && strpos($resolved,$root)===0) { $row['resolved_targets'][]=substr($resolved,strlen($root)); }
            }
        }
        foreach ($included as $loaded) { if (isset($loaded_paths[$row['file']]) && in_array($loaded['file'],$row['resolved_targets'],true)) { $row['status']='activo'; } }
    }
} unset($row);
foreach ($source as &$row) {
    $observed = false;
    if (in_array($row['kind'],array('add_action','add_filter','register_activation_hook','register_deactivation_hook','add_menu_page','add_submenu_page'),true)) { foreach ($hooks as $hook) { if (in_array($row['file'].':'.$row['line'], $hook['registrations'], true)) { $observed = true; break; } } }
    if ($row['kind'] === 'add_shortcode') { $observed = isset($loaded_paths[$row['file']]) && isset($shortcodes[$row['name']]); }
    if ($observed) { $row['status'] = 'activo'; }
} unset($row);
$noise = ob_get_clean();
echo wp_json_encode(array('profile'=>getenv('EIPSI_M0_PROFILE') ?: 'frontend','php'=>PHP_VERSION,'wordpress'=>get_bloginfo('version'),'plugin'=>EIPSI_FORMS_VERSION,'included'=>$included,'source'=>$source,'hooks'=>$hooks,'shortcodes'=>$shortcodes,'rest'=>$rest,'cron'=>$cron,'assets'=>$assets,'blocks'=>$blocks,'bootstrap_output_bytes'=>strlen($noise)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
