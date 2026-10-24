<?php
/** Fixed events and an allowlist, never raw requests, exceptions or scientific content. */
final class EIPSI_Security_Log {
    private static $seen = array();
    public static function event(string $event, array $context = array(), string $level = 'error'): void {
        if ($level === 'debug' && !(defined('EIPSI_SECURITY_DEBUG') && EIPSI_SECURITY_DEBUG)) { return; }
        $clean = static function($v, int $limit=64) { return is_scalar($v) && strlen((string)$v)<=$limit && preg_match('/^[A-Za-z0-9_.:-]+$/D', (string)$v) ? (string)$v : 'REDACTED'; };
        $event = $clean($event);
        if (isset(self::$seen[$event]) || count(self::$seen) >= 20) { return; }
        self::$seen[$event] = true;
        $row = array('event'=>$event,'level'=>$level==='debug'?'debug':(in_array($event,array('STUDY_OPERATION_FAILED','SERVER_KEYS_PROVISIONED'),true)&&$level==='info'?'info':'error'));
        foreach (array('action','request_id','study_id','status','code','duration_ms') as $key) {
            if (isset($context[$key])) { $row[$key] = $clean($context[$key]); }
        }
        if ($event === 'SERVER_KEYS_PROVISIONED') {
            foreach(array('actor','at','operation','result','source')as $key)if(isset($context[$key]))$row[$key]=$clean($context[$key]);
        }
        if ($event === 'STUDY_OPERATION_FAILED') {
            foreach (array('operation','route','request_method','study_code_hash','exception_class','exception_code','component','source_method','source_line','stack_fingerprint','cause_code','db_errno') as $key) {
                if (isset($context[$key])) { $row[$key] = $clean($context[$key], $key==='exception_class'?160:64); }
            }
        }
        error_log('[EIPSI] ' . json_encode($row, JSON_UNESCAPED_SLASHES));
    }
}
