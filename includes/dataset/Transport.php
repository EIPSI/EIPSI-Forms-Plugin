<?php
namespace EIPSI\Dataset;
use EIPSI\StudyEngine\Contracts\Canonical as C;
require_once dirname(__DIR__).'/security/Csv.php';
/** Same cells for all channels. XLSX uses typed inline strings, never formulas or shared-string materialization. */
final class Transport {
    const MIME_XLSX='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    public static function selected(array $selected): array {
        if(!$selected)$selected=array('data','dictionary','response_status','bindings');
        if(array_diff($selected,CanonicalStudyDataset::ANNEXES)||count($selected)!==count(array_unique($selected)))throw new Conflict('DATASET_SELECTION_INVALID');
        if(in_array('data',$selected,true)&&!in_array('dictionary',$selected,true))$selected[]='dictionary';
        return $selected;
    }
    public static function columns(array $bundle,string $section): array {
        if(!in_array($section,CanonicalStudyDataset::ANNEXES,true))throw new Conflict('DATASET_SELECTION_INVALID');
        if($section==='data')return $bundle['columns'];$columns=array();foreach($bundle[$section]??array()as$row)foreach(array_keys($row)as$key)$columns[$key]=true;return array_keys($columns);
    }
    public static function csv($stream,iterable $rows,array $columns): void {
        \EIPSI_Csv_Serializer::row($stream,$columns);foreach($rows as$row){$cells=array();foreach($columns as$key){$v=$row[$key]??null;$cells[]=is_array($v)?C::json($v):(is_bool($v)?($v?'true':'false'):$v);} \EIPSI_Csv_Serializer::row($stream,$cells);}
    }
    private static function xml(string $s): string {
        if(!preg_match('//u',$s))throw new Conflict('DATASET_INVALID_UNICODE');if(preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u',$s))throw new Conflict('XLSX_INVALID_XML_CHARACTER');return htmlspecialchars($s,ENT_QUOTES|ENT_XML1,'UTF-8');
    }
    private static function column(int $index): string {$s='';do{$s=chr(65+$index%26).$s;$index=intdiv($index,26)-1;}while($index>=0);return $s;}
    /** Returns a private stream; caller owns fclose. One row at a time, ZIP reads sheet files on close. */
    public static function workbook(array $sheets) {
        if(!class_exists('ZipArchive'))throw new \RuntimeException('XLSX_ZIP_UNAVAILABLE');
        $dir=sys_get_temp_dir().'/eipsi-xlsx-'.bin2hex(random_bytes(16));if(!mkdir($dir,0700))throw new \RuntimeException('XLSX_TEMP_UNAVAILABLE');$zip=new \ZipArchive();$opened=false;
        try {
            if($zip->open($dir.'/book.xlsx',\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true)throw new \RuntimeException('XLSX_TEMP_UNAVAILABLE');$opened=true;
            $types='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
            $book='<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';$rels='<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';$index=0;
            foreach($sheets as$name=>$sheet){
                if(!preg_match('/^[a-z_]{1,31}$/D',$name))throw new Conflict('XLSX_SHEET_NAME_INVALID');$index++;$columns=$sheet['columns'];if(count($columns)>16384)throw new Conflict('XLSX_COLUMN_LIMIT');
                $file=$dir.'/sheet'.$index.'.xml';$f=fopen($file,'xb');if(!$f)throw new \RuntimeException('XLSX_TEMP_UNAVAILABLE');
                try {self::put($f,'<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');$rowNumber=0;
                    $iterator=(static function()use($sheet,$columns){yield $columns;foreach($sheet['rows']as$row){$cells=array();foreach($columns as$key)$cells[]=$row[$key]??null;yield $cells;}})();
                    foreach($iterator as$cells){if(++$rowNumber>1048576)throw new Conflict('XLSX_ROW_LIMIT');self::put($f,'<row r="'.$rowNumber.'">');foreach($cells as$i=>$value){$ref=self::column($i).$rowNumber;if($value===null)continue;
                        if(is_int($value)||is_float($value)){if(!is_finite((float)$value))throw new Conflict('DATASET_INVALID_NUMBER');if((is_int($value)&&strlen(ltrim((string)$value,'-'))>15)||(is_float($value)&&(float)sprintf('%.15g',$value)!==$value))throw new Conflict('XLSX_NUMERIC_PRECISION_LIMIT');self::put($f,'<c r="'.$ref.'" t="n"><v>'.C::json($value).'</v></c>');}
                        elseif(is_bool($value))self::put($f,'<c r="'.$ref.'" t="b"><v>'.($value?'1':'0').'</v></c>');
                        else {if(is_array($value))$value=C::json($value);$value=(string)$value;if(strlen($value)>32767)throw new Conflict('XLSX_CELL_LIMIT');self::put($f,'<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.self::xml($value).'</t></is></c>');}
                    }self::put($f,'</row>');}self::put($f,'</sheetData></worksheet>');}finally{fclose($f);}
                $entry='xl/worksheets/sheet'.$index.'.xml';$zip->addFile($file,$entry);$zip->setMtimeName($entry,315532800);$types.='<Override PartName="/'.$entry.'" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';$book.='<sheet name="'.$name.'" sheetId="'.$index.'" r:id="rId'.$index.'"/>';$rels.='<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';
            }
            $zip->addFromString('[Content_Types].xml',$types.'</Types>');$zip->addFromString('xl/workbook.xml',$book.'</sheets></workbook>');$zip->addFromString('xl/_rels/workbook.xml.rels',$rels.'</Relationships>');$zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            foreach(array('[Content_Types].xml','xl/workbook.xml','xl/_rels/workbook.xml.rels','_rels/.rels')as$entry)$zip->setMtimeName($entry,315532800);
            if(!$zip->close())throw new \RuntimeException('XLSX_WRITE_FAILED');$opened=false;$stream=fopen('php://temp/maxmemory:1048576','w+b');$input=fopen($dir.'/book.xlsx','rb');try{if(stream_copy_to_stream($input,$stream)===false)throw new \RuntimeException('XLSX_WRITE_FAILED');}finally{fclose($input);}rewind($stream);return $stream;
        } finally {if($opened)$zip->close();foreach(glob($dir.'/*')as$file)unlink($file);rmdir($dir);}
    }
    private static function put($stream,string $text): void {if(fwrite($stream,$text)!==strlen($text))throw new \RuntimeException('XLSX_WRITE_FAILED');}
    public static function file(array $bundle,array $manifest,array $selected,string $format,string $section='data'): array {
        $selected=self::selected($selected);if(!in_array($format,array('csv','json','xlsx'),true))throw new Conflict('DATASET_FORMAT_INVALID');
        if($format==='json'){$out=array('manifest'=>$manifest);foreach($selected as$key)$out[$key]=$bundle[$key]??array();return array('mime'=>'application/json','content'=>C::json($out),'encoding'=>'utf8');}
        if($format==='csv'){if(!in_array($section,$selected,true))throw new Conflict('DATASET_SELECTION_INVALID');$f=fopen('php://temp','w+b');try{self::csv($f,$bundle[$section]??array(),self::columns($bundle,$section));rewind($f);return array('mime'=>'text/csv;charset=utf-8','content'=>stream_get_contents($f),'encoding'=>'utf8');}finally{fclose($f);}}
        $sheets=array();foreach($selected as$key)$sheets[$key]=array('columns'=>self::columns($bundle,$key),'rows'=>$bundle[$key]??array());$sheets['manifest']=array('columns'=>array('field','value'),'rows'=>array_map(static function($key,$value){return array('field'=>$key,'value'=>$value);},array_keys($manifest),array_values($manifest)));$stream=self::workbook($sheets);try{return array('mime'=>self::MIME_XLSX,'content'=>base64_encode(stream_get_contents($stream)),'encoding'=>'base64');}finally{fclose($stream);}
    }
}
