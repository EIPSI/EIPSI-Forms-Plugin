<?php
require dirname(__DIR__,2).'/includes/i18n/ContentDefaults.php';
use EIPSI\I18n\ContentDefaults as C;
$expected=json_decode(file_get_contents(__DIR__.'/question-parity.json'),true,64,JSON_THROW_ON_ERROR);$tests=0;$failed=0;
foreach($expected as $language=>$types)foreach($types as $type=>$defaults){$tests++;$actual=C::question($type,$language);ksort($actual);ksort($defaults);if($actual===$defaults)echo "PASS PHP/JS $language $type\n";else{$failed++;echo "FAIL PHP/JS $language $type\n";}}
echo "$tests tests, $failed failures\n";exit($failed?1:0);
