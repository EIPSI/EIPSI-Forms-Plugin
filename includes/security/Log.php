<?php
/** Fixed events and an allowlist, never raw requests, exceptions or scientific content. */
final class EIPSI_Security_Log {
    private static $seen = array();
    public static function event(string $event, array $context = array(), string $level = 'error'): void {
        if ($level === 'debug' && !(defined('EIPSI_SECURITY_DEBUG') && EIPSI_SECURITY_DEBUG)) { return; }
        $clean = static function($v) { return is_scalar($v) && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', (string)$v) ? (string)$v : 'REDACTED'; };
        $event = $clean($event);
        if (isset(self::$seen[$event]) || count(self::$seen) >= 20) { return; }
        self::$seen[$event] = true;
        $row = array('event'=>$event,'level'=>$level==='debug'?'debug':'error');
        foreach (array('action','request_id','study_id','status','code','duration_ms') as $key) {
            if (isset($context[$key])) { $row[$key] = $clean($context[$key]); }
        }
        error_log('[EIPSI] ' . json_encode($row, JSON_UNESCAPED_SLASHES));
    }
}
