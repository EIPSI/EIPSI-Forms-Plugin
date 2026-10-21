<?php
namespace EIPSI\StudyEngine\Contracts;

final class ContractViolation extends \InvalidArgumentException {
    private $issues;
    public function __construct(array $issues) {
        $this->issues = $issues;
        parent::__construct(Canonical::json($issues));
    }
    public function issues(): array { return $this->issues; }
}

final class Check {
    public static function fail(string $code, string $path, string $message): void {
        throw new ContractViolation(array(array('code' => $code, 'path' => $path, 'message' => $message)));
    }
    public static function fields($data, array $required, array $optional = array(), string $path = ''): void {
        if (!is_array($data)) { self::fail('INVALID_RECORD', $path, 'Expected a record'); }
        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) { self::fail('MISSING_FIELD', $path . '.' . $field, 'Required field missing'); }
        }
        foreach (array_keys($data) as $field) {
            if (!in_array($field, array_merge($required, $optional), true)) { self::fail('UNKNOWN_FIELD', $path . '.' . $field, 'Unknown contract field'); }
        }
        try { Canonical::json($data); } catch (\InvalidArgumentException $e) { self::fail('INVALID_JSON', $path, $e->getMessage()); } catch (\JsonException $e) { self::fail('INVALID_JSON', $path, $e->getMessage()); }
    }
    public static function id($value, string $path): void {
        if (!is_string($value) || !preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,127}$/D', $value)) { self::fail('INVALID_ID', $path, 'Stable logical ID required'); }
    }
    public static function text($value, string $path): void {
        if (!is_string($value) || $value === '') { self::fail('INVALID_STRING', $path, 'Nonempty string required'); }
    }
    public static function integer($value, string $path, int $min = 0): void {
        if (!is_int($value) || $value < $min) { self::fail('INVALID_INTEGER', $path, 'Integer outside contract range'); }
    }
    public static function boolean($value, string $path): void {
        if (!is_bool($value)) { self::fail('INVALID_BOOLEAN', $path, 'Boolean required'); }
    }
    public static function set($value, string $class, string $path): void {
        if (!$class::contains($value)) { self::fail('INVALID_ENUM', $path, 'Unknown ' . $class); }
    }
    public static function listOf($value, string $path): void {
        if (!is_array($value) || ($value !== array() && array_keys($value) !== range(0, count($value)-1))) { self::fail('INVALID_LIST', $path, 'List required'); }
    }
    public static function timestamp($value, string $path, bool $nullable = true): void {
        if ($value === null && $nullable) { return; }
        if (!is_string($value)) { self::fail('INVALID_TIMESTAMP', $path, 'UTC timestamp required'); }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $value) { self::fail('INVALID_TIMESTAMP', $path, 'Expected valid YYYY-MM-DDTHH:MM:SSZ'); }
    }
    public static function timezone($value, string $path): void {
        if (!is_string($value) || !in_array($value, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            self::fail('INVALID_TIMEZONE', $path, 'IANA timezone required');
        }
    }
    public static function time($value, string $path): void {
        if (!is_string($value) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value)) { self::fail('INVALID_LOCAL_TIME', $path, 'HH:MM required'); }
    }
}
