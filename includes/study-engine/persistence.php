<?php
/** Internal opt-in API. Loaded explicitly by internal services or the opt-in Study runtime route. */
require_once __DIR__.'/engine.php';
foreach(array('Database','SeedVault','StudyVersionRepository','StudyExecutionRepository','BlockExecutionRepository','ExecutionEventRepository','RandomDecisionRepository','CommandRepository','NotificationIntentRepository','WithdrawalRepository','SubmissionJournalRepository') as $eipsiEngineModule)require_once __DIR__.'/persistence/'.$eipsiEngineModule.'.php';
unset($eipsiEngineModule);
require_once __DIR__.'/orchestration/ExecutionOwner.php';
