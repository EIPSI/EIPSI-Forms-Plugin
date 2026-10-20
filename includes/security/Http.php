<?php
final class EIPSI_Http_Policy {
    public static function post(?string $expected = null): void {
        if (($_SERVER['REQUEST_METHOD']??'') !== 'POST') { throw new RuntimeException('POST required',405); }
        self::origin($expected);
    }
    public static function origin(?string $expected = null): void {
        // WordPress, CLI clients and reverse proxies may omit Origin. Nonce/auth remain mandatory.
        if (empty($_SERVER['HTTP_ORIGIN'])) { return; }
        $normalize=static function($value) {
            $u=wp_parse_url($value);
            if (!is_array($u) || empty($u['scheme']) || empty($u['host']) || isset($u['user']) || isset($u['pass'])) { return null; }
            $scheme=strtolower($u['scheme']);if(!in_array($scheme,array('http','https'),true))return null;
            return array($scheme,strtolower($u['host']),(int)($u['port']??($scheme==='https'?443:80)));
        };
        $origin=$normalize($_SERVER['HTTP_ORIGIN']);
        if ($origin === null || $origin !== $normalize($expected??home_url())) { throw new RuntimeException('Invalid origin',403); }
    }
}
