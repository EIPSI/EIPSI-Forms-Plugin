<?php
namespace EIPSI\ExternalApi;
/** Request-private temporary stream. PHP removes its spilled 0600 file when the handle closes. */
final class ExportDownload {
    public $stream;public $start;public $length;public $total;public $status;public $sha256;public $format;
    public function __construct($stream,int $total,string $sha,string $format,?string $range){
        $this->stream=$stream;$this->total=$total;$this->sha256=$sha;$this->format=$format;$this->start=0;$this->length=$total;$this->status=200;
        if($range!==null){if(!preg_match('/^bytes=([0-9]{0,18})-([0-9]{0,18})$/D',$range,$m)||$total===0||$m[1]===''&&$m[2]==='')throw new ApiError('eipsi_external_invalid_range',416,array('Content-Range'=>'bytes */'.$total,'Accept-Ranges'=>'bytes'));
            if($m[1]===''){$suffix=(int)$m[2];if($suffix===0)throw new ApiError('eipsi_external_invalid_range',416,array('Content-Range'=>'bytes */'.$total,'Accept-Ranges'=>'bytes'));$start=max(0,$total-$suffix);$end=$total-1;}
            else{$start=(int)$m[1];$end=$m[2]===''?$total-1:min($total-1,(int)$m[2]);}
            if($start>=$total||$end<$start)throw new ApiError('eipsi_external_invalid_range',416,array('Content-Range'=>'bytes */'.$total,'Accept-Ranges'=>'bytes'));$this->start=$start;$this->length=$end-$start+1;$this->status=206;
        }
        if(fseek($stream,$this->start)!==0)throw new ApiError('eipsi_external_export_failed',503);
    }
    public function ifRange(?string $validator): void {
        // Only an exact strong ETag resumes. Dates/weak/unrecognized validators get the full representation.
        if($validator===null||$this->status!==206||$validator==='"'.$this->sha256.'"')return;
        $this->status=200;$this->start=0;$this->length=$this->total;if(fseek($this->stream,0)!==0)throw new ApiError('eipsi_external_export_failed',503);
    }
    public function emit(): void {try{$remaining=$this->length;while($remaining>0&&!feof($this->stream)){$data=fread($this->stream,min(65536,$remaining));if($data===false||$data==='')throw new \RuntimeException('Export stream interrupted');echo $data;$remaining-=strlen($data);}}finally{$this->close();}}
    public function close(): void {if(is_resource($this->stream))fclose($this->stream);}
    public function __destruct(){$this->close();}
}
