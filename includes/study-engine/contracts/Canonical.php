<?php
namespace EIPSI\StudyEngine\Contracts;

/** JSON-only canonical data: map keys sorted, lists retain semantic order. */
final class Canonical {
    public static function normalize($value, int $depth = 0) {
        if ($depth > 256) { throw new \InvalidArgumentException("Maximum canonical depth is 256"); }
        if (is_array($value)) {
            $list = array_keys($value) === range(0, count($value) - 1) || $value === array();
            if (!$list) { ksort($value, SORT_STRING); }
            foreach ($value as $key => $item) { $value[$key] = self::normalize($item, $depth + 1); }
            return $value;
        }
        if (is_object($value) || is_resource($value) || (is_float($value) && !is_finite($value))) {
            throw new \InvalidArgumentException('Only finite JSON values are canonical');
        }
        return $value;
    }
    public static function json($value): string {
        return json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
    public static function definition(array $data): array {
        // Registries are sets; tree order lives in position, not insertion order.
        usort($data['nodes'], static function ($a, $b) { return strcmp($a['id'], $b['id']); });
        usort($data['condition_rules'], static function ($a, $b) { return strcmp($a['id'], $b['id']); });
        sort($data['root_block_ids'], SORT_STRING);
        return self::normalize($data);
    }
    public static function protocolHash(array $data): string {
        $data = self::definition($data);
        // Lifecycle metadata is mutable; the scientific protocol is not.
        unset($data['state']);
        return hash('sha256', self::json($data));
    }
}
