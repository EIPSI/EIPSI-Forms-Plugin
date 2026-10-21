<?php
namespace EIPSI\StudyEngine\Persistence;
/** Test-only namespace clock; no production hook or public clock endpoint. */
function gmdate(string $format,?int $timestamp=null): string {return \gmdate($format,$timestamp??($GLOBALS['api1_time']??\time()));}
