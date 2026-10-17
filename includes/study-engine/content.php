<?php
/** Opt-in content/runtime services; no registration or transport side effects. */
require_once __DIR__.'/persistence.php';
require_once __DIR__.'/runtime/PublicMessages.php';
foreach(array('TemplateAdapter','ResponseAdapter','AssessmentRenderer') as $module)require_once __DIR__.'/content/'.$module.'.php';
foreach(array('PublicStudyRepository','AnonymousAccess','LocalResponseSink','AccessContext','CrossSectionalRuntime') as $module)require_once __DIR__.'/runtime/'.$module.'.php';
unset($module);
