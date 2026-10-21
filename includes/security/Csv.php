<?php
/** Spreadsheet presentation only. Scientific values/JSON are never rewritten. */
final class EIPSI_Csv_Serializer {
    public static function cell($value) {
        if (!is_string($value) || $value === '') { return $value; }
        // Preserve real signed decimal/scientific numbers, including numeric strings.
        if (preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $value)) { return $value; }
        $unsafe = preg_match('/^[\p{Z}\p{Cf}\x00-\x20]*[=+\-@]/u', $value);
        if ($unsafe === false) { $unsafe = preg_match('/^[\x00-\x20\x7f-\xff]*[=+\-@]/', $value); }
        return $unsafe ? "'" . $value : $value;
    }
    public static function row($stream, array $row) {
        $row = array_map(array(__CLASS__, 'cell'), $row);
        $result = fputcsv($stream, $row, ',', '"', '');
        if ($result === false) { throw new RuntimeException('No se pudo escribir el export CSV.'); }
        return $result;
    }
    public static function xlsx(array $rows): array {
        // SimpleXLSXGen's documented raw-string marker bypasses its formula/HTML auto-typing.
        return array_map(static function($row){return array_map(static function($cell){return is_string($cell)?"\0".$cell:$cell;},$row);},$rows);
    }
}
