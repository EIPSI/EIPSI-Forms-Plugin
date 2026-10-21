<?php
class EIPSI_Rate_Limit_Exception extends RuntimeException {
    public $retry_after;
    public function __construct(int $retry, int $status = 429) { $this->retry_after=$retry;parent::__construct('RATE_LIMIT', $status); }
}
/** Atomic fixed windows on the WordPress options unique index; no object-cache assumptions. */
final class EIPSI_Rate_Limiter {
    const PREFIX = '_eipsi_rl_';
    public static function consume(string $operation, string $principal, int $limit, int $window, ?int $now = null): int {
        if ($limit < 1 || $limit > 1000000 || $window < 1 || $window > 86400) { throw new InvalidArgumentException('Invalid rate policy'); }
        global $wpdb;$now=$now??time();$retry=$window-($now%$window);$end=$now+$retry;
        $name=self::PREFIX.sprintf('%010d',$end).'_'.hash_hmac('sha256',$operation.'|'.$principal,wp_salt('nonce'));
        // Initialize without increment, then atomically increment on the unique option_name.
        // INSERT's auto-increment ID must never be mistaken for the budget counter.
        $insert=$wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,'0','off')",$name);
        $update=$wpdb->prepare("UPDATE {$wpdb->options} SET option_value=LAST_INSERT_ID(LEAST(CAST(option_value AS UNSIGNED)+1,%d)) WHERE option_name=%s",$limit+1,$name);
        if ($wpdb->query($insert) === false || $wpdb->query($update) === false) { throw new EIPSI_Rate_Limit_Exception(min($retry,60),503); }
        $used=$wpdb->get_var('SELECT LAST_INSERT_ID()');
        if ($used === null || $wpdb->last_error !== '' || (int)$used < 1) { throw new EIPSI_Rate_Limit_Exception(min($retry,60),503); }
        $used=(int)$used;
        if ($used > $limit) { throw new EIPSI_Rate_Limit_Exception($retry); }
        return $used;
    }
    public static function cleanup(int $limit = 200): int {
        global $wpdb;$limit=max(1,min(500,$limit));$low=self::PREFIX.'0000000000_';$high=self::PREFIX.sprintf('%010d',time()).'~';
        return (int)$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name >= %s AND option_name < %s ORDER BY option_name LIMIT %d",$low,$high,$limit));
    }
}
